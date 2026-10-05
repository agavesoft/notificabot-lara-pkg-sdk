---
feature: F-003
tipo: decisiones
titulo: Decisiones del SDK v2
fecha: 2026-10-05
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F003-sdk-ingesta-robusta
autor: seoane81@gmail.com
---

# Decisiones — SDK v2

| # | Decision | Por que |
|---|---|---|
| S1 | Paquete renombrado a `agavesoft/smartmailto` y repo a `notificabot-lara-pkg-sdk` (product engineer, 2026-10-05) | v2 rompe la API de todos modos; nombre comercial |
| S2 | `eventId` e `idempotencyKey` obligatorios en el SDK (opcionales en el servidor por compatibilidad v1) | Sin ellos un reintento duplica; el servidor no puede detectarlo |
| S3 | Encolado `afterCommit` por default | Los eventos de FF ocurren dentro de transacciones (timbrado, activacion) |
| S4 | `tries = 0` + `retryUntil` 24 h + `maxExceptions` 20; 429 hace `release(Retry-After)` | Reintentar por tiempo, no por numero; respetar el limite del servidor |
| S5 | 4xx → `fail()` sin reintento + `SmartmailtoDeliveryFailed`; items invalidos de un lote se reportan uno por uno | Un rechazo no se arregla reintentando; la app debe enterarse |
| S6 | Sin dependencias de `illuminate/foundation` en el codigo (jobs y eventos sin traits de Foundation) | El paquete declara solo `illuminate/*` |
| S7 | `Smartmailto::fake()` registra el cuerpo exacto que se mandaria y mantiene las validaciones | Las pruebas de la app cliente detectan un `eventId` faltante |
| S8 | Soporte Laravel 12 y 13, no 11 (la definicion decia 11-13) | Todas las versiones de Laravel 11 tienen avisos de seguridad sin parche y Composer las bloquea (`policy.advisories.block`); FF usa 13 |
| S9 | Sin `maxExceptions`: los reintentos los limita solo `retryUntil` | Con 20 excepciones y backoff de 1 h se rendia a las ~16 h (code review) |
| S10 | Item `error` en un lote → excepcion transitoria y se reintenta el lote completo | Los aceptados vuelven como duplicados por `event_id`; nada se pierde en silencio |
| S11 | Cola `sync`: entrega unica tras el commit y falla → `SmartmailtoDeliveryFailed` | `release()` en `SyncJob` no reencola (el evento se perdia) y una excepcion saldria del commit de la app |
