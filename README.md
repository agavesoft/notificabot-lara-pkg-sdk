# Smartmailto — SDK para Laravel

`agavesoft/smartmailto` conecta una aplicacion Laravel (12 o 13) con Smartmailto: identifica personas (con o sin cuenta), registra eventos idempotentes, carga el historico y manda correos transaccionales.

> Antes `agavesoft/mailflow` (v1). La v2 cambia la API publica; ver [Migrar desde v1](#migrar-desde-v1).

## Instalacion

```bash
composer require agavesoft/smartmailto
php artisan vendor:publish --tag=smartmailto-config   # opcional
```

Antes de publicar una version, `develop` se puede requerir desde el repositorio como `2.2.x-dev` (`"agavesoft/smartmailto": "^2.2@dev"` con un repositorio `vcs` a `https://github.com/agavesoft/notificabot-lara-pkg-sdk`).

```dotenv
SMARTMAILTO_API_URL=https://smartmailto.example.com
SMARTMAILTO_API_TOKEN=mf_live_...        # token del proyecto (se muestra una sola vez en el panel)
SMARTMAILTO_ENABLED=true                 # false = el SDK no hace nada
# SMARTMAILTO_QUEUE=true                 # default: encola tras el commit y reintenta
# SMARTMAILTO_QUEUE_CONNECTION=
# SMARTMAILTO_QUEUE_NAME=
# SMARTMAILTO_WEBHOOK_SECRET=whsec_...   # webhook de falla (F-008)
# SMARTMAILTO_ATTACHMENTS_MAX_FILES=10
# SMARTMAILTO_ATTACHMENTS_MAX_BYTES=7340032
```

Por default **cada llamada se encola despues del commit** de la transaccion en curso: necesitas un worker de cola corriendo (`php artisan queue:work`). Con la cola `sync` la llamada se hace una sola vez al terminar el commit, sin reintentos; una falla dispara `SmartmailtoDeliveryFailed`. Para reintentos reales usa una cola de verdad (`database`, `redis`). Con `SMARTMAILTO_QUEUE=false` las llamadas son sincronas y lanzan `SmartmailtoException` si fallan.

## Uso

```php
use Agavesoft\Smartmailto\Facades\Smartmailto;
use Agavesoft\Smartmailto\Identity;

// Persona sin cuenta (compra como invitado): se identifica por correo.
Smartmailto::track('orden_creada', Identity::guest($order->email), [
    'timbres' => $order->stamps, 'total' => $order->total, 'paquete' => $order->package_name,
], eventId: "ff:orden_creada:{$order->id}", object: ['order', $order->id],
   secrets: ['checkout_url' => $checkoutUrl]);

// Persona con cuenta. Mandar su correo une su historial de invitado con la cuenta.
Smartmailto::identify(Identity::user($user->id, $user->email), ['name' => $user->name]);

// Correo transaccional inmediato (idempotente).
Smartmailto::send('recibo-compra', Identity::user($user->id, $user->email), [
    'total' => $order->total,
], idempotencyKey: "ff:recibo:{$order->id}");
```

### Reglas del contrato

1. **`eventId` sale del hecho de negocio**, nunca de un uuid nuevo: `"{app}:{evento}:{id del objeto}"`. Asi un reintento (tuyo o del SDK) no duplica el evento: Smartmailto descarta el repetido.
2. **`idempotencyKey` en cada `send`**: el mismo valor nunca manda dos correos.
3. **`secrets`** son valores de un solo uso (ligas de activacion o de pago). Smartmailto los guarda cifrados, no los muestra en su panel ni en logs, y solo las plantillas los leen (`{{secret:activation_url}}`). Esas ligas no pasan por el seguimiento de clics.
4. **Nunca mandes datos fiscales** (RFC, contenido de CFDI) ni datos que el correo no necesita.
5. **`object`** (`['order', 100]`) identifica el objeto de negocio; Smartmailto lo usa para llevar una secuencia por orden.
6. **Unir invitado y cuenta**: cuando la persona reclama su orden o se registra, manda un evento o `identify` con `Identity::user($id, $correoDeLaOrden)`. Si tu app borra el correo de la orden al reclamarla, leelo antes.

### Correos esenciales y emergencia

Para registro y recibo de compra (plantillas transaccionales en Smartmailto):

```php
Smartmailto::send('recibo-compra', Identity::user($user->id, $user->email), $data,
    idempotencyKey: "ff:recibo:{$order->id}", sendBefore: now()->addMinutes(10));
```

- Si la peticion llega despues de `sendBefore` (Smartmailto caido o tu cola atrasada), Smartmailto responde 410 y el SDK dispara `SmartmailtoDeliveryFailed`: manda ese correo por tu cuenta.
- Si Smartmailto ya lo habia aceptado (202) pero no pudo enviarlo antes de `sendBefore` (por ejemplo, su cola esta detenida), **el SDK no se entera**: Smartmailto avisa por el **webhook de fallas del proyecto** (`failure_webhook_url`, se configura en el panel) con `{"event": "send_expired", "idempotency_key": "...", "send_id": ...}`. Tu app debe recibir ese webhook y mandar directo el correo de esa `idempotency_key`. Es parte obligatoria del contrato de emergencia.
- En ambos casos Smartmailto nunca lo envia tarde.
- `Smartmailto::health()` devuelve `status` (`ok`, `degraded`, `down`), el atraso de las colas y el estado del proveedor: consultalo en tu scheduler y, si no es `ok` por varios minutos, enciende tu bandera para mandar directo.

### Adjuntos, copias y remitente (F-008)

Todo es opcional y con nombre: un `send()` sin estos parametros manda exactamente lo mismo que antes.

```php
use Agavesoft\Smartmailto\Attachment;

// CFDI por correo: PDF + XML, varios destinatarios, copia oculta y Reply-To de soporte.
Smartmailto::send('cfdi-por-correo', Identity::user($user->id, $user->email), [
    'folio' => $cfdi->folio, 'total' => $cfdi->total,
], idempotencyKey: "ff:cfdi-correo:{$cfdi->id}:{$request->id}",
    attachments: [
        Attachment::fromPath(storage_path("cfdi/{$cfdi->uuid}.pdf"), "{$cfdi->rfc}_{$cfdi->folio}.pdf"),
        Attachment::fromData($cfdi->xml, "{$cfdi->rfc}_{$cfdi->folio}.xml"),   // contenido crudo, no base64
    ],
    to: ['contador@example.com'],                 // destinatarios ADICIONALES (el contacto siempre va)
    cc: ['administracion@example.com'],
    bcc: ['auditoria@facturafacilita.com'],
    replyTo: ['email' => 'soporte@facturafacilita.com', 'name' => 'Soporte'],
);

// Recibo de compra con el PDF y otro remitente del mismo dominio.
Smartmailto::send('recibo-compra', Identity::user($user->id, $user->email), $data,
    idempotencyKey: "ff:recibo:{$order->id}", sendBefore: now()->addMinutes(10),
    attachments: [Attachment::fromData($pdf, 'Comprobante.pdf')],
    from: ['email' => 'soporte@facturafacilita.com', 'name' => 'Factura Facilita'],
);

// Restablecer contrasena: la liga de un solo uso viaja en secrets ({{secret:reset_url}}), no en data.
Smartmailto::send('restablecer-contrasena', Identity::user($user->id, $user->email), [],
    idempotencyKey: "ff:reset:{$token->id}", secrets: ['reset_url' => $url]);
```

- **Adjuntos:** `Attachment::fromPath($ruta, $nombre?)`, `Attachment::fromData($bytes, $nombre)` o `Attachment::fromUpload($request->file('x'))`; tambien se acepta directo un `UploadedFile` o una ruta. El SDK los codifica en base64. El tipo sale de la extension (`pdf`, `xml`, `zip`, `png`, `jpg`/`jpeg`, `csv`, `txt`) o del `contentType` que pases si corresponde a ella. Nombre simple, max 150, sin rutas.
- **Limites:** 10 archivos y **7 MB decodificados en total** por envio. El SDK los valida **antes de encolar** (lanza `InvalidArgumentException`); si el servidor tiene otros, ajusta `SMARTMAILTO_ATTACHMENTS_MAX_FILES` / `SMARTMAILTO_ATTACHMENTS_MAX_BYTES`. El servidor ademas revisa la firma del archivo (un `.pdf` debe ser PDF).
- **Adjuntos y cola:** el contenido se lee al llamar `send()` y el job lleva el base64 (~1.37x el tamano de los archivos): no depende de que el archivo siga en disco cuando corre el worker. Consecuencias: (1) el payload del job puede pesar ~10 MB y queda **sin cifrar** en tu almacen de cola (`jobs` de la cola `database`, Redis); (2) **SQS no sirve** para envios con adjuntos (limite de 256 KB por mensaje): usa `database`/`redis` o `SMARTMAILTO_QUEUE=false` para esos envios. `Smartmailto::fake()` tambien guarda el base64.
- **`to`, `cc`, `bcc`:** max 10 por lista; solo con plantillas `kind: transactional` (si no: 422 `recipients_require_transactional`). No crean contactos y la lista de supresion del proyecto les aplica.
- **`replyTo` / `from`:** un correo o `['email' => ..., 'name' => ...]`. `from` solo del dominio del From del proyecto (422 `from_not_allowed`).
- Contrato completo: `docs/api-envio-y-aprovisionamiento.md` del servidor (`notificabot-lara-mailflow`).

### Plantillas como codigo (aprovisionamiento, F-008)

Un admin debe habilitar *Aprovisionamiento por API* en el proyecto (apagado: 403 `provisioning_disabled`). Cada `PUT` es idempotente: lo que no cambio responde `unchanged` y no crea version.

```text
resources/smartmailto/
├── partials/pie.html                   # {{> pie}}; frontmatter opcional: description
├── templates/recibo-compra.md          # frontmatter: subject (requerido), kind, layout, display_name, description
└── workflows/checkout.yaml             # o .json
```

```markdown
---
subject: Tu recibo {{data:folio}}
kind: transactional
layout: default
display_name: Recibo de compra
---
<p>Hola {{contact:name}}</p>
{{> pie}}
```

```bash
php artisan smartmailto:provision resources/smartmailto --dry-run   # valida local, no llama
php artisan smartmailto:provision resources/smartmailto             # bloques -> plantillas -> workflows
```

- El cuerpo va tal cual (metalenguaje de Smartmailto; **no se convierte Markdown**: `.md` solo es el formato del archivo). Frontmatter plano `llave: valor`. El nombre es el del archivo (`^[a-z0-9][a-z0-9_-]*$`).
- Se detiene en el primer rechazo (lo siguiente puede depender de el) y sale con codigo 1. Correrlo en cada deploy es seguro.
- **Los workflows nunca quedan activos por API**: se crean inactivos y, si su definicion cambia, quedan inactivos aunque estuvieran activos. El comando lo avisa; un admin los activa en el panel.
- Sin `layout` en el frontmatter el servidor conserva el actual; sin `kind`, una plantilla nueva nace `marketing`.

Desde codigo: `Smartmailto::putPartial($name, $body)`, `Smartmailto::putTemplate($name, $subject, $body, layout:, kind:, displayName:, description:)`, `Smartmailto::putWorkflow($name, $yamlOArreglo)` y `Smartmailto::templates()` (con `checksum` = sha256 de `json_encode([subject, body, layout, kind])`). Son sincronos y lanzan `SmartmailtoException` si el servidor rechaza.

### Webhook de falla (F-008)

Cuando un envio aceptado vence (`send_before`) o falla, Smartmailto avisa por el webhook de falla del proyecto (panel → engrane → *Webhook de falla*). El secreto `whsec_...` se muestra una vez:

```dotenv
SMARTMAILTO_WEBHOOK_SECRET=whsec_...
```

```php
// routes/api.php (fuera de CSRF)
Route::post('/smartmailto/webhook', SmartmailtoWebhookController::class)->middleware('smartmailto.webhook');

// o a mano:
if (! Smartmailto::verifyWebhook($request)) {   // usa smartmailto.webhook_secret; o verifyWebhook($request, $secret, 300)
    abort(401);
}

$event = $request->json()->all();               // version, event, delivery_id, send_id, idempotency_key, template, reason
if ($event['event'] === 'send_expired') {
    // manda directo el correo de $event['idempotency_key'] (idempotente por delivery_id)
}
```

- Verifica `X-Smartmailto-Signature` (`sha256=` + HMAC-SHA256 de `"{timestamp}.{cuerpo crudo}"`) en tiempo constante y rechaza un `X-Smartmailto-Timestamp` a mas de 5 minutos (en cualquier sentido) contra replay. Cada reintento llega con timestamp nuevo.
- El middleware responde 401 si la firma no vale (Smartmailto no reintenta un 4xx) y **500 si falta `SMARTMAILTO_WEBHOOK_SECRET`** (error de configuracion de tu app: con 5xx Smartmailto reintenta ~10 h y el aviso no se pierde). `smartmailto.webhook:600` cambia la ventana.
- Responde rapido (2xx) y procesa en cola.

### Carga inicial

```php
$batch = Smartmailto::batch(backfill: true);   // no dispara correos

Order::query()->paid()->unclaimed()->each(fn ($order) => $batch->track(
    'orden_pagada', Identity::guest($order->email), ['timbres' => $order->stamps],
    eventId: "ff:orden_pagada:{$order->id}", object: ['order', $order->id], occurredAt: $order->paid_at,
));

$batch->dispatch();   // se parte en lotes de 100
```

Volver a correr la carga es seguro: los `eventId` repetidos se descartan.

### Fallas

- Se reintenta solo lo transitorio (red, 5xx, 429 respetando `Retry-After`) con espera de 30 s, 2 min, 10 min y despues cada hora, hasta 24 h.
- Un rechazo (422 por datos invalidos, 401 por token) no se reintenta.
- En ambos casos, al rendirse se dispara `Agavesoft\Smartmailto\Events\SmartmailtoDeliveryFailed` (`endpoint`, `key` = event_id o idempotency key, `status`, `error`). Escuchalo para registrar o alertar. En un lote, cada item invalido dispara su propio evento.

### Privacidad (consulta y borrado)

Smartmailto guarda correos y atributos cifrados con una llave por proyecto. Para atender a una persona:

```php
Smartmailto::contact(Identity::user($user->id));        // correo, atributos, eventos y envios (o null)
Smartmailto::renderedEmail($sendId);                     // el correo enviado, re-generado sin ligas de un solo uso
Smartmailto::forget(Identity::guest('ana@example.com')); // borrado ARCO; true si existia
```

Cada consulta queda registrada en la bitacora de acceso del proyecto.

### Pruebas en tu app

```php
$fake = Smartmailto::fake();

// ... ejecuta tu codigo ...

$fake->assertTracked('orden_pagada', fn ($body) => $body['object']['id'] === '100');
$fake->assertSent('recibo-compra');
$fake->assertNotTracked('orden_creada');
$fake->assertSent('cfdi-por-correo', fn ($body) => count($body['attachments']) === 2);
$fake->assertProvisioned('templates', 'recibo-compra');
```

## Contrato de eventos de Factura Facilita

Fuente de verdad: `notificabot-conocimiento/features/2026-10-F003-sdk-ingesta-robusta/dimensiones/integracion.md`.

| Evento | eventId | Identidad | object | properties | secrets |
|---|---|---|---|---|---|
| `orden_creada` | `ff:orden_creada:{order_id}` | guest(email) | order | timbres, total, paquete | checkout_url |
| `orden_pagada` | `ff:orden_pagada:{order_id}` | guest(email) o user(id, email) | order | invitado (bool), timbres, total, paid_at | activation_url, activation_expires_at (invitado) |
| `orden_reclamada` | `ff:orden_reclamada:{order_id}` | user(id, correo de la orden) | order | via | — |
| `cuenta_creada` | `ff:cuenta_creada:{user_id}` | user(id, email) | — | name | — |
| `sesion_iniciada` | `ff:sesion_iniciada:{user_id}:{fecha}` | user(id) | — | — | — |
| `timbres_asignados` | `ff:timbres_asignados:{package_account_id}` | user(id) | order? | disponibles, origen | — |
| `empresa_creada` | `ff:empresa_creada:{company_id}` | user(id) | company | es_primera | — |
| `cfdi_timbrado` | `ff:cfdi_timbrado:{cfdi_id}` | user(id) | cfdi | es_primero | — |

## Migrar desde v1

| v1 (`agavesoft/mailflow`) | v2 (`agavesoft/smartmailto`) |
|---|---|
| `Mailflow::identify($userId, $attrs, async: true)` | `Smartmailto::identify(Identity::user($userId, $email), $attrs)` |
| `Mailflow::track($userId, $event, $props, async: true)` | `Smartmailto::track($event, Identity::user($userId), $props, eventId: '...')` |
| `Mailflow::send($userId, $slug, $data)` | `Smartmailto::send($slug, Identity::user($userId, $email), $data, idempotencyKey: '...')` |
| `MAILFLOW_API_URL`, `MAILFLOW_API_TOKEN` | `SMARTMAILTO_API_URL`, `SMARTMAILTO_API_TOKEN` |
| errores ignorados en silencio | reintentos reales + `SmartmailtoDeliveryFailed` |

## Licencia

MIT.
