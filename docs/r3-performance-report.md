# Requirement 3: performance report

Status: Step 0 done, baseline recorded (Gate 0). Step 1 done: no N+1 on reads, write-path per-item insert fixed (bulk insert). Step 2: analysis done, no new index is justified, no migration. One query rewrite (deferred join in `OrderService::paginate`) is implemented and checked (JSON identical). The `step2-indexes` k6 run, the regression checks and the write-cost benchmark are still to be done.

Rules for this file:
- Every performance number (p50, p95, p99, requests per second, data volume) comes only from k6 output pasted by the reviewer, or from the JSON files in `perf/results/`. Nothing is estimated.
- Screenshots in `docs/r3-evidence/` are supporting evidence only.
- Sections for Steps 3 to 6 are reserved below and are filled later by a separate request.

## Environment

- Machine: Windows 11 Home, Docker Desktop (Linux engine). Docker sees NCPU=8 and 11.69 GiB of memory. CPU model: not recorded.
- Project folder on `E:\`, mounted into containers through `9p` (slow for many small files). `vendor` lives in the named volume `vendor_data` (local override).
- Stack (versions read from the running containers): PHP 8.3.36, Laravel 13.39.0, MySQL 8.4.13, Redis 7 (`redis:7-alpine`).
- Compose network: `distributed-real-time-e-commerce_default`.
- k6: image `grafana/k6:2.3.0`, running inside the Docker network against `http://nginx/api/v1`.
- Data size (defaults of `perf:seed`, seed 42, reference date 2026-01-01): 2,000 fake customers, 5,000 fake products (0 to 3 images each), 300,000 fake orders with 1 to 4 items each. Exact counts and sizes are in the Baseline section.
- Scenario: 20 VUs, constant, 60 s, no think time. Warm-up: 10 VUs, 20 s (not counted). Each measurement runs twice: both runs are reported and their mean is used. If p95 differs by more than 10 percent, a third run is made and the median of the three is used.
- Route weights: 30% product list, 20% product detail, 20% customer order list, 15% customer order detail, 15% admin order list (includes far pages).
- PHP-FPM: `pm = dynamic`, `pm.max_children = 5`, `pm.start_servers = 2`, `pm.min_spare_servers = 1`, `pm.max_spare_servers = 3`. Image defaults, not changed.
  - Only five requests are processed at the same moment. The rest wait in nginx. The numbers therefore measure capacity under this constraint.
- OPcache: `opcache.enable=On`, `opcache.validate_timestamps=On`, `opcache.memory_consumption=128`.
  - `opcache.jit=tracing` was read as a setting only. The JIT buffer size was not checked, so JIT may not be active.
- Xdebug: not installed. `intl` extension: not loaded.
- `config:cache` and `route:cache`: not enabled (no files in `bootstrap/cache/` except `modules.php`, `packages.php`, `services.php`).
- `CACHE_STORE`: `redis` (effective value from `config:show cache`). The rate limiter and the JWT blacklist use the same store.
- MySQL: `innodb_buffer_pool_size` = 134217728 (128 MB), default. `performance_schema` on. `slow_query_log` off, `long_query_time` 10, `log_output` FILE at the start. Not changed.
  - A 128 MB buffer pool is small for 300,000 orders and their items. Reads will partly hit disk. Not changed.
- Inventory: separate Node.js service over gRPC, own MySQL. One `getStock` call per product list page. Timeout 2000 ms (not changed).
- Rate limit: the global `api` limit (60 per minute per IP) was raised temporarily (`API_RATE_LIMIT_PER_MINUTE`) during measurement only, because all k6 requests come from one IP. Otherwise the test would measure the limiter and return 429. The limiter still runs and writes to Redis on every request, so its cost is part of every request. The default stays 60 and is restored after each measurement.
- Known limits: fake orders have no inventory reservation and cannot be cancelled. Responses with `stock: null`, any 429 or any 5xx make a run invalid.

## Results: before and after (filled after each gate)

Per route and metric, one value per layer. `-` means not measured yet.

Product list (`products_list`)
- baseline (run 1 / run 2): p50 685.84 / 673.28 ms; p95 810.14 / 793.43 ms; p99 862.84 / 854.21 ms; requests 497 / 503; body bytes 6829055 / 6894948
- step1-n-plus-1: unchanged (no code change on the read paths; the fix is on the write path, which the k6 scenario does not send)
- step2-indexes: -
- step3 (Redis cache): reserved
- step4: reserved
- step5 (ETag): reserved
- step6 (Read Replica): reserved

Product detail (`product_detail`)
- baseline (run 1 / run 2): p50 674.22 / 654.11 ms; p95 786.79 / 771.84 ms; p99 822.73 / 848.75 ms; requests 374 / 377; body bytes 156751 / 157590
- step1-n-plus-1: unchanged (no code change on the read paths; the fix is on the write path, which the k6 scenario does not send)
- step2-indexes: -
- steps 3 to 6: reserved

