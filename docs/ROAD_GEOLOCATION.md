# Geolocalización vial

La red se importa desde archivos GeoJSON/JSON, KML, KMZ o CSV mediante `/admin/roads` o `alertapy:roads:import`; la procedencia y fecha quedan en `road_datasets`. Las progresivas oficiales se leen de propiedades `kilometer`/`km` asociadas a puntos del dataset y se guardan en `road_reference_points`. Un SHP debe convertirse previamente a GeoJSON o KML.

La resolución respeta esta prioridad: coordenadas nativas; hito kilométrico oficial; interpolación entre dos referencias sobre la geometría real; cruce de rutas; ruta y localidad; geocodificación territorial existente. Mencionar únicamente una ruta no produce un punto.

El parser reconoce formas paraguayas como `PY02`, `Ruta PY 02`, `Ruta 2`, `km 26,5`, cruces y accesos. Los alias se normalizan contra `road_aliases`. Una distancia relativa como “300 metros antes del km 25” se conserva como evidencia, pero no desplaza el marcador cuando el sentido de circulación no está determinado.

Cada resultado guarda método, precisión, error estimado, ruta, kilómetro, dataset y referencias utilizadas. Los métodos públicos son:

- `ROUTE_KM_OFFICIAL`
- `ROUTE_KM_OBSERVED` para un hito físico cartografiado y asociado inequívocamente a la ruta; no se presenta como dato oficial.
- `ROUTE_KM_INTERPOLATED`
- `ROUTE_INTERSECTION`
- `ROUTE_LOCALITY`
- `ROUTE_ONLY` (sin coordenadas)

Los cruces se calculan entre segmentos y admiten una tolerancia configurable para trazados que casi coinciden. `php artisan alertapy:locate "Accidente en PY02 km 26"` permite inspeccionar una resolución. `php artisan alertapy:locations:reprocess --only-low-accuracy --dry-run` muestra mejoras posibles; sin `--dry-run` solo reemplaza precisiones inferiores y registra una actualización auditable.

Cuando existen polígonos distritales oficiales del INE, ruta + distrito se resuelve sobre el segmento vial que cae dentro del polígono. No se usa el centroide del distrito para aparentar precisión vial.

Los hitos observados pueden importarse con `php artisan alertapy:roads:import-milestones archivo.json --dry-run`. El importador rechaza hitos sin número, alejados de una ruta, ambiguos o incompatibles con la longitud geométrica.
