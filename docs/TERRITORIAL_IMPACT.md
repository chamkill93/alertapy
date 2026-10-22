# Impacto territorial

El detalle diferencia evidencia de afectación y contexto. Una cifra censal del distrito nunca se presenta como personas afectadas. `population_potential` solo aparece cuando una fuente o método justificable vincula la afectación; en los demás casos se muestra “Contexto territorial”.

El servicio puede devolver rutas, infraestructura y estaciones DMH cercanas junto con método, radio y evidencia. Las geometrías oficiales Polygon/MultiPolygon sincronizadas desde INE se guardan en `territorial_geometries`; si no existe geometría suficiente se conserva el comportamiento puntual sin inventar cobertura.

La interfaz debe redactar los resultados como proximidad o contexto salvo que la evidencia demuestre impacto directo.
