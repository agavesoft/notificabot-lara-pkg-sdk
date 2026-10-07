---
feature: F-008
tipo: edge-cases
titulo: SDK — adjuntos en cola, provision entre sistemas y webhook mal configurado
fecha: 2026-10-07
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F008-envio-completo-ff
autor: seoane81@gmail.com
---

# Edge cases de F-008 (follow-up B3) en el SDK

- **Archivo temporal borrado antes de que corra el job:** cubierto porque el contenido se lee y codifica en `send()` (prueba: se borra el archivo despues de `send()` y el job ya lleva el base64).
- **SQS con adjuntos:** el payload del job rebasa 256 KB con cualquier PDF real. No se resuelve en el SDK; README indica usar `database`/`redis` o `SMARTMAILTO_QUEUE=false` para esos envios.
- **Adjuntos en claro en la cola:** con la cola `database` el base64 queda en la tabla `jobs` hasta que corre el job (el servidor si los cifra en reposo). Aceptado y documentado: son archivos que la app ya tiene.
- **Limite del servidor distinto al default:** si el servidor sube `MAILFLOW_ATTACHMENTS_MAX_BYTES` hay que subir `SMARTMAILTO_ATTACHMENTS_MAX_BYTES`; si lo baja, el SDK deja pasar y el servidor responde 413 `attachments_too_large` (rechazo, sin reintento, dispara `SmartmailtoDeliveryFailed`).
- **Mismo nombre con dos extensiones** (`templates/a.md` y `templates/a.html`): error antes de llamar; si no, el ultimo pisaria al primero en cada deploy.
- **CRLF/BOM** de un checkout en Windows: normalizados; sin esto el `PUT` nunca responde `unchanged` y cada deploy crea una version de plantilla.
- **`409 busy` / `409 ambiguous_name` en provision:** se reportan como rechazo y el comando se detiene; volver a correrlo es seguro (idempotente).
- **Webhook sin secreto en la app:** el middleware responde 500 (Smartmailto reintenta) en vez de 401 (definitivo).
- **Timestamp futuro** en el webhook (reloj adelantado o replay preparado): rechazado por la ventana en valor absoluto.
