#!/usr/bin/env bash
# Rewrite URLs/domains across tables via WP-CLI. Always runs a dry-run first and requires
# explicit confirmation before making real changes. Skips the `guid` column so post GUIDs are
# preserved as WordPress core recommends, even on a rewritten local copy.
#
# Usage:
#   scripts/search-replace.sh <old-domain-or-url> <new-local-url> [table ...]
#
# With no trailing table names, this runs the full-site replace across every table
# (--all-tables). If one or more table names are supplied, only those tables are processed —
# this is the table-batch mode used to work around memory exhaustion on very large/serialized
# tables (see "Continuation batch method" and "Known problematic tables" below).
#
# Example (full run):
#   scripts/search-replace.sh https://www.dermadirect.co.uk http://localhost:8088
# Example (batch of two tables):
#   scripts/search-replace.sh https://www.dermadirect.co.uk http://localhost:8088 wp_posts wp_postmeta
#
# --- Continuation batch method (only relevant if a full run still exhausts memory at 1G) ---
# 1. List every table so batches are drawn from a real, current list rather than guessed:
#      docker compose --profile cli run --rm wpcli db tables --all-tables
# 2. Split that list into groups of ~10-20 tables, smallest/simplest first.
# 3. Run this script once per group, passing the group as trailing table args, e.g.:
#      scripts/search-replace.sh <old> <new> wp_posts wp_postmeta wp_term_relationships
#    Each invocation writes its own timestamped log to logs/ — keep all of them as evidence of
#    which tables were completed.
# 4. Track completed tables externally (e.g. append table names to a scratch file as each batch
#    succeeds) and remove them from the next group before running it, so no table is skipped or
#    double-processed across batches.
# 5. Re-run `rewrite flush` / `cache flush` (as this script does) only after the last batch.
#
# --- Known problematic tables (isolate into their own single-table batch if memory errors persist) ---
# - wp_options: large autoloaded, heavily serialized values (theme mods, plugin settings caches).
# - wp_postmeta / wp_usermeta: high row counts with serialized meta values.
# - wp_actionscheduler_actions / wp_actionscheduler_logs (if Action Scheduler/WooCommerce present):
#   very high row counts, low value from URL rewriting but expensive to scan.
# - Any WooCommerce order/session/log tables (wp_woocommerce_*): large, frequently serialized.
# These are candidates to run one table at a time, in their own batch invocation, rather than
# grouped with other tables, if a group containing them fails even with memory_limit=1G.
set -euo pipefail
cd "$(dirname "$0")/.."

OLD_URL="${1:-}"
NEW_URL="${2:-}"
shift 2 2>/dev/null || true
TABLES=("$@")

if [[ -z "$OLD_URL" || -z "$NEW_URL" ]]; then
    echo "Usage: $0 <old-domain-or-url> <new-local-url> [table ...]" >&2
    echo "Example: $0 https://www.dermadirect.co.uk http://localhost:8088" >&2
    echo "Example (batch): $0 https://www.dermadirect.co.uk http://localhost:8088 wp_posts wp_postmeta" >&2
    exit 1
fi

WPCLI=(docker compose --profile cli run --rm wpcli)

# Proven table-batch syntax for this project: pass explicit table names positionally *and* keep
# --all-tables set. --all-tables alone (no positional tables) is what scans every table
# regardless of DB_PREFIX for a full run, since Bedrock/plugin tables aren't guaranteed to share
# the configured prefix. When positional table names are supplied, WP-CLI restricts the run to
# exactly those tables, and --all-tables is kept alongside them so the same skip/behavior flags
# and prefix-independent handling apply consistently whether this is a full run or a batch.
#
# --skip-plugins/--skip-themes avoid bootstrapping plugin and theme code during the replace,
# which both reduces memory pressure and avoids plugin/theme hooks firing on rows mid-rewrite.
# --skip-columns=guid preserves post GUIDs, per WordPress core's own recommendation.
# This is the authoritative table-batch syntax for this project — see README.md.
COMMON_FLAGS=(--all-tables --skip-plugins --skip-themes --skip-columns=guid)

mkdir -p logs
LOG_FILE="logs/search-replace-$(date +%Y%m%d-%H%M%S).log"
echo "Recording evidence to $LOG_FILE"
exec > >(tee "$LOG_FILE") 2>&1

# Guard array expansion (rather than relying on "${TABLES[@]}" unconditionally) because bash
# versions before 4.4 — notably macOS's default /bin/bash 3.2 — raise "unbound variable" under
# `set -u` when expanding a zero-element array, even one explicitly declared as `()`.
if [[ ${#TABLES[@]} -gt 0 ]]; then
    echo "Batch mode: ${TABLES[*]}"
    echo "== Dry run: '$OLD_URL' -> '$NEW_URL' =="
    "${WPCLI[@]}" search-replace "$OLD_URL" "$NEW_URL" "${TABLES[@]}" "${COMMON_FLAGS[@]}" --dry-run
else
    echo "== Dry run: '$OLD_URL' -> '$NEW_URL' =="
    "${WPCLI[@]}" search-replace "$OLD_URL" "$NEW_URL" "${COMMON_FLAGS[@]}" --dry-run
fi

echo
read -r -p "Review the dry-run output above. Type 'yes' to apply for real: " CONFIRM
if [[ "$CONFIRM" != "yes" ]]; then
    echo "Aborted. No changes made."
    exit 1
fi

echo "== Applying: '$OLD_URL' -> '$NEW_URL' (confirmed $(date -u +%Y-%m-%dT%H:%M:%SZ)) =="
if [[ ${#TABLES[@]} -gt 0 ]]; then
    "${WPCLI[@]}" search-replace "$OLD_URL" "$NEW_URL" "${TABLES[@]}" "${COMMON_FLAGS[@]}"
else
    "${WPCLI[@]}" search-replace "$OLD_URL" "$NEW_URL" "${COMMON_FLAGS[@]}"
fi

echo "Flushing rewrite rules and cache..."
"${WPCLI[@]}" rewrite flush
"${WPCLI[@]}" cache flush

echo "Done. Evidence recorded to $LOG_FILE"
