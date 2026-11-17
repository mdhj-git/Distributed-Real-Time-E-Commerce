<?php

return [

    /*
    | Read replica switches (Requirement 3, Step 6). Read here, never with env() elsewhere, so config:cache stays safe.
    | The replica itself is chosen by DB_READ_HOST in config/database.php (empty = no replica).
    */

    'read_your_writes' => [
        // After a request modified data, that user's reads go to the primary for a short time (see ReadRouting).
        // false = no marker is read or written. Writes (POST, PUT, PATCH, DELETE) and the catalog rule still read the primary.
        'enabled' => filter_var(env('READ_YOUR_WRITES_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // How long the marker lives. It must stay longer than the replica lag.
        'ttl_seconds' => (int) env('READ_YOUR_WRITES_TTL_SECONDS', 30),
    ],

    // true = category and product catalog reads (the routes with the "reads.primary" alias) use the primary.
    // false exists only to show the stale create-then-read case in perf:freshness-check. Keep true.
    'catalog_reads_on_primary' => filter_var(env('CATALOG_READS_ON_PRIMARY', true), FILTER_VALIDATE_BOOLEAN),

];
