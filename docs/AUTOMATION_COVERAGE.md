# Automatización y cobertura de AlertaPY

Revisión operativa: 22 de octubre de 2026, zona `America/Asuncion`.

## Qué quedó operativo

La instancia local usa la base real `storage/framework/alertapy-local.sqlite`, con respaldos privados previos a la actualización. La recolección de noticias se ejecuta mediante `alertapy:cycle`; el navegador consulta la base cada minuto mientras está activo. Desplegar código desde GitHub no actualiza noticias por sí solo.

El catálogo contiene **64 fuentes, 44 habilitadas**. Una auditoría de solo lectura comparó las 44 habilitadas con el registro de comandos y encontró un collector para cada una. El ciclo maestro elige las fuentes vencidas, respeta la frecuencia de cada una y avanza el resto mediante checkpoint; el 22/10 un ciclo completó 19 fuentes en 119 segundos. Una fuente puede responder sin novedades, estar bloqueada o necesitar reparación. El backoffice privado `/admin/connections` distingue estado, última consulta, último éxito y salud del ciclo. `/api/sources/status` requiere sesión administrativa; el mapa público ya no consulta ni muestra estos diagnósticos.

En la lectura local de las 18:12 del 22/10, la capa de electricidad tenía dos señales vigentes de los últimos siete días, agua una e hidrología una; el resto de las capas también se consulta, aunque no todas tenían una incidencia vigente. Son conteos de ese momento, no cuotas ni datos simulados. La lista de capas y fuentes activas se detalla abajo.

## Cambios comprobados

- El mapa recorre todas las páginas por cursor; dejó de truncar en 200. Un cambio de filtros cancela la carga anterior y una página fallida conserva el mapa previo.
- La selección por cursor evita saltos provocados por la expiración de resultados anteriores entre páginas. Las noticias que llegan durante una carga aparecen en la próxima consulta; no se promete un snapshot atómico de toda la base.
- Las noticias con un hecho reconocible en el título pueden obtener la ubicación del cuerpo del artículo. Se excluyen pies de página, menús y barras laterales como evidencia geográfica.
- La deduplicación exige evidencia del mismo hecho. Compartir ciudad, categoría o kilómetro no basta. Se preservan las evidencias originales y el seguimiento de una noticia editada.
- Los cierres también exigen identidad del hecho o vínculo con la evidencia original. Una coincidencia ambigua queda pendiente: un incendio extinguido no cierra otro de la misma ciudad, ni el restablecimiento de un alimentador cierra otro distinto.
- Las detecciones muestran el titular original en novedades. Se distinguen expresiones figuradas como “accidente dentro del destino” de un siniestro vial real.
- Los avisos meteorológicos usan fecha explícita día/mes/año y hora con segundos; se quitan scripts y estilos. No se sustituye una fecha desconocida por la hora de consulta.
- Los avisos que nombran varios departamentos generan candidatos para cada lugar reconocido por el catálogo. La descripción conserva el alcance completo de la fuente.
- El ciclo comparte un catálogo con los comandos de recolección, descubrimiento y monitoreo. Incluye estaciones DMH y alertas CAP, respeta frecuencias, registra errores y conserva el punto por donde continuar.
- La agenda Laravel tiene un único ciclo. La expiración se ejecuta antes de recolectar; un fallo de una fuente no cancela las demás.
- Un selector flotante persistente permite cambiar entre Todas, Electricidad, Agua y Ríos con un toque, incluso en móvil y sin abrir Filtros. Las otras incidencias permanecen agrupadas en el panel avanzado y conservan a la vista cuál categoría adicional quedó seleccionada.
- La expiración persiste el estado y su evento de auditoría una sola vez; un vencimiento por tiempo se distingue de una resolución confirmada del hecho.
- Las expiraciones técnicas permanecen en el detalle de cada incidencia, pero no desplazan noticias y cierres reales del panel de novedades. Se probó con 105 vencimientos antiguos procesados a la vez.
- El panel usa el ciclo de vida para rotular “Vencido” o “Resuelto”; no interpreta automáticamente todo estado finalizado como una resolución confirmada.

