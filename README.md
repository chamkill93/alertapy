# AlertaPY

AlertaPY es una plataforma paraguaya de información territorial verificable. Su producto principal es un mapa grande que reúne incidencias geolocalizables procedentes de fuentes oficiales, prensa y reportes ciudadanos claramente identificados.

> **Regla de datos:** AlertaPY no muestra datos de demostración en producción. Si no existen incidencias reales publicadas, el mapa permanece disponible sin fabricar marcadores ni mostrar un aviso superpuesto.

## Estado de implementación

Las **fases 1 a 16** están implementadas de forma incremental, con recolección real y sin sembrar incidencias ficticias:

El proyecto se encuentra en **Release Candidate 1**. RC1 agrega temporalidad de ocurrencia separada de publicación, ciclo de vida normalizado, freshness por categoría, subcategorías, alcance territorial, calidad geográfica, roles de fuentes, evolución estructurada, explicación de publicación y diagnóstico del scheduler.

- Laravel 12 sobre PHP 8.2 o superior.
- MariaDB/MySQL como base de producción; SQLite para pruebas en memoria y la instancia local de desarrollo.
- Esquema base de categorías, fuentes, ubicaciones, evidencia, candidatos e incidencias.
- Catálogo geográfico persistente con ubicaciones, coordenadas y alias; extracción automática desde el texto de cada fuente.
- Geocodificación con prioridad caché → catálogo local → proveedor externo opcional, cuyos resultados completan el catálogo.
- Contrato de collectors mediante `CollectorInterface` y `CandidateDTO`.
- Catálogos de categorías y fuentes, sin sembrar incidencias.
- API pública paginada y filtrable.
- Blade, CSS y JavaScript vanilla.
- Leaflet, OpenStreetMap y clustering cargados directamente por CDN.
- Mapa responsive, filtros, búsqueda local, detalle, fuentes y línea de tiempo.
- Polling frontend cada 60 segundos.
- PHPUnit y controles de compatibilidad con hosting compartido.
- Collector de ABC Color con RSS incremental y archivo JSON público paginado para recuperar 1 a 30 días, incluyendo acontecimientos eléctricos publicados en Economía.
- Collectors de Última Hora y La Nación sobre sus listados públicos y metadatos estructurados, con cobertura explícita del Chaco.
- Parser de zonas ANDE con paginación histórica, importación asistida de capturas autorizadas y detección explícita de la verificación anti-bot Radware.
- Collector de ESSAP sobre su API pública oficial de WordPress, con clasificación territorial conservadora de incidencias de agua.
- Panel de últimas novedades con tiempos relativos, diversidad de fuentes y una sola ficha por acontecimiento.
- Capas opcionales de infraestructura y estaciones, apagadas inicialmente.
- Red vial versionada importable, búsqueda por ruta/kilómetro y resolución de cruces reales.
- Cartografía local con 22 rutas PY operativas, 17.573 segmentos viales, 20.486 tramos hidrográficos agrupados en 957 cauces y 13.558 infraestructuras.
- 95 estaciones meteorológicas activas en la base local de referencia.
- Decisiones deterministas auditables como proveedor predeterminado; OpenCode es opcional.
- Salud estructural de fuentes y parsers en el panel administrativo.
- Impacto territorial que separa población contextual de afectación demostrada.
- “Mis zonas” privadas con radio, categorías y estado de actividad.

No hay endpoints ni datos simulados que finjan capacidades. Las integraciones sin canal público estable permanecen deshabilitadas o degradadas explícitamente.

## Recolección real

ABC Color ofrece un RSS público para la recolección incremental y un archivo JSON público para la recuperación histórica. Solo se convierten en candidatos artículos cuyo título describe explícitamente un corte eléctrico, accidente, incendio, alerta meteorológica, afectación vial, emergencia o hecho de seguridad, y cuya ubicación puede identificarse y geocodificarse dentro de Paraguay.

```bash
php artisan alertapy:scrape abc --force

# Backfill idempotente: pagina Nacionales y Economía hasta cubrir 7 días.
php artisan alertapy:scrape abc --force --days=7
```

Última Hora y La Nación se consultan cada cinco minutos desde listados públicos. El collector abre únicamente las notas con ubicación y utilidad territorial reconocibles; para el Chaco también admite novedades de interés público sobre comunidades indígenas, ambiente, caminos, agua, salud, emergencias y actividades extractivas.

