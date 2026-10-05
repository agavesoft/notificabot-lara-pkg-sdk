# Changelog

## v2.0.0 — 2026-10

Paquete renombrado: `agavesoft/mailflow` → `agavesoft/smartmailto` (namespace `Agavesoft\Smartmailto`).

- Laravel 11, 12 y 13; PHP 8.2+.
- Identidad con `Identity::user($id, $email)` / `Identity::guest($email)`: personas sin cuenta.
- `eventId` obligatorio en `track` e `idempotencyKey` obligatoria en `send` (idempotencia de punta a punta).
- `secrets`, `object` y `occurredAt` en `track`.
- `batch(backfill: true)` para cargas iniciales que no disparan correos.
- Reintentos reales: solo errores transitorios, con backoff y `Retry-After`; evento `SmartmailtoDeliveryFailed`.
- Encolado `afterCommit` por default; `SMARTMAILTO_ENABLED=false` no construye el cliente.
- `Smartmailto::fake()` para pruebas.

## v1.0.1

Ultima version de `agavesoft/mailflow` (Laravel 10-12).