## Alcance real de las fuentes

| Grupo | Qué aporta | Condición o límite |
| --- | --- | --- |
| ABC Color | RSS y una página reciente del archivo público por sección (Nacionales/Economía) en cada recorrido normal | Ventana automática de 48 horas y cupo de candidatos. Backfill manual hasta 30 días, sujeto a páginas y cupos configurados |
| Última Hora, La Nación, La Tribuna, HOY y otros medios | Notas originales con fecha, categoría y ubicación | Los listados HTML son parciales; ampliar días no crea un archivo histórico que el sitio no ofrece |
| Instituciones, salud, municipios y rutas | Comunicaciones operativas territoriales | Se excluyen actos institucionales, datos nacionales sin lugar y notas sin evidencia suficiente |
| DMH estaciones | Mediciones oficiales por estación | Una medición es distinta de una incidencia; la antigüedad debe consultarse en cada estación |
| DMH avisos y CAP | Alertas oficiales | CAP devolvió cero mensajes en la consulta; cero es un resultado válido |
| ANDE | Parser e importación asistida de contenido público | HTTP directo sigue bloqueado por protección anti-bot; no se evade ni se declara actualizado |
| NASA FIRMS y MADES aire | Detecciones térmicas y calidad del aire | Requieren claves; actualmente deshabilitados. Una detección térmica no confirma un incendio |
| GDELT y redes sociales | Descubrimiento de posibles evidencias | Opcionales/deshabilitados; requieren configuración y, en varios casos, claves o canales autorizados |

## Seguimiento automático por capa

Estas fuentes forman parte del mismo `alertapy:cycle`; las habilitadas se consultan automáticamente al vencer su intervalo. Un contador en cero quiere decir que no hay una incidencia publicable vigente, no que se haya simulado un evento.

