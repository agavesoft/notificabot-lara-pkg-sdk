---
feature: F-010
tipo: decisiones
titulo: Decisiones del SDK para eventos garantizados (outbox, emergencia sin duplicados y alertas agrupadas)
fecha: 2026-10-08
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F010-eventos-garantizados-sdk
autor: salomon@agavesoft.com.mx
---

# F-010 — decisiones tecnicas (SDK)

Sesion no interactiva autorizada por Jose (2026-10-08 01:02). Aplica el archivo de respuestas: el plan del
SDK de `analisis.md` (plan 2, puntos 1-8 con 3b, 3c, 6b-6e) se aprobo sin preguntar `(respuesta pre-dada)`
y las decisiones tecnicas dentro del alcance se toman con "la opcion mas simple y segura, consistente con
las decisiones del feature" `(respuesta pre-dada)`. El servidor ya estaba en develop (PRs #46-#63 de
`notificabot-lara-smartmailto`, incluido #63, alertas agrupadas y estado cerrado de la consulta). El
contrato se tomo de `docs/api-envio-y-aprovisionamiento.md` §2b del servidor, de `IngestionResult`,
`ExternalSendReport`, `ContactApiController::link` y `AttachmentStore`. Precisiones de Claude del analisis
se implementan tal cual (decision de Jose 2026-10-08 14:02).

## Contrato: donde el servidor manda sobre el analisis

- **S1. Heartbeat por `POST /api/outbox/heartbeat`**, no `GET` (D11 del servidor: un GET responde 405).
  `(respuesta pre-dada)`
- **S2. Sin header `X-Smartmailto-Outbox`.** El servidor no lo implementa; el SDK no lo manda. El
  `send_before` obligatorio lo exige el SDK (S9). `(respuesta pre-dada)`
- **S3. Acuse por tipo** (D20 del servidor): `track` cierra solo con `ack: true` y su `event_id`; `send` y
  `external_report` con `ack: true` y su `idempotency_key`; `link` con su `link_id`; `identify` (el servidor
  responde el contacto, sin campos de acuse) con cualquier 2xx. Una respuesta 2xx sin acuse (servidor sin
  F-010) se reintenta con `last_error = missing ack`: v2.5 con outbox requiere un servidor con F-010.
  `(respuesta pre-dada)`
- **S4. La consulta `GET /api/send/{key}` codifica cada segmento y conserva `/`** (la llave puede llevar
  "/", D83d del servidor; un `%2F` lo rechazan muchos servidores web). `(respuesta pre-dada)`
- **S5. Estado de la consulta → resultado** (contrato §2b, conjunto cerrado de D89): `queued`, `sending`,
  `sent`, `sent_externally`, `duplicate_external`, `suppressed` y `skipped` → la fila queda `acked` (con
  `ack.status`) y no hay emergencia; `expired`, `failed`, `not_found` (404) o sin respuesta → la fila se
  cierra y se dispara `SmartmailtoOutboxFailed`. `suppressed`/`skipped` quedan `acked` porque Smartmailto
  ya decidio no mandarlo a proposito; mandarlo directo repetiria un rebote/queja. `(respuesta pre-dada)`

## Clasificacion de respuestas en el worker

- **S6. Se reintenta, nunca falla la fila:** red, 5xx, 429 (con `Retry-After`), 408, **401/403** y el SDK sin
  configurar (estado 0). Un token rotado es problema de la app, no de la fila: si fallara, cada `send`
  dispararia una emergencia. Las alertas por antiguedad avisan. `(respuesta pre-dada)`
- **S7. Rechazo definitivo** (resto de 4xx: 422, 404 `template_not_found`, 413): `failed` con
  `reason` = estado HTTP (`"422"`, `"404"`), alerta agrupada por plantilla y evento. El analisis dice
  `reason=422|expired`; se generaliza al estado HTTP porque el servidor guarda el motivo libre
  (`emergency:{reason}`). `(respuesta pre-dada)`
- **S8. Excepciones por tipo:** `send` 410 → `expired`; `external_report` 409 (`send_in_flight`, `retry`) →
  reintento (D79 del servidor); `link` 404 `contact_not_found` → reintento (el contacto puede venir en una
  fila anterior del outbox aun pendiente) y 409 `contact_changed` → reintento; los demas 409 de `link`
  (`ambiguous_email`, `link_id_conflict`, `both_have_user_id`) son definitivos. `(respuesta pre-dada)`
- **S8b. Antes de cerrar cualquier `send` se consulta** (tambien en 422 y 410), literal de la decision de
  Jose 11:50 ("antes de cualquier emergencia, si Smartmailto responde, consulta"). En 422/410 la consulta
  normalmente responde `not_found`; cuesta un GET. `(respuesta pre-dada)`

## Outbox

