<?php
/**
 * Privacy Web Counter — public stats dashboard.
 *
 * Reads the SQLite database written by counter.php and renders everything as
 * one self-contained page. No external CSS, JS, fonts or images.
 *
 * This page includes counter.php itself, so a visit to the dashboard is
 * tracked like any other page when config['count_dashboard'] is on (the
 * default). Set it to false to keep the dashboard out of its own stats.
 */

require_once __DIR__ . '/counter.php';

try {
    $cfg = require __DIR__ . '/config.php';
} catch (Throwable $e) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Not set up yet</title>'
       . '<body style="font:16px system-ui;padding:2rem;max-width:40rem">'
       . '<h1>Not set up yet</h1><p>config.php has not been created. '
       . '<a href="install.php">Run the setup wizard</a> to create it, or copy '
       . 'config.example.php to config.php yourself.</p>';
    exit;
}
date_default_timezone_set($cfg['timezone']);

try {
    $db = new PDO('sqlite:' . $cfg['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 3000');
    $db->query('SELECT 1 FROM hits LIMIT 1');
} catch (Exception $e) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Stats</title>'
       . '<body style="font:16px system-ui;padding:2rem;max-width:40rem">'
       . '<h1>No data yet</h1><p>The counter database has not been created. It appears '
       . 'automatically the first time a page carrying the counter is visited.</p>'
       . '<p style="color:#888">' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>';
    exit;
}

/* ---------- range selection ---------- */

$ranges = [
    '12h'       => 'Last 12 hours',
    'today'     => 'Today',
    'yesterday' => 'Yesterday',
    '7'         => 'Last 7 days',
    '30'        => 'Last 30 days',
    '90'        => 'Last 90 days',
    '365'       => 'Last year',
    'all'       => 'All time',
];
$range    = isset($_GET['range']) && isset($ranges[$_GET['range']]) ? $_GET['range'] : '30';
$showBots = !empty($_GET['bots']);

// $dateWhere/$dateParams hold just the date-range clause (no bot filter), so
// they can be reused as-is for the bot count below, which needs the same
// window but the opposite is_bot condition.
$dateWhere  = '1=1';
$dateParams = [];
$from = null;
switch ($range) {
    case '12h':
        $dateWhere    = 'ts >= ?';
        $dateParams[] = time() - 12 * 3600;
        break;
    case 'today':
        $from         = date('Y-m-d');
        $dateWhere    = 'day = ?';
        $dateParams[] = $from;
        break;
    case 'yesterday':
        $from         = date('Y-m-d', strtotime('-1 day'));
        $dateWhere    = 'day = ?';
        $dateParams[] = $from;
        break;
    case 'all':
        break;
    default:
        $from         = date('Y-m-d', strtotime('-' . ((int) $range - 1) . ' days'));
        $dateWhere    = 'day >= ?';
        $dateParams[] = $from;
}

$where  = ($showBots ? '1=1' : 'is_bot = 0') . ' AND ' . $dateWhere;
$params = $dateParams;

