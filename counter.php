<?php
/**
 * Privacy Web Counter — the tracker.
 *
 * Include this as the VERY FIRST thing on every page you want counted:
 *
 *   <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/YOUR-FOLDER-NAME/counter.php'; ?>
 *
 * Replace YOUR-FOLDER-NAME with wherever you put this package (e.g. counter,
 * stats, analytics). That is the only place the folder name needs to be typed —
 * everything else in this package finds its own folder automatically, so
 * renaming it later needs no config changes.
 *
 * It runs server-side during page render, makes no extra HTTP request itself,
 * and sets no cookies. If config['js_beacon'] is on (the default), it also
 * buffers the page and appends one small inline script right before </body> —
 * see the "Referrer recovery" section of the README for what that script does
 * and why. Set js_beacon to false for a tracker with zero JavaScript.
 *
 * It is wrapped so that ANY failure (missing SQLite, unwritable disk, locked
 * database, missing config) is swallowed silently. A broken counter must
 * never break a page.
 */

if (defined('W3B_COUNTER_LOADED')) {
    return;
}
define('W3B_COUNTER_LOADED', true);

require_once __DIR__ . '/lib.php';

// Config is loaded here, outside the main try/catch below, because whether to
// buffer the page for the beacon script has to be decided before any page
// content is sent — buffering can't be switched on retroactively once output
// has started. A missing or broken config.php is still handled silently: $cfg
// just stays null and the tracker quietly does nothing.
$cfg = null;
try {
    $cfg = require __DIR__ . '/config.php';
} catch (Throwable $e) {
    $cfg = null;
} catch (Exception $e) {
    $cfg = null;
}

$selfUrlPath = w3b_counter_self_url_path();

if (is_array($cfg) && !empty($cfg['js_beacon'])) {
    $beaconUrl = $selfUrlPath . '/referrer.php';
    ob_start(function ($html) use ($beaconUrl) {
        // sendBeacon only, no image fallback: an <img> beacon means referrer.php
        // has to accept GET, and a GET that writes to the database can be fired
        // from any third-party page using your visitor's own IP and user agent.
        // Browsers without sendBeacon simply show as "JavaScript did not run".
        $script = '<script>(function(p,r,u){if(navigator.sendBeacon)navigator.sendBeacon(u,'
                . 'JSON.stringify({p:p,r:r}));})(location.pathname,document.referrer,'
                . json_encode($beaconUrl) . ');</script>';
        $patched = preg_replace('/<\/body>/i', $script . '</body>', $html, 1);
        return $patched === null ? $html : $patched;
    });
}

// ---------------------------------------------------------------------------
// Record the hit. Anything that goes wrong here is deliberately silent.
// ---------------------------------------------------------------------------
try {
    if (!is_array($cfg)) {
        return;
    }

    // Only count real page views.
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
    if ($method !== 'GET') {
        return;
    }

    $uri  = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = '/';
    }
    $path = substr($path, 0, 300);

    // This package's own live.php (the dashboard's polling endpoint) is never
    // counted. index.php (the dashboard itself) is excluded too, unless
    // count_dashboard is on — in which case it's tracked like any other page.
    $selfExcludes = [$selfUrlPath . '/live.php'];
    if (empty($cfg['count_dashboard'])) {
        $selfExcludes[] = $selfUrlPath . '/';
        $selfExcludes[] = $selfUrlPath . '/index.php';
    }
    foreach (array_merge($selfExcludes, $cfg['exclude_paths']) as $skip) {
        if (strcasecmp($path, $skip) === 0) {
            return;
        }
    }

    $ip = w3b_counter_ip($cfg);
    if (in_array($ip, $cfg['exclude_ips'], true)) {
        return;
    }

    $ua    = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    $isBot = w3b_counter_is_bot($ua) ? 1 : 0;
    if ($isBot && empty($cfg['record_bots'])) {
        return;
    }

    $now = new DateTime('now', new DateTimeZone($cfg['timezone']));

    // Referrer: keep the host always, and the full URL only for outside traffic.
    $refUrl  = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    $refHost = w3b_counter_ref_host($cfg, $refUrl);
    if ($refHost === '') {
        $refUrl = '';   // '' either because there was none, or it was internal navigation
    }

    // UTM campaign parameters, if this request's URL carries any.
    $query = parse_url($uri, PHP_URL_QUERY);
    $utmSource = $utmMedium = $utmCampaign = '';
    if (is_string($query)) {
        parse_str($query, $q);
        $utmSource   = isset($q['utm_source'])   ? substr((string) $q['utm_source'], 0, 100)   : '';
        $utmMedium   = isset($q['utm_medium'])   ? substr((string) $q['utm_medium'], 0, 100)   : '';
        $utmCampaign = isset($q['utm_campaign']) ? substr((string) $q['utm_campaign'], 0, 100) : '';
    }

    list($browser, $os, $device) = w3b_counter_client($ua);

    // Visitor fingerprint: salted hash of IP + user agent, re-salted every day.
    // Irreversible, and it expires on its own — no cookie, no persistent ID.
    //
    // '' means data/salt.php is missing or unusable. Record nothing at all in
    // that case: an unsalted hash of IP + user agent is reversible by anyone,
    // so writing one would be worse than losing the hit.
    $visitor = w3b_counter_visitor($cfg, $ip, $ua, $now->format('Y-m-d'));
    if ($visitor === '') {
        return;
    }

    $db = w3b_counter_db($cfg);
    $stmt = $db->prepare(
        'INSERT INTO hits (ts, day, hour, path, ref_host, ref_url, browser, os, device, country, visitor, is_bot, utm_source, utm_medium, utm_campaign)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $now->getTimestamp(),
        $now->format('Y-m-d'),
        (int) $now->format('G'),
        $path,
        $refHost,
        substr($refUrl, 0, 500),
        $browser,
        $os,
        $device,
        w3b_counter_country(),
        $visitor,
        $isBot,
        $utmSource,
        $utmMedium,
        $utmCampaign,
    ]);

    // Occasional housekeeping.
    if (!empty($cfg['retention_days']) && random_int(1, 500) === 1) {
        $cutoff = (clone $now)->modify('-' . (int) $cfg['retention_days'] . ' days')->format('Y-m-d');
        $db->prepare('DELETE FROM hits WHERE day < ?')->execute([$cutoff]);
    }
    w3b_counter_backup($cfg, $db);
} catch (Throwable $e) {
    // Silent by design.
} catch (Exception $e) {
    // PHP 5 fallback.
}
