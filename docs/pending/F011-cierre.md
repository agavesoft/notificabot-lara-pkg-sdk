---
feature: F-011
tipo: cierre
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F011-pull-sdk
fecha-cierre-componente: 2026-10-08
autor: salomon@agavesoft.com.mx
origen: agave-cerrar
issue-id: NB-F011
issue-pendiente: true
estado-pendiente: En desarrollo
alertas-dependabot-abiertas: sin-verificar
code-review-ejecutado: true
---

# Cierre de F-011 en el SDK (`agavesoft/smartmailto`)

Componente 2 de 2 en el orden de `intake.md` (`## Componentes`). El servidor (1) ya esta en develop: PRs #40, #42, #43 y #44 de `notificabot-lara-mailflow`. Con este cierre, los dos componentes quedan cerrados. Factura Facilita no es componente: implementa su resolver en su propio feature.

## Que hizo este componente

- **Ruta:** `POST {prefix}/smartmailto/pull` (prefijo `api`, nombre `smartmailto.pull`).
  - Se registra solo con `smartmailto.pull.enabled` (apagado por default) y un resolver. Sin eso no existe y responde 404.
  - Lleva throttle `120,1` antes de la firma y acepta middleware extra.
- **Middleware `smartmailto.pull`:**
  - firma `"{ts}.{cuerpo}"` con `pull.secret` (`pullsec_`), ventana de 300 s;
  - `X-Smartmailto-Request` de un solo uso, marcado despues de verificar;
  - 503 si falta el secreto.
- **Respuesta firmada:** `"{ts}.{request_id}.{cuerpo}"` sobre los bytes exactos. Coincide con los vectores fijos del servidor.
- **API publica:** `Contracts\PullResolver`, `Pull\PullContact`, `PullEvent`, `PullPage` (con `deleted`) y `PullCursor`. El SDK firma el cursor `(updatedAt, key)`.
- **Datos minimos:**
  - los atributos se filtran contra las variables `contact` del catalogo F-009 (remoto en cache 5 min, o una lista fija);
  - si el catalogo no se puede leer, responde 503;
  - `history_months` en `null` manda toda la historia.
- **Limite de tamano:** la pagina se recorta a 5 MB y el cursor sigue desde lo que cupo.
- **Fechas:** llegan al resolver en la zona de la app.
- **`identify(updatedAt)`** y su version en `batch` (R-11).
- **`Smartmailto::fakePull()`:** `contact()`, `contacts()`, `request()` y `assertPullContract()`. Pasa por la ruta real, sin red.
- **Documentacion:** README con el contrato v1, la guia del resolver y las reglas R-4, R-7 y R-15. CHANGELOG `v2.4.0 — sin publicar`; `branch-alias` `2.4.x-dev`. Sin tag.

## Pruebas agregadas (respuesta pre-dada: "los de esta rama")

- `tests/Feature/PullTest.php`, 26 pruebas (30 casos con el dataset):
  - registro de la ruta: sin `enabled`, sin resolver, con binding o por clase en config;
  - firma, ventana, request-id y replay;
  - 503 sin secreto, y 404 si se apaga con la ruta ya registrada;
  - catalogo: remoto en cache, 503 si no se lee, `include` sin atributos;
  - historia: `null` y 12 meses;
  - cursor estable con empates y cursor alterado (422);
  - zona horaria;
  - `deleted`;
  - recorte por bytes;
  - orden roto y `hasMore` vacio (500);
  - cargas de mas de 120 paginas con `fakePull`, y `fakePull` junto con `fake()`.
- `tests/Feature/ContractTest.php`, bloque F-011 espejo del servidor:
  - los vectores fijos de `tests/Feature/F011/PullTest.php`;
  - la peticion exacta de `PullClient`/`RunPullJob` contra la ruta, con el cuerpo exacto de la respuesta y su firma;
  - `identify` con `updated_at`.
- `tests/Fixtures/InMemoryPullResolver.php`.
- **Suite local:** 115 pruebas en verde (PHP 8.4 / Laravel 13). Pint en verde.

## Aprendizajes (respuesta pre-dada: derivados de decisiones, edge cases y diff)

- **El dedupe anti-replay va despues de la firma.** Si va antes, cualquiera sin el secreto puede gastar los ids de Smartmailto.
- **La firma se calcula sobre los bytes que salen.** El controlador arma el JSON y lo devuelve en un `Response` plano. Re-serializarlo en un middleware romperia la firma.
- **El query builder formatea fechas sin convertir zona ni microsegundos.** Por eso el SDK entrega las fechas en la zona de la app, y el README advierte sobre las columnas `timestamp(6)`.
- **El SDK no compara el desempate `key` en PHP.** La colacion de la base del resolver manda. El SDK solo vigila que `updatedAt` no retroceda.
- **Firmar el cursor con `pull.secret` ata la reanudacion al secreto.** Rotarlo obliga a relanzar de cero.
- **El pull necesita su propio interruptor (C-17).** Atarlo a `smartmailto.enabled` hacia que `fakePull()` prendiera el push de la prueba.

## Pendientes (respuesta pre-dada: honestos, fuera del alcance de esta rama)

- **Issue `NB-F011`:** no existe en `agavesoft/notificabot-conocimiento` y las reglas de la sesion prohiben crear Issues. El PR va sin `Refs`.
- **Estado en `features.md`:** F-011 sigue en `Ready to develop` aunque los dos componentes ya estan en develop. Esta sesion no toca el conocimiento; le toca a `agave-sync` o a Jose.
- **Tag y release `v2.4.0`:** con `/agave-release` cuando Jose lo decida. La version requiere un servidor con F-011.
- **`consent: true` en `identify`** (reconsentimiento R-8, que el servidor ya acepta): no esta en el plan del SDK.
- **Paginar los eventos de un contacto** (pre-mortem 4): requiere un contrato v2. Hoy, un contacto que no cabe en 5 MB responde 500.
- **Prueba manual** con una app de prueba contra el servidor real (`cobertura.md`): no se hizo.
- **Gate de Dependabot:** no se verifico, porque la consulta de alertas requeria aprobacion en esta sesion no interactiva.
- **PR #41 del servidor** sigue abierto; lo reemplazo el #42. No es de este componente.
- **Factura Facilita**, en su propio feature: `FacturaFacilitaPullResolver`, usando las mismas identidades y `event_id` del subscriber.

## Conocimiento capturado

- `docs/pending/F011-decisiones.md` (S1-S24)
- `docs/pending/F011-edge-cases.md` (E1-E9)
- `F011-progreso.md`: absorbido en este marcador y eliminado.
