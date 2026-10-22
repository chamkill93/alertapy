# Prueba de estabilidad 24/48/72 horas

La aplicación queda preparada para ejecutar `alertapy:cycle` cada cinco minutos y registrar cada corrida en `scheduler_cycles`.

Antes de comenzar:

1. ejecutar `php artisan alertapy:status`;
2. comprobar cron y timezone;
3. anotar conteos iniciales de incidencias y fuentes;
4. no ejecutar simultáneamente `schedule:run` y `alertapy:cycle`.

Durante la observación registrar desde `scheduler_cycles`, `source_runs`, `incident_updates` y el panel administrativo:

- ciclos ejecutados y estado;
- duración media y máxima;
- fuentes procesadas/degradadas/fallidas;
- candidatos e incidencias creadas;
- incidencias actualizadas, fusionadas, cerradas o vencidas;
- flags geográficos;
- crecimiento de logs y base.

Ejecutar `alertapy:status` al cumplir 24, 48 y 72 horas. Una corrida fallida no debe detener las siguientes; ANDE/Radware y errores DNS se registran como degradación externa sin bypass.
