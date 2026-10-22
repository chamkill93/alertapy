# Solución de problemas

## Pantalla 500

1. Confirmá `APP_KEY`, conexión a base y permisos.
2. Ejecutá `php artisan about` y `php artisan migrate:status`.
3. Revisá `storage/logs/laravel.log` sin copiar tokens a tickets públicos.
4. Limpiá cache tras cambiar `.env`:

```bash
php artisan optimize:clear
```

En producción, restaurá `config:cache`, `route:cache` y `view:cache` después de resolver.

## `could not find driver`

Falta `pdo_mysql` en producción o `pdo_sqlite` para tests. Habilitá la extensión correspondiente desde PHP Configuration. MariaDB de producción debe usar `DB_CONNECTION=mysql` o `mariadb`.

## Composer usa otra versión PHP

El PHP del sitio y el CLI pueden diferir. Ejecutá `php -v`. En Hostinger podés anteponer el binario correspondiente:

```bash
/opt/alt/php83/usr/bin/php /usr/local/bin/composer2 install --no-dev --optimize-autoloader
```

Adaptá la versión y ruta según hPanel.

## 404 en todas las rutas

- Confirmá que el dominio apunte a `public/` o que exista el `.htaccess` raíz.
- Verificá que `public/.htaccess` se haya subido.
- Ejecutá `php artisan route:list --except-vendor`.
- Limpiá `route:cache` si las rutas cambiaron.

## El mapa aparece gris

- Revisá la consola del navegador y conectividad con `tile.openstreetmap.org` y `unpkg.com`.
- Confirmá que el contenedor `#map` tenga altura; `public/css/alertapy.css` debe responder 200.
- No elimines la atribución de OpenStreetMap.

## El mapa está vacío

Es correcto si todavía no existen incidencias reales vigentes. Consultá `/api/incidents?period=7d` para revisar los últimos 7 días, incluidos los eventos finalizados. Si `data` sigue vacío, no agregues filas ficticias: verificá collectors, decisiones, fechas del evento y `published_at`.

## La API devuelve 422

Revisá filtros. `bbox` usa `south,west,north,east`, con sur menor que norte y oeste menor que este. `period` solo admite `now`, `1h`, `6h`, `24h` o `7d`. El periodo histórico ya no está disponible.

## Cambié `.env` y no se refleja

```bash
php artisan config:clear
php artisan config:cache
```

Nunca edites `config/*.php` con credenciales.

## Tests

```bash
composer test
```

La suite no usa red. Si falla por SQLite, habilitá `pdo_sqlite` y `sqlite3` en el PHP CLI. Los tests no prueban acceso en vivo a medios o instituciones.

## Cron se solapa o agota tiempo

Verificá primero manualmente `php artisan alertapy:cycle`. El comando ya incluye lock, límites por lote y checkpoint; luego revisá que hPanel use la ruta PHP correcta y un intervalo de cinco minutos.
