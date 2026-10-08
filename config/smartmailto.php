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

    /*
     * F-011: pull. Smartmailto le pide datos a tu app (carga inicial, reconciliacion tras caidas, datos
     * faltantes al enviar) en `POST {route.prefix}/smartmailto/pull`. Apagado por default: la ruta solo
     * existe con `enabled` y un resolver (clase que implementa Contracts\PullResolver). Ver README.
     */
    'pull' => [
        'enabled' => (bool) env('SMARTMAILTO_PULL_ENABLED', false),

        // Secreto propio del pull (`pullsec_...`, se muestra una vez en el panel). Firma peticiones y respuestas.
        'secret' => env('SMARTMAILTO_PULL_SECRET'),

        'resolver' => env('SMARTMAILTO_PULL_RESOLVER'),

        // Meses de historia de eventos que se mandan; null = toda. Smartmailto descarta lo que este fuera
        // de su retencion de eventos.
        'history_months' => env('SMARTMAILTO_PULL_HISTORY_MONTHS') !== null && env('SMARTMAILTO_PULL_HISTORY_MONTHS') !== ''
            ? (int) env('SMARTMAILTO_PULL_HISTORY_MONTHS')
            : null,

        // Llaves de atributos de contacto permitidas. null = el catalogo del proyecto en Smartmailto
        // (variables de contacto de F-009), en cache `catalog_ttl` segundos.
        'catalog' => null,
        'catalog_ttl' => (int) env('SMARTMAILTO_PULL_CATALOG_TTL', 300),

        // Contactos por pagina como maximo (Smartmailto pide 200) y tamano maximo de la respuesta.
        'max_limit' => 500,
        'max_response_bytes' => 5_000_000,

        // Ventana de la firma (segundos) y cache para recordar los X-Smartmailto-Request ya vistos.
        'tolerance' => 300,
        'cache_store' => env('SMARTMAILTO_PULL_CACHE_STORE'),

        'route' => [
            'prefix' => env('SMARTMAILTO_PULL_ROUTE_PREFIX', 'api'),
            'name' => 'smartmailto.pull',
            'throttle' => '120,1',
            // Middleware extra de tu app (por ejemplo un interruptor propio).
            'middleware' => [],
        ],
    ],
];
