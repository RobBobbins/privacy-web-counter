<?php
/**
 * Privacy Web Counter — configuration
 * Edit this file. Everything else can be replaced on upgrade without losing settings.
 *
 * Prefer a form to editing PHP? Visit install.php once instead — it writes this
 * exact file for you, plus data/salt.php, and then deletes itself.
 *
 * Creating these by hand instead? config.php is not enough on its own: the counter
 * records nothing until data/salt.php exists too. See the salt note below.
 */

return [

    // Where the SQLite database file lives. Kept inside data/ which is web-blocked.
    'db_path' => __DIR__ . '/data/stats.db',

    // Your timezone. Days and hours in the dashboard are calculated with this.
    // Full list: https://www.php.net/manual/en/timezones.php
    'timezone' => 'UTC',

    // NOTE: there is no 'salt' setting here any more. The visitor-hash salt lives
    // in data/salt.php, because data/ is blocked from the web and this file is not.
    // Create it with a long random string of your own, at least 16 characters:
    //
    //     <?php return 'a-long-random-string-you-generate-once';
    //
    // Or let install.php generate one for you. Until that file exists with a usable
    // value, the counter deliberately records nothing at all — an unsalted hash of
    // an IP address can be reversed by anyone, so no data beats bad data.
    // The raw IP address is NEVER stored — only a salted hash that rotates daily.

    // Addresses of proxies permitted to set X-Forwarded-For, X-Real-IP or
    // CF-Connecting-IP. Plain IPs or CIDR ranges, IPv4 or IPv6.
    //
    // Leave this EMPTY unless a CDN or reverse proxy genuinely sits in front of
    // this site. Those headers are written by whoever sends the request, so any
    // address you list here is trusted to claim it is any visitor it likes —
    // which would let it forge unlimited unique visitors, or impersonate an
    // address from exclude_ips and never be counted.
    //
    // Behind Cloudflare, put Cloudflare's published ranges here:
    // https://www.cloudflare.com/ips/
    'trusted_proxies' => [
        // '173.245.48.0/20',
    ],

    // Requests to these paths are never counted. Exact match, case-insensitive.
    // This folder's own live.php (and index.php, unless count_dashboard is on)
    // is excluded automatically — you do not need to list them here, even if
    // you rename the folder.
    'exclude_paths' => [
        '/robots.txt',
        '/favicon.ico',
        '/sitemap.xml',
    ],

    // Requests from these IP addresses are never counted. Put your own home/office
    // IP here so you don't count yourself. Find it at https://ifconfig.me
    'exclude_ips' => [
        // '203.0.113.45',
    ],

    // Record bot/crawler hits? They are stored with is_bot=1 and hidden from the
    // dashboard by default, so leaving this on costs nothing and lets you see
    // crawler activity with the "Include bots" toggle.
    'record_bots' => true,

    // Delete hits older than this many days on a random 1-in-500 request.
    // Set to 0 to keep everything forever.
    'retention_days' => 0,

    // How many daily database backups to keep in data/backups/ before the
    // oldest ones are deleted automatically. A fresh one is taken once a day,
    // on the first hit after midnight. Set to 0 or false to turn this off.
    'backup_days' => 14,

    // --- JavaScript ---

    // Append a small inline script to every counted page that reports
    // document.referrer back to referrer.php via navigator.sendBeacon (or an
    // image pixel as a fallback). This recovers referrers that the Referer
    // header lost — a privacy setting, an app's in-app browser, an https-to-http
    // hop — and turns "no referrer" into a real signal instead of a guess: it
    // can only mean the visit really was direct, or that JavaScript was off.
    // See the README's "Referrer recovery" section for exactly what it sends.
    //
    // Set this to false for a tracker with zero JavaScript, full stop. Core
    // tracking, UTM capture and backups all keep working either way.
    'js_beacon' => true,

    // --- Dashboard settings ---

    'site_name' => 'My Site',

    // Count visits to the dashboard itself as a normal page view? If false,
    // the dashboard stays out of its own stats, like live.php always does.
    'count_dashboard' => true,

    // Seconds between live refreshes on the dashboard. Polling pauses entirely
    // while the tab is in the background. Set to 0 to switch live updating off
    // and go back to a plain static page.
    'live_interval' => 15,

    // Show the "Happening now" feed of the most recent page views?
    // OFF by default. Your dashboard is public, so this feed is public too —
    // anyone watching sees which pages are being read, seconds after it happens.
    // No IP addresses or identities are ever exposed. Turn it on deliberately.
    'live_feed' => false,

    // How many recent hits the "Happening now" live feed shows at once.
    'live_feed_limit' => 10,

    // Show the detailed "Recent visitors" table (one row each, with
    // browser/OS/device/referrer)? OFF by default, and the most revealing thing
    // on the dashboard: on a low-traffic site those rows read as a trace of what
    // one individual browsed, publicly. Aggregate totals above expose nothing of
    // the sort. Turn it on only if you have thought about that.
    'show_recent_log' => false,

    // How many hits the "Recent visitors" table shows.
    'recent_log_limit' => 50,

    // Show the "Campaign sources" card when any hit carries utm_source? Only
    // relevant if you use UTM-tagged links; harmless either way if you don't.
    'show_campaigns' => true,

    // Hostnames that count as "your own site" and are therefore not shown as
    // referrers (internal navigation).
    'own_hosts' => ['example.com', 'www.example.com'],

    // Show a small "LISTEN TO: PGNIP.ca comedy podcast" credit near the top of
    // the dashboard. Entirely optional — set to false to remove it.
    'powered_by' => true,

    // Show a link to this project's GitHub repo at the bottom of the dashboard
    // footer, below the "Visitors are counted..." line.
    'show_github' => true,
];
