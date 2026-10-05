# Smartmailto — SDK para Laravel

`agavesoft/smartmailto` conecta una aplicacion Laravel (12 o 13) con Smartmailto: identifica personas (con o sin cuenta), registra eventos idempotentes, carga el historico y manda correos transaccionales.

> Antes `agavesoft/mailflow` (v1). La v2 cambia la API publica; ver [Migrar desde v1](#migrar-desde-v1).

## Instalacion

```bash
composer require agavesoft/smartmailto
php artisan vendor:publish --tag=smartmailto-config   # opcional
```

```dotenv
SMARTMAILTO_API_URL=https://smartmailto.example.com
SMARTMAILTO_API_TOKEN=mf_live_...        # token del proyecto (se muestra una sola vez en el panel)
SMARTMAILTO_ENABLED=true                 # false = el SDK no hace nada
# SMARTMAILTO_QUEUE=true                 # default: encola tras el commit y reintenta
# SMARTMAILTO_QUEUE_CONNECTION=
# SMARTMAILTO_QUEUE_NAME=
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

- Si no sale antes de `sendBefore`, Smartmailto no lo envia y el SDK dispara `SmartmailtoDeliveryFailed` (`status` 410): manda ese correo por tu cuenta. Al recuperarse, Smartmailto nunca lo envia tarde.
- `Smartmailto::health()` devuelve `status` (`ok`, `degraded`, `down`), el atraso de las colas y el estado del proveedor: consultalo en tu scheduler y, si no es `ok` por varios minutos, enciende tu bandera para mandar directo.

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

### Pruebas en tu app

```php
$fake = Smartmailto::fake();

// ... ejecuta tu codigo ...

$fake->assertTracked('orden_pagada', fn ($body) => $body['object']['id'] === '100');
$fake->assertSent('recibo-compra');
$fake->assertNotTracked('orden_creada');
```

## Contrato de eventos de Factura Facilita

Fuente de verdad: `notificabot-conocimiento/features/2026-10-F003-sdk-ingesta-robusta/dimensiones/integracion.md`.

| Evento | eventId | Identidad | object | properties | secrets |
|---|---|---|---|---|---|
| `orden_creada` | `ff:orden_creada:{order_id}` | guest(email) | order | timbres, total, paquete | checkout_url |
| `orden_pagada` | `ff:orden_pagada:{order_id}` | guest(email) o user(id, email) | order | timbres, total, paid_at | activation_url, activation_expires_at (invitado) |
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
