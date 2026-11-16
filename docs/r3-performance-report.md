# Requirement 3: performance report

Status: Step 0 done, baseline recorded (Gate 0). Step 1 done: no N+1 on reads, write-path per-item insert fixed (bulk insert). Step 2: analysis done, no new index is justified, no migration. One query rewrite (deferred join in `OrderService::paginate`) is implemented and checked (JSON identical). The `step2-indexes` k6 run is recorded (2 valid runs, lower than the baseline on every route, NOT attributed to the rewrite: the same-day control run was not done, by decision). The regression checks passed. The scratch-table write-cost and read-side benchmark is done and cleaned up. Zero indexes were added.

Steps 3 to 5 (branch `feat/r3-step3-5-caching`): Step 3 (cache store) is skipped because the store is already Redis. The same-day control `step3-pre` is recorded (2 valid runs). Step 4 (catalog cache in Redis) is in progress and Step 5 (ETag) is not started. Numbers for them are filled only from the k6 output pasted by the reviewer.

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
- step2-indexes (run 1 / run 2): p50 668.40 / 670.47 ms; p95 771.59 / 763.45 ms; p99 807.28 / 802.71 ms; requests 524 / 521; body bytes 7169504 / 7107567. Mean and change against the baseline: see "Step 2 k6 measurement" below.
- step3-pre (same-day control, run 1 / run 2): p50 678.47 / 670.29 ms; p95 834.34 / 779.06 ms; p99 908.05 / 818.03 ms; requests 500 / 517; body bytes 6877153 / 7082417. X-Cache: absent 500 / 517 (no cache exists yet).
- step4-redis: reserved
- step5-etag: reserved
- step6 (Read Replica): reserved

Product detail (`product_detail`)
- baseline (run 1 / run 2): p50 674.22 / 654.11 ms; p95 786.79 / 771.84 ms; p99 822.73 / 848.75 ms; requests 374 / 377; body bytes 156751 / 157590
- step1-n-plus-1: unchanged (no code change on the read paths; the fix is on the write path, which the k6 scenario does not send)
- step2-indexes (run 1 / run 2): p50 646.82 / 645.45 ms; p95 737.75 / 738.55 ms; p99 780.01 / 774.52 ms; requests 388 / 389; body bytes 162568 / 162957. Mean and change against the baseline: see "Step 2 k6 measurement" below.
- step3-pre (same-day control, run 1 / run 2): p50 664.41 / 651.83 ms; p95 800.29 / 750.92 ms; p99 863.00 / 801.45 ms; requests 377 / 386; body bytes 157872 / 161888. X-Cache: absent 377 / 386.
- step4-redis, step5-etag, step6 (Read Replica): reserved

Customer order list (`orders_customer_list`)
- baseline (run 1 / run 2): p50 662.31 / 654.20 ms; p95 779.76 / 778.31 ms; p99 825.85 / 838.71 ms; requests 338 / 347; body bytes 1898452 / 1948884
- step1-n-plus-1: unchanged (no code change on the read paths; the fix is on the write path, which the k6 scenario does not send)
- step2-indexes (run 1 / run 2): p50 646.85 / 642.99 ms; p95 742.89 / 746.08 ms; p99 790.18 / 767.48 ms; requests 359 / 356; body bytes 2017822 / 2000418. Mean and change against the baseline: see "Step 2 k6 measurement" below.
- step3-pre (same-day control, run 1 / run 2): p50 664.02 / 650.62 ms; p95 791.82 / 752.19 ms; p99 827.65 / 798.14 ms; requests 343 / 356; body bytes 1925927 / 2000149. X-Cache: absent 343 / 356.
- step4-redis, step5-etag, step6 (Read Replica): reserved

Customer order detail (`order_detail`)
- baseline (run 1 / run 2): p50 660.45 / 646.76 ms; p95 776.58 / 774.86 ms; p99 820.78 / 827.65 ms; requests 272 / 276; body bytes 107536 / 109079
- step1-n-plus-1: unchanged (no code change on the read paths; the fix is on the write path, which the k6 scenario does not send)
- step2-indexes (run 1 / run 2): p50 635.29 / 635.81 ms; p95 726.50 / 726.33 ms; p99 756.23 / 752.62 ms; requests 281 / 279; body bytes 111074 / 110289. Mean and change against the baseline: see "Step 2 k6 measurement" below.
- step3-pre (same-day control, run 1 / run 2): p50 653.31 / 643.86 ms; p95 807.37 / 739.36 ms; p99 859.62 / 772.76 ms; requests 276 / 278; body bytes 109079 / 109856. X-Cache: absent 276 / 278.
- step4-redis, step5-etag, step6 (Read Replica): reserved

Admin order list (`orders_admin_list`)
- baseline (run 1 / run 2): p50 773.45 / 767.29 ms; p95 988.22 / 955.48 ms; p99 1041.77 / 998.20 ms; requests 266 / 269; body bytes 2048246 / 2071963
- step1-n-plus-1: unchanged (no code change on the read paths; the fix is on the write path, which the k6 scenario does not send)
- step2-indexes (run 1 / run 2): p50 716.45 / 733.32 ms; p95 832.03 / 844.55 ms; p99 866.30 / 880.26 ms; requests 280 / 277; body bytes 2155456 / 2132503. Mean and change against the baseline: see "Step 2 k6 measurement" below.
- step3-pre (same-day control, run 1 / run 2): p50 753.17 / 726.12 ms; p95 918.92 / 826.37 ms; p99 1006.43 / 872.24 ms; requests 268 / 276; body bytes 2014582 / 2075454. X-Cache: absent 268 / 276.
- step4-redis, step5-etag, step6 (Read Replica): reserved

Overall (tag `phase:main`): p50, p95, p99, requests per second, response body bytes (`data_received` sub-metric is n/a in k6)
- baseline (run 1 / run 2 / mean): p50 682.58 / 671.84 / 677.21 ms; p95 858.56 / 860.94 / 859.75 ms; p99 982.35 / 941.09 / 961.72 ms; requests 1747 / 1772; req/s 29.12 / 29.53 / 29.33; body bytes 11040040 / 11182464
- step1-n-plus-1: unchanged (no code change on the read paths; the fix is on the write path, which the k6 scenario does not send)
- step2-indexes (run 1 / run 2 / mean): p50 659.57 / 661.29 / 660.43 ms; p95 773.41 / 780.96 / 777.19 ms; p99 828.60 / 841.48 / 835.04 ms; requests 1832 / 1822; req/s 30.53 / 30.37 / 30.45; body bytes 11616424 / 11513734. Mean and change against the baseline: see "Step 2 k6 measurement" below.
- step3-pre (same-day control, run 1 / run 2 / mean): p50 676.09 / 666.06 / 671.07 ms; p95 834.32 / 785.31 / 809.81 ms; p99 921.92 / 821.91 / 871.91 ms; requests 1764 / 1813; req/s 29.40 / 30.22 / 29.81; body bytes 11084613 / 11429764. X-Cache: absent 1764 / 1813. Details in "Step 3" below.
- step4-redis, step5-etag, step6 (Read Replica): reserved

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
- `security:proof` and `orders:race-test` were run AFTER the `step2-indexes` k6 run (by decision, because they write real orders and the k6 run had to use the dataset identical to the baseline). Results are under "Regression checks after the k6 run" below.

### Step 2 k6 measurement (label `step2-indexes`)

