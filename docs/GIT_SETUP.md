# Git del proyecto

El repositorio del proyecto es [chamkill93/alertapy](https://github.com/chamkill93/alertapy). La rama inicial es `main` y el remoto `origin` usa `https://github.com/chamkill93/alertapy.git`.

La identidad de esta copia se configura únicamente para este repositorio, usando el usuario GitHub autenticado y su dirección privada `noreply`. No se modifica la configuración global del equipo. Para usar otra identidad:

```bash
git config user.name "NOMBRE REAL"
git config user.email "CORREO REAL"
```

Usar `--global` solo si esa identidad corresponde a todos los repositorios del equipo.

## Qué se versiona

El código, las migraciones, los catálogos de referencia, los recursos cartográficos públicos, las pruebas, la documentación y la CI se guardan en Git. `.env.example` contiene únicamente configuración de ejemplo y credenciales vacías.

`.env`, otras variantes de entorno, bases SQLite y sus journals, volcados SQL, backups, archivos privados, logs, capturas, `work/`, `vendor/` y el INI de PHP específico de esta PC quedan excluidos. El INI local se conserva en disco; una instalación nueva debe usar su propia configuración de PHP. Git no transporta los datos de incidencias ni las cuentas administrativas.

## Validación y despliegue

Un push a `main` y los pull requests ejecutan la CI: PHP 8.2/8.3 con SQLite aislado, pruebas JavaScript, validación de scripts y migraciones con MySQL 8. Revisar esa corrida antes de desplegar.

Subir código a GitHub no configura automáticamente Hostinger ni su cron. La instalación, los secretos del hosting y el flujo de despliegue se describen en [HOSTINGER_DEPLOYMENT.md](HOSTINGER_DEPLOYMENT.md). La recolección de noticias sigue siendo una tarea independiente del redeploy.
