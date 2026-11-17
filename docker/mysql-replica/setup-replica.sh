#!/bin/bash
# Idempotent setup of the read replica (Requirement 3, Step 6). Runs INSIDE the mysql-replica container:
#
#   docker compose stop app
#   docker compose --profile replica exec mysql-replica bash /scripts/setup-replica.sh [--reseed]
#   docker compose up -d app
#
# What it does:
#   - replication already running and healthy: prints the status and changes nothing (exit 0)
#   - replica volume new (application database empty): creates the replication user on the primary,
#     copies the application database with mysqldump, starts replication with GTID auto-positioning,
#     waits until the replica has caught up, then makes it read-only (persisted)
#   - replica has data or a configured source but replication is stopped or broken: prints the error
#     and stops (exit 2). Only --reseed wipes the replica's copy of the application database and
#     copies it again. The primary's data is never touched.
#
# Secrets: every password is read from the container environment (MYSQL_ROOT_PASSWORD, REPLICATION_PASSWORD).
# Client calls pass the root password through MYSQL_PWD in the environment of that one process. SQL that
# carries a secret is sent on stdin, never on a command line. Nothing secret is printed.
#
# Exit codes: 0 ok, 1 error, 2 replica needs --reseed, 3 primary not ready or writers active.

set -euo pipefail

PRIMARY_HOST="${PRIMARY_HOST:-mysql}"
PRIMARY_PORT=3306
WAIT_SECONDS="${WAIT_SECONDS:-1800}"
RESEED=0

for arg in "$@"; do
  case "$arg" in
    --reseed) RESEED=1 ;;
    -h|--help) echo "Usage: bash /scripts/setup-replica.sh [--reseed]  (see the comment at the top of the script)"; exit 0 ;;
    *) echo "Unknown option: $arg (only --reseed is supported)" >&2; exit 1 ;;
  esac
done

log() { printf '[setup-replica %s] %s\n' "$(date +%H:%M:%S)" "$*"; }
die() { log "ERROR: $1" >&2; exit "${2:-1}"; }

: "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD is not set in the container}"
: "${MYSQL_DATABASE:?MYSQL_DATABASE is not set in the container}"
: "${MYSQL_USER:?MYSQL_USER is not set in the container}"
: "${REPLICATION_USER:?REPLICATION_USER is not set in the container}"
: "${REPLICATION_PASSWORD:?REPLICATION_PASSWORD is not set in the container}"

[[ "$MYSQL_DATABASE" =~ ^[A-Za-z0-9_]+$ ]] || die "MYSQL_DATABASE has unexpected characters"
[[ "$MYSQL_USER" =~ ^[A-Za-z0-9_]+$ ]] || die "MYSQL_USER has unexpected characters"
[[ "$REPLICATION_USER" =~ ^[A-Za-z0-9_]+$ ]] || die "REPLICATION_USER has unexpected characters"

# SQL always comes on stdin. rsql = this replica (local socket), psql_ = the primary.
rsql() { MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -B "$@"; }
psql_() { MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -h "$PRIMARY_HOST" -P "$PRIMARY_PORT" -uroot -N -B "$@"; }
rstatus() { MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -E <<<'SHOW REPLICA STATUS'; }