Customer order list (`orders_customer_list`)
- baseline (run 1 / run 2): p50 662.31 / 654.20 ms; p95 779.76 / 778.31 ms; p99 825.85 / 838.71 ms; requests 338 / 347; body bytes 1898452 / 1948884
- step1-n-plus-1: unchanged (no code change on the read paths; the fix is on the write path, which the k6 scenario does not send)
- step2-indexes: -
- steps 3 to 6: reserved

Customer order detail (`order_detail`)
- baseline (run 1 / run 2): p50 660.45 / 646.76 ms; p95 776.58 / 774.86 ms; p99 820.78 / 827.65 ms; requests 272 / 276; body bytes 107536 / 109079
- step1-n-plus-1: unchanged (no code change on the read paths; the fix is on the write path, which the k6 scenario does not send)
- step2-indexes: -
- steps 3 to 6: reserved

Admin order list (`orders_admin_list`)
- baseline (run 1 / run 2): p50 773.45 / 767.29 ms; p95 988.22 / 955.48 ms; p99 1041.77 / 998.20 ms; requests 266 / 269; body bytes 2048246 / 2071963
- step1-n-plus-1: unchanged (no code change on the read paths; the fix is on the write path, which the k6 scenario does not send)
- step2-indexes: -
- steps 3 to 6: reserved

Overall (tag `phase:main`): p50, p95, p99, requests per second, response body bytes (`data_received` sub-metric is n/a in k6)
- baseline (run 1 / run 2 / mean): p50 682.58 / 671.84 / 677.21 ms; p95 858.56 / 860.94 / 859.75 ms; p99 982.35 / 941.09 / 961.72 ms; requests 1747 / 1772; req/s 29.12 / 29.53 / 29.33; body bytes 11040040 / 11182464
- step1-n-plus-1: unchanged (no code change on the read paths; the fix is on the write path, which the k6 scenario does not send)
- step2-indexes: -
- steps 3 to 6: reserved

## Baseline

Source of every number below: the raw k6 output pasted by the reviewer at Gate 0 and the JSON files. Nothing is estimated here.

Evidence
- `perf/results/baseline-1.json`, `perf/results/baseline-2.json` (k6 summaries).
- `perf/results/baseline-docker-stats.txt` (docker stats samples, about every 7 s).
- `docs/r3-evidence/baseline-run1.png`, `docs/r3-evidence/baseline-run2.png` (screenshots, supporting only).

Environment during the runs
- Docker sees NCPU=8 and 11.69 GiB. k6 ran inside the Docker network (`http://nginx/api/v1`).
- `API_RATE_LIMIT_PER_MINUTE` was raised to 100000 during the runs and restored to 60 afterwards (verified with `config:show ratelimit`).
- `pm.max_children` = 5. `innodb_buffer_pool_size` = 128 MB.

Dataset (`perf:seed`, seed 42)
- Rows: customers 2000 (users table 2003), products 5000 (table 5002), orders 300000, order_items 750603, product_images 7569.
- Table sizes in MB (data + index): order_items 61.6 + 43.1, orders 29.6 + 35.2, products 1.5 + 0.2. The two big tables are about 170 MB together, larger than the 128 MB buffer pool.
- Seed phase timings in seconds: customers 0.44, products 0.46, images 0.46, orders generation 2.07, orders INSERT 14.56, order_items INSERT 36.90, total 55.54.
- The 5,000 gRPC `adjust` calls (first real stock sync) took about 44 s (about 114 per second). This was measured from `updated_at` in `inventory-db`, not printed by the command. Later syncs report `adjusted=0`.

Method
- Warm-up: 10 VUs for 20 s, then a 60 s pause, then runs of 20 VUs for 60 s.
- Two runs. p95 differs by 0.3 percent between them, so no third run was needed.
- All runs: RUN VALID = yes, 0 responses with 429, 0 with 5xx, 0 with `stock:null`, failed rate 0 percent.

Results (run 1 / run 2 / mean), values in ms unless stated
- ALL: p50 682.58 / 671.84 / 677.21; p95 858.56 / 860.94 / 859.75; p99 982.35 / 941.09 / 961.72; requests 1747 / 1772; req/s 29.12 / 29.53 / 29.33; body bytes 11040040 / 11182464.
- `products_list`: p50 685.84 / 673.28; p95 810.14 / 793.43; p99 862.84 / 854.21; requests 497 / 503; body bytes 6829055 / 6894948.
- `product_detail`: p50 674.22 / 654.11; p95 786.79 / 771.84; p99 822.73 / 848.75; requests 374 / 377; body bytes 156751 / 157590.
- `orders_customer_list`: p50 662.31 / 654.20; p95 779.76 / 778.31; p99 825.85 / 838.71; requests 338 / 347; body bytes 1898452 / 1948884.
- `order_detail`: p50 660.45 / 646.76; p95 776.58 / 774.86; p99 820.78 / 827.65; requests 272 / 276; body bytes 107536 / 109079.
- `orders_admin_list`: p50 773.45 / 767.29; p95 988.22 / 955.48; p99 1041.77 / 998.20; requests 266 / 269; body bytes 2048246 / 2071963.