```bash
php artisan alertapy:scrape ultimahora --force --days=7
php artisan alertapy:scrape lanacion --force --days=7
```

Cada publicación aceptada se conserva en `raw_items` con su contenido original, URL, fechas y hash. La tabla `incidents` contiene la representación territorial para el mapa. Vencer en “Ahora” retira el marcador de los eventos vigentes; la publicación sigue disponible en los periodos recientes hasta salir de la ventana de 7 días. No existe un periodo histórico. El ciclo automático elimina el contenido vencido y evita reimportar noticias antiguas. Ver [política de retención](docs/DATA_RETENTION.md).

La ubicación no está fijada dentro de cada collector. El texto se compara con `location_aliases`; el registro relacionado en `locations` aporta el nombre canónico, ciudad, departamento, precisión y consulta geográfica. Si todavía no tiene coordenadas, el geocoder permitido las resuelve una vez, las guarda en `locations` y reutiliza `geocoding_cache` en las siguientes publicaciones.

Los candidatos se evalúan con reglas deterministas por defecto. Las fuentes oficiales y la prensa permitida solo se publican con URL, fecha, ubicación y controles válidos; discovery y redes sociales esperan corroboración. OpenCode es optativo. `ALERTAPY_LOCAL_RULES_PUBLISH=false` detiene esta publicación automática como interruptor de emergencia.

ESSAP se consulta mediante `https://www.essap.com.py/wp-json/wp/v2/posts`, restringiendo la consulta a su categoría oficial de informes de prensa. Solo se aceptan publicaciones con un acontecimiento operativo de agua y una ubicación paraguaya reconocible:

```bash
php artisan alertapy:scrape essap --force

# Recuperación histórica idempotente de los últimos 7 días.
php artisan alertapy:scrape essap --force --days=7
```

El scheduler ejecuta ESSAP cada diez minutos. Cada corrida queda auditada en `source_runs`; publicaciones originales en `raw_items`; decisiones normalizadas en `content_candidates`; marcadores en `incidents`; y el vínculo a cada evidencia en `incident_sources`. Si ESSAP no publicó una incidencia territorial durante el período, la corrida termina correctamente con cero resultados y no crea datos ficticios.

ANDE actualmente entrega una verificación Radware a clientes HTTP simples, aunque la página pública carga normalmente en un navegador. AlertaPY detecta el bloqueo y no intenta evadirlo. Para una recuperación asistida se puede exportar el texto público visible a un JSON dentro de `storage/app/private` e importarlo de forma idempotente:

```bash
php artisan alertapy:scrape ande --force --days=7 --archive=storage/app/private/ande-browser-7d.json
```

Cada `ZONA` se conserva como evidencia independiente, con horario, actividad, ubicación y enlace oficial. La captura no contiene cookies ni datos de sesión y el comando rechaza archivos ubicados fuera del directorio privado de la aplicación.

Para evitar marcadores repetidos, las zonas oficiales de electricidad que corresponden al mismo municipio o departamento y fecha se consolidan en un solo acontecimiento. El detalle conserva todas las zonas, horarios y evidencias. Los datos anteriores pueden normalizarse de forma idempotente con `php artisan alertapy:deduplicate`.

## Requisitos

- PHP 8.2 o superior con extensiones habituales de Laravel: Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer y XML.
- Composer 2.
- MariaDB o MySQL.
- Apache con `mod_rewrite` en producción.

No se requiere ni se admite Node.js, npm, Vite, Docker, Redis, Supervisor o un worker permanente.

## Instalación local

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Creá una base MariaDB/MySQL vacía y completá `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD` en `.env`. Después:

```bash
php artisan migrate --seed
php artisan serve
```

Abrí la URL configurada por `artisan serve`; la instancia de desarrollo actual utiliza `http://127.0.0.1:8878`. Los activos web ya están en `public/css` y `public/js`; no hay paso de compilación.

### Actualización automática

El despliegue del código y la actualización de noticias son procesos separados. `alertapy:cycle` consulta las fuentes habilitadas según su frecuencia, actualiza estaciones y alertas meteorológicas, vence incidencias y registra su estado. Conserva un checkpoint entre lotes; una fuente con error no detiene las demás.

En esta PC existe una base de referencia real en `storage/framework/alertapy-local.sqlite`. Para mantenerla actualizada mientras la PC está encendida, el runner local configura PHP y esa conexión únicamente para su proceso:

