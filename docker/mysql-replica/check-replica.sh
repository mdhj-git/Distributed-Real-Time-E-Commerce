#!/bin/bash
# Read-only health check of the read replica (Requirement 3, Step 6). Run it at the start of every session:
#
#   docker compose --profile replica exec -T mysql-replica bash /scripts/check-replica.sh
#
# Changes nothing. Prints the replication state, the replica delay, how many transactions the replica
# is missing compared with the primary, and the persisted read-only values.
#
# Exit codes: 0 healthy and current, 1 not healthy or behind, 2 replication not configured,
#             3 the primary does not answer.
# Reads the root password from the container environment (MYSQL_ROOT_PASSWORD). Prints no secret.

set -uo pipefail

PRIMARY_HOST="${PRIMARY_HOST:-mysql}"
PRIMARY_PORT=3306

: "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD is not set in the container}"

rsql() { MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -B "$@"; }
psql_() { MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -h "$PRIMARY_HOST" -P "$PRIMARY_PORT" -uroot -N -B "$@"; }

status=$(MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -E <<<'SHOW REPLICA STATUS' 2>&1)
declare -A S=()
while IFS= read -r line; do
  if [[ $line =~ ^[[:space:]]*([A-Za-z_]+):[[:space:]]?(.*)$ ]]; then
    S[${BASH_REMATCH[1]}]=${BASH_REMATCH[2]}
  fi
done <<<"$status"

echo "replica check $(date -u +%Y-%m-%dT%H:%M:%SZ)"

if [[ -z "$status" ]]; then
  echo "  replication is NOT configured on this server. Run: bash /scripts/setup-replica.sh (application stopped)"
  exit 2
fi
if [[ -z "${S[Replica_IO_Running]:-}" ]]; then
  echo "  could not read the replica status:"
  echo "$status" | head -5
  exit 1
fi

for k in Replica_IO_State Source_Host Source_User Auto_Position Replica_IO_Running Replica_SQL_Running \
         Seconds_Behind_Source SQL_Delay Source_Log_File Last_IO_Errno Last_IO_Error Last_SQL_Errno Last_SQL_Error; do
  printf '  %-24s %s\n' "$k" "${S[$k]:-}"
done

# Number of transactions in a GTID set string such as "uuid:1-10:15-20,uuid2:3".
count_gtids() {
  local set=$1 total=0 part tok
  local -a parts toks
  IFS=',' read -ra parts <<<"$set"
  for part in "${parts[@]}"; do
    part=${part// /}
    [[ -z "$part" ]] && continue
    IFS=':' read -ra toks <<<"$part"
    for tok in "${toks[@]:1}"; do
      if [[ "$tok" == *-* ]]; then
        total=$(( total + ${tok#*-} - ${tok%-*} + 1 ))
      else
        total=$(( total + 1 ))
      fi
    done
  done
  echo "$total"
}

p_gtid=$(psql_ <<<"SELECT REPLACE(@@GLOBAL.gtid_executed, CHAR(10), '')" 2>/dev/null)
if [[ $? -ne 0 ]]; then
  echo "  the primary '$PRIMARY_HOST' does not answer"
  exit 3
fi
missing=$(rsql <<<"SELECT REPLACE(GTID_SUBTRACT('$p_gtid', @@GLOBAL.gtid_executed), CHAR(10), '')")
missing_count=$(count_gtids "$missing")
echo "  missing transactions     $missing_count${missing:+ ($missing)}"

rsql <<<'SELECT CONCAT("  read_only (runtime)      ", @@GLOBAL.read_only, " / super_read_only ", @@GLOBAL.super_read_only)'
rsql <<<"SELECT CONCAT('  persisted                ', VARIABLE_NAME, '=', VARIABLE_VALUE) FROM performance_schema.persisted_variables WHERE VARIABLE_NAME IN ('read_only','super_read_only') ORDER BY VARIABLE_NAME"

if [[ "${S[Replica_IO_Running]}" == "Yes" && "${S[Replica_SQL_Running]:-}" == "Yes" \
      && -z "${S[Last_IO_Error]:-}" && -z "${S[Last_SQL_Error]:-}" && "$missing_count" == "0" ]]; then
  echo "  RESULT: healthy and current"
  exit 0
fi
echo "  RESULT: NOT healthy or behind (read the lines above)"
exit 1
