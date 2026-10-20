#!/bin/bash
# Read-only content fingerprint of a WordPress database, run ON the server (or piped over SSH with
# `ssh ... 'bash -s' -- <root> < dest-fingerprint.sh`). Used to prove the hosted destination has not
# changed between the baseline pull and a destructive import (Action A refuses on any difference).
#   dest-fingerprint.sh <project-root>        -> TSV: table <TAB> method <TAB> value
# Coverage: every base table with CHECKSUM TABLE (any changed, added or deleted row changes it), except
#   - wp_options and wp_usermeta: row fingerprint (count + CRC32 sum) that skips only volatile rows
#     (transients, cron, rewrite rules, runtime locks; login sessions, last-active stamps, screen layout);
#   - tables that are pure queues, logs or sessions (listed in VOLATILE below), reported as 'excluded';
#   - migration source/scratch tables (lgcy_*, dd_mig_h_*).
# Not covered: files (uploads, plugins, code) — see deploy.sh / initial-import.sh. A content freeze is
# still required: this check detects drift and refuses; it never merges later edits.
set -euo pipefail
cd "$1"
P=$(sed -n "s/^DB_PREFIX=['\"]\{0,1\}\([^'\"]*\)['\"]\{0,1\}$/\1/p" .env | tail -1); P=${P:-wp_}
# every query must succeed and return a value: a failed or empty answer aborts (never an empty fingerprint)
q() { local r; r=$(printf '%s\n' "$1" | wp db query --skip-column-names 2>/dev/null) && [ -n "$r" ] || { echo "dest-fingerprint: query failed: ${1:0:80}" >&2; exit 3; }; printf '%s\n' "$r"; }
VOLATILE="actionscheduler_actions actionscheduler_logs actionscheduler_claims actionscheduler_groups woocommerce_sessions wc_rate_limits
 wpmailsmtp_emails_log wpmailsmtp_debug_events wpmailsmtp_tasks_meta wpmailsmtp_email_tracking_events wpmailsmtp_email_tracking_links
 simple_history simple_history_contexts redirection_404 wc_admin_notes wc_admin_note_actions jetpack_sync_queue"
VL=""; for t in $VOLATILE; do VL="$VL,'$P$t'"; done
TABLES=$(q "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND table_name NOT IN ('${P}options','${P}usermeta'${VL}) AND table_name NOT LIKE 'lgcy\\_%' AND table_name NOT LIKE 'dd\\_mig\\_h\\_%' ORDER BY 1") || exit 3
for t in $TABLES; do
  v=$(q "CHECKSUM TABLE \`$t\`") || exit 3
  printf '%s\tchecksum\t%s\n' "$t" "$(echo "$v" | awk '{print $2}')"
done
v=$(q "SELECT CONCAT(COUNT(*),':',COALESCE(SUM(CRC32(CONCAT_WS(0x1F,option_name,option_value,autoload))),0)) FROM \`${P}options\` WHERE option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%' AND option_name NOT IN ('cron','rewrite_rules','action_scheduler_lock_async-request-runner','dd_safety_blocked_log','recovery_keys')") || exit 3
printf '%s\trows\t%s\n' "${P}options" "$v"
v=$(q "SELECT CONCAT(COUNT(*),':',COALESCE(SUM(CRC32(CONCAT_WS(0x1F,umeta_id,user_id,meta_key,meta_value))),0)) FROM \`${P}usermeta\` WHERE meta_key NOT IN ('session_tokens','wc_last_active','community-events-location','wp_dashboard_quick_press_last_post_id') AND meta_key NOT LIKE 'meta-box-order%' AND meta_key NOT LIKE 'closedpostboxes%' AND meta_key NOT LIKE 'metaboxhidden%' AND meta_key NOT LIKE 'manageedit%'") || exit 3
printf '%s\trows\t%s\n' "${P}usermeta" "$v"
for t in $VOLATILE; do printf '%s\texcluded\tvolatile\n' "$P$t"; done
