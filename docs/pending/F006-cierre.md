---
feature: F-006
tipo: cierre
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F006-privacidad
branch-cierre: feature/2026-10-NB-F006-privacidad-cierre
fecha-cierre-componente: 2026-10-07
autor: seoane81@gmail.com
origen: agave-cerrar
issue-id: NB-F006
issue-numero: '15'
pr-feature: '#5'
---

# Cierre de F-006 en notificabot-lara-pkg-sdk

Cierre retroactivo: el codigo del feature ya estaba en `develop` (PR #5, squash `0a24b04`) sin marcador de cierre. Esta rama solo agrega el marcador; no toca codigo.

## Que hizo F-006 en este componente

- `Smartmailto::contact(Identity)`: consulta sincrona de lo que Smartmailto guarda de una persona (correo, atributos, eventos y envios) via `GET contacts?user_id=` o `?email=`. 404 → `null`.
- `Smartmailto::forget(Identity)`: borrado ARCO (cancelacion) via `DELETE contacts?...`. `true` si existia, `false` en 404. Smartmailto conserva solo el hash de la baja.
- `Smartmailto::renderedEmail($sendId)`: el correo enviado re-generado (`GET sends/{id}/render`), con las ligas de un solo uso ocultas.
- Busqueda: con `Identity::user` se busca por `user_id` (el correo de una cuenta puede no ser unico); con `Identity::guest`, por `email`.
- `SmartmailtoClient::delete()`; el cliente solo manda cuerpo en `post` (antes lo mandaba en todo lo que no fuera `get`).
- `SmartmailtoFake`: `contact()` devuelve `null` y `forget()` registra en `$forgotten`.
- Con `SMARTMAILTO_ENABLED=false` las tres devuelven `null`/`false` sin llamar.
- README (seccion Privacidad), facade y CHANGELOG (v2.2.0).

## Pruebas

- Del PR #5 (respuesta pre-dada): dos casos en `tests/Feature/ContractTest.php` — `contact` por id y `forget` por correo (verifica `DELETE` y URL exacta), y persona inexistente (404 → `null` / `false`). Esta rama no agrega pruebas.
- Safety net local al cerrar (develop @ `d94a143`, Herd PHP 8.4): 27 pruebas, 48 aserciones, en verde.

## Aprendizajes absorbidos (respuesta pre-dada)

No hay `docs/pending/F006-*` (ni progreso ni decisiones). Del PR y del diff:

- La consulta y el borrado se resuelven por `user_id` cuando existe, no por correo: en el servidor el correo de una cuenta no es llave unica.
- Las operaciones de privacidad son sincronas (no pasan por la cola `afterCommit`): el que atiende la solicitud ARCO necesita la respuesta en ese momento.

## Pendientes / follow-ups (respuesta pre-dada, auditoria 2026-10-07)

1. **`forget()` con `user_id` no borra invitados con el mismo correo.** Con `Identity::user($id, $email)` solo se manda `user_id`; si la misma persona tambien existe como invitado (`Identity::guest($email)`), ese registro queda. Para un borrado ARCO completo hoy la app tendria que llamar dos veces (por id y por correo). Decidir si lo resuelve el SDK o el servidor.
2. **409 `ambiguous_email` sin manejo.** Si el servidor responde 409 (correo ambiguo al buscar por `email`), `contact()`/`forget()` lo propagan como `SmartmailtoException` cruda; no hay tipo ni documentacion para que la app lo distinga.
3. **`renderedEmail()` no maneja 404.** A diferencia de `contact()`, un envio inexistente lanza `SmartmailtoException` en vez de devolver `null`, aunque la firma es `?array`. Ademas `SmartmailtoFake` no lo sobreescribe: con `Smartmailto::fake()` la llamada sale a la red con el cliente real.
4. **v2.2.0 sin publicar.** Esta en `develop` y en el CHANGELOG, pero no hay tag ni version en Packagist (que tampoco tiene dado de alta el paquete, ver `F003-cierre.md`). Bloquea a FacturaFacilita hasta que Jose publique (PR `develop → main` + tag `v2.2.0`) o FF use el repositorio VCS con `2.2.x-dev`.

## Componentes del feature

Intake: `notificabot-lara-mailflow` (ya cerro) y `notificabot-lara-pkg-sdk` (este). Con este marcador cierran todos los componentes declarados; el paso a `Terminada` lo hace `/agave-sync`.
