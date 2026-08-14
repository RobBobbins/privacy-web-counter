<?php
/**
 * Privacy Web Counter — live JSON endpoint for the dashboard.
 *
 * This file is READ-ONLY against the database. It deliberately does NOT include
 * counter.php, so polling it records nothing and cannot pollute your stats.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex');

try {
    $cfg = require __DIR__ . '/config.php';
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false]);
    exit;
}
date_default_timezone_set($cfg['timezone']);

$ranges   = ['12h' => 1, 'today' => 1, 'yesterday' => 1, '7' => 1, '30' => 1, '90' => 1, '365' => 1, 'all' => 1];
// is_string() first: ?range[]=x makes $_GET['range'] an array, and an array used
// as an array key is a fatal TypeError on PHP 8 — even inside isset().
$range    = isset($_GET['range']) && is_string($_GET['range']) && isset($ranges[$_GET['range']])
    ? $_GET['range'] : '30';
$showBots = !empty($_GET['bots']);

try {
    $db = new PDO('sqlite:' . $cfg['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 3000');

    $bot = $showBots ? '1=1' : 'is_bot = 0';

    $dateWhere  = '1=1';
    $dateParams = [];
    switch ($range) {
        case '12h':
            $dateWhere    = 'ts >= ?';
            $dateParams[] = time() - 12 * 3600;
            break;
        case 'today':
            $dateWhere    = 'day = ?';
            $dateParams[] = date('Y-m-d');
            break;
        case 'yesterday':
            $dateWhere    = 'day = ?';
            $dateParams[] = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'all':
            break;
        default:
            $dateWhere    = 'day >= ?';
            $dateParams[] = date('Y-m-d', strtotime('-' . ((int) $range - 1) . ' days'));
    }

    $where  = "$bot AND $dateWhere";
    $params = $dateParams;

    $one = function ($sql, $p = []) use ($db) {
        $s = $db->prepare($sql);
        $s->execute($p);
        $v = $s->fetchColumn();
        return (int) ($v === false ? 0 : $v);
    };

    $today = date('Y-m-d');
    $now   = time();

    $out = [
        'ok'           => true,
        'ts'           => $now,
        'views'        => $one("SELECT COUNT(*) FROM hits WHERE $where", $params),
        'visitors'     => $one("SELECT COUNT(*) FROM (SELECT 1 FROM hits WHERE $where GROUP BY day, visitor)", $params),
        'pages'        => $one("SELECT COUNT(DISTINCT path) FROM hits WHERE $where", $params),
        'viewsToday'   => $one("SELECT COUNT(*) FROM hits WHERE $bot AND day = ?", [$today]),
        'visitToday'   => $one("SELECT COUNT(*) FROM (SELECT 1 FROM hits WHERE $bot AND day = ? GROUP BY visitor)", [$today]),
        'allTimeViews' => $one("SELECT COUNT(*) FROM hits WHERE $bot"),
        // Distinct people seen in the last five minutes.
        'online'       => $one("SELECT COUNT(DISTINCT visitor) FROM hits WHERE $bot AND ts >= ?", [$now - 300]),
        'feed'         => [],
    ];

    if (!empty($cfg['live_feed'])) {
        $liveFeedLimit = isset($cfg['live_feed_limit']) ? (int) $cfg['live_feed_limit'] : 10;
        $s = $db->prepare(
            "SELECT ts, path, browser, device, country, ref_host, is_bot, js_status
             FROM hits WHERE $bot ORDER BY id DESC LIMIT $liveFeedLimit"
        );
        $s->execute();
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['feed'][] = [
                'ago'     => max(0, $now - (int) $r['ts']),
                'path'    => $r['path'],
                'browser' => $r['browser'],
                'device'  => $r['device'],
                'country' => $r['country'],
                'ref'     => $r['ref_host'],
                'bot'     => (int) $r['is_bot'] === 1,
                'js'      => $r['js_status'] !== '',
            ];
        }
    }

    echo json_encode($out);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false]);
}
