# Cloudflare WAF — canonical rule reference

Cloudflare WAF Custom Rules and Rate Limiting rules cannot be expressed as nginx config — they live in the Cloudflare dashboard. This file is the version-controlled source of truth for the **rule expressions** so the operator can recreate them from scratch on a fresh zone, and so future drift (someone edits a rule by hand) can be reconciled by diffing against this file.

## Where to apply

- **Rules 1, 2, 4, 5, 7** — Cloudflare dashboard → **Security → WAF → Custom Rules → Create rule**. Action = Block.
- **Rules 3, 6** — Cloudflare dashboard → **Security → WAF → Rate limiting rules**. Action and duration as specified per rule.

All rules apply to the `extrachill.com` zone (which covers every subsite via the multisite network). Where a rule is host-specific the expression includes an explicit `http.host eq ...` clause.

## Status

These rules were drafted in agent Session 22 (2026-04-28) and Session 25 (2026-05-18) and are tracked for deployment in [Extra-Chill/extrachill-network#23](https://github.com/Extra-Chill/extrachill-network/issues/23). The acceptance criteria there govern which rules are required (1–3, 6, 7) vs. optional (4, 5).

---

## Rule 1 — Block SQLi probes against `/?s=`

**Why:** WordPress core search (`/?s=`) is the most-attacked endpoint on the network. Time-based and union-based SQLi probes from S22's investigation get blocked at the edge so they never touch PHP.

- **Panel:** Custom Rules
- **Action:** Block
- **Host scope:** `extrachill.com`

```
(http.host eq "extrachill.com" and starts_with(http.request.uri.path, "/") and len(http.request.uri.query) > 0 and http.request.uri.query contains "s=" and (
  http.request.uri.query contains "sleep(" or
  http.request.uri.query contains "sleep%28" or
  http.request.uri.query contains "waitfor delay" or
  http.request.uri.query contains "waitfor%20delay" or
  http.request.uri.query contains "DBMS_PIPE.RECEIVE_MESSAGE" or
  http.request.uri.query contains "pg_sleep(" or
  http.request.uri.query contains "benchmark(" or
  lower(http.request.uri.query) contains "union select" or
  lower(http.request.uri.query) contains "union%20select"
))
```

---

## Rule 2 — Block scanner gibberish probes

**Why:** Patterns observed in production access logs from automated scanners — double-encoded quotes, repeated `/page/page/page` paths, `@@<random>` tokens. Zero legitimate traffic matches; everything that matches is a scanner.

- **Panel:** Custom Rules
- **Action:** Block
- **Host scope:** `extrachill.com`

```
(http.host eq "extrachill.com" and (
  http.request.uri.query contains "%2527%2522" or
  http.request.uri.query contains "????%25" or
  http.request.uri.query contains "%252527" or
  http.request.uri.query contains "/page/page/page" or
  http.request.uri.query matches "@@[A-Za-z0-9]{4,8}(&|$)"
))
```

---

## Rule 3 — Rate limit `/?s=` per IP

**Why:** Even non-malicious search bursts can saturate FPM workers via the WP_Query that backs `/?s=`. 30 req/min/IP covers any human search usage and shuts down dictionary scrapers.

- **Panel:** Rate Limiting
- **Action:** Block
- **Duration:** 1 hour
- **Period:** 1 minute
- **Requests:** 30
- **Characteristic:** IP address

**Match expression:**

```
(http.request.uri.path eq "/" and http.request.uri.query contains "s=") or starts_with(http.request.uri.path, "/search/")
```

---

## Rule 4 — Block XSS probes (optional)

**Why:** Preventive coverage for reflected-XSS scanners. Optional because WordPress core + the theme already escape on output; this rule shifts the probes off the origin entirely. Evaluate based on false-positive rate against real query strings before enabling.

- **Panel:** Custom Rules
- **Action:** Block

```
(http.request.uri.query contains "<script" or
 http.request.uri.query contains "%3Cscript" or
 http.request.uri.query contains "javascript:" or
 http.request.uri.query contains "javascript%3A" or
 http.request.uri.query matches "on(error|load|click|mouseover)\s*=")
```

---

## Rule 5 — Block path traversal (optional)

**Why:** Preventive coverage for path-traversal scanners (`../`, `/etc/passwd` probes). Optional because nginx + PHP are configured to refuse these anyway; this rule moves the noise off the origin.

- **Panel:** Custom Rules
- **Action:** Block

```
(http.request.uri.path contains "../" or
 http.request.uri.query contains "../" or
 http.request.uri.query contains "..%2F" or
 lower(http.request.uri.query) contains "/etc/passwd")
```

---

## Rule 6 — Rate limit `/login/` and `/wp-login.php` per IP

**Why:** The `/login/` path was responsible for **86% of all 404 traffic** across the network (158,358 hits in 14 days). nginx has a 3 req/min limit at the origin (see `bot-blocking.conf` / `server-snippet.conf`), but Cloudflare adds an edge layer that prevents the traffic from ever touching the VPS. Belt and braces — both layers run together.

Managed Challenge lets real humans through with a CAPTCHA-style flow; pure bots fail it. Switch to **Block** if edge cases (e.g. password managers, legitimate login bursts after deploys) aren't a concern.

- **Panel:** Rate Limiting
- **Action:** Managed Challenge (or Block)
- **Duration:** 1 hour
- **Period:** 1 minute
- **Requests:** 5
- **Characteristic:** IP address

**Match expression:**

```
(http.request.uri.path eq "/login/" or http.request.uri.path eq "/login" or http.request.uri.path eq "/wp-login.php")
```

---

## Rule 7 — Block known scanner user-agents at the edge

**Why:** Standard pen-test and recon tooling has identifiable user-agent strings. Real visitors never send these UAs. Block at the edge to drop the traffic before it consumes any origin resources, including the nginx `$bad_uri` map lookups.

- **Panel:** Custom Rules
- **Action:** Block

```
(http.user_agent contains "zgrab" or
 http.user_agent contains "masscan" or
 http.user_agent contains "nmap" or
 http.user_agent contains "nikto" or
 http.user_agent contains "sqlmap" or
 http.user_agent contains "wpscan" or
 http.user_agent contains "httpx" or
 lower(http.user_agent) contains "censys")
```

---

## Maintenance

When you discover a new attack pattern that warrants an edge rule:

1. Add the rule to this file first, with the rationale.
2. Apply it in the Cloudflare dashboard.
3. Commit the docs change with a `docs(nginx):` prefix referencing the relevant issue or session.

When Cloudflare drift is detected (a rule in the dashboard doesn't match this file), the dashboard is the live truth — update this file to match, then open an issue if the change wasn't intentional.

## See also

- [Extra-Chill/extrachill-network#23](https://github.com/Extra-Chill/extrachill-network/issues/23) — the issue tracking deployment of these rules.
- [Extra-Chill/extrachill-network#12](https://github.com/Extra-Chill/extrachill-network/issues/12) — closed predecessor that established the `docs/nginx/` pattern.
- `bot-blocking.conf` and `server-snippet.conf` in this directory — the nginx origin layer that runs alongside these Cloudflare rules.
