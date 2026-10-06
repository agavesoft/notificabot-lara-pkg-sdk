# Changelog

## v2.2.0 — 2026-10

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
