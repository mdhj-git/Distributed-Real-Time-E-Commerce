# Measurement runbook (Requirement 3)

The fixed sequence for measuring any layer: warm-up, two measured runs, docker stats, MySQL status counters, restore.
All commands are PowerShell, run from the project root. Only the reviewer measures, on one machine (the demo machine).

## Rules

- Quiet machine: close Claude Code, PhpStorm indexing, downloads, browsers and anything else heavy before you start.
- The scenario never changes between steps: 20 VUs, constant, 60 s, no think time. Do not edit `perf/k6/*.js` between steps.
- k6 runs inside the Docker network and calls `http://nginx/api/v1`, not `localhost:8080`.
- Any `429`, `5xx` or `stock:null` response makes a run INVALID. Fix the cause and repeat the run. Do not record it.
- Every measurement runs twice. If p95 differs between the two runs by more than 10 percent, run a third time. Report all runs. With two runs use their mean; with a third run use the median of the three. Never pick the best run.
- Warm-up numbers and `setup()` requests are never counted. The summary shows only requests tagged `phase:main`.
- The step 1 query counter (`PERF_QUERY_LOG`) must be OFF (`false` or unset) during every k6 run. Logging to a file on the `9p` mount would distort the numbers. Count queries per route with single `curl.exe` requests instead, never with k6.
- The slow query log stays OFF during official runs.
- The `performance_schema` statement digest is NOT evidence in this project (see "Why the digest is not used" below). Per-query evidence comes from the Step 1 query counter and from `EXPLAIN` in Step 2.

## 0. Helper (run once in each PowerShell window)

Sends SQL to MySQL as root inside the container. The password is read inside the container and is never printed.

```powershell
function Invoke-MySql([string]$sql) { $sql | docker compose exec -T mysql sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot -t' }
```

## 0b. Reset and seed the data (once per dataset, before the baseline)

`migrate:fresh` wipes the whole application database. Then `perf:seed` fills it with the deterministic dataset.
`-d memory_limit=-1` is required: the default CLI limit is 128 MB and the command keeps 300,000 order references in memory.

```powershell
docker compose exec app php artisan migrate:fresh --seed --force
docker compose exec app php -d memory_limit=-1 artisan perf:seed
```

- The command prints the time of each phase and writes `perf/k6/customers.json` (the fixed k6 customer set). Commit that file.
- It refuses to run twice. The correct sequence is always `migrate:fresh --seed --force`, then `perf:seed`.
- Fake orders have no inventory reservation. Never cancel them.
- The inventory service keeps its stock in its own database (`inventory-db`, volume `inventory_db_data`). `migrate:fresh` does not touch it. Stock for ids 3 to 5002 is 1,000,000 after any `perf:seed`, and the stock sync phase time depends on what the inventory already holds, so it is not comparable between runs.

## 1. Raise the api rate limit (temporary)

All k6 requests come from one IP, so the default limit (60 per minute) would return 429 and you would measure the limiter.
The limit is read from `API_RATE_LIMIT_PER_MINUTE` (config `ratelimit.api_per_minute`). Its default stays 60.

```powershell
function Set-ApiLimit([int]$n) {
  $p = (Resolve-Path .env).Path
  $t = [IO.File]::ReadAllText($p)
  if ($t -match '(?m)^API_RATE_LIMIT_PER_MINUTE=') { $t = $t -replace '(?m)^API_RATE_LIMIT_PER_MINUTE=.*', "API_RATE_LIMIT_PER_MINUTE=$n" }
  else { $t = $t.TrimEnd() + "`nAPI_RATE_LIMIT_PER_MINUTE=$n`n" }
  [IO.File]::WriteAllText($p, $t, (New-Object Text.UTF8Encoding $false))
}
Set-ApiLimit 100000
docker compose exec app php artisan config:show ratelimit
```

- Expected output: `api_per_minute ... 100000`.
- `config:cache` is not enabled in this project (no `bootstrap/cache/config.php`), so no clearing is needed.
  If it is ever enabled, run `docker compose exec app php artisan config:clear` after every `.env` change.
- Optional proof that PHP-FPM also sees the new value (80 requests in a few seconds, expect no 429):

```powershell
docker compose exec -T nginx sh -c 'for i in $(seq 1 80); do wget -S -qO /dev/null http://localhost/api/v1/categories 2>&1 | grep -c "HTTP/1.1 429"; done | sort | uniq -c'
```

