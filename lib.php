<?php
/**
 * Privacy Web Counter — shared helpers.
 *
 * Used by counter.php (records hits), referrer.php (patches recovered
 * referrers onto existing hits) and index.php (reads them back). Kept
 * separate so referrer.php can reuse the database and hashing logic without
 * re-running counter.php's require_once guard, which would otherwise skip it
 * on a second include.
 */

if (defined('W3B_COUNTER_LIB_LOADED')) {
    return;
}
define('W3B_COUNTER_LIB_LOADED', true);

/** Open the database, creating and migrating the schema on first run. */
function w3b_counter_db(array $cfg)
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }

    $dir = dirname($cfg['db_path']);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $db = new PDO('sqlite:' . $cfg['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');
    $db->exec('PRAGMA busy_timeout = 3000');

    $db->exec(
        'CREATE TABLE IF NOT EXISTS hits (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            ts           INTEGER NOT NULL,
            day          TEXT    NOT NULL,
            hour         INTEGER NOT NULL,
            path         TEXT    NOT NULL,
            ref_host     TEXT    NOT NULL DEFAULT "",
            ref_url      TEXT    NOT NULL DEFAULT "",
            browser      TEXT    NOT NULL DEFAULT "",
            os           TEXT    NOT NULL DEFAULT "",
            device       TEXT    NOT NULL DEFAULT "",
            country      TEXT    NOT NULL DEFAULT "",
            visitor      TEXT    NOT NULL DEFAULT "",
            is_bot       INTEGER NOT NULL DEFAULT 0,
            utm_source   TEXT    NOT NULL DEFAULT "",
            utm_medium   TEXT    NOT NULL DEFAULT "",
            utm_campaign TEXT    NOT NULL DEFAULT "",
            js_status    TEXT    NOT NULL DEFAULT ""
        )'
    );

    // Migrate a database created before referrer recovery / UTM tracking existed,
    // so upgrading never means losing existing traffic history.
    $existing = array_column($db->query('PRAGMA table_info(hits)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'js_status'] as $col) {
        if (!in_array($col, $existing, true)) {
            $db->exec("ALTER TABLE hits ADD COLUMN $col TEXT NOT NULL DEFAULT ''");
        }
    }

    $db->exec('CREATE INDEX IF NOT EXISTS idx_hits_day     ON hits(day)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_hits_botday  ON hits(is_bot, day)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_hits_path    ON hits(is_bot, path)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_hits_visitor ON hits(day, visitor)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_hits_match   ON hits(visitor, path, js_status, ts)');

    return $db;
}

/**
 * Keep one daily snapshot of the database in data/backups/, so a bad upload or
 * a broken migration always has yesterday's data to fall back on. Cheap to call
 * on every hit: it only does real work once, the first hit after midnight.
 * Set config['backup_days'] to 0 or false to turn this off entirely.
 */
function w3b_counter_backup(array $cfg, PDO $db)
{
    if (empty($cfg['backup_days'])) {
        return;
    }

    $dir = dirname($cfg['db_path']) . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $file = $dir . '/stats-' . date('Y-m-d') . '.db';
    if (!file_exists($file)) {
        // VACUUM INTO writes a complete, consistent copy in one step — safe to
        // run even while WAL mode is active, and it doesn't block other writers.
        $db->exec('VACUUM INTO ' . $db->quote($file));
    }

    $cutoff = strtotime('-' . (int) $cfg['backup_days'] . ' days');
    foreach (glob($dir . '/stats-*.db') ?: [] as $old) {
        if (preg_match('/stats-(\d{4}-\d{2}-\d{2})\.db$/', $old, $m) && strtotime($m[1]) < $cutoff) {
            @unlink($old);
        }
    }
}

/** Best guess at the real client IP, trusting proxy headers only when present. */
function w3b_counter_ip()
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

/** Salted, daily-rotating visitor fingerprint. Irreversible, no cookie. */
function w3b_counter_visitor(array $cfg, $ip, $ua, $day)
{
    return substr(hash('sha256', $cfg['salt'] . $day . $ip . $ua), 0, 20);
}

