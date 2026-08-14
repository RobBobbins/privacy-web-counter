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

/** True if $ip falls inside $list, whose entries are plain IPs or CIDR ranges. */
function w3b_counter_ip_in_list($ip, array $list)
{
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    foreach ($list as $entry) {
        $entry = trim((string) $entry);
        if ($entry === '') {
            continue;
        }

        if (strpos($entry, '/') === false) {
            if (strcasecmp($ip, $entry) === 0) {
                return true;
            }
            continue;
        }

        list($net, $bits) = explode('/', $entry, 2);
        if (!filter_var($net, FILTER_VALIDATE_IP) || !ctype_digit($bits)) {
            continue;
        }

        // Compare as packed bytes, so the same code handles IPv4 and IPv6.
        $ipBin  = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            continue;   // different families never match
        }

        $bits = (int) $bits;
        if ($bits < 0 || $bits > strlen($ipBin) * 8) {
            continue;
        }

        $whole = intdiv($bits, 8);
        $rest  = $bits % 8;
        if ($whole > 0 && strncmp($ipBin, $netBin, $whole) !== 0) {
            continue;
        }
        if ($rest > 0) {
            $mask = chr((0xFF << (8 - $rest)) & 0xFF);
            if (((ord($ipBin[$whole]) ^ ord($netBin[$whole])) & ord($mask)) !== 0) {
                continue;
            }
        }
        return true;
    }

    return false;
}

/**
 * The real client IP.
 *
 * Proxy headers are read ONLY when REMOTE_ADDR is itself one of the addresses
 * in config['trusted_proxies']. That check is the whole point: these headers
 * are supplied by whoever made the request, so trusting them unconditionally
 * lets any visitor forge an unlimited number of "unique visitors", or claim an
 * address from exclude_ips and never be counted at all.
 *
 * With no trusted proxies configured — the default, and correct for ordinary
 * shared hosting — this returns REMOTE_ADDR and nothing else.
 */
function w3b_counter_ip(array $cfg)
{
    $remote  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $trusted = isset($cfg['trusted_proxies']) && is_array($cfg['trusted_proxies'])
        ? $cfg['trusted_proxies']
        : [];

    if (!$trusted || !w3b_counter_ip_in_list($remote, $trusted)) {
        return $remote;
    }

    // Single-value headers, written by the proxy itself rather than forwarded.
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim($_SERVER[$key]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    // X-Forwarded-For is a chain — "client, proxy1, proxy2" — and everything
    // the client sent sits at the LEFT, where it can say anything it likes.
    // Walk from the right instead and take the first address that isn't one of
    // our own trusted proxies: that is the last hop we can actually vouch for.
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $chain = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (!filter_var($chain[$i], FILTER_VALIDATE_IP)) {
                continue;
            }
            if (!w3b_counter_ip_in_list($chain[$i], $trusted)) {
                return $chain[$i];
            }
        }
    }

    return $remote;
}

/** Where the visitor-hash salt lives: alongside the database, inside data/. */
function w3b_counter_salt_path(array $cfg)
{
    return dirname($cfg['db_path']) . '/salt.php';
}

/**
 * The visitor-hash salt, or '' if it is missing or too short to be safe.
 *
 * It lives in data/salt.php rather than config.php because data/ is the folder
 * this package blocks from the web. The salt is the entire privacy model: the
 * stored visitor hash is only irreversible while the salt is secret, since an
 * attacker holding it can hash the whole IPv4 space in minutes and turn every
 * row back into an address.
 *
 * Read as text rather than require()d, so that a half-written or truncated file
 * yields '' instead of a parse error taking a visitor's page down with it.
 */
function w3b_counter_salt(array $cfg)
{
    static $salt = null;
    if ($salt !== null) {
        return $salt;
    }

    $salt = '';
    $raw  = @file_get_contents(w3b_counter_salt_path($cfg));
    if (is_string($raw) && preg_match('/return\s+\'((?:[^\'\\\\]|\\\\.)*)\'\s*;/s', $raw, $m)) {
        $value = stripcslashes($m[1]);
        if (strlen($value) >= 16) {
            $salt = $value;
        }
    }

    return $salt;
}

/**
 * Salted, daily-rotating visitor fingerprint. Irreversible, no cookie.
 *
 * Returns '' when no usable salt exists. Callers must treat that as "record
 * nothing" — hashing without a salt would produce a value anyone could reverse,
 * which is worse than not counting at all.
 */
function w3b_counter_visitor(array $cfg, $ip, $ua, $day)
{
    $salt = w3b_counter_salt($cfg);
    if ($salt === '') {
        return '';
    }
    return substr(hash('sha256', $salt . $day . $ip . $ua), 0, 20);
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
