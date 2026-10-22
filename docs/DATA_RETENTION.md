# Retención de 7 días

AlertaPY mantiene una ventana móvil de 7 días (168 horas) para el contenido de noticias, incidencias y registros operativos. La vigencia de “Ahora” es más corta y depende de cada categoría: retirar un punto de “Ahora” y eliminar sus datos son procesos distintos.

## Fechas que gobiernan la limpieza

| Contenido | Fecha de antigüedad |
| --- | --- |
| Incidencias y sus fotos | Deben estar dentro de la ventana tanto la fecha del hecho (`occurred_at`, `starts_at`, `published_at`, `detected_at`, `created_at`) como la fecha de publicación (`published_at`, `detected_at`, `created_at`), usando la primera disponible de cada grupo |
| Artículos recolectados (`raw_items`) | `published_at`, o `detected_at`, o `created_at` |
| Candidatos de moderación | `created_at`, o eliminación del artículo al que pertenecen |
| Actualizaciones y auditorías | `occurred_at` / `audited_at`, o eliminación de la incidencia |
| Votos comunitarios | `created_at`, o eliminación de la incidencia |
| Corridas de fuentes, ciclos y muestras del parser | `started_at` / `observed_at` |
| Mediciones de estaciones | `measured_at` |
| Alertas meteorológicas CAP | `sent_at`, o `starts_at`, o `created_at` |

El límite es estricto: se eliminan fechas anteriores al instante de hace 7 días; la fecha exactamente igual al límite permanece hasta el siguiente ciclo. Cambiar `updated_at`, repetir una consulta o actualizar `last_seen_at` no renueva contenido antiguo. Un anuncio publicado hace más de 7 días se elimina aunque su evento todavía sea futuro: programarlo para más adelante no amplía su almacenamiento.

Al retirar una incidencia también se eliminan sus referencias, votos, auditorías y actualizaciones mediante las relaciones de la base. Si una noticia antigua respalda una incidencia todavía vigente, se elimina el contenido crudo y se conserva la referencia con el título y URL original dentro de esa incidencia. De esta forma el evento mantiene su atribución durante su propia ventana de retención.

No se eliminan cuentas de usuarios, credenciales de conexión, fuentes, categorías, estaciones ni capas estables de infraestructura, rutas o territorios. La limpieza de archivos se limita al directorio privado `community-reports`; no recorre otros directorios. Las fotos compartidas por una incidencia conservada permanecen. También se retiran archivos huérfanos de ese directorio que tengan más de 7 días.

## Ejecución automática y revisión manual

`alertapy:cycle` aplica la retención al comienzo de cada ciclo, antes de contar nuevos artículos o consultar fuentes. Funciona con el mismo cron de Laravel y con el ejecutor local; no necesita un segundo cron. Un bloqueo compartido impide dos purgas simultáneas. La eliminación relacional utiliza una transacción y lotes de 500 IDs; las fotos se eliminan después de confirmar la transacción. Un error detiene el ciclo y queda registrado como fallo del scheduler.

Para previsualizar cantidades sin borrar contenido ni fotos:

```bash
php artisan alertapy:prune-data --dry-run
```

Para aplicar la misma política inmediatamente:

```bash
php artisan alertapy:prune-data
```

Antes de la primera aplicación sobre datos existentes, detener el ejecutor automático, revisar la simulación y guardar un respaldo de la base y de las fotos que se necesiten conservar. Un respaldo es una copia operativa separada: debe tener su propia caducidad; el comando no elimina respaldos ni archivos de logs de Laravel. Después se puede reanudar el cron.

## Evitar que vuelva contenido antiguo

Los ingestors comprueban la fecha antes de almacenar un artículo. Omiten publicaciones de más de 7 días, fechas de publicación futuras y contenido sin ninguna fecha verificable de publicación o evento. Los cronogramas de ANDE sin fecha de publicación pueden utilizar su fecha explícita de trabajo. Los proveedores de descubrimiento requieren fecha de publicación. El resultado `ignored_date` aparece como “omitidos por fecha” en la salida del recolector.

Las mediciones DMH/MADES y alertas CAP también rechazan observaciones/publicaciones antiguas o futuras, por lo que una fuente que sigue devolviendo su última observación obsoleta no llena nuevamente las tablas después de la purga. La ficha estable de una estación permanece disponible para identificar la conexión sin conservar mediciones vencidas.