Slowest route
- `orders_admin_list` has the highest latency at every percentile in both runs (p50 773.45 / 767.29, p95 988.22 / 955.48).

docker stats during the main phase (100 percent = one core)
- app: average about 116 percent (run 1) and 118 percent (run 2), peak 126 percent.
- mysql: average about 60 percent (run 1) and 52 percent (run 2), peak 85 percent.
- nginx: under 2 percent.
- Memory stayed flat: app about 156 MB, mysql about 671 MB.
- The k6 container CPU was not analysed. Raw samples for the k6 container exist in `perf/results/baseline-docker-stats.txt` (lines named `...-k6-run-<id>`), but they were not summarised here.

Observations (to verify in Steps 1 and 2, not conclusions)
- All five routes have almost the same latency (p50 about 650 to 690 ms). `orders_admin_list` is the slowest.
- Throughput barely changed between the 2000-order smoke dataset (about 29 to 34 req/s) and 300000 orders.
- Latency looks dominated by queueing: 20 VUs against 5 PHP workers. Little's law gives 20 / 0.68 s = about 29 req/s.
- Per request there is about 40 ms of CPU in app and about 19 ms in mysql, against about 170 ms of service time. Most of the time would then be waiting.
- The machine does not look CPU-bound (8 cores).
- MySQL status shows every query is prepared, executed and closed separately (`Com_stmt_prepare`, `Com_stmt_execute` and `Com_stmt_close` are equal), and a new database connection is opened per request (`Quit` and `change_db` counts).
- `pm.max_children` and the PDO options are not changed in Requirement 3. Gains in later steps are attributed to caching and indexes, not to infrastructure.

Slowest queries
- Not available from `performance_schema`. See limit (a) below. Per-query evidence will come from the Step 1 query counter and from `EXPLAIN` in Step 2.

Known limits of this baseline
- (a) The `performance_schema` digest cannot see the application queries, because Laravel uses the binary prepared-statement protocol. Evidence: `statement/com/Execute` = 36692 while `statement/sql/select` = 28, and the digest table held only `SET NAMES` and `USE` rows. The digest is NOT used as evidence.
- (b) The inventory volume keeps its state across `migrate:fresh` (`inventory-db`, volume `inventory_db_data`). Stock for ids 3 to 5002 is deterministic (1,000,000), ids 1 and 2 are 50 and 100. Stock sync time is not comparable between runs.
- (c) Single machine, other programs closed during the runs. One earlier smoke pair showed a 22 percent p95 difference while the machine was busy. The two official runs did not.
- (d) The 60 s pause between run 1 and run 2 was not verified (terminal glitch). It does not affect validity, because the login limiter was not hit.
- (e) Fake orders have no inventory reservation and must never be cancelled.
- (f) The k6 `data_received` sub-metric is n/a. Response body bytes are measured by the script (body only, no headers).
- (g) `opcache.jit=tracing` was read as a setting only. The JIT buffer size was not checked.

## Step 1: N+1

Outcome: no N+1 on any read route. The only per-item loop was the write path (`POST /orders`, one INSERT per item), and it is fixed with one bulk insert. There is no new k6 run for this step, because the k6 scenario only sends read requests and the code serving those requests is unchanged. The `step1-n-plus-1` k6 entries above stay "unchanged (no code change on the read paths)".

- Evidence: `perf/MEASURE.md` sections 12.1 to 12.4 (the commands).
- Screenshots in `docs/r3-evidence/` (supporting only): `step1-before-orders-stock-1.png` and `step1-before-orders-stock2.png` (the write path BEFORE the fix), and `step1-after-orders1.png` and `step1-after-orders2.png` (the write path AFTER the fix, section 12.4 run by the reviewer).
- What changed in the application:
  - `Modules/Orders/Services/OrderService.php`: `$order->items()->createMany($rows)` followed by `$order->load('items')` was replaced by one `OrderItem::query()->insert(...)` for all items (with `order_id`, `created_at`, `updated_at` set explicitly) and the `items` relation set from memory. Same transaction, same order of operations, same gRPC `reserve` and compensation, same response.
  - A temporary query counter was added (`app/Providers/PerfQueryCounterServiceProvider.php`, registered in `bootstrap/providers.php`). It does nothing unless `PERF_QUERY_LOG=true`, and it stays until the final cleanup (Step 7) because Steps 2 and 3 need per-query evidence.
