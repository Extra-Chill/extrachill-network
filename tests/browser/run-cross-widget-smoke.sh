#!/usr/bin/env bash
set -euo pipefail

# Cross-widget Turnstile browser smoke (explicit per-widget render isolation).
#
# Boots a disposable WordPress (WP Codebox / Playground), activates
# extrachill-network, seeds a page that renders TWO .cf-turnstile widgets via
# the plugin's own ec_render_turnstile_widget() and drives them with the
# plugin's REAL explicit-render boot script (assets/js/turnstile-boot.js) over a
# faithful stub of window.turnstile.render(). Then it drives a real headless
# browser over the page and captures console + page-error + screenshot artifacts.
#
# The decisive scenario (multisite #48): the FIRST widget declares a broken
# data-callback (undefined global) and the SECOND is well-formed. Under the OLD
# implicit batch render this aborted BOTH widgets — the lackey bug. Under the new
# explicit per-widget render, the boot's try/catch isolates the failure: the bad
# widget is skipped, the GOOD widget still renders.
#
# What this catches today: a hard render/navigation failure of the seeded page
# (the page must load and both widgets must reach the DOM). The screenshot and
# seed output are the durable evidence.
#
# What it additionally proves when the WP Codebox browser runtime captures
# console/errors: the isolation guarantee — ZERO uncaught page errors (the boot
# contained the bad widget's throw) AND at least the good widget rendered
# (rendered >= 1 of total = 2) via the `EC_TURNSTILE_SMOKE rendered=<n>
# total=<n>` console marker the seed emits. On builds whose browser runtime does
# not capture console/errors this final assertion is skipped with a NOTE rather
# than a false green; the seed + runner light it up automatically once capture is
# available.
#
# Usage:
#   tests/browser/run-cross-widget-smoke.sh
#
# Requires the `wp-codebox` CLI on PATH (the WP Codebox plugin's runtime).

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RECIPE="${SCRIPT_DIR}/cross-widget-turnstile-recipe.json"
ARTIFACTS_DIR="${SCRIPT_DIR}/artifacts"

if ! command -v wp-codebox >/dev/null 2>&1; then
    echo "SKIP: wp-codebox CLI not found on PATH — cannot run the browser smoke." >&2
    exit 0
fi

rm -rf "$ARTIFACTS_DIR"

run_json="$(mktemp)"
trap 'rm -f "$run_json"' EXIT

echo "Running cross-widget Turnstile browser recipe..."
if ! ( cd "$SCRIPT_DIR" && wp-codebox recipe-run --recipe "$RECIPE" --json ) > "$run_json" 2>&1; then
    echo "FAIL: recipe-run exited non-zero" >&2
    tail -n 40 "$run_json" >&2
    exit 1
fi

# A top-level recipe error (e.g. navigation TimeoutError, PHP fatal in the seed,
# failed plugin activation) means the page did not load/render as expected.
top_error="$(python3 - "$run_json" <<'PY'
import json, sys
raw = open(sys.argv[1]).read()
start = raw.find("{")
end = raw.rfind("}")
try:
    data = json.loads(raw[start:end + 1])
except Exception:
    print("PARSE_FAIL")
    sys.exit(0)
err = data.get("error")
print(json.dumps(err) if err else "")
PY
)"

if [ "$top_error" = "PARSE_FAIL" ]; then
    echo "FAIL: could not parse recipe-run output" >&2
    tail -n 40 "$run_json" >&2
    exit 1
fi

if [ -n "$top_error" ]; then
    echo "FAIL: recipe reported an error (page did not load/render cleanly):" >&2
    echo "  $top_error" >&2
    exit 1
fi

