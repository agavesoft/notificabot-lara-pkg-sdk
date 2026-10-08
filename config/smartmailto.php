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

        // F-010 (J12): con el outbox, un adjunto de mas de esto no va en el mensaje sino por URL firmada
        // (Attachment::fromDisk() o fromUrl()).
        'inline_max_bytes' => (int) env('SMARTMAILTO_ATTACHMENTS_INLINE_MAX_BYTES', 1024 * 1024),

        // F-010: tope por archivo de un adjunto por URL (MAILFLOW_ATTACHMENTS_URL_MAX_BYTES del servidor).
        'url_max_bytes' => (int) env('SMARTMAILTO_ATTACHMENTS_URL_MAX_BYTES', 15 * 1024 * 1024),

        // F-010: vigencia (minutos) de la URL firmada de Attachment::fromDisk(); se genera nueva en cada intento.
        'signed_url_ttl' => (int) env('SMARTMAILTO_ATTACHMENTS_SIGNED_URL_TTL', 1440),
    ],

    /*
     * F-010 (J1): outbox transaccional. Apagado por default (v2.x compatible). Prendido, track, send,
     * identify, link y reportExternalSend escriben una fila en la transaccion de tu app en vez de encolar
     * un job; `php artisan smartmailto:outbox:work` la entrega y la cierra solo con el acuse de Smartmailto.
     * Requiere publicar y correr la migracion (`--tag=smartmailto-migrations`) y un servidor con F-010.
     */
    'outbox' => [
        'enabled' => (bool) env('SMARTMAILTO_OUTBOX_ENABLED', false),

        // Conexion de BD de la tabla: la MISMA de tus transacciones de negocio (null = la default).
        'connection' => env('SMARTMAILTO_OUTBOX_CONNECTION'),

        'table' => 'smartmailto_outbox',
        'alert_table' => 'smartmailto_outbox_alert_state',

        // Espera entre intentos (segundos); despues del ultimo, cada `backoff` final (cada hora).
        'backoff' => [30, 120, 600, 3600],

        // Minutos sin acuse para rendirse: la fila queda `failed` (se reprocesa con smartmailto:outbox:retry).
        'give_up_after' => (int) env('SMARTMAILTO_OUTBOX_GIVE_UP_AFTER', 72 * 60),

        // Minutos que una fila puede quedar `sending` antes de volver a `pending` (el worker murio a medias).
        'sending_timeout' => 10,

        // Filas por pasada del worker.
        'batch_size' => 100,

        // Minutos entre avisos de vida a Smartmailto (POST /api/outbox/heartbeat).
        'heartbeat_every' => 5,

        // Alertas agrupadas (decision 2026-10-08 11:50): primera a los 15 min sin acuse, recordatorios
        // (minutos desde que empezo el atraso) y 422 agrupados por plantilla en ventanas de 15 min.
        'alert_after' => (int) env('SMARTMAILTO_OUTBOX_ALERT_AFTER', 15),
        'alert_steps' => [60, 240, 720, 1440, 2880],
        'reject_group_window' => 15,

        // Dias que se conservan las filas cerradas (smartmailto:outbox:prune).
        'retention' => [
            'acked_days' => 7,
            'failed_days' => 30,
        ],
    ],

    /*
     * F-010 (J2): alertas del outbox. Las emite TU app (si Smartmailto no responde, no puede avisar el):
     * correo y Teams (webhook de un flujo de Workflows de Power Automate).
     */
    'alerts' => [
        'mail_to' => env('SMARTMAILTO_ALERTS_MAIL_TO', 'soporte@agavesoft.com.mx'),
        'teams_webhook_url' => env('SMARTMAILTO_ALERTS_TEAMS_WEBHOOK_URL'),
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