- Expected: a single line `80 0`. Any line with `1` means 429 responses: the limit is not applied.

## 2. Warm-up (not counted)

Fills OPcache, gRPC channels, the MySQL buffer pool and Redis. 10 VUs for 20 s, then a 60 second pause.

```powershell
docker compose --profile perf run --rm -e LABEL=warmup -e RUN=0 k6 run /perf/k6/warmup.js
Start-Sleep -Seconds 60
```

## 3. MySQL status counters: before the first run

The counters are cumulative since MySQL started, so record them before run 1 and again after the last run, and subtract.
The second query (statement counts by event name) is cleared first so it only holds the measured runs.

```powershell
Invoke-MySql "SHOW GLOBAL STATUS WHERE Variable_name IN ('Com_stmt_prepare','Com_stmt_execute','Com_stmt_close','Com_select','Questions','Connections');"
Invoke-MySql "TRUNCATE TABLE performance_schema.events_statements_summary_global_by_event_name;"
```

## 4. docker stats (second PowerShell window, start before run 1, stop after the last run with Ctrl+C)

`docker stats --no-stream` takes a couple of seconds per call, so the sample spacing is about 7 s in practice.
100 percent CPU in `docker stats` is one core.

Loop (exact command used at Gate 0). Run it in the project root. Change only the file name to the step label (`baseline`, `step1-n-plus-1`, `step2-indexes`, ...):

```powershell
$label = "baseline"
while ($true) { $ts = Get-Date -Format o; docker stats --no-stream --format "$ts {{.Name}} cpu={{.CPUPerc}} mem={{.MemUsage}}" | Tee-Object -FilePath "perf\results\$label-docker-stats.txt" -Append; Start-Sleep -Seconds 5 }
```

Each line of the file is: ISO timestamp (local time with offset), container name, `cpu=..%`, `mem=..`. The file also holds the `inventory`, `redis` and `k6` containers.

- On Windows PowerShell 5.1, `Tee-Object` writes the file as UTF-16. `Get-Content` and `Select-String` read it correctly. Tools like `grep` do not (use `iconv -f UTF-16 -t UTF-8` first).

Filter for reading the main phase of the runs (exact command used at Gate 0). The minute pattern `2026-11-12T01:(27|28|32|33):` is the date, the hour and the minutes that cover the main phases of run 1 and run 2:

```powershell
Select-String -Path perf\results\baseline-docker-stats.txt -Pattern '2026-11-12T01:(27|28|32|33):' | Where-Object { $_.Line -match 'app-1|mysql-1|nginx-1' } | ForEach-Object { $_.Line -replace 'distributed-real-time-e-commerce-','' }
```

How to derive the minute pattern for each run:
- The k6 summary (and `perf/results/<label>-<run>.json`) is written when the run ends. Read that time:

```powershell
(Get-Item perf\results\baseline-1.json).LastWriteTime
(Get-Item perf\results\baseline-2.json).LastWriteTime
```

- The main phase is the 60 seconds before that time. `setup()` (51 logins and the order prefetch) runs before the main phase and must not be included.
- Write down the date, the hour, and the minutes that contain those 60 seconds (usually two consecutive minutes per run). Join all minutes of all runs with `|`, in the form `YYYY-MM-DDTHH:(MM1|MM2|MM3|MM4):`.
- The timestamps in the file use the local time zone of the machine (for example `+03:00`), the same as `LastWriteTime`.
- If a run crosses the hour, add a second alternative for the next hour, for example `2026-11-12T01:(58|59):|2026-11-12T02:(00):`.
- Whole minutes can include a few samples taken just before or after the 60 seconds. Do not use samples from the warm-up or the pauses.

## 5. Measure: run 1 and run 2 (label = step label)

```powershell
$label = "baseline"
docker compose --profile perf run --rm -e LABEL=$label -e RUN=1 k6 run /perf/k6/baseline.js
Start-Sleep -Seconds 60
docker compose --profile perf run --rm -e LABEL=$label -e RUN=2 k6 run /perf/k6/baseline.js
```

