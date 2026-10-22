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

Flujos normales: `detected → active → monitoring → resolved` y `active → expired`. Los cambios de estado no eliminan la evidencia; la limpieza automática la conserva únicamente durante la ventana de 7 días.

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

La interfaz abre en “Ahora”: solo incluye estados vigentes que ya comenzaron y no tienen cierre, resolución, descarte ni `ends_at`/`expires_at` vencidos. Un anuncio futuro aparece en los periodos recientes, pero no en “Ahora”. Si no existe ninguna fecha de fin, se aplica la vigencia de su categoría (rutas: 8 horas; clima: 12 horas; accidentes, incendios y emergencias: 18 horas; demás categorías: 24 horas). La antigüedad se basa en `occurred_at`, luego `starts_at`, `published_at`, `detected_at` o `created_at`; volver a recolectar o editar una fila no la rejuvenece.

Los periodos de 1 hora, 6 horas, 24 horas y 7 días filtran por fecha de publicación e incluyen eventos finalizados. La API pública además limita la antigüedad real del evento a 7 días, incluso antes de que se ejecute la limpieza automática. El periodo “Histórico” se eliminó; solicitarlo devuelve 422.

## Precisión

`location_accuracy` admite `exact`, `street`, `neighborhood`, `city`, `district`, `department` y `approximate`. La API marca expresamente las precisiones territoriales como aproximadas. La ausencia de coordenadas es preferible a inventar un punto.

## Actualizaciones y fusión

Un mismo hecho puede acumular `incident_sources` e `incident_updates`. Una fuente adicional no crea por sí sola otro punto; las reglas deterministas consolidan los casos verificables y los casos ambiguos quedan reservados para la decisión `MERGE` del motor de moderación.

Los trabajos oficiales de ANDE se agrupan por categoría, municipio o departamento y fecha. Cada grupo usa un solo marcador; las zonas y franjas horarias adicionales permanecen en el resumen, la línea de tiempo y `raw_items`.

## Cierre y expiración

Los campos `starts_at`, `ends_at`, `expires_at`, `occurred_at`, `resolved_at`, `expired_at` y `finalized_at` son independientes. `IncidentExpirationService` revisa vencimientos por lotes durante `alertapy:cycle`; el fallo de esa revisión nunca publica contenido nuevo. Las consultas de “Ahora” aplican los límites temporales directamente, aunque el proceso de cierre todavía no se haya ejecutado. La limpieza por antigüedad se ejecuta por separado y retira los registros fuera de los 7 días.
