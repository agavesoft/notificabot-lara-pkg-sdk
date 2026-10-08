---
feature: F-011
tipo: decisiones
titulo: Decisiones del SDK para el pull (ruta, firma, resolver, cursor, catalogo e historia)
fecha: 2026-10-08
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F011-pull-sdk
autor: salomon@agavesoft.com.mx
---

# F-011 — decisiones tecnicas (SDK)

Sesion no interactiva autorizada por Jose (2026-10-08 01:02). Aplica el archivo de respuestas: el plan
por componente de `dimensiones/tecnica.md` se aprobo sin preguntar `(respuesta pre-dada)` y las
decisiones tecnicas dentro del alcance se toman con "la opcion mas simple y segura, consistente con las
decisiones del feature" `(respuesta pre-dada)`. El servidor ya estaba en develop (PRs #40, #42, #43 y #44
de `notificabot-lara-mailflow`); el contrato se tomo de `App\Services\Pull\PullSignature`, `PullClient`,
`PullProcessor`, `RunPullJob` y `PullContactJob`.

## Ruta y registro

- **S1. Registro en `booted()`.** Asi se ven los bindings de `Contracts\PullResolver` que la app haga en
  sus providers. La ruta solo se registra con `smartmailto.pull.enabled` y un resolver (ver S21) (`pull.resolver` que implemente la interfaz, o un binding). Si las rutas estan en cache
  (`routesAreCached()`) no se registra: la cache ya la tiene, o no. El controlador repite la revision
  y responde 404 si el pull se apago con la ruta ya registrada. `(respuesta pre-dada)`
- **S2. `PullRoute::register()` es idempotente y se puede volver a llamar.** La usan `fakePull()` y las
  pruebas, porque la config de una prueba se fija despues del arranque.
- **S3. Prefijo default `api`, fuera del grupo `web`** (sin CSRF). Nombre `smartmailto.pull`; throttle
  `120,1` (R-18: 60 + 60 por minuto de Smartmailto); middleware extra configurable (FF antepone su
  interruptor).

## Firma y anti-replay

- **S4. Una sola verificacion de la peticion:** el middleware usa `Smartmailto::verifyWebhook($request,
  $pullSecret, $tolerance)`, siempre con el secreto del pull explicito (el default de `verifyWebhook` es
  el secreto del webhook). Respuesta en `Pull\PullSignature`, espejo de la clase del servidor, con los
  vectores fijos del servidor en `ContractTest`.
- **S5. Dedupe de `X-Smartmailto-Request` despues de verificar la firma**, con `Cache::add` (atomico) y
  TTL del doble de la ventana (600 s). Si se marcara antes, cualquiera sin el secreto podria gastar ids.
  Un id repetido responde 401 `replayed_request` (Smartmailto nunca repite un id: genera uno por intento).
  Un request-id ausente o con caracteres fuera de `[A-Za-z0-9-]` (max 100) es 401.
- **S6. Sin `pull.secret`: 503 + `report()`.** Es error de configuracion de la app; con 5xx el servidor
  reintenta. Difiere del webhook de falla (500) solo en el codigo; ambos son 5xx.
- **S7. Solo la respuesta 200 va firmada**, sobre los bytes exactos: el controlador arma el JSON y lo
  devuelve en un `Response` plano (ningun middleware lo re-serializa). Los errores no se firman: el
  servidor clasifica por estado antes de verificar.

## Resolver, cursor y orden

- **S8. Interfaz `Contracts\PullResolver` tal cual `tecnica.md`** (`contact(Identity, ?eventsSince)`,
  `contacts(?updatedSince, ?PullCursor, limit): PullPage`). `PullContact` lleva `key` opcional para el
  desempate del cursor; sin el se usa user_id o correo. `PullPage` acepta `hasMore` (null = pagina
  llena) y `deleted` (lista de `Identity`, R-15).
- **S9. Cursor opaco** = base64url de `{u: updated_at UTC con microsegundos, k: key con su tipo}` + HMAC
  con `pull.secret`. Un cursor alterado o de otro secreto es 422 `invalid_cursor`. **Consecuencia:**
  rotar el secreto invalida el cursor guardado de una corrida fallida; hay que relanzarla de cero.
- **S10. El SDK solo valida que `updatedAt` no retroceda** (dentro de la pagina y contra el cursor); si
  retrocede responde 500 (falla en voz alta en vez de saltarse contactos). El desempate por `key` no se
  compara en PHP: lo hace el resolver en su base, con su colacion (un correo o un id numerico como texto
  se ordenarian distinto en PHP y daria falsos positivos). `assertPullContract()` si revisa que ningun
  contacto se repita entre paginas.
