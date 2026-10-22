# Reglas de scraping

## Principio

Scraping HTML es el último recurso. Antes de implementarlo se debe comprobar si existe API oficial, RSS, feed o JSON público adecuado.

## Conducta de red

- User-Agent identificable y contacto configurable.
- Timeout corto y reintentos limitados con espera creciente.
- Respeto de términos, `robots.txt` y límites razonables.
- ETag e If-Modified-Since cuando la fuente los proporcione.
- Hash SHA-256 para no reprocesar contenido idéntico.
- Frecuencia controlada por `sources.polling_minutes`.
- Un fallo de fuente no detiene las demás.
- Nunca evadir CAPTCHA, autenticación o mecanismos anti-bot.

## Diseño de collectors

Cada fuente implementará `CollectorInterface` y devolverá una colección de `CandidateDTO`. Selectores, limpieza y particularidades quedan encapsulados en su clase. Un cambio en ABC Color no debe alterar ANDE u otros collectors.

El collector extrae evidencia; no escribe directamente en el mapa. El flujo posterior normaliza, ubica y busca duplicados. Los dominios oficiales con reglas verificadas pueden publicarse determinísticamente mediante `ALERTAPY_OFFICIAL_SOURCES_PUBLISH=true`, sin IA. La prensa permitida puede publicarse mediante las reglas territoriales auditables con `ALERTAPY_LOCAL_RULES_PUBLISH=true`; OpenCode no es obligatorio.

La detección territorial se resuelve contra `locations` y `location_aliases`, no mediante listas privadas dentro de cada collector. Una coincidencia aporta una consulta geográfica canónica y metadatos estructurados. Las coordenadas locales tienen prioridad; una respuesta externa válida dentro de Paraguay se guarda tanto en el catálogo como en `geocoding_cache` para que las corridas siguientes sean automáticas y no repitan la consulta.

ESSAP se consume desde su API pública oficial de WordPress antes que recurrir a HTML. El filtro exige simultáneamente contexto de agua, un acontecimiento operativo y una ubicación territorial. La ausencia de resultados válidos es una corrida exitosa con cero elementos, no una razón para inferir o fabricar incidencias.

Última Hora y La Nación no ofrecen en este flujo un feed público utilizable, por lo que sus collectors recorren listados HTML públicos y leen metadatos Open Graph o JSON-LD de cada nota. Antes de descargar el detalle se exige una ubicación paraguaya y una categoría territorial reconocible. Para los departamentos del Chaco se amplía el interés público a comunidades indígenas, ambiente, caminos, agua, salud, emergencias y actividades extractivas, manteniendo siempre una ubicación verificable.

ANDE responde actualmente con una página de verificación Radware a clientes HTTP simples. El collector reconoce esa respuesta como bloqueo, la registra y termina sin candidatos. No se ejecuta JavaScript de desafío, no se imitan sesiones humanas y no se intenta eludir la protección. Una captura asistida solo puede contener el contenido público que un navegador normal ya mostró, se guarda dentro de `storage/app/private` y se importa sin cookies ni encabezados de sesión.

## Contenido

- No renderizar HTML obtenido directamente.
- Sanitizar o convertir a texto antes de persistir contenido normalizado.
- Conservar `raw_content` para auditoría con acceso interno.
- Guardar URL directa, título y fechas sin reemplazarlos.
- Rechazar candidatos sin fecha, fuente o utilidad territorial mínima.
- No borrar `raw_items` cuando una incidencia vence; su retención es independiente de la visibilidad temporal del mapa.

## Tests

Los parsers se prueban con fixtures locales versionados. La suite no depende de internet. Cada cambio de selector debe incorporar un fixture que reproduzca la estructura observada, sin copiar contenido personal innecesario.