- Method: one request per route, counter ON, through `localhost:8080` as a heavy fake customer or the admin. These numbers are NOT comparable with k6 results: the counter writes a log file, and the timings are single requests.
- Result: no query is repeated inside any request, and 0 lazy-loading violations were logged.

Queries per request (summed query time in ms, single request, counter ON, not comparable with k6)
- `GET /health`: 0 queries, 0 ms.
- `GET /categories`: 1 query, 1.96 ms.
- `GET /products?page=1&per_page=15`: 4 queries, 6.06 ms.
- `GET /products?page=1&per_page=50`: 4 queries, 6.69 ms.
- `GET /products?page=1&per_page=15&category_id=1`: 5 queries, 6.94 ms (the extra query is the `exists` validation of the category).
- `GET /products?page=1&per_page=15&search=Premium`: 4 queries, 6.29 ms.
- `GET /products?page=300&per_page=15`: 4 queries, 7.4 ms.
- `GET /products/103`: 4 queries, 3.78 ms.
- `GET /auth/me` as customer and as admin: 1 query each, 2.35 ms and 2.39 ms.
- `GET /notifications` as customer and as admin: 2 queries each, 8.03 ms and 3.34 ms.
- `GET /orders?page=1&per_page=15` as customer: 4 queries, 13.8 ms.
- `GET /orders/299973` as customer and as admin: 3 queries each, 3.13 ms and 3.32 ms.
- `GET /orders?page=1&per_page=20` as admin: 4 queries, 52.72 ms.
- `GET /orders?page=300&per_page=20` as admin: 4 queries, 38.37 ms.
- `GET /orders?page=15000&per_page=20` as admin (last page): 4 queries, 231.95 ms.
- The query count does not grow with the page size (4 queries at `per_page` 15 and 50) nor with the page number. This is what no N+1 looks like.
- The gRPC `getStock` call of the product routes is not a database query and is not in this counter (one call per list page, from the code).
- A first pass of the same requests showed the same query counts. Its single-request timings differed (for example the last admin page 326.63 ms), so single-request timings are noisy and are only a rough guide.

Write path, first measurement (real API orders, light fake customer, products 3 to 6, then cancelled through the API)
- `POST /orders` with 1 item: 6 queries (user lookup, one product lookup for all items, `insert orders`, 1 `insert order_items`, reload of the items, `insert user_notifications`), 1 gRPC `ReserveStock` call.
- `POST /orders` with 4 items: 9 queries, 1 gRPC `ReserveStock` call.
- So the query count grew by one for each extra item: `$order->items()->createMany($rows)` ran one `insert into order_items` per item. This is a per-item write loop, not a read N+1. The request allows at most 20 items.
- The gRPC calls do NOT grow with the number of items: one `ReserveStock` call carries all items.
- Inside the inventory service (separate service, not changed) `reserveStock.js` runs one `UPDATE stock` and one `INSERT reservation_items` per item inside one transaction, and `releaseStock.js` one `UPDATE stock` per item. That cost is inside the service and is not visible to the application counter.
- Cancel (`PATCH /orders/{id}/status`, admin): 6 queries and 1 gRPC `ReleaseStock` call for both orders.
- Both test orders (ids 300001 and 300002) were cancelled through the API. Stock of products 3 to 6 was 1000000 before and after. The fake perf orders were not touched.
- Dataset note: the cancelled test orders stay in the database (with their order items and notifications). The baseline dataset had 300000 orders. The dataset will be regenerated in Step 2.

Write path fix: before and after (Gate 1)
- Same method for both runs: real API, light fake customer (id 1995), products 3 to 22 with quantity 1, counter ON only for the run, every order cancelled through the API, stock of products 3 to 22 checked before and after. Single requests, counter ON, NOT comparable with k6.
- Before (current code, orders 300005 to 300007):
  - `POST /orders` with 1 item: 6 queries, 1 gRPC `ReserveStock`.
  - `POST /orders` with 4 items: 9 queries, 1 gRPC `ReserveStock`.
  - `POST /orders` with 20 items: 25 queries, 1 gRPC `ReserveStock`.
  - Cancel (`PATCH /orders/{id}/status`): 6 queries, 1 gRPC `ReleaseStock`, for all three orders.
  - Stock restored: yes.
- After (one bulk insert, orders 300008 to 300010):
  - `POST /orders` with 1 item: 5 queries, 1 gRPC `ReserveStock`.
  - `POST /orders` with 4 items: 5 queries, 1 gRPC `ReserveStock`.
  - `POST /orders` with 20 items: 5 queries, 1 gRPC `ReserveStock`.
  - Cancel: 6 queries, 1 gRPC `ReleaseStock`, for all three orders (unchanged).
  - Stock restored: yes.
