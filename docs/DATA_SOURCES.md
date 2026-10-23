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
| MSPBS | Oficial | 30 min | Listados paginados y portada; separa salud pública, vectores y otras capas mencionadas en el titular. Solo publica notas con ubicación y fecha verificables |
| DGVS | Oficial | 30 min | Portada y archivo de noticias; clasifica vigilancia, brotes y servicios. No geolocaliza personas ni convierte estadísticas nacionales en casos puntuales |
| PAI | Oficial | 30 min | Portada y noticias paginadas del Programa Ampliado de Inmunizaciones; campañas y jornadas solo se llevan al mapa cuando la nota identifica un lugar |
| DIGIES | Oficial | 30 min | Noticias sobre registros, establecimientos y servicios de salud; ubicación extraída de la nota, sin publicar información personal |
| DINAVISA | Oficial | 30 min | Alertas sanitarias y de productos; noticias locales solo cuando hay fecha y lugar verificables |
| DIGESA | Oficial | 60 min | Salud ambiental, residuos, higiene e inspecciones; se evita convertir registros nacionales en incidencias locales |
| INS | Oficial | 60 min | Noticias del Instituto Nacional de Salud; clasificador sanitario y geolocalización de la nota |
| INAN | Oficial | 60 min | Alimentación y nutrición; solo alertas o hechos territoriales clasificables |
| DNERHS | Oficial | 60 min | Categorías vigentes Noticias y EqIPS; recursos humanos y servicios de salud, con fecha y ubicación explícitas para el mapa |
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
| Última Hora | Prensa | 5 min | Portada, búsquedas públicas ANDE/ESSAP y listados regionales de Chaco, Ñeembucú, Pilar y Paraguarí; metadatos de cada nota |
| La Nación | Prensa | 5 min | Portada, País y etiquetas ANDE/ESSAP; metadatos de cada nota |
| La Tribuna | Prensa | 10 min | Portada y secciones HTML públicas; artículos de cortes programados con zonas y horarios, enlace original |
| 1000 Noticias | Prensa | 15 min | Búsquedas de agua y electricidad en la API WordPress pública |
| Ñandutí | Prensa | 15 min | API WordPress pública y artículos originales en `nanduti.live` |
| Asunción Actual | Prensa | 15 min | Portada HTML; scraper territorial para cortes, incidentes y alertas con fecha y ubicación reconocibles |
| Ahora Paraguay | Prensa | 15 min | Portada HTML; fuente adicional de noticias locales de servicios, clima y territorio |
| El Nacional | Prensa | 15 min | Portada HTML; noticias nacionales y territoriales con el enlace de publicación original |
| Diario La Clave | Prensa | 15 min | Portada HTML; cobertura local de Alto Paraná y Ciudad del Este |
| Ratio Paraguay | Prensa | 15 min | Portada HTML; noticias nacionales con cobertura operativa de ANDE y temporales |
| Ahendu | Prensa | 30 min | Portada, Ciudades y Sociedad; noticias con contexto de clima, salud, servicios e incidentes territoriales. Usa el cuerpo del artículo para extraer lugares y conserva el enlace original |
| La Verdad Paraguaya | Prensa | 30 min | Portada bilingüe y secciones; cobertura nacional de sociedad, seguridad, salud, ambiente e infraestructura con publicación y ubicación verificables |
| El Independiente | Prensa | 30 min | Portada nacional con secciones de salud, meteorología y medio ambiente; guarda el titular y el enlace original si reconoce un hecho y un lugar |
| Parámetro | Prensa | 30 min | Portada de investigación y noticias; el medio se declara independiente y aporta notas territoriales cuando están dentro de la ventana de siete días |
| Pulso Paraguayo | Prensa | 30 min | Portada nacional con notas verificadas y referencias citadas; ubicación extraída del artículo y URL original del medio |
| RDN - Resumen de Noticias | Prensa | 30 min | Portada nacional; enlaces originales y artículos territoriales dentro de la ventana de siete días |
| Diario Paraguayo | Prensa | 30 min | Portada con notas nacionales y territoriales; se conservan URL original y host canónico |
| Paraguay24Horas | Prensa complementaria | 30 min | Portada activa con notas de varias regiones, incluidas publicaciones de Ñeembucú; el lugar se extrae del artículo y la fuente no se considera confirmación independiente |
| Caazapá Ahora | Prensa regional | 30 min | Portada y Locales; departamento de Caazapá |
| Norte Digital | Prensa regional | 30 min | Portada; San Pedro y noticias territoriales cercanas |
| Red Digital San Pedro | Prensa regional | 30 min | Portada; San Pedro |
| Digital Misiones | Prensa regional | 30 min | Portada; Misiones y localidades del sur |
| QAP Chaco News | Prensa regional | 30 min | Portada; Gran Chaco, sin imponer un único departamento |
| Caaguazú Noticias Digital | Prensa regional | 30 min | Portada y Locales; Caaguazú |
| Diario Vanguardia | Prensa regional | 30 min | Portada y Locales; Alto Paraná |
| [CDE HOT](https://www.cdehot.com.py/) | Prensa regional complementaria | 30 min | Portada con secciones locales de Alto Paraná; se conserva el enlace original y se exige fecha y ubicación de cada nota |
| Concepción al Día | Prensa regional | 30 min | Portada; Concepción y departamentos vecinos |
| Tercer Ojo | Prensa regional | 30 min | Portada; Caacupé y Cordillera |
| Portal de Curuguaty | Prensa regional | 30 min | Portada, Locales y Sucesos; Canindeyú |
| Portal Capitán Meza | Prensa regional | 30 min | Portada; Itapúa |
| Radio ZP30 | Prensa regional | 30 min | Sección Noticias Locales; Chaco Central y otras localidades occidentales, solo con lugar explícito en la publicación |
| Radio Guairá | Prensa regional | 30 min | Portada; noticias locales de Guairá |
| Pedro Juan Digital | Prensa regional | 30 min | Portada y Locales; Amambay |
| Amambay Digital | Prensa regional | 30 min | Portada; Amambay |
| [Portal Amambay](https://portalamambay.com.py/) | Prensa regional | 30 min | Portada con sección Locales y publicaciones recientes de Amambay; se conserva el enlace original |
| Luque Noticias | Prensa regional | 30 min | Portada; Luque y Central |
| Últimas Noticias | Prensa regional | 30 min | Portada con secciones Locales, Nacionales y Policiales; cobertura observada de Alto Paraná y otros departamentos |
| Hechos Digital | Prensa regional | 30 min | Portada; medio digital de Pedro Juan Caballero con notas recientes de Amambay |
| Diario HOY | Prensa | 5 min | Fase 4 |
| Popular | Prensa | 5 min | Fase 4 |
| Extra | Prensa | 5 min | Fase 4 |

El [GeoServer WFS público del INE](https://geonode.ine.gov.py/geoserver/ows) respondió el 23/10/2026 para `geonode:Vias_Principales_2022` (17.573 elementos) y `geonode:Hidrografia` (42.664, incluidos segmentos sin nombre). El mapa sirve copias GeoJSON versionadas; esa geometría todavía no se vuelve a generar automáticamente desde WFS. La red vial publicada es un dataset de 2022 y la geometría hidrográfica no representa niveles actuales: estos últimos provienen por separado de SIAGUAPY y DMH.

## Orden de integración

### Cobertura de servicios básicos

Canales verificados el 22/10/2026: [API de 1000 Noticias](https://1000noticias.com.py/wp-json/wp/v2/posts), [API de Ñandutí](https://nanduti.live/wp-json/wp/v2/posts), [Última Hora / ANDE](https://www.ultimahora.com/buscador?q=ande&s=0), [Última Hora / ESSAP](https://www.ultimahora.com/buscador?q=essap&s=0), [La Nación / ANDE](https://www.lanacion.com.py/tag/ande/) y [La Nación / ESSAP](https://www.lanacion.com.py/tag/essap/). Los buscadores de Última Hora aportan artículos recientes que no aparecen en sus etiquetas antiguas.

Se agregaron cinco medios con scraper HTML y frecuencia de 15 minutos: [Asunción Actual](https://asuncionactual.com/), [Ahora Paraguay](https://ahoraparaguay.com/), [El Nacional](https://elnacional.com.py/), [Diario La Clave](https://laclave.com.py/) y [Ratio Paraguay](https://www.ratioparaguay.com.py/). Sus titulares y notas se evalúan con el clasificador de prensa común; para evitar geolocalizar una ciudad mencionada al pasar, estas cinco fuentes solo aceptan lugares que aparecen en el titular o en la descripción editorial de la nota. Solo una nota aceptada llega al mapa y conserva su enlace de origen. Cuando un titular informa cortes de electricidad y agua, el recolector conserva el mismo enlace y crea un candidato separado por servicio. Son fuentes periodísticas adicionales y no reemplazan la confirmación oficial ni garantizan que haya un aviso cada día. El scraper limita cada portada a ocho notas pertinentes de la última semana para acotar solicitudes y evitar importar toda la portada.

El 23/10/2026 `RegionalPressSourceSeeder` quedó con 23 portales locales/regionales, tras sumar CDE HOT y Portal Amambay y excluir de la recolección automática a Frontera Seca News por una respuesta HTTP 403 documentada. Sus listados pueden incluir notas locales aunque el titular no mencione la ciudad; por eso el collector busca evidencia en el artículo y conserva solo el titular como contenido almacenado junto al enlace fuente. Cada consulta queda limitada a 18 enlaces y 12 candidatos, con recencia máxima de siete días e intervalo de 30 minutos. La región configurada describe el alcance habitual del portal y no reemplaza la localización que debe extraerse de cada nota. Para QAP Chaco News se usa la portada general sin fijar departamento porque cubre el Gran Chaco.

Ahendu publica su política editorial, verificación, separación entre información y opinión y mecanismo de correcciones; La Verdad Paraguaya se presenta como medio digital independiente bilingüe; Parámetro también se declara independiente; El Independiente mantiene actividad reciente de noticias nacionales; Pulso Paraguayo publica artículos con referencias y dice contrastar los temas con fuentes externas. Estas cinco fuentes se consultan cada 30 minutos y aplican la ventana de siete días, el clasificador y la ubicación territorial. Solo el titular y la URL original se conservan como contenido público; el cuerpo se usa para extraer el lugar. Las notas pueden correlacionarse con más de una capa si el titular describe hechos distintos o impactos simultáneos. La clasificación no convierte una fuente periodística en confirmación oficial, ni verifica de forma independiente su propiedad o todas sus afirmaciones editoriales.

La revisión de cobertura encontró portales recientes para localidades de doce departamentos y una fuente especializada en el Chaco Central; Últimas Noticias y Hechos Digital amplían la diversidad de Alto Paraná y Amambay. Sigue siendo una cobertura editorial desigual: que una fuente publique noticias nacionales no garantiza una nota reciente para cada departamento ni para cada categoría.

Fuentes revisadas sin activar automáticamente: TN Noticias (el listado de artículos no fue verificable en esta revisión), Pilar 24 Horas (la página consultada no mostró publicaciones recientes) y Frontera Seca News (su listado respondió HTTP 403, sin intentar eludir el control de acceso). Diario Paraguayo y Paraguay24Horas se incluyen como fuentes de prensa complementarias; al detectarse contenido republicado o referencias a otras redacciones, se conservan los enlaces disponibles y no se presenta el scraping como una verificación independiente. Los directorios sirven para descubrir medios, pero no prueban actividad reciente ni conexión técnica.

En salud, la categoría `health_public` agrupa vigilancia epidemiológica, vacunación, atención, servicios, trasplantes e insumos; también reconoce enfermedades como hantavirus, leptospirosis, tuberculosis y COVID-19. `health_vector` conserva acciones de SENEPA y enfermedades vectoriales, y `animal_health` sigue separada. Un titular puede aparecer además en una capa relacionada —por ejemplo, asistencia sanitaria durante una crecida— cuando el mismo titular describe ambos hechos. El mapa exige fecha y lugar del acontecimiento: alertas nacionales de DINAVISA o campañas de vacunación sin punto territorial no reciben coordenadas genéricas.

La selección se basó en actividad y cobertura visibles en las portadas durante la revisión; no verifica propiedad, independencia financiera, neutralidad ni exactitud de cada medio. Los portales inactivos o con publicaciones demasiado antiguas se omitieron. Al 23/10/2026, las portadas oficiales consultadas mostraban publicaciones recientes de DINAVISA del 14/10 y DIGESA/DGVS del 08/10, fuera de la ventana de siete días; esa falta de noticias publicables no demuestra por sí sola una falla de conexión. La portada de Hechos Digital mostraba el 21/10 un informe de atenciones de salud de Amambay, que ahora pasa la regla de clasificación; el ciclo y el estado del collector deben confirmarse en el backoffice de la base desplegada.

`UtilityNewsCollector` consulta por separado agua, energía, eléctrica, luz y apagón; la configuración de 1000 Noticias y Ñandutí recorre hasta tres páginas de 100 publicaciones por término, una ventana de siete días y un máximo de 30 candidatos únicos por medio. Se amplió la paginación porque la búsqueda WordPress de «agua» también devuelve «Paraguay» y puede desplazar noticias pertinentes de la primera página. Todos los términos se consultan antes de aplicar el cupo de candidatos más recientes. Son límites configurables, no una garantía de cobertura exhaustiva. La próxima pasada vuelve a revisar la ventana y el ingestor conserva la identidad del artículo; releerlo no crea otra incidencia ni renueva su fecha.

Solo se admiten electricidad/agua con un hecho reconocible, fecha de publicación válida, dominio original y lugar conocido. `date_gmt` prevalece sobre la fecha local; no se usa la modificación o la hora de consulta como publicación. Los comunicados institucionales, negaciones de cortes y referencias figuradas se excluyen. Una reposición futura o parcial no cierra un corte. Los cierres reales requieren coincidencia con el hecho; las palabras «corte de agua» por sí solas no fusionan barrios distintos.

Cuando la noticia contiene una única fecha y franja horaria explícitas del corte, se guardan por separado como inicio y fin. Un aviso futuro se publica como **Programado** y vence al finalizar esa franja, conservando la fecha original de la noticia. No se inventan horarios para expresiones como «mañana» o «este sábado» sin fecha, ni se aplican horarios comerciales. Un año omitido solo se resuelve si la fecha cae entre un día antes y 31 días después de la publicación y coincide con el día de semana mencionado.

Una respuesta vacía válida se diferencia de un bloqueo, un timeout o JSON incompatible. Si falla alguna consulta del medio, se registra el error y el ciclo continúa con otras fuentes. Cada listado HTML tiene oportunidad de aportar resultados antes del límite global; una portada abundante no desplaza todos los canales temáticos. Un titular mixto que anuncia varios servicios y ciudades puede seguir sin publicarse cuando no permite atribuir cada afectación con precisión.

El ciclo maestro único está preparado para iniciarse cada minuto: runner local con intervalo predeterminado de 60 segundos y cron de Hostinger todavía pendiente de configurar. Ejecuta, por registro central, todos los scrapers, feeds, sensores y proveedores discovery habilitados que tengan una frecuencia vencida. Electricidad, agua, hidrología, Meteorología/DMH y los medios incorporados tienen prioridad; el resto continúa por checkpoint general. Ninguna fuente se descarga cada minuto: manda la frecuencia individual y el presupuesto cooperativo de cada ciclo. El cron consulta las fuentes y actualiza la base; el redeploy solo actualiza código. En una instalación existente, fijar `ALERTAPY_MAX_SOURCES_PER_CYCLE=32` y agregar los trece medios de `AdditionalPressSourceSeeder` y los veinte medios regionales de `RegionalPressSourceSeeder` con `php artisan db:seed --class=<Seeder> --force`; los seeders son aditivos y preservan la habilitación/configuración existente de cada fuente. Para 1000 Noticias y Ñandutí, si faltan: `php artisan db:seed --class=UtilityNewsSourceSeeder --force`.

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

