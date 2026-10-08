---
feature: F-010
tipo: progreso
fecha: 2026-10-08
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F010-eventos-garantizados-sdk
autor: salomon@agavesoft.com.mx
issue-pendiente: true
estado-pendiente: En desarrollo
---

# Progreso de F-010 (SDK)

## Completado

- [x] Migracion publicada `smartmailto_outbox` + `smartmailto_outbox_alert_state` (plan SDK 1, 6e)
- [x] track/send/identify/link escriben al outbox con `outbox.enabled` (plan SDK 2)
- [x] Worker con backoff, acuse por llave, `send_before` → `expired`, `superseded` con fila tomada (plan SDK 3)
- [x] `SmartmailtoOutboxFailed` y consulta previa `GET /api/send/{key}` (plan SDK 3b)
- [x] `external_report` + `reportExternalSend()` → `POST /api/send/external` (plan SDK 3c)
- [x] Alertas agrupadas + heartbeat `POST /api/outbox/heartbeat` (plan SDK 4)
- [x] Adjuntos por URL firmada (`fromDisk`, `fromUrl`) (plan SDK 5)
- [x] Contract tests espejo (plan SDK 6)
- [x] `Identity::external` + `origin` (6b), README regla 4 por canal (6c), `identify(consent:)` (6d)
- [x] CHANGELOG v2.5.0 sin publicar (plan SDK 7)

## Pendiente

- [ ] Publicar 2.2.0 → 2.5.0 en orden y alta en Packagist antes del rc de FF-F041 (RV9; fuera de esta rama)

## Gaps cerrados

- Plan y decisiones tecnicas: respuestas pre-dadas del archivo de respuestas (ver F010-decisiones.md).
- Issue NB-F010: no existe y esta sesion no crea Issues (regla dura) → `issue-pendiente: true`.
- Estado en `features.md` del conocimiento: sigue `Ready to develop`; esta sesion no escribe en main del
  conocimiento → `estado-pendiente: En desarrollo`.

## Notas para el siguiente

- El plan SDK 8 (profundidad de carga inicial) ya existe desde F-011 (`pull.history_months`).
- FF-F041 adopta el outbox en su propio feature (listener de emergencia + `reportExternalSend`).
