---
feature: F-009
tipo: progreso
fecha: 2026-10-08
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F009-catalogo-sdk
autor: salomon@agavesoft.com.mx
issue-pendiente: true
estado-pendiente: En desarrollo
---

# Progreso de F-009 (SDK)

## Completado
- [x] Metodos del catalogo, activacion, paquete, validate y schema en `Smartmailto` (+ facade y fake) — 1681c29
- [x] `smartmailto:provision`: `variables/`, paquete unico todo o nada, `--activate`, `--validate`, avisos — 1681c29
- [x] `SmartmailtoException` enriquecida (`error`, `items`, `usages`, `warnings`) — 1681c29
- [x] Pruebas Pest (contrato + comando), pint
- [x] README (contrato y reglas del catalogo), CHANGELOG `v2.3.0 — sin publicar`, `branch-alias` 2.3.x-dev

## Pendiente
- [ ] Cierre (agave-cerrar): PR a develop
- [ ] Fuera de esta rama: tag/release `v2.3.0` (con agave-release cuando Jose lo decida)
- [ ] Fuera de este componente: FF migra su `smartmailto:aprovisionar` a `variables/` + `--validate` en su propio feature

## Gaps cerrados
- Plan: aprobado por Jose sin preguntas (respuesta pre-dada).
- Decisiones tecnicas dentro del alcance: las tomo el agente (respuesta pre-dada). Ver F009-decisiones.md (D1-D8).

## Notas para el siguiente
- **Issue `NB-F009` no existe** en `agavesoft/notificabot-conocimiento`, y las reglas de esta sesion prohiben crear Issues. El PR va sin `Refs`.
- **Estado en `features.md`**: sigue en `Ready to develop` aunque el servidor ya se mergeo. Esta sesion no lo toca (regla: el conocimiento no se toca salvo via rama + PR). Lo resuelve Jose o agave-sync.
- El contrato se leyo del servidor en develop `bc94599` (`Provisioner::package`, `VariableController`, `docs/api-envio-y-aprovisionamiento.md` §3b).
