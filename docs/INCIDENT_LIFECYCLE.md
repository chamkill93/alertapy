# Ciclo de vida de una incidencia

## Estados

RC1 conserva los valores históricos de `status` por compatibilidad y expone un ciclo normalizado mediante `lifecycle_status`:

| Ciclo RC1 | Significado |
| --- | --- |
| `detected` | Detectado y todavía en procesamiento/corroboración |
| `active` | Vigente |
| `monitoring` | Relevante y en seguimiento |
| `resolved` | Resolución respaldada |
| `expired` | Vigencia terminada por política temporal |
| `discarded` | No válido para el mapa activo |

Flujos normales: `detected → active → monitoring → resolved` y `active → expired`. Ninguno elimina evidencia ni historial.

| Valor persistido | Presentación | Uso |
| --- | --- | --- |
| `scheduled` | Programado | Evento futuro anunciado por una fuente |
| `reported` | Reportado | Reporte con procedencia clara, aún no confirmado |
| `under_verification` | En verificación | Evidencia insuficiente o contradictoria |
| `confirmed` | Confirmado | Corroboración suficiente según su nivel de fuente |
| `active` | Activo | Evento vigente |
| `finalized` | Finalizado | Evento concluido con evidencia o expiración válida |
| `cancelled` | Cancelado | Evento anunciado que fue cancelado |

El estado no cambia el tipo de fuente. Una noticia de prensa puede estar activa; un reporte comunitario no pasa a oficial por recibir confirmación.

## Visibilidad

Una incidencia pública necesita como mínimo:

- `published_at` no nulo y no futuro;
- categoría válida;
- título, resumen y ubicación textual;
- tipo de fuente, estado, severidad y precisión;
- evidencia compatible con su procedencia;
- guardrails técnicos aprobados en fases posteriores.

La consulta “Ahora” incluye estados vigentes y excluye `expires_at` vencido. La interfaz abre en “7 días” para que el backfill reciente sea visible; “Ahora” sigue disponible como filtro. Histórico conserva eventos finalizados sin reactivarlos y la evidencia cruda nunca se elimina por expiración.

## Precisión

`location_accuracy` admite `exact`, `street`, `neighborhood`, `city`, `district`, `department` y `approximate`. La API marca expresamente las precisiones territoriales como aproximadas. La ausencia de coordenadas es preferible a inventar un punto.

## Actualizaciones y fusión

Un mismo hecho puede acumular `incident_sources` e `incident_updates`. Una fuente adicional no crea por sí sola otro punto; las reglas deterministas consolidan los casos verificables y los casos ambiguos quedan reservados para la decisión `MERGE` del motor de moderación.

Los trabajos oficiales de ANDE se agrupan por categoría, municipio o departamento y fecha. Cada grupo usa un solo marcador; las zonas y franjas horarias adicionales permanecen en el resumen, la línea de tiempo y `raw_items`.

## Cierre y expiración

Los campos `starts_at`, `ends_at`, `expires_at`, `occurred_at`, `resolved_at`, `expired_at` y `finalized_at` son independientes. `IncidentExpirationService` revisa vencimientos por lotes durante `alertapy:cycle`; el fallo de esa revisión nunca publica contenido nuevo y el histórico se conserva.
