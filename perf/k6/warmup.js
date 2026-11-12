// Warm-up: same scenario as baseline.js, shorter. Its numbers are NEVER recorded.
// Run: docker compose --profile perf run --rm -e LABEL=warmup -e RUN=0 k6 run /perf/k6/warmup.js
import { check } from 'k6';
import { ROUTES, WARMUP_VUS, WARMUP_DURATION_SECONDS, buildSetup, iteration } from './lib.js';

export const options = {
  scenarios: {
    warmup: { executor: 'constant-vus', vus: WARMUP_VUS, duration: `${WARMUP_DURATION_SECONDS}s` },
  },
};

export function setup() {
  return buildSetup();
}

export default function (data) {
  iteration(data, check);
}

export function handleSummary() {
  // No JSON file, no numbers: warm-up results must not be recorded.
  return { stdout: `warm-up finished (${ROUTES.length} routes, ${WARMUP_VUS} VUs, ${WARMUP_DURATION_SECONDS}s). Numbers are not counted.\n` };
}
