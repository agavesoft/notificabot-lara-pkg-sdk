# Changelog

## v2.5.0 — sin publicar

- F-010 (eventos garantizados), compatible hacia atras: el outbox viene **apagado** (`SMARTMAILTO_OUTBOX_ENABLED=false`) y sin el todo sigue igual. **Requiere un servidor con F-010** (acuse con llave, `GET /api/send/{key}`, `POST /api/send/external`, `POST /api/outbox/heartbeat`, `POST /api/contacts/link`).
  - **Outbox transaccional:** primera migracion que publica el paquete (`--tag=smartmailto-migrations`): `smartmailto_outbox` y `smartmailto_outbox_alert_state`.
    - Con `outbox.enabled`, `track`, `send`, `identify`, `link` y `reportExternalSend` escriben la fila en la transaccion de la app (conexion `outbox.connection`), con el payload cifrado con `APP_KEY`.
    - La fila se cierra solo con el acuse de su llave.
  - **Worker** `smartmailto:outbox:work` (`--once`, `--sleep`, `--max-time`):
    - Reintentos con backoff de 30 s, 2 min, 10 min y despues cada hora, hasta 72 h (`failed`, `reason=gave_up`). 401/403 y la falta de configuracion se reintentan, nunca fallan la fila.
    - Un `send` que llega a su `send_before` sin acuse (o recibe 410) queda `expired`. Antes de cerrarlo consulta `GET /api/send/{key}`: si ya salio, esta en cola o se descarto a proposito, queda `acked`.
    - Filas colgadas en `sending` vuelven a `pending` a los 10 min.
    - Aviso de vida `POST /api/outbox/heartbeat` cada 5 min.
  - **Evento `SmartmailtoOutboxFailed`** (`outboxId`, `kind`, `key`, `reason`, `template`, `status`, `error`; sin datos personales): rechazo definitivo (`reason` = estado HTTP), `expired` o `gave_up`. `needsEmergencySend()` para los `send`.
  - **`Smartmailto::reportExternalSend($key, $identity?, $template?, $sentAt?, $channel = 'ses_direct', $reason?)`:** reporta el envio de emergencia de la app.
    - Con el outbox marca la fila `superseded` (nunca en vuelo: lanza `OutboxRowInFlight`) y escribe la fila `external_report` en la misma transaccion. Sin outbox lo encola a `POST /api/send/external`.
  - **Alertas agrupadas por proyecto** (correo `alerts.mail_to` y Teams `alerts.teams_webhook_url`, tarjeta adaptable):
    - primera a los 15 min, recordatorios a 1 h, 4 h, 12 h, 24 h y 48 h, un resumen a las 72 h y "recuperado";
    - 422 de inmediato, agrupados por plantilla en ventanas de 15 min.
  - Comandos `smartmailto:outbox:status`, `smartmailto:outbox:retry {id*} --key= --failed` y `smartmailto:outbox:prune [--contact=]` (retencion: acked 7 dias, cerradas 30 dias; ARCO por persona).
  - Con el outbox, `send()` exige `sendBefore`; un `track` sin `occurredAt` guarda la hora del commit; un `identify` manda `updated_at` (hora de la llamada).
  - `identify(..., consent: true)` (vuelta a consentir, D7).
  - `Smartmailto::link($survivor, $absorbed, $reason, $linkId)` (`POST /api/contacts/link`).
  - `Identity::external($email)` (`recipient_kind: external`) y `send(..., origin: Identity)`: destinatarios que no son contactos (R2).
  - Adjuntos por URL: `Attachment::fromDisk($disk, $path, $filename?)` (URL firmada nueva en cada intento, 24 h) y `Attachment::fromUrl($url, $filename, $sha256)`. Con el outbox, un adjunto inline de mas de 1 MB se rechaza antes de guardar.
  - `SmartmailtoFake`: `reportExternalSend()` registrado sin red; `assertLinked()` y `assertExternalSendReported()`.
  - README: regla 4 por canal (R1) y guia de emergencia sin duplicados. Ya no recomienda mandar directo por `health()`.
  - Dependencias declaradas: `illuminate/database`, `illuminate/encryption`, `illuminate/filesystem` e `illuminate/mail`. `composer.json`: `branch-alias` `dev-develop` → `2.5.x-dev`.