- Check each summary: `RUN VALID` must be `yes`, `failed rate` must be 0, and the 429, 5xx and `stock:null` counters must be 0.
- If p95 of run 1 and run 2 differ by more than 10 percent, wait 60 seconds and run `RUN=3`.
- The JSON files are written to `perf/results/<label>-<run>.json`. They are evidence and are committed with the step.
- The login limiter allows 5 attempts per minute per email and IP. Each k6 invocation logs in each fake customer once, so keep the 60 second pause between invocations.
- k6 prints `data_received` as n/a for the tagged sub-metrics. Use the `response body` bytes line instead (body only, measured by the script).

## 6. MySQL status counters: after the last run

```powershell
Invoke-MySql "SHOW GLOBAL STATUS WHERE Variable_name IN ('Com_stmt_prepare','Com_stmt_execute','Com_stmt_close','Com_select','Questions','Connections');"
Invoke-MySql "SELECT EVENT_NAME, COUNT_STAR FROM performance_schema.events_statements_summary_global_by_event_name WHERE COUNT_STAR > 0 ORDER BY COUNT_STAR DESC LIMIT 15;"
```

- The first query is the same as in section 3. The difference between the two outputs is what the measured runs did.
- The second query lists statement counts by type (it was cleared in section 3, so it only holds the measured runs).
- These counters show how many statements the application sent and how they were sent. They do NOT tell which SQL text was slow.

### Why the digest is not used

- Laravel talks to MySQL with the binary prepared-statement protocol.
- `performance_schema.events_statements_summary_by_digest` does not see those statements as `select` rows.
- Evidence from the baseline: `statement/com/Execute` = 36692, but `statement/sql/select` = 28. The digest table held only `SET NAMES` and `USE` rows.
- So a digest query ("slowest queries") would show nothing useful. Do not use it, and do not quote it as evidence.
- Per-query evidence comes from the Step 1 query counter (SQL text, number of bindings, duration in ms, route) and from `EXPLAIN` in Step 2.
- Note: `Com_stmt_prepare`, `Com_stmt_execute` and `Com_stmt_close` were equal in the baseline, so every query is prepared, executed and closed separately. A new database connection is also opened per request (`Quit` and `change_db` counts).

## 7. Restore the limit to 60 and verify

```powershell
Set-ApiLimit 60
docker compose exec app php artisan config:show ratelimit
```

Expected output: `api_per_minute ... 60`. Do not leave the limit raised.

## 8. Optional: diagnostic run with the slow query log (NOT a measurement)

Use only when the Step 1 counter and `EXPLAIN` are not enough. The slow log writes to a table and distorts timing, so the k6 numbers of this run are NEVER recorded or used.
Label the run `diagnostic-slowlog`.

```powershell
# a) print the previous values (keep them)
Invoke-MySql "SELECT @@GLOBAL.log_output AS log_output, @@GLOBAL.slow_query_log AS slow_query_log, @@GLOBAL.long_query_time AS long_query_time;"
# b) enable (values at the time of writing this runbook: FILE, 0, 10.000000)
Invoke-MySql "TRUNCATE TABLE mysql.slow_log; SET GLOBAL log_output='TABLE'; SET GLOBAL long_query_time=0.1; SET GLOBAL slow_query_log=ON;"
# c) one run, numbers discarded
Set-ApiLimit 100000
docker compose --profile perf run --rm -e LABEL=diagnostic-slowlog -e RUN=1 k6 run /perf/k6/baseline.js
# d) read the slow log
Invoke-MySql "SELECT ROUND(query_time,3) AS qt_s, rows_examined, LEFT(sql_text,110) AS sql_text FROM mysql.slow_log ORDER BY query_time DESC LIMIT 15;"
# e) restore EXACTLY the values printed in (a), then verify
Invoke-MySql "SET GLOBAL slow_query_log=OFF; SET GLOBAL long_query_time=10; SET GLOBAL log_output='FILE';"
Invoke-MySql "SELECT @@GLOBAL.log_output, @@GLOBAL.slow_query_log, @@GLOBAL.long_query_time;"
Set-ApiLimit 60
docker compose exec app php artisan config:show ratelimit
```

If the values printed in (a) differ from `FILE`, `0`, `10`, use the printed values in (e).
After a diagnostic run, wait a few minutes and warm up again before the next official measurement.
Delete nothing: `perf/results/diagnostic-slowlog-1.json` may be kept but must not be quoted.

## 9. Regression commands (after steps 1 and 2)

```powershell
docker compose exec app php artisan security:proof
docker compose exec app php artisan orders:race-test --product=1 --count=10
```

