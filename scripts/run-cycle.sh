#!/usr/bin/env bash
# Hostinger Custom cron: /bin/bash /absolute/project/scripts/run-cycle.sh /absolute/php
set -Eeuo pipefail
umask 077

app_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
php_bin="${1:-php}"
cd -- "$app_dir"

command -v "$php_bin" >/dev/null || { printf 'PHP no disponible.\n' >&2; exit 1; }
command -v flock >/dev/null || { printf 'flock no disponible; revisar el plan del hosting.\n' >&2; exit 1; }
[[ -f .env && -f vendor/autoload.php && -d storage/framework ]] || {
    printf 'La aplicación todavía no está instalada.\n' >&2
    exit 1
}

# Shared lock permits the application's own cycle lock to handle duplicate cron runs,
# while preventing a deploy from changing code during an active collection.
exec 9>storage/app/deployment.lock
flock -sn 9 || { printf 'Despliegue en curso; se omite este ciclo.\n'; exit 0; }
[[ ! -f storage/framework/down ]] || { printf 'Mantenimiento activo; se omite este ciclo.\n'; exit 0; }

exec "$php_bin" artisan schedule:run --no-interaction