- **S11. Fechas al resolver en la zona de la app** (`date_default_timezone_get()`, que Laravel fija con
  `app.timezone`): el query builder formatea las fechas sin convertir zona; con UTC un resolver en
  `America/Mexico_City` compararia 6 h corrido. Las fechas de salida van en UTC con `Z`.
- **S12. `limit`** default 200, topado en `pull.max_limit` (500); un limite no entero o < 1 es 422.

## Datos minimos e historia

- **S13. Filtro por catalogo (resuelve el `VERIFICAR` de tecnica.md):** las llaves permitidas son las
  variables `contact` del catalogo del proyecto, leidas con `Smartmailto::variables('contact')` (GET
  `/api/variables`, solo token) y guardadas en cache `catalog_ttl` (300 s); o una lista fija en
  `smartmailto.pull.catalog` (sin red). Si el catalogo no se puede leer: **503** `catalog_unavailable`.
  Nunca se mandan atributos sin filtrar ni se descartan todos en silencio. El servidor tambien filtra
  con la politica `reject` (defensa en profundidad). `(respuesta pre-dada)`
- **S14. Solo se filtran atributos.** Las `properties` de los eventos viajan tal cual: el criterio 8 habla
  de atributos y el servidor trata las llaves de evento sin catalogar por el camino de `backfill`.
- **S15. `include`** se respeta: sin `attributes` no se consulta el catalogo ni viajan atributos (la
  prueba de conexion del servidor manda solo `attributes`); sin `events` no viajan eventos. Sin `include`
  se mandan ambos.
- **S16. `history_months` (null = toda la historia, D5):** en `contact` el resolver recibe
  `eventsSince = max(events_since del servidor, horizonte)`; en `contacts` (la interfaz no recibe
  horizonte) el SDK filtra los eventos por `occurred_at` despues del resolver.

## Limite de respuesta

- **S17. Pagina recortada a `pull.max_response_bytes`** (5 000 000, bajo los 5 MiB del servidor, con
  1 KB de margen): se codifica cada contacto, se conservan los que caben y el cursor sigue desde el
  ultimo que cupo. Un solo contacto que no cabe responde 500 con mensaje para bajar `history_months`.
  El paginado de eventos por contacto del pre-mortem 4 **no** se implementa: el contrato v1 no tiene
  campos para eso y el servidor no los consume (pendiente).

## Push y version

- **S18. `identify(..., updatedAt:)` y `batch()->identify(..., updatedAt)`** (item 6 del plan del SDK,
  R-11): manda `updated_at` solo si se da; el cuerpo sin el es identico al de antes. El servidor ya lo
  acepta (D15 del servidor). `consent: true` (reconsentimiento, R-8) **no** se agrega: no esta en el
  plan del SDK (pendiente).
- **S19. Version v2.4.0** (no v2.3.0 como dice `tecnica.md`): F-009 ya ocupo v2.3.0 sin publicar.
  `branch-alias` → `2.4.x-dev`. Sin tag.
- **S20. `fakePull()`** pasa por el kernel HTTP de la app con peticiones firmadas como el servidor y
  verifica la firma de cada respuesta. Con `catalog` no sale a la red; sin el, junto con
  `Smartmailto::fake()`, usa `variablesResponse`.

## Ajustes del /code-review

- **S21. El pull no depende de `smartmailto.enabled`** (interruptor del push): C-17 le da su propio
  interruptor y asi `fakePull()` no prende el push de la prueba como efecto secundario. Con el push
  apagado el catalogo remoto no se lee y la ruta responde 503 (salvo `pull.catalog` fijo).
- **S22. Throttle antes de la firma:** las peticiones con firma invalida tambien consumen el cupo.
- **S23. `fakePull()` usa una IP de prueba por peticion:** recorrer una carga de mas de 120 paginas no
  choca con el throttle `120,1` de la ruta real.
- **S24. `hasMore: true` con pagina vacia → 500** (el cursor no puede avanzar; un `next_cursor: null`
  terminaria la corrida en silencio). Una lista `deleted` de mas de la mitad del presupuesto de bytes →
  500 con mensaje propio.
- **No aplicado (documentado en README):** el query builder formatea fechas sin microsegundos (columnas
  `timestamp(6)` deben comparar con `format('Y-m-d H:i:s.u')`); `contacts()` no recibe el horizonte de
  historia (la interfaz es la de `tecnica.md`; el resolver puede leer `pull.history_months`);
  `identify(updatedAt)` usa `DATE_ATOM` con la zona de quien llama, igual que `occurred_at` (el servidor
  la parsea); una cache caida responde 500 (transitorio, el servidor reintenta).