## v2.4.0 — sin publicar

- F-011 (sincronizacion desde el proyecto: pull), compatible hacia atras. **Requiere un servidor con F-011**, y el pull se prende por proyecto en el panel. Con `SMARTMAILTO_PULL_ENABLED=false` (default) nada cambia: no se registra ninguna ruta.
  - Ruta `POST {prefix}/smartmailto/pull` (prefijo `api`, nombre `smartmailto.pull`). Solo existe con `smartmailto.pull.enabled` y un resolver; si no, 404.
    - Middleware `smartmailto.pull`: firma con `SMARTMAILTO_PULL_SECRET` (`pullsec_...`, propio y separado del webhook), ventana de 300 s y `X-Smartmailto-Request` de un solo uso.
    - Throttle `120,1` y middleware extra configurables.
  - La respuesta va firmada: `"{timestamp}.{request_id}.{cuerpo}"`.
  - `Contracts\PullResolver` (`contact()`, `contacts()`), con `Pull\PullContact`, `PullEvent`, `PullPage` (con `deleted`) y `PullCursor`.
    - El SDK arma y firma el cursor `(updatedAt, key)`.
    - Las fechas llegan al resolver en la zona de la app.
  - Datos minimos: los atributos se filtran contra las variables de contacto del catalogo (F-009).
    - El catalogo se lee del servidor en cache 5 min, o se fija con `smartmailto.pull.catalog`.
    - Si no se puede leer: 503 (Smartmailto reintenta).
  - `smartmailto.pull.history_months` (`SMARTMAILTO_PULL_HISTORY_MONTHS`): `null` = toda la historia.
  - La pagina se recorta a `pull.max_response_bytes` (5 MB) y el cursor sigue desde lo que cupo.
  - `Smartmailto::fakePull($resolver, catalog: [...])` prueba el resolver por la ruta real sin red: `contact()`, `contacts()` y `assertPullContract()`.
  - `identify(..., updatedAt:)` y `batch()->identify(..., updatedAt)`: hora del cambio en la app. Si push y pull traen el mismo atributo, gana el mas reciente (R-11).
  - `composer.json`: `branch-alias` `dev-develop` → `2.4.x-dev`.

## v2.3.0 — sin publicar

- F-009 (catalogo de variables por proyecto y control de usos), compatible hacia atras en la API PHP. **Requiere un servidor con F-009**: sin `POST /api/provision`, `smartmailto:provision` falla con 404 y no cambia nada.
  - Catalogo: `variables($scope?, $event?)`, `putVariable($scope, $key, $definition, $event?)`, `obsoleteVariable()`, `deleteVariable()` (false si no existia), `variableUsages()` y `schema()` (esquema para agentes).
  - Activacion explicita: `activateTemplate($name)` y `activateWorkflow($name)`. Lo nuevo queda en borrador.
  - Paquete atomico: `provisionPackage($package, $activate)` (`POST /api/provision`, todo o nada) y `validatePackage($package, $activate)` (`POST /api/validate`, sin guardar). `validatePackage` responde 200 aunque no sea valido: hay que revisar `valid`.
  - `smartmailto:provision` lee `variables/*.yaml|yml|json` (una lista por archivo) y manda **un solo paquete**. Antes eran un `PUT` por recurso y se detenia en el primer rechazo. Ahora, si algo falla, imprime **todos** los items fallidos y no aplica nada. Opciones nuevas: `--activate` y `--validate` (para CI). Los avisos (`warnings`) se imprimen y no hacen fallar.
  - `SmartmailtoException`: `error()`, `items()`, `usages()` y `warnings()` leen el cuerpo del rechazo. El mensaje incluye el codigo (`Smartmailto rejected provision (422 provision_failed).`).
  - `SmartmailtoFake` registra el catalogo, los paquetes y las activaciones sin salir a la red. Asserts nuevos: `assertPackageProvisioned()`, `assertVariablePut()` y `assertActivated()`.
  - El aprovisionamiento y el catalogo usan su propia espera: `smartmailto.provision_timeout` (`SMARTMAILTO_PROVISION_TIMEOUT`, default 120 s). La espera de la ingesta sigue en 10 s.
  - El comando avisa en tres casos:
    - un workflow que estaba activo y quedo inactivo;
    - un workflow pausado;
    - un timeout, que pudo aplicarse (repetirlo es seguro).
  - Dependencia nueva: `symfony/yaml` (`^7.2|^8.0`). `composer.json`: `branch-alias` `dev-develop` → `2.3.x-dev`.

