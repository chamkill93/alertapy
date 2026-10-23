# Fuentes de datos

## Tipos

- **🏛 Oficial (`official`)**: instituciones públicas paraguayas.
- **📰 Prensa (`press`)**: medios paraguayos con URL original obligatoria.
- **👥 Comunidad (`community`)**: reportes ciudadanos; nunca se convierten automáticamente en “Oficial”.

Fuente y estado de la incidencia son dimensiones independientes.

## Catálogo inicial

El catálogo registra configuración, dominio y frecuencia. Las filas con collector activo ya pueden descargar contenido.

| Fuente | Tipo | Frecuencia inicial | Collector |
| --- | --- | ---: | --- |
| ANDE | Oficial | 5 min | Parser paginado; acceso HTTP directo bloqueado actualmente por Radware; importación asistida disponible |
| SENEPA | Oficial (API WordPress) | 10 min | Collector dedicado, paginado y geolocalizable |
| ESSAP | Oficial | 10 min | API pública oficial de WordPress; categorías de prensa, noticias, comunicados y obras, con filtro determinista de acontecimientos de agua |
| Dirección de Meteorología e Hidrología | Oficial | 5 min | Avisos oficiales con fecha de emisión y departamentos afectados; ventana de 12 horas |
| INFONA | Oficial | 30 min | Fase 9 |
| MADES | Oficial | 30 min | Noticias institucionales: solo hechos territoriales clasificables, con fecha y ubicación verificables |
| SIAGUAPY | Sensor | 30 min | Tabla oficial de alertas hidrológicas: conserva estados de alerta recientes; lecturas normales no generan incidencias |
| MOPC | Oficial | 10 min | Fase 9 |
| MOPC - red vial | Dataset oficial | Actualización manual versionada | GeoJSON, KML/KMZ o CSV mediante `alertapy:roads:import`; no se incluye geometría ficticia |
| OpenStreetMap - rutas PY | Cartografía pública ODbL | Actualización manual versionada | Copia local de relaciones `network=PY:national`; códigos contrastados con la publicación vial del MOPC |
| OpenStreetMap - hitos físicos | Cartografía pública ODbL | Actualización manual validada | Solo hitos numéricos asociados a una única ruta dentro de la tolerancia; se marcan `OBSERVED`, nunca oficiales |
| INE - Vías principales 2022 | Cartografía pública de referencia | Actualización manual | Copia GeoJSON local de 17.573 tramos nacionales y departamentales; se agrupa y simplifica solo para la capa visual |
| INE - Hidrografía | Cartografía pública de referencia | Actualización manual | Copia GeoJSON local de 20.486 tramos hidrográficos con nombre, agrupados en 957 ríos, arroyos, riachos, lagos, canales y otros cauces para la capa cartográfica |
| Secretaría de Emergencia Nacional | Oficial | 10 min | Fase 9 |
| MSPBS | Oficial | 30 min | Noticias oficiales; solo operativos, campañas o afectaciones territoriales con fecha y ubicación |
| DGVS | Oficial | 30 min | Noticias y boletines epidemiológicos; no geolocaliza personas ni convierte estadísticas nacionales en casos puntuales |
| ERSSAN | Oficial | 30 min | Supervisiones, controles y problemas publicados sobre agua; separado de ESSAP |
| SENACSA | Oficial | 60 min | Alertas, focos, campañas y restricciones de sanidad animal; categoría propia |
| Dirección Nacional de Migraciones | Oficial | 30 min | Cambios operativos, restricciones, cierres y reaperturas de pasos fronterizos |
| DINAC | Oficial | 60 min | Afectaciones operativas reales en aeropuertos públicos; excluye simulacros y actos institucionales |
| CONATEL | Oficial | 24 h | Desactivado: no se verificó un feed estable de interrupciones territoriales actuales |
| MADES - Calidad del Aire | Sensor | 30 min | API oficial; estaciones y series, requiere `MADES_AIR_API_KEY`. Los medios de prensa aportan incidentes ambientales por separado; no se presentan como mediciones. |
| NASA FIRMS | Sensor | 30 min | CSV oficial con coordenadas nativas, requiere `NASA_FIRMS_MAP_KEY` |
| GDELT | Discovery | 30 min | Descubre URLs originales; nunca se presenta como fuente final |
| X | Social | 15 min | Opcional; requiere bearer token y decisión posterior |
| Bluesky | Social | 15 min | Búsqueda pública opcional; resultados no verificados |
| Mastodon | Social | 30 min | Instancias y hashtags explícitos; no existe búsqueda global |
| Telegram | Social | 5 min | Solo canales configurados/autorizados mediante Bot API |
| YouTube | Discovery | 60 min | Solo canales configurados; usa metadatos, sin transcripción |
| ABC Color | Prensa | 5 min | RSS incremental + archivo JSON público paginado (`Nacionales` y `Economía`) |
| Última Hora | Prensa | 5 min | Portada, Chaco y búsquedas públicas ANDE/ESSAP ordenadas por fecha; metadatos de cada nota |
| La Nación | Prensa | 5 min | Portada, País y etiquetas ANDE/ESSAP; metadatos de cada nota |
| 1000 Noticias | Prensa | 15 min | Búsquedas de agua y electricidad en la API WordPress pública |
| Ñandutí | Prensa | 15 min | API WordPress pública y artículos originales en `nanduti.live` |
| Asunción Actual | Prensa | 15 min | Portada HTML; scraper territorial para cortes, incidentes y alertas con fecha y ubicación reconocibles |
| Ahora Paraguay | Prensa | 15 min | Portada HTML; fuente adicional de noticias locales de servicios, clima y territorio |
| El Nacional | Prensa | 15 min | Portada HTML; noticias nacionales y territoriales con el enlace de publicación original |
| Diario La Clave | Prensa | 15 min | Portada HTML; cobertura local de Alto Paraná y Ciudad del Este |
| Ratio Paraguay | Prensa | 15 min | Portada HTML; noticias nacionales con cobertura operativa de ANDE y temporales |
| Diario HOY | Prensa | 5 min | Fase 4 |
| Popular | Prensa | 5 min | Fase 4 |
| Extra | Prensa | 5 min | Fase 4 |