`security:proof` must show 15 PASS. Fake perf orders must never be cancelled (they have no inventory reservation).

## 10. Final measured order of commands (use this for every layer)

1. Close heavy programs (quiet machine).
2. Section 1: `Set-ApiLimit 100000`, then `config:show ratelimit` must print 100000.
3. Section 2: warm-up (`warmup.js`), then `Start-Sleep -Seconds 60`.
4. Section 3: record the MySQL status counters and truncate the event-name table.
5. Section 4: start the docker stats loop in a second window.
6. Section 5, run 1: `RUN=1`.
7. `Start-Sleep -Seconds 60`.
8. Section 5, run 2: `RUN=2`. Run 3 only if p95 differs by more than 10 percent (after another 60 s pause).
9. Stop the docker stats loop (Ctrl+C).
10. Section 6: record the MySQL status counters again and the event-name query.
11. Section 7: `Set-ApiLimit 60`, then `config:show ratelimit` must print 60.
12. Section 9 (after Steps 1 and 2 only): `security:proof` and `orders:race-test`.
13. Take screenshots into `docs/r3-evidence/` (`<label>-run1.png`, `<label>-run2.png`) and commit `perf/results/<label>-*`.

For the baseline, the 60 second pause between run 1 and run 2 was not verified (terminal glitch). It did not affect validity because the login limiter was not hit.

## 11. ETag scenario (used later, when Step 5 arrives)

`perf/k6/etag.js` is not part of the baseline. Measure it twice, once per `ETAG_ENABLED` value:

```powershell
docker compose --profile perf run --rm -e LABEL=etag-off -e RUN=1 k6 run /perf/k6/etag.js
docker compose --profile perf run --rm -e LABEL=etag-on -e RUN=1 k6 run /perf/k6/etag.js
```

Follow the same warm-up, pause, two-run and restore rules. Replica lag commands belong to the caching and replica work, not to this file.

## 12. Step 1: per-query counter (TEMPORARY, queries per route with single requests)

The counter is a temporary provider (`app/Providers/PerfQueryCounterServiceProvider.php`, registered in `bootstrap/providers.php`).
It is active only when `PERF_QUERY_LOG=true` and only for web requests. It stays until the final cleanup (Step 7) because Steps 2 and 3 need it. With `PERF_QUERY_LOG=false` it registers no listener and does no work beyond reading the flag.

- It writes `storage/logs/perf-queries.jsonl` inside the app container: one JSON line per query (SQL with `?` placeholders, number of bindings, duration in ms), one per lazy-loading violation (logged, never thrown), and one `request` line per request (method, route, path, query string, status, query count, summed query ms, request ms).
- It must be OFF during every k6 run. The `.env` value must be `false` before you run k6 (section 5 of this runbook).
- Use single `curl.exe` requests, never k6, to count queries. The gRPC stock call is not a database query and is not in this file (the product list makes one `getStock` call per page).
- `request_ms` is wall time of the request inside PHP and includes the logging overhead of this counter. Do not compare it with k6 numbers.

Run from the project root in PowerShell (needs the `Invoke-MySql` helper from section 0 and a raised limit is NOT needed: about 15 requests).

