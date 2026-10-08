# Changelog

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
