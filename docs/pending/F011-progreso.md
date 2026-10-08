---
feature: F-011
tipo: progreso
fecha: 2026-10-08
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F011-pull-sdk
autor: salomon@agavesoft.com.mx
issue-pendiente: true
estado-pendiente: En desarrollo
---

# Progreso de F-011 (SDK)

## Completado
- [x] Ruta `POST {prefix}/smartmailto/pull` condicionada a `pull.enabled` + resolver; middleware `smartmailto.pull` (firma, ventana, request-id unico, 503 sin secreto) (7c39643)
- [x] Respuesta firmada `"{ts}.{request_id}.{cuerpo}"` con los vectores fijos del servidor (7c39643)
- [x] `Contracts\PullResolver`, `PullContact`, `PullEvent`, `PullPage`, `PullCursor` firmado (7c39643)
- [x] Filtro por catalogo F-009 (remoto en cache o lista fija; 503 si no se lee) (7c39643)
- [x] `history_months` (null = toda) y recorte por `max_response_bytes` (7c39643)
- [x] `identify(updatedAt)` y `batch()->identify(updatedAt)` (R-11) (7c39643)
- [x] `Smartmailto::fakePull()` + `assertPullContract()` (7c39643)
- [x] Fechas al resolver en la zona de la app
- [x] README (contrato v1, guia del resolver, R-4), CHANGELOG v2.4.0 sin publicar, branch-alias 2.4.x-dev
- [x] Pruebas Testbench (`tests/Feature/PullTest.php`) y contract test espejo del servidor (`ContractTest`)

## Pendiente
- [ ] Issue `NB-F011` en `agavesoft/notificabot-conocimiento`: no existe y esta sesion no puede crear Issues (regla de Jose)
- [ ] Transicion de estado `Ready to develop -> En desarrollo` en `features.md` del conocimiento: no se escribio (esta sesion no toca el conocimiento); `agave-sync` la deriva
- [ ] `consent: true` en `identify` (reconsentimiento R-8): fuera del plan del SDK
- [ ] Paginado de eventos por contacto (pre-mortem 4): requiere contrato v2

## Gaps cerrados
- Plan: aprobado por Jose sin preguntar (respuesta pre-dada).
- Decisiones tecnicas: tomadas con la opcion mas simple y segura (respuesta pre-dada); ver `F011-decisiones.md`.
- Validacion por unidad: con pruebas Pest automatizadas (respuesta pre-dada).

## Notas para el siguiente
- El contrato de pull vive en `analisis.md` de F-011; servidor `tests/Feature/F011/PullTest.php` y SDK `tests/Feature/ContractTest.php` (bloque F-011) cambian juntos.
- Probar contra el servidor real es la prueba manual de `cobertura.md` (app de prueba); no se hizo en esta sesion.
