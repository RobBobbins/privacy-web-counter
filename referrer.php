<?php
/**
 * Privacy Web Counter — referrer recovery endpoint.
 *
 * Receives the small beacon that counter.php appends to every counted page
 * when config['js_beacon'] is on. It patches the matching hit's referrer, or
 * marks it confirmed direct, so "Direct" on the dashboard means "the browser
 * checked and there really wasn't one" instead of just "the server didn't get
 * a Referer header."
 *
 * Deliberately does NOT include counter.php — hitting this endpoint must never
 * itself count as a page view.
 *
 * POST only. This endpoint writes to the database, and a GET that writes can be
 * fired by any third-party page simply by embedding an <img> tag pointing here:
 * the visitor's browser would send it with their real IP and user agent, so a
 * stranger's page could patch a row belonging to someone reading yours. Only
 * navigator.sendBeacon reaches this, which is a POST.
 *
 * No rate limiting: a request here can only ever patch a row matching the same
 * visitor hash, path and 5-second window, so a flood of junk costs no more than
 * hitting any other page on the site.
 */

require_once __DIR__ . '/lib.php';

header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex');

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

try {
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }

    $cfg = require __DIR__ . '/config.php';
    date_default_timezone_set($cfg['timezone']);

    if (empty($cfg['js_beacon'])) {
        // The beacon is switched off; ignore anything that still hits this URL.
        throw new RuntimeException('js_beacon disabled');
    }

    $body = json_decode((string) file_get_contents('php://input'), true);
    $path = isset($body['p']) && is_string($body['p']) ? $body['p'] : '';
    $ref  = isset($body['r']) && is_string($body['r']) ? $body['r'] : '';

    $path    = substr((string) (parse_url($path, PHP_URL_PATH) ?: '/'), 0, 300);
    $refHost = w3b_counter_ref_host($cfg, $ref);
    $refUrl  = $refHost === '' ? '' : substr($ref, 0, 500);

    $ip      = w3b_counter_ip($cfg);
    $ua      = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    $now     = time();
    $visitor = w3b_counter_visitor($cfg, $ip, $ua, date('Y-m-d', $now));
    if ($visitor === '') {
        throw new RuntimeException('no usable salt');
    }

    $db = w3b_counter_db($cfg);

    // Most recent still-unpatched hit from this exact visitor and page, landed
    // in the last 5 seconds. No match means either it's too late, it was
    // already patched, or the row never existed — any of which is a silent
    // no-op, which also serves as this endpoint's deduplication.
    $find = $db->prepare(
        "SELECT id FROM hits WHERE visitor = ? AND path = ? AND js_status = '' AND ts >= ? ORDER BY ts DESC LIMIT 1"
    );
    $find->execute([$visitor, $path, $now - 5]);
    $id = $find->fetchColumn();

    if ($id !== false) {
        if ($refHost !== '') {
            $db->prepare("UPDATE hits SET ref_host = ?, ref_url = ?, js_status = 'recovered' WHERE id = ?")
               ->execute([$refHost, $refUrl, $id]);
        } else {
            $db->prepare("UPDATE hits SET js_status = 'confirmed' WHERE id = ?")->execute([$id]);
        }
    }
} catch (Throwable $e) {
    // Silent by design — a broken beacon must never surface to the visitor.
}

http_response_code(204);
