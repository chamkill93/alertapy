# Red vial

AlertaPY puede cargar geometrías viales verificables sin inventar trazados. Los conjuntos se registran en `road_datasets`; las rutas, alias, segmentos y referencias kilométricas se conservan en tablas separadas con fuente, fecha, hash y estado activo.

## Importación

Formatos admitidos: GeoJSON/JSON, KML, KMZ y CSV. Antes de reemplazar una red se recomienda validar con `--dry-run`:

```bash
php artisan alertapy:roads:import storage/app/imports/roads/red-vial.geojson --dry-run --dataset-name="MOPC red vial"
php artisan alertapy:roads:import storage/app/imports/roads/red-vial.geojson --replace --dataset-name="MOPC red vial" --source-url="https://www.mopc.gov.py/"
php artisan alertapy:roads:validate
```

El importador comprueba estructura, límites de Paraguay, códigos, geometrías y duplicados por SHA-256. `--replace` desactiva el conjunto anterior solo después de que la transacción nueva finaliza correctamente. `/admin/roads` ofrece carga, validación, vista previa, importación y desactivación bajo el mismo Basic Auth del panel administrativo.

## Consumo

- `GET /api/roads?bbox=south,west,north,east`
- `GET /api/roads/resolve?query=PY02%20km%2026`
- `GET /api/roads/{id}/segments`
- `GET /api/roads/{id}/reference-points`
- `GET /rutas/PY02`

La API vial conserva la geometría completa y los segmentos usados para resolver rutas, localidades y progresivas. Esa precisión no se reemplaza por la capa visual.

## Capa cartográfica rápida

La sección **Cartografía** ofrece capas independientes que se descargan bajo demanda. En RC1 las capas pesadas, incluida la red hidrográfica, permanecen apagadas hasta que el usuario las active:

- **Red vial:** 17.573 tramos de vías principales del INE, agrupados en 126 corredores nacionales y departamentales.
- **Ríos y cauces:** 20.486 tramos hidrográficos con nombre, agrupados en 957 ríos, arroyos, riachos, lagos, canales y otros cauces.

Las capas se sirven como GeoJSON local desde `public/data/map-layers/`. Se descargan una sola vez al activarlas, quedan en la caché del navegador y no vuelven a consultar Laravel al mover o ampliar el mapa. Esto evita bloquear las consultas de incidencias en instalaciones PHP sin procesos permanentes.

Para regenerarlas después de descargar una versión nueva del Geoportal INE:

```bash
php -d memory_limit=1024M artisan alertapy:map-layers:build \
  --roads=storage/app/imports/roads/ine-vias-principales-2022.geojson \
  --rivers=storage/app/imports/roads/ine-hidrografia-nombrada.geojson
```

El generador agrupa segmentos conectados, aplica simplificación Douglas–Peucker únicamente para representación visual y conserva fuente, clasificación y superficie. No modifica `roads` ni `road_segments`, por lo que los cálculos viales continúan usando la cartografía de precisión.

No se distribuye un trazado ficticio. El administrador debe conservar la URL y la fecha de cada dataset y verificar sus condiciones de reutilización antes de actualizar una instalación pública.
