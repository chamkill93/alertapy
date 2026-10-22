# AlertaPY UI/UX RC1

## Integración

La modernización visual trabaja sobre Blade, CSS, JavaScript vanilla y Leaflet existentes. No introduce framework UI, compilador ni una segunda implementación de marcadores.

El ZIP `AlertaPY_UIUX_Completo.zip` contiene el prompt, el preview y los SVG DMH. El preview referencia otros SVG, CSS y JavaScript que no fueron incluidos físicamente. Para evitar recursos rotos y sistemas duplicados:

- los SVG DMH idénticos a los existentes se consolidaron en `public/assets/alertapy-ui/icons/monitoring/`;
- los glifos UI, incidentes, infraestructura y zonas se mantienen en la fuente única `public/js/alertapy-icons.js`;
- los tokens y componentes comunes viven en `public/assets/alertapy-ui/css/alertapy-ui-kit.css`.

## Componentes aplicados

- Tokens RC1 de marca, superficies, bordes, radios y sombras.
- Botones `primary`, `secondary`, `ghost` y `danger`.
- Botón Capas con panel lateral plegable en escritorio y tablet.
- Toggles de categorías, infraestructura, monitoreo y cartografía.
- Periodos como control segmentado con `aria-pressed`.
- Marcadores de incidencia de 32 × 40 px y anclaje Leaflet 16 × 38.
- Clúster navy para mezcla de categorías y color propio para clúster homogéneo.
- Infraestructura y estaciones DMH visualmente diferenciadas de los eventos.
- Noticias operativas y diagnóstico de conexiones en el backoffice privado.
- Detalle con acciones de fuente original y compartir.
- Iconos propios para Casa, Trabajo, Familia y Otra zona.
- Cierres de paneles y modales mediante SVG accesible.
- Controles Leaflet de 40 px en escritorio y 44 px en móvil.
- Leyenda compacta en pantallas pequeñas.

## Responsive y accesibilidad

- Desktop conserva filtros y mapa, y permite plegar filtros.
- Tablet permite compactar filtros; las noticias y las conexiones se consultan en el backoffice.
- Mobile mantiene el mapa como protagonista, filtros en panel y áreas táctiles mínimas de 44 px.
- Los botones solo-icono tienen nombre accesible; los periodos anuncian su selección; los toggles conservan inputs nativos; todos los controles incluyen foco visible.

## Validación

- Baseline: 114 tests, 623 assertions.
- Resultado: 114 tests, 635 assertions.
- Blade compilado correctamente.
- Base SQLite local actualizada con la paleta RC1.

## QA visual

El mapa local y el acceso al backoffice se comprobaron en el navegador integrado. Las cinco vistas privadas se revisaron con datos ficticios en 320, 768 y 1440 px; los cuadros no desbordan y las tablas se desplazan dentro de su contenedor. Los datos operativos privados requieren sesión administrativa. Ver `docs/BACKOFFICE_ACCESS.md`.
