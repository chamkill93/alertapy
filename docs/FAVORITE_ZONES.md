# Mis zonas favoritas

“Mis zonas” permite guardar un nombre, centro elegido en el mapa, radio de 0,5 a 25 km y categorías. El estado se calcula con bounding box y distancia Haversine sobre incidencias publicadas: `SIN ACTIVIDAD`, `ACTIVIDAD BAJA`, `ACTIVIDAD MEDIA` o `ACTIVIDAD ALTA`.

La API dispone de listado, alta, edición, eliminación y `GET /api/favorite-zones/{id}/status`. Usuarios autenticados son propietarios por `user_id`; visitantes reciben un identificador aleatorio en cookie propia HttpOnly y SameSite. No se guarda IP como identidad. El encabezado alternativo `X-AlertaPY-Favorite-Token` existe para clientes API controlados.

Los límites de cantidad y radio se configuran en `config/alertapy.php`. Las zonas son privadas por propietario y no alteran la publicación ni verificación de incidencias.
