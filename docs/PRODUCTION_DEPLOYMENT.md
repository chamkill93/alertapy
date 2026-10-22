# Operación y despliegue de producción

Complementa [HOSTINGER_DEPLOYMENT.md](HOSTINGER_DEPLOYMENT.md). No hay destino remoto ni auto-deploy activo incluido. El flujo preparado es CI → SHA aprobado → backup → script por SSH → verificación, con ingesta independiente mediante un cron propuesto cada minuto. Falta configurar y probar ese cron en la cuenta real de Hostinger.

## Antes de cada cambio

- Confirmar CI verde para el SHA elegido y probar PHP/DB equivalentes en staging. La CI tiene tests SQLite y una instalación/migración MySQL; no es una prueba completa de la DB de producción.
- Revisar migraciones y seeders: `db:seed` reaplica catálogos y puede actualizar configuración de fuentes. Revisar overrides operativos.
- Desactivar otros mecanismos de auto-deploy sobre este checkout.
- Respaldar DB, `storage/app`, uploads/datasets y `.env` fuera de la raíz pública; conservar el SHA previo.
- Si el esquema cambia de forma incompatible, pausar cron y escrituras antes del backup. Preferir migraciones aditivas y una reparación hacia adelante.

## Backup reproducible

Crear archivo de credenciales MySQL privado, fuera de la raíz pública, modo `600`, sin versionarlo. No pasar contraseñas en argumentos:

```ini
[client]
host=HOST_REAL
user=USUARIO_REAL
password=CLAVE_PRIVADA
```

Ejemplo, reemplazando rutas y base reales:

```bash
umask 077
backup_dir=/RUTA/PRIVADA/backups
stamp=$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p "$backup_dir"
mysqldump --defaults-extra-file=/RUTA/PRIVADA/mysql-backup.cnf --single-transaction --quick --no-tablespaces BASE_REAL > "$backup_dir/database-$stamp.sql"
tar -czf "$backup_dir/storage-$stamp.tar.gz" -C /RUTA/DEL/PROYECTO storage/app
git -C /RUTA/DEL/PROYECTO rev-parse HEAD > "$backup_dir/commit-$stamp.txt"
```

Comprobar el resultado de **cada** comando; no usar dump vacío o parcial. MariaDB puede requerir `mariadb-dump`. `--single-transaction` presupone tablas transaccionales y no protege cambios concurrentes de esquema. Respaldar `.env` en gestor de secretos y copiar backups a almacenamiento separado. Definir retención y restaurar periódicamente en una base aislada. El script solo comprueba tamaño y antigüedad: no certifica que el dump sea restaurable ni de la base correcta.

## Aplicar una actualización

Requiere instalación funcional, `main` limpio, PHP 8.2+, Composer 2, `flock` y backup de menos de 24 horas. `PHP_BIN` y `COMPOSER_BIN` seleccionan los ejecutables reales. Dentro del checkout del servidor:

```bash
git fetch --no-tags origin main
git log --oneline HEAD..origin/main
bash scripts/deploy-hostinger.sh --commit SHA_COMPLETO_DE_40_CARACTERES --backup /RUTA/PRIVADA/database-FECHA.sql
```

Tras revisar la validación, aplicar ese mismo SHA:

```bash
bash scripts/deploy-hostinger.sh --commit SHA_COMPLETO_DE_40_CARACTERES --backup /RUTA/PRIVADA/database-FECHA.sql --apply
```

El script toma lock exclusivo compartido con `run-cycle.sh`; si hay recolección activa falla sin modificar el deploy. No combinarlo con cron que invoque `artisan` directamente. Activa mantenimiento, hace `git merge --ff-only`, instala `composer.lock` con `install`, migra, aplica catálogos, reconstruye caches y consulta `alertapy:status`. Si termina, reabre el sitio y guarda `storage/app/deployed-commit.txt`.

No ejecuta scraping durante el deploy ni modifica secretos. Las migraciones modifican datos y no se revierten automáticamente. `alertapy:status` no exige todas las fuentes saludables para salir con éxito: revisar la salida y completar smoke tests.

## Validación posterior

- Probar HTTPS y respuestas de `/up`, `/`, `/api/categories`, `/api/incidents` y `/api/sources/status`; comprobar el contenido además del status HTTP.
- Abrir mapa y filtros: «Ahora», periodos dentro de los últimos siete días, capas y zonas; verificar fuente y ubicación coherente de registros nuevos. No existe periodo histórico fuera de esa retención.
- Comprobar que `.env`, `.git/config`, archivos privados y backups no se descargan desde el dominio.
- Probar autenticación de `/admin/coverage`, reportes comunitarios y privacidad de fotos.
- Observar el próximo cron, ejecutar `php artisan alertapy:status` y revisar logs rotados. Cero publicaciones no implica falla: revisar candidatos y filtros.
- Completar la [verificación de conexiones](PRE_HOSTINGER_SOURCE_CHECKS.md) desde el servidor: los bloqueos de ANDE y otros proveedores pueden variar según IP. Comprobar al menos tres ciclos sin `--force`, incluidas las conexiones prioritarias vencidas y el avance del checkpoint general.
- Comparar conteos antes/después para detectar pérdidas; revisar disco, CPU, duración del ciclo y acumulación de datos/logs.

## Fallo y recuperación

Si falla un paso tras activar mantenimiento, se conserva el sitio cerrado y se muestra el SHA previo. Inspeccionar el error por SSH. Si falló antes de migrar, arreglar dependencias/configuración y completar los pasos; si hubo migración, comprobar su estado antes de reintentar. El script rechaza mantenimiento preexistente para no retirarlo por accidente.

Preferir un commit correctivo compatible con el esquema actual. Para volver a código anterior, preparar su SHA en un checkout/release separado, instalar su lock y comprobar compatibilidad con la DB antes de cambiar la raíz pública. No usar `git reset --hard`, `migrate:fresh` ni `migrate:rollback` sin revisar las operaciones y pérdidas posibles.

Para restaurar datos, mantener el sitio cerrado y cron pausado; restaurar dump en una base nueva, validar tablas/conteos, recuperar archivos asociados y apuntar `.env` a la base verificada. Esto puede perder escrituras posteriores al backup: conservar la base fallida para reconciliarlas. Reconstruir caches, probar y después ejecutar `php artisan up` y reanudar cron.

## Antes de habilitar auto-deploy

1. Destino, rutas, PHP y credenciales reales confirmados; staging operativo.
2. Tres jobs CI obligatorios en `main`; desplegar el SHA aprobado sin cambiarlo por una rama móvil.
3. Restauración ensayada; migraciones compatibles; `.env` y datos persistentes preservados.
4. Un único ejecutor, lock compartido con cron, huella SSH verificada y secretos limitados al environment.
5. Smoke tests y responsable que reciba fallas del deploy/scheduler; capacidad probada con las fuentes habilitadas.

La cobertura depende de disponibilidad/permisos de fuentes, fecha de publicación, geografía, deduplicación y revisión de candidatos. Medir fuentes habilitadas/saludables y antigüedad junto con cantidad de incidencias; no prometer cobertura total de Internet.
