# Integración del SDK de Mailflow en un proyecto Laravel cliente

Este documento explica cómo instalar, configurar y usar el paquete `agavesoft/mailflow` en cualquier proyecto Laravel que necesite enviar eventos y correos a través de Mailflow.

---

## Requisitos

- PHP >= 8.1
- Laravel 10, 11 o 12
- Acceso al repositorio de Mailflow (para el path repository local) o conexión al servidor de Mailflow
- Variables de entorno: `MAILFLOW_API_URL` y `MAILFLOW_API_TOKEN`

---

## Instalación

### 1. Registrar el repositorio local en `composer.json`

El paquete vive dentro del repositorio de Mailflow. Añade la referencia en la sección `repositories` de tu `composer.json`:

```json
"repositories": {
    "mailflow-sdk": {
        "type": "path",
        "url": "../mailflow/packages/mailflow-sdk"
    }
}
```

> Ajusta la ruta `../mailflow/packages/mailflow-sdk` según la ubicación relativa del repositorio de Mailflow respecto a tu proyecto.

### 2. Instalar el paquete

```bash
composer require agavesoft/mailflow:*
```

### 3. Publicar la configuración (opcional)

```bash
php artisan vendor:publish --tag=mailflow-config
```

Esto crea el archivo `config/mailflow.php` en tu proyecto. Si no publicas la config, el paquete usa directamente las variables de entorno.

### 4. Configurar las variables de entorno

En tu archivo `.env`:

```env
MAILFLOW_API_URL=http://mailflow.test
MAILFLOW_API_TOKEN=tu_token_aqui
```

El token se obtiene desde el panel de Mailflow en **Configuración → Proyecto → API Token**.

---

## Auto-discovery

El paquete se registra automáticamente gracias al auto-discovery de Laravel. No necesitas añadir nada en `config/app.php`.

Lo que se registra automáticamente:
- **ServiceProvider:** `Agavesoft\Mailflow\MailflowServiceProvider`
- **Facade alias:** `Mailflow` → `Agavesoft\Mailflow\MailflowFacade`

---

## Uso básico

Puedes usar la facade `Mailflow::` en cualquier parte del proyecto.

### Identify — Crear o actualizar un contacto

```php
use Agavesoft\Mailflow\MailflowFacade as Mailflow;

// Síncrono
Mailflow::identify((string) $user->id, [
    'email'      => $user->email,
    'name'       => $user->name,
    'created_at' => $user->created_at->toIso8601String(),
]);

// Asíncrono (vía queue)
Mailflow::identify((string) $user->id, ['email' => $user->email], async: true);
```

> El primer parámetro es siempre el `user_id` de tu sistema (identificador externo). El email es obligatorio para contactos nuevos.

### Track — Registrar un evento

```php
// Síncrono — retorna ['id' => 123, 'message' => '...']
$response = Mailflow::track((string) $user->id, 'purchase_completed', [
    'plan'   => 'pro',
    'amount' => 49,
]);

$eventId = $response['id'] ?? null; // Guarda este ID para consultar el status

// Asíncrono
Mailflow::track((string) $user->id, 'purchase_completed', ['plan' => 'pro'], async: true);
```

### Send — Enviar un correo transaccional

```php
// Síncrono — retorna ['id' => $sendId]
Mailflow::send((string) $user->id, 'welcome-email', [
    'first_name'       => $user->name,
    'verification_url' => $verificationUrl,
]);

// Asíncrono
Mailflow::send((string) $user->id, 'bienvenida', ['name' => $user->name], async: true);
```

> El segundo parámetro es el **slug** del template configurado en el panel de Mailflow.

### EventStatus — Consultar el estado de un evento

Disponible solo en modo síncrono (requiere el ID del evento).

```php
$status = Mailflow::eventStatus(123);

// Respuesta:
// [
//   'id'                  => 123,
//   'event'               => 'purchase_completed',
//   'user_id'             => 'ext-456',
//   'properties'          => ['plan' => 'pro'],
//   'received_at'         => '2026-03-26T10:00:00Z',
//   'workflows_triggered' => [
//     ['workflow_id' => 1, 'workflow_name' => 'Post Purchase', 'status' => 'active', ...]
//   ]
// ]
```

### isConfigured — Verificar si el SDK está configurado

```php
if (Mailflow::isConfigured()) {
    Mailflow::send(...);
} else {
    // Fallback a notificación estándar de Laravel
    $user->notify(new CustomVerifyEmail);
}
```

---

## Modo asíncrono (queue)

Cuando usas `async: true`, el SDK despacha un `MailflowDispatchJob` a la cola de Laravel en lugar de hacer la llamada HTTP de forma síncrona. Esto es recomendable para eventos que no necesitan respuesta inmediata (la mayoría).

**Requisitos:**
- Cola configurada en Laravel (`QUEUE_CONNECTION` en `.env`)
- Worker corriendo: `php artisan queue:work`