- **S9. `send()` con outbox exige `sendBefore`** (InvalidArgumentException) en vez de un default: la regla
  17 dice "toda fila `send` lleva `send_before` obligatorio" y un default oculto (p. ej. 72 h) dejaria a
  la app sin pensar cuando procede su emergencia. Sin outbox sigue opcional. `(respuesta pre-dada)`
- **S10. Escritura con `insertOrIgnore` y cifrado manual** (`encrypter->encryptString`, APP_KEY): un choque
  de la llave unica `(kind, key)` dentro de la transaccion del llamador no la aborta (en PostgreSQL un
  error de unicidad invalida la transaccion entera). Llave > 191 → InvalidArgumentException antes de
  escribir. `(respuesta pre-dada)`
- **S11. `identify` lleva una llave por llamada** (`identify:{ulid}`), no una derivada del contenido como
  proponia el analisis (C3): con llave por contenido, A → B → A descartaria la tercera mientras la primera
  sigue en la tabla y el contacto quedaria en B. `identify` es idempotente en el servidor, asi que
  repetirlo no duplica nada. Ademas, con outbox, `identify` manda `updated_at` (hora de la llamada) para
  que el servidor se quede con el mas reciente aunque lleguen fuera de orden (R-11 de F-011).
  `(respuesta pre-dada)`
- **S12. `track` sin `occurredAt` guarda la hora del commit** (J4): sin esto, una caida de 2 h movia el
  ancla de todos los workflows a la hora de llegada. Se agrega al escribir la fila, no en `trackBody`, para
  que el cuerpo sin outbox (y el del fake) no cambie. `(respuesta pre-dada)`
- **S13. Transiciones con UPDATE condicionado al estado** (`WHERE id = ? AND status = ?`), no solo
  `lockForUpdate`: SQLite lo ignora y dos workers podrian tomar la misma fila. pending → sending (claim);
  sending → acked/failed/expired/pending (al volver, solo si sigue `sending`); pending/failed/expired →
  superseded (reporte de emergencia, con la fila bloqueada y exigiendo el estado leido). Regla 17.5.
  `(respuesta pre-dada)`
- **S14. Filas `sending` de mas de 10 min vuelven a `pending`** (`outbox.sending_timeout`): un worker que
  murio a media peticion dejaria la fila sin reintento y sin poder marcarse `superseded`.
  `(respuesta pre-dada)`
- **S15. Un `send` vencido se cierra aunque su siguiente intento sea despues**, y el backoff de un `send`
  se recorta a su `send_before`: una espera de 1 h no esconde un plazo de 10 min. `(respuesta pre-dada)`
- **S16. Rendirse a las 72 h cuenta desde el primer intento** (`first_attempt_at`), y
  `smartmailto:outbox:retry` lo reinicia; asi una fila reprocesada no se rinde de inmediato. Una fila que
  nunca se intento (worker muerto) no se rinde: la cubre la alerta por antiguedad y el heartbeat del
  servidor. `(respuesta pre-dada)`
- **S17. `SmartmailtoOutboxFailed` se dispara despues del UPDATE**, para todos los tipos (con `kind`); solo
  `kind = send` pide emergencia (`needsEmergencySend()`). Para track/identify/link/external_report no hay
  correo que mandar (precision de Claude del analisis sobre las 72 h). `(respuesta pre-dada)`
- **S18. `reportExternalSend()`** acepta una fila `pending`, `failed` o `expired` (→ `superseded`), una
  `acked` (solo agrega el reporte: caso del webhook `send_expired`, cuando Smartmailto ya lo habia
  aceptado) y ninguna fila (exige identidad y plantilla). En `sending` lanza `OutboxRowInFlight`
  (reintentable). Reportar dos veces es idempotente por `(external_report, key)`. Sin outbox, encola
  `POST /api/send/external` como cualquier llamada. `(respuesta pre-dada)`
- **S19. `batch()` no usa el outbox:** `/api/batch` no acepta `send` y la carga inicial se puede repetir sin
  riesgo (idempotente por `event_id`). `(respuesta pre-dada)`
- **S20. Purga ARCO del outbox (`prune --contact`) descifra fila por fila**: el payload va cifrado y no se
  agregaron columnas de hash de identidad; el outbox es chico (lo pendiente y 7-30 dias). Borra en
  cualquier estado salvo `sending`; busca en la identidad, `survivor`, `absorbed` y `origin`.
  `(respuesta pre-dada)`
- **S21. Migracion solo publicada** (`publishesMigrations`, tag `smartmailto-migrations`), no cargada sola:
  una app sin outbox no recibe tablas que no usa. Usa `outbox.connection` (la de las transacciones de
  negocio). Columnas extra respecto al analisis: `template` (agrupar rechazos sin descifrar), `reason`
  (motivo del cierre) y en el estado de alertas `opened_at`, `final_sent_at` y `context` (como
  `project_alert_states` del servidor, D84). `(respuesta pre-dada)`

