---
feature: F-009
tipo: decisiones
titulo: Decisiones del SDK para el catalogo de variables y el aprovisionamiento atomico
fecha: 2026-10-08
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F009-catalogo-sdk
autor: salomon@agavesoft.com.mx
---

# F-009 — Decisiones del SDK

Sesion no interactiva. Las decisiones tecnicas dentro del alcance las tomo el agente con la autorizacion de Jose: "elige la opcion mas simple y segura, consistente con las decisiones del feature" (respuesta pre-dada). El plan es el "Plan por componente → 2. SDK" del analisis; Jose lo aprobo sin preguntas (respuesta pre-dada).

## D1. Formato de `variables/`: una lista por archivo, con la misma forma que el paquete del servidor

Cada archivo de `variables/` (`.yaml`, `.yml` o `.json`) es una **lista** de variables. Cada una lleva `scope`, `key`, `event?` y los campos de la ficha (`label`, `type`, `description`, `allowed_values`, `required`, `default`, `filterable`, `sensitive`), y viaja tal cual en `variables[]` de `POST /api/provision`.

- Se descarto un mapa `clave: ficha` por seccion: la misma clave existe en varios eventos (`orden` en `orden_creada` y `orden_pagada`), y un mapa por evento necesitaria un nombre reservado para las comunes, que podria chocar con un evento real.
- El nombre del archivo es libre (`contact.yaml`, `eventos.yaml`). Se ordenan alfabeticamente, igual que el resto del comando.
- `event: null` se quita antes de mandar (comun). Una llave desconocida, como `sensible` por `sensitive`, falla antes de llamar, igual que el frontmatter de plantillas.

(respuesta pre-dada: decision tecnica dentro del alcance)

## D2. El SDK solo valida estructura; las reglas de negocio las valida el servidor (RN-13)

Localmente (`--dry-run` y antes de llamar) solo se revisa:

- que el archivo se pueda leer y sea una lista;
- que `scope` sea `contact`, `event` o `secret`;
- que `key` cumpla `^[a-z][a-z0-9_]{0,63}$`;
- que `event` no aparezca en `contact`;
- que no haya llaves desconocidas;
- que no haya variables repetidas entre archivos, con la misma `(scope, event, key)`.

El tipo, `description` obligatoria, `enum` con `allowed_values`, `required` en `contact`, `filterable` con `sensitive`, el tope de 100 y los datos fiscales los decide **solo** el servidor (422 por item en el paquete). Duplicarlas en el SDK es justo lo que F-009 quita a FF (C14, `ValidadorPlantillasSmartmailto`). `--validate` cubre la validacion completa sin guardar.

(respuesta pre-dada)

## D3. `smartmailto:provision` manda un solo paquete a `POST /api/provision` (RN-16), con `--activate` y `--validate`

- El comando ya no hace un `PUT` por recurso. Arma `{ variables, partials, templates, workflows, activate }` y hace **una** peticion. Los dos tests de F-008 que esperaban 4 URLs y "se detiene en el primer rechazo" se reescribieron: el cambio de comportamiento es el que pide RN-16 (G5).
- Con un rechazo `provision_failed` imprime **todos** los `items` y los `warnings`, y sale con codigo 1. Con un rechazo sin `items` (403 `provisioning_disabled`, 409 `busy`) imprime el cuerpo.
- `--validate` es una opcion del mismo comando, no un comando aparte, porque reutiliza la lectura de archivos y arma el mismo paquete. `POST /api/validate` responde **200 aunque sea invalido**: el comando y `validatePackage()` leen `valid`, y el comando sale con 1 si es `false`.
- `--dry-run` sigue siendo local (no llama) y gana sobre `--validate`.
- Sin `--activate`, si algun resultado queda `draft` o `inactive`, el comando lo dice y sugiere `--activate`. Se quito el texto viejo "activarlo en el panel".

(respuesta pre-dada)

## D4. Metodos del catalogo: los tres del analisis mas los que el servidor ya expone

