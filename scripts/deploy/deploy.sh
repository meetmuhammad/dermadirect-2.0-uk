#!/bin/sh
# DermaDirect deployment system — one entry point, three separate actions:
#   deploy.sh code        <target> <artifact.tar.gz>      Action B: code + built assets only
#   deploy.sh initial     <target> <package-dir> --confirm-replace-review-db   Action A (see initial.sh)
#   deploy.sh final-sync  ...                              Action C: scripts/migration/final-sync.sh
#   deploy.sh rollback-code <target> <release-backup>      restore a previous code tree (never the DB)
# Targets: kinsta-review (values from .deploy-targets or env), or local:<dir> for validation.
# A remote lock prevents overlapping deploys; every code deploy first archives the current code.
set -eu
HERE=$(cd "$(dirname "$0")" && pwd)
ACTION=${1:?action}; TARGET=${2:?target}; shift 2
[ -f "$HERE/../../.deploy-targets" ] && . "$HERE/../../.deploy-targets"
case "$TARGET" in
  kinsta-review) SSH="ssh -p ${KINSTA_REVIEW_PORT:?} -o BatchMode=yes ${KINSTA_REVIEW_SSH:?}"; RSH="ssh -p $KINSTA_REVIEW_PORT -o BatchMode=yes"
                 DEST="$KINSTA_REVIEW_SSH:${KINSTA_REVIEW_ROOT:?}"; ROOT=$KINSTA_REVIEW_ROOT; PRIV=${KINSTA_REVIEW_PRIVATE:?}; URL=${KINSTA_REVIEW_URL:?} ;;
  local:*)       ROOT=${TARGET#local:}; SSH="sh -c"; RSH=""; DEST=$ROOT; PRIV=$ROOT/../private/dd-deploy; URL=${LOCAL_URL:-}; ;;
  *) echo "unknown target $TARGET (there is deliberately no legacy target)"; exit 2 ;;
esac
remote() { if [ "${TARGET%%:*}" = local ]; then sh -c "$1"; else $SSH "$1"; fi; }
TS=$(date -u +%Y%m%dT%H%M%SZ)
lock()   { remote "mkdir -p '$PRIV' && mkdir '$PRIV/lock' 2>/dev/null && echo '$ACTION $TS' > '$PRIV/lock/owner'" || { echo "LOCKED: another deploy/migration is running on $TARGET ($(remote "cat '$PRIV/lock/owner'" 2>/dev/null))"; exit 4; }; }
unlock() { remote "rm -rf '$PRIV/lock'" || true; }

# Paths on the server that a code deploy must never modify or delete.
PROTECT="--filter=P_/.env* --filter=P_/.git/*** --filter=P_/web/app/uploads/*** --filter=P_/web/app/cache/*** --filter=P_/web/app/plugins/*** --filter=P_/web/app/upgrade/*** --filter=P_/web/.htaccess --filter=P_/web/app/mu-plugins/000-local-safety.php --filter=P_/web/app/mu-plugins/000-dd-qa-sandbox.php --filter=P_/logs/*** --filter=P_/web/app/wflogs/***"

case "$ACTION" in
code)
  ART=${1:?artifact.tar.gz}
  [ ! -e "$ART.SUPERSEDED" ] || { echo "REFUSED: $ART is superseded — $(head -1 "$ART.SUPERSEDED")"; exit 11; }
  (cd "$(dirname "$ART")" && shasum -a 256 -c "$(basename "$ART").sha256" >/dev/null) || { echo "artifact checksum mismatch"; exit 5; }
  lock; trap unlock EXIT
  if [ "${TARGET%%:*}" != local ]; then
    scp -P "$KINSTA_REVIEW_PORT" -q "$HERE/remote/remote-guard.sh" "$KINSTA_REVIEW_SSH:$PRIV/remote-guard.sh"
    remote "sh '$PRIV/remote-guard.sh' '$ROOT' '$URL' code"
  fi
  echo "== archive current code on target (rollback point)"
  remote "mkdir -p '$PRIV/releases' && cd '$ROOT' && tar --exclude=./web/app/uploads --exclude=./.git --exclude=./web/app/cache -czf '$PRIV/releases/code-$TS.tar.gz' . && cp REVISION '$PRIV/releases/code-$TS.revision' 2>/dev/null || true; ls -1t '$PRIV'/releases/code-*.tar.gz | tail -n +6 | xargs rm -f"
  echo "== sync artifact (protected paths untouched)"
  STAGE=$(mktemp -d); tar -xzf "$ART" -C "$STAGE"
  chmod 755 "$STAGE"   # rsync -a copies the stage root mode onto the server root; mktemp -d is 0700, which made nginx unable to read the site
  # Composer-installed plugins are copied/updated; server-only plugins are never deleted (P filter).
  rsync -a --delete --checksum $PROTECT ${RSH:+-e "$RSH"} "$STAGE/" "$DEST/"
  rm -rf "$STAGE"
  echo "== post-deploy"
  if [ "${TARGET%%:*}" != local ]; then
    remote "cd '$ROOT' && wp cache flush --skip-plugins --skip-themes >/dev/null 2>&1; (wp kinsta cache purge --all >/dev/null 2>&1 || true)"
    "$HERE/healthcheck.sh" "$URL"
  fi
  echo "DEPLOYED $(tar -xzOf "$ART" ./REVISION) to $TARGET (rollback point: $PRIV/releases/code-$TS.tar.gz)" ;;
rollback-code)
  BK=${1:?release backup path on target}
  lock; trap unlock EXIT
  # Exact restore of the archived code tree (same protected paths as a deploy; never the database).
  remote "test -f '$BK' && T=\$(mktemp -d) && tar -xzf '$BK' -C \"\$T\" && rsync -a --delete $PROTECT \"\$T/\" '$ROOT/' && rm -rf \"\$T\" && echo restored code from $BK"
  [ "${TARGET%%:*}" = local ] || "$HERE/healthcheck.sh" "$URL" ;;
initial)    exec "$HERE/initial.sh" "$TARGET" "$@" ;;
final-sync) exec "$HERE/../migration/final-sync.sh" "$@" ;;
*) echo "unknown action $ACTION"; exit 2 ;;
esac
