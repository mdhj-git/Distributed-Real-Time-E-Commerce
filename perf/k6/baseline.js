// Fixed read scenario for Requirement 3. 20 VUs, 60 s, no think time.
// Run: docker compose --profile perf run --rm -e LABEL=baseline -e RUN=1 k6 run /perf/k6/baseline.js
import { check } from 'k6';
import {
  ROUTES,
  MAIN_DURATION_SECONDS,
  mainOptions,
  buildSetup,
  iteration,
  summaryOutputs,
} from './lib.js';

export const options = mainOptions(ROUTES, 'main');

export function setup() {
  return buildSetup();
}

export default function (data) {
  iteration(data, check);
}

export function handleSummary(data) {
  return summaryOutputs(data, ROUTES, MAIN_DURATION_SECONDS);
}
