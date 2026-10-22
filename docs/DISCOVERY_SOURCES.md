# Fuentes de discovery

> **Discovery no es verificación.** Ningún resultado de esta capa se publica automáticamente como incidencia confirmada.

Los proveedores guardan la URL original, título, contenido disponible, autor/canal, fecha y evidencia de ubicación. Si la ubicación puede resolverse, el candidato queda como `pending_ai`; si no, queda como `waiting_location`. Ambos estados requieren una decisión posterior. El comando común es `php artisan alertapy:discover {proveedor}` y es seguro ejecutarlo por cron: respeta frecuencia, bloqueo e idempotencia.

| Proveedor | URL investigada | Tipo | Método | Frecuencia | Estado inicial | Limitaciones |
| --- | --- | --- | --- | ---: | --- | --- |
| GDELT | `https://api.gdeltproject.org/api/v2/doc/doc` | Discovery | DOC 2.0 JSON | 30 min | Desactivado por feature flag | Solo descubre. Debe existir URL HTTP(S) del medio original; GDELT nunca reemplaza esa fuente. El servicio exige como mínimo cinco segundos entre solicitudes y puede responder 429. |
| X | `https://api.x.com/2/tweets/search/recent` | Social | API v2 | 15 min | `DISABLED_CONFIGURATION` | Requiere proyecto de desarrollador y `X_BEARER_TOKEN`. Un post aislado queda sin verificar. |
| Bluesky | `https://public.api.bsky.app/xrpc/app.bsky.feed.searchPosts` | Social | AppView pública | 15 min | `STATUS = NOT_IMPLEMENTED` en el entorno actual | La comprobación del 19/08/2026 respondió HTTP 403. Alternativa: reactivar cuando el AppView permita acceso estable o se configure autenticación de servicio. |
| Mastodon | `/api/v1/timelines/tag/{hashtag}` en instancias configuradas | Social | API por instancia | 30 min | `DISABLED_CONFIGURATION` | No existe búsqueda global completa. Solo consulta instancias y hashtags explícitos. Algunas instancias pueden exigir token. |
| Telegram | `https://api.telegram.org/bot…/getUpdates` | Social | Bot API | 5 min | `DISABLED_CONFIGURATION` | Solo canales configurados/autorizados que agregaron el bot. No se scrapea Telegram completo. El token nunca se guarda en la base. |
| YouTube | `https://www.googleapis.com/youtube/v3/search` | Discovery | Data API v3 | 60 min | `DISABLED_CONFIGURATION` | Requiere API key y IDs de canales aprobados. Usa título, descripción, fecha y URL; no transcribe videos. Consume cuota. |

## Configuración

Todas las integraciones están apagadas por defecto. Las variables están documentadas en `.env.example`:

- `GDELT_ENABLED`.
- `X_DISCOVERY_ENABLED` y `X_BEARER_TOKEN`.
- `BLUESKY_DISCOVERY_ENABLED`.
- `MASTODON_DISCOVERY_ENABLED`, `MASTODON_INSTANCES` y `MASTODON_HASHTAGS`.
- `TELEGRAM_DISCOVERY_ENABLED`, `TELEGRAM_BOT_TOKEN` y `TELEGRAM_CHANNELS`.
- `YOUTUBE_DISCOVERY_ENABLED`, `YOUTUBE_API_KEY` y `YOUTUBE_CHANNEL_IDS`.

El programador ejecuta `alertapy:discover all` cada cinco minutos. Cada proveedor conserva su frecuencia interna y los desactivados solo muestran su estado; la falta de token no rompe el ciclo general.

## Reglas de seguridad editorial

1. GDELT sin URL original confiable se descarta.
2. Solo se aceptan URLs públicas HTTP(S); no se admiten hosts locales ni direcciones IP.
3. Los hallazgos se deduplican por identificador externo y hash de contenido.
4. La capa discovery no crea filas en `incidents`.
5. Un resultado social comienza como no verificado y necesita corroboración o decisión posterior.
