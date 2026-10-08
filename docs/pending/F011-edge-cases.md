---
feature: F-011
tipo: edge-cases
titulo: Casos limite del pull en el SDK
fecha: 2026-10-08
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F011-pull-sdk
autor: salomon@agavesoft.com.mx
---

# F-011 — casos limite (SDK)

- **E1. `route:cache`.** La condicion de registro (pull prendido + resolver) queda fija en la cache de
  rutas. Prender el pull despues sin regenerar la cache no crea la ruta (404); apagarlo con la ruta en
  cache responde 404 desde el controlador.
- **E2. Rotacion del secreto con una corrida fallida.** El cursor guardado en `pull_runs` esta firmado
  con el secreto viejo: al reanudar responde 422 `invalid_cursor` (definitivo). La corrida se relanza
  de cero (los eventos son idempotentes).
- **E3. Empates de `updated_at`.** El desempate es `key`; si el resolver ordena por `updated_at` pero no
  por la misma columna de `key`, se pueden saltar o repetir contactos. `assertPullContract()` lo detecta
  (contacto repetido entre paginas).
- **E4. Precision.** El cursor guarda microsegundos; si la columna del resolver guarda segundos, la
  comparacion `updated_at = cursor` sigue funcionando porque el valor sale de la misma columna.
- **E5. Cache del catalogo.** Una variable recien creada tarda hasta `catalog_ttl` (5 min) en viajar por
  pull. Una variable borrada se sigue mandando ese tiempo; el servidor la descarta con su politica.
- **E6. Catalogo vacio.** Un proyecto sin variables de contacto manda `attributes: {}`. Es valido (no es
  "catalogo no disponible").
- **E7. Cache por request (driver `array`) en produccion.** El dedupe de request-id no protege entre
  peticiones; la firma con ventana de 300 s sigue protegiendo. Usar un store compartido
  (`SMARTMAILTO_PULL_CACHE_STORE`) en apps con varios servidores.
- **E8. `deleted` en una pagina recortada por tamano** viaja completo con la primera parte; R-15 es
  idempotente.
- **E9. Identidad con `user_id` numerico en JSON** (`{"user_id": 123}`): se acepta como string, igual que
  el servidor.