## Orden de integración

### Cobertura de servicios básicos

Canales verificados el 22/10/2026: [API de 1000 Noticias](https://1000noticias.com.py/wp-json/wp/v2/posts), [API de Ñandutí](https://nanduti.live/wp-json/wp/v2/posts), [Última Hora / ANDE](https://www.ultimahora.com/buscador?q=ande&s=0), [Última Hora / ESSAP](https://www.ultimahora.com/buscador?q=essap&s=0), [La Nación / ANDE](https://www.lanacion.com.py/tag/ande/) y [La Nación / ESSAP](https://www.lanacion.com.py/tag/essap/). Los buscadores de Última Hora aportan artículos recientes que no aparecen en sus etiquetas antiguas.

Se agregaron cinco medios independientes con scraper HTML y frecuencia de 15 minutos: [Asunción Actual](https://asuncionactual.com/), [Ahora Paraguay](https://ahoraparaguay.com/), [El Nacional](https://elnacional.com.py/), [Diario La Clave](https://laclave.com.py/) y [Ratio Paraguay](https://www.ratioparaguay.com.py/). Sus titulares y notas se evalúan con el clasificador de prensa común; para evitar geolocalizar una ciudad mencionada al pasar, estas cinco fuentes solo aceptan lugares que aparecen en el titular o en la descripción editorial de la nota. Solo una nota aceptada llega al mapa y conserva su enlace de origen. Cuando un titular informa cortes de electricidad y agua, el recolector conserva el mismo enlace y crea un candidato separado por servicio. Son fuentes periodísticas adicionales y no reemplazan la confirmación oficial ni garantizan que haya un aviso cada día. El scraper limita cada portada a ocho notas pertinentes de la última semana para acotar solicitudes y evitar importar toda la portada.

`UtilityNewsCollector` consulta por separado agua, energía, eléctrica, luz y apagón; la configuración de 1000 Noticias y Ñandutí recorre hasta tres páginas de 100 publicaciones por término, una ventana de siete días y un máximo de 30 candidatos únicos por medio. Se amplió la paginación porque la búsqueda WordPress de «agua» también devuelve «Paraguay» y puede desplazar noticias pertinentes de la primera página. Todos los términos se consultan antes de aplicar el cupo de candidatos más recientes. Son límites configurables, no una garantía de cobertura exhaustiva. La próxima pasada vuelve a revisar la ventana y el ingestor conserva la identidad del artículo; releerlo no crea otra incidencia ni renueva su fecha.

Solo se admiten electricidad/agua con un hecho reconocible, fecha de publicación válida, dominio original y lugar conocido. `date_gmt` prevalece sobre la fecha local; no se usa la modificación o la hora de consulta como publicación. Los comunicados institucionales, negaciones de cortes y referencias figuradas se excluyen. Una reposición futura o parcial no cierra un corte. Los cierres reales requieren coincidencia con el hecho; las palabras «corte de agua» por sí solas no fusionan barrios distintos.

Cuando la noticia contiene una única fecha y franja horaria explícitas del corte, se guardan por separado como inicio y fin. Un aviso futuro se publica como **Programado** y vence al finalizar esa franja, conservando la fecha original de la noticia. No se inventan horarios para expresiones como «mañana» o «este sábado» sin fecha, ni se aplican horarios comerciales. Un año omitido solo se resuelve si la fecha cae entre un día antes y 31 días después de la publicación y coincide con el día de semana mencionado.

Una respuesta vacía válida se diferencia de un bloqueo, un timeout o JSON incompatible. Si falla alguna consulta del medio, se registra el error y el ciclo continúa con otras fuentes. Cada listado HTML tiene oportunidad de aportar resultados antes del límite global; una portada abundante no desplaza todos los canales temáticos. Un titular mixto que anuncia varios servicios y ciudades puede seguir sin publicarse cuando no permite atribuir cada afectación con precisión.

El ciclo maestro único está preparado para iniciarse cada minuto: runner local con intervalo predeterminado de 60 segundos y cron de Hostinger todavía pendiente de configurar. Ejecuta, por registro central, todos los scrapers, feeds, sensores y proveedores discovery habilitados que tengan una frecuencia vencida. Electricidad, agua, hidrología, Meteorología/DMH, las cinco fuentes nuevas de prensa y los feeds de utilities verificados tienen prioridad; el resto continúa por checkpoint general. Ninguna fuente se descarga cada minuto: manda la frecuencia individual y el presupuesto cooperativo de cada ciclo. El cron consulta las fuentes y actualiza la base; el redeploy solo actualiza código. En una instalación existente, agregar los cinco medios con `php artisan db:seed --class=AdditionalPressSourceSeeder --force`; es aditivo, conserva habilitación e intervalos existentes y solo completa la opción faltante que impide geolocalizar una ciudad mencionada incidentalmente en el cuerpo. Para 1000 Noticias y Ñandutí, si faltan: `php artisan db:seed --class=UtilityNewsSourceSeeder --force`.

La [comprobación del 22/10/2026](PRE_HOSTINGER_SOURCE_CHECKS.md) distingue acceso técnico, disponibilidad de publicaciones recientes y cantidad de incidencias aceptadas. Cero alertas no convierte una fuente normal en fallida, ni una respuesta anti-bot en una fuente sana.

### Preferencia técnica

Cada collector debe preferir:

1. API oficial documentada.
2. RSS o feed.
3. JSON público.
4. HTML estructurado.
5. Scraping HTML aislado como último recurso.

No se implementarán evasiones de CAPTCHA, mecanismos anti-bot o consultas más frecuentes que la configuración de la fuente.

## Evidencia y cambios

Una lectura nueva genera un `raw_item` con URL original, título, contenido, publicación, detección y SHA-256. `external_identifier` y `content_hash` impiden reprocesar contenido idéntico. Las fuentes oficiales con collector verificado pueden publicarse mediante reglas deterministas y auditables con `ALERTAPY_OFFICIAL_SOURCES_PUBLISH=true`; la prensa permitida usa las mismas validaciones territoriales deterministas con `ALERTAPY_LOCAL_RULES_PUBLISH=true`. OpenCode es opcional.

RC1 distingue el rol de cada referencia (`origin`, `primary`, `secondary`, `corroborating`, `update`, `discovery`, `community`) sin perder URL, titular ni fechas. La salud guarda tipo de error, duración, elementos observados/nuevos y último cambio; un fallo de una fuente no detiene las demás.

SENEPA consulta directamente la API JSON oficial de WordPress, con paginación, en lugar de depender de las cuatro tarjetas visibles en la portada. Solo publica acciones territoriales geolocalizables de prevención o control vectorial —por ejemplo mingas, rastrillajes, eliminación de criaderos y control químico— y excluye comunicaciones institucionales genéricas. Las publicaciones repetidas se consolidan por el identificador oficial del artículo.

ESSAP se obtiene de su endpoint oficial `wp-json/wp/v2/posts`, limitado a la categoría de informes de prensa. Solo se convierten en candidatos entradas que combinan contexto de agua, un hecho operativo y una ubicación reconocible. Comunicados administrativos, capacitaciones, documentos y publicaciones sin ubicación se descartan. `php artisan alertapy:scrape essap --force --days=7` recupera el período de forma idempotente.

`php artisan alertapy:scrape abc --force --days=7` realiza una recuperación idempotente dentro de la ventana de siete días. `raw_items`, incidencias, eventos y corridas también vencen después de siete días; para una incidencia que aún está dentro de la ventana, se conserva la URL y el titular de origen en `incident_sources` hasta que venza esa incidencia.

`php artisan alertapy:scrape ultimahora --force --days=7` y `php artisan alertapy:scrape lanacion --force --days=7` recorren listados públicos, validan la fecha publicada de cada artículo y solo conservan hechos territoriales con ubicación reconocible. Última Hora incluye listados de Boquerón, Presidente Hayes y Alto Paraguay. Las referencias de varios medios a un mismo hecho se enlazan al mismo incidente mediante URL, ubicación, categoría y similitud temporal; no crean marcadores repetidos.

Para ANDE, el mismo parámetro pagina hasta cubrir el periodo cuando el servidor permite acceso HTTP. Si Radware bloquea al cliente, una captura de las páginas públicas visibles puede importarse con `--archive`; nunca se reutilizan cookies ni se automatiza el desafío anti-bot.

Las lecturas de calidad del aire se guardan en `monitoring_stations` y `station_measurements`; no se presentan como noticias. Se sincronizan con `php artisan alertapy:sync-monitoring mades-air-quality` y se consultan en `GET /api/monitoring/stations`.

NASA FIRMS produce incidencias de tipo sensor y subtipo `thermal_detection`, siempre con el texto neutral “Detección térmica satelital”. Una lectura aislada nunca se declara incendio confirmado. Cuando coincide espacial y temporalmente con evidencia de INFONA, se conserva una sola incidencia con las dos referencias y nivel `corroborated`. La distancia y ventana se configuran mediante `FIRE_CORRELATION_DISTANCE_KM` y `FIRE_CORRELATION_WINDOW_HOURS`.

Las fuentes sanitarias oficiales usan título público y ubicación territorial, sin datos clínicos, domicilios de pacientes ni información de productores. Las publicaciones multi-departamento se dividen por ámbito; las actualizaciones repetidas del mismo operativo ERSSAN se consolidan en una sola incidencia por departamento y permanecen como referencias y eventos de la línea de tiempo.

Migraciones se consulta con `php artisan alertapy:scrape migraciones --force --days=7`. Solo publica comunicaciones que alteran la operación de un paso y conserva el estado `CLOSED`, `RESTRICTED` o `MODIFIED_OPERATION`. DINAC se consulta de la misma forma con el slug `dinac` y descarta simulacros, capacitaciones, reuniones e inauguraciones. Los cruces y aeropuertos del catálogo son referencias configurables sin coordenadas inventadas: reciben coordenadas únicamente cuando aparece un acontecimiento real y se resuelve su ubicación.

CONATEL queda visible en el catálogo, pero desactivado. Sus páginas públicas verificadas contienen principalmente normativa, condiciones de operación, cobertura e indicadores históricos. Esos datos no se transforman en una caída actual de telecomunicaciones sin fecha, ubicación y evidencia operacional estable.

La capa de discovery está documentada en `docs/DISCOVERY_SOURCES.md`. Sus hallazgos se archivan como candidatos pendientes o sin ubicación y nunca se publican directamente en el mapa. Credenciales, tokens, instancias y canales autorizados se configuran exclusivamente mediante variables de entorno.

## Datos reales

Los seeders crean únicamente catálogos. No crean incidentes, ejecuciones exitosas ni cantidades. Los fixtures HTML y RSS inventados existen exclusivamente bajo `tests/Fixtures`.
