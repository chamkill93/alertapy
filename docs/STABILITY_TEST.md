# Prueba de estabilidad 24/48/72 horas

La aplicación queda preparada para despertar `alertapy:cycle` cada minuto y registrar cada corrida en `scheduler_cycles`. El cron de Hostinger todavía requiere configuración y comprobación en la cuenta real. El runner local usa ahora un intervalo predeterminado de 60 segundos; las observaciones anteriores con 300 segundos permanecen como evidencia histórica, no como frecuencia operativa actual.

Antes de comenzar:

1. ejecutar `php artisan alertapy:status`;
2. comprobar cron y timezone;
3. anotar conteos iniciales de incidencias y fuentes;
4. no ejecutar simultáneamente `schedule:run` y `alertapy:cycle`.

Durante la observación registrar desde `scheduler_cycles`, `source_runs`, `incident_updates` y el panel administrativo:

- ciclos ejecutados y estado;
- duración media y máxima;
- fuentes procesadas/degradadas/fallidas;
- intervalo real entre ejecuciones de cada fuente, comparado con `polling_minutes`, y avance del checkpoint general después de las conexiones prioritarias;
- candidatos e incidencias creadas;
- incidencias actualizadas, fusionadas, cerradas o vencidas;
- flags geográficos;
- crecimiento de logs y base.

Ejecutar `alertapy:status` al cumplir 24, 48 y 72 horas. Una corrida fallida no debe detener las siguientes; ANDE/Radware y errores DNS se registran como degradación externa sin bypass.

La [revisión previa del 22/10/2026](PRE_HOSTINGER_SOURCE_CHECKS.md) documenta respuestas HTTP y ejecuciones locales puntuales; no sustituye una observación continua de 24, 48 o 72 horas ni confirma el funcionamiento desde Hostinger.