function q(PDO $db, $sql, array $p = [])
{
    $s = $db->prepare($sql);
    $s->execute($p);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
function q1(PDO $db, $sql, array $p = [])
{
    $s = $db->prepare($sql);
    $s->execute($p);
    $v = $s->fetchColumn();
    return $v === false ? 0 : $v;
}
function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function n($v) { return number_format((float) $v); }

/* ---------- headline numbers ---------- */

$views    = q1($db, "SELECT COUNT(*) FROM hits WHERE $where", $params);
$visitors = q1($db, "SELECT COUNT(*) FROM (SELECT 1 FROM hits WHERE $where GROUP BY day, visitor)", $params);
$pages    = q1($db, "SELECT COUNT(DISTINCT path) FROM hits WHERE $where", $params);

$today        = date('Y-m-d');
$yesterday    = date('Y-m-d', strtotime('-1 day'));
$botFilter    = $showBots ? '1=1' : 'is_bot = 0';
$viewsToday   = q1($db, "SELECT COUNT(*) FROM hits WHERE $botFilter AND day = ?", [$today]);
$visitToday   = q1($db, "SELECT COUNT(*) FROM (SELECT 1 FROM hits WHERE $botFilter AND day = ? GROUP BY visitor)", [$today]);
$viewsYest    = q1($db, "SELECT COUNT(*) FROM hits WHERE $botFilter AND day = ?", [$yesterday]);
$allTimeViews = q1($db, "SELECT COUNT(*) FROM hits WHERE $botFilter");
$firstDay     = q1($db, "SELECT MIN(day) FROM hits");
$botCount     = q1($db, "SELECT COUNT(*) FROM hits WHERE is_bot = 1 AND $dateWhere", $dateParams);

$delta = $viewsYest > 0 ? round((($viewsToday - $viewsYest) / $viewsYest) * 100) : null;

// Live updating. The panel is hidden until JavaScript reveals it, so a browser
// with JS disabled sees a normal static page rather than a dead section.
$liveInterval = isset($cfg['live_interval']) ? (int) $cfg['live_interval'] : 15;
$liveOn       = $liveInterval > 0;
$online       = (int) q1($db, "SELECT COUNT(DISTINCT visitor) FROM hits WHERE $botFilter AND ts >= ?", [time() - 300]);

/* ---------- breakdowns ---------- */

$daily = q($db, "SELECT day, COUNT(*) v, COUNT(DISTINCT visitor) u FROM hits WHERE $where GROUP BY day ORDER BY day", $params);

$topPages = q($db, "SELECT path, COUNT(*) v, COUNT(DISTINCT visitor) u FROM hits WHERE $where
                    GROUP BY path ORDER BY v DESC LIMIT 25", $params);

$topRefs = q($db, "SELECT ref_host, COUNT(*) v FROM hits WHERE $where AND ref_host != ''
                   GROUP BY ref_host ORDER BY v DESC LIMIT 20", $params);
$directViews = q1($db, "SELECT COUNT(*) FROM hits WHERE $where AND ref_host = ''", $params);

// Whether the page's JavaScript ran at all, for any hit — not just direct ones.
// The beacon fires on every page; if it reaches referrer.php, js_status ends up
// 'confirmed' or 'recovered', otherwise it stays ''. Meaningless when the beacon
// is switched off, so these are only shown on the dashboard when js_beacon is on.
$jsRan     = q1($db, "SELECT COUNT(*) FROM hits WHERE $where AND js_status != ''", $params);
$jsNot     = q1($db, "SELECT COUNT(*) FROM hits WHERE $where AND js_status = ''", $params);
$recovered = q1($db, "SELECT COUNT(*) FROM hits WHERE $where AND js_status = 'recovered'", $params);

$showRecentLog = !empty($cfg['show_recent_log']);
$recentLogLimit = isset($cfg['recent_log_limit']) ? (int) $cfg['recent_log_limit'] : 50;
$recentHits = $showRecentLog
    ? q($db, "SELECT ts, path, browser, os, device, ref_host, js_status, is_bot FROM hits WHERE $where
              ORDER BY ts DESC LIMIT $recentLogLimit", $params)
    : [];

$showCampaigns = !empty($cfg['show_campaigns']);
$campaigns = $showCampaigns
    ? q($db, "SELECT utm_source, utm_medium, utm_campaign, COUNT(*) v FROM hits WHERE $where AND utm_source != ''
              GROUP BY utm_source, utm_medium, utm_campaign ORDER BY v DESC LIMIT 20", $params)
    : [];
$campaigns = array_map(function ($r) {
    $label = $r['utm_source'];
    if ($r['utm_medium'] !== '')   $label .= ' / ' . $r['utm_medium'];
    if ($r['utm_campaign'] !== '') $label .= ' / ' . $r['utm_campaign'];
    return ['label' => $label, 'v' => $r['v']];
}, $campaigns);

$browsers  = q($db, "SELECT browser AS k, COUNT(*) v FROM hits WHERE $where GROUP BY k ORDER BY v DESC LIMIT 10", $params);
$systems   = q($db, "SELECT os      AS k, COUNT(*) v FROM hits WHERE $where GROUP BY k ORDER BY v DESC LIMIT 10", $params);
$devices   = q($db, "SELECT device  AS k, COUNT(*) v FROM hits WHERE $where GROUP BY k ORDER BY v DESC", $params);
$countries = q($db, "SELECT country AS k, COUNT(*) v FROM hits WHERE $where AND country != '' GROUP BY k ORDER BY v DESC LIMIT 15", $params);

// Hour-of-day x day-of-week grid for the "Hourly activity" heatmap. Built from
// day+hour pairs already stored per hit rather than a new SQL date function, so
// it works the same on every SQLite build.
$hourGrid = array_fill(0, 7, array_fill(0, 24, 0)); // rows: 0=Mon..6=Sun
foreach (q($db, "SELECT day, hour, COUNT(*) v FROM hits WHERE $where GROUP BY day, hour", $params) as $r) {
    $dow = (int) date('N', strtotime($r['day'])) - 1; // ISO: 1=Mon..7=Sun -> 0=Mon..6=Sun
    $hourGrid[$dow][(int) $r['hour']] += (int) $r['v'];
}
$hourGridMax = 0;
foreach ($hourGrid as $row) {
    $hourGridMax = max($hourGridMax, max($row));
}
$hourGridMax = $hourGridMax ?: 1;

/** Bucket a value into 6 heat levels (0 = lightest, 5 = darkest) relative to a max. */
function heatBucket($v, $max)
{
    if ($max <= 0 || $v <= 0) {
        return 0;
    }
    return min(5, (int) floor($v / $max * 6));
}

/* ---------- fill gaps in the daily series ---------- */

$series = [];
if (in_array($range, ['7', '30', '90', '365'], true)) {
    for ($i = (int) $range - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $series[$d] = ['v' => 0, 'u' => 0];
    }
} elseif ($range === 'today' || $range === 'yesterday') {
    $series[$from] = ['v' => 0, 'u' => 0];
}
foreach ($daily as $r) {
    $series[$r['day']] = ['v' => (int) $r['v'], 'u' => (int) $r['u']];
}
if ($range === 'all' && count($series) > 120) {
    $series = array_slice($series, -120, null, true);
}
$maxDay = $series ? max(array_column($series, 'v')) : 0;

function url($overrides)
{
    global $range, $showBots;
    $p = array_merge(['range' => $range, 'bots' => $showBots ? 1 : 0], $overrides);
    if (!$p['bots']) unset($p['bots']);
    return '?' . http_build_query($p);
}

/** One row of a horizontal "top N" bar list. */
function bars(array $rows, $labelKey, $valueKey, $total, $formatter = null)
{
    if (!$rows) {
        echo '<p class="empty">Nothing recorded yet.</p>';
        return;
    }
    $max = max(array_column($rows, $valueKey)) ?: 1;
    echo '<ul class="bars">';
    foreach ($rows as $r) {
        $label = $formatter ? $formatter($r[$labelKey]) : h($r[$labelKey]);
        $pct   = $total > 0 ? round($r[$valueKey] / $total * 100, 1) : 0;
        $w     = round($r[$valueKey] / $max * 100, 2);
        echo '<li><span class="fill" style="width:' . $w . '%"></span>'
           . '<span class="lab">' . $label . '</span>'
           . '<span class="val">' . n($r[$valueKey]) . ' <em>' . $pct . '%</em></span></li>';
    }
    echo '</ul>';
}
?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Stats — <?= h($cfg['site_name']) ?></title>
<script>
try {
  var w3bTheme = localStorage.getItem('w3b_theme');
  if (w3bTheme === 'light' || w3bTheme === 'dark') document.documentElement.setAttribute('data-theme', w3bTheme);
} catch (e) {}
</script>
<style>
:root{
  --bg:#ffffff; --ink:#16181d; --dim:#6b7280; --line:#e4e5e9;
  --accent:#2563eb; --accent-soft:#dbe6ff; --bot:#dc2626; --ok:#16a34a;
  --heat-0:#eaf1fd; --heat-1:#cfe0fa; --heat-2:#a8c8f5; --heat-3:#7aa9ee; --heat-4:#4a86e0; --heat-5:#2563eb;
}
@media (prefers-color-scheme:dark){
  :root:not([data-theme="light"]){
    --bg:#000000; --ink:#e8eaee; --dim:#9099a6; --line:#26262b;
    --accent:#1d4ed8; --accent-soft:#101b3d; --bot:#b91c1c; --ok:#16a34a;
    --heat-0:#0f1830; --heat-1:#132352; --heat-2:#1a2f74; --heat-3:#22409e; --heat-4:#2c52c4; --heat-5:#3866e8;
  }
}
:root[data-theme="dark"]{
  --bg:#000000; --ink:#e8eaee; --dim:#9099a6; --line:#26262b;
  --accent:#1d4ed8; --accent-soft:#101b3d; --bot:#b91c1c; --ok:#16a34a;
  --heat-0:#0f1830; --heat-1:#132352; --heat-2:#1a2f74; --heat-3:#22409e; --heat-4:#2c52c4; --heat-5:#3866e8;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);
  font:15px/1.5 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.wrap{max-width:1100px;margin:0 auto;padding:1.5rem 1rem 4rem}
h1{font-size:1.4rem;margin:0 0 .15rem}
h2{font-size:.8rem;letter-spacing:.09em;text-transform:uppercase;color:var(--dim);margin:0 0 .75rem}
a{color:var(--accent)}
.credit{color:var(--dim);font-size:.78rem;margin:0}
.credit a{color:inherit;text-decoration:underline}
.sub{color:var(--dim);font-size:.85rem;margin:0 0 1.25rem}
.topbar{display:flex;align-items:center;gap:1rem;margin-bottom:.5rem}
.theme-toggle{background:transparent;color:var(--dim);border:1px solid var(--line);border-radius:0;
  padding:.3rem .6rem;font:inherit;font-size:.75rem;cursor:pointer;white-space:nowrap;margin-left:auto}
.theme-toggle:hover{color:var(--ink);border-color:var(--accent)}
.tabs{display:flex;flex-wrap:wrap;gap:1.1rem;margin-bottom:1.75rem}
.tabs a{text-decoration:none;color:var(--dim);font-size:.85rem;padding-bottom:.25rem;
  border-bottom:2px solid transparent}
.tabs a.on{color:var(--ink);font-weight:600;border-bottom-color:var(--accent)}
.tabs a.bot.on{border-bottom-color:var(--bot)}
.section{margin-bottom:2rem}
.kpis{display:flex;flex-wrap:wrap;gap:2rem;margin-bottom:2rem}
.kpi b{display:block;font-size:1.7rem;font-weight:650;line-height:1.15;letter-spacing:-.02em}
.kpi span{color:var(--dim);font-size:.78rem}
.up{color:var(--ok)}.down{color:var(--bot)}
.grid{display:grid;gap:2rem;grid-template-columns:repeat(auto-fit,minmax(240px,1fr))}
/* Each column is a full-height track; the <i> inside is the value. The track
   must have a real height or the percentage on <i> has nothing to resolve against. */
.chart{display:flex;align-items:stretch;gap:2px;height:160px}
.chart div{flex:1;min-width:2px;height:100%;background:var(--line);border-radius:2px 2px 0 0;position:relative}
.chart div i{position:absolute;left:0;right:0;bottom:0;background:var(--accent);border-radius:2px 2px 0 0;display:block}
.xaxis{display:flex;justify-content:space-between;color:var(--dim);font-size:.72rem;margin-top:.45rem}
.heat-hourly{display:grid;grid-template-columns:repeat(24,1fr);gap:2px}
.heat-hourly div{aspect-ratio:1;border-radius:2px}
ul.bars{list-style:none;margin:0;padding:0}
ul.bars li{position:relative;display:flex;justify-content:space-between;gap:1rem;
  padding:.4rem .55rem;border-radius:5px;font-size:.88rem;overflow:hidden}
ul.bars .fill{position:absolute;inset:0 auto 0 0;background:var(--accent-soft);border-radius:5px}
ul.bars .lab{position:relative;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
ul.bars .val{position:relative;white-space:nowrap;color:var(--dim);font-variant-numeric:tabular-nums}
ul.bars .val em{font-style:normal;opacity:.65;font-size:.8em}
.empty{color:var(--dim);font-size:.88rem;margin:0}
.log-wrap{overflow-x:auto}
table.log{width:100%;border-collapse:collapse;font-size:.85rem}
table.log th{text-align:left;color:var(--dim);font-weight:600;font-size:.7rem;
  text-transform:uppercase;letter-spacing:.06em;padding:.4rem .6rem;border-bottom:1px solid var(--line)}
table.log td{padding:.4rem .6rem;border-bottom:1px solid var(--line);white-space:nowrap}
table.log tr:last-child td{border-bottom:0}
/* live panel */
.live h2{display:flex;align-items:center;gap:.5rem}
.live .dot{width:8px;height:8px;border-radius:50%;background:#16a34a;flex:none;
  box-shadow:0 0 0 0 rgba(22,163,74,.55);animation:ping 2s ease-out infinite}
@keyframes ping{70%,100%{box-shadow:0 0 0 7px rgba(22,163,74,0)}}
.live .pulse{margin-left:auto;text-transform:none;letter-spacing:0;font-size:.75rem;opacity:.75}
.live .online{margin:0 0 .5rem;display:flex;align-items:baseline;gap:.5rem}
.live .online b{font-size:1.7rem;font-weight:650;letter-spacing:-.02em}
.live .online span{color:var(--dim);font-size:.85rem}
ul.feed{list-style:none;margin:.4rem 0 0;padding:0;border-top:1px solid var(--line)}
ul.feed li{display:flex;gap:.75rem;align-items:baseline;padding:.4rem .1rem;
  border-bottom:1px solid var(--line);font-size:.85rem}
ul.feed li:last-child{border-bottom:0}
ul.feed .p{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
ul.feed .m{color:var(--dim);font-size:.78rem;white-space:nowrap}
ul.feed li.fresh{animation:flash 1.6s ease-out}
@keyframes flash{from{background:var(--accent-soft)}to{background:transparent}}
[data-live].bump{animation:flash 1.2s ease-out;border-radius:4px}
@media (prefers-reduced-motion:reduce){
  .live .dot,ul.feed li.fresh,[data-live].bump{animation:none}
}
footer{color:var(--dim);font-size:.78rem;margin-top:2rem;line-height:1.7}
</style>
<div class="wrap">

  <div class="topbar">
    <?php if (!empty($cfg['powered_by'])): ?>
      <p class="credit">LISTEN TO: <a href="https://pgnip.ca" rel="noopener">PGNIP.ca</a> comedy podcast</p>
    <?php endif; ?>
    <button type="button" id="theme-toggle" class="theme-toggle">Dark mode</button>
  </div>

  <h1><?= h($cfg['site_name']) ?> — traffic</h1>
  <p class="sub">
    Counted server-side. No cookies, no third parties, no IP addresses stored.
    <?= !empty($cfg['js_beacon']) ? 'Uses one small JavaScript beacon for referrer recovery — see the README.' : 'No JavaScript.' ?>
    <?php if ($firstDay): ?>Recording since <?= h($firstDay) ?>.<?php endif; ?>
  </p>

  <nav class="tabs">
    <?php foreach ($ranges as $k => $label): ?>
      <a class="<?= (string) $k === (string) $range ? 'on' : '' ?>" href="<?= h(url(['range' => $k])) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
    <a class="bot <?= $showBots ? 'on' : '' ?>" href="<?= h(url(['bots' => $showBots ? 0 : 1])) ?>">
      <?= $showBots ? 'Bots included' : 'Include bots' ?> (<?= n($botCount) ?>)
    </a>
  </nav>

  <div class="kpis">
    <div class="kpi"><b data-live="views"><?= n($views) ?></b><span>Page views</span></div>
    <div class="kpi"><b data-live="visitors"><?= n($visitors) ?></b><span>Unique visitors</span></div>
    <div class="kpi"><b data-live="pages"><?= n($pages) ?></b><span>Pages visited</span></div>
    <div class="kpi">
      <b data-live="viewsToday"><?= n($viewsToday) ?></b>
      <span>Views today
        <?php if ($delta !== null): ?>
          <?= $delta >= 0
              ? '<span class="up">▲ ' . $delta . '%</span>'
              : '<span class="down">▼ ' . abs($delta) . '%</span>' ?> vs yesterday
        <?php endif; ?>
      </span>
    </div>
    <div class="kpi"><b data-live="visitToday"><?= n($visitToday) ?></b><span>Visitors today</span></div>
    <div class="kpi"><b data-live="allTimeViews"><?= n($allTimeViews) ?></b><span>All-time views</span></div>
  </div>

  <div class="section">
    <h2>Views per day</h2>
    <?php if ($maxDay > 0): ?>
      <div class="chart">
        <?php foreach ($series as $d => $r): ?>
          <div title="<?= h($d) ?> — <?= n($r['v']) ?> views, <?= n($r['u']) ?> visitors">
            <i style="height:<?= round($r['v'] / $maxDay * 100, 2) ?>%"></i>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="xaxis">
        <?php $days = array_keys($series); ?>
        <span><?= h(reset($days)) ?></span>
        <span>peak <?= n($maxDay) ?>/day</span>
        <span><?= h(end($days)) ?></span>
      </div>
    <?php else: ?>
      <p class="empty">No views in this range yet.</p>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Hourly activity (<?= h($cfg['timezone']) ?>)</h2>
    <?php if ($views > 0): ?>
      <div class="heat-hourly">
        <?php foreach ($hourGrid as $dow => $hours): foreach ($hours as $hr => $v): ?>
          <div style="background:var(--heat-<?= heatBucket($v, $hourGridMax) ?>)"
               title="<?= h(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$dow]) ?> <?= $hr ?>:00 — <?= n($v) ?> views"></div>
        <?php endforeach; endforeach; ?>
      </div>
      <div class="xaxis"><span>12am</span><span>12pm</span><span>11pm</span></div>
    <?php else: ?>
      <p class="empty">No views in this range yet.</p>
    <?php endif; ?>
  </div>

  <div class="grid section">
    <div>
      <h2>Top pages</h2>
      <?php bars($topPages, 'path', 'v', $views, function ($p) {
          return '<a href="' . h($p) . '">' . h($p) . '</a>';
      }); ?>
    </div>

    <div>
      <h2>Where visitors came from</h2>
      <?php
      $refRows = $topRefs;
      if ($directViews > 0) {
          array_unshift($refRows, ['ref_host' => 'Direct / bookmark / no referrer', 'v' => $directViews]);
          usort($refRows, function ($a, $b) { return $b['v'] - $a['v']; });
      }
      bars($refRows, 'ref_host', 'v', $views);
      ?>
    </div>

    <?php if (!empty($cfg['js_beacon'])): ?>
    <div>
      <h2>JavaScript enabled</h2>
      <?php
      $jsStats = [];
      if ($views > 0) {
          $jsStats[] = ['k' => 'JavaScript ran', 'v' => $jsRan];
          $jsStats[] = ['k' => 'JavaScript did not run', 'v' => $jsNot];
      }
      bars($jsStats, 'k', 'v', $views);
      if ($recovered > 0):
      ?>
        <p class="empty">+<?= n($recovered) ?> referrer<?= $recovered === 1 ? '' : 's' ?> recovered via JS
        that the Referer header lost.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($showRecentLog): ?>
  <div class="section">
    <h2>Recent visitors</h2>
    <?php if ($recentHits): ?>
    <div class="log-wrap">
      <table class="log">
        <tr><th>Time</th><th>Page</th><th>Browser</th><th>OS</th><th>Device</th><th>Referrer</th>
          <?php if (!empty($cfg['js_beacon'])): ?><th>JS</th><?php endif; ?></tr>
        <?php foreach ($recentHits as $r): ?>
          <tr>
            <td><?= h(date('M j, H:i', (int) $r['ts'])) ?></td>
            <td><?= h($r['path']) ?><?= $r['is_bot'] ? ' <span style="color:var(--bot)">· bot</span>' : '' ?></td>
            <td><?= h($r['browser']) ?></td>
            <td><?= h($r['os']) ?></td>
            <td><?= h($r['device']) ?></td>
            <td><?= $r['ref_host'] !== '' ? h($r['ref_host']) : 'Direct' ?></td>
            <?php if (!empty($cfg['js_beacon'])): ?>
              <td><?= $r['js_status'] !== '' ? '<span class="up">✓</span>' : '<span class="down">✗</span>' ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php else: ?>
      <p class="empty">Nothing recorded yet.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($showCampaigns && $campaigns): ?>
    <div class="section">
      <h2>Campaign sources</h2>
      <?php bars($campaigns, 'label', 'v', $views); ?>
    </div>
  <?php endif; ?>

  <div class="grid section">
    <div><h2>Browsers</h2><?php bars($browsers, 'k', 'v', $views); ?></div>
    <div><h2>Operating systems</h2><?php bars($systems, 'k', 'v', $views); ?></div>
    <div><h2>Device type</h2><?php bars($devices, 'k', 'v', $views); ?></div>
  </div>

  <?php if ($countries): ?>
    <div class="section"><h2>Countries</h2><?php bars($countries, 'k', 'v', $views); ?></div>
  <?php endif; ?>

  <?php if ($liveOn): ?>
  <div class="live section" id="live" hidden>
    <h2>
      <span class="dot"></span>Happening now
      <span class="pulse" id="live-status">connecting…</span>
    </h2>
    <p class="online"><b data-live="online"><?= n($online) ?></b>
      <span><?= $online === 1 ? 'person' : 'people' ?> on the site in the last 5 minutes</span></p>
    <?php if (!empty($cfg['live_feed'])): ?>
      <ul class="feed" id="live-feed"></ul>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <footer>
    Visitors are counted with a one-way hash of IP address and browser that is re-salted every
    midnight and then discarded — it cannot be reversed or followed from one day to the next.
    <?php if (!$countries): ?><br>Country data is unavailable: your host is not passing a
    country header. Everything else works regardless.<?php endif; ?>
    <?php if (!empty($cfg['show_github'])): ?>
      <br><a href="https://github.com/RobBobbins/privacy-web-counter">Privacy Web Counter on GitHub</a>
    <?php endif; ?>
  </footer>

</div>

<script>
(function () {
  var root = document.documentElement;
  var btn  = document.getElementById('theme-toggle');
  if (!btn) return;

  function current() {
    var explicit = root.getAttribute('data-theme');
    if (explicit) return explicit;
    return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function label(theme) {
    btn.textContent = theme === 'dark' ? 'Light mode' : 'Dark mode';
  }

  label(current());
  btn.addEventListener('click', function () {
    var next = current() === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    try { localStorage.setItem('w3b_theme', next); } catch (e) {}
    label(next);
  });
})();
</script>

<?php if ($liveOn): ?>
<script>
/* Live updating for this dashboard only.
   It reads live.php (in this same folder) and rewrites the numbers in place.
   It records nothing — live.php never loads the tracker — and none of this
   runs on any page a visitor sees. With JavaScript off, the page above is
   still complete. */
(function () {
  var every  = <?= $liveInterval * 1000 ?>;
  var jsBeaconOn = <?= !empty($cfg['js_beacon']) ? 'true' : 'false' ?>;
  var url    = 'live.php?range=<?= h(rawurlencode($range)) ?>&bots=<?= $showBots ? 1 : 0 ?>';
  var card   = document.getElementById('live');
  var status = document.getElementById('live-status');
  var feedEl = document.getElementById('live-feed');
  var timer  = null;
  var last   = {};

  if (card) card.hidden = false;

  function fmt(n) { return n.toLocaleString(); }

  function ago(s) {
    if (s < 10)   return 'just now';
    if (s < 60)   return s + 's ago';
    if (s < 3600) return Math.floor(s / 60) + 'm ago';
    if (s < 86400) return Math.floor(s / 3600) + 'h ago';
    return Math.floor(s / 86400) + 'd ago';
  }

  function setNum(key, val) {
    var el = document.querySelector('[data-live="' + key + '"]');
    if (!el) return;
    var next = fmt(val);
    if (el.textContent === next) return;
    el.textContent = next;
    el.classList.remove('bump');
    void el.offsetWidth;          // restart the highlight animation
    el.classList.add('bump');
  }

  function renderFeed(rows) {
    if (!feedEl) return;
    var seen = {};
    Array.prototype.forEach.call(feedEl.children, function (li) { seen[li.dataset.k] = 1; });

    feedEl.innerHTML = '';
    rows.forEach(function (r) {
      var key = r.ago + '|' + r.path + '|' + r.browser;
      var li  = document.createElement('li');
      li.dataset.k = key;

      var p = document.createElement('span');
      p.className = 'p';
      p.textContent = r.path + (r.bot ? '  · bot' : '');

      var m = document.createElement('span');
      m.className = 'm';
      var bits = [r.device, r.browser];
      if (r.country) bits.push(r.country);
      if (r.ref)     bits.push('via ' + r.ref);
      m.textContent = bits.join(' · ');

      var t = document.createElement('span');
      t.className = 'm';
      t.textContent = ago(r.ago);

      li.appendChild(p); li.appendChild(m);
      if (jsBeaconOn) {
        var j = document.createElement('span');
        j.className = 'm ' + (r.js ? 'up' : 'down');
        j.textContent = r.js ? 'JS ✓' : 'JS ✗';
        li.appendChild(j);
      }
      li.appendChild(t);
      if (!seen[key] && Object.keys(seen).length) li.classList.add('fresh');
      feedEl.appendChild(li);
    });
  }

  function poll() {
    fetch(url, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function (d) {
        if (!d.ok) throw 0;
        ['views','visitors','pages','viewsToday','visitToday','allTimeViews','online']
          .forEach(function (k) { if (k in d) setNum(k, d[k]); });
        if (d.feed) renderFeed(d.feed);
        if (status) status.textContent = 'updated ' + new Date().toLocaleTimeString();
        last = d;
      })
      .catch(function () {
        if (status) status.textContent = 'offline — retrying';
      });
  }

  /* Only poll while the tab is actually being looked at. A dashboard left open
     in a background tab all day would otherwise cost thousands of pointless
     queries; this drops that to zero. */
  function start() { if (!timer) { poll(); timer = setInterval(poll, every); } }
  function stop()  { if (timer) { clearInterval(timer); timer = null;
                     if (status) status.textContent = 'paused'; } }

  document.addEventListener('visibilitychange', function () {
    document.hidden ? stop() : start();
  });
  window.addEventListener('pagehide', stop);

  if (!document.hidden) start();
})();
</script>
<?php endif; ?>
</html>