# Escapes a value for a single-quoted SQL string literal (backslash and single quote).
sql_quote() { local s=${1//\\/\\\\}; s=${s//\'/\'\'}; printf '%s' "$s"; }

declare -A S=()
parse_status() {
  S=()
  local line
  while IFS= read -r line; do
    if [[ $line =~ ^[[:space:]]*([A-Za-z_]+):[[:space:]]?(.*)$ ]]; then
      S[${BASH_REMATCH[1]}]=${BASH_REMATCH[2]}
    fi
  done <<<"$1"
}

print_status() {
  local k
  for k in Replica_IO_State Source_Host Source_Port Source_User Auto_Position Replica_IO_Running \
           Replica_SQL_Running Seconds_Behind_Source SQL_Delay Source_Log_File Last_IO_Errno Last_IO_Error \
           Last_SQL_Errno Last_SQL_Error; do
    printf '  %-24s %s\n' "$k" "${S[$k]:-}"
  done
}

is_healthy() {
  [[ "${S[Replica_IO_Running]:-}" == "Yes" && "${S[Replica_SQL_Running]:-}" == "Yes" \
     && -z "${S[Last_IO_Error]:-}" && -z "${S[Last_SQL_Error]:-}" ]]
}

primary_gtid() { psql_ <<<"SELECT REPLACE(@@GLOBAL.gtid_executed, CHAR(10), '')"; }
replica_gtid() { rsql <<<"SELECT REPLACE(@@GLOBAL.gtid_executed, CHAR(10), '')"; }
missing_gtid() { rsql <<<"SELECT REPLACE(GTID_SUBTRACT('$1', @@GLOBAL.gtid_executed), CHAR(10), '')"; }

log "waiting for this replica server"
until MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin -uroot ping >/dev/null 2>&1; do sleep 1; done

# ---------------------------------------------------------------- 1. detect the state
status=$(rstatus || true)
parse_status "$status"
configured=0
[[ -n "$status" ]] && configured=1

if (( configured )); then
  if is_healthy; then
    log "replication is running and healthy. Nothing to do."
    print_status
    exit 0
  fi
  log "replication is configured but NOT healthy:"
  print_status
  if (( ! RESEED )); then
    die "stopping. Read the error above. To wipe the replica's copy and copy it again, stop the application and run this script with --reseed." 2
  fi
fi

tables=$(rsql <<<"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$MYSQL_DATABASE'")
if (( ! configured && tables > 0 && ! RESEED )); then
  die "the replica already holds $tables table(s) in '$MYSQL_DATABASE' but replication is not configured. Re-seeding wipes that copy: run with --reseed (application stopped) if that is what you want." 2
fi

# ---------------------------------------------------------------- 2. primary preconditions
log "checking the primary at $PRIMARY_HOST"
tries=0
until MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin -h "$PRIMARY_HOST" -P "$PRIMARY_PORT" -uroot ping >/dev/null 2>&1; do
  tries=$((tries + 1))
  (( tries < 60 )) || die "the primary '$PRIMARY_HOST' does not answer" 3
  sleep 1
done

read -r p_gtid_mode p_enforce p_logbin p_server_id <<<"$(psql_ <<<'SELECT @@GLOBAL.gtid_mode, @@GLOBAL.enforce_gtid_consistency, @@GLOBAL.log_bin, @@GLOBAL.server_id')"
r_server_id=$(rsql <<<'SELECT @@GLOBAL.server_id')
log "primary: gtid_mode=$p_gtid_mode enforce_gtid_consistency=$p_enforce log_bin=$p_logbin server_id=$p_server_id; replica server_id=$r_server_id"
[[ "$p_gtid_mode" == "ON" ]] || die "the primary has gtid_mode=$p_gtid_mode. Recreate it with: docker compose up -d mysql" 3
[[ "$p_enforce" == "ON" ]] || die "the primary has enforce_gtid_consistency=$p_enforce. Recreate it with: docker compose up -d mysql" 3
[[ "$p_logbin" == "1" ]] || die "the primary has no binary log" 3
[[ "$p_server_id" != "$r_server_id" ]] || die "the primary and the replica have the same server_id ($p_server_id)" 3

# Best-effort writer check. The real consistency proof is the GTID comparison after the dump (step 5).
writers=$(psql_ <<<"SELECT COUNT(*) FROM information_schema.processlist WHERE user = '$MYSQL_USER'")
if [[ "$writers" != "0" ]]; then
  die "$writers connection(s) of the application user '$MYSQL_USER' are open on the primary. Stop the writers first: docker compose stop app" 3
fi

# ---------------------------------------------------------------- 3. replication user on the primary
# The only change made on the primary: an account with REPLICATION SLAVE and nothing else.
# Done BEFORE the GTID snapshot, so it is part of the dump's GTID set.
log "creating the replication user '$REPLICATION_USER' on the primary (REPLICATION SLAVE only)"
q_pw=$(sql_quote "$REPLICATION_PASSWORD")
psql_ <<SQL
CREATE USER IF NOT EXISTS '$REPLICATION_USER'@'%' IDENTIFIED BY '$q_pw';
ALTER USER '$REPLICATION_USER'@'%' IDENTIFIED BY '$q_pw';
GRANT REPLICATION SLAVE ON *.* TO '$REPLICATION_USER'@'%';
SQL

# ---------------------------------------------------------------- 4. prepare the replica
log "preparing the replica (read-only off for the import, replication reset)"
rsql <<<'SET GLOBAL super_read_only = OFF'
rsql <<<'SET GLOBAL read_only = OFF'
rsql <<<'STOP REPLICA' || log "note: STOP REPLICA returned an error (expected when no source was ever configured)"
rsql <<<'RESET REPLICA ALL' || log "note: RESET REPLICA ALL returned an error (expected when no source was ever configured)"

if (( tables > 0 )); then
  log "wiping the replica's copy of '$MYSQL_DATABASE' ($tables table(s))"
  rsql <<SQL
DROP DATABASE IF EXISTS \`$MYSQL_DATABASE\`;
SQL
fi

# gtid_purged can only be set while gtid_executed is empty. The first-boot init may have left GTIDs.
if [[ -n "$(replica_gtid)" ]]; then
  log "the replica has its own GTIDs ($(replica_gtid)); resetting them"
  rsql <<<'RESET BINARY LOGS AND GTIDS' || die "RESET BINARY LOGS AND GTIDS failed on the replica. If the replica runs with --skip-log-bin, remove that flag from docker-compose.yml (the documented fallback) and recreate the replica." 1
fi
[[ -z "$(replica_gtid)" ]] || die "the replica's gtid_executed is still not empty" 1

# ---------------------------------------------------------------- 5. consistent copy
gtid_before=$(primary_gtid)
log "primary gtid_executed before the copy: ${gtid_before:-<empty>}"
log "copying '$MYSQL_DATABASE' with mysqldump (single transaction, GTID_PURGED set, import runs with sql_log_bin=0)"
start=$SECONDS
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -h "$PRIMARY_HOST" -P "$PRIMARY_PORT" -uroot \
    --single-transaction --set-gtid-purged=ON --routines --triggers --events \
    --default-character-set=utf8mb4 --hex-blob --databases "$MYSQL_DATABASE" \
  | MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot --default-character-set=utf8mb4
log "copy finished in $((SECONDS - start)) s"

gtid_after=$(primary_gtid)
if [[ "$gtid_before" != "$gtid_after" ]]; then
  die "the primary committed transactions while the copy ran (before: ${gtid_before:-<empty>}, after: ${gtid_after:-<empty>}). The copy may not match its GTID set. Stop the application (docker compose stop app) and run this script again with --reseed." 3
fi
log "replica gtid_executed after the copy: $(replica_gtid)"

# ---------------------------------------------------------------- 6. start replication
log "starting replication (GTID auto-positioning)"
q_source_pw=$(sql_quote "$REPLICATION_PASSWORD")
rsql <<SQL
CHANGE REPLICATION SOURCE TO
  SOURCE_HOST='$PRIMARY_HOST',
  SOURCE_PORT=$PRIMARY_PORT,
  SOURCE_USER='$REPLICATION_USER',
  SOURCE_PASSWORD='$q_source_pw',
  SOURCE_AUTO_POSITION=1,
  GET_SOURCE_PUBLIC_KEY=1;
START REPLICA;
SQL

deadline=$((SECONDS + WAIT_SECONDS))
while true; do
  status=$(rstatus || true)
  parse_status "$status"
  if [[ -n "${S[Last_IO_Error]:-}" || -n "${S[Last_SQL_Error]:-}" ]]; then
    print_status
    die "replication reports an error (see above)." 1
  fi
  if [[ "${S[Replica_IO_Running]:-}" == "Yes" && "${S[Replica_SQL_Running]:-}" == "Yes" ]]; then
    missing=$(missing_gtid "$(primary_gtid)")
    [[ -z "$missing" ]] && break
    log "waiting: replica is missing $missing"
  else
    log "waiting: IO=${S[Replica_IO_Running]:-?} SQL=${S[Replica_SQL_Running]:-?}"
  fi
  (( SECONDS < deadline )) || die "the replica did not catch up within $WAIT_SECONDS s" 1
  sleep 2
done
log "replica is running and caught up"

# ---------------------------------------------------------------- 7. read-only (persisted)
log "making the replica read-only (SET PERSIST, survives restarts)"
rsql <<'SQL'
SET PERSIST read_only = ON;
SET PERSIST super_read_only = ON;
SQL
rsql <<<'SELECT CONCAT("read_only=", @@GLOBAL.read_only, " super_read_only=", @@GLOBAL.super_read_only)'
rsql <<<"SELECT CONCAT('persisted: ', VARIABLE_NAME, '=', VARIABLE_VALUE) FROM performance_schema.persisted_variables WHERE VARIABLE_NAME IN ('read_only','super_read_only') ORDER BY VARIABLE_NAME"

status=$(rstatus || true)
parse_status "$status"
log "final replica status:"
print_status
log "done"
