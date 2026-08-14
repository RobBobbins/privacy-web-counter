# Privacy Web Counter

Paid analytics adds up fast, and half of what it's supposed to show you is already gone
by the time it loads — uBlock Origin, Brave Shields, Safari ITP, Firefox ETP and every
privacy-focused browser block third-party analytics scripts before they fire, so a
growing share of real visits never show up at all. This is the alternative: it counts
server-side, on your own server, and shows just the basics — views, visitors,
referrers, the shape of a day — as unintrusively as a counter can be.

Those blockers work from lists of known third-party tracker domains and scripts —
Google Analytics, Meta Pixel, most hosted "privacy-friendly" analytics too, since even
those load a script from someone else's server. There's nothing here for a list like
that to match: counting happens in PHP on your own server while the page itself is
being built, before anything is sent to the browser. No separate script tag, no
separate domain, no separate request — so there's nothing to block, not because it's
hiding from anything, but because it was never a third party to begin with.

No cookies, no IP addresses stored. Data lives in one SQLite file you control, on the
same server as the site itself. One small optional inline script recovers referrers
your browser's `Referer` header would otherwise lose — see
[Referrer recovery](#referrer-recovery--campaign-tracking) for exactly what it does, and
how to turn it off for a tracker with zero JavaScript.

Free to use under the [MIT license](LICENSE). The dashboard shows a small "LISTEN TO:
PGNIP.ca comedy podcast" credit by default — you're welcome to leave it, and free to turn
it off in setup if you'd rather not.

## What it shows

- Page views, unique visitors, pages visited, and today vs. yesterday change
- A views-per-day chart and an hour-of-day × day-of-week activity heatmap
- Top pages, referrers, campaign sources (UTM), browsers, OS, device type, countries
- A live "Happening now" panel plus a detailed recent-visitors log
- Nine date ranges: last 12 hours, today, yesterday, 7/30/90/365 days, all time
- A light/dark theme toggle — remembers your choice, otherwise follows your system setting

## Pick a folder name

This package can live at any path on your site — `counter`, `stats`, `analytics`,
`tools/traffic`, whatever you like. Nothing here is hardcoded to a specific name: every
file finds its own folder automatically, and the dashboard excludes its own pages from
being counted based on wherever you actually put it.

The **one place** the folder name has to be typed is the include line you add to your
pages (step 3 below) — because that's how PHP finds the file in the first place. Rename
the folder later and you only need to update that one line, everywhere it appears.

The rest of this README uses `YOUR-FOLDER-NAME` as a placeholder. Replace it with
whatever you actually chose.

## Files

| Local file | Goes on the host at |
|---|---|
| `config.php` | `/public_html/YOUR-FOLDER-NAME/config.php` |
| *(`config.example.php` is a fallback template — see below)* | |
| `.htaccess` | `/public_html/YOUR-FOLDER-NAME/.htaccess` |
| `lib.php` | `/public_html/YOUR-FOLDER-NAME/lib.php` |
| `counter.php` | `/public_html/YOUR-FOLDER-NAME/counter.php` |
| `referrer.php` | `/public_html/YOUR-FOLDER-NAME/referrer.php` |
| `install.php` | `/public_html/YOUR-FOLDER-NAME/install.php` *(deletes itself after setup)* |
| `index.php` | `/public_html/YOUR-FOLDER-NAME/index.php` |
| `live.php` | `/public_html/YOUR-FOLDER-NAME/live.php` |
| `data/.htaccess` | `/public_html/YOUR-FOLDER-NAME/data/.htaccess` |
| `root-htaccess-SNIPPET.txt` | paste its contents into `/public_html/.htaccess` |

`stats.db` is created automatically on the first counted page view. Do not upload one.

`data/salt.php` is created for you by `install.php` and holds the visitor-hash salt.
Do not upload one, do not commit one, and do not move it into `config.php` — see
[Where the salt lives](#where-the-salt-lives).

Both `.htaccess` files matter. The one in `data/` blocks the database; the one in the
package folder blocks `config.php`, which contains `exclude_ips` — your own IP address.

Some hosts have no `public_html` folder at all — the FTP root **is** the web root. If
that's yours, drop `YOUR-FOLDER-NAME` straight into the FTP root instead.

## Install

1. **Upload everything** to a folder of your choice in your web root, so you end up
   with `/YOUR-FOLDER-NAME/` containing `config.example.php`, `.htaccess`, `lib.php`,
   `counter.php`, `referrer.php`, `install.php`, `index.php`, `live.php` and a `data`
   folder holding `.htaccess`.

2. **Make `data` writable.** In your FTP client or file manager, set the permissions
   of `/YOUR-FOLDER-NAME/data/` to `755`.

   If the counter records nothing later, ask your host which user PHP runs as and have
   the folder owned by it. Do **not** reach for `777`: on shared hosting that grants
   write access to every other account on the same server, and this folder holds both
   your database and your visitor-hash salt.

3. **Visit `https://yoursite.com/YOUR-FOLDER-NAME/install.php` promptly** and fill in
   the form. It writes `config.php` and `data/salt.php` for you, with a freshly
   generated salt, and then **deletes itself**.

   Do this as soon as the upload finishes. Until setup runs, that page is an
   unauthenticated form that chooses your visitor-hash salt, and whoever loads it first
   picks that salt. If you ever open it and see "Already configured" on a site you have
   not set up yet, someone else got there first: delete `config.php` and `data/salt.php`
   and run it again.

   Prefer to edit PHP by hand? Skip the installer: copy `config.example.php` to
   `config.php`, create `data/salt.php` yourself as described in
   [Where the salt lives](#where-the-salt-lives), and delete `install.php` over FTP.

4. **Edit the `.htaccess` in your web root.** Open `root-htaccess-SNIPPET.txt`, copy
   the contents, and **append** it to your web root's `.htaccess` (not the one in
   `data/`). Do not overwrite that file — it probably has redirects and rewrite rules
   already. Keep a backup copy of it first. Not sure which handler line your host
   needs? See [Checking it worked](#checking-it-worked) below.

5. **Add one line to the top of every page you want counted.** It must be the very
   first thing in the file, before `<!doctype html>`, with nothing — not even a
   blank line or a space — in front of it:

   ```php
   <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/YOUR-FOLDER-NAME/counter.php'; ?><!doctype html>
   ```

6. **Visit your site**, then open `https://yoursite.com/YOUR-FOLDER-NAME/`.

## Checking it worked

- If pages render normally and the dashboard shows a view, you're done.
- If your browser **downloads** the `.html` file instead of showing it, or you see
  the raw `<?php ... ?>` text on the page, the `.htaccess` handler line is wrong for
  your host. Upload the `verify/phpcheck/` folder from this repo — it has seven
  pre-built test folders (`t1` through `t7`), one per handler spelling in
  `root-htaccess-SNIPPET.txt`. Open each one; whichever says "WORKS" tells you which
  line to uncomment. `verify/phptest.html` is a second, single-file check once you
  think you've got the right one.
- If pages render but the dashboard says "No data yet", the `data` folder isn't
  writable. Go back to step 2.

## Live updating

The dashboard refreshes itself while you're looking at it. Every 15 seconds it fetches
`live.php` — a read-only JSON endpoint — and rewrites the headline numbers in place,
along with a **Happening now** panel showing how many people were on the site in the
last five minutes and the most recent page views as they land.

This is a *viewer* feature and it changes nothing about the tracking:

- This script lives only on `index.php`, a page your visitors never load. It's separate
  from the referrer-recovery script described below, which does run on visitor-facing pages.
- `live.php` does not include `counter.php`, so polling it records nothing.
- With JavaScript disabled the dashboard still renders completely — the numbers just
  stop moving, and the Happening now panel stays hidden rather than sitting there dead.
- Polling **stops entirely when the tab is in the background** and resumes when you come
  back. A dashboard left open all day otherwise costs thousands of pointless queries.

Settings in `config.php`:

- `live_interval` — seconds between refreshes, default `15`. Set to `0` to turn live
  updating off completely and go back to a plain static page.
- `live_feed` — **off by default.** Set to `true` to add the recent-views list beneath
  the live numbers. Worth thinking about before you do: **your dashboard is public, so
  that feed is public too.** It exposes no IP addresses or identities, but anyone
  watching can see which pages are being read within seconds of it happening.

## Referrer recovery & campaign tracking

Some browsers strip the `Referer` header from the actual page request — a privacy
setting, an app's in-app browser, an https-to-http hop — but still expose the same value
to JavaScript as `document.referrer`. Without help, those visits just show up as "Direct."

When `config['js_beacon']` is on (the default), `counter.php` buffers each page and
appends one small inline script right before `</body>`:

```html
<script>(function(p,r,u){if(navigator.sendBeacon)navigator.sendBeacon(u,
JSON.stringify({p:p,r:r}));})(location.pathname,document.referrer,'/YOUR-FOLDER-NAME/referrer.php');
</script>
```

`navigator.sendBeacon` only, with no image-pixel fallback. An `<img>` beacon would mean
`referrer.php` had to accept `GET`, and a `GET` that writes to the database can be fired
by *any* third-party page: it just embeds an image tag pointing at your endpoint, and the
visitor's browser sends it with their real IP and user agent. `sendBeacon` is a `POST`
and is supported by every browser released in the last decade. On anything older, the
hit is still counted — it simply reports as "JavaScript did not run."

It runs on every counted page and always reports back — either a referrer value, or
confirmation that there wasn't one. That second case matters: without it, "no referrer
received" could mean either a genuinely direct visit, or a visitor whose JavaScript never
ran at all. Always reporting turns silence into a real signal: it can only mean
JavaScript was off or blocked.

`referrer.php` receives that report and patches the matching row in `stats.db` — the most
recent hit from the same visitor hash and page path, within 5 seconds. No cookie or new
identifier is involved: it's the same daily-rotating `visitor` hash described in
[Privacy design](#privacy-design), recomputed from the beacon's own IP and user agent. A
request can therefore only ever touch a row matching its own visitor hash, page path and
5-second window, so there's no rate limiting — a flood of junk costs no more than loading
any other page.

On the dashboard, a **JavaScript enabled** card shows how many hits had the beacon report
back versus not, plus how many referrers it actually recovered.

`counter.php` also reads `utm_source`, `utm_medium` and `utm_campaign` off the page URL's
query string, if present, and stores them alongside the hit — no JavaScript involved,
this part is entirely server-side. When any hit in the selected range carries UTM data, a
**Campaign sources** card appears on the dashboard.

**Want zero JavaScript instead?** Set `js_beacon` to `false` in `config.php` (or uncheck
it during setup). Core tracking, UTM capture and backups all keep working exactly the
same — you just lose referrer recovery and the JS-status column, and "Direct" goes back
to meaning "no `Referer` header arrived," full stop.

## Automatic backups

If `backup_days` is set above `0` (default `14`), the first hit after midnight writes a
complete snapshot of `stats.db` to `data/backups/stats-YYYY-MM-DD.db`, using SQLite's
`VACUUM INTO` — safe to run while the live database is being written to. Snapshots older
than `backup_days` are deleted automatically. Set `backup_days` to `0` to turn this off
entirely. `data/.htaccess` already blocks web access to everything in `data/`, backups
included.

## Known tradeoffs of this approach

- **Every `.html` page now goes through the PHP interpreter.** Slightly slower
  than serving a static file, and some hosts stop applying browser-cache headers
  to PHP-handled files. On a small site the difference is not noticeable.
- **Any page containing a literal `<?` sequence may break.** The most common
  culprit is an XML declaration, `<?xml version="1.0"?>`, inside an inline SVG.
  PHP will try to interpret it. Search your pages for `<?xml` before switching
  the handler on; SVGs work fine without that declaration, so delete it if found.
- **You must edit every page you want counted.** Pages without the include line
  are invisible to the counter.
- **If `js_beacon` is on, every counted page makes one small extra request** via
  `navigator.sendBeacon` to `referrer.php`. It fires on every page view, not just
  direct ones — see [Referrer recovery](#referrer-recovery--campaign-tracking) — since
  that's the only way to distinguish a confirmed-direct visit from one where JavaScript
  never ran. Turn `js_beacon` off if you'd rather the tracker made zero requests, full
  stop. Browsers too old for `sendBeacon` are still counted; they just report as
  "JavaScript did not run".
- **The dashboard is public, and there is no password.** Anyone who finds
  `/YOUR-FOLDER-NAME/` can read your traffic numbers. It carries `noindex` so search
  engines skip it, but that is a request, not a lock. If `count_dashboard` is on (the
  default), visits to the dashboard itself appear in the Top pages list like any other
  page.

  Because of that, the two views that expose individual behaviour — `live_feed` and
  `show_recent_log` — ship **off**. Aggregate totals tell a stranger how busy you are.
  A row-by-row list of paths with times, browser, OS, device and country beside them is
  a different thing: on a quiet site it reads as a trace of what one identifiable person
  browsed. Turn them on for yourself if you want them; know that you are turning them on
  for everyone else too.
- **Referrer spam is possible.** `referrer.php` only ever patches a row created by the
  same visitor hash, so nobody can alter anyone else's data — but someone can load a
  page of yours and then report a made-up referrer for their own hit, and that hostname
  will appear in your referrers list. It is cosmetic, and short of putting the dashboard
  behind a login there is no way to fully prevent it.

## If `.html` parsing won't work on your host

Rename the pages to `.php` instead (`index.html` → `index.php`) and add these
redirects to your web root's `.htaccess` so old links keep working:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.+)\.html$ /$1.php [R=301,L]
```

No handler directive is needed in that case — `.php` files already run PHP.

## Privacy design

The only per-visitor value stored is `visitor`: the first 20 characters of
`sha256(secret salt + today's date + IP + user agent)`. Because the date is part of
the input, the same person produces a different value tomorrow — the hash cannot be
followed across days, and it cannot be reversed to recover an IP. Raw IP addresses
are never written to disk. This is the same technique Plausible and Fathom use, and
it is what makes the counter GDPR/PIPEDA-friendly without a cookie banner.

### Where the salt lives

**`data/salt.php`, never `config.php`.** That hash is irreversible *only while the salt
is secret*. Anyone holding it can hash the entire IPv4 address space in minutes and turn
every stored `visitor` value back into a real IP address — so the salt, not the hash, is
what actually protects your visitors.

`data/` is the folder blocked from the web by `data/.htaccess`, which is why the salt
lives there. `install.php` creates the file for you. By hand, it is just:

```php
<?php return 'a-long-random-string-you-generate-once';
```

At least 16 characters. If that file is missing, unreadable, or shorter than that, **the
counter records nothing at all** and the dashboard says so in red. That is deliberate:
an unsalted hash of an IP address is reversible by anyone, so storing one would be worse
than losing the traffic. Back it up alongside `stats.db` — losing it resets
unique-visitor counting the same way changing it does.

### Visitors cannot forge their own identity

The visitor hash is built from the IP address in `REMOTE_ADDR`. The `X-Forwarded-For`,
`X-Real-IP` and `CF-Connecting-IP` headers are **ignored** unless the address making the
request appears in `trusted_proxies`, because those headers are written by whoever sent
the request. Trusting them blindly lets any visitor invent a new IP per request and
manufacture unlimited "unique visitors", or claim an address from `exclude_ips` and never
be counted at all.

Behind a real CDN, list its ranges in `trusted_proxies` — for Cloudflare, from
<https://www.cloudflare.com/ips/>. `X-Forwarded-For` is then read from the right-hand end
of the chain inward, because everything at the left-hand end was supplied by the client
and can say anything it likes.

## Every setting

Set these with `install.php`, or by editing `config.php` directly:

| Setting | Default | What it does |
|---|---|---|
| `timezone` | `UTC` | Timezone used for days/hours throughout the dashboard. |
| *(salt)* | *(random)* | **Not a `config.php` setting.** Lives in `data/salt.php` — see [Where the salt lives](#where-the-salt-lives). |
| `trusted_proxies` | *(none)* | Proxy IPs/CIDRs allowed to set `X-Forwarded-For` etc. Leave empty unless a CDN is genuinely in front of you. |
| `exclude_paths` | robots.txt, favicon.ico, sitemap.xml | Paths never counted. This folder's own pages are excluded automatically. |
| `exclude_ips` | *(none)* | IPs never counted — add your own from ifconfig.me. |
| `record_bots` | `true` | Store bot hits (hidden from the dashboard by default) instead of dropping them. |
| `retention_days` | `0` | Auto-delete hits older than this many days. `0` keeps everything. |
| `backup_days` | `14` | Keep this many days of automatic SQLite backups. `0` disables backups. |
| `js_beacon` | `true` | Referrer-recovery script on every counted page. `false` = zero JavaScript. |
| `site_name` | `My Site` | Dashboard title. |
| `count_dashboard` | `true` | Count visits to the dashboard itself as a normal hit. |
| `live_interval` | `15` | Seconds between live dashboard refreshes. `0` disables live updating. |
| `live_feed` | `false` | Show the public "Happening now" recent-views feed. Off by default — it is public. |
| `live_feed_limit` | `10` | Rows shown in the "Happening now" feed. |
| `show_recent_log` | `false` | Show the detailed "Recent visitors" table. Off by default — the most revealing view here. |
| `recent_log_limit` | `50` | Rows shown in the "Recent visitors" table. |
| `show_campaigns` | `true` | Show the "Campaign sources" table when UTM data exists. |
| `own_hosts` | `example.com, www.example.com` | Hosts treated as internal navigation, not referrers. |
| `powered_by` | `true` | Show the "LISTEN TO: PGNIP.ca comedy podcast" credit. Optional. |
| `show_github` | `true` | Show a link to this project's GitHub repo in the footer. |
