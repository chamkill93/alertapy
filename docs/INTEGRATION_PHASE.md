# Integración final

## Release Candidate 1

RC1 amplía las fases 1–16 sin nuevas dependencias obligatorias: modelo temporal, ciclo de vida canónico, severidad separada de verificación, freshness configurable, subcategorías, scope, geometría formal, calidad y auditoría geográfica, roles de fuentes, timeline estructurado, explicación de publicación, calidad del dato, capacidad de monitoreo y salud del scheduler.

Comandos operativos:

```bash
php artisan alertapy:status
php artisan alertapy:geo:quality --active --dry-run
php artisan alertapy:data:backfill --dry-run --limit=5000
```

## Corroboración y deduplicación

`CorroborationService` compara candidatos independientes utilizando categoría, distancia Haversine, ventana temporal, ciudad/departamento, similitud textual y códigos de ruta. La coincidencia no crea otro marcador: añade una fila a `incident_sources` y una actualización al incidente existente. Dos fuentes independientes elevan `verification_level` a `corroborated`; `trust_level` por sí solo nunca equivale a verdad.

La fuente representativa del incidente se elige por evidencia disponible (`official`, `sensor`, `press`, `community`, `social`, `discovery`), sin eliminar las URLs originales. Los reportes comunitarios continúan operando con su validación propia.

## Representaciones del mapa

La API declara `representation.type` y `representation.geometry`:

- `point`: comportamiento predeterminado.
- `cluster`: agrupación visual de puntos en Leaflet.
- `line`: únicamente con `LineString` o `MultiLineString` real.
- `polygon`: únicamente con `Polygon` o `MultiPolygon` real.
- `station`: estaciones y mediciones mediante la API separada.
- `heatmap`: capacidad declarada para datos históricos; no se usa para afirmar riesgo actual.

Si un collector solicita línea o polígono sin GeoJSON válido, Laravel lo degrada a `point`. Nunca fabrica una geometría.

## Consultas territoriales

`GET /api/incidents` acepta:

- `bbox=south,west,north,east` para consultar solo la ventana visible;
- `source=official|press|sensor|community` o el slug de una fuente;
- `near_lat`, `near_lng` y `radius_km`, donde el radio permitido es 1, 3, 5, 10 o 25 km.

La búsqueda por radio aplica primero un bounding box conservador y luego Haversine. MariaDB usa sus funciones matemáticas nativas; SQLite local registra equivalentes PHP.

Endpoints complementarios:

- `GET /api/layers`.
- `GET /api/sources`.
- `GET /api/stations`.
- `GET /api/stations/{id}`.
- `GET /api/stations/{id}/measurements`.

## Ciclo general

Hostinger puede ejecutar cada cinco minutos:

```bash
php artisan alertapy:cycle
```

El comando procesa únicamente fuentes vencidas, limita fuentes y duración por ciclo, usa bloqueo de archivo/base compatible con hosting compartido y conserva el último `source_id` como checkpoint. Variables:

- `ALERTAPY_CYCLE_MAX_SECONDS`.
- `ALERTAPY_MAX_SOURCES_PER_CYCLE`.
- `ALERTAPY_MAX_CANDIDATES_PER_CYCLE`.
- `ALERTAPY_MAX_AI_DECISIONS_PER_CYCLE`.

## Administración y cobertura

`/admin/coverage` está deshabilitado si `ALERTAPY_ADMIN_TOKEN` está vacío. Al configurarlo, exige autenticación HTTP Basic usando el token como contraseña. No expone credenciales ni errores internos.

La vista muestra:

- cobertura real de los últimos 30 días por departamento;
- fuentes oficiales, prensa nacional/regional, municipalidades y sensores;
- salud técnica `HEALTHY`, `DEGRADED`, `FAILING` o `DISABLED`;
- último intento/éxito, elementos, candidatos y errores consecutivos.

La cobertura mide evidencia observada; no inventa presencia departamental ni representa una garantía editorial.

## Estado final de fases

- **Fase 2:** parcial por dependencia externa. ANDE funciona cuando el sitio responde; el bloqueo Radware se informa como degradado y no se evade.
- **Fase 5:** operativa con `RuleBasedDecisionProvider`. OpenCode queda como proveedor opcional, deshabilitado inicialmente.
- **Fase 11:** operativa con impacto basado en evidencia. La población censal distrital es contexto y no personas afectadas; las estimaciones dependen de geometrías oficiales disponibles.
- **Fases 12 a 15:** operativas. Las capas de infraestructura y estaciones son opcionales y están apagadas inicialmente.
- **Fase 16:** implementada con zonas privadas, categorías, radios, estado de actividad y consulta Haversine optimizada.

## Integración de Fases 12 a 16

La ampliación incorpora red vial versionada, geolocalización por ruta/kilómetro/cruce, motor determinista sin dependencia de IA, monitoreo estructural de parsers, impacto territorial basado en evidencia y zonas favoritas privadas.

La red vial no se siembra con datos de prueba en producción. Debe importarse desde `/admin/roads` o `alertapy:roads:import`; la capa consulta por `bbox` y solo se dibuja cuando el usuario la activa.