# Confirm the seed reported both widgets, and the probe produced a screenshot.
seed_ok="$(python3 - "$run_json" <<'PY'
import json, sys
raw = open(sys.argv[1]).read()
start = raw.find("{")
end = raw.rfind("}")
data = json.loads(raw[start:end + 1])
seeded = False
for cmd in data.get("executions", []):
    if cmd.get("command") == "wordpress.run-php":
        out = cmd.get("stdout", "") or ""
        try:
            payload = json.loads(out.strip().splitlines()[-1])
        except Exception:
            payload = {}
        if payload.get("seeded") and int(payload.get("widgets", 0)) >= 2:
            seeded = True
print("ok" if seeded else "no")
PY
)"

if [ "$seed_ok" != "ok" ]; then
    echo "FAIL: seed did not report two rendered widgets" >&2
    tail -n 40 "$run_json" >&2
    exit 1
fi

# Locate the browser-probe screenshot as durable evidence the page rendered.
screenshot="$(find "$ARTIFACTS_DIR" -name screenshot.png 2>/dev/null | head -n 1)"
if [ -z "$screenshot" ] || [ ! -s "$screenshot" ]; then
    echo "FAIL: browser-probe produced no screenshot — page likely did not render" >&2
    exit 1
fi

echo "PASS: page loaded, two Turnstile widgets seeded, browser captured a render."
echo "  Screenshot: ${screenshot}"

# Best-effort: if this WP Codebox build captures page console/errors, enforce the
# full cross-widget contract. When the channels are inert (older CLI builds),
# these assertions are skipped rather than producing a false green.
summary="$(find "$ARTIFACTS_DIR" -path '*browser/summary.json' 2>/dev/null | head -n 1)"
if [ -n "$summary" ]; then
    python3 - "$summary" "$ARTIFACTS_DIR" <<'PY'
import json, sys, glob, os

summary = json.load(open(sys.argv[1]))
counts = summary.get("summary", {})
errors = int(counts.get("errors", 0))
console_n = int(counts.get("consoleMessages", 0))

# Isolation guarantee: the bad widget's failure is caught by the boot's
# try/catch, so it must NEVER surface as an uncaught page error. A page error
# here means the failure escaped containment (the old implicit-batch behaviour).
if errors > 0:
    print(f"FAIL: browser captured {errors} uncaught page error(s) — a bad widget escaped per-widget isolation")
    sys.exit(1)

if console_n == 0:
    print("NOTE: this WP Codebox build did not capture page console/errors;")
    print("      skipping the rendered-marker assertion (load+screenshot smoke only).")
    sys.exit(0)

# Console capture is live — enforce the rendered marker. The seed renders TWO
# widgets where ONE is deliberately broken; explicit per-widget render must keep
# the GOOD widget rendering, so we require rendered >= 1 of total == 2. Under the
# old implicit batch this scenario produced rendered == 0 (both aborted).
artifacts_dir = sys.argv[2]
console_files = glob.glob(os.path.join(artifacts_dir, "**", "browser", "console.jsonl"), recursive=True)
rendered = total = None
for path in console_files:
    for line in open(path):
        if "EC_TURNSTILE_SMOKE" not in line:
            continue
        try:
            text = json.loads(line).get("text", "")
        except Exception:
            text = line
        for tok in text.split():
            if tok.startswith("rendered="):
                rendered = int(tok.split("=", 1)[1])
            if tok.startswith("total="):
                total = int(tok.split("=", 1)[1])

if rendered is None or total is None:
    print("FAIL: console captured but the EC_TURNSTILE_SMOKE marker was missing")
    sys.exit(1)

if total < 2:
    print(f"FAIL: expected 2 widgets on the page, saw total={total}")
    sys.exit(1)

if rendered < 1:
    print(f"FAIL: {rendered}/{total} widgets rendered — the good sibling was aborted by the bad widget (isolation broken)")
    sys.exit(1)

print(f"PASS: per-widget isolation held — {rendered}/{total} widgets rendered (bad widget skipped, good sibling survived), zero uncaught page errors.")
PY
fi

echo "Cross-widget Turnstile browser smoke passed"