`putVariable()`, `variables()` y `obsoleteVariable()` son los del analisis. Se agregaron `deleteVariable()`, `variableUsages()`, `schema()`, `activateTemplate()`, `activateWorkflow()`, `provisionPackage()` y `validatePackage()`: el servidor ya expone esos endpoints, y sin ellos `usages`/`items` de la excepcion no tienen como llegar al usuario.

- `?event=` va en **todas** las rutas `{scope}/{key}`, no solo en `PUT`: el servidor resuelve con `event` tambien `obsolete`, `DELETE` y `usages`, y sin el una variable de evento da 404.
- `deleteVariable()` devuelve `false` en 404, igual que `forget()`.
- Los nombres `provisionPackage`/`validatePackage` evitan chocar con `provision()`, el helper protegido por recurso que el fake ya reemplaza.
- Todas pasan por un helper protegido `provisioning()`. El fake reemplaza los metodos publicos, como en F-008: el aprovisionamiento nunca sale a la red en pruebas.

(respuesta pre-dada)

## D5. Excepcion enriquecida sin romper la firma

`SmartmailtoException` gana `error()`, `items()`, `usages()` y `warnings()`, que se leen de `$response`. El constructor y las fabricas no cambian. El mensaje de un rechazo ahora incluye el codigo: `Smartmailto rejected provision (422 provision_failed).`

## D6. `409 busy` sigue siendo rechazo, no transitorio

El candado de aprovisionamiento del servidor responde `409 busy`. Las llamadas de aprovisionamiento son sincronas (un deploy), no van por cola, asi que marcarlo como transitorio no agrega reintento. Ademas, `in_use` y `allowed_value_in_use` tambien son 409 y no deben reintentarse. Se deja como rechazo: el comando imprime `busy` y quien corre el deploy lo repite.

(respuesta pre-dada)

## D7. Dependencia nueva `symfony/yaml` (`^7.2|^8.0`)

El paquete necesita las variables como estructura, no como texto (a diferencia de los workflows, que el servidor parsea). `symfony/yaml` ya venia transitivo de Laravel. Se declara explicito con `^7.2|^8.0` porque Symfony 8 pide PHP 8.4 y el CI corre PHP 8.2 y 8.3 con Laravel 12, que usa Symfony 7.

## D9. Resultado de `/code-review` sobre la rama

Se aplicaron cuatro hallazgos:

- **Aviso de workflow desactivado y pausado.** El paquete de `POST /api/provision` resume cada resultado a `{result, status}` y no trae `deactivated`. Un workflow `updated` + `inactive` sin `--activate` ahora imprime un aviso explicito: si estaba activo, ya no inscribe contactos. `paused` avisa que se reactiva en el panel.
- **Espera propia del aprovisionamiento.** `smartmailto.provision_timeout` vale 120 s por default; antes se usaban los 10 s de la ingesta. Un paquete grande corre en una transaccion, y un timeout no distingue "fallo" de "se aplico". Ante un error transitorio, el comando avisa que pudo aplicarse y que repetirlo es seguro, porque es idempotente.
- **`event` con espacios alrededor** se rechaza localmente. El servidor lo guardaria tal cual y nunca coincidiria con el evento real.
- **El fake** se puede ajustar con `usagesResponse` y `deleteResponse`.

No se aplicaron:

- **`v2.3.0` como minor aunque `provision` requiere un servidor con F-009.** El analisis fija `v2.3.0` (v2.x, compatible hacia atras en la API PHP), y el unico consumidor es FF, que migra en su propio feature. Queda advertido en el README y el CHANGELOG, y como pregunta para Jose.
- **404 → `false` en `deleteVariable()`.** Es el mismo criterio que `forget()`. El servidor no distingue "no existe" de "falto `event`".
- **Archivos con el mismo nombre y distinta extension en `variables/`.** Se rechazan igual que en el resto de carpetas.
- **Cuerpo de plantilla armado en el comando y en `putTemplate()`.** Es duplicacion menor: el frontmatter nunca trae nulls.

## D8. Version `v2.3.0` sin publicar

`CHANGELOG.md` agrega `v2.3.0 — sin publicar`, arriba de `v2.2.0`, que tampoco esta publicada, y `branch-alias` pasa a `2.3.x-dev`. Sin tag ni release (fuera del alcance de esta rama).
