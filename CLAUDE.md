# notificabot-lara-pkg-sdk

**Repositorio:** agavesoft/notificabot-lara-pkg-sdk (antes `agavesoft/mailflow-sdk`)
**Proyecto:** Notificabot (producto comercial: Smartmailto)
**Fuente de conocimiento:**
- Repositorio: agavesoft/notificabot-conocimiento
- Ruta local: ~/workspaces/agavesoft/conocimiento/notificabot/

Paquete publico `agavesoft/smartmailto` (Packagist): SDK de Laravel 12-13 para la API de ingesta de Smartmailto (`notificabot-lara-mailflow`). El contrato de ingesta vive en el repo de conocimiento (`features/2026-10-F003-sdk-ingesta-robusta/dimensiones/tecnica.md` e `integracion.md`); este README lo replica. Un cambio de contrato es un feature de notificabot que toca este repo y el servidor en el mismo ciclo (las pruebas `tests/Feature/ContractTest.php` aqui y `tests/Feature/F003/ContractTest.php` en el servidor son espejo).

## Ramas y publicacion

- `develop` (trabajo) y `main` (publicado). Ramas de trabajo → PR a `develop`.
- Publicar: PR `develop → main` con merge commit y tag `vX.Y.Z` en `main`; Packagist se actualiza por webhook del repo.
- Gate: `.github/workflows/tests.yml` (matriz Laravel 12/13 + pint).
- Sin ambientes desplegables: no aplica `/agave-ci` de dev/pro.

## Captura automatica de conocimiento

Durante la implementacion de cambios en este componente, documenta automaticamente en `docs/pending/`:

- **Reglas de negocio descubiertas** → `F{XXX}-reglas.md` o `hallazgo-{fecha}-reglas.md`.
- **Decisiones tecnicas tomadas** → `F{XXX}-decisiones.md` o `hallazgo-{fecha}-decisiones.md`.
- **Edge cases y descubrimientos** → `F{XXX}-edge-cases.md` o `hallazgo-{fecha}-edge-cases.md`.
- **Cambios fuera de features** → `incrementales-{AAAA-MM}.md`.

Registrar cada archivo creado en `docs/cosecha.md`. La integracion al repo de conocimiento la hace `/agave-sync` desde `notificabot-conocimiento`.

## Persistencia de conocimiento

Cuando se te pida recordar algo: memoria local como cache y `docs/pending/` como fuente de verdad versionada.
