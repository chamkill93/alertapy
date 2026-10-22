# Privacidad y seguridad de contenido

## Minimización

AlertaPY publica información territorial útil, no expedientes personales. No debe exponer nombres de víctimas, documentos, teléfonos, domicilios residenciales exactos, acusaciones contra particulares, placas u otros identificadores salvo necesidad pública excepcional respaldada por una fuente oficial y una política explícita.

## Reportes ciudadanos

La Fase 7 incorporará:

- publicación inmediata identificada como comunidad y sujeta a validación, sin presentarla como fuente oficial;
- IP y user-agent transformados mediante hash con salt rotativo;
- límites de frecuencia, honeypot y CAPTCHA configurable;
- detección de repetición y similitud;
- aceptación de términos;
- redacción o rechazo de acusaciones personales;
- aproximación pública de ubicaciones residenciales sensibles.

No se conservará una IP en claro indefinidamente.

## Fotografías

Las evidencias futuras se guardarán privadas hasta una decisión válida. PHP comprobará MIME real, límite de tamaño y dimensiones; generará nombre aleatorio; eliminará EXIF; volverá a codificar la imagen y nunca confiará en la extensión.

## API pública

Los resources usan listas explícitas de campos. No exponen `raw_content`, `ai_raw_response`, tokens, errores internos, ETag, IP, user-agent o IDs numéricos de incidencias. Blade escapa por defecto y el JavaScript crea nodos mediante `textContent`.

## Retención y auditoría

La evidencia original se conserva para explicar por qué se tomó una decisión, con acceso administrativo restringido. Las políticas de retención y eliminación deben definirse antes de habilitar reportes públicos. Los logs nunca deben contener tokens o archivos completos.