```powershell
pwsh -NoProfile -File scripts/local-scheduler.ps1
# Detener al finalizar el ciclo en curso, desde otra consola:
pwsh -NoProfile -File scripts/local-scheduler.ps1 -Stop
```

El intervalo inicial es de cinco minutos; el estado y log quedan en `work/local-scheduler.json` y `work/local-scheduler.log`. El runner se inicia de nuevo después de reiniciar la PC; no instala una tarea del sistema. La base local no se envía a GitHub y producción utiliza MySQL/MariaDB.

Para Hostinger usar el cron de [la guía de despliegue](docs/HOSTINGER_DEPLOYMENT.md). La [cobertura y condiciones pendientes](docs/AUTOMATION_COVERAGE.md) distingue fuentes activas, bloqueadas, opcionales y límites de geolocalización. El mapa recorre todas las páginas mediante cursor y consulta la base cada minuto cuando está visible; el indicador de fuentes refleja la salud del proceso de recolección.

## Tests

```bash
composer test
node --test tests/Frontend/*Test.cjs
```

La suite PHP usa SQLite en memoria y no accede a internet. Las pruebas JavaScript usan Node y respuestas controladas, sin navegador ni red. Los datos simulados existen únicamente dentro de las pruebas.
Verificación del 22/10/2026: **177 pruebas PHP (941 aserciones) y 33 pruebas JavaScript**, todas aprobadas.

La integración visual RC1 se documenta en [docs/UI_UX_RC1.md](docs/UI_UX_RC1.md). Ejecutar la aplicación no requiere Node, npm ni compilación de assets; Node se usa para las pruebas de interacciones.

Las categorías muestran cuántas incidencias hay en la zona, período y tipo de fuente elegidos, independientemente de las casillas activadas. Infraestructura, estaciones y cartografía tienen interruptores separados. El filtro por radio se activa con “Usar centro del mapa” o “Usar mi ubicación”, también en móvil, y se elimina con “Quitar radio”.

## API pública

| Método | Ruta | Uso |
| --- | --- | --- |
| GET | `/api/incidents` | Listado paginado con filtros y resumen real |
| GET | `/api/incidents/{uuid}` | Detalle, fuentes originales y actualizaciones |
| GET | `/api/incidents/updates` | Actualizaciones recientes para polling |
| GET | `/api/categories` | Categorías habilitadas |
| GET | `/api/locations?query=` | Búsqueda en el catálogo geográfico local |
| GET | `/api/sources/status` | Diagnóstico privado de fuentes; requiere sesión de administrador |
| GET | `/api/monitoring/stations` | Estaciones oficiales y su última medición por parámetro |
| GET | `/api/roads` | Red vial activa, filtrable por ventana visible o código |
| GET | `/api/roads/resolve?query=` | Resolución de ruta, kilómetro o cruce |
| GET/POST | `/api/favorite-zones` | Zonas privadas del usuario o visitante |
| GET | `/api/favorite-zones/{id}/status` | Actividad vigente dentro de una zona |

Filtros de `/api/incidents`:

- `categories=electricity,water`
- `period=now|1h|6h|24h|7d`
- `bbox=south,west,north,east`
- `city=Asunción`
- `status=active,confirmed`
- `source=official` o slug de fuente
- `per_page=1..200`

Solo se devuelven incidencias con `published_at` válido. La evidencia cruda, respuestas IA y errores internos no forman parte de la API pública.

## Estructura principal

```text
app/
├── Collectors/            Collectors aislados por fuente
├── Console/Commands/      Comandos de recolección
├── Enums/                 Estados y tipos cerrados
├── Http/Controllers/      Mapa y API pública
├── Http/Requests/         Validación de filtros
├── Http/Resources/        Contratos JSON públicos
├── Models/                Dominio Eloquent
└── Services/              Consultas de negocio
database/
├── migrations/            Esquema MariaDB/MySQL
└── seeders/               Solo catálogos verificables
public/
├── css/alertapy.css       Componentes del mapa
├── css/alertapy-responsive.css  Distribución, tamaños y contención
└── js/alertapy-map.js     Leaflet y polling sin build
resources/views/map.blade.php
routes/api.php
routes/web.php
docs/
```

## Decisiones de arquitectura

