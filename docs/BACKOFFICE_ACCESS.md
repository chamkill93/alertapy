# Acceso al backoffice

## Pantallas disponibles

- `/admin`: resumen de noticias recibidas, incidencias publicadas, pendientes y conexiones que necesitan atención.
- `/admin/news`: búsqueda y filtros por fuente, estado y categoría publicada, con paginación y detalle de cada publicación original. «Pendientes» incluye elementos sin procesar; una noticia sin referencia a una incidencia publicada no se rotula como publicada.
- `/admin/connections`: todas las fuentes, incluidas las deshabilitadas, con salud, última consulta, último éxito, frecuencia, HTTP y resultados. El detalle contiene el historial paginado de consultas y las publicaciones de esa fuente. Una ejecución en curso no muestra un cero inventado.

Estas pantallas consultan la base y no disparan recolecciones. El ciclo automático continúa funcionando por separado. Los errores técnicos y las URLs con credenciales se omiten o se presentan mediante diagnósticos seguros.

## Configuración del acceso

El panel `/admin` usa usuarios de Laravel con `is_admin = true`. Se inicia sesión en `/admin/login` y se cierra con `POST /admin/logout`. Los formularios del grupo `web` exigen CSRF. Los intentos fallidos tienen límites por correo e IP, la sesión cambia de identificador al iniciar sesión y se invalida al salir. El rol no es asignable mediante `User::create()` desde datos externos.

El middleware `admin.private` se aplica al grupo administrativo y los diagnósticos JSON: devuelve `Cache-Control: private, no-store` y `X-Robots-Tag: noindex, nofollow`. La autenticación, configuración y datos privados no se almacenan en cachés compartidas.

Aplicar las migraciones antes de usar el panel:

```console
php artisan migrate --force
php artisan alertapy:admin operador@ejemplo.com --name="Operador"
```

El comando pide una contraseña de al menos 12 caracteres y su confirmación de manera oculta. Crea el usuario o actualiza su contraseña y rol si el correo ya existe. No acepta contraseñas por argumentos ni funciona con `--no-interaction`. No hay credenciales predeterminadas. Ejecutarlo contra la base de datos del entorno correspondiente; en el preview local se utiliza SQLite y en producción la conexión configurada para el hosting.

Solo en `APP_ENV=local`, desde `127.0.0.1` o `::1` y mientras no exista ningún administrador, `/admin/login` lleva a `/admin/setup` para crear la primera cuenta. En producción o desde una IP externa, `/admin/setup` responde 404. Al crear la cuenta, ese formulario deja de estar disponible.

Los endpoints anteriores `/admin/coverage` y `/admin/roads` conservan la autenticación HTTP Basic con `ALERTAPY_ADMIN_TOKEN` para herramientas existentes. También aceptan la sesión de un administrador. Este token no habilita el nuevo panel ni se debe incluir en URLs. Si no se usa la integración anterior, dejar `ALERTAPY_ADMIN_TOKEN` vacío. En producción servir el sitio por HTTPS y configurar `SESSION_SECURE_COOKIE=true`.

`/api/sources` y `/api/sources/status` ahora requieren la misma sesión administrativa. El mapa público dejó de consultar esos endpoints y el feed de novedades operativas. Todas las pantallas administrativas llevan `noindex, nofollow` y `Cache-Control: private, no-store`.

## Verificación del 22/10/2026

La suite completa aprobó **263 pruebas PHP y 1366 aserciones**; las regresiones del mapa aprobaron **30 pruebas JavaScript**. Se verificaron accesos sin sesión, rol no administrativo, login, logout, límites de intentos, CSRF, configuración inicial local, bloqueo de esa configuración en producción, filtros, paginación y sanitización.

La migración local se aplicó con respaldo en `work/before-backoffice-20261022-153323.sqlite`. No se crearon administradores ni credenciales en la base real: el primer acceso queda listo para que su responsable cree la cuenta.

La configuración inicial y la ausencia de diagnósticos en el mapa se comprobaron en el navegador real. Las cinco vistas interiores se revisaron en anchos de 320, 768 y 1440 px mediante renderizados estáticos con datos ficticios, sin usuarios ni sesiones reales. No se detectó desborde de página o tarjetas; las tablas tienen desplazamiento dentro de su cuadro. Evidencia de medidas: `work/backoffice-layout-20261022.json`.
