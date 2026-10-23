<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="AlertaPY: información territorial verificable de Paraguay en un mapa interactivo.">
    <meta name="theme-color" content="#102a35">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <title>AlertaPY — Paraguay en tiempo casi real</title>

    <link rel="preconnect" href="https://unpkg.com">
    <link rel="preconnect" href="https://tile.openstreetmap.org">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
    <link rel="stylesheet" href="{{ asset('css/alertapy.css') }}?v={{ filemtime(public_path('css/alertapy.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/alertapy-ui/css/alertapy-ui-kit.css') }}?v={{ filemtime(public_path('assets/alertapy-ui/css/alertapy-ui-kit.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/alertapy-responsive.css') }}?v={{ filemtime(public_path('css/alertapy-responsive.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/alertapy-theme.css') }}?v={{ filemtime(public_path('css/alertapy-theme.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/alertapy-brand.css') }}?v={{ filemtime(public_path('css/alertapy-brand.css')) }}">
</head>
<body>
    <a class="skip-link" href="#map">Saltar al mapa</a>

    <header class="topbar">
        <div class="brand">
            <a class="brand-home" href="{{ route('map') }}" aria-label="AlertaPY, inicio">
                @include('components.brand')
            </a>
            <a class="developer-credit"
               href="https://www.linkedin.com/in/diego-laconich-chamorro-244775180/"
               target="_blank"
               rel="noopener noreferrer">
                Desarrollado por Ing Diego Laconich
            </a>
        </div>

        <div class="header-actions">
            <div class="header-action-group header-action-group--utilities">
                <button class="button button-secondary desktop-layers-toggle" id="layers-toggle" type="button" aria-controls="filters" aria-expanded="true">
                    <span data-app-icon="layers" aria-hidden="true"></span> Capas
                </button>
                <button class="button button-secondary" id="near-me" type="button" title="Solicita tu ubicación solo al pulsar">
                    <span data-app-icon="near" aria-hidden="true"></span> Cerca de mí
                </button>
                <button class="about-toggle" id="about-toggle" type="button" aria-label="Acerca de AlertaPY" title="Acerca de AlertaPY" aria-haspopup="dialog" aria-controls="about-dialog">
                    <span data-app-icon="info" aria-hidden="true"></span>
                    <span class="about-label">Acerca de</span>
                </button>
            </div>
            <button class="button button-report" id="report-incident" data-report-open type="button">
                <span data-app-icon="report" aria-hidden="true"></span> Reportar incidente
            </button>
            <div class="header-action-group header-action-group--view">
                <button class="mobile-filter-toggle" id="mobile-filter-toggle" type="button" aria-controls="filters" aria-expanded="false">
                    <span data-app-icon="filters" aria-hidden="true"></span> Filtros
                </button>
            </div>
        </div>
    </header>

    <main class="workspace" id="alertapy-app"
          data-incidents-url="{{ route('api.incidents.index') }}"
          data-incident-base-url="{{ url('/api/incidents') }}"
          data-locations-url="{{ route('api.locations.index') }}"
          data-zone-now-url="{{ route('api.nearby.summary') }}"
          data-stations-url="{{ route('api.monitoring.stations') }}"
          data-infrastructure-url="{{ route('api.infrastructure.index') }}"
          data-roads-url="{{ route('api.roads.index') }}"
          data-road-layer-url="{{ asset('data/map-layers/roads.geojson') }}?v={{ filemtime(public_path('data/map-layers/roads.geojson')) }}"
          data-rivers-layer-url="{{ asset('data/map-layers/rivers.geojson') }}?v={{ filemtime(public_path('data/map-layers/rivers.geojson')) }}"
          data-road-resolve-url="{{ route('api.roads.resolve') }}"
          data-report-url="{{ route('api.community-incidents.store') }}">
        <aside class="filters" id="filters" aria-label="Filtros del mapa">
            <div class="sidebar-heading">
                <div>
                    <span class="eyebrow">Explorá el territorio</span>
                    <h1>Paraguay ahora</h1>
                    <p class="sidebar-intro">Elegí una capa y explorá el mapa.</p>
                </div>
                <button class="filters-close" id="filters-close" type="button" aria-label="Cerrar filtros"><span data-app-icon="close" aria-hidden="true"></span></button>
            </div>

            <section aria-labelledby="period-heading">
                <div class="section-heading">
                    <h2 id="period-heading">Periodo</h2>
                    <span class="precise-period-status" id="precise-period-status" hidden></span>
                </div>
                <div class="period-grid" id="period-filter">
                    <button type="button" class="active" data-period="now" aria-pressed="true"><span data-app-icon="verified"></span>Ahora</button>
                    <button type="button" data-period="24h" aria-label="Últimas 24 horas" aria-pressed="false"><span data-app-icon="calendar"></span>24 h</button>
                    <button type="button" data-period="7d" aria-label="Últimos 7 días" aria-pressed="false"><span data-app-icon="calendar"></span>7 días</button>
                </div>
                <p class="filter-help">Ahora: incidencias vigentes. 24 h y 7 días: eventos recientes.</p>
            </section>

            <section aria-labelledby="layers-heading">
                <div class="section-heading">
                    <h2 id="layers-heading" class="section-title-icon"><span data-app-icon="report" aria-hidden="true"></span>Incidencias</h2>
                    <button type="button" class="all-categories-button" id="toggle-categories" aria-label="Mostrar todas las incidencias" aria-pressed="true">Todas</button>
                </div>
                @php
                    $orderedCategories = $categories->sortBy(fn ($category) => match ($category->slug) {
                        'electricity' => 0, 'water' => 1, 'hydrology' => 2, 'weather_flood' => 3, default => 4,
                    });
                    $primaryCategories = $orderedCategories->filter(fn ($category) => in_array($category->slug, ['electricity', 'water', 'hydrology'], true));
                    $additionalCategories = $orderedCategories->reject(fn ($category) => in_array($category->slug, ['electricity', 'water', 'hydrology'], true));
                @endphp
                <fieldset class="category-filter" id="category-filter" role="radiogroup" aria-labelledby="layers-heading" aria-describedby="category-selection-help">
                    <legend class="sr-only">Capas de eventos</legend>
                    @if ($primaryCategories->isNotEmpty())
                        <div class="category-list category-list--primary">
                            @foreach ($primaryCategories as $category)
                                <label class="category-option category-option--primary category-option--{{ $category->slug }}" style="--category-color: {{ $category->color }}" title="{{ $category->name }}">
                                    <input type="radio" name="event-category" value="{{ $category->slug }}" aria-label="{{ $category->name }}">
                                    <span class="category-icon category-icon--{{ $category->slug }}" data-app-icon="{{ $category->slug }}" aria-hidden="true"></span>
                                    <span class="category-name">{{ match($category->slug) {
                                        'hydrology' => 'Ríos', default => $category->name,
                                    } }}</span>
                                    <small class="category-count" data-category-count="{{ $category->slug }}" aria-hidden="true">—</small>
                                    <span class="category-selection-mark" aria-hidden="true">✓</span>
                                </label>
                            @endforeach
                        </div>
                    @endif

                    @if ($additionalCategories->isNotEmpty())
                        <details class="filter-disclosure category-more" id="more-event-categories">
                            <summary>
                                <span data-app-icon="layers" aria-hidden="true"></span>
                                <span>Otras incidencias</span>
                                <small class="category-more-status" id="category-more-status">{{ $additionalCategories->count() }} disponibles</small>
                                <span class="disclosure-chevron" aria-hidden="true">⌄</span>
                            </summary>
                            <div class="filter-disclosure-content">
                                <div class="category-list category-list--secondary">
                                    @foreach ($additionalCategories as $category)
                                        <label class="category-option category-option--additional" style="--category-color: {{ $category->color }}" title="{{ $category->name }}">
                                            <input type="radio" name="event-category" value="{{ $category->slug }}" aria-label="{{ $category->name }}" data-category-extra="true">
                                            <span class="category-icon category-icon--{{ $category->slug }}" data-app-icon="{{ $category->slug }}" aria-hidden="true"></span>
                                            <span class="category-name">{{ match($category->slug) {
                                                'traffic_accident' => 'Tránsito', 'weather_flood' => 'Clima',
                                                'roads' => 'Rutas y obras', 'borders' => 'Fronteras',
                                                'health_vector' => 'Salud', 'air_quality' => 'Aire',
                                                'territorial_news' => 'Territorio', default => $category->name,
                                            } }}</span>
                                            <small class="category-count" data-category-count="{{ $category->slug }}" aria-hidden="true">—</small>
                                            <span class="category-selection-mark" aria-hidden="true">✓</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        </details>
                    @endif

                    @if ($categories->isEmpty())
                        <p class="muted">Ejecutá las migraciones y seeders para cargar el catálogo.</p>
                    @endif
                </fieldset>
                <p class="filter-help" id="category-selection-help">Tocá Electricidad, Agua o Ríos para filtrar. «Todas» muestra todo; 0 = sin incidencias en el periodo elegido.</p>
            </section>

            <details class="filter-disclosure" id="map-layer-options">
                <summary><span data-app-icon="layers" aria-hidden="true"></span>Capas del mapa<span class="disclosure-chevron" aria-hidden="true">⌄</span></summary>
                <div class="filter-disclosure-content">
                    <section aria-labelledby="infrastructure-layers-heading">
                        <div class="section-heading">
                            <h2 id="infrastructure-layers-heading" class="section-title-icon"><span data-app-icon="layers" aria-hidden="true"></span>Infraestructura</h2>
                        </div>
                        <div class="infrastructure-layer-list" id="infrastructure-filter">
                            <label><input type="checkbox" value="health"><span data-app-icon="health_facility"></span><span>Salud</span><span class="layer-toggle" aria-hidden="true"></span></label>
                            <label><input type="checkbox" value="education"><span data-app-icon="education"></span><span>Educación</span><span class="layer-toggle" aria-hidden="true"></span></label>
                            <label><input type="checkbox" value="police"><span data-app-icon="police_station"></span><span>Policía</span><span class="layer-toggle" aria-hidden="true"></span></label>
                        </div>
                        <p class="infrastructure-layer-status" id="infrastructure-layer-status" role="status" aria-live="polite">Capas apagadas.</p>
                    </section>

                    <section aria-labelledby="monitoring-layers-heading">
                        <div class="section-heading">
                            <h2 id="monitoring-layers-heading" class="section-title-icon"><span data-app-icon="sensor" aria-hidden="true"></span>Estaciones</h2>
                        </div>
                        <div class="infrastructure-layer-list monitoring-layer-list" id="monitoring-filter">
                            <label>
                                <input type="checkbox" value="dmh">
                                <span data-app-icon="sensor" aria-hidden="true"></span>
                                <span>Estaciones DMH</span>
                                <span class="layer-toggle" aria-hidden="true"></span>
                            </label>
                        </div>
                        <p class="infrastructure-layer-status" id="monitoring-layer-status" role="status" aria-live="polite">Capa apagada.</p>
                    </section>

                    <section aria-labelledby="cartography-layers-heading">
                        <div class="section-heading"><h2 id="cartography-layers-heading" class="section-title-icon"><span data-app-icon="map" aria-hidden="true"></span>Mapa</h2></div>
                        <div class="infrastructure-layer-list monitoring-layer-list" id="road-filter">
                            <label><input type="checkbox" value="roads"><span data-app-icon="roads"></span><span>Red vial</span><span class="layer-toggle" aria-hidden="true"></span></label>
                            <label><input type="checkbox" value="rivers"><span data-app-icon="hydrology"></span><span>Ríos y cauces</span><span class="layer-toggle" aria-hidden="true"></span></label>
                        </div>
                        <div class="cartography-key" aria-label="Leyenda de cartografía">
                            <span><i class="cartography-key__line cartography-key__line--national"></i>Nacional</span>
                            <span><i class="cartography-key__line cartography-key__line--departmental"></i>Departamental</span>
                            <span><i class="cartography-key__line cartography-key__line--river"></i>Río</span>
                        </div>
                        <p class="infrastructure-layer-status" id="road-layer-status" role="status" aria-live="polite">Capas apagadas.</p>
                    </section>

                </div>
            </details>

            <details class="filter-disclosure" id="advanced-filter-options">
                <summary><span data-app-icon="filters" aria-hidden="true"></span><span class="filter-disclosure-label">Más opciones<small id="advanced-filter-indicator" aria-live="polite" hidden></small></span><span class="disclosure-chevron" aria-hidden="true">⌄</span></summary>
                <div class="filter-disclosure-content">
                    <label class="combine-categories-option"><input type="checkbox" id="combine-categories">Combinar varias categorías</label>
                    <section aria-labelledby="precise-period-heading">
                        <div class="section-heading"><h2 id="precise-period-heading">Últimas horas</h2></div>
                        <div class="precise-period-options" id="precise-period-filter">
                            <button type="button" data-period="1h" aria-label="Última hora" aria-pressed="false">1 h</button>
                            <button type="button" data-period="6h" aria-label="Últimas 6 horas" aria-pressed="false">6 h</button>
                        </div>
                    </section>
                    <section aria-labelledby="source-filter-heading">
                        <div class="section-heading"><h2 id="source-filter-heading">Fuente</h2></div>
                        <select id="source-filter" class="compact-select" aria-label="Filtrar por tipo de fuente">
                            <option value="">Todos</option>
                            <option value="official">Oficial</option>
                            <option value="press">Prensa</option>
                            <option value="sensor">Sensor</option>
                            <option value="community">Comunidad</option>
                        </select>
                        <p class="filter-help">Filtra los eventos. Las capas de infraestructura, estaciones y cartografía se controlan por separado.</p>
                    </section>

                    <section aria-labelledby="near-radius-heading">
                        <div class="section-heading"><h2 id="near-radius-heading">Radio</h2></div>
                        <select id="near-radius" class="compact-select" aria-label="Radio de búsqueda" aria-describedby="radius-status" disabled>
                            <option value="1">1 km</option><option value="3">3 km</option><option value="5" selected>5 km</option><option value="10">10 km</option><option value="25">25 km</option>
                        </select>
                        <div class="radius-actions">
                            <button type="button" class="text-button" id="near-map-center">Usar centro del mapa</button>
                            <button type="button" class="text-button" id="near-me-filter">Usar mi ubicación</button>
                            <button type="button" class="text-button" id="clear-radius" hidden>Quitar radio</button>
                        </div>
                        <p class="filter-help" id="radius-status" role="status">Sin filtro por radio. Elegí un centro para activarlo.</p>
                    </section>

                    <section class="zone-now" id="zone-now" aria-labelledby="zone-now-heading" hidden>
                        <div class="section-heading"><h2 id="zone-now-heading">Tu zona ahora</h2><span class="zone-now-live">En vivo</span></div>
                        <div id="zone-now-content"><p class="muted">Seleccioná “Cerca de mí” para consultar tu zona.</p></div>
                    </section>

                    <section aria-labelledby="summary-heading">
                        <div class="section-heading">
                            <h2 id="summary-heading">Resumen</h2>
                        </div>
                        <div class="summary-list" id="summary-list">
                            <p class="muted">Consultando datos publicados…</p>
                        </div>
                    </section>

                </div>
            </details>

            <footer class="sidebar-footer">
                <span id="last-refresh">Sin actualizar</span>
                <button type="button" class="text-button icon-button-label" id="manual-refresh"><span data-app-icon="refresh"></span>Actualizar</button>
            </footer>
        </aside>

        <section class="map-shell" aria-label="Mapa de incidencias de Paraguay">
            <div id="map" tabindex="0" aria-label="Mapa interactivo de AlertaPY"></div>

            <div class="map-quick-categories" id="quick-categories" role="group" aria-label="Cambiar capa de incidencias">
                <button type="button" data-quick-category="all" aria-label="Mostrar todas las incidencias" aria-pressed="true">
                    <span data-app-icon="layers" aria-hidden="true"></span><span>Todas</span>
                </button>
                <button type="button" data-quick-category="electricity" aria-label="Mostrar incidencias de electricidad" aria-pressed="false">
                    <span data-app-icon="electricity" aria-hidden="true"></span><span>Energía</span>
                </button>
                <button type="button" data-quick-category="water" aria-label="Mostrar incidencias de agua" aria-pressed="false">
                    <span data-app-icon="water" aria-hidden="true"></span><span>Agua</span>
                </button>
                <button type="button" data-quick-category="hydrology" aria-label="Mostrar incidencias de ríos e hidrología" aria-pressed="false">
                    <span data-app-icon="hydrology" aria-hidden="true"></span><span>Ríos</span>
                </button>
            </div>

            <button class="report-fab" data-report-open type="button">
                <span data-app-icon="plus" aria-hidden="true"></span>
                Reportar
            </button>

            <div class="map-status" id="map-status" role="status" aria-live="polite">
                <span class="spinner" aria-hidden="true"></span>
                <span>Consultando incidencias reales…</span>
            </div>

            <div class="map-legend" aria-label="Leyenda de fuentes">
                <span><i class="source-symbol source-symbol--official" data-app-icon="official"></i>Oficial</span>
                <span><i class="source-symbol source-symbol--press" data-app-icon="press"></i>Prensa</span>
                <span><i class="source-symbol source-symbol--sensor" data-app-icon="sensor"></i>Sensor</span>
                <span><i class="source-symbol source-symbol--community" data-app-icon="community"></i>Comunidad</span>
            </div>

            <aside class="incident-detail" id="incident-detail" aria-labelledby="detail-title" hidden>
                <button class="detail-close" id="detail-close" type="button" aria-label="Cerrar detalle"><span data-app-icon="close" aria-hidden="true"></span></button>
                <div id="detail-content"></div>
            </aside>
        </section>
    </main>

    <dialog class="report-dialog" id="report-dialog" aria-labelledby="report-title">
        <button class="detail-close" id="report-dialog-close" type="button" aria-label="Cerrar reporte"><span data-app-icon="close" aria-hidden="true"></span></button>

        <div class="report-dialog-header">
            <span class="dialog-icon dialog-icon--community" data-app-icon="community" aria-hidden="true"></span>
            <div>
                <span class="eyebrow">Publicación inmediata</span>
                <h2 id="report-title">Reportar un incidente</h2>
                <p>Se mostrará como información de la comunidad y sin confirmación.</p>
            </div>
        </div>

        <form class="report-form" id="community-report-form" enctype="multipart/form-data" novalidate>
            <div class="report-location-card" id="report-location-card">
                <span class="report-location-icon" data-app-icon="exact" aria-hidden="true"></span>
                <div>
                    <strong id="report-location-status">Falta marcar la ubicación</strong>
                    <small id="report-coordinates">Elegí el punto exacto donde ocurre el incidente.</small>
                </div>
                <button class="button report-pick-button" id="report-pick-location" type="button">
                    <span data-app-icon="map" aria-hidden="true"></span> Marcar en el mapa
                </button>
                <input id="report-latitude" name="latitude" type="hidden">
                <input id="report-longitude" name="longitude" type="hidden">
            </div>

            <div class="report-fields">
                <label class="report-field">
                    <span>Tipo de incidente</span>
                    <select id="report-category" name="category_slug" required>
                        <option value="">Seleccionar categoría…</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->slug }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="report-field">
                    <span>Lugar o referencia</span>
                    <input id="report-location-text" name="location_text" type="text" minlength="3" maxlength="255" placeholder="Ej.: Av. Mariscal López y Perú" required>
                </label>

                <label class="report-field report-field--wide">
                    <span>Título</span>
                    <input id="report-title-input" name="title" type="text" minlength="5" maxlength="220" placeholder="¿Qué está ocurriendo?" required>
                </label>

                <label class="report-field report-field--wide">
                    <span>Descripción</span>
                    <textarea id="report-summary" name="summary" minlength="10" maxlength="3000" rows="4" placeholder="Contá brevemente lo que observaste…" required></textarea>
                </label>

                <label class="report-field">
                    <span>Enlace opcional</span>
                    <span class="report-input-with-icon">
                        <i data-app-icon="link" aria-hidden="true"></i>
                        <input id="report-source-link" name="source_link" type="url" maxlength="2048" placeholder="https://…">
                    </span>
                </label>

                <label class="report-field">
                    <span>Foto opcional</span>
                    <span class="report-file-input">
                        <i data-app-icon="image" aria-hidden="true"></i>
                        <input id="report-photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">
                        <b>Elegir foto</b>
                    </span>
                </label>
            </div>

            <div class="report-photo-preview" id="report-photo-preview" hidden>
                <img id="report-photo-image" alt="Vista previa de la foto seleccionada">
                <div>
                    <strong id="report-photo-name"></strong>
                    <small>JPG, PNG o WEBP · máximo 5 MB</small>
                </div>
                <button id="report-photo-remove" type="button" aria-label="Quitar foto"><span data-app-icon="close" aria-hidden="true"></span></button>
            </div>

            <p class="report-form-error" id="report-form-error" role="alert" hidden></p>

            <footer class="report-form-footer">
                <span><i data-app-icon="warning" aria-hidden="true"></i> Se publicará inmediatamente como “Sin confirmación”.</span>
                <button class="button button-ghost" id="report-dialog-cancel" type="button">Cancelar</button>
                <button class="button button-primary" id="report-submit" type="submit">
                    <span data-app-icon="report" aria-hidden="true"></span> Publicar incidente
                </button>
            </footer>
        </form>
    </dialog>

    <dialog class="about-dialog" id="about-dialog" aria-labelledby="about-title" aria-describedby="about-description">
        <button class="detail-close" id="about-dialog-close" type="button" aria-label="Cerrar acerca de AlertaPY"><span data-app-icon="close" aria-hidden="true"></span></button>

        <div class="about-dialog-header">
            <span class="dialog-icon dialog-icon--info" data-app-icon="info" aria-hidden="true"></span>
            <div>
                <span class="eyebrow">Información de la plataforma</span>
                <h2 id="about-title">Acerca de AlertaPY</h2>
            </div>
        </div>

        <div class="about-dialog-content" id="about-description">
            <p>AlertaPY es una plataforma de información territorial de Paraguay que reúne y geolocaliza acontecimientos relevantes para ayudarte a conocer qué está ocurriendo en distintas zonas del país.</p>

            <section aria-labelledby="about-sources-title">
                <h3 id="about-sources-title">La información puede provenir de:</h3>
                <div class="about-source-list">
                    <article class="about-source about-source--official">
                        <span data-app-icon="official" aria-hidden="true"></span>
                        <p><strong>Fuentes oficiales</strong> ANDE, SENEPA, ESSAP, Meteorología, SEN, MOPC y otras instituciones públicas.</p>
                    </article>
                    <article class="about-source about-source--press">
                        <span data-app-icon="press" aria-hidden="true"></span>
                        <p><strong>Prensa</strong> Noticias geolocalizables publicadas por medios paraguayos, siempre acompañadas de un enlace a la fuente original.</p>
                    </article>
                    <article class="about-source about-source--community">
                        <span data-app-icon="community" aria-hidden="true"></span>
                        <p><strong>Comunidad</strong> Reportes enviados por usuarios de AlertaPY e identificados claramente como información comunitaria.</p>
                    </article>
                </div>
            </section>

            <p>AlertaPY puede mostrar cortes de energía y agua, accidentes, hechos de seguridad, incendios, inundaciones, bloqueos, intervenciones de SENEPA, emergencias y otras novedades territoriales.</p>

            <aside class="about-notice">
                <span data-app-icon="warning" aria-hidden="true"></span>
                <p><strong>Importante</strong> AlertaPY no reemplaza a los organismos oficiales, servicios de emergencia ni autoridades competentes. La plataforma organiza información disponible y muestra siempre su procedencia y nivel de verificación.</p>
            </aside>

            <aside class="about-update">
                <span data-app-icon="refresh" aria-hidden="true"></span>
                <p><strong>Actualización automática</strong> Las fuentes son consultadas periódicamente y el mapa se actualiza cuando se detectan nuevas incidencias o cambios.</p>
            </aside>
        </div>

        <div class="about-dialog-footer">
            <strong>AlertaPY</strong>
            <span>Información territorial verificable.</span>
            <button class="button button-primary" id="about-dialog-accept" type="button">Entendido</button>
        </div>
    </dialog>

    <noscript>
        <div class="noscript">AlertaPY necesita JavaScript para mostrar el mapa interactivo.</div>
    </noscript>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
    <script src="{{ asset('js/alertapy-icons.js') }}?v={{ filemtime(public_path('js/alertapy-icons.js')) }}" defer></script>
    <script src="{{ asset('js/alertapy-map.js') }}?v={{ filemtime(public_path('js/alertapy-map.js')) }}" defer></script>
</body>
</html>