- Monolito Laravel para adecuarse a Hostinger Business compartido.
- UUID en URLs públicas; IDs internos no se exponen.
- `source_type` y `status` son conceptos independientes.
- Coordenadas pueden ser nulas para impedir precisión inventada.
- `location_accuracy` acompaña siempre la presentación pública.
- Evidencia original vive en `raw_items`; referencias públicas en `incident_sources`.
- Un incidente puede tener varias fuentes y actualizaciones.
- Cache y locks se apoyan en MariaDB o archivos, nunca Redis.
- El trabajo futuro se ejecutará en lotes desde cron, sin daemon.

## Documentación

- [Arquitectura](docs/ARCHITECTURE.md)
- [Fuentes](docs/DATA_SOURCES.md)
- [Ciclo de vida](docs/INCIDENT_LIFECYCLE.md)
- [Motor OpenCode](docs/OPENCODE_DECISION_ENGINE.md)
- [Prompt del moderador](docs/opencode-moderator-prompt.md)
- [Reglas de scraping](docs/SCRAPING_RULES.md)
- [Privacidad](docs/PRIVACY.md)
- [Despliegue en Hostinger](docs/HOSTINGER_DEPLOYMENT.md)
- [Solución de problemas](docs/TROUBLESHOOTING.md)
- [Red vial](docs/ROAD_NETWORK.md)
- [Geolocalización vial](docs/ROAD_GEOLOCATION.md)
- [Decisiones por reglas](docs/RULE_BASED_DECISIONS.md)
- [Salud de fuentes](docs/SOURCE_HEALTH.md)
- [Impacto territorial](docs/TERRITORIAL_IMPACT.md)
- [Zonas favoritas](docs/FAVORITE_ZONES.md)
- [Modelo de datos RC1](docs/DATA_MODEL.md)
- [Modelo temporal](docs/TEMPORAL_MODEL.md)
- [Calidad de geolocalización](docs/GEOLOCATION_QUALITY.md)
- [Cobertura territorial](docs/TERRITORIAL_COVERAGE.md)
- [Checklist de producción](docs/PRODUCTION_DEPLOYMENT.md)
- [Prueba de estabilidad](docs/STABILITY_TEST.md)
- [Configuración Git](docs/GIT_SETUP.md)

## Operación pendiente de datos externos

- Habilitar ANDE cuando exista un canal oficial accesible sin evasión anti-bot.
- Configurar únicamente las credenciales de fuentes opcionales que efectivamente se utilizarán.

## Uso en móvil y escritorio

Los filtros tienen desplazamiento propio y se abren como panel hasta 760 px. El panel se cierra con su botón o Escape y sus controles quedan fuera del recorrido del teclado cuando está oculto. Los formularios respetan el alto visible y las zonas seguras del dispositivo. El mapa conserva la actualización de incidencias, los reportes y las referencias originales en cada detalle.

Las noticias detectadas y el estado de las conexiones están en el **backoffice privado `/admin`**. La página pública no muestra ni consulta el feed de novedades o los diagnósticos internos. Un cero en el mapa corresponde a incidencias publicadas con los filtros actuales.

La cobertura de electricidad y agua incorpora búsquedas de prensa independientes: 1000 Noticias y Ñandutí mediante sus APIs públicas, y canales temáticos de Última Hora y La Nación. Los medios nuevos se consultan cada 15 minutos cuando les corresponde en el ciclo, conservan la fecha y el enlace originales y no necesitan claves. Para incorporar solo esas fuentes en una instalación existente, ejecutar `php artisan db:seed --class=UtilityNewsSourceSeeder --force`; respeta las preferencias de fuentes ya creadas. Ver [cobertura y límites](docs/DATA_SOURCES.md).

## Backoffice

`/admin` ofrece resumen operativo, noticias paginadas con búsqueda por texto, fuente, estado y categoría publicada, detalle de evidencia y conexiones con su historial de consultas. El diagnóstico diferencia habilitación, salud y última ejecución; una consulta en curso no se presenta como cero noticias. Es una interfaz de consulta y no ejecuta recolecciones desde el navegador.

Después de aplicar las migraciones, el primer acceso en localhost permite crear la cuenta de administrador. El alta inicial está cerrada en producción y cuando ya existe un administrador. En el hosting, usar el comando interactivo `php artisan alertapy:admin correo@ejemplo.com --name="Nombre"`. No hay contraseñas predeterminadas. Ver [acceso, sesiones y configuración](docs/BACKOFFICE_ACCESS.md).