## Alertas

- **S22. Semantica del servidor (D84-D87):** el escalon solo avanza si el aviso salio por algun canal; si
  el worker se brinco escalones sale un solo aviso; los escalones cuentan desde que empezo el atraso (la
  fila pendiente mas vieja); "recuperado" solo si el atraso llego a avisar, y el siguiente atraso empieza
  de cero aunque el "recuperado" no saliera. Sin canales configurados (sin `mail_to` ni Teams) el aviso solo
  va al log y cuenta como enviado. `(respuesta pre-dada)`
- **S23. "La cola se vacia" = no queda ninguna fila `pending`/`sending` de mas de `alert_after`.** Una fila
  de segundos no esta atrasada; con trafico continuo la cola literal nunca se vaciaria. `(respuesta pre-dada)`
- **S24. Resumen unico de las 72 h:** se manda una vez por atraso, con las filas `failed` por `gave_up`
  desde que empezo; si todo el atraso se rindio a la vez, sale el resumen y despues el "recuperado" en la
  misma pasada. `(respuesta pre-dada)`
- **S25. 422 por plantilla:** los rechazos de una pasada se juntan y salen al terminarla (inmediato); despues
  se acumulan hasta que cierra la ventana de 15 min. Para filas sin plantilla el grupo es el tipo
  (`rejected:track`). `(respuesta pre-dada)`
- **S26. Correo con el mailer de la app (`mail.manager`, texto plano) y Teams con la tarjeta adaptable del
  servidor (`OpsAlerter`).** Sin datos personales: conteos, edades, llaves, codigos de error.
  `(respuesta pre-dada)`
- **S27. Estado del heartbeat en la tabla de estado de alertas** (fila `heartbeat`), no en cache: con cache
  `array` cada corrida del scheduler mandaria el aviso. `(respuesta pre-dada)`

## Adjuntos, identidad y version

- **S28. Adjuntos:** con outbox, un inline > `inline_max_bytes` (1 MB) se rechaza al llamar `send()` y pide
  `fromDisk`/`fromUrl`. `fromDisk` guarda la referencia `{disk, path, sha256}` en la fila y genera una URL
  firmada nueva en cada intento (24 h); `fromUrl` exige https y sha256. El cuerpo del contrato es
  `{filename, content_type, url, sha256}` (sin `expires_at`: el servidor no lo lee). Tope por archivo 15 MB
  (`url_max_bytes`, D71 del servidor). `(respuesta pre-dada)`
- **S29. `Identity::external($email)`** en vez de un parametro `recipientKind`: un externo no puede ser
  identidad de `track`, `identify` ni `link`, ni `origin` (InvalidArgumentException). `send(..., origin:)`
  manda `{user_id|email}`. `(respuesta pre-dada)`
- **S30. `identify(..., consent: true)`** manda `consent: true` solo si se da (el cuerpo sin consent no
  cambia). `(respuesta pre-dada)`
- **S31. v2.5.0 sin publicar** (sin tag ni release), `branch-alias` `2.5.x-dev`. Se declaran
  `illuminate/database`, `illuminate/encryption`, `illuminate/filesystem` e `illuminate/mail` (Testbench
  escondia su ausencia). `(respuesta pre-dada)`
- **S33. Ajustes del `/code-review`:** (a) `prune --contact` compara el `user_id` tal cual (solo el correo
  en minusculas), busca tambien en `to`/`cc`/`bcc`, salta filas que no se descifran y reporta las en vuelo
  y las ilegibles (sale con codigo 1) en vez de abortar. (b) La evaluacion de alertas corre bajo un
  candado de cache (`smartmailto:outbox:alerts`) si el store lo soporta: con varios workers no salen
  avisos dobles. (c) El estado de alertas se guarda con `updateOrInsert` y, si dos workers crean la misma
  fila, el segundo actualiza; el conteo de rechazos es atomico (`increment`/`decrement`). (d)
  `outbox:retry` nunca reintenta un `send` failed: ya disparo la emergencia y reintentarlo podria mandar
  el correo dos veces. (e) `fromDisk` falla al llamar si el disco no da URLs temporales. (f) Las
  estadisticas del worker cuentan como acuse un cierre que la consulta resolvio. Se queda como decision:
  `batch()` sigue por la cola (S19) y el default de `alerts.mail_to` (S32). `(respuesta pre-dada)`
- **S32. `alerts.mail_to` default `soporte@agavesoft.com.mx`** como dice el analisis (J2), aunque el paquete
  es publico (MIT): otra app debe cambiarlo. Queda como pregunta para Jose. `(respuesta pre-dada)`