- The query count no longer grows with the number of items. The 5 queries are: user lookup, one product lookup, `insert orders`, one `insert order_items` for all items, `insert user_notifications`. The reload of the items (`select * from order_items`) is gone because the response needs only the items that were just written, which are now set from memory.
- The reviewer repeated section 12.4 of `perf/MEASURE.md` after the fix on his own terminal: `POST /orders` with 1 item = 5 queries and with 4 items = 5 queries (one `INSERT INTO order_items` with all rows), 1 gRPC `ReserveStock` each, cancel = 6 queries and 1 `ReleaseStock`, stock restored: True (orders 300023 and 300024).
- Unchanged behaviour, checked for 1, 4 and 20 items:
  - The JSON response of `POST /orders` is byte-for-byte equal before and after once the order id and the timestamps are masked (same structure, field order and values). Status 201, `Content-Type` and the `Location` header (with the id masked) are the same.
  - The stored `order_items` rows have the same `product_id`, `product_name`, `unit_price` and `quantity`. `order_id`, `created_at` and `updated_at` are populated in every row, and the item timestamps equal the order's `created_at`.
  - The order row has the same `status` and `total`.
- Checks run after the change: `php artisan test` 56 passed (507 assertions, sqlite in memory, the MySQL dataset is not touched); `security:proof` 15 PASS; `orders:race-test --product=1 --count=10` PASS (one 201, nine 409) once product 1 had stock 1, as in step 4 of `docs/r2-demo-scenario.md`. A first run with stock 40 gave ten 201, which is the correct result for that stock and not a regression (the command expects stock 1). Stock of product 1 was set to 1 through the admin API for the proof and finally restored to 50 (the demo seed value; it was first put back to 40, then raised by 10, and checked through the API and in `inventory-db`). The pending orders created by the race runs remain until the dataset is regenerated in Step 2.

gRPC on the read paths (measured by the reviewer)
- `GET /products?page=1&per_page=15`, `GET /products?page=1&per_page=50` and `GET /products/103`: 1 x `GetStock` call per request. The call is batched per page, so there is no gRPC N+1.

Conclusion for Step 1
- No N+1 on any read route (query counts and gRPC calls are constant per request).
- The only per-item loop was the write path (`createMany`, one INSERT per item). It is fixed: 5 queries for any number of items (1, 4 and 20 measured).
- Not changed, by decision: the duplicate product lookup in product detail (about half a millisecond, not an N+1).

Known limits
- The missing `Modules/*/resources/views` directories were found while testing `php artisan optimize` and were fixed in a separate commit (`11f1c00`).
- Single-request timings come from a counter that writes a log file, so they are only a rough guide and are not comparable with k6.

Observations, not fixed
- Product detail fetches the product twice: `where id = ?` (route model binding) and then `where products.id = ?` (`ProductService::find`). About half a millisecond, not an N+1. Decision: not worth changing the Products module. Kept as an observation.
- Every authenticated request runs one `select * from users where id = ?` (one query, not a loop).
- `GrpcInventoryService` is bound with `bind`, not `singleton` (see the note below).

Inputs for Step 2 (admin order list, from the same single requests)
- `select count(*) as aggregate from orders` runs on every admin page. It took 81.56 ms, 31.51 ms and 31.19 ms on page 1, page 300 and the last page in the first pass.
- Large OFFSET on far pages: `order by id desc limit 20 offset 299980` took 291.53 ms on the last page, against 3.43 ms for `offset 5980` (page 300) and 0.56 ms for `offset 0`.
- The customer order list runs a `count(*)` filtered by `user_id` (5.71 ms for the heavy customer with 16478 orders) and a `limit 15 offset 0` query (8.58 ms).
- Product list queries are all in the low milliseconds at this size (the `count(*)` on products and the `name like` count are 3 to 8 ms).
- Whether an index helps any of these is for Step 2 to decide with `EXPLAIN`.
- Note (no change made): `GrpcInventoryService` is bound with `bind`, not `singleton` (`Modules/Products/Providers/ProductsServiceProvider.php`). Changing it would touch the Requirement 2 contract, so it is only noted.

## Step 2: indexes and EXPLAIN

### Stage A: analysis before any change

Status of this stage when it was written: no migration and no production code change. Only the temporary counter was extended (it now also logs binding values, strings cut to 100 characters, never for `/auth/*`, only when `PERF_QUERY_LOG=true`). The deferred join that followed is described under "Query rewrite" below.

