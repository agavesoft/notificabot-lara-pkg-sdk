---
feature: F-003
tipo: cierre
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F003-sdk-ingesta-robusta
branch-cierre: feature/2026-10-NB-F003-sdk-ingesta-robusta-cierre
fecha-cierre-componente: 2026-10-07
autor: seoane81@gmail.com
origen: agave-cerrar
issue-id: NB-F003
issue-numero: '9'
pr-feature: '#1'
---

# Cierre de F-003 en notificabot-lara-pkg-sdk

Cierre retroactivo: el codigo del feature ya estaba en `develop` (PR #1, squash `5532c6e`) sin marcador de cierre. Esta rama solo agrega el marcador; no toca codigo.

## Que hizo F-003 en este componente

- Renombre: paquete `agavesoft/mailflow` → `agavesoft/smartmailto`, namespace `Agavesoft\Smartmailto`, repo `notificabot-lara-pkg-sdk` (antes `mailflow-sdk`).
- Soporte Laravel 12 y 13, PHP 8.2+. El PR #1 decia 11-13; Laravel 11 se quito despues (decision S8: Composer bloquea todas sus versiones por avisos de seguridad sin parche).
- `Identity::user($id, $email)` / `Identity::guest($email)` para personas sin cuenta.
- `track` con `eventId` obligatorio, `secrets`, `object` y `occurredAt`; `send` con `idempotencyKey` obligatoria; `batch(backfill: true)` en lotes de 100.
- Entrega en cola `afterCommit`; reintenta solo red/5xx/429 (respeta `Retry-After`) hasta 24 h por `retryUntil`; 4xx y agotados disparan `SmartmailtoDeliveryFailed`; items invalidos de un lote se reportan uno por uno; un item `error` reintenta el lote completo; cola `sync` con entrega unica tras el commit.
- `SMARTMAILTO_ENABLED=false` no construye el cliente.
- `Smartmailto::fake()` con asserts y las mismas validaciones.
- README con el contrato de ingesta (espejo de `tecnica.md` / `integracion.md`) y guia de migracion desde v1.
- CI `.github/workflows/tests.yml`: matriz Laravel 12/13 + pint.

## Pruebas

- Del PR #1 (respuesta pre-dada): `tests/Feature/ContractTest.php` (contrato exacto, espejo de `tests/Feature/F003/ContractTest.php` en `notificabot-lara-mailflow`) y las de entrega/reintentos/fake. Esta rama no agrega pruebas.
- Safety net local al cerrar (develop @ `0a24b04`, Herd PHP 8.4): 27 pruebas, 48 aserciones, en verde. Incluye lo de F-008 y F-006 que llego despues.

## Aprendizajes absorbidos (respuesta pre-dada)

Salen de `docs/pending/F003-decisiones.md` (S1-S11), que queda para `/agave-sync`. Los principales:

- Reintentar por tiempo (`retryUntil` 24 h) y no por numero: con `maxExceptions` 20 y backoff de 1 h el job se rendia a las ~16 h (S4 → S9, salio del code review).
- `release()` en `SyncJob` no reencola: con cola `sync` el evento se perdia y la excepcion se escapaba del commit de la app (S11).
- El SDK no puede depender de `illuminate/foundation` (S6).
- Laravel 11 fuera del soporte, contra lo que decia la definicion (S8). Esto tiene que quedar en la definicion del feature cuando corra `/agave-sync`.

## Pendientes / follow-ups (respuesta pre-dada)

1. **Packagist:** `agavesoft/smartmailto` tiene los tags v2.0.0 y v2.1.0 en GitHub, pero el paquete no esta dado de alta en Packagist. Hasta que se de de alta, `composer require agavesoft/smartmailto` no resuelve sin un repositorio VCS. Lo decide y lo hace Jose (cuenta de Packagist + webhook).
2. **Cambios sin publicar en develop:** el contrato de emergencia completo (`send_expired` por webhook, invitado en `orden_pagada`, commit `1edf699`) y F-006 (`contact()`, `forget()`, `renderedEmail()`, `0a24b04`). Estan planeados como v2.2.0 en el CHANGELOG. Publicar es un PR `develop → main` con merge commit + tag `v2.2.0`; lo decide Jose.
3. **Definicion vs implementacion:** la definicion firmada dice Laravel 11-13 y lo que se entrego es 12-13 (S8). `/agave-sync` tiene que reflejarlo en la definicion.
4. **Servidor vs SDK:** `eventId` e `idempotencyKey` siguen opcionales en el servidor por compatibilidad con v1 (S2). Que el servidor los exija algun dia depende de que ya no queden clientes v1; no hay fecha.
5. Las decisiones S1-S11 siguen en `docs/pending/F003-decisiones.md`, pendientes de `/agave-sync`.

## Componentes del feature

Intake: `notificabot-lara-mailflow` (ya cerro) y el SDK (este). Con este marcador cierran todos los componentes declarados; el paso a `Terminada` lo hace `/agave-sync`.
