<?php

return [
    'api_url'   => env('MAILFLOW_API_URL'),
    'api_token' => env('MAILFLOW_API_TOKEN'),

    /*
     * Queue connection to use for async Mailflow jobs.
     * Set to 'sync' to execute immediately without a queue worker.
     * Defaults to the application's default queue connection if null.
     */
    'queue_connection' => env('MAILFLOW_QUEUE_CONNECTION'),

    /*
     * Queue name to use for async Mailflow jobs.
     * Defaults to the application's default queue if null.
     */
    'queue_name' => env('MAILFLOW_QUEUE_NAME'),
];
