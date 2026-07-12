# nginx + Cloudflare WAF — edge protection for the Extra Chill network

Canonical reference copies of the nginx config and Cloudflare WAF rules that protect extrachill.com from credential scanners, aggressive scrapers, brute-force login probes, and SQLi/XSS attacks. These files are the source of truth — the live `/etc/nginx/` config on the production VPS and the rules in the Cloudflare dashboard should match this directory.

## Why these exist

On **2026-05-10** Pinterestbot crawled the events calendar with `past=1` queries that bypassed cache and saturated PHP-FPM, taking the network down. While debugging we also discovered ongoing credential scanning (5,675 hits to `/config.env` from a single IP) and steady brute-force probing of `/wp-login.php` from rotating IPs.

That fire was followed by a second one. By **2026-05-18** the `/login/` path alone was responsible for **86% of all 404 traffic** across the network (158,488 hits in 14 days vs 184,492 total). 404 traffic was up +262% in 28 days. Each `/login/` GET booted WordPress to serve a 301 redirect chain, which the attackers were exploiting because nothing throttled them.

The fix is a two-layer edge:

- **nginx (origin)** — block bad UAs, bad IPs, scanner-probe URIs, and author enumeration; rate-limit `/wp-json/` and the login endpoints per-IP.
- **Cloudflare WAF (edge)** — block SQLi/XSS/path-traversal probes, scanner user-agents, and rate-limit `/?s=` and `/login/` at the edge so the traffic never touches the VPS.

That config previously lived only on the VPS (and as scribbles in agent memory for the Cloudflare side). A server rebuild or a CF zone migration would have lost it. This directory codifies all of it.

## Files

| File | Scope | Purpose |
|------|-------|---------|
| `bot-blocking.conf` | nginx `http { }` | UA / IP / URI block maps + `wpjson` and `wp_login` rate-limit zones. Install in `/etc/nginx/conf.d/`. |
| `server-snippet.conf` | nginx `server { }` | The `if`-guards that turn the maps into actual returns, the author-enumeration guard, and the location blocks for `/wp-json/`, `/wp-login.php`, and `/login/`. Paste into the site server block. |
| `cloudflare-waf-rules.md` | Cloudflare dashboard | Canonical reference for the 7 Cloudflare WAF rules (Custom Rules + Rate Limiting). The dashboard is the live truth; this file is the version-controlled reference. |

`wp-login-ratelimit.conf` previously held the `/wp-login.php` rate limit as a separate file. It was deleted on 2026-05-18 in favor of consolidation — the `wp_login` zone now lives in `bot-blocking.conf` and the location blocks live in `server-snippet.conf`. One file per scope, no orphan files.

## Relationship to live config

This directory is a **canonical reference**, not an auto-deployed artifact. The operator copies these files to the production VPS by hand and reloads nginx; the operator recreates the WAF rules in the Cloudflare dashboard by hand using `cloudflare-waf-rules.md` as the source.

Live install paths on the production VPS (do not edit from automation):

```
/etc/nginx/conf.d/bot-blocking.conf        ← copy from docs/nginx/bot-blocking.conf
/etc/nginx/conf.d/cloudflare-real-ip.conf  ← real-IP recovery (already deployed, separate scope)
/etc/nginx/sites-enabled/extrachill        ← paste from docs/nginx/server-snippet.conf
```

If the live config drifts (someone hand-edits the VPS or the CF dashboard), the right reconciliation is to update this directory to match the live state, then commit. Never let the live state run ahead of the docs silently.

### Drift policy

