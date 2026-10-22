#!/usr/bin/env bash
# Upgrade an existing Hostinger checkout. Default is validation only, with no writes.
# First install is documented in docs/HOSTINGER_DEPLOYMENT.md.
set -Eeuo pipefail
umask 077

usage() {
    cat <<'HELP'
Uso: bash scripts/deploy-hostinger.sh --commit SHA --backup /ruta/backup.sql [--apply]

Requiere un checkout limpio, un commit ya descargado de origin/main y un dump SQL
reciente fuera de la aplicación. --apply habilita mantenimiento, actualización
fast-forward, Composer, migraciones, seeders y caches. No ejecuta git fetch.
PHP_BIN y COMPOSER_BIN permiten seleccionar ejecutables del hosting.
HELP
}
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
main() {
apply=false
commit=''
backup=''
while (($#)); do
    case "$1" in
        --commit) [[ $# -ge 2 ]] || fail 'Falta SHA.'; commit="$2"; shift 2 ;;
        --backup) [[ $# -ge 2 ]] || fail 'Falta backup.'; backup="$2"; shift 2 ;;
        --apply) apply=true; shift ;;
        --help|-h) usage; exit 0 ;;
        *) usage >&2; fail 'Argumento desconocido.' ;;
    esac
done

app_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
php_bin="${PHP_BIN:-php}"
composer_bin="${COMPOSER_BIN:-composer2}"
cd -- "$app_dir"

[[ "$commit" =~ ^[0-9a-fA-F]{40}$ ]] || fail 'Usá el SHA completo de 40 caracteres validado por CI.'
[[ "$backup" = /* && -f "$backup" && -s "$backup" && -r "$backup" ]] || fail 'Falta un backup SQL absoluto, legible y no vacío.'
for binary in git flock realpath stat "$php_bin" "$composer_bin"; do
    command -v "$binary" >/dev/null || fail "No se encontró: $binary"
done
backup="$(realpath -- "$backup")"
[[ "$backup" != "$app_dir/"* ]] || fail 'Guardá el backup fuera del proyecto y del document root.'
backup_age=$(( $(date +%s) - $(stat -c %Y -- "$backup") ))
(( backup_age >= 0 && backup_age <= 86400 )) || fail 'El backup debe tener menos de 24 horas.'
[[ -f .env && -f vendor/autoload.php ]] || fail 'Completá primero la instalación inicial.'
[[ -z "$(git status --porcelain)" ]] || fail 'El checkout tiene cambios; revisalos antes de desplegar.'
[[ "$(git branch --show-current)" = main ]] || fail 'El checkout de producción debe estar en main.'
git cat-file -e "$commit^{commit}" || fail 'Descargá primero el commit desde origin.'
git merge-base --is-ancestor "$commit" origin/main || fail 'El commit no pertenece a origin/main.'
git merge-base --is-ancestor HEAD "$commit" || fail 'Solo se permiten actualizaciones fast-forward.'
[[ -w storage/framework && -w storage/app && -w bootstrap/cache ]] || fail 'Faltan permisos de escritura de Laravel.'
"$php_bin" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || fail 'Se requiere PHP 8.2 o superior.'
"$php_bin" -r '
require "vendor/autoload.php";
$v = Dotenv\Dotenv::parse(file_get_contents(".env"));
exit(($v["APP_ENV"] ?? "") === "production"
    && filter_var($v["APP_DEBUG"] ?? "true", FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false
    && !empty($v["APP_KEY"]) ? 0 : 1);
' || fail 'Revisá APP_ENV=production, APP_DEBUG=false y APP_KEY en .env.'

old_commit="$(git rev-parse HEAD)"
printf 'Actual: %s\nObjetivo: %s\nBackup: %s\n' "$old_commit" "$commit" "$backup"
if [[ "$apply" != true ]]; then
    printf 'Validación completa. Revisá CI y restauración del backup; agregá --apply para ejecutar.\n'
    exit 0
fi

exec 9>storage/app/deployment.lock
flock -n 9 || fail 'Existe otro deploy o un ciclo activo; reintentá cuando termine.'
[[ ! -f storage/framework/down ]] || fail 'Ya existe mantenimiento activo; verificá el despliegue anterior.'
maintenance=false
on_exit() {
    result=$?
    if ((result != 0)) && [[ "$maintenance" = true ]]; then
        printf 'Falló el despliegue. Se conserva mantenimiento. Commit previo: %s\nRevisá docs/PRODUCTION_DEPLOYMENT.md antes de ejecutar artisan up.\n' "$old_commit" >&2
    fi
}
trap on_exit EXIT

"$php_bin" artisan down --retry=60
maintenance=true
git merge --ff-only "$commit"
"$composer_bin" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
"$composer_bin" check-platform-reqs --no-dev
"$php_bin" artisan config:clear
"$php_bin" artisan migrate --force --no-interaction
"$php_bin" artisan db:seed --force --no-interaction
"$php_bin" artisan config:cache
"$php_bin" artisan route:cache
"$php_bin" artisan view:cache
"$php_bin" artisan alertapy:status
"$php_bin" artisan up
maintenance=false
printf '%s\n' "$commit" >storage/app/deployed-commit.txt
printf 'Despliegue aplicado. Verificá HTTPS, /up, APIs y el próximo ciclo; ver docs/PRODUCTION_DEPLOYMENT.md.\n'
}

# Parse the function completely before git can replace this script during an upgrade.
main "$@"