```powershell
function Set-EnvValue([string]$name, [string]$value) {
  $p = (Resolve-Path .env).Path
  $t = [IO.File]::ReadAllText($p)
  if ($t -match "(?m)^$name=") { $t = $t -replace "(?m)^$name=.*", "$name=$value" } else { $t = $t.TrimEnd() + "`n$name=$value`n" }
  [IO.File]::WriteAllText($p, $t, (New-Object Text.UTF8Encoding $false))
}

# 1) enable the flag (takes effect on the next request, no restart needed)
Set-EnvValue PERF_QUERY_LOG true

# 2) log in one heavy fake customer and the admin (these two requests are logged, the next step clears them)
$base = "http://localhost:8080/api/v1"
$ds   = Get-Content perf\k6\customers.json -Raw | ConvertFrom-Json
$cust = $ds.k6_customers[0]
function Get-Token($email) { (Invoke-RestMethod -Method Post -Uri "$base/auth/login" -ContentType "application/json" -Body (@{ email = $email; password = $ds.password } | ConvertTo-Json)).data.token }
$ct = Get-Token $cust.email
$at = Get-Token $ds.admin_email
$orderId = ("SELECT MAX(id) FROM ecommerce.orders WHERE user_id=$($cust.id);" | docker compose exec -T mysql sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot -N' | Select-Object -First 1).Trim()
$adminLast = [math]::Ceiling($ds.orders.total / 20)

# 3) empty the log (inside the container; the file stays writable for php-fpm)
docker compose exec app sh -c ': > storage/logs/perf-queries.jsonl && chmod 666 storage/logs/perf-queries.jsonl'

# 4) ONE curl.exe request per route (and parameter variant)
$calls = @(
  @{ p = "/products?page=1&per_page=15" },
  @{ p = "/products?page=1&per_page=50" },
  @{ p = "/products?page=1&per_page=15&category_id=1" },
  @{ p = "/products?page=1&per_page=15&search=Premium" },
  @{ p = "/products?page=300&per_page=15" },
  @{ p = "/products/$($ds.products.first_id + 100)" },
  @{ p = "/orders?page=1&per_page=15"; t = $ct },
  @{ p = "/orders/$orderId"; t = $ct },
  @{ p = "/orders?page=1&per_page=20"; t = $at },
  @{ p = "/orders?page=300&per_page=20"; t = $at },
  @{ p = "/orders?page=$adminLast&per_page=20"; t = $at }
)
foreach ($c in $calls) {
  $a = @('-s', '-o', 'NUL', '-w', '%{http_code}', '-H', 'Accept: application/json')
  if ($c.t) { $a += @('-H', "Authorization: Bearer $($c.t)") }
  $a += "$base$($c.p)"
  $code = curl.exe @a
  "$code  $($c.p)"
}

# 5) summarise the JSONL
$rows = docker compose exec -T app cat storage/logs/perf-queries.jsonl | Where-Object { $_ } | ForEach-Object { $_ | ConvertFrom-Json }
function Norm($s) { (($s -replace '\s+', ' ') -replace '\?(?:, ?\?)+', '?...') -replace 'in \(\d+(?:, ?\d+)*\)', 'in (ids...)' }

"=== per request: status, queries, summed query ms"
$rows | Where-Object type -eq 'request' | ForEach-Object { [pscustomobject]@{ status = $_.status; queries = $_.queries; db_ms = $_.ms; request = "$($_.method) /$($_.path)?$($_.query)" } } | Format-Table -AutoSize | Out-String -Width 250

$q = $rows | Where-Object type -eq 'query' | ForEach-Object { [pscustomobject]@{ req = $_.req; route = $_.route; ms = [double]$_.ms; nsql = Norm $_.sql } }

"=== repeated normalized queries inside ONE request (N+1 suspects)"
$q | Group-Object { "$($_.req)|$($_.nsql)" } | Where-Object Count -gt 1 | ForEach-Object { [pscustomobject]@{ times = $_.Count; route = $_.Group[0].route; db_ms = [math]::Round(($_.Group | Measure-Object ms -Sum).Sum, 2); sql = $_.Group[0].nsql } } | Sort-Object times -Descending | Format-Table -Wrap -AutoSize | Out-String -Width 250

"=== per normalized SQL over all requests, highest total ms first"
$q | Group-Object nsql | ForEach-Object { [pscustomobject]@{ count = $_.Count; total_ms = [math]::Round(($_.Group | Measure-Object ms -Sum).Sum, 2); sql = $_.Name } } | Sort-Object total_ms -Descending | Select-Object -First 15 | Format-Table -Wrap -AutoSize | Out-String -Width 250

"=== lazy-loading violations"
$rows | Where-Object type -eq 'lazy_loading' | Group-Object route, model, relation | ForEach-Object { "$($_.Count) x $($_.Name)" }

# 6) turn the flag OFF again (required before any k6 run)
Set-EnvValue PERF_QUERY_LOG false
```

The same commands are used for the "after" counts in Gate 1 (turn the flag on, repeat, turn it off).

### 12.2 Helpers for the extra checks (run once per PowerShell window; Set-EnvValue from 12 must exist)

