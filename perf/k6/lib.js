// Shared helpers for the Requirement 3 k6 scripts. Everything here is deterministic:
// no Math.random, a per-VU linear congruential generator, fixed page and filter sets.
import http from 'k6/http';
import { Counter } from 'k6/metrics';

export const BASE_URL = __ENV.BASE_URL || 'http://nginx/api/v1';
export const DATASET = JSON.parse(open('/perf/k6/customers.json'));

// Fixed scenario shape. Do not change between steps (see requirement_3_plan.md).
export const MAIN_VUS = 20;
export const MAIN_DURATION_SECONDS = 60;
export const WARMUP_VUS = 10;
export const WARMUP_DURATION_SECONDS = 20;

export const ROUTES = [
  'products_list',
  'product_detail',
  'orders_customer_list',
  'order_detail',
  'orders_admin_list',
];

export const rateLimited429 = new Counter('rate_limited_429');
export const serverErrors5xx = new Counter('server_errors_5xx');
export const stockNullResponses = new Counter('stock_null_responses');
// Response body bytes of counted (phase:main) requests, tagged per route. Measured by the script itself,
// because k6's own data_received sub-metrics may receive no samples when filtered by request tags.
export const bodyBytes = new Counter('response_body_bytes');

// Call for every counted (phase:main) response. Setup requests never call this.
export function recordBody(res, route) {
  bodyBytes.add(res.body ? res.body.length : 0, { name: route });
}

// ---------- deterministic generator (per VU, module state is per VU in k6) ----------
let state = ((__VU || 1) * 2654435761) >>> 0;
export function rnd() {
  state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
  return state / 4294967296;
}
export function pick(list) {
  return list[Math.floor(rnd() * list.length)];
}
export function intBetween(lo, hi) {
  return lo + Math.floor(rnd() * (hi - lo + 1));
}

// ---------- options ----------
export function thresholdsFor(routes) {
  // Always-true thresholds: they never fail the run, they only make k6 create the per-route sub-metrics.
  const t = {
    'http_req_duration{phase:main}': ['max>=0'],
    'http_req_failed{phase:main}': ['rate>=0'],
    'http_reqs{phase:main}': ['count>=0'],
    'data_received{phase:main}': ['count>=0'],
    response_body_bytes: ['count>=0'],
  };
  routes.forEach((r) => {
    t[`http_req_duration{name:${r}}`] = ['max>=0'];
    t[`http_req_failed{name:${r}}`] = ['rate>=0'];
    t[`http_reqs{name:${r}}`] = ['count>=0'];
    t[`data_received{name:${r},phase:main}`] = ['count>=0'];
    t[`response_body_bytes{name:${r}}`] = ['count>=0'];
  });
  return t;
}

export function mainOptions(routes, execName) {
  return {
    scenarios: {
      [execName]: {
        executor: 'constant-vus',
        vus: MAIN_VUS,
        duration: `${MAIN_DURATION_SECONDS}s`,
      },
    },
    summaryTrendStats: ['med', 'p(95)', 'p(99)', 'avg', 'max'],
    thresholds: thresholdsFor(routes),
  };
}

// ---------- requests ----------
function params(token, route, extraHeaders) {
  const headers = Object.assign({ Accept: 'application/json' }, extraHeaders || {});
  if (token) headers.Authorization = `Bearer ${token}`;
  return { headers, tags: { name: route, phase: route.startsWith('setup') ? 'setup' : 'main' } };
}

function record(res) {
  if (res.status === 429) rateLimited429.add(1);
  if (res.status >= 500) serverErrors5xx.add(1);
}

export function get(path, route, token, extraHeaders) {
  const res = http.get(`${BASE_URL}${path}`, params(token, route, extraHeaders));
  record(res);
  if (!route.startsWith('setup')) recordBody(res, route);
  return res;
}

function hasNullStock(res) {
  if (res.status !== 200) return false;
  let body;
  try {
    body = res.json();
  } catch (e) {
    return false;
  }
  const d = body && body.data;
  const items = Array.isArray(d) ? d : d ? [d] : [];
  return items.some((p) => p && p.stock === null);
}