/** True if the user agent looks like a crawler, monitor or command-line tool. */
function w3b_counter_is_bot($ua)
{
    if ($ua === '') {
        return true;
    }
    $pattern = '/bot|crawl|spider|slurp|scrape|fetch|monitor|uptime|pingdom|'
             . 'headless|phantom|puppeteer|playwright|selenium|'
             . 'curl|wget|python-requests|python-urllib|go-http|java\/|okhttp|libwww|httpclient|axios|node-fetch|guzzle|'
             . 'facebookexternalhit|whatsapp|telegram|slackbot|discordbot|twitterbot|linkedinbot|embedly|preview|'
             . 'yandex|baidu|sogou|duckduck|petal|semrush|ahrefs|mj12|dotbot|bytespider|gptbot|claudebot|ccbot|perplexity/i';
    return (bool) preg_match($pattern, $ua);
}

/** Rough browser / OS / device classification from the user agent string. */
function w3b_counter_client($ua)
{
    $browser = 'Other';
    $map = [
        'Edge'      => '/edg[ea]?\//i',
        'Opera'     => '/opr\/|opera/i',
        'Samsung'   => '/samsungbrowser/i',
        'Vivaldi'   => '/vivaldi/i',
        'Brave'     => '/brave/i',
        'Chrome'    => '/chrome|crios|chromium/i',
        'Firefox'   => '/firefox|fxios/i',
        'Safari'    => '/safari/i',
        'Internet Explorer' => '/msie|trident/i',
    ];
    foreach ($map as $name => $re) {
        if (preg_match($re, $ua)) {
            $browser = $name;
            break;
        }
    }

    $os = 'Other';
    $osMap = [
        'Windows'   => '/windows nt/i',
        'Android'   => '/android/i',
        'iOS'       => '/iphone|ipad|ipod/i',
        'macOS'     => '/mac os x|macintosh/i',
        'Chrome OS' => '/cros/i',
        'Linux'     => '/linux|x11|ubuntu|fedora/i',
    ];
    foreach ($osMap as $name => $re) {
        if (preg_match($re, $ua)) {
            $os = $name;
            break;
        }
    }

    if (preg_match('/ipad|tablet|kindle|playbook|silk/i', $ua)) {
        $device = 'Tablet';
    } elseif (preg_match('/mobi|iphone|ipod|android.*mobile|windows phone/i', $ua)) {
        $device = 'Phone';
    } else {
        $device = 'Desktop';
    }

    return [$browser, $os, $device];
}

/** Two-letter country code, if the host or CDN provides one. Otherwise ''. */
function w3b_counter_country()
{
    foreach (['HTTP_CF_IPCOUNTRY', 'GEOIP_COUNTRY_CODE', 'HTTP_X_COUNTRY_CODE', 'COUNTRY_CODE'] as $key) {
        if (!empty($_SERVER[$key]) && preg_match('/^[A-Za-z]{2}$/', $_SERVER[$key])) {
            $code = strtoupper($_SERVER[$key]);
            return $code === 'XX' ? '' : $code;
        }
    }
    return '';
}

/** Host from a referrer URL, own_hosts stripped to '' (internal navigation, not a referrer). */
function w3b_counter_ref_host(array $cfg, $url)
{
    if ($url === '') {
        return '';
    }
    $h = parse_url($url, PHP_URL_HOST);
    if (!is_string($h)) {
        return '';
    }
    $h = strtolower(preg_replace('/^www\./i', '', $h));
    foreach ($cfg['own_hosts'] as $own) {
        if (strcasecmp($h, preg_replace('/^www\./i', '', $own)) === 0) {
            return '';
        }
    }
    return $h;
}

/**
 * This package's own URL path, worked out from where it sits on disk relative
 * to the document root — e.g. '/counter', '/stats', '/tools/analytics'. Lets
 * counter.php exclude this package's own pages without the folder name being
 * hardcoded anywhere, so renaming the folder needs no config edit.
 */
function w3b_counter_self_url_path()
{
    $selfDir = str_replace('\\', '/', __DIR__);
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']) : '';
    $docRoot = rtrim($docRoot, '/');
    if ($docRoot === '' || strpos($selfDir, $docRoot) !== 0) {
        return '';
    }
    return rtrim(substr($selfDir, strlen($docRoot)), '/');
}