```powershell
$base = "http://localhost:8080/api/v1"
$ds   = Get-Content perf\k6\customers.json -Raw | ConvertFrom-Json
function Get-Token($email) { (Invoke-RestMethod -Method Post -Uri "$base/auth/login" -ContentType "application/json" -Body (@{ email = $email; password = $ds.password } | ConvertTo-Json)).data.token }
function Norm($s) { (($s -replace '\s+', ' ') -replace '\?(?:, ?\?)+', '?...') -replace 'in \(\d+(?:, ?\d+)*\)', 'in (ids...)' }
function Clear-Counter { docker compose exec app sh -c ': > storage/logs/perf-queries.jsonl && chmod 666 storage/logs/perf-queries.jsonl' }
function Read-Counter { docker compose exec -T app cat storage/logs/perf-queries.jsonl | Where-Object { $_ } | ForEach-Object { $_ | ConvertFrom-Json } }
function Show-Counter($labels) {
  $rows = @(Read-Counter)
  $i = 0
  "=== per request (labels are in the order the requests were sent)"
  @($rows | Where-Object type -eq 'request') | ForEach-Object { [pscustomobject]@{ who = $labels[$i++]; status = $_.status; queries = $_.queries; db_ms = $_.ms; request = "$($_.method) /$($_.path)?$($_.query)" } } | Format-Table -AutoSize | Out-String -Width 250
  $q = @($rows | Where-Object type -eq 'query' | ForEach-Object { [pscustomobject]@{ req = $_.req; route = $_.route; ms = [double]$_.ms; nsql = Norm $_.sql } })
  "=== repeated normalized queries inside ONE request (N+1 suspects; empty = none)"
  $q | Group-Object { "$($_.req)|$($_.nsql)" } | Where-Object Count -gt 1 | ForEach-Object { [pscustomobject]@{ times = $_.Count; route = $_.Group[0].route; db_ms = [math]::Round(($_.Group | Measure-Object ms -Sum).Sum, 2); sql = $_.Group[0].nsql } } | Sort-Object times -Descending | Format-Table -Wrap -AutoSize | Out-String -Width 250
  "=== lazy-loading violations: $(@($rows | Where-Object type -eq 'lazy_loading').Count)"
}
function Show-Queries {   # every query of every request, in order (use for short runs)
  $rows = @(Read-Counter)
  foreach ($r in @($rows | Where-Object type -eq 'request')) {
    "--- $($r.status) $($r.method) /$($r.path)  queries=$($r.queries) db_ms=$($r.ms)"
    $rows | Where-Object { $_.req -eq $r.req -and $_.type -eq 'query' } | ForEach-Object { "   {0,8} ms  {1}" -f $_.ms, (Norm $_.sql) }
  }
}
```

### 12.3 Complete list of GET routes (read-only)

```powershell
docker compose exec -T app php artisan route:list --path=api --except-vendor
Set-EnvValue PERF_QUERY_LOG true
$cust = $ds.k6_customers[0]            # heavy fake customer
$ct = Get-Token $cust.email; $at = Get-Token $ds.admin_email
$orderId = ("SELECT MAX(id) FROM ecommerce.orders WHERE user_id=$($cust.id);" | docker compose exec -T mysql sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot -N' | Select-Object -First 1).Trim()
$adminLast = [math]::Ceiling($ds.orders.total / 20)
Clear-Counter
$calls = @(
  @{ w = 'anon';     p = "/health" },
  @{ w = 'anon';     p = "/categories" },
  @{ w = 'anon';     p = "/products?page=1&per_page=15" },
  @{ w = 'anon';     p = "/products?page=1&per_page=50" },
  @{ w = 'anon';     p = "/products?page=1&per_page=15&category_id=1" },
  @{ w = 'anon';     p = "/products?page=1&per_page=15&search=Premium" },
  @{ w = 'anon';     p = "/products?page=300&per_page=15" },
  @{ w = 'anon';     p = "/products/$($ds.products.first_id + 100)" },
  @{ w = 'customer'; p = "/auth/me"; t = $ct },
  @{ w = 'admin';    p = "/auth/me"; t = $at },
  @{ w = 'customer'; p = "/notifications"; t = $ct },
  @{ w = 'admin';    p = "/notifications"; t = $at },
  @{ w = 'customer'; p = "/orders?page=1&per_page=15"; t = $ct },
  @{ w = 'customer'; p = "/orders/$orderId"; t = $ct },
  @{ w = 'admin';    p = "/orders/$orderId"; t = $at },
  @{ w = 'admin';    p = "/orders?page=1&per_page=20"; t = $at },
  @{ w = 'admin';    p = "/orders?page=300&per_page=20"; t = $at },
  @{ w = 'admin';    p = "/orders?page=$adminLast&per_page=20"; t = $at }
)
foreach ($c in $calls) {
  $a = @('-s', '-o', 'NUL', '-w', '%{http_code}', '-H', 'Accept: application/json')
  if ($c.t) { $a += @('-H', "Authorization: Bearer $($c.t)") }
  $a += "$base$($c.p)"
  "$(curl.exe @a)  $($c.w)  $($c.p)"
}
Show-Counter ($calls | ForEach-Object { "$($_.w) $($_.p)" })
Set-EnvValue PERF_QUERY_LOG false
```

