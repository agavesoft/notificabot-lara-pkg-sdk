---
feature: F-008
tipo: cierre
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F008-envio-completo-ff
fecha-cierre-componente: 2026-10-07
autor: seoane81@gmail.com
origen: agave-cerrar
issue-id: NB-F008
issue-numero: '14'
pr-feature: '#3'
alertas-dependabot-abiertas: sin-verificar
---

# Cierre de F-008 en notificabot-lara-pkg-sdk

Este marcador cierra el componente para **F-008 completo**: el PR original #3 (`sendBefore` y `health()`, squash `68c0a3e`, que nunca tuvo cierre) y el follow-up B3 de esta rama (`feature/2026-10-NB-F008-envio-completo-ff`) para que Factura Facilita mande todos sus correos por Smartmailto.

## Que hizo F-008 en este componente

### PR #3 (v2.1.0)
- `send(..., sendBefore:)`: manda `send_before`; si el servidor responde 410 (`expired`) no se reintenta y se dispara `SmartmailtoDeliveryFailed` para que la app lo mande directo.
- `Smartmailto::health()`: `ok | degraded | down` del proyecto, para la bandera de emergencia de la app.

### Follow-up B3 (esta rama, v2.2.0 sin publicar)
- `send()` acepta con nombre `attachments`, `to`, `cc`, `bcc`, `replyTo`, `from` y `secrets`, sin romper la firma (parametros opcionales al final; lo vacio no viaja). Clase `Attachment` (`fromPath`, `fromData`, `fromUpload`; tambien `UploadedFile` o ruta directa): base64, `content_type` por extension con la tabla del servidor, firma del archivo, nombre simple; limites (10 archivos, 7 MB decodificados, 10 destinatarios por lista) validados antes de encolar y configurables (`smartmailto.attachments`).
- Aprovisionamiento: `templates()`, `putTemplate()`, `putPartial()`, `putWorkflow()` (sincronos, solo mandan los campos que se pasan) y `php artisan smartmailto:provision {path} {--dry-run}` (partials → templates → workflows, frontmatter plano, CRLF/BOM normalizados, se detiene en el primer rechazo; workflows quedan inactivos por diseno del servidor).
- Webhook de falla: `Smartmailto::verifyWebhook($request, $secret?, 300)` (HMAC `sha256` de `"{timestamp}.{cuerpo}"`, `hash_equals`, ventana anti-replay en ambos sentidos) y middleware `smartmailto.webhook` (401 firma invalida; 500 sin secreto para que Smartmailto reintente); config `smartmailto.webhook_secret` (`SMARTMAILTO_WEBHOOK_SECRET`).
- `SmartmailtoFake`: aprovisionamiento registrado sin red (`assertProvisioned()`), `renderedEmail()` sin red.
- `composer.json`: `extra.branch-alias` `dev-develop` → `2.2.x-dev`; `illuminate/console` declarado. README (adjuntos, cc/bcc, reply-to, aprovisionamiento, webhook) y CHANGELOG v2.2.0.

Contrato replicado de `notificabot-lara-mailflow` develop (PR #31 webhook firmado, PR #32 envio completo y aprovisionamiento; `docs/api-envio-y-aprovisionamiento.md`, runbook 2.6/2.7).

## Pruebas (respuesta pre-dada: las de esta rama)

- `tests/Feature/ContractTest.php`: cuerpo exacto de `send()` sin opciones (compatibilidad) y con todo B3; adjuntos por ruta/upload; rechazos locales (tamano, cantidad, tipo, nombre, firma, destinatarios); `send()` apagado no lee adjuntos; contrato de `templates`/`putTemplate`/`putPartial`/`putWorkflow`; 403 `provisioning_disabled` sin reintento.
- `tests/Feature/ProvisionCommandTest.php`: dry-run sin HTTP, corrida real en orden con cuerpos exactos (CRLF/BOM), alto en el primer rechazo, archivos invalidos (dataset), frontmatter vacio, job encolado con base64 aunque se borre el archivo, fake sin red.
- `tests/Feature/WebhookTest.php`: firma valida, firma mala / cuerpo alterado / secreto vacio, timestamp viejo y futuro, secreto desde config, middleware 200/401/500 y ventana no numerica.
- Local (Herd PHP 8.4): 56 pruebas, 149 aserciones, en verde; pint limpio. `/code-review` del diff aplicado (ver decisiones 15).

## Aprendizajes absorbidos (respuesta pre-dada: de decisiones, edge-cases y diff)

- Un 4xx del receptor del webhook es definitivo para Smartmailto: un receptor mal configurado debe responder 5xx, no 401, o los `send_expired` se pierden en silencio.
- Los adjuntos se codifican al llamar `send()`: el job no depende del archivo temporal, a cambio de un payload de cola ~1.37x sin cifrar (SQS no sirve para envios con adjuntos).
- Normalizar CRLF/BOM es parte de la idempotencia del aprovisionamiento: sin eso cada deploy desde otro sistema crea una version de plantilla.
- El cliente debe replicar las validaciones baratas del servidor (tipo, firma, limites) para fallar en la peticion de la app y no en un job horas despues.

Detalle: `F008-decisiones.md` (15 decisiones) y `F008-edge-cases.md`. `F008-progreso.md` absorbido aqui y eliminado.

## Pendientes / fuera de alcance (respuesta pre-dada)

1. **Publicar v2.2.0** (PR `develop → main` + tag + alta/refresh en Packagist): decision de Jose. Mientras, FF puede requerir `^2.2@dev` desde el repo VCS gracias al `branch-alias`.
2. **Sin guarda de payload de cola** para drivers con limite (SQS): documentado, no implementado.
3. **Contrato espejo en el servidor:** las pruebas de B3 aqui reflejan `tests/Feature/F008/*` del servidor; un cambio futuro del contrato debe tocar ambos.
4. **Pendientes de F-006 que siguen abiertos** (`F006-cierre.md` 1, 2 y la parte del cliente real de 3): no se tocaron aqui.
5. **Gate Dependabot no verificado** en este cierre (la consulta requeria aprobacion en la sesion no interactiva).
6. **Integracion en FF** (usar adjuntos, provision y webhook en `facturafacilita-api`): trabajo del lado de FF.

## Componentes del feature

Intake: `notificabot-lara-mailflow` (cerro 2026-10-07, ver `cierres/notificabot-lara-mailflow.md`; sus follow-ups B2/B3 mergeados en #31/#32) y `notificabot-lara-pkg-sdk` (este). Con este marcador cierran todos los componentes declarados; el paso a `Terminada` lo hace `/agave-sync`.
