# Salud de fuentes

Las corridas y snapshots estructurales alimentan cuatro estados operativos:

- `HEALTHY`: éxito reciente y estructura esperada.
- `DEGRADED`: resultados anómalos, HTTP restrictivo o demora creciente.
- `FAILING`: errores consecutivos o parser roto.
- `DISABLED` / `DISABLED_CONFIGURATION`: fuente deshabilitada o sin credenciales requeridas.

Una respuesta HTTP 200 con cero elementos después de corridas productivas puede degradar la fuente; no se registra automáticamente como éxito editorial. Los snapshots guardan firma del parser, cantidad, duración, estado HTTP y mensaje técnico. El panel `/admin/coverage` muestra último intento, último cambio, HTTP, elementos, duración, errores y próxima ejecución sin exponer secretos.

RC1 agrega `last_error_type`, `last_duration_ms`, `items_seen`, `items_new` y `last_new_item_at`. `ANTI_BOT_BLOCKED` y `DNS_RESOLUTION_ERROR` se tratan como degradaciones externas y no bloquean el ciclo completo. Cada ejecución general se registra además en `scheduler_cycles` y se diagnostica con `php artisan alertapy:status`.

AlertaPY usa un agente identificable, frecuencia limitada y reintentos prudentes. No evade CAPTCHA ni Radware. ETag o Last-Modified solo deben usarse cuando el servidor los ofrece de manera estable y el collector conserva una copia válida para responder a 304.