Method
- Dataset regenerated by the reviewer (`migrate:fresh --seed --force`, then `perf:seed`, seed 42). Verified by the reviewer: users 2003, categories 3, products 5002, orders 300000, order_items 750603, product_images 7569, stock of products 1 and 2 = 50 and 100 (API and gRPC), `git diff --stat perf/k6/customers.json` empty. The phase timings of that regeneration run were not pasted, so the baseline phase timings stay the reference.
- Counter run: section 12.3 calls, heavy fake customer (id 4, 16478 orders), admin, two passes ("first" and "second"), counter ON then OFF (`PERF_QUERY_LOG=false` verified). Times are single requests, counter ON, NOT comparable with k6.
- `EXPLAIN` and `EXPLAIN ANALYZE` on literal queries rebuilt from the logged bindings. Each `EXPLAIN ANALYZE` was run twice, recorded as "first" and "warm". Both are noisy. MySQL was not restarted. The counter passes had already read the same pages, so "first" is not a cold-cache run. `ANALYZE TABLE` was NOT run (it rewrites statistics).
- Evidence (supporting only, nothing renamed or moved):
  - `docs/r3-evidence/step2-explain-raw.txt`: the raw `EXPLAIN` and `EXPLAIN ANALYZE` output of every query below, including the deferred-join variants (SQL text, plans and timings only).
  - `docs/r3-evidence/step2-explain-count-and-explain-deep-offset.png`: the reviewer's screenshot of one warm run. It shows `count(*)` 30.2 ms, the plain last-page query 148 ms and the deferred join 66 ms. It is a single warm run. The numbers in this report (the "first" and "warm" runs listed below) stay the reference.

Per-request summed query time (ms, counter ON, first pass / second pass, not comparable with k6)
- `GET /categories`: 2.05 / 1.52
- `GET /products?page=1&per_page=15`: 13.86 / 4.22 (the first pass includes an 11.26 ms `count(*)`, the second 2.69 ms)
- `GET /products?page=1&per_page=50`: 5.29 / 4.84
- `GET /products?...&category_id=1`: 6.05 / 5.77
- `GET /products?...&search=Premium`: 7.90 / 5.59
- `GET /products?page=300&per_page=15`: 9.40 / 7.24
- `GET /products/103`: 3.31 / 2.51
- `GET /auth/me` (customer, admin): 2.23 / 1.57 and 1.90 / 1.48
- `GET /notifications` (customer, admin): 7.09 / 2.35 and 2.45 / 2.17
- `GET /orders?page=1&per_page=15` (heavy customer): 8.11 / 7.05
- `GET /orders/299973` (customer, admin): 2.93 / 2.62 and 3.15 / 2.22
- `GET /orders?page=1&per_page=20` (admin): 34.31 / 38.75
- `GET /orders?page=300&per_page=20` (admin): 36.34 / 36.29
- `GET /orders?page=15000&per_page=20` (admin, last page): 169.38 / 165.46

Slowest single queries (ms, first / second pass)
- `select count(*) as aggregate from orders` (every admin page): 30.91 / 32.14 on page 1, 30.32 / 30.45 on page 300, 30.58 / 30.55 on the last page. Constant.
- `select * from orders order by id desc limit 20 offset 299980` (admin last page): 134.57 / 132.29.
- Same query with `offset 5980`: 3.34 / 3.20. With `offset 0`: 0.69 / 0.56.
- Customer list (heavy customer): `count(*) ... where user_id = ?` 2.77 / 2.75, page query 1.44 / 1.34.
- Everything else is at or under about 5 ms. The per-request `select * from users where id = ?` takes 1.5 to 2 ms (primary key lookup; the time is connection and round-trip overhead, not an index problem).

Existing indexes (from `information_schema.STATISTICS`; size per index from `mysql.innodb_index_stats`, MB)
- `orders`: `PRIMARY(id)` 29.56; `orders_reference_unique(reference)` 24.64; `orders_user_id_id_index(user_id, id)` 10.52.
- `order_items`: `PRIMARY(id)` 61.59; `order_items_order_id_foreign(order_id)` 16.56; `order_items_product_id_index(product_id)` 26.58.
- `products`: `PRIMARY(id)` 1.52; `products_category_id_foreign(category_id)` 0.19.
- `product_images`: `PRIMARY(id)` 1.52; `product_images_product_id_foreign(product_id)` 0.19.
- `categories`: `PRIMARY`, unique `name`, unique `slug`. `users`: `PRIMARY`, unique `email`. `user_notifications`: `PRIMARY`, `(user_id, read_at, id)`.

Table sizes, exact rows (data MB + index MB)
- `orders`: 300000 rows, 29.56 + 35.16. `order_items`: 750603 rows, 61.59 + 43.14.
- `products`: 5002 rows, 1.52 + 0.19. `product_images`: 7569 rows, 1.52 + 0.19.
- `users`: 2003 rows, 0.34 + 0.14. `categories`: 3 rows. `user_notifications`: 0 rows at the time of the query.
- `TABLE_ROWS` in `information_schema` is an estimate (for example 298146 for 300000 orders). Exact counts above come from `count(*)`. `innodb_buffer_pool_size` is 128 MB, `innodb_stats_persistent` is on.