## v2.2.0 — sin publicar

- F-008 (envio completo de Factura Facilita), compatible hacia atras:
  - `send()` acepta con nombre `attachments` (`Attachment::fromPath/fromData/fromUpload`, `UploadedFile` o ruta; base64 y `content_type` por extension), `to`, `cc`, `bcc`, `replyTo`, `from` y `secrets`. Limites del servidor (10 archivos, 7 MB) validados antes de encolar; configurables con `smartmailto.attachments`.
  - Aprovisionamiento: `templates()`, `putTemplate()`, `putPartial()`, `putWorkflow()` y `php artisan smartmailto:provision {path} {--dry-run}`.
  - Webhook de falla: `Smartmailto::verifyWebhook($request, $secret?, $tolerance = 300)` y middleware `smartmailto.webhook`; config `smartmailto.webhook_secret` (`SMARTMAILTO_WEBHOOK_SECRET`).
  - `SmartmailtoFake`: `assertProvisioned()`; `renderedEmail()` y el aprovisionamiento ya no salen a la red.
  - `composer.json`: `branch-alias` `dev-develop` → `2.2.x-dev`.
- F-006: `Smartmailto::contact(Identity)` (consulta de datos de una persona), `Smartmailto::forget(Identity)` (borrado ARCO) y `Smartmailto::renderedEmail($sendId)` (correo enviado re-generado). Sincronos; quedan en la bitacora de acceso del proyecto.

## v2.1.0 — 2026-10

- `send(..., sendBefore:)`: si Smartmailto no lo envia antes, responde 410 y se dispara `SmartmailtoDeliveryFailed` (contrato de emergencia: la app lo manda directo sin duplicar).
- `Smartmailto::health()`: `ok | degraded | down` del proyecto para la bandera de emergencia.

## v2.0.0 — 2026-10

Paquete renombrado: `agavesoft/mailflow` → `agavesoft/smartmailto` (namespace `Agavesoft\Smartmailto`).

- Laravel 12 y 13; PHP 8.2+ (Laravel 11 ya no recibe parches de seguridad: Composer bloquea todas sus versiones).
- Identidad con `Identity::user($id, $email)` / `Identity::guest($email)`: personas sin cuenta.
- `eventId` obligatorio en `track` e `idempotencyKey` obligatoria en `send` (idempotencia de punta a punta).
- `secrets`, `object` y `occurredAt` en `track`.
- `batch(backfill: true)` para cargas iniciales que no disparan correos.
- Reintentos reales: solo errores transitorios, con backoff y `Retry-After`; evento `SmartmailtoDeliveryFailed`.
- Encolado `afterCommit` por default; `SMARTMAILTO_ENABLED=false` no construye el cliente.
- `Smartmailto::fake()` para pruebas.

## v1.0.1

Ultima version de `agavesoft/mailflow` (Laravel 10-12).
