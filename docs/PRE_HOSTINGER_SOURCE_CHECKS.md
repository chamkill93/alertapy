# Revisión de conexiones antes de Hostinger

Revisión realizada el **22 de octubre de 2026**, desde el entorno local, con horarios de `America/Asuncion` (UTC−03). Comprueba acceso a fuentes públicas y trazabilidad de ingesta; no confirma conexión desde la IP de Hostinger. El dominio previsto es `https://www.alertaparaguay.com`; siguen pendientes el acceso a hPanel, la verificación del plan, las rutas reales, la base del servidor y la configuración de cron y despliegue.

## Respuestas públicas verificadas

| Conexión | Comprobación local | Resultado y significado |
| --- | --- | --- |
| [ANDE: trabajos programados](https://www.ande.gov.py/noticias.php?tipo_nota=trabajo_programado) | 17:11:48 | HTTP 200 con página de verificación anti-bot. El collector registra el bloqueo como error; no se declara actualizado ni se intenta evadirlo. |
| [ESSAP: API pública](https://www.essap.com.py/wp-json/wp/v2/posts) | 17:16:20 | HTTP 200, JSON `[]`, total 0 para la ventana de siete días y categorías operativas consultadas. Una consulta adicional sin categorías tampoco encontró publicaciones dentro de la ventana. La publicación más nueva disponible era del 15/10 a las 10:42:54, sin título y fuera de la ventana móvil de siete días a esa hora. Cero noticias recientes es legítimo. |
| [SIAGUAPY: alertas hidrológicas](https://siaguapy.mades.gov.py/alertas-hidrologicas) | 17:11:56 | HTTP 200, ocho estaciones con fecha 22/10/26: seis en estado normal y Villa Florida/Yuty sin inconvenientes. El collector produjo cero alertas, correctamente. Los niveles de Villa Florida y Yuty habían cambiado respecto de una respuesta anterior del mismo día. |
| [DMH: avisos](https://www.meteorologia.gov.py/avisos/) | Página oficial revisada durante esta auditoría | Aviso publicado el 22/10 a las 15:03:33 para Caazapá, Itapúa y Alto Paraná, coherente con los tres candidatos del último ciclo local de las 17:07. |
| [MADES: noticias](https://www.mades.gov.py/noticias/) | Página oficial revisada durante esta auditoría | Los últimos artículos visibles eran del 20/10 y 17/10, sobre educación ambiental y eficiencia energética. No constituían incidencias hidrológicas. Último ciclo local exitoso observado: 17:07:34. |
| [1000 Noticias: API pública](https://1000noticias.com.py/wp-json/wp/v2/posts) | 17:12:54 | HTTP 200 en las búsquedas de agua, energía, eléctrica, luz y apagón, con paginación ampliada. Hubo 139 coincidencias para «agua» repartidas en dos páginas; después de verificar contenido, fecha y ubicación no se aceptaron incidencias de servicios en esa lectura. |
| [Ñandutí: API pública](https://nanduti.live/wp-json/wp/v2/posts) | 17:12:53 | HTTP 200 en las cinco búsquedas, con publicaciones del propio 22/10. La lectura ampliada no produjo incidencias de servicios aceptables. Tener noticias recientes no implica que exista un corte de agua o electricidad. |

Los resultados reflejan respuestas puntuales, no una garantía de disponibilidad posterior ni cobertura completa. Las consultas de diagnóstico fueron de solo lectura y no modificaron `sources.last_run_at` ni incorporaron noticias artificiales.

## Cinco sitios adicionales investigados

Se escogieron medios que publicaron información territorial o de servicios en Paraguay y exponen notas periodísticas con fecha y URL, y se añadieron al scraper compartido, no como fuentes de confirmación oficial.

| Sitio adicional | Ejemplo público localizado | Uso y límite |
| --- | --- | --- |
| [Asunción Actual](https://asuncionactual.com/index.php/2026/10/18/sin-luz-en-casa-estas-son-las-zonas-con-cortes-programados-de-ande/) | Cortes de ANDE del 18/10 con zonas, horarios y obras | Fuente periodística para candidatos de electricidad; el texto de varias zonas necesita validación territorial. |
| [Ahora Paraguay](https://ahoraparaguay.com/2026/10/20/cortes-programados-de-energia-y-agua-afectaran-a-asuncion-y-tres-departamentos-este-martes/) | Trabajos de ANDE y ESSAP del 20/10 en varias zonas | Aporta ambos servicios, pero una nota con muchos sectores/horarios puede no separarse con precisión suficiente y quedar descartada. |
| [El Nacional](https://elnacional.com.py/) | Portada del 22/10 con noticias nacionales recientes | Amplía la cobertura general del mapa; la lectura no se cuenta como aviso de corte sin una nota concreta, ubicación y fecha válidas. |
| [Diario La Clave](https://laclave.com.py/2026/09/07/ande-castiga-a-cde-con-hasta-cinco-cortes-de-energia-por-dia/) | Reporte del 07/09 sobre interrupciones reiteradas en Ciudad del Este | Cobertura local de Alto Paraná; ese ejemplo ya excede siete días y no debe publicarse como incidencia vigente. |
| [Ratio Paraguay](https://www.ratioparaguay.com.py/ande-se-prepara-para-nuevos-cortes-de-luz-por-tormentas-y-movilizara-hasta-40-cuadrillas-adicionales-en-asuncion-y-central/) | Respuesta de ANDE a tormentas y cortes en Asunción/Central | Complementa emergencias y electricidad; movilizar cuadrillas o prepararse no demuestra por sí solo un corte actual. |

Las cinco fuentes quedan registradas como prensa, se consultan cada 15 minutos y solo se leen ocho notas que superan el prefiltrado territorial/categórico, dentro de los últimos siete días. Para estos medios la ciudad debe aparecer en el titular o en la descripción editorial; no se usa una mención incidental en el cuerpo como ubicación del hecho. Si un aviso nombra electricidad y agua, se producen candidatos separados con el mismo URL original. Así, un corte explícitamente planificado se admite si fecha, zona y horario se pueden atribuir; comunicados generales, duplicados, artículos viejos, cobertura sin zona y noticias con varios sectores ambiguos no se inventan como marcadores. La fecha de cada consulta automática se puede inspeccionar en `/admin/connections` tras instalar el seeder.

La lectura directa del recolector real a las **17:56 del 22/10** volvió a obtener las cinco portadas mediante solicitudes públicas de solo lectura. Asunción Actual produjo un candidato; Ahora Paraguay, cuatro (incluidos un mismo aviso de electricidad y agua ya separado en dos categorías); El Nacional, dos; Diario La Clave, dos; Ratio Paraguay, cero notas que superaran los filtros a esa hora. Una nota sin candidatos es una lectura válida, no un error de conexión. El aviso de Ahora Paraguay corresponde al 20/10, con la fecha de los trabajos ya pasada, y no debe mostrarse como corte activo. Esta lectura no modificó corridas ni incidencias de la base.

Tras aplicar el cambio al código local, el ciclo automático periódico procesó las cinco fuentes entre **18:04:45 y 18:06:01**, con ejecución exitosa en las cinco y sin errores de ciclo. Asunción Actual descubrió cero notas elegibles; Ahora Paraguay descubrió cuatro y publicó un candidato nuevo; El Nacional y Diario La Clave reconocieron dos cada uno como ya vistos; Ratio Paraguay terminó sin candidatos elegibles. Ahora Paraguay generó registros distintos para electricidad y agua usando el mismo artículo. Ambas incidencias están vencidas desde el 21/10, y el mapa no las presenta como actuales.

La corrida posterior volvió a intentar ANDE a las **18:13:54** y registró otra vez la verificación anti-bot. El ciclo siguió con siete fuentes adicionales y creó una incidencia; el siguiente ciclo terminó correctamente. El fallo de ANDE permanece visible, pero no detiene la recolección de las demás fuentes.

Las capas con actividad actual sí tienen referencias vigentes de otras fuentes: [Última Hora reporta 400 familias sin energía en Cerrito](https://www.ultimahora.com/aislados-bajo-agua-y-sin-energia-electrica-400-familias-esperan-ayuda-humanitaria-en-cerrito), [Diario HOY reporta falta de agua por una avería eléctrica](https://www.hoy.com.py/nacionales/2026/10/22/reportan-falta-de-agua-en-asuncion-y-gran-asuncion-por-averia-electrica) y [ABC informa la crecida del río Paraguay en Humaitá](https://www.abc.com.py/clima/2026/10/22/crecida-del-rio-paraguay-arrasa-cultivos-y-amenaza-al-ganado-en-humaita/). El canal SIAGUAPY respondió correctamente sin alertas; sus ocho lecturas del 22/10 estaban dentro de rango normal. DMH recibió 99 elementos y guardó 239 mediciones en el ciclo de las 18:05. La geometría de ríos sigue siendo una capa cartográfica estable, no una serie de niveles; las noticias y mediciones se muestran como incidencias/observaciones separadas.

## Referencias y vigencia

La noticia de ABC sobre la [crecida en Humaitá del 22/10](https://www.abc.com.py/clima/2026/10/22/crecida-del-rio-paraguay-arrasa-cultivos-y-amenaza-al-ganado-en-humaita/) mantiene su URL de origen. La nota de [compuertas de Pilar del 19/10](https://www.abc.com.py/economia/2026/10/19/pilar-cierran-compuertas-de-estacion-de-bombeos-ante-crecida-del-rio-paraguay/) conserva su referencia, pero su incidencia ya está finalizada y no corresponde a «Ahora».

Las noticias e incidencias tienen [retención de siete días](DATA_RETENTION.md). Volver a leer una nota no renueva su fecha de publicación. La capa de ríos de `public/data/map-layers/rivers.geojson` es cartografía estable de referencia y no un feed de noticias ni de mediciones actuales. Un nivel normal de SIAGUAPY tampoco se publica como alerta para completar un contador.

## Demoras detectadas y cambio del ciclo

La auditoría anterior al cambio encontró 39 fuentes habilitadas compatibles con el ciclo, presupuesto de 180 segundos y máximo de 20 fuentes. El runner local despertaba cada 300 segundos. En continuidad, DMH configurado cada tres minutos se consultó cada 10,2–11,6 minutos y ESSAP configurado cada diez se consultó cada 21,9 minutos. SIAGUAPY tuvo un intervalo de 60,15 minutos, que incluye una pausa del runner de 21,5 minutos: no toda esa demora procede de la cola.

Algunas fuentes generales agotaban el presupuesto antes de llegar a las de servicios. Por ejemplo, CDE Hot e Itapúa Noticias sumaron 196,14 segundos en un ciclo que solo atendió dos fuentes. Las nueve conexiones de servicios y monitoreo examinadas sumaron 33,35 segundos en sus últimas ejecuciones, lo que sustentó priorizarlas.

El código ahora atiende primero 19 fuentes vencidas, ordenadas por ejecución debida más antigua: ANDE, agua e hidrología; ABC, Última Hora, La Nación, HOY y La Tribuna; 1000 Noticias, Ñandutí y los cinco medios adicionales de la tabla. El límite es de 20 fuentes por ciclo y la cola general conserva su checkpoint independiente; procesar prioritarias no lo reinicia. Continúan el bloqueo, la deduplicación y el registro de fallos. El runner local tiene un intervalo predeterminado de 60 segundos. Son límites cooperativos: un collector en marcha termina antes de dar paso al siguiente y puede prolongar el ciclo.

Las pruebas dirigidas verificaron 12 casos de SIAGUAPY/Meteorología (38 aserciones) y 15 casos del ciclo general (85 aserciones). Son comprobaciones aisladas, no una observación continua del servidor de producción.

## Evidencia local

Archivos de trabajo privados, conservados localmente y excluidos del repositorio:

- `work/core-connections-before-review.json`: estado previo de fuentes, candidatos, incidencias y ciclos.
- `work/utility-live-ande-diagnostic.json`: respuesta anti-bot reconocida aunque el HTTP sea 200.
- `work/utility-live-essap-diagnostic.json` y `work/utility-live-essap-api.json`: consulta operativa, últimas publicaciones y consulta sin categorías.
- `work/utility-live-1000noticias-diagnostic.json` y `work/utility-live-nanduti-diagnostic.json`: búsquedas y paginación verificadas.
- `work/hydrology-live-audit-20261022.json` y `work/siaguapy-live-audit-20261022.html`: lecturas y estados de las ocho estaciones, más ejecuciones locales observadas.
- `work/cycle-latency-audit-20261022.json`: orden, duraciones e intervalos de `source_runs` y `scheduler_cycles` antes de aplicar prioridad.
- `work/core-connections-after-review.json`: consultas automáticas posteriores al cambio, leídas sin modificar la base.
- `work/filter-qa-20261022.json` y capturas `work/filters-simple-*-20261022.png`: interacciones y revisión visual del filtro en navegador real.

## Validación automática posterior al cambio

Se inició el runner con intervalo de 60 segundos y se observaron ciclos reales de `alertapy:cycle` **sin `--force`**, conservando las frecuencias de cada fuente. Lectura de la base a las 17:30:04:

| Fuente | Consulta automática observada | Resultado |
| --- | --- | --- |
| ESSAP | 17:20:32 → 17:20:33 | Éxito; cero candidatos recientes. |
| 1000 Noticias | 17:23:45 → 17:23:53 | Éxito; cero candidatos de servicios aceptados. |
| Ñandutí | 17:23:53 → 17:23:58 | Éxito; cero candidatos de servicios aceptados. |
| ANDE | 17:25:58 | Bloqueo anti-bot registrado; no se oculta el error. |
| DMH CAP | 17:25:59 | Éxito; cero alertas en ese canal. |
| ABC | 17:26:00 → 17:26:07 | Éxito; 18 candidatos observados sin duplicar publicaciones. |
| Estaciones DMH | 17:27:02 → 17:27:03 | Éxito; 99 elementos recibidos y 188 mediciones guardadas. |
| SIAGUAPY | 17:07:34 → 17:07:36 | Consulta automática exitosa previa al cambio; todavía no estaba vencida a las 17:30, por su intervalo de 30 minutos. No se adelantó artificialmente su ejecución. |

El ciclo 31 (17:20:22–17:23:44) procesó 13 fuentes, creó un candidato y una incidencia y dejó visible el fallo de ANDE. El ciclo 32 (17:23:45–17:25:57) procesó otras 14 fuentes sin errores. El ciclo 33 registró el bloqueo de ANDE y continuó con CAP, Meteorología y ABC. Los ciclos 34 y 35 se iniciaron automáticamente a las 17:27 y 17:28: actualizaron estaciones DMH y Última Hora, respectivamente. La cola general sigue avanzando y un fallo no detiene el runner.

La suite completa pasó con **330 pruebas PHP, 1681 aserciones y 35 pruebas JavaScript**. En navegador real se comprobó selección exclusiva de Electricidad, Agua y Ríos, retorno a Todas, navegación por teclado, combinación de categorías, fuente, radio y período con indicador visible al plegar las opciones, y encendido/apagado independiente de la cartografía de ríos. En 320, 390, 768 y 1440 píxeles no hubo desbordes horizontales del documento ni del panel; los controles de categorías miden al menos 48 píxeles de alto. Esto valida el entorno local, no sustituye los ciclos de staging desde Hostinger.

## Verificación de respaldo de electricidad (23/10)

La Tribuna ya está en el catálogo como respaldo periodístico y el ciclo local la consultó correctamente el 23/10 a las 08:27:17: 11 elementos detectados, sin elementos nuevos y sin error de conexión. Su nota propia sobre cortes programados del 07/10 confirma que publica zonas y horarios, pero quedó fuera de la ventana vigente de siete días. El recolector usa portada y listados HTML permitidos; `robots.txt` excluye los feeds, que no se consultan. Para absorber publicaciones tras interrupciones del runner, su ventana de lectura se alineó a las 168 horas de retención. La Nación, Última Hora, HOY y La Tribuna se adelantan en la cola automática junto con las otras fuentes de servicios y prensa. [Nota de La Tribuna](https://www.latribuna.com.py/nacionales/locales/2026/10/07/sin-luz-hoy-estas-son-las-zonas-donde-ande-hara-cortes-hasta-las-1800/).

El ciclo 128, iniciado el 23/10 a las 08:02, procesó 20 fuentes y publicó 12 incidencias; ANDE fue el único error y quedó registrado como bloqueo anti-bot. El ciclo continuó con Meteorología, medios, hidrología y el resto de sus fuentes. Las cinco páginas añadidas en esta revisión terminaron con éxito: Asunción Actual encontró una nota, Ahora Paraguay cuatro, El Nacional dos, La Clave dos y Ratio Paraguay ninguna que superara los filtros. Una consulta exitosa con cero avisos publicables no se cuenta como corte.

La base conserva un corte futuro de electricidad publicado por La Nación: el suministro del Palacio de Justicia en Asunción está anunciado para el 24/10, de 08:00 a 11:00, con el enlace original y estado `scheduled`. El aviso conjunto de Ahora Paraguay para el 20/10 se separó entre electricidad y agua y venció al pasar su horario, sin renovarse en cada lectura. Esto demuestra que los avisos de prensa pueden alimentar el mapa aunque ANDE falle.

La cobertura no es exhaustiva: ANDE publicó en su [página oficial](https://www.ande.gov.py/interna.php?id=15704) 17 zonas de la Región Metropolitana para el 23/10. La consulta directa de la aplicación fue bloqueada y las cinco páginas alternativas no habían republicado ese listado en la corrida de las 08:02. Por eso, la automatización puede incorporar un aviso publicado por sus fuentes alternativas, pero no puede prometer todas las zonas si ningún medio cubre el comunicado.

En el ciclo automático siguiente, iniciado a las 08:07:39, las estaciones DMH entregaron 99 elementos y se guardaron 202 cambios; La Nación detectó tres notas y publicó un aviso nuevo. ESSAP, 1000 Noticias y Ñandutí respondieron en el ciclo de las 08:02, sin hallar avisos de agua nuevos. SIAGUAPY y ERSSAN también finalizaron correctamente sin alertas, lo que significa «sin incidencia que publicar», no una falla de conexión. La geometría de ríos sigue siendo una capa cartográfica estática. La lectura pública de [SIAGUAPY](https://siaguapy.mades.gov.py/alertas-hidrologicas) identifica sus estaciones y frecuencias de actualización; en la captura revisada los niveles estaban fechados el 22/10, así que no presento esa página como dato del 23/10. [Meteorología/DMH](https://www.meteorologia.gov.py/avisos/) publicó un aviso para el sur de Itapúa y Misiones el 23/10 a las 06:25:54, que el ciclo leyó correctamente.

Hubo un timeout de 15 segundos al abrir una nota de Diario HOY en el ciclo de las 08:07. El runner registró el fallo y continuó con las otras fuentes; ANDE volvió a reportar el bloqueo anti-bot en el ciclo iniciado a las 08:10. Estos errores quedan visibles y sujetos a reintento, no se convierten en una falsa confirmación.

También se evaluó [RDN](https://www.rdn.com.py/2026/10/18/ande-anuncia-cortes-de-luz-este-domingo-zonas-y-horarios/) como posible respaldo adicional: su nota del 18/10 detalla cortes con zonas y horarios, pero declara como fuente a ABC Color, ya habilitado en el ciclo. Se descartó agregar RDN como fuente independiente para no duplicar el mismo aviso ni inflar la cobertura.

Para esta actualización, las 37 pruebas JavaScript pasaron y la plantilla Blade compiló sin errores. El PHP local carece de `mbstring`, por lo que PHPUnit no pudo iniciar allí; el CI de GitHub para el commit `748b6c9ee273eb4ce5a059b29f1620b1bc678027` pasó los trabajos PHP 8.2/8.3 con SQLite, prueba de interacciones del mapa, validación de scripts y smoke test de migración MySQL.

## Comprobación pendiente en Hostinger

La configuración propuesta es un único cron cada minuto (`* * * * *`) que invoque el wrapper `scripts/run-cycle.sh` con las rutas reales del proyecto y PHP. La [documentación oficial de Hostinger sobre sintaxis cron](https://www.hostinger.com/ca/tutorials/crontab-syntax/) explica esa cadencia. Confirmar primero que el plan admita la configuración y `flock`; seguir las instrucciones de [despliegue](HOSTINGER_DEPLOYMENT.md). No se ha creado ese job en la cuenta del usuario.

Después de instalar en staging, comprobar al menos tres ciclos sin `--force`, los intervalos reales por fuente, la fecha de las publicaciones, la deduplicación y los enlaces originales. Inspeccionar las conexiones desde el backoffice autenticado y los logs: una página `/up` disponible o un deploy correcto no demuestran que las fuentes sean accesibles. ANDE continúa como limitación externa hasta que su canal permita una consulta autorizada estable. El redeploy actualiza código; el cron mantiene las noticias en la base persistente.