export function countNullStock(res) {
  if (hasNullStock(res)) stockNullResponses.add(1);
}

export function login(email, password) {
  const res = http.post(`${BASE_URL}/auth/login`, JSON.stringify({ email, password }), {
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    tags: { name: 'setup_login', phase: 'setup' },
  });
  record(res);
  if (res.status !== 200) {
    throw new Error(`setup login failed for ${email}: HTTP ${res.status}`);
  }
  return res.json().data.token;
}

// ---------- setup(): fixed customer set, not counted in any measurement ----------
export function buildSetup() {
  const customers = DATASET.k6_customers.map((c) => {
    const token = login(c.email, DATASET.password);
    const first = get('/orders?per_page=15', 'setup_orders_prefetch', token);
    const body = first.json();
    const ids = (body.data || []).map((o) => o.id);
    return { token, orderIds: ids, lastPage: body.meta ? body.meta.last_page : 1 };
  });

  const adminToken = login(DATASET.admin_email, DATASET.password);
  const adminFirst = get('/orders?per_page=20', 'setup_orders_prefetch', adminToken).json();
  const cats = get('/categories', 'setup_categories', null).json();
  const categoryIds = (cats.data || []).map((c) => c.id);

  return {
    customers,
    admin: { token: adminToken, lastPage: adminFirst.meta.last_page },
    categoryIds,
  };
}

// ---------- the fixed read scenario ----------
// Only VALID pages (never beyond the last page, so every request reaches the stock gRPC call).
// With about 5,000 products: unfiltered per_page=15 has about 334 pages, per_page=50 about 101 pages.
// A category (about 1,700 products) or a search term (at least about 270 products) has 5+ pages at per_page=50.
const PAGES_UNFILTERED_15 = [1, 2, 3, 5, 10, 25, 50, 100, 200, 300];
const PAGES_UNFILTERED_50 = [1, 2, 3, 5, 10, 25, 50, 100];
const PAGES_FILTERED = [1, 2, 3, 4, 5];
const PER_PAGE = [15, 50];
const SEARCH_TERMS = ['Headphones', 'Keyboard', 'Novel', 'Jacket', 'Premium'];

function productList(data) {
  const perPage = pick(PER_PAGE);
  const kind = rnd();
  let page;
  let qs;
  if (kind < 0.5) {
    // 50% unfiltered
    page = pick(perPage === 15 ? PAGES_UNFILTERED_15 : PAGES_UNFILTERED_50);
    qs = `page=${page}&per_page=${perPage}`;
  } else {
    page = pick(PAGES_FILTERED);
    qs = `page=${page}&per_page=${perPage}`;
    if (kind < 0.75) qs += `&category_id=${pick(data.categoryIds)}`; // 25% category
    else qs += `&search=${pick(SEARCH_TERMS)}`; // 25% search
  }
  const res = get(`/products?${qs}`, 'products_list');
  countNullStock(res);
  return res;
}

function productDetail() {
  const id = intBetween(DATASET.products.first_id, DATASET.products.last_id);
  const res = get(`/products/${id}`, 'product_detail');
  countNullStock(res);
  return res;
}

function customerOrders(me) {
  const heavy = me.lastPage > 3;
  const page = heavy ? intBetween(1, 3) : 1;
  return get(`/orders?page=${page}&per_page=15`, 'orders_customer_list', me.token);
}

function orderDetail(me) {
  return get(`/orders/${pick(me.orderIds)}`, 'order_detail', me.token);
}

function adminOrders(data) {
  const last = data.admin.lastPage;
  const k = rnd();
  let page;
  if (k < 0.4) page = intBetween(1, 5); // near the head
  else if (k < 0.7) page = intBetween(1, last); // anywhere
  else page = Math.max(1, last - intBetween(0, 20)); // far pages (large OFFSET)
  return get(`/orders?page=${page}&per_page=20`, 'orders_admin_list', data.admin.token);
}