`EXPLAIN` before (key rows only; `EXPLAIN ANALYZE` times in ms, first / warm, noisy)
- Q1 `select count(*) from orders`: `type=index`, `key=orders_user_id_id_index` (the smallest index, 10.5 MB), `Using index`, rows 298146. Actual 30.8 / 30.0. An index scan of all 300000 entries. No further index can make this cheaper.
- Q2a `... order by id desc limit 20 offset 0`: `PRIMARY`, `Backward index scan`, rows 20. Actual 0.94 / 0.03.
- Q2b `offset 5980`: same plan, 6000 rows read. Actual 3.11 / 3.13.
- Q2c `offset 299980`: same plan, 300000 rows read and thrown away. Actual 158 / 150.
- Q3 `select * from order_items where order_id in (20 ids) order by product_id`: `type=range`, `key=order_items_order_id_foreign`, `Using index condition; Using filesort`, 46 to 50 rows. The sort node takes 0.17 / 0.26 ms.
- Q4 customer `count(*) where user_id = 4`: `ref`, `orders_user_id_id_index`, `Using index` (covering), 16478 rows. Actual 3.21 / 3.27.
- Q5a customer list `offset 0`: `ref`, `orders_user_id_id_index`, `Backward index scan`. Actual 0.89 / 0.92.
- Q5b customer list last page (`offset 16470`): same plan, 16478 rows read. Actual 30.7 / 32.5.
- Q6 `count(*) from products where deleted_at is null`: `type=ALL` (5002 rows). Actual 1.47 / 1.46.
- Q7a/Q7b products list `offset 0` and `offset 4485`: `PRIMARY`, `Backward index scan`, `Using where`. Actual 0.03 / 0.03 and 3.8 / 3.3.
- Q8a/Q8b with `category_id = 1`: `ref`, `products_category_id_foreign`. Actual 2.13 / 4.09 (count, 1675 rows) and 0.04 / 0.04 (list).
- Q9a/Q9b with `name like '%Premium%'`: count is `type=ALL` (5002 rows, 488 hits), actual 2.17 / 2.22. The list stops after 220 rows of the PK scan, actual 0.25 / 0.21. A leading wildcard cannot use a B-tree index.
- Q10 `select * from product_images where product_id in (15 ids) order by position`: `range`, `product_images_product_id_foreign`, `Using filesort`, 22 rows. The sort node takes 0.08 ms.

Deferred join, tested with `EXPLAIN ANALYZE` only (not implemented): `select o.* from orders o join (select id from orders order by id desc limit 20 offset N) t on o.id = t.id order by o.id desc`
- The inner query becomes a `Covering index scan on orders using PRIMARY (reverse)` (it does not read whole rows while skipping). No new index is involved.
- `offset 299980`: 69.7 / 69.8 (direct: 158 / 150). About 2.1 times faster.
- `offset 150000`: 37.4 / 35.4 (direct: 77.6 / 80.2). About 2.2 times faster.
- `offset 5980`: 1.49 / 1.48 (direct: 3.11 / 3.13). About 2.1 times faster.
- Cost: it still walks every skipped entry, so the time is still linear in the offset. The `count(*)` is untouched.

Reading of the evidence (Stage A conclusions)
- Every read query that can use an index already does. The only `type=ALL` plans are on `products` (5002 rows, 1.5 to 2.2 ms), where no B-tree index can serve `deleted_at is null` or a leading-wildcard `LIKE`.
- The two expensive queries (the admin `count(*)` at about 30 ms and the deep `OFFSET` at up to about 150 ms) are limited by how many index entries MySQL must walk, not by a missing index. A new index cannot change that.
- The two filesorts (order items: 46 to 50 rows, product images: 22 rows) cost about 0.1 ms each. A composite index would remove them but gives a gain far below the single-request noise.
- No index is proposed.

### Decisions taken by the reviewer after Stage A
- No migration and no new index. Step 2 delivers the `EXPLAIN` evidence and one query rewrite.
- The exact `count(*)` on the admin list stays as it is (about 30 ms, constant). It is deferred to the Step 3 cache.
- Only the deferred join is implemented. Reading from the nearer end of the table, an approximate total, dropping the total, FULLTEXT search and cursor pagination are NOT implemented (they change behaviour or the response shape, or add little: the `LIKE` scan costs about 2 ms on 5002 rows).
- The write-cost question ("what does an index slow down?") is answered with a scratch-table benchmark instead of a rebuild with new indexes (see "Write cost" below). It runs after the k6 run and after the regression checks.

