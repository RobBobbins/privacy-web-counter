<?php
/**
 * Privacy Web Counter — configuration
 * Edit this file. Everything else can be replaced on upgrade without losing settings.
 *
 * Prefer a form to editing PHP? Visit install.php once instead — it writes this
 * exact file for you and then refuses to run again.
 */

return [

    // Where the SQLite database file lives. Kept inside data/ which is web-blocked.
    'db_path' => __DIR__ . '/data/stats.db',

    // Your timezone. Days and hours in the dashboard are calculated with this.
    // Full list: https://www.php.net/manual/en/timezones.php
    'timezone' => 'UTC',

    // Random secret used to hash visitor fingerprints. CHANGE THIS ONCE, then never
    // change it again (changing it resets unique-visitor counting).
    // The raw IP address is NEVER stored — only a salted hash that rotates daily.
    'salt' => 'PUT-A-LONG-RANDOM-STRING-HERE',

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
    // NOTE: your dashboard is public, so this feed is public too — anyone
    // watching sees which pages are being read, seconds after it happens.
    // No IP addresses or identities are ever exposed, but set this to false
    // if you'd rather not broadcast it.
    'live_feed' => true,

    // Show the detailed "Recent visitors" table (last 50 hits, one row each,
    // with browser/OS/device/referrer)? More revealing than the live feed
    // above since it doesn't auto-hide anything — set to false to drop it.
    'show_recent_log' => true,

    // Show the "Campaign sources" card when any hit carries utm_source? Only
    // relevant if you use UTM-tagged links; harmless either way if you don't.
    'show_campaigns' => true,

    // Hostnames that count as "your own site" and are therefore not shown as
    // referrers (internal navigation).
    'own_hosts' => ['example.com', 'www.example.com'],

    // Show a small "Powered by w3bguru.com" credit near the top of the dashboard.
    // Entirely optional — set to false to remove it, no strings attached.
    'powered_by' => true,
];