// Weights: 30% product list, 20% product detail, 20% my orders, 15% my order detail, 15% admin orders.
export function iteration(data, check) {
  // Spread the VUs over the whole fixed set (10 heavy, 20 mid, 20 light), not only its first 20 entries.
  const idx = Math.min(data.customers.length - 1, Math.floor(((__VU - 1) * data.customers.length) / MAIN_VUS));
  const me = data.customers[idx];
  const r = rnd();
  let res;
  if (r < 0.3) res = productList(data);
  else if (r < 0.5) res = productDetail();
  else if (r < 0.7) res = customerOrders(me);
  else if (r < 0.85) res = orderDetail(me);
  else res = adminOrders(data);
  check(res, { 'status is 200': (x) => x.status === 200 });
}

// ---------- summary ----------
function fmt(n) {
  return n === undefined || n === null ? '-' : Number(n).toFixed(2);
}

function metricLine(label, m) {
  if (!m) return `  ${label.padEnd(24)} (no data)`;
  const v = m.values;
  return `  ${label.padEnd(24)} med=${fmt(v.med)}ms p95=${fmt(v['p(95)'])}ms p99=${fmt(v['p(99)'])}ms avg=${fmt(v.avg)}ms max=${fmt(v.max)}ms`;
}

export function textReport(data, routes, durationSeconds) {
  const M = data.metrics;
  const count = (name) => (M[name] ? M[name].values.count : 0);
  const mainReqs = count('http_reqs{phase:main}');
  const lines = [];
  lines.push(`LABEL=${__ENV.LABEL || '-'} RUN=${__ENV.RUN || '-'} VUs=${MAIN_VUS} duration=${durationSeconds}s`);
  lines.push('Counted requests only (tag phase:main). setup() and warm-up are excluded.');
  lines.push('');
  lines.push(metricLine('ALL (main)', M['http_req_duration{phase:main}']));
  lines.push(`  requests (main)          ${mainReqs}   req/s = ${(mainReqs / durationSeconds).toFixed(2)} (count / ${durationSeconds}s)`);
  // Bytes: never print 0. A missing or empty sub-metric is shown as n/a.
  const bytes = (name) => {
    const m = M[name];
    return m && m.values && m.values.count > 0 ? `${m.values.count} bytes` : 'n/a';
  };
  lines.push(`  data_received (main)     ${bytes('data_received{phase:main}')}   (k6 sub-metric, headers included)`);
  lines.push(`  response body (main)     ${bytes('response_body_bytes')}   (measured by the script, body only)`);
  lines.push(`  data_received (whole test, includes setup)  ${bytes('data_received')}`);
  lines.push('');
  routes.forEach((r) => {
    lines.push(metricLine(r, M[`http_req_duration{name:${r}}`]));
    lines.push(
      `    requests=${count(`http_reqs{name:${r}}`)} data_received=${bytes(`data_received{name:${r},phase:main}`)} body=${bytes(`response_body_bytes{name:${r}}`)}`
    );
  });
  lines.push('');
  const failed = M['http_req_failed{phase:main}'];
  lines.push(`  failed rate (main)       ${failed ? (failed.values.rate * 100).toFixed(3) : '-'} %`);
  lines.push(`  429 responses            ${count('rate_limited_429')}   (any value > 0 = INVALID run)`);
  lines.push(`  5xx responses            ${count('server_errors_5xx')}   (any value > 0 = INVALID run)`);
  lines.push(`  stock:null responses     ${count('stock_null_responses')}   (any value > 0 = INVALID run)`);
  const invalid = count('rate_limited_429') + count('server_errors_5xx') + count('stock_null_responses') > 0;
  lines.push(`  RUN VALID                ${invalid ? 'NO' : 'yes (also check failed rate above is 0)'}`);
  return lines.join('\n') + '\n';
}

export function summaryOutputs(data, routes, durationSeconds) {
  const label = __ENV.LABEL || 'unlabeled';
  const run = __ENV.RUN || '1';
  return {
    stdout: textReport(data, routes, durationSeconds),
    [`/perf/results/${label}-${run}.json`]: JSON.stringify(data, null, 2),
  };
}
