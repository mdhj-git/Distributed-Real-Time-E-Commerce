<?php

use App\Providers\AppServiceProvider;
use App\Providers\PerfQueryCounterServiceProvider;

return [
    AppServiceProvider::class,
    PerfQueryCounterServiceProvider::class, // TEMPORARY, Requirement 3 Step 1: remove at the end of the step
];
