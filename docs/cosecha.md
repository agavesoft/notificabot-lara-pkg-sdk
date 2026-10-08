# Manifiesto de Cosecha

Registro de conocimiento pendiente de sincronizar al repo de conocimiento.

## Pendiente de cosechar

<!-- Entradas ligadas a features: F-XXX -->

<!-- Sin pendientes: F-003, F-006 y F-008 se cosecharon el 2026-10-07 (agave-sync, segunda pasada). -->

### F-009 — Catalogo de variables por proyecto y control de usos

- `docs/pending/F009-decisiones.md` (2026-10-08) — formato `variables/`, validacion solo estructural, paquete unico, metodos, excepcion, `symfony/yaml`
- `docs/pending/F009-edge-cases.md` (2026-10-08) — fechas YAML, orden de bloques, servidor sin F-009, `validate` 200 invalido, `?event=`
- `docs/pending/F009-cierre.md` (2026-10-08) — marcador de cierre del componente SDK (absorbe el progreso)

### F-011 — Sincronizacion de datos desde el proyecto (pull, carga inicial y resincronizacion)

- `docs/pending/F011-decisiones.md` (2026-10-08) — registro de la ruta, firma y anti-replay, cursor, orden, zona horaria, filtro por catalogo, historia, limite de respuesta, `identify(updatedAt)`, v2.4.0
- `docs/pending/F011-edge-cases.md` (2026-10-08) — route:cache, rotacion con corrida fallida, empates, cache del catalogo, store `array`
- `docs/pending/F011-cierre.md` (2026-10-08) — marcador de cierre del componente SDK (absorbe el progreso)

## Hallazgos sin feature

<!-- Entradas creadas por /agave-capturar (sin F-XXX asociado) -->

## Historial de cosechas

| Fecha | Commit | Tema | Origen | Procesado por |
|-------|--------|------|--------|---------------|
| 2026-10-07 | 0e933f6 | F-003 cierre del SDK + decisiones S1-S11 → ADR-008; revision de la definicion (Laravel 12-13) → F-003 Terminada | F-003 | agave-sync |
| 2026-10-07 | 0e933f6 | F-006 cierre del SDK → F-006 Terminada | F-006 | agave-sync |
| 2026-10-07 | 0e933f6 | F-008 cierre del SDK (PR #3 + follow-up B3) + decisiones + edge-cases → ADR-010/ADR-011 → F-008 Terminada | F-008 | agave-sync |
