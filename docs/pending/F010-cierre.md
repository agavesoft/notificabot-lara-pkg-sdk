---
feature: F-010
tipo: cierre
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F010-eventos-garantizados-sdk
fecha-cierre-componente: 2026-10-08
autor: salomon@agavesoft.com.mx
origen: agave-cerrar
issue-id: NB-F010
issue-pendiente: true
estado-pendiente: En desarrollo
alertas-dependabot-abiertas: sin-verificar
code-review-ejecutado: true
---

# Cierre de F-010 en el SDK (`agavesoft/smartmailto`)

Componente 2 de 2 en el orden de `intake.md`. El servidor (1) ya esta en develop y cerrado (PRs #46-#63 de `notificabot-lara-smartmailto`, marcador `F010-cierre.md` del servidor, mas #63: alertas agrupadas y estado cerrado de la consulta). Con este cierre, los dos componentes quedan cerrados. Factura Facilita adopta el outbox en FF-F041.

## Que hizo este componente

- **Outbox transaccional (J1, J3):**
  - primera migracion que publica el paquete (`--tag=smartmailto-migrations`): `smartmailto_outbox` y `smartmailto_outbox_alert_state`;
  - con `outbox.enabled` (apagado por default), `track`, `send`, `identify`, `link` y `reportExternalSend` escriben en la transaccion de la app, con el payload cifrado con APP_KEY.
- **Worker `smartmailto:outbox:work`:**
  - acuse por llave;
  - backoff de 30 s, 2 min, 10 min y despues cada hora, hasta 72 h;
  - `send_before` → `expired` y rechazo definitivo → `failed`;
  - consulta previa `GET /api/send/{key}` antes de cualquier emergencia;
  - `SmartmailtoOutboxFailed`;
  - transiciones con UPDATE condicionado (nunca `superseded` en vuelo);
  - recuperacion de filas `sending` colgadas;
  - heartbeat `POST /api/outbox/heartbeat`.
- **Emergencia sin duplicados (regla 17):** `reportExternalSend()` cierra la fila como `superseded` y deja el `external_report` → `POST /api/send/external`, con la misma garantia.
- **Alertas agrupadas (regla 2):**
  - filas sin acuse: 15 min, 1 h, 4 h, 12 h, 24 h y 48 h, un resumen a las 72 h y "recuperado";
  - 422 de inmediato, agrupados por plantilla en ventanas de 15 min;
  - canales: correo y Teams (tarjeta adaptable).
- **Identidad y API:** `identify(consent:)`, `link()`, y `Identity::external()` + `origin` (R2).
- **Adjuntos por URL (J12):** `Attachment::fromDisk()` (URL firmada nueva en cada intento) y `fromUrl()`.
- **Comandos:** `status`, `retry` (nunca `send`) y `prune` (`--contact` para ARCO).
- **Documentacion:**
  - README con la regla 4 por canal (R1), la guia de emergencia (listener en cola e idempotente) y la seccion del outbox;
  - CHANGELOG `v2.5.0 — sin publicar`;
  - `branch-alias` `2.5.x-dev`;
  - sin tag.

## Pruebas agregadas (respuesta pre-dada: "los de esta rama")

- `tests/Feature/OutboxTest.php`. Cubre los criterios 1, 2, 3, 22, 29, 31, 32, 34, 35, 37 y la parte del SDK del 36. Ademas:
  - backoff;
  - 401 y SDK sin configurar;
  - identify A→B→A;
  - fila colgada;
  - heartbeat;
  - un escalon sin canal;
  - 410;
  - reporte despues del acuse, sin fila, con 409 y con 422;
  - `link` con 404 y 409;
  - adjuntos por disco;
  - prune ARCO (mayusculas, cc, en vuelo);
  - retry sin `send`;
  - listener que truena;
  - outbox apagado.
- `tests/Feature/ContractTest.php`, bloque F-010 espejo del servidor:
  - `consent`;
  - `link`;
  - `recipient_kind` y `origin`;
  - externos rechazados en `track`, `identify` y `link`;
  - adjunto por URL;
  - `send/external` sin outbox;
  - consulta con "/" en la llave;
  - heartbeat por POST.
- **Suite local:** 162 pruebas en verde (PHP 8.4 / Laravel 13). Pint en verde.

## Aprendizajes (respuesta pre-dada: derivados de decisiones, edge cases y diff)

- **El contrato del servidor manda sobre el analisis:**
  - el heartbeat es POST;
  - no hay header de outbox;
  - `identify` no lleva acuse;
  - la consulta usa un conjunto cerrado de estados;
  - el 409 del reporte es reintentable.
- **`lockForUpdate` no basta (SQLite lo ignora).** La garantia contra el doble envio y el `superseded` en vuelo la da el UPDATE condicionado al estado.
- **Una llave por contenido en `identify` rompe A→B→A.** Llave por llamada y `updated_at` dejan que el servidor ordene.
- **Un 401 no es culpa de la fila.** Fallarla dispararia una emergencia por cada `send` al rotar el token.
- **Un listener sincrono que truena perdia la emergencia y cortaba la pasada.** Ahora se registra y la pasada sigue; el README exige listener en cola e idempotente.
- **Reintentar un `send` failed puede duplicar el correo** (ya paso a la emergencia): `retry` lo excluye.

## Pendientes (respuesta pre-dada: honestos, fuera del alcance de esta rama)

- **Issue `NB-F010`:** no existe y la sesion no crea Issues. El PR va sin `Refs`.
- **Estado de `features.md`:** sigue en `Ready to develop` (esta sesion no escribe en main del conocimiento). Debe pasar a `En desarrollo`, y `agave-sync` decide `Terminada`.
- **Publicacion (RV9):** publicar 2.2.0 → 2.5.0 en orden y dar de alta en Packagist antes del rc de FF-F041. Esta rama no crea tags.
- **Sin outbox,** `reportExternalSend` y `link` van por `DeliverToSmartmailto`: ahi un 409 `send_in_flight` o un 404 de `link` son definitivos (`SmartmailtoDeliveryFailed`) y no se reintentan. Impacto bajo: FF usara el outbox.
- **`batch()`** sigue por la cola, no por el outbox (S19).
- **Prueba manual** con una app de prueba contra el servidor de DEV: no se hizo. Va con la integracion FF-Smartmailto en DEV.
- **Gate de Dependabot:** no se verifico, porque la consulta requeria aprobacion en esta sesion no interactiva.

## Conocimiento capturado

- `docs/pending/F010-decisiones.md` (S1-S33)
- `docs/pending/F010-edge-cases.md`
- `F010-progreso.md`: absorbido en este marcador y eliminado.
