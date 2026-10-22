# Calidad de geolocalización

## Métodos

Orden general de calidad, de mayor a menor:

1. `source_coordinates`.
2. `route_km_official`.
3. `route_km_interpolated`.
4. `route_intersection`.
5. `street_intersection`.
6. `route_locality`.
7. `neighborhood`.
8. `city`.
9. `district`.
10. `department`.
11. `unknown`.

`location_quality` deriva el método como `very_high`, `high`, `medium`, `low`, `very_low` o `unknown`. La verificación editorial de la fuente no modifica esta calidad geométrica.

## Flags

El analizador detecta, entre otros: `OUTSIDE_PARAGUAY`, `ZERO_COORDINATES`, `ROUTE_KM_OUT_OF_RANGE`, `CITY_DEPARTMENT_MISMATCH`, `LOW_ACCURACY`, `CENTROID_ONLY` y `UNKNOWN_LOCATION`.

El comando `php artisan alertapy:geo:quality --active --dry-run` audita sin escribir. `--fix-safe` solo persiste método, calidad y flags derivados; no mueve coordenadas ambiguas. `LocationAuditService` impide sustituir una ubicación mejor por otra peor y registra cualquier cambio de coordenadas autorizado.