El job tiene **3 reintentos** con **30 segundos de backoff** en caso de fallo.

---

## Integración con eventos de Laravel (patrón recomendado)

El patrón más limpio es usar un **Event Subscriber** que escuche los eventos de tu aplicación y los envíe a Mailflow automáticamente.

### 1. Crear el Subscriber

```php
// app/Listeners/MailflowSubscriber.php

namespace App\Listeners;

use Agavesoft\Mailflow\MailflowFacade as Mailflow;
use Illuminate\Auth\Events\Registered;
use Illuminate\Events\Dispatcher;

class MailflowSubscriber
{
    public function handleUserRegistered(Registered $event): void
    {
        Mailflow::identify((string) $event->user->id, [
            'email'      => $event->user->email,
            'name'       => $event->user->name,
            'created_at' => $event->user->created_at->toIso8601String(),
        ], async: true);
    }

    public function handleUserLoggedIn(UserLoggedIn $event): void
    {
        Mailflow::track((string) $event->user->id, 'login', [
            'email' => $event->user->email,
        ], async: true);
    }

    // Añade más handlers según los eventos de tu app...

    public function subscribe(Dispatcher $events): array
    {
        return [
            Registered::class   => 'handleUserRegistered',
            UserLoggedIn::class => 'handleUserLoggedIn',
        ];
    }
}
```

### 2. Registrar el Subscriber en EventServiceProvider

```php
// app/Providers/EventServiceProvider.php

protected $subscribe = [
    MailflowSubscriber::class,
];
```

---

## Configuración del webhook de errores (opcional)

Mailflow puede notificar a tu proyecto cuando un job falla definitivamente (después de 3 reintentos). Para activarlo, configura la URL de callback en el panel de Mailflow:

**Panel → Proyecto → Configuración → Webhook de errores**

O directamente en la base de datos del proyecto, campo `failure_webhook_url`.

### Payload que recibirás en tu endpoint

**Fallo en envío de correo (`SendEmailJob`):**
```json
{
  "event":       "job_failed",
  "job":         "SendEmailJob",
  "send_id":     123,
  "contact_id":  456,
  "template_id": 789,
  "error":       "Connection refused",
  "failed_at":   "2026-03-26T10:00:00Z"
}
```

**Fallo en ejecución de workflow (`ExecuteWorkflowStepJob`):**
```json
{
  "event":               "job_failed",
  "job":                 "ExecuteWorkflowStepJob",
  "workflow_contact_id": 123,
  "workflow_id":         1,
  "contact_id":          456,
  "step_index":          2,
  "error":               "Template not found",
  "failed_at":           "2026-03-26T10:00:00Z"
}
```

### Ejemplo de endpoint receptor en tu proyecto

```php
// routes/api.php
Route::post('/mailflow/webhook', [MailflowWebhookController::class, 'handle']);

// app/Http/Controllers/MailflowWebhookController.php
public function handle(Request $request): JsonResponse
{
    $data = $request->all();

    if ($data['event'] === 'job_failed') {
        Log::error('Mailflow job failed', $data);
        // Notificar al equipo, crear alerta, etc.
    }

    return response()->json(['ok' => true]);
}
```

---

## Trazabilidad: flujo completo con el ID del evento

```php
// 1. Enviar evento y capturar ID
$response = Mailflow::track((string) $user->id, 'purchase_completed', [
    'plan'   => 'pro',
    'amount' => 49,
]);

$eventId = $response['id']; // ← guardar en tu base de datos si necesitas rastrear

// 2. Consultar el estado (en otro request, worker, etc.)
$status = Mailflow::eventStatus($eventId);

// $status['workflows_triggered'] muestra qué workflows se activaron y su estado actual:
// active | completed | error | exited
foreach ($status['workflows_triggered'] as $wf) {
    echo "{$wf['workflow_name']}: {$wf['status']}";
}
```

---

## Resumen de la API del SDK

| Método | Descripción | Soporta `async` |
|---|---|---|
| `identify($userId, $attrs, $async)` | Crea/actualiza contacto | Sí |
| `track($userId, $event, $props, $async)` | Registra un evento | Sí |
| `send($userId, $templateSlug, $data, $async)` | Envía correo transaccional | Sí |
| `eventStatus($eventId)` | Consulta estado de un evento | No |
| `isConfigured()` | Verifica si el SDK tiene credenciales | N/A |

---

## Manejo de errores

El SDK **nunca lanza excepciones**. En caso de error:
- Loguea el error vía `Log::error()` (visible en `storage/logs/laravel.log`)
- Retorna `null`

Siempre verifica la respuesta si necesitas el ID:

```php
$response = Mailflow::track($userId, 'evento', $props);

if ($response === null) {
    // Error de conexión o configuración — revisar logs
}
```