### Query rewrite: deferred join in `OrderService::paginate`
- File: `Modules/Orders/Services/OrderService.php` only. Signature `paginate(?int $ownerId, int $perPage): LengthAwarePaginator` and the returned type are unchanged. It is used by the customer list and the admin list.
- Before: `Order::query()->with('items')->when(owner)->orderByDesc('id')->paginate($perPage)`. After: the same count query (`getCountForPagination()`), then `select orders.* from orders inner join (select id from orders [where user_id = ?] order by id desc limit N offset M) as page_ids on page_ids.id = orders.id order by orders.id desc`, `with('items')` as before, and a `LengthAwarePaginator` built with the same current page and path.
- Why it works (from `EXPLAIN ANALYZE`, see above): the inner query is a covering scan of the primary key, so the skipped rows are not read in full. It is still linear in the offset. No new index is involved.
- JSON identity check (same method as Step 1): 17 requests through the API before and after the change, bodies compared byte for byte (SHA-256).
  - Admin per_page 20 pages 1, 2, 300, 7500, 15000 and 15001 (past the end). Admin per_page 50 pages 1 and 6000. Admin default `/orders`.
  - Heavy customer per_page 15 pages 1, 2, 1099 and 1100 (past the end), and per_page 50 page 1. Light customer pages 1 and 2, and `page=0`.
  - Result: 16 of 17 identical. The 17th is the 422 for `page=0`. It differs only in the per-request `request_id`. With `request_id` normalised the two bodies are identical.
- Shallow pages, old query against new (counter OFF). Method A: PHP inside the container, includes model hydration and the `items` eager load, 3 runs of 200 calls, ms per call, median in brackets. Method B: server side `EXPLAIN ANALYZE` of the main query, 3 runs, median ms. Single machine, noisy.
  - Admin page 1 (offset 0): A old 3.602 / 4.071 / 4.269 (4.071), new 3.838 / 4.047 / 4.114 (4.047). B old 0.034, new 0.117.
  - Admin page 2 (offset 20): A old 3.531 / 3.562 / 3.688 (3.562), new 3.778 / 3.781 / 3.788 (3.781). B old 0.042, new 0.140.
  - Heavy customer 4, page 1 (offset 0): A old 4.047 / 4.076 / 4.182 (4.076), new 3.479 / 3.588 / 3.614 (3.588). B old 0.890, new 0.218.
  - Reading: on shallow admin pages the new form is about 0.1 to 0.2 ms slower (page 1 is within the noise of method A), the customer page is faster, deep pages gain a lot (above). The ids returned were identical in every case. Decision: keep the code as it is, no threshold variant.
- Not covered by `php artisan test`: no test calls the order list. The JSON comparison above is the evidence for this change.

Regression checks so far
- `php artisan test`: 56 passed (507 assertions). The test database is `sqlite` `:memory:` (forced by `phpunit.xml`; the container has no `DB_*`, `APP_ENV` or `CACHE_*` variables that could override it; cache `array`, queue `sync`; no test references MySQL, Redis or gRPC). Real MySQL row counts were identical before and after the run (users 2003, orders 300000, order_items 750603, notifications 0, products 5002).
- `security:proof` and `orders:race-test` are NOT run yet. By decision they run after the `step2-indexes` k6 run, because they write real orders and the k6 run must use the dataset identical to the baseline (300000 orders).

### Stage B status
- Migrations added: none, by decision.
- `step2-indexes` k6 run: not done yet. Per-route and overall `step2-indexes` lines above stay `-` until the reviewer pastes k6 output.
- Write cost of indexes: scratch-table benchmark approved, to run after the k6 run and after `security:proof` and `orders:race-test`. Plan: copies `scratch_orders_v0` to `_v2` and `scratch_order_items_v0` to `_v2` (v0 primary key only, v1 plus one secondary index, v2 all existing indexes), timed load in 2000-row batches, non-indexed `UPDATE`, indexed `UPDATE`, `DELETE`, index sizes, 3 runs and the median, binlog ON. Raw output will go to `docs/r3-evidence/step2-write-cost-raw.txt`. Reference from the reviewer for the real seed (all existing indexes): customers 0.42 s, products 0.48 s, images 0.49 s, orders INSERT 13.56 s, order_items INSERT 35.04 s, total 52.75 s (stock sync excluded).
- Summary table of the benchmark (as bullets): to be filled.
- What does an index slow down? To be answered with the evidence above: every `INSERT`, `UPDATE` and `DELETE` must also update each index, indexes take disk space and buffer pool memory, and unused indexes cost writes without helping any read.

## Step 3: Redis cache

Reserved. Filled by a later request.

## Step 4: reserved

Reserved. Filled by a later request.

## Step 5: ETag

Reserved. Filled by a later request. Scenario script: `perf/k6/etag.js`.

## Step 6: Read Replica

Reserved. Filled by a later request.
