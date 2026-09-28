#!/bin/sh
# Action A — initial transfer of the completed local migrated website to the new Kinsta REVIEW site.
# (Not a re-migration: it ships the already-migrated, cleaned database.)
#   initial.sh package <label>                         local: build the private data package
#   initial.sh <target> upload <package-dir>           copy package over SSH to the target's private dir
#   initial.sh <target> apply  <package-dir> --kinsta-backup=<MyKinsta backup label> --confirm-replace-review-db
#   initial.sh <target> verify <package-dir>
# Order on the day: deploy.sh code (Action B, puts the patched safety harness + tools in place)
# -> initial upload -> initial apply -> initial verify -> browser checks.
set -eu
HERE=$(cd "$(dirname "$0")" && pwd); PROJ=$(cd "$HERE/../.." && pwd)
[ -f "$PROJ/.deploy-targets" ] && . "$PROJ/.deploy-targets"
if [ "$1" = package ]; then shift; exec "$HERE/package-initial.sh" "$@"; fi
TARGET=$1; STEP=$2; PKG=${3:?package dir}; shift 3
[ "$TARGET" = kinsta-review ] || { echo "Action A only targets kinsta-review"; exit 2; }
SSH="ssh -p $KINSTA_REVIEW_PORT -o BatchMode=yes $KINSTA_REVIEW_SSH"
NAME=$(basename "$PKG"); RDIR="$KINSTA_REVIEW_PRIVATE/incoming/$NAME"
case "$STEP" in
upload)
  (cd "$PKG" && shasum -a 256 -c SHA256SUMS >/dev/null) || { echo "package checksum mismatch"; exit 5; }
  $SSH "mkdir -p '$RDIR' && chmod 700 '$KINSTA_REVIEW_PRIVATE'"
  rsync -a --partial -e "ssh -p $KINSTA_REVIEW_PORT -o BatchMode=yes" "$PKG/" "$KINSTA_REVIEW_SSH:$RDIR/"
  $SSH "cd '$RDIR' && sha256sum -c SHA256SUMS" ;;
apply)
  BK=""; OK=""
  for a in "$@"; do case "$a" in --kinsta-backup=*) BK=${a#*=};; --confirm-replace-review-db) OK=1;; esac; done
  [ -n "$BK" ] && [ -n "$OK" ] || { echo "apply needs --kinsta-backup=<label of a fresh MyKinsta backup> and --confirm-replace-review-db"; exit 2; }
  $SSH "mkdir -p '$KINSTA_REVIEW_PRIVATE/logs' && bash '$RDIR/remote/initial-import.sh' '$KINSTA_REVIEW_ROOT' '$KINSTA_REVIEW_URL' '$RDIR' '$KINSTA_REVIEW_PRIVATE' '$BK' 2>&1 | tee -a '$KINSTA_REVIEW_PRIVATE/logs/initial-import.log'" ;;
verify)
  $SSH "sh '$RDIR/remote/initial-verify.sh' '$KINSTA_REVIEW_ROOT' '$RDIR'"
  "$HERE/healthcheck.sh" "$KINSTA_REVIEW_URL" ;;
*) echo "unknown step $STEP"; exit 2 ;;
esac