Source of every number in this section: the raw k6 output pasted by the reviewer and `perf/results/step2-indexes-1.json` and `-2.json` (the baseline numbers come from `baseline-1.json` and `-2.json`). All means, differences and percentages were computed from those files by script. Nothing is estimated.

What was measured
- The code under test is the working tree with the deferred join in `OrderService::paginate` (commit `dbd5936`; the tree was at `97b0bdd`). No index was added in Step 2, so nothing here can be attributed to an index.
- Same scenario as the baseline: 20 VUs, constant, 60 s, no think time, a warm-up of 10 VUs for 20 s and a 60 s pause first. Dataset: 300000 orders (`max(id)` 300000 after the runs), regenerated with `perf:seed` seed 42 after the baseline, `customers.json` unchanged.
- `API_RATE_LIMIT_PER_MINUTE` was raised to 100000 for the runs and restored to 60 afterwards (`config:show ratelimit` printed 60). `PERF_QUERY_LOG=false` after the runs. The slow query log stayed off.
- Run 1 finished at 22:15:01 and run 2 at 22:17:27 (local time, UTC+3, as in the docker stats timestamps). The two end times are 146 s apart: an 82.8 s run (including `setup()`) plus about 63 s, which is the 60 s pause in the command plus container start-up.
- Both runs: `RUN VALID` yes, 0 responses with 429, 0 with 5xx, 0 with `stock:null`, failed rate 0 percent. p95 of the two runs differs by 1.0 percent (773.41 and 780.96), so no third run was needed.
- Evidence files: `perf/results/step2-indexes-1.json`, `perf/results/step2-indexes-2.json`, `perf/results/step2-indexes-docker-stats.txt` (UTF-16 text, as `perf/MEASURE.md` warns for `Tee-Object` on Windows PowerShell 5.1), `perf/results/step2-indexes-transcript.txt` (the reviewer's terminal transcript; kept local and not committed, because its header holds the machine name and the Windows user name, and its content is already in the JSON files and in this report). Screenshots (supporting only, saved by the reviewer, never renamed or moved): `docs/r3-evidence/step2-indexes-run1.png`, `docs/r3-evidence/step2-indexes-run2.png`.

MySQL status counters (cumulative, read after the warm-up and after run 2, so the difference covers runs 1 and 2 with their `setup()` requests)
- Before: `Com_select` 10685, `Com_stmt_close` 10447, `Com_stmt_execute` 10447, `Com_stmt_prepare` 12295, `Connections` 2576, `Questions` 14948.
- After: `Com_select` 25532, `Com_stmt_close` 25292, `Com_stmt_execute` 25292, `Com_stmt_prepare` 30101, `Connections` 6461, `Questions` 41404.
- Difference: `Com_select` +14847, `Com_stmt_execute` +14845, `Com_stmt_close` +14845, `Com_stmt_prepare` +17806, `Connections` +3885, `Questions` +26456.
- Statement counts by event name (table cleared before run 1): Prepare 14845, Execute 14845, Close stmt 14845, Quit 3884, change_db 3860, set_option 3859, Ping 23, select 2, error 2, truncate 1, show_status 1. The `Com_stmt_prepare` status counter rose by more than the 14845 prepares in this table. This was not investigated.
- `Connections` rose by 3885 for 3654 counted requests (1832 + 1822) plus the `setup()` requests of the two runs, so a new connection per request still looks to hold. These counters show how many statements were sent. They do not show which SQL was slow.

Comparison against the baseline (mean of 2 runs each, baseline -> `step2-indexes`; req/s = requests / 60)

How "within run-to-run spread" is decided here: the absolute change of the means is compared with the larger of the two run-to-run differences (`|run 1 - run 2|` of the baseline, `|run 1 - run 2|` of `step2-indexes`). With two runs per side this is a rough guide and not a statistical test. It only measures how much two runs in one session differ from each other. It does not measure how much the whole level moves from one day to another.

- Overall (`phase:main`)
  - p50: 677.21 -> 660.43 ms (-16.78 ms, -2.5%). Runs: baseline 682.58 / 671.84, step2-indexes 659.57 / 661.29. Run-to-run difference: baseline 10.74, step2-indexes 1.72. outside the observed run-to-run spread.
  - p95: 859.75 -> 777.19 ms (-82.56 ms, -9.6%). Runs: baseline 858.56 / 860.94, step2-indexes 773.41 / 780.96. Run-to-run difference: baseline 2.38, step2-indexes 7.56. outside the observed run-to-run spread.
  - p99: 961.72 -> 835.04 ms (-126.68 ms, -13.2%). Runs: baseline 982.35 / 941.09, step2-indexes 828.60 / 841.48. Run-to-run difference: baseline 41.26, step2-indexes 12.88. outside the observed run-to-run spread.
  - req/s: 29.33 -> 30.45 (+1.13, +3.8%). Runs: baseline 29.12 / 29.53, step2-indexes 30.53 / 30.37. Run-to-run difference: baseline 0.42, step2-indexes 0.17. outside the observed run-to-run spread.
- Product list (`products_list`)
  - p50: 679.56 -> 669.44 ms (-10.12 ms, -1.5%). Runs: baseline 685.84 / 673.28, step2-indexes 668.40 / 670.47. Run-to-run difference: baseline 12.56, step2-indexes 2.07. within run-to-run spread.
  - p95: 801.78 -> 767.52 ms (-34.26 ms, -4.3%). Runs: baseline 810.14 / 793.43, step2-indexes 771.59 / 763.45. Run-to-run difference: baseline 16.71, step2-indexes 8.14. outside the observed run-to-run spread.
  - p99: 858.53 -> 804.99 ms (-53.53 ms, -6.2%). Runs: baseline 862.84 / 854.21, step2-indexes 807.28 / 802.71. Run-to-run difference: baseline 8.63, step2-indexes 4.58. outside the observed run-to-run spread.
  - req/s: 8.33 -> 8.71 (+0.38, +4.5%). Runs: baseline 8.28 / 8.38, step2-indexes 8.73 / 8.68. Run-to-run difference: baseline 0.10, step2-indexes 0.05. outside the observed run-to-run spread.
- Product detail (`product_detail`)
  - p50: 664.17 -> 646.14 ms (-18.03 ms, -2.7%). Runs: baseline 674.22 / 654.11, step2-indexes 646.82 / 645.45. Run-to-run difference: baseline 20.11, step2-indexes 1.36. within run-to-run spread.
  - p95: 779.32 -> 738.15 ms (-41.16 ms, -5.3%). Runs: baseline 786.79 / 771.84, step2-indexes 737.75 / 738.55. Run-to-run difference: baseline 14.95, step2-indexes 0.80. outside the observed run-to-run spread.
  - p99: 835.74 -> 777.27 ms (-58.48 ms, -7.0%). Runs: baseline 822.73 / 848.75, step2-indexes 780.01 / 774.52. Run-to-run difference: baseline 26.02, step2-indexes 5.49. outside the observed run-to-run spread.
  - req/s: 6.26 -> 6.48 (+0.22, +3.5%). Runs: baseline 6.23 / 6.28, step2-indexes 6.47 / 6.48. Run-to-run difference: baseline 0.05, step2-indexes 0.02. outside the observed run-to-run spread.
- Customer order list (`orders_customer_list`)
  - p50: 658.26 -> 644.92 ms (-13.33 ms, -2.0%). Runs: baseline 662.31 / 654.20, step2-indexes 646.85 / 642.99. Run-to-run difference: baseline 8.11, step2-indexes 3.86. outside the observed run-to-run spread.
  - p95: 779.04 -> 744.49 ms (-34.55 ms, -4.4%). Runs: baseline 779.76 / 778.31, step2-indexes 742.89 / 746.08. Run-to-run difference: baseline 1.45, step2-indexes 3.19. outside the observed run-to-run spread.
  - p99: 832.28 -> 778.83 ms (-53.45 ms, -6.4%). Runs: baseline 825.85 / 838.71, step2-indexes 790.18 / 767.48. Run-to-run difference: baseline 12.86, step2-indexes 22.70. outside the observed run-to-run spread.
  - req/s: 5.71 -> 5.96 (+0.25, +4.4%). Runs: baseline 5.63 / 5.78, step2-indexes 5.98 / 5.93. Run-to-run difference: baseline 0.15, step2-indexes 0.05. outside the observed run-to-run spread.
- Customer order detail (`order_detail`)
  - p50: 653.60 -> 635.55 ms (-18.05 ms, -2.8%). Runs: baseline 660.45 / 646.76, step2-indexes 635.29 / 635.81. Run-to-run difference: baseline 13.69, step2-indexes 0.52. outside the observed run-to-run spread.
  - p95: 775.72 -> 726.41 ms (-49.30 ms, -6.4%). Runs: baseline 776.58 / 774.86, step2-indexes 726.50 / 726.33. Run-to-run difference: baseline 1.72, step2-indexes 0.17. outside the observed run-to-run spread.
  - p99: 824.21 -> 754.43 ms (-69.79 ms, -8.5%). Runs: baseline 820.78 / 827.65, step2-indexes 756.23 / 752.62. Run-to-run difference: baseline 6.87, step2-indexes 3.62. outside the observed run-to-run spread.
  - req/s: 4.57 -> 4.67 (+0.10, +2.2%). Runs: baseline 4.53 / 4.60, step2-indexes 4.68 / 4.65. Run-to-run difference: baseline 0.07, step2-indexes 0.03. outside the observed run-to-run spread.
- Admin order list (`orders_admin_list`)
  - p50: 770.37 -> 724.89 ms (-45.48 ms, -5.9%). Runs: baseline 773.45 / 767.29, step2-indexes 716.45 / 733.32. Run-to-run difference: baseline 6.16, step2-indexes 16.88. outside the observed run-to-run spread.
  - p95: 971.85 -> 838.29 ms (-133.56 ms, -13.7%). Runs: baseline 988.22 / 955.48, step2-indexes 832.03 / 844.55. Run-to-run difference: baseline 32.74, step2-indexes 12.52. outside the observed run-to-run spread.
  - p99: 1019.98 -> 873.28 ms (-146.70 ms, -14.4%). Runs: baseline 1041.77 / 998.20, step2-indexes 866.30 / 880.26. Run-to-run difference: baseline 43.57, step2-indexes 13.96. outside the observed run-to-run spread.
  - req/s: 4.46 -> 4.64 (+0.18, +4.1%). Runs: baseline 4.43 / 4.48, step2-indexes 4.67 / 4.62. Run-to-run difference: baseline 0.05, step2-indexes 0.05. outside the observed run-to-run spread.

What is within the run-to-run spread, and what is not
- Within the spread: `products_list` p50 (-1.5 percent) and `product_detail` p50 (-2.7 percent). Every other change in the list above is outside the spread observed in these two pairs of runs.
- The two `step2-indexes` runs agree closely with each other (overall p95 1.0 percent apart, p50 0.3 percent apart). The two baseline runs agree closely on overall p95 (2.38 ms apart) and less on p50 (10.74 ms) and p99 (41.26 ms).
- Largest change by route: `orders_admin_list` (p50 -5.9 percent, p95 -13.7 percent, p99 -14.4 percent, req/s +4.1 percent).
- Routes that do not call the changed code also changed: `products_list` p95 -4.3 percent, `product_detail` p95 -5.3 percent, `order_detail` p95 -6.4 percent (p99 -6.2 to -8.5 percent for these three; req/s +2.2 to +4.5 percent). `orders_customer_list` does call the changed code, but its k6 pages never exceed offset 30, and its p95 changed by -4.4 percent.
- Overall: p50 -2.5 percent, p95 -9.6 percent, p99 -13.2 percent, req/s +3.8 percent.

docker stats per container for the two run windows (CPU in percent, 100 percent = one core; memory in MiB)

Window used: 62 s to 2 s before the time the summary file was written, to allow about 2 s between the end of the load and the file write. This gives 8 samples for run 1 (22:14:03 to 22:14:53) and 9 for run 2 (22:16:27 to 22:17:24). Samples are about 7 s apart, so the numbers are coarse. The strict 60 s window of `perf/MEASURE.md` section 4 would add one idle sample at 22:15:00.7 to run 1 (app CPU 2.97 percent) and drop the sample at 22:16:27.0 from run 2. With that strict window the app CPU mean is 107.50 percent (run 1) and 122.08 percent (run 2), and the mysql CPU mean is 46.81 percent (run 1) and 43.44 percent (run 2). The `app` and `mysql` peaks do not change.
- Run 1, mean / peak CPU, mean / peak memory:
  - `app`: 120.56 / 127.32 percent, 116.7 / 117.5 MiB.
  - `mysql`: 52.57 / 104.54 percent, 590.3 / 590.4 MiB. The peak is one sample (22:14:24).
  - `nginx`: 1.68 / 1.94 percent, 8.8 / 8.9 MiB.
  - `redis`: 3.73 / 7.29 percent, 12.9 / 12.9 MiB.
  - `inventory`: 3.69 / 7.88 percent, 37.5 / 38.7 MiB.
  - `inventory-db`: 1.77 / 7.81 percent, 474.4 / 474.4 MiB.
  - `k6`: 4.31 / 6.31 percent, 27.4 / 28.6 MiB.
- Run 2, mean / peak CPU, mean / peak memory:
  - `app`: 121.58 / 128.97 percent, 118.1 / 119.3 MiB.
  - `mysql`: 44.01 / 55.48 percent, 590.4 / 590.4 MiB.
  - `nginx`: 1.73 / 1.96 percent, 8.8 / 8.8 MiB.
  - `redis`: 3.03 / 6.02 percent, 13.0 / 13.1 MiB.
  - `inventory`: 2.51 / 4.19 percent, 39.3 / 39.7 MiB.
  - `inventory-db`: 0.86 / 0.94 percent, 474.4 / 474.4 MiB.
  - `k6`: 4.51 / 5.57 percent, 26.3 / 27.2 MiB.
- Baseline values recorded earlier (whole-minute windows, a different window rule): `app` about 116 and 118 percent average with a peak of 126; `mysql` about 60 and 52 percent average with a peak of 85; `nginx` under 2 percent; memory `app` about 156 MB and `mysql` about 671 MB. The windows differ, so this is only a rough comparison.
- Reading: the `app` container is busy at about 120 percent in both sets, which is what a 5-worker PHP-FPM pool under 20 VUs looks like. The `mysql` mean CPU is lower than the baseline mean in both runs, but the sample count is small and the windows are not the same. Container memory differs from the baseline (`app` about 117 MiB against about 156 MB, `mysql` about 590 MiB against about 671 MB), so the environment was not in the same state as on the baseline day.

Interpretation (kept to what the data supports)
- All five routes and the overall figures are lower than the baseline, and requests per second are higher. The two `step2-indexes` runs agree with each other.
- The change is larger on `orders_admin_list` than on the other routes (p95 -13.7 percent against -4.3 to -6.4 percent). The query-time saving that `EXPLAIN ANALYZE` showed for deep and far admin pages points the same way, and 60 percent of the k6 admin requests ask for deep or far pages. This is consistent with a deferred-join effect on that route. It does not prove it.
- The routes that do not use the changed code also improved. Two explanations fit and this data cannot separate them:
  - The system is queue-bound (20 VUs, 5 PHP workers). If admin requests need less service time, workers free up sooner and every route waits less. Then part of the improvement on the untouched routes would itself be an effect of the deferred join.
  - The conditions differed from the baseline day. The baseline files are from 2026-11-12 and this measurement from 2026-11-15. The database was regenerated in between (same seed and data, but page layout, statistics and buffer pool state differ), the container memory figures differ, and the host state is not controlled. A level shift from such differences would move every route.
- The run-to-run comparison used above only compares repeats inside one session. It cannot rule out a day-to-day level difference. No claim is made about what share of the change the deferred join caused.
- To prove the effect of the rewrite, a same-day control run is needed: the parent commit of the deferred join (`7d4943f`), the same dataset, the same procedure and label, run in the same session as a new `step2-indexes` run. It was NOT done, by the reviewer's decision (time). So the numbers above are a before and after on different days, not a controlled experiment, and the improvement is not attributed to the deferred join.
- Not measured and not claimed: any effect on the write path, any effect of caching, and any effect of an index.

### Regression checks after the k6 run
- Code state: the deferred join (`dbd5936`), HEAD `97b0bdd`. `PERF_QUERY_LOG=false` and `API_RATE_LIMIT_PER_MINUTE=60` confirmed afterwards.
- `php artisan test`: 56 passed (see above, run before the k6 run).
- `security:proof`: 15 PASS, 0 FAIL (21 requests). It created order #300001 (customer B, user 3) and cancelled it itself.
- `orders:race-test --product=1 --count=10`: PASS, one 201 and nine 409, final stock 0 as printed by the command. It created order #300002 (user 2, status `pending`), which stays.
- Product 1 stock, every value READ (none inferred), through the API (`GET /api/v1/products/1`) and gRPC (`GetStock`, via grpcurl), and `inventory-db` where noted:
  - Before `security:proof`: API 50, gRPC 50, `inventory-db` 50. Product 2: 100 on all three.
  - After `security:proof`: API 50, gRPC 50 (product 2: 100).
  - PATCH to 1 (`adjustment` -49, computed from the value read, 50): HTTP 200. Read afterwards: API 1, gRPC 1.
  - After the race test: API 0, `inventory-db` 0. gRPC printed `{"productId": "1"}` without `quantity`: proto3 JSON omits a zero value, so the gRPC reading is "field absent", and the API and `inventory-db` readings are the numeric 0.
  - Restore to 50 (`adjustment` +50, computed from the value read, 0): HTTP 200. Read afterwards: API 50, gRPC 50, `inventory-db` 50. Product 2 stayed 100.
- Dataset after the checks: `orders` 300002 (max id 300002), `order_items` 750605, `user_notifications` 3, `users` 2003. The two extra orders are #300001 (cancelled) and #300002 (pending). The dataset is therefore no longer identical to the baseline dataset (300000 orders). Any later k6 run on it differs by two orders (the admin list then has 15001 pages, the last one with 2 rows). The scratch benchmark copies only ids 1 to 300000 (orders) and 1 to 750603 (order items), so it is not affected.
- Not changed by these checks: no migration, no code, no commit.

### Write cost and read side: scratch-table benchmark

Everything in this section was measured on SCRATCH COPIES of `orders` and `order_items`, not on production tables and not through the application. The real tables were only read. No migration was run and no index was added or dropped: the decision stays "zero indexes added in Step 2".

Evidence
- `docs/r3-evidence/step2-write-cost-raw.txt`: the raw output of the whole run (create, three write rounds, reload, read-side runs, drop and verification). SQL, plans, timings and sizes only, no credentials.
- Screenshot of the two summary blocks (WRITE COST and READ SIDE, supporting only, saved by the reviewer, never renamed or moved): `docs/r3-evidence/step2-write-cost-summary-and-read-side-summary.png`.

Method
- Six scratch tables, created and later dropped by exact name: `scratch_orders_v0`, `scratch_orders_v1`, `scratch_orders_v2`, `scratch_order_items_v0`, `scratch_order_items_v1`, `scratch_order_items_v2`. Each is a copy of ids 1 to 300000 (orders) and 1 to 750603 (order items).
  - `orders`: v0 = primary key only, v1 = plus `(user_id, id)`, v2 = all existing indexes (plus unique `reference`).
  - `order_items`: v0 = primary key only, v1 = plus `order_id`, v2 = all existing indexes (plus `product_id`).
- Write side: timed load by `INSERT INTO scratch SELECT * FROM real WHERE id BETWEEN a AND b` in chunks of 2000 ids, autocommit, `READ COMMITTED`, binlog ON (ROW). After the load, a timed `UPDATE` of a non-indexed column, a timed `UPDATE` of an indexed column and a timed `DELETE`. Three rounds, median reported. Index sizes come from `mysql.innodb_index_stats` after `ANALYZE TABLE` on the scratch table (statistics of the real tables were not touched).
- Read side: the three scratch versions were reloaded with the pristine copy (no `UPDATE` or `DELETE` afterwards). For each query and version: `EXPLAIN`, then 2 warm-up runs (not counted) and 7 measured `EXPLAIN ANALYZE` runs, median of the server-side time of the top plan node.
- Run on 2026-11-16, MySQL 8.4.13, `innodb_buffer_pool_size` 128 MB.

Write cost (medians of 3 runs, seconds)
- `orders` (300000 rows)
  - v0, primary key only: load 3.84; non-indexed `UPDATE` 0.15 (19217 rows changed of 20000 matched); `UPDATE` of `user_id` 0.16 (20000 rows, not indexed in v0); `DELETE` 0.09 (20000 rows); size 29.6 MB.
  - v1, plus `(user_id, id)`: load 5.26 (x1.37); non-indexed `UPDATE` 0.15; `UPDATE` of `user_id` 0.25; `DELETE` 0.16; size 40.1 MB (primary 29.6, index 10.5).
  - v2, plus unique `reference` (all existing): load 7.27 (x1.90); non-indexed `UPDATE` 0.18; `UPDATE` of `user_id` 0.29; `DELETE` 0.31; size 64.7 MB (index `reference` 24.6).
  - Load runs (s): v0 4.67 / 3.84 / 3.51, v1 5.26 / 5.09 / 5.41, v2 7.04 / 10.16 / 7.27.
- `order_items` (750603 rows)
  - v0, primary key only: load 8.98; non-indexed `UPDATE` (`quantity`) 0.31; `UPDATE` of `product_id` 0.31 (not indexed in v0); `DELETE` 0.22 (50000 rows each); size 61.6 MB.
  - v1, plus `order_id`: load 10.54 (x1.17); non-indexed `UPDATE` 0.33; `UPDATE` of `product_id` 0.31 (still not indexed in v1); `DELETE` 0.32; size 78.2 MB (index `order_id` 16.6).
  - v2, plus `product_id` (all existing): load 14.64 (x1.63); non-indexed `UPDATE` 0.36; `UPDATE` of `product_id` 0.85; `DELETE` 0.69; size 104.7 MB (index `product_id` 26.6).
  - Load runs (s): v0 8.98 / 9.83 / 8.77, v1 10.93 / 10.44 / 10.54, v2 21.42 / 14.11 / 14.64.
- Reading: every extra secondary index made the bulk load slower and the table bigger. A non-indexed column update did not get meaningfully slower (0.15 to 0.18 and 0.31 to 0.36, within noise at this scale). An update of an indexed column and a delete got slower with each index that covers the changed column or the row (`product_id` update 0.31 to 0.85, `order_items` delete 0.22 to 0.69, `orders` delete 0.09 to 0.31).
- The reference values of the real seed (all existing indexes, from the reviewer): customers 0.42 s, products 0.48 s, images 0.49 s, orders INSERT 13.56 s, order_items INSERT 35.04 s, total 52.75 s (stock sync excluded). The scratch loads (7.27 s and 14.64 s for v2) are server-side bulk copies, so their absolute seconds are NOT comparable with the seed phases. Only the ratios between versions inside this benchmark mean something.

Read side (scratch copies, warm buffer pool, median of 7, server-side ms)
- R1a, customer order list for a heavy customer (user 4, 16478 orders, 5.49 percent of the table): `WHERE user_id = 4 ORDER BY id DESC LIMIT 15`.
  - v0: `type=index`, `key=PRIMARY`, `Extra: Using where; Backward index scan`, 0.15 ms.
  - v1: `type=ref`, `key=orders_user_id_id_index`, `Extra: Backward index scan`, estimated rows 32732, 0.88 ms.
  - v2: same plan as v1, 0.86 ms.
- R1b, same query for a light customer (user 1995, 31 orders): v0 74.50 ms (`PRIMARY` backward scan); v1 0.04 ms and v2 0.04 ms (`ref` on `orders_user_id_id_index`, estimated rows 31).
- R2, order items of 20 orders: `WHERE order_id IN (20 ids) ORDER BY product_id`. v0: `type=ALL`, 746522 estimated rows, 478.00 ms. v1 and v2: `type=range`, `key=order_items_order_id_foreign`, 46 rows, 0.15 ms and 0.14 ms.
- R3, order items of one product: `WHERE product_id = 103` (166 rows). v0 `ALL` 445.00 ms; v1 `ALL` 438.00 ms; v2 `type=ref`, `key=order_items_product_id_index`, 0.32 ms.

The heavy-user result, as measured and what was checked afterwards
- As measured: with the existing index the heavy-user query took 0.88 ms (0.86 ms in v2), and without it (primary key only) 0.15 ms. The index version was slower for this user.
- Explanation (checked, read-only): without the index the plan is a backward scan of the primary key with a filter (`Using where; Backward index scan`, see the `EXPLAIN` in the raw file). A heavy customer owns 5.49 percent of all orders, so about one row in 18 matches, and the scan can stop early. On the real `orders` table with the index ignored (`IGNORE INDEX`, `EXPLAIN ANALYZE`, one run, the real table has 300002 rows) the scan read 273 rows to return the 15 rows. For the light customer (31 orders, 0.01 percent of the table) the same primary-key scan read 143304 rows (149 ms in that single run) against 1.5 ms with the index. So the early stop works only for customers who own a large share of the table. The timings of those single real-table runs were taken right after the benchmark (cold pages) and are not medians. The row counts (273 and 143304) are the evidence, the milliseconds are not.
- Consequence: the benefit of `(user_id, id)` is large for ordinary customers and absent (even slightly negative) for the heaviest ones. The k6 customers include 10 heavy ones, 20 mid-range and 20 light ones, so both cases occur there.

`order_items.product_id` has no read route today
- No read route filters or joins `order_items` by `product_id`: the order list and the order detail read items by `order_id` only, and the Products module must not join `order_items` (module boundary rule). R3 above was therefore a test of a query the application does not run.
- Checked read-only in `information_schema` (`KEY_COLUMN_USAGE`, `REFERENTIAL_CONSTRAINTS`): the only foreign key of `order_items` is `order_items_order_id_foreign` (`order_id` to `orders.id`, `ON DELETE CASCADE`). `product_id` is NOT a foreign key (a plain number with a name and price snapshot, by design). `order_items_product_id_index` is a plain secondary index with no constraint role.
- It is the index with the highest write cost relative to its read use: +26.6 MB, load x1.39 against v1 (14.64 s against 10.54 s), `product_id` update 0.85 s against 0.31 s, delete 0.69 s against 0.32 s. Dropping it is NOT part of Step 2. It is only recorded as a candidate for a later decision.
- Similarly `orders_reference_unique` (24.6 MB, load x1.38 against v1 for orders) is used by no read route. Its purpose is uniqueness of the order reference, so it is not an "unused" index in the sense of being removable.

Why the `order_id` index could be dropped on the copy: `CREATE TABLE ... LIKE` does not copy foreign keys. On the real `order_items` the foreign key `order_items_order_id_foreign` needs an index on `order_id`, so a "primary key only" version (v0) is impossible there. That is why v0 exists only as a scratch copy.

Limits of this benchmark
- Scratch copies: no foreign key, no concurrent traffic, no application code in the path. The real tables were only read.
- The load is a server-side bulk `INSERT ... SELECT` in 2000-row chunks. The application writes differently (one order row and one bulk insert of its items per request, from PHP), so absolute seconds are not application latencies and are not comparable with the `perf:seed` phases. Only the differences between versions are meaningful.
- The `UPDATE` and `DELETE` statements are single statements of 0.1 to 0.9 s each, so their medians are coarse. The v2 load runs vary (orders 7.04 / 10.16 / 7.27, order items 21.42 / 14.11 / 14.64). The median of 3 is used for writes, of 7 for reads.
- Reads ran on a warm buffer pool and show server-side `EXPLAIN ANALYZE` time of the top node, not request time. The real hot data is larger than the 128 MB pool, so real reads can be slower, especially for the full scans of v0.
- Only two customers (one heavy with 16478 orders, one light with 31), one product id (103) and one set of 20 order ids were tested. Other customers or products can behave differently.
- The `UPDATE` of an indexed column changes `user_id` (`orders`) and `product_id` (`order_items`). That is not an update the application performs. It is used only to show when an index must be maintained.
- Sizes are InnoDB statistics after `ANALYZE TABLE` on the scratch tables.
- The decision "zero indexes added" is not changed by these results.

Disk effect of the run (read-only checks)
- Binlog total: 207.1 MB before, 1206.7 MB after (+999.6 MB). Nothing was purged. `E:` free space: 11.92 GB before, 10.08 GB after the run and still 10.08 GB after the six scratch tables were dropped, because the Docker disk image does not shrink by itself.
- Correction of an earlier statement: the "946 GB free" that was quoted earlier in this work was the `Avail` column of `/var/lib/mysql` inside the container. That is the nominal size of the virtual disk (1007 GB), not real free space. The real limit is the free space on `E:`.

Cleanup (done and verified)
- Before the drops, `SHOW TABLES LIKE 'scratch\_%'` listed exactly the six names above, and `USE ecommerce` was confirmed as the application's database (`config("database.connections.mysql.database")` is `ecommerce`). Then only the six approved `DROP TABLE` statements ran. Afterwards no `scratch_` table remained.
- Real tables after the run: `orders` 300002 (max id 300002), `order_items` 750605, `users` 2003, `products` 5002, `product_images` 7569, `user_notifications` 3. The real indexes of `orders` and `order_items` are unchanged.

What does an index slow down, and what does it speed up? (answer from the evidence above)
- It slows down every write that has to maintain it. Each `INSERT` adds an entry to every secondary index: a bulk load of `orders` took x1.37 with one extra index and x1.90 with two, and of `order_items` x1.17 and x1.63. A `DELETE` removes entries from every index (orders 0.09 s to 0.31 s, order items 0.22 s to 0.69 s). An `UPDATE` pays only when it changes an indexed column (`product_id` update 0.31 s to 0.85 s). Updating a non-indexed column was not slowed (within noise).
- It also costs disk space and buffer pool memory: `orders` grew from 29.6 MB to 64.7 MB and `order_items` from 61.6 MB to 104.7 MB with all existing indexes.
- It speeds up reads whose `WHERE` or `ORDER BY` can use it: order items of 20 orders 478 ms to 0.15 ms, one product's order items 445 ms to 0.32 ms, a light customer's order list 74.5 ms to 0.04 ms.
- It does not speed up everything. For the heaviest customer the plain primary-key scan was faster (0.15 ms against 0.88 ms). And no B-tree index helps the admin `count(*)` or the deep `OFFSET` (see Stage A).
- So an index pays off only when reads that use it are frequent and selective enough to outweigh the write, disk and memory cost. In this application `orders (user_id, id)` and `order_items (order_id)` are used by read routes. `order_items (product_id)` and `orders (reference)` are not used by any read route (the second one exists for uniqueness).

### Attribution of the k6 improvement
- The k6 improvement of `step2-indexes` over the baseline is NOT attributed to the deferred join. No same-day control run was made on the parent commit (`7d4943f`). Control run: not done, by decision (time).
- What is attributable to the deferred join: the same-session `EXPLAIN ANALYZE` (the last admin page about 150 ms with the plain query and about 70 ms with the deferred join in the median runs; 148 ms and 66 ms in the reviewer's screenshot) and the identical JSON (16 of 17 responses byte-identical, the 17th identical after normalising `request_id`).
- What the k6 numbers can and cannot say is in "Step 2 k6 measurement" above.

### Stage B status
- Migrations added: none, by decision. Indexes added or dropped: none. Zero indexes added in Step 2.
- `step2-indexes` k6 run: done, 2 valid runs, numbers and comparison in "Step 2 k6 measurement". Not attributed to the deferred join (control run not done, by decision).
- Regression checks: `php artisan test` 56 passed, `security:proof` 15 PASS, `orders:race-test` PASS, product 1 stock restored to 50 and verified.
- Write-cost and read-side scratch benchmark: done and cleaned up, results above, raw output in `docs/r3-evidence/step2-write-cost-raw.txt`.
- Open for the reviewer: committing the evidence files (see the commit list given with the final report).

## Step 3: cache store (skipped) and the same-day control `step3-pre`

Outcome: Step 3 (move the cache store to Redis) is skipped, because the store is already Redis. The label `step3-pre` is a same-day control measurement of the unchanged application, and it is the "before" for Step 4.

Why Step 3 is skipped (read from the loaded configuration inside the `app` container, 2026-11-16)
- `cache.default` = `redis`. Store class `Illuminate\Cache\RedisStore`, store connection `cache`, Redis database 1 (`REDIS_CACHE_DB`), the default connection uses database 0. Client `phpredis`, host `redis`. The app reaches Redis (ping answers).
- The rate limiter and the JWT blacklist use the same store:
  - `RateLimiter` is built by `CacheServiceProvider` with `cache.limiter`, which is not set, so it uses the default store (Redis database 1). `throttleApi()` is used without the Redis throttle variant.
  - The JWT blacklist is `PHPOpenSourceSaver\JWTAuth\Providers\Storage\Illuminate`, built on the default cache repository.
- So moving the store would change nothing: there is no "before" and "after" for it. Side effects that a move would have had (tokens revoked in an old store becoming valid again, every member editing `.env`) do not apply.
- Related facts checked at the same time (documentation only, nothing changed): `config:cache` and `route:cache` are not active; nginx has no active `gzip` directive; PHP-FPM `pm.max_children` is 5; OPcache is enabled with `opcache.jit=tracing` but `opcache.jit_buffer_size=0`, so the JIT is not active (earlier notes said "may not be active", now verified); the Redis service is `redis:7-alpine` without a config file and with an anonymous volume at `/data` (image default), so cached values survive `docker compose restart redis` and any reseed.

### `step3-pre` measurement

Source of every number: the raw k6 output pasted by the reviewer and `perf/results/step3-pre-1.json` and `step3-pre-2.json` (all means and percentages below were computed from those files by script). Nothing is estimated.

What was measured
- Code under test: `main` as merged (PR #15, `13758be`) plus the commit `3278396` that changed only the k6 scripts (`perf/k6/lib.js`, `perf/k6/etag.js`). `git diff --stat 97b0bdd HEAD` over `app`, `Modules`, `config`, `bootstrap`, `routes`, `database`, `docker`, `docker-compose.yml`, `composer.json` and `composer.lock` is empty: the production code is the one measured by `step2-indexes`.
- Procedure: the standard one (`warmup.js`, 60 s pause, run 1, 60 s pause, run 2). No replay, because no cache exists yet. Warm-up: 583 iterations.
- Same scenario as before: 20 VUs, constant, 60 s, no think time. `API_RATE_LIMIT_PER_MINUTE` was 100000 during the runs and 60 afterwards (verified with `config:show ratelimit`). `PERF_QUERY_LOG=false` before and after (verified). The slow query log stayed off.
- Dataset: users 2003, products 5002, orders 300002 (the two extra orders come from the Step 2 regression checks), order_items 750605, product_images 7569, categories 3. Stock of products 1 and 2 was 50 and 100 (API and `inventory-db`). `customers.json` unchanged.
- Timeline (local time, UTC+3): start 17:51:57, run 1 finished 17:55:14 (+199 s), run 2 finished 17:58:17 (about +382 s). Per k6 invocation about 82 to 84 s including `setup()`.
- Validity: both runs `RUN VALID` yes, 0 responses with 429, 0 with 5xx, 0 with `stock:null`, failed rate 0 percent. p95 of the two runs differs by 49.01 ms (6.05 percent of their mean), under the 10 percent limit, so no third run was needed.
- X-Cache counters (new in this step): every counted response was `absent` (1764 in run 1, 1813 in run 2), as expected before the cache exists.
- Token check: the two JSON files contain `"token": "<redacted>"` in `setup_data` and no `eyJ` string. This also shows that k6 2.3.0 passes `setup_data` to `handleSummary`, so the redaction was needed and works in a real run.
- Evidence files: `perf/results/step3-pre-1.json`, `perf/results/step3-pre-2.json`, `perf/results/step3-pre-docker-stats.txt` (written by `Tee-Object` as UTF-16; the reviewer converted it to UTF-8 without BOM after the run, content unchanged; I re-read it after the conversion: 434 lines, the same window samples and means as in this section). Screenshots (supporting only, saved by the reviewer): `docs/r3-evidence/step3-pre-run1.png`, `docs/r3-evidence/step3-pre-run2.png`. The stats loop was started during the warm-up (17:52:20), so only the samples inside the two run windows belong to the measurement.

Results (run 1 / run 2 / mean), values in ms unless stated; req/s = requests / 60
- ALL: p50 676.09 / 666.06 / 671.07; p95 834.32 / 785.31 / 809.81; p99 921.92 / 821.91 / 871.91; requests 1764 / 1813; req/s 29.40 / 30.22 / 29.81; body bytes 11084613 / 11429764.
- `products_list`: p50 678.47 / 670.29 / 674.38; p95 834.34 / 779.06 / 806.70; p99 908.05 / 818.03 / 863.04; requests 500 / 517; body bytes 6877153 / 7082417.
- `product_detail`: p50 664.41 / 651.83 / 658.12; p95 800.29 / 750.92 / 775.60; p99 863.00 / 801.45 / 832.22; requests 377 / 386; body bytes 157872 / 161888.
- `orders_customer_list`: p50 664.02 / 650.62 / 657.32; p95 791.82 / 752.19 / 772.00; p99 827.65 / 798.14 / 812.90; requests 343 / 356; body bytes 1925927 / 2000149.
- `order_detail`: p50 653.31 / 643.86 / 648.58; p95 807.37 / 739.36 / 773.37; p99 859.62 / 772.76 / 816.19; requests 276 / 278; body bytes 109079 / 109856.
- `orders_admin_list`: p50 753.17 / 726.12 / 739.65; p95 918.92 / 826.37 / 872.64; p99 1006.43 / 872.24 / 939.33; requests 268 / 276; body bytes 2014582 / 2075454.

MySQL status counters (cumulative; read after the warm-up and after run 2, so the difference covers runs 1 and 2 with their `setup()` requests)
- Before: `Com_select` 2965, `Com_stmt_close` 2545, `Com_stmt_execute` 2545, `Com_stmt_prepare` 3070, `Connections` 3575, `Questions` 14574.
- After: `Com_select` 17502, `Com_stmt_close` 17080, `Com_stmt_execute` 17080, `Com_stmt_prepare` 20504, `Connections` 7408, `Questions` 40514.
- Difference: `Com_select` +14537, `Com_stmt_execute` +14535, `Com_stmt_close` +14535, `Com_stmt_prepare` +17434, `Connections` +3833, `Questions` +25940.
- Statement counts by event name (table cleared before run 1): Execute 14535, Prepare 14531, Close stmt 14531, Quit 3831, set_option 3783, change_db 3782, Ping 48, select 2, error 2, truncate 1, show_status 1. As in Step 2, the `Com_stmt_prepare` status counter rose by more than the Prepare events in this table (17434 against 14531). Not investigated.
- These counters are the "before" for the database work that the Step 4 cache should remove (statements sent per run). They do not show which SQL was slow.

docker stats for the two run windows (CPU in percent, 100 percent = one core; memory in MiB). Window: 62 s to 2 s before the time the summary file was written (17:55:14 and 17:58:17), as in the Step 2 report. 8 samples for run 1 and 9 for run 2, about 7 s apart, so the numbers are coarse.
- Run 1, mean / peak CPU: `app` 127.68 / 137.65, `mysql` 50.48 / 75.87, `nginx` 1.62 / 1.77, `redis` 3.25 / 6.53, `inventory` 3.22 / 5.91, `inventory-db` 2.61 / 7.81, `k6` 4.80 / 6.54. Memory means: `app` 167.3, `mysql` 692.5, `redis` 14.1, `inventory` 36.1, `inventory-db` 501.5, `nginx` 9.8, `k6` 29.5.
- Run 2, mean / peak CPU: `app` 120.40 / 130.01, `mysql` 33.24 / 52.45, `nginx` 1.56 / 1.87, `redis` 3.13 / 6.99, `inventory` 3.31 / 9.39, `inventory-db` 0.89 / 1.13, `k6` 4.96 / 6.60. Memory means: `app` 167.1, `mysql` 692.7, `redis` 14.1, `inventory` 39.2, `inventory-db` 501.6, `nginx` 9.8, `k6` 27.9.

Reading (careful, two runs only)
- The application code did not change between `step2-indexes` and `step3-pre`, yet the overall numbers differ: p50 660.43 to 671.07 ms (+1.6 percent), p95 777.19 to 809.81 ms (+4.2 percent), p99 835.04 to 871.91 ms (+4.4 percent), req/s 30.45 to 29.81 (-2.1 percent). This is between-session variation of the machine (another day and session), not a code effect. A Step 4 change of that size or smaller cannot be told apart from session noise, and the three order routes (not cached) are the in-run control for that.
- Within this session the two runs differ more than the two runs of `step2-indexes` did (p95 49.01 ms apart against 7.56 ms), mostly on `orders_admin_list` (p95 918.92 against 826.37) and `order_detail` (807.37 against 739.36). The 10 percent rule was not triggered.
- This is a control for the current code only. There is still no same-day run of the code before the deferred join, so the `step2-indexes` improvement over the baseline stays unattributed, as recorded in "Attribution of the k6 improvement" above.

## Step 4: catalog cache in Redis

Status: implemented and checked (see "Checks done before the measurement"). The `step4-redis` measurement is not done yet. Its numbers are filled only from the k6 output pasted by the reviewer.

What is cached, and what is not
- Cached, as plain arrays only (never models, closures or paginators): product list pages, product details and `GET /categories`. No API route writes categories (they come from the seeder), so that list has the longest TTL.
- Not cached:
  - Stock quantities. Their source is the inventory service and they change with every order. Every list or detail request makes one `GetStock` call (for the whole page), whether the product data came from the cache or from the database, and writes the quantity into the `stock` slot of the cached array. This call is the main per-request cost that remains on a cache HIT. It is not measured separately in this work, so no number is given. Caching quantities for a short time was raised as a question and the decision is to keep stock live.
  - Anything user specific (orders, user data, tokens).
  - Errors and every non-200 response, including 404: the fill leaves through an exception before anything is stored.
  - `ProductCatalogService::findForOrder()`, the price snapshot used when an order is created. It reads the database directly and stays uncached.
- Nothing request dependent is stored. Checked on stored values: `stock` is stored as `null`, there is no object, no `request_id`, no host. Image URLs come from `APP_URL` through the disk URL (`config/filesystems.php`), not from the request: a fill made with `Host: other.test` gave a body without `other.test`, identical to the next read. If `APP_URL` is ever changed, the cache must be flushed.

Where the cache sits (all inside `Modules/Products`)
- `Services/CatalogCache.php`: key names, version counters, invalidation, TTLs from config. The only code that knows the keys. Uses the `Cache` facade only (no `Redis::`, no `Cache::tags()`).
- `Services/CachedProductReader.php`: `list()`, `detail()`, `categories()`. Used only by `ProductController::index`, `ProductController::show` and `CategoryController::index`.
- `Console/CacheFlushCommand.php`: `products:cache-flush`.
- `ProductService` keeps its public signatures and stays uncached. Its write methods call `find()` inside transactions to build their responses, so a cached `find()` could fill the cache with uncommitted data or give the admin a stale value. The reader calls `ProductService::paginate()` and `find()` on a MISS, so the query logic exists once, and the live stock comes with them: a MISS makes no extra `GetStock` call.
- The controllers build the response with the unchanged `ApiResponse` helper (a `LengthAwarePaginator` of arrays for lists, so the `meta` block comes from the same shared code) and add the header `X-Cache: HIT` or `MISS`. The JSON body is byte-identical to the previous one.

Keys, versions and TTLs
- Prefix and code version: `products:v1:`. A change of the cached array shape bumps `v1` in `CatalogCache::KEY_VERSION`.
- Counters (created by `Cache::increment`, no TTL): `products:v1:ns` (bumped only by the flush command), `products:v1:list-ver` (bumped on every catalog write), `products:v1:product-ver:{id}` (bumped when that product is written). A missing counter reads as 0.
- Entries: list `products:v1:l:{ns}:{list-ver}:{sha1 of [category_id, search, page, per_page]}`, detail `products:v1:d:{ns}:{id}:{product-ver}`, categories `products:v1:c:{ns}`. The list parameters are normalised (defaults applied: page 1, per_page 15; an empty search is the same as no search; fixed order; hashed because `search` can be 255 characters). `/products`, `/products?page=1&per_page=15` and `?search=` share one key (checked: the second and third requests were HITs). The response is the same for admin and customer, so no role is in the key.
- TTLs (config `products.cache.*`, env `PRODUCTS_CACHE_LIST_TTL`, `PRODUCTS_CACHE_DETAIL_TTL`, `PRODUCTS_CACHE_CATEGORIES_TTL`, block `# r3: caching` in `.env.example`): lists 600 s, details 1800 s, categories 3600 s. They are read in the config file only. Because versions do the invalidation, the TTL is a safety net (it bounds staleness if an invalidation is missed, and memory) and not the freshness mechanism.
- TTL against the measurement timeline (durations from the Step 2 and Step 3 runs: about 83 s per k6 invocation including `setup()`, plus 60 s pauses): from the flush to the end of run 2 is about 8 minutes (roughly 480 s). The oldest entries are written during the warm-up, about 25 s after the flush, so at the end of run 2 they are about 450 s old, under the 600 s list TTL. Expected expirations during run 1 and run 2: none. A third run would bring the oldest list entries to about 600 s near its end. The per-run HIT/MISS counts and the `Get-Date` stamps of the gate script show whether that happened (the elapsed time from the flush to the end of each measured run is reported, and above 570 s the report says that list keys written by the warm-up may have expired).
- Scenario facts that shape the HIT ratio (not performance numbers): the list sequence has at most 98 distinct keys (18 unfiltered pages, plus 5 pages x 2 page sizes x (3 categories + 5 search terms) = 80 filtered). Product detail picks ids uniformly from 5000, so within one run most detail requests are first-time keys unless a replay has already filled them.

Invalidation, and the race it prevents
- Writes (inside the `ProductService` write methods, not in model events, because bulk inserts such as `perf:seed` fire no events): the database write commits, then `DB::afterCommit` bumps the versions. Outside a transaction the callback runs at once, inside one it runs after the outermost commit and is dropped on rollback (checked on the real MySQL connection). `create` bumps the list version; `update`, `delete` and `addImages` bump the list version and the product version. `adjustStock` bumps nothing, because it changes only the inventory service and stock is never cached.
- Reads on a MISS: (1) read the version counters, (2) build the entry key from them and try it, (3) read the database on the primary, (4) store under the key built in step 2.
- The race: a reader loads the old row, a writer commits and bumps the version, then the reader stores. The late store lands on the OLD version key, which nobody reads any more, and it expires by TTL. With "delete after write" the late store would have landed on the live key and stayed stale until its TTL.
- Remaining window, by design: between the writer's commit and its version bump (milliseconds) a reader can still HIT the old entry. The admin who wrote reads after the bump, so read-your-writes holds for the writer.
- A soft delete is caught first by the route model binding (404), and the version bump frees the dead entries.
- If a counter key were lost while old entries survive (Redis has no `maxmemory` here, so no eviction), stale values could live until their TTL at most.
- Writers that bypass `ProductService` (seeders, `perf:seed`, `migrate:fresh`, direct SQL) do not invalidate. After any of them run `products:cache-flush`.

Cache fill from the primary database (needed for Step 6)
- Verified in the installed Laravel 13.39.0 and on a temporary read/write-split connection created in memory (not written anywhere), recording for each query of one list page whether it used the read or the write PDO:
  - plain `paginate`: read, read, read, read (count, page, category eager load, images eager load);
  - `Builder::useWritePdo()` on the main query only: write, write, read, read. So it is NOT enough: the eager loads are separate builders;
  - `Connection::usingWritePdo()` around the whole fill: write, write, write, write, and the next plain page is back to read, read, read, read.
- Choice: every fill (list, detail, categories) runs inside `DB::connection()->usingWritePdo(...)` in `CachedProductReader::fromPrimary()`. It sets `readOnWriteConnection`, which `Connection::getReadPdo()` checks, and restores the previous value in a `finally`. No BEGIN/COMMIT round trips.
- Not covered, noted for Step 6: the `exists:categories,id` validation of `category_id` and the route model binding of `show` still read through the normal read connection, and `ProductCatalogService::findForOrder()` would read from a replica unless it is routed to the primary then.

`show` and the route model binding
- Kept. The binding is one primary key query and it is also the source of the existing 404 body (missing or soft-deleted id, non-numeric id), so the route signature and all 404 behaviour stay as they were. The reader is called with the bound product's id. The cost is one query that remains on a HIT; its measured count is recorded at the query-count check.
- The alternative (resolve the id inside the reader) needs `whereNumber`, an integer-typed parameter and a hand-made 404, and changes how ids such as `1e3` behave. Little to gain for a primary key lookup.

Flush command and why not `cache:clear`
- `php artisan products:cache-flush` bumps `ns` once. Every list, detail and categories entry stops being read, with no `Redis::`, no `FLUSHDB` and no pattern delete. Old entries expire by TTL.
- `php artisan cache:clear` is not used: the cache store also holds the rate limiter counters and the JWT blacklist, and it would flush all of them.

Cache stampede
- Documented, not implemented. When a hot key expires, several requests can rebuild it at the same time. With `pm.max_children = 5` at most 5 workers can do so for one key. No lock was added, because nothing in the numbers shows a problem; if it ever does, that is a separate decision.

Other notes
- Empty list pages (past the last page, or a search with no match) are cached like any 200: cheap to store and bounded by the TTL. Many distinct `search` values or page numbers can grow the key space until the TTLs expire; Redis has no `maxmemory` here and it was not changed.
- If Redis is down, the throttle middleware already fails first, so the catalog cache adds no new failure mode. Not handled.
- The JWT library itself calls `tags('tymon.jwt')` on the cache. That is library code; the application code uses no tags.

Checks done before the measurement (production code changed only from this point)
- `php -l` on all changed files, `pint --test` on the 8 new and changed PHP files: pass. `php artisan test`: 56 passed (507 assertions), same as before (SQLite in memory, array cache).
- Body comparison: 25 URLs captured before the change (code unchanged, `X-Cache` absent on all) and after it, twice (first pass MISS, second pass HIT): lists (defaults, pages 1, 2, 101, 102 and 300, both page sizes, `category_id`, `search`, both together, a search without match, an empty `search=`), `/categories`, details of products 1, 2, 3 (three images), 103 and 5002, and five error cases. 20 bodies are byte-identical in all three passes. The 5 error bodies (two 404, three 422) are identical once the per-request `request_id` is normalised. The other response headers are identical in all 25 cases (only `X-Cache` is new).
- `GET /categories`, lists and details answered `MISS` on the first request of a key and `HIT` on the second. Errors carry no `X-Cache`.
- Stored values inspected through the `Cache` facade: see "Nothing request dependent is stored" above.
- The mechanism checks for the primary fill and for `DB::afterCommit` are described above.

Procedure of `step4-redis`. The warm-up procedure of this label is: `warmup.js`, a 60 s pause, one discarded replay of `baseline.js` with `RUN=0` (no JSON file, no docker stats, no screenshot, not in any table or median), a 60 s pause, then the two runs. The reason: every VU draws the same pseudo-random sequence in every run (the generator is seeded only by the VU number), so without the replay run 2 would repeat run 1's keys on a warm cache while run 1 starts partly cold.

## Step 5: ETag

Reserved. Filled by a later request. Scenario script: `perf/k6/etag.js`.

## Step 6: Read Replica

Reserved. Filled by a later request.
