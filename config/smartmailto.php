<?php

return [

    /*
     * Interruptor general. En false el SDK no hace nada (ni construye el cliente): util para
     * ambientes sin credenciales o para apagar la integracion sin tocar codigo.
     */
    'enabled' => (bool) env('SMARTMAILTO_ENABLED', true),

    'api_url' => env('SMARTMAILTO_API_URL'),

    'api_token' => env('SMARTMAILTO_API_TOKEN'),

    /*
     * Por default cada llamada se encola (afterCommit) y se reintenta si Smartmailto no responde.
     * En false las llamadas son sincronas y lanzan excepcion si fallan.
     */
    'queue' => (bool) env('SMARTMAILTO_QUEUE', true),

    'queue_connection' => env('SMARTMAILTO_QUEUE_CONNECTION'),

    'queue_name' => env('SMARTMAILTO_QUEUE_NAME'),

    // Segundos de espera por peticion.
    'timeout' => (int) env('SMARTMAILTO_TIMEOUT', 10),

    // Espera entre reintentos (segundos) y ventana maxima de reintento (horas).
    'backoff' => [30, 120, 600, 3600],

    'retry_hours' => 24,
];
