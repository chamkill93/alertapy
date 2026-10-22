# GitHub y Hostinger: instalación y automatización

Revisado el 22 de octubre de 2026. El proyecto usa Laravel 12, PHP 8.2+ y MySQL/MariaDB en producción. El código se actualiza al desplegar; las noticias se actualizan mediante cron contra la base persistente. Recibir una noticia no debe crear un commit ni provocar un redeploy.

## Destino confirmado

- Repositorio: [chamkill93/alertapy](https://github.com/chamkill93/alertapy), rama `main`.
- Dominio de producción: `https://www.alertaparaguay.com`.
- Primer commit publicado: `ceab031`; los tres jobs de CI aprobaron ese commit. Los cambios posteriores requieren su propia corrida aprobada.
- Configuración revisable: [`deploy/hostinger/production.env.example`](../deploy/hostinger/production.env.example). Contiene el dominio y valores de producción, con credenciales vacías.

El dominio confirmado no determina el servidor ni demuestra que DNS o SSL estén configurados. Confirmar en hPanel el plan, IP de destino, rutas y acceso antes de cambiar registros. Configurar el dominio raíz `alertaparaguay.com` y su redirección al host `www` después de verificar el destino y los certificados de ambos nombres.

La consulta DNS del 22/10/2026 mostró los nameservers `dns1.registrar-servers.com` y `dns2.registrar-servers.com`, y `www` como CNAME hacia `parkingpage.namecheap.com`, seguido por `parking.d.parity.domains`. Es un destino de parking de Namecheap; no usar su dirección como IP del hosting. Antes de modificar A/CNAME o nameservers, obtener el destino real desde la cuenta de Hostinger y revisar los registros de correo existentes. Después de la propagación, verificar HTTPS para `alertaparaguay.com` y `www.alertaparaguay.com` y aplicar la redirección al dominio canónico.

## Opciones del hosting

Hostinger documenta integración GitHub/GitLab mediante OAuth en **Websites → Dashboard → Advanced → Git**, para sitios PHP/HTML en hosting Web/Cloud. Permite elegir repositorio, rama y directorio, redeploy manual y despliegue automático. Tutoriales antiguos muestran otro flujo con webhooks. [Guía oficial actual de Git](https://www.hostinger.com/support/1583302-how-to-deploy-a-git-repository-in-hostinger/).

Para AlertaPY conviene comenzar con SSH y una rama `main` protegida: así Composer, backup, migraciones y cron forman una secuencia verificable. La documentación de la integración Git no garantiza que ejecute nuestro script posterior ni migraciones Laravel. El auto-deploy nativo solo debe activarse después de comprobar esos pasos en staging; tampoco sustituye los checks de CI. No combinar dos mecanismos que escriban el mismo checkout.

SSH está disponible en Premium Web y superiores; Composer 2 se invoca como `composer2`. Confirmar las capacidades del plan contratado. [SSH oficial](https://www.hostinger.com/support/1583245-how-to-connect-to-a-hosting-plan-via-ssh-in-hostinger/), [Composer oficial](https://www.hostinger.com/support/5792078-how-to-use-composer-at-hostinger/).

## Datos pendientes para conectar producción

| Dato o decisión | Uso |
| --- | --- |
| Plan Hostinger, IP de destino y subdominio de staging | Verificar capacidades, DNS y ensayo |
| Host, puerto SSH, usuario y huella del servidor | Transporte verificado |
| Ruta absoluta de aplicación y document root | Separar código privado de `public/` |
| Credenciales MySQL/MariaDB | `.env` privado en el servidor |
| Destino de backups fuera de la raíz pública y retención | Recuperación |
| Contacto real del User-Agent y responsable operativo | Fuentes y atención de fallas |
| Claves opcionales de NASA FIRMS, MADES, X, YouTube o Telegram | Cobertura adicional autorizada |

La CI incluida no requiere secretos del hosting. Para un futuro workflow SSH, usar environment `production` y una clave dedicada (`HOSTINGER_SSH_KEY`), `HOSTINGER_HOST`, `HOSTINGER_PORT`, `HOSTINGER_USER`, `HOSTINGER_KNOWN_HOSTS` verificado y `HOSTINGER_APP_PATH`. DB y claves de fuentes permanecen en el servidor. Algunas protecciones de environments dependen del plan GitHub y visibilidad del repo. [Environments oficiales](https://docs.github.com/en/actions/concepts/workflows-and-actions/deployment-environments).

## 1. Preparar GitHub

1. Revisar `git status` y qué se publicará; excluir `.env`, SQLite, dumps, evidencia privada, logs y `vendor`.
2. Usar el remoto confirmado `https://github.com/chamkill93/alertapy.git` y conservar la identidad real del autor.
3. Mantener `composer.lock` versionado en `main`; el primer commit y su CI ya están publicados.
4. Dejar que `.github/workflows/ci.yml` valide cada cambio en PHP 8.2/8.3, tests, Bash y migraciones/seeders MySQL 8.0. Las contraseñas del servicio MySQL del YAML son solo de una base efímera de CI.
5. Exigir los tres jobs en la protección de `main` y revisar PRs. Dependabot propone actualizaciones; no las fusiona ni despliega.

La CI también admite ejecución manual cuando el workflow existe en la rama predeterminada. No realiza scraping real ni publica en Hostinger. [Ejecución manual de Actions](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/manually-run-a-workflow).

## 2. PHP y raíz pública

Seleccionar PHP 8.2+ en hPanel y comprobar PHP web y CLI. PHP 8.3 está incluido en la CI. Habilitar Ctype, cURL, DOM, Fileinfo, Mbstring, OpenSSL, PDO MySQL, Session, Tokenizer, XML y OPcache. [Soporte oficial de Laravel](https://www.hostinger.com/support/which-programming-languages-and-frameworks-are-supported-at-hostinger/).

```bash
php -v
php -m
composer2 --version
command -v php composer2 flock
```

Si `PHP_BIN` selecciona otro PHP, comprobar también qué PHP usa `composer2`. La raíz web debe ser **solo `public/`**. Preferir el proyecto fuera de `public_html`, con document root dirigido a su `public`. La ruta de instalación Git y el document root son ajustes distintos. Si el plan impide esta estructura, resolverla con soporte y probarla en staging antes de automatizar; el `.htaccess` raíz no debe asumirse como aislamiento suficiente.

No publicar `.git`, `.env`, `storage`, `database`, `vendor`, `scripts` ni backups. No regenerar `APP_KEY` en cada deploy. No se necesitan Node, Redis, Docker ni workers residentes en el hosting; MySQL de CI sí usa un contenedor efímero en GitHub.

## 3. Instalación inicial por SSH

Crear DB y usuario exclusivos, con `utf8mb4`. Clonar `main` en la ruta acordada; usar deploy key de lectura si es privado. Mantener desactivado auto-deploy nativo cuando se elige este procedimiento SSH.

En una instalación nueva, copiar la plantilla de producción. No sobrescribir el `.env` de un sitio existente ni copiar el `.env` del preview local.

```bash
cd /RUTA/ABSOLUTA/DEL/PROYECTO
umask 077
cp deploy/hostinger/production.env.example .env
```

Completar `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD` con los datos reales de hPanel. La plantilla deja `APP_KEY` vacío para generarlo una única vez durante la instalación. Las contraseñas y claves permanecen en ese `.env` privado; no se suben al repositorio. Valores principales ya preparados:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://www.alertaparaguay.com
APP_TIMEZONE=America/Asuncion
DB_CONNECTION=mysql
DB_HOST=
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=sync
LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=info
ALERTAPY_LOCAL_RULES_PUBLISH=true
ALERTAPY_OFFICIAL_SOURCES_PUBLISH=true
ALERTAPY_SCHEDULER_STALE_MINUTES=15
ALERTAPY_SOURCE_USER_AGENT="AlertaPY/1.0 (+https://www.alertaparaguay.com)"
```

`SESSION_DOMAIN=null` limita la cookie al host de acceso; iniciar sesión en el dominio canónico `www`. El correo saliente queda sin proveedor, con `MAIL_MAILER=log`, hasta disponer de un remitente verificado. Revisar un contacto operativo real para los User-Agent antes de habilitar consultas externas.

El backoffice requiere un usuario administrador; `ALERTAPY_ADMIN_TOKEN` solo sirve a integraciones administrativas anteriores y puede quedar vacío si no se usan. El cron por CLI tampoco necesita `ALERTAPY_CRON_SECRET`. Activar fuentes con credenciales solo cuando existan claves válidas. El descubrimiento social produce candidatos para verificación, no confirmaciones automáticas. Respetar reglas de cada fuente, frecuencia y uso de geocodificación.

```bash
composer2 install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer2 check-platform-reqs --no-dev
php artisan key:generate --force
php artisan migrate --seed --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan alertapy:status
```

Los seeders cargan catálogos; las incidencias se obtienen por ingesta. No llevar SQLite mediante Git. Para preservar datos locales reales, planificar exportación/importación compatible por separado. Dar al usuario PHP escritura en `storage` y `bootstrap/cache`, sin `777`. Activar SSL antes de operar con cookies seguras y credenciales.

Crear la cuenta administrativa por SSH contra la base de producción con `php artisan alertapy:admin CORREO_REAL --name="NOMBRE_REAL"`. El comando pide la contraseña de forma oculta y no admite `--no-interaction`; no hay cuenta ni contraseña predeterminadas. `/admin/setup` está deshabilitado en producción. Consultar [acceso al backoffice](BACKOFFICE_ACCESS.md).

Verificar `https://www.alertaparaguay.com/up`, el mapa público y `/admin/login`; iniciar sesión para revisar `/admin/connections`. Los endpoints de diagnóstico `/api/sources` y `/api/sources/status` requieren la misma sesión administrativa, por lo que una respuesta sin autenticar no demuestra una caída de la ingesta.

## 4. Noticias automáticas con cron

Probar desde SSH:

```bash
/bin/bash /RUTA/ABSOLUTA/DEL/PROYECTO/scripts/run-cycle.sh /RUTA/ABSOLUTA/PHP
```

En hPanel → Cron Jobs, elegir **Custom** y ese comando, con minutos `*/5` y los demás campos `*`. El wrapper localiza el proyecto, omite mantenimiento y comparte un lock con el deploy. Laravel controla fuentes vencidas, checkpoints y duplicación de ciclos. El wrapper exige `flock`; verificarlo en el plan.

Hostinger programa en UTC. Una frecuencia de cinco minutos no cambia por zona; el negocio usa `America/Asuncion`. hPanel permite inspeccionar la salida del job. [Cron oficial](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/).

No configurar este wrapper simultáneamente con `schedule:run`. El scheduler Laravel alternativo se invoca cada minuto; revisar su lista, ya que ambas estrategias no necesariamente cubren las mismas tareas. Los catálogos territoriales periódicos deben revisarse por separado.

Verificar tres ciclos: ejecución reciente, errores por fuente, antigüedad de noticias, candidatos, ubicación y publicación. `/up` solo demuestra disponibilidad web. Cero incidencias nuevas puede ser legítimo, pero el cron debe dejar trazabilidad. CPU, disco, timeouts y respuestas 403/429 pueden retrasar ingesta. [Diagnóstico oficial de cron](https://www.hostinger.com/support/1583514-troubleshooting-cron-jobs-at-hostinger/).

## 5. Próximos despliegues

Usar [PRODUCTION_DEPLOYMENT.md](PRODUCTION_DEPLOYMENT.md) y `scripts/deploy-hostinger.sh`. Valida por defecto y requiere `--apply` para modificar producción. Solo avanza `main` a un SHA completo ya descargado; requiere backup externo reciente y conserva mantenimiento si falla.

Después de ensayar en staging, añadir un workflow SSH que siga esta secuencia tras CI: serializar deploys, verificar host, respaldar datos/archivos, desplegar el SHA aprobado y probar HTTP. Ese workflow y la conexión de cuentas quedan pendientes del destino real. El auto-deploy del código no reemplaza el cron.
