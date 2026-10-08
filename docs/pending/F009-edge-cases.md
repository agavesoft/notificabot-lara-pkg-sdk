---
feature: F-009
tipo: edge-cases
titulo: Casos limite del SDK con el catalogo y el paquete atomico
fecha: 2026-10-08
componente: notificabot-lara-pkg-sdk
branch: feature/2026-10-NB-F009-catalogo-sdk
autor: salomon@agavesoft.com.mx
---

# F-009 — Casos limite del SDK

- **Fechas sin comillas en YAML.** Sin flags, Symfony YAML convierte `default: 2026-01-01` en un entero (timestamp Unix) y el servidor recibiria un numero. El comando parsea con `PARSE_DATETIME` y **rechaza** cualquier fecha sin comillas antes de llamar, con un mensaje que pide `'2026-01-01'`. Con comillas viaja como texto (hay prueba).
- **Orden de los bloques.** El servidor aplica los `partials` en el orden en que llegan. El comando los ordena por nombre de archivo, asi que un bloque que incluye a otro cuyo nombre va despues falla con `invalid_partial`. Pasaba igual con los PUT de F-008, asi que no es regresion. Queda documentado en el README.
- **Servidor sin F-009.** `POST /api/provision` no existe en un servidor anterior: el comando falla con `404` y no cambia nada. Los `put*()` sueltos siguen funcionando. Queda en el README y en el CHANGELOG.
- **`POST /api/validate` responde 200 aunque el paquete sea invalido.** Solo el campo `valid` lo dice. Si el SDK confiara en el estado HTTP, un CI dejaria pasar un paquete roto.
- **Variable de evento sin `?event=`.** `obsolete`, `DELETE` y `usages` de una variable con `event_name` dan 404 si no llevan `?event=`. Todas las rutas `{scope}/{key}` del SDK lo agregan.
- **Misma clave en varios eventos.** `event:orden@orden_creada` y `event:orden@orden_pagada` son variables distintas; solo se rechaza la repeticion exacta `(scope, event, key)`, aunque este en archivos distintos.
- **`event: null` explicito** (por ejemplo, en JSON) se quita del cuerpo: es la variable comun.
- **El fake con el comando.** `smartmailto:provision` con `Smartmailto::fake()` registra el paquete en `$fake->packages` y no llama (prueba para la app cliente).
