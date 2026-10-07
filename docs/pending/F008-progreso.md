---
feature: F-008
tipo: progreso
fecha: 2026-10-07
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F008-envio-completo-ff
autor: seoane81@gmail.com
---

# Progreso de F-008 (follow-up B3)

## Completado
- [x] `send()` con adjuntos (`Attachment`), `to`, `cc`, `bcc`, `replyTo`, `from`, `secrets`; limites validados antes de encolar (1c1bd35)
- [x] `templates()`, `putTemplate()`, `putPartial()`, `putWorkflow()` y `SmartmailtoClient::put()` (1c1bd35)
- [x] Comando `smartmailto:provision {path} {--dry-run}` (1c1bd35)
- [x] `verifyWebhook()`, middleware `smartmailto.webhook`, config `webhook_secret` (1c1bd35)
- [x] composer `branch-alias` `dev-develop` → `2.2.x-dev` (1c1bd35)
- [x] Pruebas: contrato JSON exacto, compatibilidad, adjuntos y limites, provision dry-run y real, webhook (53 pruebas)
- [x] README y CHANGELOG (v2.2.0 sin publicar)

## Pendiente
- [ ] `/agave-cerrar` (marcador `F008-cierre.md`, PR a develop)

## Gaps cerrados
- Decisiones tecnicas dentro del alcance: tomadas y registradas en `F008-decisiones.md` (respuesta pre-dada).

## Notas para el siguiente
- Publicar v2.2.0 (PR develop → main + tag) es decision de Jose; mientras, FF usa `^2.2@dev` por VCS.
