---
feature: F-009
tipo: cierre
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F009-catalogo-sdk
fecha-cierre-componente: 2026-10-08
autor: salomon@agavesoft.com.mx
origen: agave-cerrar
issue-id: NB-F009
issue-pendiente: true
estado-pendiente: En desarrollo
alertas-dependabot-abiertas: sin-verificar
code-review-ejecutado: true
---

# Cierre de F-009 en el SDK (`agavesoft/smartmailto`)

Componente 2 de 2 en el orden de `intake.md` (`## Componentes`). El servidor (1) se mergeo a develop antes, en los PRs #35-#39 de `notificabot-lara-smartmailto`. Con este cierre, ya cerraron los dos componentes. Factura Facilita no es componente: migra en su propio feature.

## Que hizo este componente

- **Metodos del catalogo:** `variables()`, `putVariable()`, `obsoleteVariable()`, `deleteVariable()`, `variableUsages()` y `schema()`. Todas las rutas `{scope}/{key}` llevan `?event=`.
- **Activacion explicita (RN-4, P13):** `activateTemplate()` y `activateWorkflow()`.
- **Paquete atomico (RN-16):** `provisionPackage()` (`POST /api/provision`) y `validatePackage()` (`POST /api/validate`; hay que revisar `valid`, porque responde 200 aunque el paquete no sea valido).
- **`smartmailto:provision`:**
  - lee `variables/*.yaml|yml|json`, una lista por archivo;
  - manda un solo paquete, todo o nada;
  - acepta `--activate` y `--validate`;
  - imprime los avisos sin fallar y todos los items fallidos;
  - advierte cuando un workflow se desactiva, cuando esta pausado o cuando hay timeout.
- **`SmartmailtoException`:** `error()`, `items()`, `usages()` y `warnings()`. El mensaje incluye el codigo.
- **`SmartmailtoFake`:** registra catalogo, paquetes y activaciones sin red, y agrega asserts nuevos.
- **Configuracion y dependencias:**
  - `smartmailto.provision_timeout`, de 120 s;
  - `symfony/yaml` `^7.2|^8.0`;
  - `branch-alias` `2.3.x-dev`.
- **Documentacion:** el README trae el contrato y las reglas del catalogo; el CHANGELOG, `v2.3.0 — sin publicar`. Sin tag ni release.

## Pruebas agregadas (respuesta pre-dada: "los de esta rama")

- `tests/Feature/ContractTest.php`: el catalogo con `?event=`; el 409 `in_use` con `usages()`; la activacion con `items()`; `provision_failed` con `items` y `warnings`; `validate` con `valid=false`; `schema`; `enabled=false` sin red; y el fake.
- `tests/Feature/ProvisionCommandTest.php`:
  - el paquete exacto en una sola peticion;
  - `--activate` y `--validate`, valido e invalido;
  - el rechazo con todos los items;
  - el aviso de workflow desactivado y de pausado, y el timeout;
  - 10 casos de archivos de variables invalidos;
  - variables repetidas, fechas entre comillas y el fake con el comando.
- Suite local: **82 pruebas en verde** (258 aserciones), PHP 8.4 / Laravel 13. Pint en verde.

## Aprendizajes (respuesta pre-dada: derivados de decisiones, edge cases y diff)

- **El SDK solo valida la estructura de los archivos.** Las reglas de negocio quedan solo en el servidor (RN-13): duplicarlas es justo lo que F-009 le quita a FF (C14). Ver `F009-decisiones.md` D2.
- **`POST /api/validate` responde 200 aunque el paquete sea invalido.** Un cliente que solo mire el estado HTTP deja pasar paquetes rotos.
- **YAML convierte fechas sin comillas en enteros.** El comando las rechaza antes de llamar.
- **La respuesta del paquete no trae `deactivated`.** El SDK infiere el riesgo de `updated` + `inactive` y lo advierte.
- **Un paquete grande no cabe en la espera de 10 s de la ingesta.** El aprovisionamiento usa su propia espera, y un timeout se reporta como "pudo aplicarse; repetir es seguro".

## Pendientes (respuesta pre-dada: honestos, fuera del alcance de esta rama)

- **Issue `NB-F009`:** no existe en `agavesoft/notificabot-conocimiento`, y las reglas de la sesion prohiben crear Issues. El PR va sin `Refs`.
- **Estado en `features.md`:** el conocimiento sigue en `Ready to develop` aunque los dos componentes ya estan en develop. Esta sesion no lo toca. Le toca a agave-sync, o a Jose.
- **Tag y release `v2.3.0`:** con `/agave-release` cuando Jose lo decida.
- **`v2.3` como minor:** `smartmailto:provision` requiere un servidor con F-009 (404 en uno anterior). Esta advertido en el README y el CHANGELOG.
- **Factura Facilita**, en su propio feature: crear `resources/smartmailto/variables/`, poner `folio_fiscal` como `secret`, correr `--validate` en CI y borrar `ValidadorPlantillasSmartmailto`.
- **Gate de Dependabot no verificado:** la consulta `gh api .../dependabot/alerts` requeria aprobacion en esta sesion no interactiva.

## Conocimiento capturado

- `docs/pending/F009-decisiones.md` (D1-D9)
- `docs/pending/F009-edge-cases.md`
- `F009-progreso.md`: absorbido en este marcador y eliminado.