`/health` is excluded from the api limiter and does not touch the database. All other routes of `route:list` are writes (POST, PATCH, DELETE) and are not part of this check, except order creation and cancellation in 12.4.

### 12.4 Write path: POST /orders with 1 item and with 4 items, then cancel (uses REAL API orders, never the fake perf orders)

- Uses a light fake customer and fake products 3 to 6 (stock 1,000,000 each).
- Every order created here is cancelled through the API (admin `PATCH /orders/{id}/status`), which releases the reserved stock. The script then compares the stock before and after.
- The cancelled orders stay in the database as cancelled orders (nothing is deleted). They have the highest order ids and add a few rows to `orders` and `order_items`. Do not run it between a baseline and its comparison run unless you accept that.
- gRPC calls: the counter only sees database queries. The inventory service logs one line per call, so the script counts the new lines of `docker compose logs inventory` after each request.

```powershell
$light = @($ds.k6_customers | Where-Object kind -eq 'light')[0]
$ids = 3, 4, 5, 6
function Get-Stock($id) { (Invoke-RestMethod -Uri "$base/products/$id").data.stock }
function Inv-Count { @(docker compose logs --no-log-prefix inventory).Count }
function Inv-New($n0) { Start-Sleep -Seconds 1; $all = @(docker compose logs --no-log-prefix inventory); ($all | Select-Object -Skip $n0) | ForEach-Object { ($_ | ConvertFrom-Json).message } | Group-Object | ForEach-Object { "$($_.Count) x $($_.Name)" } }
function Send-Json($method, $path, $token, $obj) {   # Invoke-WebRequest, because piping a body into curl.exe did not reach the server
  try {
    $r = Invoke-WebRequest -UseBasicParsing -Method $method -Uri "$base$path" -Headers @{ Authorization = "Bearer $token"; Accept = 'application/json' } -ContentType 'application/json' -Body ($obj | ConvertTo-Json -Depth 5 -Compress)
    [pscustomobject]@{ code = [int]$r.StatusCode; body = $r.Content }
  } catch {
    [pscustomobject]@{ code = [int]$_.Exception.Response.StatusCode; body = $_.ErrorDetails.Message }
  }
}

$before = $ids | ForEach-Object { Get-Stock $_ }
"stock before: $($before -join ', ')"
Set-EnvValue PERF_QUERY_LOG true
$lt = Get-Token $light.email; $at = Get-Token $ds.admin_email
Clear-Counter
$created = @()
try {
  foreach ($n in 1, 4) {
    $items = @(); foreach ($id in $ids[0..($n - 1)]) { $items += @{ product_id = $id; quantity = 1 } }
    $n0 = Inv-Count
    $out = Send-Json POST "/orders" $lt @{ items = $items }
    $json = $out.body | ConvertFrom-Json
    "POST /orders with $n item(s): HTTP $($out.code), order id $($json.data.id)"
    if ($json.data.id) { $created += $json.data.id }
    "  gRPC calls to the inventory service: " + ((Inv-New $n0) -join "; ")
  }
}
finally {
  foreach ($oid in $created) {
    $n0 = Inv-Count
    $out = Send-Json PATCH "/orders/$oid/status" $at @{ status = 'cancelled' }
    "PATCH /orders/$oid/status cancelled: HTTP $($out.code)"
    "  gRPC calls to the inventory service: " + ((Inv-New $n0) -join "; ")
  }
}
Show-Queries
Set-EnvValue PERF_QUERY_LOG false
$after = $ids | ForEach-Object { Get-Stock $_ }
"stock after:  $($after -join ', ')"
"stock restored: $((Compare-Object $before $after -SyncWindow 0).Count -eq 0)"
```

