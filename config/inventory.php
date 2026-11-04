<?php

return [
    /*
     * The inventory gRPC service. This is the only place that reads the
     * INVENTORY_* variables; every other class goes through config().
     * The timeout is in milliseconds and is applied to every single call.
     */
    'grpc' => [
        'host' => env('INVENTORY_GRPC_HOST', 'inventory'),
        'port' => (int) env('INVENTORY_GRPC_PORT', 50051),
        'timeout_ms' => (int) env('INVENTORY_GRPC_TIMEOUT_MS', 2000),
    ],
];
