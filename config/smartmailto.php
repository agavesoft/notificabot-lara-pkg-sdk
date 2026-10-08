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

    /*
     * F-009: espera de las llamadas de aprovisionamiento y catalogo (sincronas). Un paquete completo
     * corre en una transaccion del servidor, que espera hasta 10 s por el candado del proyecto.
     */
    'provision_timeout' => (int) env('SMARTMAILTO_PROVISION_TIMEOUT', 120),

    // Espera entre reintentos (segundos) y ventana maxima de reintento (horas).
    'backoff' => [30, 120, 600, 3600],

    'retry_hours' => 24,

    /*
     * F-008 (B3): limites de adjuntos de send(), validados antes de encolar. Deben coincidir con los del
     * servidor (MAILFLOW_ATTACHMENTS_MAX_FILES / MAILFLOW_ATTACHMENTS_MAX_BYTES; default 10 y 7 MB
     * decodificados). Con cola, el job lleva el base64 (~1.37x): ver README.
     */
    'attachments' => [
        'max_files' => (int) env('SMARTMAILTO_ATTACHMENTS_MAX_FILES', 10),
        'max_bytes' => (int) env('SMARTMAILTO_ATTACHMENTS_MAX_BYTES', 7 * 1024 * 1024),
    ],

    /*
     * F-008 (B2): secreto del webhook de falla (`whsec_...`, se muestra una vez en el panel del proyecto).
     * Lo usan Smartmailto::verifyWebhook() y el middleware `smartmailto.webhook`.
     */
    'webhook_secret' => env('SMARTMAILTO_WEBHOOK_SECRET'),
];