| Capa | Fuentes automáticas | Estado o límite verificado |
| --- | --- | --- |
| Electricidad | ANDE; Última Hora, La Nación, ABC, La Tribuna, HOY, 1000 Noticias, Ñandutí y cinco medios incorporados | ANDE sigue bloqueada. Los medios sí han importado cortes programados verificables: Ahora Paraguay produjo registros separados para electricidad y agua, y La Nación mantiene un corte futuro del Palacio de Justicia para el 24/10, de 08:00 a 11:00. La consulta del 23/10 encontró que ANDE también publicó una lista de 17 zonas para ese día, pero ni la conexión bloqueada ni los medios consultados la incorporaron. El respaldo periodístico es automático, pero no garantiza cubrir cada aviso que ANDE publique. Se conservan los enlaces originales. |
| Agua | ESSAP, ERSSAN, SIAGUAPY y medios de prensa | La API de ESSAP puede responder correctamente con cero avisos. El mapa usa noticias de servicio con fecha y zona verificables como respaldo. |
| Seguridad | Ministerio Público, SENAD y prensa | Titular y ubicación pasan filtros territoriales; los falsos positivos de expresiones figuradas están cubiertos por pruebas. |
| Accidentes y tránsito | MOPC, municipalidades y prensa | Cierres, desvíos y accidentes se clasifican con reglas distintas para evitar mezclar obras con siniestros. |
| Incendios | INFONA y prensa; NASA FIRMS opcional | NASA FIRMS está desactivado hasta configurar su clave. Una detección térmica sola no se publica como incendio confirmado. |
| Inundaciones y clima | Meteorología/DMH, alertas CAP, estaciones DMH, SEN, SIAGUAPY y prensa | Las estaciones se guardan como observaciones; solo alertas o hechos territoriales forman incidencias. |
| Calles, rutas y obras | MOPC, fuentes municipales y prensa | La red vial y su cartografía son datos de referencia versionados; los incidentes viales se recolectan por separado. |
| Fronteras y pasos | Migraciones y prensa | Los cambios, restricciones y cierres operativos usan el collector de Migraciones. |
| Salud y SENEPA | SENEPA, MSPBS, DGVS y prensa | Se filtran brotes y operaciones territoriales; no se convierten estadísticas nacionales en puntos. |
| Salud animal | SENACSA | Fuente oficial habilitada con categoría propia. |
| Ríos e hidrología | SIAGUAPY, estaciones DMH y prensa | Los trazos de ríos son cartografía estable; lecturas y noticias son datos separados. El feed de niveles de ríos de DMH y los de embalses están deshabilitados porque su última publicación no era actual. |
| Calidad del aire | Prensa para incidentes ambientales; API de estaciones del MADES para mediciones | Los titulares con condiciones adversas explícitas se clasifican como incidentes de aire. Las mediciones oficiales requieren clave API; sin ella la conexión de estaciones permanece deshabilitada. El MADES exige clave para los endpoints de datos y publica límites de uso; no se evade ese acceso. Se puede solicitar una clave en [Acceso a la API de MADES](https://calidadaire.mades.gov.py/acceso-api). |
| Emergencias | SEN, fuentes municipales y prensa | Se admiten hechos localizados como evacuaciones y derrumbes, no comunicados generales sin lugar. |
| Novedades territoriales | MADES, INFONA, DINAC y prensa | Solo hechos publicados con fecha, categoría y localización reconocibles. |

En esa misma lectura, las incidencias vigentes de electricidad, agua e hidrología tenían fuentes originales enlazadas. Otras capas no tenían un evento publicable en la ventana consultada. La cobertura de cada fuente y su estado se puede inspeccionar en el backoffice autenticado.

El listado anterior de Unicanal devuelve 404. Su [portada oficial](https://www.unicanal.com.py/) enlaza noticias de [La Tribuna](https://www.latribuna.com.py/); se añadió La Tribuna con atribución propia y Unicanal quedó deshabilitado por defecto hasta disponer de un canal directo adecuado. Se conserva su historial.

“Todos los resultados” significa todas las incidencias publicadas que satisfacen los filtros, no todas las noticias existentes en internet. Artículos sin lugar, fecha, pertinencia o autorización técnica de acceso no se convierten en puntos inventados.

## Pendientes antes de producción

1. **Destino y despliegue:** conectar el repositorio real, definir plan/dominio/rutas de Hostinger, PHP CLI, raíz pública, SSH y base MySQL/MariaDB. Ejecutar CI en GitHub y ensayar el despliegue en staging. Ver [Hostinger](HOSTINGER_DEPLOYMENT.md) y [producción](PRODUCTION_DEPLOYMENT.md).
2. **Persistencia:** migrar los datos reales de SQLite a MySQL mediante un proceso validado si deben conservarse. Git no transporta la base, `.env`, evidencias privadas ni uploads. Probar restauración de backups.
3. **Duración y capacidad:** tiempo y candidatos son presupuestos entre fuentes; una fuente ya iniciada termina. Una fuente lenta puede exceder los 180 segundos. Medir el recorrido completo del catálogo en el hosting, ajustar lotes/frecuencias y vigilar 403/429, disco y CPU. El lock expira al cabo de una hora como mínimo si el proceso muere.
4. **Cobertura histórica:** los cupos y portadas pueden omitir noticias durante una caída prolongada. Ampliar adaptadores RSS/API/archivos y checkpoints por fuente; después de una interrupción larga ejecutar backfill idempotente. No interpretar un scrape de portada como cobertura exhaustiva de siete días.
5. **Geografía:** completar departamentos, distritos y alias con fuentes verificadas. El aviso DMH consultado menciona siete departamentos, pero el catálogo reconoce seis: falta un registro departamental verificable de Guairá. Su mención se conserva en la descripción; no se inventaron coordenadas.
6. **Candidatos pendientes:** al corte había 7 pendientes de decisión y 6 sin ubicación. Hace falta un flujo editorial/reprocesador de pendientes. `next_retry_at` por sí solo no es un worker; los hallazgos de discovery todavía no tienen un consumidor automático que verifique y publique su fuente original.
7. **Calidad continua:** medir precisión geográfica, coincidencias de hechos, diferencias entre fecha de publicación/ocurrencia y cierres. No renovar la vigencia por releer una noticia antigua. `last_seen_at` puede conservar una detección anterior al editarse una noticia; separar observación técnica y evidencia nueva antes de cambiarlo, porque alimenta las etiquetas de frescura y calidad. Conservar fixtures ante cada cambio de estructura.
8. **Fuentes opcionales:** acordar qué API keys/canales se habilitarán, sus cuotas y costo. `max_ai_decisions` figura en configuración pero no está aplicado; el flujo actual usa reglas deterministas, no un servicio IA que procese esas colas.
9. **Operación:** configurar cron en Hostinger, comprobar varios ciclos completos y definir quién atiende fallos. El runner Windows funciona con la PC encendida y se debe iniciar nuevamente tras reiniciarla; no se instaló una tarea del sistema.

## Verificación y recuperación

`php artisan alertapy:status` muestra base, scheduler y fuentes. En Windows, los comandos sobre la base local deben usar el INI y variables de conexión del runner; el `.env` conserva la configuración MySQL del proyecto. Para comprobaciones automatizadas se usa SQLite **en memoria**; nunca la base real.

La última corrida completa del commit `fa53004` aprobó **333 pruebas PHP (1701 aserciones) y 36 pruebas JavaScript**; también pasó el smoke test de migraciones MySQL. La prueba nueva exige un comando de ciclo para cada fuente habilitada y que las 19 prioridades quepan en el lote máximo de 20. [Ejecución de CI](https://github.com/chamkill93/alertapy/actions/runs/39946763633). También se verificó el rechazo de una segunda instancia del runner mientras la primera mantiene el lock.

Las capas ignoran respuestas obsoletas después de cambiar la selección. La infraestructura reparte su límite global sin perder colegios al activar salud o policía: en el área de prueba devuelve 2471 de 2471 lugares. Las vistas fuera de cobertura devuelven una colección vacía. Los conteos junto a cada categoría explican cuándo no hay datos; el radio permite elegir un centro y quitar el filtro desde móvil. Las regresiones incluyen cargas tardías, errores de peticiones anteriores, selección manual y permisos de ubicación que llegan después de cancelar.

La distribución se comprobó en anchos de 320, 360, 390, 768, 1024, 1180 y 1440 px. Los controles y tarjetas comprobados no desbordan horizontalmente. Los paneles ocultos son inertes para el teclado, los flotantes se cierran con Escape y el detalle ignora respuestas tardías. Los formularios respetan el alto visible; los datos censales ausentes muestran «Sin dato», no NaN ni cero inventado.

El diagnóstico del 22/10 distinguió un cron activo de fuentes incompletas: ANDE sigue bloqueando la consulta automática; ESSAP devolvió cero avisos operativos recientes y SIAGUAPY mostraba estados normales en la consulta oficial. Cero alertas oficiales no descarta noticias de afectación en otros puntos: el clasificador de prensa reconoce cortes de agua, cortes programados de energía y crecidas con consecuencias explícitas. Las consultas, éxitos y problemas se revisan ahora en `/admin/connections`, sin presentar un cero como garantía de ausencia de incidencias.

Se incorporaron tres artículos verificados desde las respuestas públicas archivadas de ABC: una crecida en Humaitá del 22/10, el cierre de compuertas en Pilar del 19/10 y un aviso de cortes para el 17/10. Se conservaron las fechas originales, la precisión geográfica y las evidencias. Los dos últimos quedaron vencidos tras ejecutar la expiración: el mapa devolvió hidrología 2/electricidad 1/agua 0 en siete días e hidrología 1/electricidad 0/agua 0 en «Ahora». El resultado y el respaldo previo se referencian en `work/utility-replay-result-20261022.json`. No se inventaron avisos de agua ni se declaró actualizado el canal bloqueado de ANDE.

Se conservaron respaldos antes de la actualización y antes de reparar evidencia. La reparación puntual de dos fusiones y la fecha del aviso meteorológico tiene registro en `work/refresh-20261022-evidence-repair-v1.json`; los `raw_items` originales permanecen intactos. `work/audit-refresh-evidence.php --verify` permite revisar esa reparación en esta PC.

La ampliación de servicios básicos del 22/10 incorporó **1000 Noticias y Ñandutí**, con consultas temáticas públicas cada 15 minutos cuando les corresponde en el ciclo. Última Hora suma búsquedas ANDE/ESSAP ordenadas por fecha y La Nación sus etiquetas. Se consultan todos los temas dentro de cupos; las portadas ya no agotan el presupuesto antes de llegar a los listados temáticos. Ambas APIs nuevas terminaron correctamente sin avisos operativos recientes; el primer timeout de 1000 Noticias quedó registrado y el reintento se completó con éxito.

Última Hora incorporó la afectación eléctrica de Cerrito publicada el 22/10 a las 14:34. Se representa con precisión departamental en Ñeembucú porque no hay un punto local verificado. La Nación incorporó el aviso del Palacio de Justicia del 20/10: el corte está **programado para el 24/10, de 08:00 a 11:00**, separado de su fecha de publicación. Se corrigió su expiración con respaldo y auditoría en `work/utility-schedule-repair-20261022.json`. El nuevo extractor usa una única fecha y franja explícitas; rechaza horarios ambiguos o ajenos al corte. Los cierres futuros, negados y parciales no resuelven eventos, y las palabras genéricas del servicio no bastan para fusionar barrios diferentes.

El reintento completo de Última Hora devolvió siete registros sin cambios y ninguna publicación duplicada. Estado y evidencia final: `work/new-utility-sources-final-20261022.json`; respaldo e importación inicial: `work/new-utility-sources-result-20261022.json`. El panel de salud incluye los medios nuevos y mantiene visible el bloqueo de ANDE. El runner Windows se reactivó después de estas verificaciones; la automatización del hosting sigue pendiente del despliegue y cron de producción.

Se retiró además una opinión política clasificada por error como accidente vial. El registro, sus fuentes y la auditoría se conservaron; `work/refresh-20261022-metaphor-repair.json` contiene el antes/después y la ubicación de su respaldo.

El runner local completó su primera pasada en 197 segundos: procesó 10 fuentes, registró el bloqueo de ANDE y un timeout de HOY, y guardó el punto de continuación. Se comprobó la parada ordenada y se reinició con la versión final; la siguiente pasada retomó desde las fuentes restantes. Los límites de tiempo son cooperativos, no un corte abrupto de una fuente en curso.

La segunda pasada terminó a las 14:10:08 con éxito: 19 fuentes procesadas, cinco incidencias nuevas y mediciones de estaciones actualizadas. El runner quedó activo, esperando el siguiente ciclo; sus registros locales están en `work/local-scheduler.json` y `work/local-scheduler.log`.

Los scripts `scripts/deploy-hostinger.sh` y `scripts/run-cycle.sh` coordinan el despliegue y el cron mediante un lock. El despliegue exige SHA revisado, checkout limpio y respaldo reciente; valida por defecto y requiere `--apply` para ejecutar. La CI prepara pruebas PHP 8.2/8.3 y migraciones MySQL 8; su corrida en GitHub y el despliegue real siguen pendientes de la conexión del repositorio.
