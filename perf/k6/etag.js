// ETag scenario (Requirement 3). Written in Step 0, NOT run until the ETag work (Step 5) arrives.
// Each VU keeps the last ETag it saw per product and sends If-None-Match, like a browser would.
// Works without ETag support too: no ETag header means plain 200 responses and no 304s.
// Run: docker compose --profile perf run --rm -e LABEL=etag-off -e RUN=1 k6 run /perf/k6/etag.js
import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import {
  BASE_URL,
  DATASET,
  MAIN_DURATION_SECONDS,
  mainOptions,
  intBetween,
  summaryOutputs,
  rateLimited429,
  recordBody,
  serverErrors5xx,
  stockNullResponses,
} from './lib.js';

const ROUTE = 'product_detail_etag';
const HOT_PRODUCTS = 200; // fixed hot set so the same product is requested repeatedly

const responses304 = new Counter('responses_304');
const responses200 = new Counter('responses_200');
const etagHeaderSeen = new Counter('etag_header_seen');

export const options = mainOptions([ROUTE], 'etag');
options.thresholds['responses_304'] = ['count>=0'];
options.thresholds['responses_200'] = ['count>=0'];

const lastEtag = {}; // per VU (module state is per VU)

export default function () {
  const id = DATASET.products.first_id + intBetween(0, HOT_PRODUCTS - 1);
  const headers = { Accept: 'application/json' };
  if (lastEtag[id]) headers['If-None-Match'] = lastEtag[id];

  const res = http.get(`${BASE_URL}/products/${id}`, {
    headers,
    tags: { name: ROUTE, phase: 'main' },
    responseCallback: http.expectedStatuses(200, 304),
  });

  if (res.status === 429) rateLimited429.add(1);
  if (res.status >= 500) serverErrors5xx.add(1);
  recordBody(res, ROUTE);

  if (res.status === 304) {
    responses304.add(1);
  } else if (res.status === 200) {
    responses200.add(1);
    const etag = res.headers['Etag'] || res.headers['ETag'];
    if (etag) {
      lastEtag[id] = etag;
      etagHeaderSeen.add(1);
    }
    try {
      const d = res.json().data;
      if (d && d.stock === null) stockNullResponses.add(1);
    } catch (e) {
      // body not JSON: ignored, the status check below reports it
    }
  }

  check(res, { 'status is 200 or 304': (r) => r.status === 200 || r.status === 304 });
}

export function handleSummary(data) {
  const out = summaryOutputs(data, [ROUTE], MAIN_DURATION_SECONDS);
  const c = (n) => (data.metrics[n] ? data.metrics[n].values.count : 0);
  out.stdout += `  200 responses            ${c('responses_200')}\n  304 responses            ${c('responses_304')}\n  ETag headers seen        ${c('etag_header_seen')}\n`;
  return out;
}
