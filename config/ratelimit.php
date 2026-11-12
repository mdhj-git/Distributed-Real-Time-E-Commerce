<?php

return [

    /*
    | Requests per minute per IP for the global "api" limiter (see SharedServiceProvider).
    | The default stays 60. It is raised only temporarily while measuring (see perf/MEASURE.md).
    */
    'api_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 60),

];