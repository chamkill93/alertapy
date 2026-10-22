# Modelo de datos RC1

Cada incidencia conserva evidencia, ubicación y tiempos sin confundir sus dimensiones.

## Clasificación

- `category_id`: categoría pública estable.
- `subcategory`: segundo nivel nullable, asignado únicamente por reglas deterministas.
- `severity`: importancia demostrada (`informational`, `low`, `moderate`/`medium` heredado, `high`, `critical`). Una fuente oficial no implica severidad crítica.
- `verification_level`: valor persistido compatible. La API normaliza a `official`, `corroborated`, `press`, `sensor`, `community` o `unverified`.

## Ciclo de vida y actualidad

`lifecycle_status` utiliza `detected`, `active`, `monitoring`, `resolved`, `expired` o `discarded`. El campo `status` anterior se conserva para compatibilidad. `IncidentLifecycleService` traduce ambos modelos de forma determinista.

Freshness no es un score persistido: `IncidentFreshnessService` lo calcula con `last_seen_at`, categoría y ciclo de vida. Sus valores son `live`, `recent`, `aging`, `stale` y `archived`.

## Alcance y geometría

`incident_scope` separa alcance (`local`, `city`, `department`, `multi_department`, `national`, `route_segment`) de severidad. `geometry_type` declara `point`, `line`, `polygon`, `multipolygon`, `route_segment`, `area_reference`, `station` o `none`; `geometry_geojson` solo contiene geometría recibida o importada, nunca inventada.

## Fuentes y evolución

`incident_sources.source_role` distingue `origin`, `primary`, `secondary`, `corroborating`, `update`, `discovery` y `community`. URL, titular, fecha y fuente se preservan.

`incident_updates` se reutiliza como timeline estructurado mediante `event_type` y `metadata_json`. No existe una segunda tabla redundante de eventos.

## Transparencia

`publication_explanation_json` guarda razones comprobables de publicación. `data_quality_json` conserva componentes explicables de fuente, ubicación, temporalidad, evidencia y freshness; no representa una probabilidad mágica de verdad.
