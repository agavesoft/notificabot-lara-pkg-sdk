---
feature: F-010
tipo: edge-cases
titulo: Casos limite del outbox del SDK
fecha: 2026-10-08
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F010-eventos-garantizados-sdk
autor: salomon@agavesoft.com.mx
---

# F-010 — casos limite (SDK)

- **Rotar `APP_KEY` con filas pendientes:** el payload ya no se descifra; el worker reintenta (`error
  DecryptException`) y la alerta por antiguedad avisa. Vaciar el outbox antes de rotar (README).
- **Conexion equivocada:** si `outbox.connection` no es la de la transaccion de negocio, la fila no se
  revierte con ella (criterio 1 roto). Default = la conexion default de la app.
- **Servidor sin F-010:** responde 2xx sin acuse; las filas nunca cierran (`missing ack`) y se rinden a las
  72 h. v2.5 con outbox necesita el servidor de F-010.
- **Fila en vuelo y emergencia:** `reportExternalSend()` lanza `OutboxRowInFlight` mientras el worker espera
  la respuesta; el listener en cola debe dejar que se reintente (la fila cerrara `acked` o se cerrara
  `expired` y volvera a disparar).
- **Worker muerto a media peticion:** la fila queda `sending`; a los 10 min vuelve a `pending`. Si
  Smartmailto ya la habia aceptado, el reintento vuelve como duplicado con el mismo acuse.
- **Varios workers:** cada fila se toma con UPDATE condicionado; las alertas pueden duplicarse si dos
  workers evaluan a la vez (se recomienda `withoutOverlapping()->onOneServer()`).
- **`link` antes de que exista el contacto:** 404 se reintenta hasta 72 h; si el contacto nunca llega se
  rinde (`gave_up`).
- **`fromDisk` en un disco local:** `temporaryUrl` necesita un disco con URLs temporales (S3, o local con
  `serve`), y la URL debe ser https publica: Smartmailto solo descarga https publico (OutboundUrlGuard).
- **Adjunto inline grande con outbox:** se rechaza al llamar `send()` (dentro de la transaccion de la app),
  no en el worker.
- **Llave de envio con "/":** la consulta codifica cada segmento (`ff%3Arecibo/2026%2010/1`).
- **Todo el atraso se rinde a la vez (72 h):** el resumen y el "recuperado" salen en la misma pasada; si
  Smartmailto sigue caido, el "recuperado" solo dice que ya no hay filas atrasadas (quedaron `failed`).