The convention that prevents this directory from going stale is tracked in [Extra-Chill/extrachill-network#29](https://github.com/Extra-Chill/extrachill-network/issues/29):

> Any edit to a file in `/etc/nginx/conf.d/` or `/etc/nginx/sites-enabled/` on the production VPS MUST be accompanied by a PR to `extrachill-network/docs/nginx/` updating the corresponding canonical reference. Same edit, same change window.

The same rule applies to Cloudflare WAF rules vs `cloudflare-waf-rules.md`. Emergency live-first edits are allowed but the docs PR must follow within 24h, linked to the incident.

## From-scratch deployment ritual

Assuming a Debian/Ubuntu nginx package (the auto-include `/etc/nginx/conf.d/*.conf` is wired up by default) and a fresh Cloudflare zone:

### nginx

1. Copy the http-scope file into `/etc/nginx/conf.d/`:
   ```bash
   sudo cp docs/nginx/bot-blocking.conf /etc/nginx/conf.d/bot-blocking.conf
   ```

2. Open the site server block (e.g. `/etc/nginx/sites-available/extrachill`) and paste the contents of `server-snippet.conf` into the HTTPS server block:
   - The `if`-guards (`$bad_bot`, `$blocked_ip`, `$bad_uri`, `$args ~* author=`) go **early** in the server block, before any `location` directives, so blocked requests never reach a location match.
   - The `location` blocks (`/wp-json/`, `= /wp-login.php`, `= /login/`, `= /login`) can go anywhere among the other location blocks — exact-match (`=`) wins over regex regardless of order — but keep them grouped near the top for readability.

3. Validate and reload:
   ```bash
   sudo nginx -t && sudo systemctl reload nginx
   ```

### Cloudflare WAF

1. Open the `extrachill.com` zone in the Cloudflare dashboard.
2. Navigate to **Security → WAF → Custom Rules** and create Rules 1, 2, 7 from `cloudflare-waf-rules.md`. Evaluate Rules 4 and 5 (optional) based on false-positive tolerance.
3. Navigate to **Security → WAF → Rate limiting rules** and create Rules 3 and 6.
4. Verify each rule shows match counts within ~5 minutes of going live (any zero-match rule should be double-checked against the expression in this repo).

## Status codes used

| Code | Meaning | Used for |
|------|---------|----------|
| `429` | Too Many Requests | Bad bots, rate-limit hits — signals "back off" |
| `444` | nginx-only: close connection, no response | Credential scanners, probe URIs, author enumeration — don't even leak that the path exists |

## Maintenance — these blocklists drift

The IP and bot lists in `bot-blocking.conf` are **hand-maintained snapshots**, not authoritative. They were derived from log analysis on **2026-05-10** and extended on **2026-05-18**. The right way to extend them is the same way they were built originally:

- `tail -F /var/log/nginx/access.log | grep -E '(\.env|config\.env|\.git/|wp-config\.php\.)'` to see live probe traffic
- `awk '{print $1}' /var/log/nginx/access.log | sort | uniq -c | sort -rn | head` to spot single-IP volume spikes
- `wp extrachill analytics 404 patterns --days=14` to surface emerging probe categories
- `wp extrachill analytics 404 drill bot-probe --days=14` to drill into a category's top URLs
- `wp extrachill analytics attacks --group-by=day --days=7 --site=all` to track attack volume after a deploy

When you find a new offender, add it to the appropriate file here, commit, then sync to the VPS or apply the CF rule — don't hand-edit the live config and let it drift back out of sync.

The `/wp-login.php` + `/login/` rate limit (both nginx and Cloudflare) is the one piece of this config that's **mostly self-maintaining** — it doesn't need a list. New brute-force IPs hit the same per-IP cap automatically.

## Future work

- A pipeline / scheduled job that mines nginx access logs and proposes blocklist additions as a PR against this repo (instead of needing a human to grep). Out of scope for this issue.
- Cloudflare-only allowlist for `xmlrpc.php` if any legitimate consumer surfaces (currently `deny all`).
- Optional WordPress mu-plugin backstop for `/wp-login.php` rate limiting on environments without nginx in front (shared hosting, dev containers). Tracked separately.

## See also

- [Extra-Chill/extrachill-network#23](https://github.com/Extra-Chill/extrachill-network/issues/23) — the issue this revision was filed for (CF WAF + `/login/` rate limit + scanner-path extensions).
- [Extra-Chill/extrachill-network#12](https://github.com/Extra-Chill/extrachill-network/issues/12) — closed predecessor that originally created this directory.
- [Extra-Chill/data-machine-events#246](https://github.com/Extra-Chill/data-machine-events/issues/246) — calendar caching, the proper upstream fix for the original Pinterestbot DOS vector.
