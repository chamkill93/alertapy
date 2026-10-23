(function () {
    'use strict';

    const app = document.getElementById('alertapy-app');
    const visualIcons = window.AlertaIcons;
    const appTimeZone = 'America/Asuncion';
    const communityVoterStorageKey = 'alertapy-community-voter';
    const nationalInfrastructureStorageKey = 'alertapy-infrastructure-national-v1';

    if (!app || !visualIcons || typeof window.L === 'undefined') {
        return;
    }

    visualIcons.hydrate(document);

    const elements = {
        categoryFilter: document.getElementById('category-filter'),
        toggleCategories: document.getElementById('toggle-categories'),
        quickCategories: document.getElementById('quick-categories'),
        combineCategories: document.getElementById('combine-categories'),
        categorySelectionHelp: document.getElementById('category-selection-help'),
        categoryMoreStatus: document.getElementById('category-more-status'),
        periodFilter: document.getElementById('period-filter'),
        precisePeriodFilter: document.getElementById('precise-period-filter'),
        precisePeriodStatus: document.getElementById('precise-period-status'),
        advancedFilterIndicator: document.getElementById('advanced-filter-indicator'),
        sourceFilter: document.getElementById('source-filter'),
        nearRadius: document.getElementById('near-radius'),
        nearMapCenter: document.getElementById('near-map-center'),
        nearMeFilter: document.getElementById('near-me-filter'),
        clearRadius: document.getElementById('clear-radius'),
        radiusStatus: document.getElementById('radius-status'),
        infrastructureFilter: document.getElementById('infrastructure-filter'),
        infrastructureStatus: document.getElementById('infrastructure-layer-status'),
        monitoringFilter: document.getElementById('monitoring-filter'),
        monitoringStatus: document.getElementById('monitoring-layer-status'),
        roadFilter: document.getElementById('road-filter'),
        roadStatus: document.getElementById('road-layer-status'),
        summary: document.getElementById('summary-list'),
        status: document.getElementById('map-status'),
        refreshTime: document.getElementById('last-refresh'),
        manualRefresh: document.getElementById('manual-refresh'),
        detail: document.getElementById('incident-detail'),
        detailContent: document.getElementById('detail-content'),
        detailClose: document.getElementById('detail-close'),
        nearMe: document.getElementById('near-me'),
        zoneNow: document.getElementById('zone-now'),
        zoneNowContent: document.getElementById('zone-now-content'),
        filters: document.getElementById('filters'),
        filtersClose: document.getElementById('filters-close'),
        layersToggle: document.getElementById('layers-toggle'),
        mobileFilterToggle: document.getElementById('mobile-filter-toggle'),
        mapShell: document.querySelector('.map-shell'),
        aboutButton: document.getElementById('about-toggle'),
        aboutDialog: document.getElementById('about-dialog'),
        aboutDialogClose: document.getElementById('about-dialog-close'),
        aboutDialogAccept: document.getElementById('about-dialog-accept'),
        reportOpenButtons: document.querySelectorAll('[data-report-open]'),
        reportDialog: document.getElementById('report-dialog'),
        reportDialogClose: document.getElementById('report-dialog-close'),
        reportDialogCancel: document.getElementById('report-dialog-cancel'),
        reportForm: document.getElementById('community-report-form'),
        reportPickLocation: document.getElementById('report-pick-location'),
        reportLocationCard: document.getElementById('report-location-card'),
        reportLocationStatus: document.getElementById('report-location-status'),
        reportCoordinates: document.getElementById('report-coordinates'),
        reportLatitude: document.getElementById('report-latitude'),
        reportLongitude: document.getElementById('report-longitude'),
        reportPhoto: document.getElementById('report-photo'),
        reportPhotoPreview: document.getElementById('report-photo-preview'),
        reportPhotoImage: document.getElementById('report-photo-image'),
        reportPhotoName: document.getElementById('report-photo-name'),
        reportPhotoRemove: document.getElementById('report-photo-remove'),
        reportError: document.getElementById('report-form-error'),
        reportSubmit: document.getElementById('report-submit'),
    };

    const state = {
        period: 'now',
        city: '',
        source: '',
        roadCode: '',
        nearLatitude: null,
        nearLongitude: null,
        nearOrigin: null,
        geolocationRequest: 0,
        radiusKm: 5,
        abortController: null,
        loading: false,
        categoriesVisible: true,
        allCategories: true,
        locationCircle: null,
        reportSelectingLocation: false,
        reportLocationMarker: null,
        reportPhotoUrl: null,
        zoneAbortController: null,
        infrastructureAbortController: null,
        nationalInfrastructurePromise: null,
        weatherAbortController: null,
        mapLayerPromises: new Map(),
    };

    const map = L.map('map', {
        center: [-23.4, -58.4],
        zoom: 6,
        minZoom: 5,
        maxZoom: 18,
        zoomControl: false,
    });

    L.control.zoom({ position: 'bottomright' }).addTo(map);

    map.createPane('riversPane');
    map.getPane('riversPane').style.zIndex = '425';

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>',
    }).addTo(map);

    const markers = L.markerClusterGroup({
        showCoverageOnHover: false,
        maxClusterRadius: 52,
        spiderfyOnMaxZoom: true,
        iconCreateFunction: function (cluster) {
            const count = cluster.getChildCount();
            const colors = [...new Set(cluster.getAllChildMarkers().map(marker => marker.alertColor).filter(Boolean))];
            const content = document.createElement('div');
            content.className = 'alertapy-cluster';
            content.classList.add(count >= 10 ? 'is-large' : 'is-small');
            content.style.setProperty('--cluster-color', colors.length === 1 ? colors[0] : '#0F3B5F');
            const label = document.createElement('span');
            label.textContent = String(count);
            content.appendChild(label);

            return L.divIcon({
                html: content.outerHTML,
                className: '',
                iconSize: [46, 46],
                iconAnchor: [23, 23],
            });
        },
    });
    const dmhStationIconUrl = '/assets/alertapy-ui/icons/monitoring/estacion-meteorologica-dmh.svg';
    const dmhMeasurementIcons = {
        precipitation: '/assets/alertapy-ui/icons/monitoring/pluviometro.svg',
        temperature: '/assets/alertapy-ui/icons/monitoring/termometro.svg',
        wind_direction: '/assets/alertapy-ui/icons/monitoring/anemometro.svg',
        wind_speed: '/assets/alertapy-ui/icons/monitoring/anemometro.svg',
    };
    const weatherStationsLayer = L.markerClusterGroup({
        chunkedLoading: true,
        showCoverageOnHover: false,
        maxClusterRadius: 42,
        disableClusteringAtZoom: 13,
        iconCreateFunction(cluster) {
            const node = document.createElement('div');
            node.className = 'dmh-cluster';
            const icon = document.createElement('img');
            icon.src = dmhStationIconUrl;
            icon.alt = '';
            node.append(icon, createTextElement('span', String(cluster.getChildCount())));
            return L.divIcon({ html: node.outerHTML, className: '', iconSize: [44, 44], iconAnchor: [22, 22] });
        },
    });
    const roadNetworkLayer = L.geoJSON([], {
        style(feature) {
            const national = String(feature?.properties?.classification || '').toLowerCase().includes('nacional') || /^PY/i.test(feature?.properties?.code || '');
            return { color: national ? '#f04b23' : '#f2a900', weight: national ? 3.4 : 2.2, opacity: national ? .92 : .78, className: 'road-network-line' };
        },
        onEachFeature(feature, layer) {
            const code = feature.properties?.code || '';
            const name = feature.properties?.name || '';
            const label = code && name !== code ? `${code} · ${name}` : (code || name || 'Vía principal');
            layer.bindTooltip(label, { sticky: true, className: 'road-route-tooltip' });
            const popup = document.createElement('div'); popup.className = 'road-route-popup';
            popup.append(createTextElement('strong', label));
            popup.append(createTextElement('small', feature.properties?.classification === 'departamental' ? 'Ruta departamental' : 'Ruta nacional'));
            if (feature.properties?.surface) popup.append(createTextElement('small', `Superficie: ${feature.properties.surface}`));
            if (feature.properties?.source) popup.append(createTextElement('small', `Geometría: ${feature.properties.source}`));
            layer.bindPopup(popup);
        },
    });
    const riverNetworkLayer = L.geoJSON([], {
        pane: 'riversPane',
        style(feature) {
            const classification = feature?.properties?.classification || 'waterway';
            const styles = {
                river: { color: '#006fbe', weight: 3.8, opacity: .96 },
                stream: { color: '#169bd5', weight: 1.8, opacity: .78 },
                lake: { color: '#087fc1', weight: 2.6, opacity: .88 },
                canal: { color: '#118fca', weight: 2.1, opacity: .82 },
                waterway: { color: '#169bd5', weight: 1.6, opacity: .72 },
            };
            return { ...(styles[classification] || styles.waterway), className: `river-network-line river-network-line--${classification}` };
        },
        onEachFeature(feature, layer) {
            const label = feature.properties?.name || 'Cauce';
            layer.bindTooltip(label, { sticky: true, className: 'river-route-tooltip' });
            const popup = document.createElement('div'); popup.className = 'road-route-popup river-route-popup';
            popup.append(createTextElement('strong', label));
            const typeLabels = { river: 'Río', stream: 'Arroyo o riacho', lake: 'Lago o laguna', canal: 'Canal', waterway: 'Cauce cartografiado' };
            popup.append(createTextElement('small', typeLabels[feature.properties?.classification] || typeLabels.waterway));
            if (feature.properties?.source) popup.append(createTextElement('small', `Geometría: ${feature.properties.source}`));
            layer.bindPopup(popup);
        },
    });
    const infrastructureColors = { health: '#f01427', education: '#0877e8', police: '#084b9f' };
    const infrastructureIconNames = { health: 'health_facility', education: 'education', police: 'police_station' };
    const infrastructureLayers = Object.fromEntries(Object.keys(infrastructureColors).map(kind => [kind, L.markerClusterGroup({
        chunkedLoading: true,
        showCoverageOnHover: false,
        maxClusterRadius: 38,
        disableClusteringAtZoom: 13,
        iconCreateFunction(cluster) {
            const count = cluster.getAllChildMarkers().reduce((sum, marker) => sum + (marker.options.facilityCount || 1), 0);
            const node = document.createElement('div');
            node.className = `facility-cluster facility-cluster--${kind}`;
            node.append(visualIcons.node(infrastructureIconNames[kind]), createTextElement('span', String(count)));
            return L.divIcon({ html: node.outerHTML, className: '', iconSize: [44, 44], iconAnchor: [22, 22] });
        },
    })]));

    map.addLayer(markers);
    const geometries = L.featureGroup().addTo(map);

    function selectedCategories() {
        const selector = state.allCategories ? 'input:not(:disabled)' : 'input:checked';
        return [...elements.categoryFilter.querySelectorAll(selector)].map(input => input.value);
    }

    function buildIncidentUrl() {
        const url = new URL(app.dataset.incidentsUrl, window.location.origin);
        const bounds = map.getBounds();
        const categories = selectedCategories();

        url.searchParams.set('period', state.period);
        url.searchParams.set('per_page', '200');
        url.searchParams.set('pagination', 'cursor');
        if (Number.isFinite(state.nearLatitude) && Number.isFinite(state.nearLongitude)) {
            url.searchParams.set('near_lat', state.nearLatitude.toFixed(7));
            url.searchParams.set('near_lng', state.nearLongitude.toFixed(7));
            url.searchParams.set('radius_km', String(state.radiusKm));
        } else {
            url.searchParams.set('bbox', [
                bounds.getSouth().toFixed(5),
                bounds.getWest().toFixed(5),
                bounds.getNorth().toFixed(5),
                bounds.getEast().toFixed(5),
            ].join(','));
        }

        if (categories.length) {
            url.searchParams.set('categories', categories.join(','));
        } else {
            url.searchParams.set('categories', '__none__');
        }

        if (state.city) {
            url.searchParams.set('city', state.city);
        }

        if (state.source) {
            url.searchParams.set('source', state.source);
        }
        if (state.roadCode) url.searchParams.set('road', state.roadCode);

        return url;
    }

    async function loadIncidents(options = {}) {
        if (state.abortController) {
            state.abortController.abort();
        }

        const controller = new AbortController();
        state.abortController = controller;
        state.loading = true;
        elements.status.hidden = false;
        elements.status.classList.remove('is-error');
        elements.status.querySelector('span:last-child').textContent = options.silent
            ? 'Actualizando incidencias…'
            : 'Consultando incidencias reales…';

        try {
            let nextUrl = buildIncidentUrl();
            let firstPayload = null;
            const incidents = new Map();
            const visited = new Set();

            while (nextUrl) {
                if (nextUrl.origin !== window.location.origin || visited.has(nextUrl.href)) {
                    throw new Error('La paginación de incidencias no es válida.');
                }
                visited.add(nextUrl.href);

                const response = await fetch(nextUrl, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error(`La API respondió ${response.status}`);

                const payload = await response.json();
                if (controller.signal.aborted || state.abortController !== controller) return;
                firstPayload ??= payload;
                for (const incident of payload.data || []) incidents.set(incident.id, incident);
                nextUrl = payload.links?.next ? new URL(payload.links.next, window.location.origin) : null;
                if (nextUrl) {
                    elements.status.querySelector('span:last-child').textContent =
                        `Cargando incidencias: ${incidents.size} de ${firstPayload.summary?.reduce((total, item) => total + item.count, 0) || 'varias'}…`;
                }
            }

            renderIncidents([...incidents.values()]);
            renderSummary(firstPayload?.summary || []);
            renderCategoryAvailability(firstPayload?.category_availability || []);
            elements.refreshTime.dataset.refreshedAt = firstPayload?.refreshed_at || new Date().toISOString();
            updateRelativeRefreshLabels();
        } catch (error) {
            if (error.name !== 'AbortError' && state.abortController === controller) {
                showStatus('No fue posible actualizar el mapa. Reintentaremos automáticamente.', true);
            }
        } finally {
            if (state.abortController === controller) {
                state.loading = false;
                if (!elements.status.classList.contains('is-error')) {
                    elements.status.hidden = true;
                }
            }
        }
    }

    function renderIncidents(incidents) {
        markers.clearLayers();
        geometries.clearLayers();
        const mapIncidents = consolidateElectricityMarkers(incidents);

        mapIncidents.forEach(incident => {
            const latitude = Number(incident.location.latitude);
            const longitude = Number(incident.location.longitude);

            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
                return;
            }

            if (['line', 'polygon'].includes(incident.representation?.type) && incident.representation.geometry) {
                const layer = L.geoJSON(incident.representation.geometry, {
                    style: {
                        color: incident.category.color,
                        weight: incident.representation.type === 'line' ? 5 : 2,
                        fillColor: incident.category.color,
                        fillOpacity: incident.representation.type === 'polygon' ? .18 : 0,
                    },
                });
                layer.bindTooltip(createTooltip(incident));
                layer.on('click', () => loadIncidentDetail(incident.id, incident.map_group));
                geometries.addLayer(layer);
                return;
            }

            const markerNode = document.createElement('div');
            markerNode.className = `map-pin${incident.location.is_fallback ? ' map-pin--fallback' : ''}`;
            markerNode.setAttribute('aria-hidden', 'true');
            markerNode.style.setProperty('--marker-color', incident.category.color);
            const markerBody = document.createElement('div');
            markerBody.className = 'map-pin-body';
            markerBody.appendChild(visualIcons.node(incident.category.slug, 'map-pin-icon'));
            markerNode.appendChild(markerBody);
            if (incident.map_group?.count > 1) {
                markerNode.appendChild(createTextElement('span', String(incident.map_group.count), 'map-pin-count'));
            }

            const marker = L.marker([latitude, longitude], {
                title: incident.title,
                icon: L.divIcon({
                    html: markerNode.outerHTML,
                    className: '',
                    iconSize: [32, 40],
                    iconAnchor: [16, 38],
                    popupAnchor: [0, -34],
                }),
            });

            marker.alertColor = incident.category.color;
            marker.bindTooltip(createTooltip(incident), { direction: 'top', offset: [0, -34] });
            marker.on('click', () => loadIncidentDetail(incident.id, incident.map_group));
            markers.addLayer(marker);
        });

    }

    function consolidateElectricityMarkers(incidents) {
        const visible = [];
        const schedulesByPlace = new Map();

        incidents.forEach(incident => {
            if (!isScheduledAndeIncident(incident)) {
                visible.push(incident);
                return;
            }

            const place = incident.location.city || incident.location.department || incident.location.text;
            const key = normalizePlaceKey(place);
            const group = schedulesByPlace.get(key) || { place, incidents: [] };
            group.incidents.push(incident);
            schedulesByPlace.set(key, group);
        });

        schedulesByPlace.forEach(group => {
            if (group.incidents.length === 1) {
                visible.push(group.incidents[0]);
                return;
            }

            const representative = selectRelevantSchedule(group.incidents);
            const timestamps = group.incidents
                .map(incident => Date.parse(incident.starts_at))
                .filter(Number.isFinite)
                .sort((left, right) => left - right);
            visible.push({
                ...representative,
                title: `${group.incidents.length} trabajos programados de ANDE en ${group.place}`,
                location: { ...representative.location, text: group.place },
                map_group: {
                    count: group.incidents.length,
                    place: group.place,
                    from: timestamps.length ? new Date(timestamps[0]).toISOString() : null,
                    to: timestamps.length ? new Date(timestamps[timestamps.length - 1]).toISOString() : null,
                },
            });
        });

        return visible;
    }

    function isScheduledAndeIncident(incident) {
        return incident.category?.slug === 'electricity'
            && incident.source?.type === 'official'
            && incident.status?.value === 'scheduled'
            && String(incident.title || '').toLocaleLowerCase('es').includes('ande');
    }

    function normalizePlaceKey(place) {
        return String(place || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim()
            .toLocaleLowerCase('es');
    }

    function selectRelevantSchedule(incidents) {
        const now = Date.now();

        return [...incidents].sort((left, right) => {
            const leftStart = Date.parse(left.starts_at);
            const rightStart = Date.parse(right.starts_at);
            const leftEnd = Date.parse(left.ends_at || left.expires_at);
            const rightEnd = Date.parse(right.ends_at || right.expires_at);
            const leftRank = scheduleRank(leftStart, leftEnd, now);
            const rightRank = scheduleRank(rightStart, rightEnd, now);

            if (leftRank !== rightRank) return leftRank - rightRank;
            return leftRank === 2 ? rightStart - leftStart : leftStart - rightStart;
        })[0];
    }

    function scheduleRank(startsAt, endsAt, now) {
        if (Number.isFinite(startsAt) && startsAt <= now && (!Number.isFinite(endsAt) || endsAt >= now)) return 0;
        if (Number.isFinite(startsAt) && startsAt > now) return 1;
        return 2;
    }

    function createTooltip(incident) {
        const tooltip = document.createElement('div');
        tooltip.className = 'marker-tooltip';
        const title = document.createElement('strong');
        title.textContent = incident.title;
        const metadata = createIconLabel(incident.source.type, `${incident.source.label} · ${incident.location.text}`);
        tooltip.append(title, metadata);
        if (incident.map_group?.count > 1) {
            tooltip.appendChild(createTextElement(
                'span',
                `Un solo marcador para ${incident.map_group.count} fechas del periodo seleccionado`,
                'marker-group-label',
            ));
        }
        return tooltip;
    }

    function renderCategoryAvailability(availability) {
        const counts = new Map(availability.map(item => [item.slug, Number(item.count) || 0]));
        elements.categoryFilter.querySelectorAll('[data-category-count]').forEach(counter => {
            const count = counts.get(counter.dataset.categoryCount);
            counter.textContent = count === undefined ? '—' : String(count);
            counter.classList.toggle('is-empty', count === 0);
            const label = counter.closest('label');
            label.title = count === undefined ? 'Disponibilidad sin consultar'
                : `${count} ${count === 1 ? 'incidencia' : 'incidencias'} en esta zona, período y tipo de fuente`;
        });
    }

    function renderSummary(summary) {
        elements.summary.replaceChildren();

        if (!summary.length) {
            const message = document.createElement('p');
            message.className = 'muted';
            message.textContent = selectedCategories().length
                ? 'No hay incidencias para esta selección. Probá otro período, tipo de fuente o zona del mapa.'
                : 'Los eventos están ocultos. Activá una categoría o pulsá “Todas”.';
            elements.summary.appendChild(message);
            return;
        }

        summary.forEach(item => {
            const row = document.createElement('div');
            row.className = 'summary-row';
            const name = createIconLabel(item.slug, item.name, 'summary-name');
            const count = document.createElement('strong');
            count.textContent = String(item.count);
            row.append(name, count);
            elements.summary.appendChild(row);
        });
    }

    async function loadIncidentDetail(id, mapGroup = null) {
        detailAbortController?.abort();
        const controller = new AbortController();
        detailAbortController = controller;
        const isCurrent = () => detailAbortController === controller && !controller.signal.aborted && !elements.detail.hidden;
        openIncidentDetailPanel();
        elements.detailContent.replaceChildren(createTextElement('p', 'Consultando detalle verificable…', 'muted'));

        try {
            const response = await fetch(`${app.dataset.incidentBaseUrl}/${encodeURIComponent(id)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });

            if (!response.ok) {
                throw new Error(`La API respondió ${response.status}`);
            }

            const payload = await response.json();
            if (!isCurrent()) return;
            renderIncidentDetail(payload.data, mapGroup);
        } catch (error) {
            if (!isCurrent() || error.name === 'AbortError') return;
            elements.detailContent.replaceChildren(createTextElement('p', 'No fue posible cargar el detalle.', 'muted'));
        }
    }

    function renderIncidentDetail(incident, mapGroup = null) {
        const fragment = document.createDocumentFragment();
        const categoryLabel = incident.subcategory
            ? `${incident.category.name} · ${humanizeCode(incident.subcategory)}`
            : incident.category.name;
        const category = createIconLabel(incident.category.slug, categoryLabel, 'detail-category');
        const title = createTextElement('h2', incident.title);
        title.id = 'detail-title';
        const place = createTextElement('p', incident.location.text, 'detail-place');
        const badges = document.createElement('div');
        badges.className = 'detail-badges';
        const sourceBadge = createIconLabel(incident.source.type, incident.source.label, `detail-badge source-badge source-badge--${incident.source.type}`);
        const statusBadge = createTextElement('span', incident.status.lifecycle_label || incident.status.label, `detail-badge status-badge status-badge--${incident.status.lifecycle || incident.status.value}`);
        const severityBadge = createTextElement('span', `Severidad ${incident.severity.label.toLowerCase()}`, `detail-badge severity-badge severity-badge--${incident.severity.value}`);
        const verificationBadge = createTextElement('span', incident.verification_level?.label || 'Sin verificar', 'detail-badge verification-badge');
        const freshnessBadge = createTextElement('span', incident.freshness?.label || 'Sin actualización', `detail-badge freshness-badge freshness-badge--${incident.freshness?.value || 'archived'}`);
        badges.append(sourceBadge, statusBadge, severityBadge, verificationBadge, freshnessBadge);
        if (incident.source.verification === 'unconfirmed') {
            badges.appendChild(createIconLabel('warning', 'Sin confirmación', 'detail-badge verification-badge--unconfirmed'));
        }

        fragment.append(category, title, place, badges, createTextElement('p', incident.summary, 'detail-summary'));
        if (incident.location.is_fallback) {
            fragment.appendChild(createIconLabel(
                'warning',
                incident.location.fallback_label || 'Ubicación no informada; marcador de referencia en Asunción.',
                'detail-location-fallback',
            ));
        }

        if (mapGroup?.count > 1) {
            const dateRange = mapGroup.from && mapGroup.to
                ? ` entre ${formatDate(mapGroup.from)} y ${formatDate(mapGroup.to)}`
                : '';
            const groupedNotice = createIconLabel(
                'layers',
                `Este marcador reúne ${mapGroup.count} trabajos programados para ${mapGroup.place}${dateRange}. Se muestra primero el trabajo vigente o más próximo.`,
                'detail-group-notice',
            );
            fragment.appendChild(groupedNotice);
        }

        if (incident.community_report?.photo_url || incident.community_report?.link) {
            const evidence = document.createElement('section');
            evidence.className = 'community-evidence';

            if (incident.community_report.photo_url) {
                const image = document.createElement('img');
                image.src = incident.community_report.photo_url;
                image.alt = `Foto aportada por la comunidad: ${incident.title}`;
                image.loading = 'lazy';
                evidence.appendChild(image);
            }

            const safeCommunityLink = safeExternalUrl(incident.community_report.link);
            if (safeCommunityLink) {
                const link = document.createElement('a');
                link.href = safeCommunityLink;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.append(visualIcons.node('external'), document.createTextNode('Abrir enlace aportado'));
                evidence.appendChild(link);
            }

            fragment.appendChild(evidence);
        }

        if (incident.community_validation) {
            fragment.appendChild(createCommunityValidation(incident));
        }

        const accuracy = createIconLabel(
            incident.location.accuracy,
            incident.location.quality_label
                ? `${incident.location.accuracy_label} · calidad ${incident.location.quality_label.toLowerCase()}`
                : incident.location.accuracy_label,
            `accuracy-note accuracy-note--${incident.location.accuracy}`,
        );
        fragment.appendChild(accuracy);

        if (incident.location.road) {
            const road = document.createElement('section'); road.className = 'detail-section road-location-detail';
            road.appendChild(createTextElement('h3', 'Ubicación vial'));
            road.appendChild(createIconLabel('roads', `Ruta ${incident.location.road.code}${incident.location.road.kilometer !== null ? ` · km ${incident.location.road.kilometer}` : ''}`));
            const methodLabels = { ROUTE_KM_OFFICIAL: 'Ubicación basada en progresiva oficial', ROUTE_KM_OBSERVED: 'Ubicación basada en un hito kilométrico físico cartografiado', ROUTE_KM_INTERPOLATED: 'Kilometraje vial interpolado sobre la geometría de la ruta', ROUTE_INTERSECTION: 'Intersección vial calculada', ROUTE_LOCALITY: 'Tramo vial aproximado dentro de la localidad', ROUTE_ONLY: 'Ruta identificada sin punto preciso' };
            road.appendChild(createTextElement('p', methodLabels[incident.location.road.method] || 'Referencia vial informada por la fuente', 'detail-place'));
            if (incident.location.road.estimated_error_meters) road.appendChild(createTextElement('small', `Precisión estimada: ±${incident.location.road.estimated_error_meters} m`, 'muted'));
            fragment.appendChild(road);
        }

        if (incident.territorial_impact) {
            fragment.appendChild(createTerritorialImpact(incident.territorial_impact));
        }

        if (incident.nearby_infrastructure?.total > 0) {
            fragment.appendChild(createNearbyInfrastructure(incident.nearby_infrastructure));
        }

        if (incident.climate_context) {
            fragment.appendChild(createClimateContext(incident.climate_context));
        }

        const dates = document.createElement('section');
        dates.className = 'detail-section';
        dates.appendChild(createTextElement('h3', 'Cuándo'));
        dates.appendChild(createTextElement('p', incident.occurred_at
            ? `Ocurrió: ${formatDateTime(incident.occurred_at)}${incident.occurred_at_accuracy && incident.occurred_at_accuracy !== 'exact' ? ' (aproximado)' : ''}`
            : 'Hora de ocurrencia no determinada por la fuente', 'detail-place'));
        dates.appendChild(createTextElement('p', `Publicado: ${formatDateTime(incident.published_at)}`, 'detail-place'));
        if (incident.starts_at) dates.appendChild(createTextElement('p', `Inicio: ${formatDateTime(incident.starts_at)}`, 'detail-place'));
        if (incident.ends_at) dates.appendChild(createTextElement('p', `Fin: ${formatDateTime(incident.ends_at)}`, 'detail-place'));
        fragment.appendChild(dates);

        const sources = document.createElement('section');
        sources.className = 'detail-section';
        sources.appendChild(createTextElement('h3', 'Fuentes originales'));

        if (!incident.sources.length) {
            sources.appendChild(createTextElement('p', 'No hay referencias públicas asociadas.', 'muted'));
        }

        incident.sources.forEach(source => {
            const card = document.createElement('article');
            card.className = 'source-card';
            card.append(
                createIconLabel(source.type, source.name, 'source-card-heading'),
                createTextElement('strong', source.original_title),
                createTextElement('span', `${humanizeCode(source.role || 'primary')} · ${formatDateTime(source.published_at || source.detected_at)}`),
            );

            const safeUrl = safeExternalUrl(source.original_url);
            if (safeUrl) {
                const link = document.createElement('a');
                link.href = safeUrl;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.append(visualIcons.node('external'), document.createTextNode('Abrir fuente original'));
                card.appendChild(link);
            }
            sources.appendChild(card);
        });
        fragment.appendChild(sources);

        if (incident.publication_explanation?.reasons?.length) {
            const explanation = document.createElement('section');
            explanation.className = 'detail-section publication-explanation';
            explanation.appendChild(createTextElement('h3', '¿Por qué aparece?'));
            incident.publication_explanation.reasons.forEach(reason => explanation.appendChild(createIconLabel('check', reason, 'detail-place detail-reason')));
            fragment.appendChild(explanation);
        }

        if (incident.updates.length) {
            const timeline = document.createElement('section');
            timeline.className = 'detail-section';
            timeline.appendChild(createTextElement('h3', 'Evolución'));
            incident.updates.forEach(update => {
                const item = document.createElement('article');
                item.className = 'timeline-item';
                item.append(
                    createTextElement('strong', `${humanizeCode(update.event_type || 'updated')} · ${update.title}`),
                    createTextElement('p', update.summary),
                    createTextElement('time', formatDateTime(update.occurred_at)),
                );
                timeline.appendChild(item);
            });
            fragment.appendChild(timeline);
        }

        const actions = document.createElement('div');
        actions.className = 'detail-actions';
        const originalUrl = incident.sources.map(source => safeExternalUrl(source.original_url)).find(Boolean);
        if (originalUrl) {
            const sourceAction = document.createElement('a');
            sourceAction.className = 'button button-secondary';
            sourceAction.href = originalUrl;
            sourceAction.target = '_blank';
            sourceAction.rel = 'noopener noreferrer';
            sourceAction.append(visualIcons.node('external'), document.createTextNode('Ver fuente original'));
            actions.appendChild(sourceAction);
        }
        const shareAction = document.createElement('button');
        shareAction.type = 'button';
        shareAction.className = 'button button-ghost';
        shareAction.append(visualIcons.node('share'), document.createTextNode('Compartir'));
        shareAction.addEventListener('click', async () => {
            const shareUrl = originalUrl || window.location.href;
            try {
                if (navigator.share) await navigator.share({ title: incident.title, text: incident.summary, url: shareUrl });
                else if (navigator.clipboard) {
                    await navigator.clipboard.writeText(shareUrl);
                    shareAction.replaceChildren(visualIcons.node('check'), document.createTextNode('Enlace copiado'));
                }
            } catch (error) {
                if (error.name !== 'AbortError') shareAction.replaceChildren(visualIcons.node('warning'), document.createTextNode('No se pudo compartir'));
            }
        });
        actions.appendChild(shareAction);
        fragment.appendChild(actions);

        elements.detailContent.replaceChildren(fragment);
    }

    function humanizeCode(value) {
        return String(value || '').replaceAll('_', ' ').replace(/\b\p{L}/gu, letter => letter.toUpperCase());
    }

    function createTerritorialImpact(impact) {
        const populationNumber = value => {
            if (typeof value !== 'number' && (typeof value !== 'string' || value.trim() === '')) return null;
            const number = Number(value);
            return Number.isFinite(number) && number >= 0 ? number : null;
        };
        const potentialPopulation = populationNumber(impact.population_potential);
        const referencePopulation = populationNumber(impact.population_reference);
        const populationValue = potentialPopulation ?? referencePopulation;
        const hasPotentialPopulation = potentialPopulation !== null;
        const censusYear = populationNumber(impact.census_year);
        const section = document.createElement('section');
        section.className = 'detail-section territorial-impact';
        section.appendChild(createTextElement('h3', hasPotentialPopulation ? 'Impacto territorial estimado' : 'Contexto territorial'));

        const card = document.createElement('div');
        card.className = 'territorial-impact-card';

        const population = populationValue === null ? 'Sin dato' : new Intl.NumberFormat('es-PY').format(populationValue);
        const populationLabel = populationValue === null ? 'población de referencia no disponible'
            : hasPotentialPopulation ? `población potencial${Number.isInteger(censusYear) && censusYear > 0 ? ` · Censo ${censusYear}` : ''}`
                : 'población censal de referencia · no equivale a afectados';
        const populationMetric = document.createElement('div');
        populationMetric.className = 'territorial-impact-metric';
        populationMetric.append(
            visualIcons.node('community'),
            createTextElement('strong', population),
            createTextElement('span', populationLabel),
        );

        const territoryMetric = document.createElement('div');
        territoryMetric.className = 'territorial-impact-metric';
        const territory = impact.territory || {};
        territoryMetric.append(
            visualIcons.node('district'),
            createTextElement('strong', territory.district || territory.department || 'Territorio no especificado'),
            createTextElement('span', territory.district ? territory.department || '' : territory.department ? 'Departamento de referencia' : ''),
        );
        card.append(populationMetric, territoryMetric);
        section.append(card, createTextElement('p', impact.methodology_notice || impact.disclaimer, 'territorial-impact-disclaimer'));

        const safeSourceUrl = safeExternalUrl(impact.source?.url);
        if (safeSourceUrl) {
            const source = document.createElement('a');
            source.className = 'territorial-impact-source';
            source.href = safeSourceUrl;
            source.target = '_blank';
            source.rel = 'noopener noreferrer';
            source.append(visualIcons.node('external'), document.createTextNode(`Fuente: ${impact.source.name}`));
            section.appendChild(source);
        }

        return section;
    }

    function createNearbyInfrastructure(infrastructure) {
        const section = document.createElement('section');
        section.className = 'detail-section';
        section.appendChild(createTextElement('h3', `Infraestructura dentro de ${infrastructure.radius_km} km`));
        const grid = document.createElement('div');
        grid.className = 'nearby-infrastructure-grid';
        infrastructure.categories.filter(item => item.count > 0).forEach(item => {
            const row = document.createElement('div');
            row.className = 'nearby-infrastructure-item';
            row.append(visualIcons.node(item.icon), createTextElement('strong', item.label), createTextElement('b', String(item.count)));
            grid.appendChild(row);
        });
        section.appendChild(grid);
        return section;
    }

    function createClimateContext(context) {
        const section = document.createElement('section');
        section.className = 'detail-section';
        section.appendChild(createTextElement('h3', 'Contexto climático relacionado'));
        const chain = document.createElement('div');
        chain.className = 'climate-context-chain';
        if (context.weather_event) chain.appendChild(createIconLabel('weather_flood', context.weather_event.title, 'climate-context-node'));
        if (context.station) {
            const readings = context.station.measurements || {};
            const temperature = readings.temperature ? `${readings.temperature.value} ${readings.temperature.unit || '°C'}` : 'sin temperatura';
            chain.appendChild(createTextElement('span', `${context.weather_event ? '↓' : ''}`, 'climate-context-arrow'));
            chain.appendChild(createIconLabel('sensor', `${context.station.name}: ${temperature}`, 'climate-context-node'));
        }
        context.related_groups.forEach(group => {
            chain.appendChild(createTextElement('span', '↓', 'climate-context-arrow'));
            chain.appendChild(createIconLabel(group.slug || 'info', `${group.count} ${group.category}`, 'climate-context-node'));
        });
        section.append(chain, createTextElement('p', context.disclaimer, 'climate-context-disclaimer'));
        return section;
    }

    async function loadZoneNow() {
        if (!Number.isFinite(state.nearLatitude) || !Number.isFinite(state.nearLongitude)) return;
        state.zoneAbortController?.abort();
        const controller = new AbortController();
        state.zoneAbortController = controller;
        const url = new URL(app.dataset.zoneNowUrl, window.location.origin);
        url.searchParams.set('lat', state.nearLatitude.toFixed(7));
        url.searchParams.set('lng', state.nearLongitude.toFixed(7));
        url.searchParams.set('radius_km', String(state.radiusKm));
        elements.zoneNow.hidden = false;
        elements.zoneNowContent.replaceChildren(createTextElement('p', 'Consultando tu zona…', 'muted'));
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const payload = await response.json();
            if (controller.signal.aborted || state.zoneAbortController !== controller) return;
            renderZoneNow(payload.data);
        } catch (error) {
            if (error.name !== 'AbortError' && state.zoneAbortController === controller) elements.zoneNowContent.replaceChildren(createTextElement('p', 'No fue posible consultar tu zona.', 'muted'));
        }
    }

    function renderZoneNow(data) {
        const fragment = document.createDocumentFragment();
        fragment.appendChild(createTextElement('p', data.message, 'zone-now-message'));
        const list = document.createElement('div');
        list.className = 'zone-now-list';
        data.weather_alerts.forEach(alert => {
            const row = document.createElement('div'); row.className = 'zone-now-row';
            row.append(visualIcons.node('warning'), createTextElement('span', alert.headline), createTextElement('b', alert.severity || 'Vigente')); list.appendChild(row);
        });
        data.incident_groups.forEach(group => {
            const row = document.createElement('div'); row.className = 'zone-now-row';
            row.append(visualIcons.node(group.slug), createTextElement('span', group.name), createTextElement('b', `${group.count} · ${group.nearest_distance_km} km`)); list.appendChild(row);
        });
        if (list.children.length) fragment.appendChild(list);
        if (data.weather_station) {
            const readings = data.weather_station.measurements || {};
            const parts = [];
            if (readings.temperature) parts.push(`${readings.temperature.value} ${readings.temperature.unit || '°C'}`);
            if (readings.wind_speed) parts.push(`viento ${readings.wind_speed.value} ${readings.wind_speed.unit || 'm/s'}`);
            if (readings.precipitation) parts.push(`lluvia ${readings.precipitation.value} ${readings.precipitation.unit || 'mm'}`);
            fragment.appendChild(createIconLabel('sensor', `${data.weather_station.name} · ${data.weather_station.distance_km} km${parts.length ? ` · ${parts.join(' · ')}` : ''}`, 'zone-weather'));
        }
        const infraCounts = data.infrastructure.categories.filter(item => item.count > 0).map(item => `${item.count} ${item.label.toLowerCase()}`);
        if (infraCounts.length) fragment.appendChild(createTextElement('div', `Dentro de ${data.infrastructure.radius_km} km: ${infraCounts.join(' · ')}`, 'zone-weather'));
        elements.zoneNowContent.replaceChildren(fragment);
    }

    async function loadWeatherStations() {
        state.weatherAbortController?.abort();
        state.weatherAbortController = null;
        const enabled = elements.monitoringFilter.querySelector('input[value="dmh"]').checked;
        if (!enabled) {
            weatherStationsLayer.clearLayers();
            if (map.hasLayer(weatherStationsLayer)) map.removeLayer(weatherStationsLayer);
            elements.monitoringStatus.textContent = 'Capa apagada.';
            elements.monitoringStatus.dataset.state = 'idle';
            return;
        }

        const controller = new AbortController();
        state.weatherAbortController = controller;
        const isCurrent = () => state.weatherAbortController === controller
            && !controller.signal.aborted
            && elements.monitoringFilter.querySelector('input[value="dmh"]').checked;
        elements.monitoringStatus.textContent = 'Cargando estaciones DMH…';
        elements.monitoringStatus.dataset.state = 'loading';
        try {
            const response = await fetch(app.dataset.stationsUrl, { headers: { Accept: 'application/json' }, signal: controller.signal });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const payload = await response.json();
            if (!isCurrent()) return;
            weatherStationsLayer.clearLayers();
            const stationMarkers = payload.data.filter(station => ['weather', 'hydrology'].includes(station.kind) && station.is_collecting_data).map(station => {
                const markerNode = document.createElement('div');
                markerNode.className = 'dmh-station-pin';
                const icon = document.createElement('img');
                icon.src = station.kind === 'hydrology' ? '/assets/alertapy-ui/icons/monitoring/estacion-hidrologica.svg' : dmhStationIconUrl;
                icon.alt = '';
                markerNode.appendChild(icon);
                const marker = L.marker([station.latitude, station.longitude], {
                    title: station.name,
                    icon: L.divIcon({ html: markerNode.outerHTML, className: '', iconSize: [28, 28], iconAnchor: [14, 14] }),
                });
                const content = document.createElement('div');
                content.className = 'marker-tooltip';
                content.append(createTextElement('strong', station.name), createTextElement('span', `${station.city || station.department || 'Paraguay'} · DMH`));
                const measurements = (station.measurements || []).filter(measurement => dmhMeasurementIcons[measurement.parameter]);
                if (measurements.length) {
                    const measurementList = document.createElement('div');
                    measurementList.className = 'dmh-measurements';
                    const seenIcons = new Set();
                    measurements.forEach(measurement => {
                        const iconUrl = dmhMeasurementIcons[measurement.parameter];
                        if (seenIcons.has(iconUrl)) return;
                        seenIcons.add(iconUrl);
                        const item = document.createElement('span');
                        const measurementIcon = document.createElement('img');
                        measurementIcon.src = iconUrl;
                        measurementIcon.alt = '';
                        item.append(measurementIcon, document.createTextNode(`${measurement.value}${measurement.unit ? ` ${measurement.unit}` : ''}`));
                        measurementList.appendChild(item);
                    });
                    content.appendChild(measurementList);
                }
                marker.bindTooltip(content, { direction: 'top', offset: [0, -22] });
                return marker;
            });
            weatherStationsLayer.addLayers(stationMarkers);
            if (!map.hasLayer(weatherStationsLayer)) weatherStationsLayer.addTo(map);
            elements.monitoringStatus.textContent = `${stationMarkers.length} estaciones DMH activas.`;
            elements.monitoringStatus.dataset.state = stationMarkers.length ? 'ready' : 'empty';
        } catch (error) {
            if (error.name !== 'AbortError' && isCurrent()) {
                elements.monitoringStatus.textContent = 'No fue posible cargar las estaciones DMH.';
                elements.monitoringStatus.dataset.state = 'error';
            }
        }
    }

    async function loadReferenceGeoJson(key, url, layer) {
        if (layer.options.alertapyLoaded) return layer.options.alertapyFeatureCount || 0;
        if (!state.mapLayerPromises.has(key)) {
            state.mapLayerPromises.set(key, fetch(url, { headers: { Accept: 'application/geo+json, application/json' }, cache: 'force-cache' })
                .then(response => {
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    return response.json();
                })
                .then(payload => {
                    layer.addData(payload);
                    layer.options.alertapyLoaded = true;
                    layer.options.alertapyFeatureCount = Number(payload?.metadata?.source_feature_count || payload?.metadata?.feature_count || payload?.features?.length || 0);
                    return layer.options.alertapyFeatureCount;
                })
                .catch(error => {
                    state.mapLayerPromises.delete(key);
                    throw error;
                }));
        }
        return state.mapLayerPromises.get(key);
    }

    let cartographyRevision = 0;

    async function loadCartographyLayers() {
        const revision = ++cartographyRevision;
        const isCurrent = () => revision === cartographyRevision;
        const activeLayers = [...elements.roadFilter.querySelectorAll('input:checked')].map(input => input.value);
        if (!activeLayers.includes('roads') && map.hasLayer(roadNetworkLayer)) map.removeLayer(roadNetworkLayer);
        if (!activeLayers.includes('rivers') && map.hasLayer(riverNetworkLayer)) map.removeLayer(riverNetworkLayer);
        if (!activeLayers.length) {
            elements.roadStatus.textContent = 'Capas apagadas.';
            elements.roadStatus.dataset.state = 'idle';
            return;
        }

        elements.roadStatus.textContent = 'Cargando cartografía local…';
        elements.roadStatus.dataset.state = 'loading';
        try {
            const counts = {};
            if (activeLayers.includes('roads')) counts.roads = await loadReferenceGeoJson('roads', app.dataset.roadLayerUrl, roadNetworkLayer);
            if (!isCurrent()) return;
            if (activeLayers.includes('rivers')) counts.rivers = await loadReferenceGeoJson('rivers', app.dataset.riversLayerUrl, riverNetworkLayer);
            if (!isCurrent()) return;

            if (activeLayers.includes('roads') && elements.roadFilter.querySelector('input[value="roads"]').checked) {
                if (!map.hasLayer(roadNetworkLayer)) roadNetworkLayer.addTo(map);
                roadNetworkLayer.bringToBack();
            }
            if (activeLayers.includes('rivers') && elements.roadFilter.querySelector('input[value="rivers"]').checked) {
                if (!map.hasLayer(riverNetworkLayer)) riverNetworkLayer.addTo(map);
            }

            const messages = [];
            if (activeLayers.includes('roads')) messages.push(`${Number(counts.roads).toLocaleString('es-PY')} tramos viales`);
            if (activeLayers.includes('rivers')) messages.push(`${Number(counts.rivers).toLocaleString('es-PY')} tramos hidrográficos`);
            elements.roadStatus.textContent = `${messages.join(' · ')}. Datos locales del INE.`;
            elements.roadStatus.dataset.state = 'ready';
        } catch (error) {
            if (!isCurrent()) return;
            elements.roadStatus.textContent = 'No fue posible cargar la cartografía.';
            elements.roadStatus.dataset.state = 'error';
        }
    }

    function syncInfrastructureSelection() {
        state.infrastructureAbortController?.abort();
        state.infrastructureAbortController = null;
        const activeKinds = [...elements.infrastructureFilter.querySelectorAll('input:checked')].map(input => input.value);
        Object.entries(infrastructureLayers).forEach(([kind, layer]) => {
            if (!activeKinds.includes(kind)) {
                layer.clearLayers();
                if (map.hasLayer(layer)) map.removeLayer(layer);
            }
        });
        if (!activeKinds.length) {
            elements.infrastructureStatus.textContent = 'Capas apagadas.';
            elements.infrastructureStatus.dataset.state = 'idle';
        }
        return activeKinds;
    }

    async function loadInfrastructureLayers() {
        const activeKinds = syncInfrastructureSelection();
        if (!activeKinds.length) return;
        const controller = new AbortController();
        state.infrastructureAbortController = controller;
        const isCurrent = () => state.infrastructureAbortController === controller
            && !controller.signal.aborted
            && [...elements.infrastructureFilter.querySelectorAll('input:checked')].map(input => input.value).join(',') === activeKinds.join(',');
        elements.infrastructureStatus.textContent = 'Cargando puntos visibles…';
        elements.infrastructureStatus.dataset.state = 'loading';
        const bounds = map.getBounds();
        const url = new URL(app.dataset.infrastructureUrl, window.location.origin);
        url.searchParams.set('bbox', [bounds.getSouth(), bounds.getWest(), bounds.getNorth(), bounds.getEast()].map(value => value.toFixed(7)).join(','));
        url.searchParams.set('kinds', activeKinds.join(','));
        url.searchParams.set('zoom', String(map.getZoom()));
        try {
            let payload;
            if (map.getZoom() <= 6 && state.nationalInfrastructurePromise) {
                const nationalPayload = await state.nationalInfrastructurePromise;
                if (!isCurrent()) return;
                if (nationalPayload) payload = { ...nationalPayload, data: nationalPayload.data.filter(facility => activeKinds.includes(facility.kind)) };
            }
            if (!payload) {
                const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                payload = await response.json();
            }
            if (!isCurrent()) return;
            activeKinds.forEach(kind => {
                infrastructureLayers[kind].clearLayers();
                if (!map.hasLayer(infrastructureLayers[kind])) infrastructureLayers[kind].addTo(map);
            });
            const markersByKind = { health: [], education: [], police: [] };
            payload.data.forEach(facility => {
                const layer = infrastructureLayers[facility.kind];
                if (!layer) return;
                const markerNode = document.createElement('div');
                markerNode.className = `facility-pin facility-pin--${facility.kind}`;
                markerNode.appendChild(visualIcons.node(infrastructureIconNames[facility.kind]));
                if (facility.count > 1) markerNode.appendChild(createTextElement('span', String(facility.count), 'facility-pin-count'));
                const marker = L.marker([facility.latitude, facility.longitude], {
                    facilityCount: facility.count || 1,
                    icon: L.divIcon({ html: markerNode.outerHTML, className: '', iconSize: [26, 30], iconAnchor: [13, 27] }),
                });
                const tooltip = document.createElement('div');
                tooltip.className = 'facility-marker-tooltip';
                tooltip.append(createTextElement('strong', facility.name), createTextElement('span', `${facility.category || 'Infraestructura'} · ${facility.district || facility.department || 'Paraguay'}`));
                marker.bindTooltip(tooltip, { direction: 'top', offset: [0, -20] });
                if (facility.is_cluster) marker.on('click', () => map.setView([facility.latitude, facility.longitude], Math.min(9, map.getZoom() + 2), { animate: false }));
                markersByKind[facility.kind].push(marker);
            });
            const labels = { health: 'salud', education: 'educación', police: 'policía' };
            const counts = activeKinds.map(kind => ({
                kind,
                count: payload.data.filter(facility => facility.kind === kind).reduce((sum, facility) => sum + (facility.count || 1), 0),
            })).filter(item => item.count > 0);
            activeKinds.forEach(kind => infrastructureLayers[kind].addLayers(markersByKind[kind]));
            elements.infrastructureStatus.textContent = counts.length
                ? `${counts.map(item => `${item.count} de ${labels[item.kind]}`).join(' · ')} en esta vista${payload.meta.aggregated ? ' · acercá para ver cada lugar' : payload.meta.truncated ? ' (acercá para ver todos)' : ''}.`
                : 'No hay puntos de esas capas en esta vista.';
            elements.infrastructureStatus.dataset.state = counts.length ? 'ready' : 'empty';
        } catch (error) {
            if (error.name !== 'AbortError' && isCurrent()) {
                elements.infrastructureStatus.textContent = 'No fue posible cargar los puntos. Intentá acercar el mapa.';
                elements.infrastructureStatus.dataset.state = 'error';
            }
        }
    }

    function prefetchNationalInfrastructure() {
        if (state.nationalInfrastructurePromise) return state.nationalInfrastructurePromise;
        try {
            const cached = JSON.parse(window.localStorage.getItem(nationalInfrastructureStorageKey) || 'null');
            if (cached?.storedAt > Date.now() - 30 * 60 * 1000 && Array.isArray(cached.payload?.data)) {
                state.nationalInfrastructurePromise = Promise.resolve(cached.payload);
                return state.nationalInfrastructurePromise;
            }
        } catch (_) { /* Una caché dañada simplemente se reemplaza. */ }

        const url = new URL(app.dataset.infrastructureUrl, window.location.origin);
        url.searchParams.set('bbox', '-27.8,-63,-19,-54');
        url.searchParams.set('kinds', 'health,education,police');
        url.searchParams.set('zoom', '6');
        state.nationalInfrastructurePromise = fetch(url, { headers: { Accept: 'application/json' } })
            .then(response => {
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                return response.json();
            })
            .then(payload => {
                try {
                    window.localStorage.setItem(nationalInfrastructureStorageKey, JSON.stringify({ storedAt: Date.now(), payload }));
                } catch (_) { /* La capa sigue funcionando aunque el navegador no permita almacenamiento. */ }
                return payload;
            })
            .catch(() => null);

        return state.nationalInfrastructurePromise;
    }

    function createTextElement(tag, text, className = '') {
        const element = document.createElement(tag);
        element.textContent = text;
        if (className) element.className = className;
        return element;
    }

    function createIconLabel(iconName, text, className = '') {
        const element = document.createElement('span');
        element.className = ['icon-label', className].filter(Boolean).join(' ');
        element.append(visualIcons.node(iconName), document.createTextNode(text));
        return element;
    }

    function createCommunityValidation(incident) {
        const validation = incident.community_validation;
        const section = document.createElement('section');
        section.className = 'community-validation';
        if (!validation.accepting_votes) section.classList.add('is-removed');
        const heading = document.createElement('div');
        heading.className = 'community-validation-heading';
        heading.append(
            createIconLabel('community', 'Validación comunitaria'),
            createTextElement('small', 'Un voto por navegador'),
        );
        const explanation = createTextElement(
            'p',
            `¿Podés confirmar esta información? Al alcanzar ${validation.negative_threshold} votos negativos se retira del mapa activo, sin borrar su historial.`,
        );
        const actions = document.createElement('div');
        actions.className = 'community-vote-actions';
        const positive = document.createElement('button');
        positive.type = 'button';
        positive.dataset.vote = 'positive';
        positive.append(
            visualIcons.node('verified'),
            document.createTextNode('Es correcto '),
            createTextElement('b', String(validation.positive), 'community-positive-count'),
        );
        const negative = document.createElement('button');
        negative.type = 'button';
        negative.dataset.vote = 'negative';
        negative.append(
            visualIcons.node('warning'),
            document.createTextNode('No es correcto '),
            createTextElement('b', String(validation.negative), 'community-negative-count'),
        );
        positive.disabled = !validation.accepting_votes;
        negative.disabled = !validation.accepting_votes;
        actions.append(positive, negative);
        const feedback = createTextElement(
            'p',
            validation.accepting_votes
                ? 'Tu validación ayuda a mantener actualizado el mapa.'
                : 'Este reporte fue dado de baja del mapa activo y permanece disponible en el historial.',
            'community-vote-feedback',
        );
        feedback.setAttribute('role', 'status');
        section.append(heading, explanation, actions, feedback);

        actions.addEventListener('click', event => {
            const button = event.target.closest('[data-vote]');
            if (button && validation.accepting_votes) {
                submitCommunityVote(incident.id, button.dataset.vote, section);
            }
        });

        return section;
    }

    async function submitCommunityVote(incidentId, vote, section) {
        const buttons = [...section.querySelectorAll('[data-vote]')];
        const feedback = section.querySelector('.community-vote-feedback');
        buttons.forEach(button => { button.disabled = true; });
        feedback.textContent = 'Registrando tu validación…';

        try {
            const response = await fetch(`${app.dataset.incidentBaseUrl}/${encodeURIComponent(incidentId)}/vote`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ vote, voter_token: communityVoterToken() }),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(reportErrorMessage(payload, 'No fue posible registrar tu validación.'));
            }

            const result = payload.data;
            section.querySelector('.community-positive-count').textContent = String(result.positive);
            section.querySelector('.community-negative-count').textContent = String(result.negative);
            buttons.forEach(button => button.classList.toggle('is-selected', button.dataset.vote === vote));
            feedback.textContent = result.removed
                ? 'El reporte alcanzó 5 votos negativos y fue retirado del mapa activo. Quedó guardado en el historial.'
                : 'Gracias. Tu validación quedó registrada.';

            if (result.removed || !result.accepting_votes) {
                section.classList.add('is-removed');
                await loadIncidents({ silent: true });
                await loadIncidentDetail(incidentId);
                return;
            }

            buttons.forEach(button => { button.disabled = false; });
        } catch (error) {
            feedback.textContent = error.message || 'No fue posible registrar tu validación.';
            buttons.forEach(button => { button.disabled = false; });
        }
    }

    function communityVoterToken() {
        try {
            const saved = window.localStorage.getItem(communityVoterStorageKey);
            if (saved) return saved;
            const token = window.crypto?.randomUUID?.()
                || `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
            window.localStorage.setItem(communityVoterStorageKey, token);
            return token;
        } catch (error) {
            return `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
        }
    }

    function safeExternalUrl(value) {
        try {
            const url = new URL(value);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : null;
        } catch (error) {
            return null;
        }
    }

    function formatDateTime(value) {
        if (!value) return 'Sin fecha informada';
        return new Intl.DateTimeFormat('es-PY', {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: appTimeZone,
            hour12: false,
        }).format(new Date(value));
    }

    function formatDate(value) {
        if (!value) return 'una fecha no informada';
        return new Intl.DateTimeFormat('es-PY', { dateStyle: 'medium', timeZone: appTimeZone }).format(new Date(value));
    }

    function formatRelativeTime(value) {
        if (!value) return 'recién';
        const date = new Date(value);
        const elapsedSeconds = Math.round((date.getTime() - Date.now()) / 1000);
        const formatter = new Intl.RelativeTimeFormat('es', { numeric: 'auto' });

        if (Math.abs(elapsedSeconds) < 45) return 'ahora';
        if (Math.abs(elapsedSeconds) < 60) return formatter.format(elapsedSeconds, 'second');
        const elapsedMinutes = Math.round(elapsedSeconds / 60);
        if (Math.abs(elapsedMinutes) < 60) return formatter.format(elapsedMinutes, 'minute');
        const elapsedHours = Math.round(elapsedMinutes / 60);
        if (Math.abs(elapsedHours) < 24) return formatter.format(elapsedHours, 'hour');
        const elapsedDays = Math.round(elapsedHours / 24);
        if (Math.abs(elapsedDays) <= 7) return formatter.format(elapsedDays, 'day');

        return new Intl.DateTimeFormat('es-PY', { dateStyle: 'short' }).format(date);
    }

    function updateRelativeRefreshLabels() {
        if (elements.refreshTime.dataset.refreshedAt) {
            elements.refreshTime.textContent = `Actualizado ${formatRelativeTime(elements.refreshTime.dataset.refreshedAt)}`;
        }
    }

    function showStatus(message, isError = false) {
        elements.status.hidden = false;
        elements.status.classList.toggle('is-error', isError);
        elements.status.querySelector('span:last-child').textContent = message;
        if (isError) {
            window.setTimeout(() => {
                elements.status.hidden = true;
                elements.status.classList.remove('is-error');
            }, 7000);
        }
    }

    const mobileFiltersMedia = window.matchMedia('(max-width: 760px)');
    let detailReturnFocus = null;
    let detailAbortController = null;

    function focusPanelControl(control) {
        const target = control?.isConnected && !control.closest('[hidden], [inert]') && control.getClientRects().length
            ? control : map.getContainer();
        target.focus({ preventScroll: true });
    }

    function syncPanelAccessibility() {
        const filtersVisible = mobileFiltersMedia.matches
            ? elements.filters.classList.contains('is-open') : !app.classList.contains('filters-collapsed');
        const filtersHadFocus = elements.filters.contains(document.activeElement);
        elements.filters.inert = !filtersVisible;
        elements.filters.setAttribute('aria-hidden', String(!filtersVisible));
        elements.mobileFilterToggle.setAttribute('aria-expanded', String(mobileFiltersMedia.matches && filtersVisible));
        elements.layersToggle?.setAttribute('aria-expanded', String(!app.classList.contains('filters-collapsed')));
        if (!filtersVisible && filtersHadFocus) focusPanelControl(mobileFiltersMedia.matches ? elements.mobileFilterToggle : elements.layersToggle);
    }

    function closeFiltersPanel({ restoreFocus = false } = {}) {
        elements.filters.classList.remove('is-open');
        syncPanelAccessibility();
        if (restoreFocus) focusPanelControl(elements.mobileFilterToggle);
    }

    function closeIncidentDetail({ restoreFocus = false } = {}) {
        detailAbortController?.abort();
        detailAbortController = null;
        elements.detail.hidden = true;
        if (restoreFocus) focusPanelControl(detailReturnFocus);
    }

    function closeFloatingPanels() {
        if (mobileFiltersMedia.matches) closeFiltersPanel();
        closeIncidentDetail();
    }

    function openIncidentDetailPanel() {
        if (elements.detail.hidden) detailReturnFocus = document.activeElement;
        if (mobileFiltersMedia.matches) closeFiltersPanel();
        elements.detail.hidden = false;
        focusPanelControl(elements.detailClose);
    }

    function handlePanelBreakpointChange() {
        if (!mobileFiltersMedia.matches) elements.filters.classList.remove('is-open');
        syncPanelAccessibility();
        if (!mobileFiltersMedia.matches && document.activeElement === elements.filtersClose) focusPanelControl(elements.toggleCategories);
    }

    mobileFiltersMedia.addEventListener('change', handlePanelBreakpointChange);
    syncPanelAccessibility();

    let mapDebounce;
    map.on('moveend', () => {
        window.clearTimeout(mapDebounce);
        mapDebounce = window.setTimeout(() => {
            loadIncidents({ silent: true });
            loadInfrastructureLayers();
        }, 350);
    });

    function syncAdvancedFilterIndicator() {
        const labels = [];
        const sourceLabels = { official: 'Oficial', press: 'Prensa', sensor: 'Sensor', community: 'Comunidad' };
        if (state.source && sourceLabels[state.source]) labels.push(sourceLabels[state.source]);
        if (Number.isFinite(state.nearLatitude) && Number.isFinite(state.nearLongitude) && !elements.nearRadius.disabled) {
            labels.push(`${state.radiusKm} km`);
        }
        if (elements.combineCategories?.checked) labels.push('Combinación');
        if (['1h', '6h'].includes(state.period)) labels.push(state.period === '1h' ? '1 h' : '6 h');
        if (elements.advancedFilterIndicator) {
            elements.advancedFilterIndicator.hidden = labels.length === 0;
            elements.advancedFilterIndicator.textContent = labels.length
                ? `${labels.length} ${labels.length === 1 ? 'filtro' : 'filtros'} · ${labels.join(' · ')}` : '';
        }
    }

    function syncCategoryToggle() {
        const inputs = [...elements.categoryFilter.querySelectorAll('input:not(:disabled)')];
        const allChecked = inputs.length > 0 && inputs.every(input => input.checked);
        state.allCategories = Boolean(state.allCategories || (elements.combineCategories?.checked && allChecked));
        state.categoriesVisible = state.allCategories || inputs.some(input => input.checked);
        elements.toggleCategories.setAttribute('aria-pressed', String(state.allCategories));
        elements.categoryFilter.classList.toggle('is-all-selected', state.allCategories);
        syncQuickCategoryButtons(inputs);
        if (elements.categoryMoreStatus) {
            const selectedInputs = inputs.filter(input => input.checked);
            elements.categoryMoreStatus.textContent = state.allCategories
                ? 'Todas'
                : selectedInputs.length > 1
                    ? `${selectedInputs.length} activas`
                    : selectedInputs.length === 1
                        ? `Activa: ${selectedInputs[0].closest?.('.category-option')?.querySelector?.('.category-name')?.textContent.trim() || selectedInputs[0].value}`
                        : `${inputs.length} disponibles`;
        }
        return state.categoriesVisible;
    }

    function syncQuickCategoryButtons(inputs = [...elements.categoryFilter.querySelectorAll('input:not(:disabled)')]) {
        if (!elements.quickCategories) return;
        const checked = inputs.filter(input => input.checked);
        const allChecked = inputs.length > 0 && checked.length === inputs.length;
        const healthCategories = new Set(['health_public', 'health_vector', 'animal_health']);
        const availableHealth = inputs.filter(input => healthCategories.has(input.value));
        const healthSelected = availableHealth.length > 0
            && checked.length === availableHealth.length
            && checked.every(input => healthCategories.has(input.value));
        const activeCategory = state.allCategories || (elements.combineCategories?.checked && allChecked)
            ? 'all'
            : healthSelected ? 'health'
                : !elements.combineCategories?.checked && checked.length === 1 ? checked[0].value : null;
        elements.quickCategories.querySelectorAll('[data-quick-category]').forEach(button => {
            button.setAttribute('aria-pressed', String(button.dataset.quickCategory === activeCategory));
        });
    }

    syncCategoryToggle();
    syncAdvancedFilterIndicator();
    elements.categoryFilter.addEventListener('change', () => {
        state.allCategories = false;
        syncCategoryToggle();
        syncAdvancedFilterIndicator();
        loadIncidents();
    });
    elements.combineCategories?.addEventListener('change', () => {
        const inputs = [...elements.categoryFilter.querySelectorAll('input:not(:disabled)')];
        const combine = elements.combineCategories.checked;
        const selected = inputs.filter(input => input.checked).map(input => input.value);
        const wasAll = state.allCategories;
        inputs.forEach(input => {
            input.checked = false;
            input.type = combine ? 'checkbox' : 'radio';
        });
        inputs.forEach(input => {
            input.checked = combine ? wasAll || selected.includes(input.value)
                : !wasAll && input.value === selected[0];
        });
        if (!combine && selected.length === 0) state.allCategories = true;
        elements.categoryFilter.setAttribute('role', combine ? 'group' : 'radiogroup');
        elements.categorySelectionHelp.textContent = combine
            ? 'Elegí una o varias categorías. «Todas» vuelve a mostrar todo; 0 = sin incidencias en el periodo elegido.'
            : 'Usá el selector sobre el mapa o elegí una categoría acá. «Todas» muestra todo; 0 = sin incidencias en el periodo elegido.';
        syncCategoryToggle();
        syncAdvancedFilterIndicator();
        loadIncidents();
    });
    let infrastructureFilterDebounce;
    elements.infrastructureFilter.addEventListener('change', () => {
        window.clearTimeout(infrastructureFilterDebounce);
        if (!syncInfrastructureSelection().length) return;
        infrastructureFilterDebounce = window.setTimeout(loadInfrastructureLayers, 180);
    });
    elements.monitoringFilter.addEventListener('change', loadWeatherStations);
    elements.roadFilter.addEventListener('change', loadCartographyLayers);
    function selectPeriod(event) {
        const button = event.target.closest('[data-period]');
        if (!button) return;
        state.period = button.dataset.period;
        const buttons = [...elements.periodFilter.querySelectorAll('button'),
            ...(elements.precisePeriodFilter?.querySelectorAll('button') || [])];
        buttons.forEach(item => {
            const selected = item === button;
            item.classList.toggle('active', selected);
            item.setAttribute('aria-pressed', String(selected));
        });
        if (elements.precisePeriodStatus) {
            elements.precisePeriodStatus.hidden = !['1h', '6h'].includes(state.period);
            elements.precisePeriodStatus.textContent = state.period === '1h' ? 'Última hora' : 'Últimas 6 horas';
        }
        syncAdvancedFilterIndicator();
        loadIncidents();
    }
    elements.periodFilter.addEventListener('click', selectPeriod);
    elements.precisePeriodFilter?.addEventListener('click', selectPeriod);

    function showAllCategories() {
        state.allCategories = true;
        const combine = Boolean(elements.combineCategories?.checked);
        elements.categoryFilter.querySelectorAll('input:not(:disabled)').forEach(input => { input.checked = combine; });
        syncCategoryToggle();
        loadIncidents();
    }

    elements.toggleCategories.addEventListener('click', showAllCategories);
    elements.quickCategories?.addEventListener('click', event => {
        const button = event.target.closest('[data-quick-category]');
        if (!button) return;

        if (button.dataset.quickCategory === 'all') {
            return;
        }

        const inputs = [...elements.categoryFilter.querySelectorAll('input:not(:disabled)')];
        const healthCategories = new Set(['health_public', 'health_vector', 'animal_health']);

        if (button.dataset.quickCategory === 'health') {
            const availableHealth = inputs.filter(input => healthCategories.has(input.value));
            if (!availableHealth.length) return;

            if (elements.combineCategories && !elements.combineCategories.checked) {
                elements.combineCategories.checked = true;
                inputs.forEach(input => { input.type = 'checkbox'; });
                elements.categoryFilter.setAttribute('role', 'group');
                elements.categorySelectionHelp.textContent = 'Elegí una o varias categorías. «Todas» vuelve a mostrar todo; 0 = sin incidencias en el periodo elegido.';
            }
            inputs.forEach(input => { input.checked = healthCategories.has(input.value); });
            state.allCategories = false;
            syncCategoryToggle();
            syncAdvancedFilterIndicator();
            loadIncidents();
            return;
        }

        const selected = inputs.find(input => input.value === button.dataset.quickCategory);
        if (!selected) return;

        if (elements.combineCategories?.checked) {
            elements.combineCategories.checked = false;
            inputs.forEach(input => { input.type = 'radio'; });
            elements.categoryFilter.setAttribute('role', 'radiogroup');
        }
        inputs.forEach(input => { input.checked = input === selected; });
        state.allCategories = false;
        syncCategoryToggle();
        syncAdvancedFilterIndicator();
        loadIncidents();
    });

    elements.layersToggle?.addEventListener('click', () => {
        const collapsed = app.classList.toggle('filters-collapsed');
        elements.filters.classList.toggle('is-desktop-collapsed', collapsed);
        syncPanelAccessibility();
        window.setTimeout(() => map.invalidateSize({ animate: false }), 190);
    });

    elements.manualRefresh.addEventListener('click', () => {
        loadIncidents();
    });
    elements.detailClose.addEventListener('click', () => closeIncidentDetail({ restoreFocus: true }));
    elements.filtersClose?.addEventListener('click', () => closeFiltersPanel({ restoreFocus: true }));

    function applyRadiusCenter(latitude, longitude, origin) {
        state.geolocationRequest++;
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)
            || latitude < -27.7 || latitude > -19.1 || longitude < -62.7 || longitude > -54.2) {
            elements.radiusStatus.textContent = 'Elegí un punto dentro de la cobertura de Paraguay.';
            return false;
        }
        state.nearLatitude = latitude;
        state.nearLongitude = longitude;
        state.nearOrigin = origin;
        state.radiusKm = Number(elements.nearRadius.value) || 5;
        elements.nearRadius.disabled = false;
        elements.clearRadius.hidden = false;
        elements.radiusStatus.textContent = `Radio de ${state.radiusKm} km desde ${origin === 'location' ? 'tu ubicación' : 'el centro elegido'}.`;
        syncAdvancedFilterIndicator();
        if (state.locationCircle) map.removeLayer(state.locationCircle);
        state.locationCircle = L.circle([latitude, longitude], {
            radius: state.radiusKm * 1000,
            color: '#123d39',
            fillColor: '#cce76d',
            fillOpacity: .18,
            interactive: false,
        }).addTo(map);
        map.fitBounds(state.locationCircle.getBounds(), { padding: [20, 20], maxZoom: 14 });
        loadIncidents();
        loadZoneNow();
        return true;
    }

    function clearRadiusFilter() {
        state.geolocationRequest++;
        state.nearLatitude = null;
        state.nearLongitude = null;
        state.nearOrigin = null;
        state.zoneAbortController?.abort();
        state.zoneAbortController = null;
        if (state.locationCircle) map.removeLayer(state.locationCircle);
        state.locationCircle = null;
        elements.zoneNow.hidden = true;
        elements.nearRadius.disabled = true;
        elements.clearRadius.hidden = true;
        elements.radiusStatus.textContent = 'Sin filtro por radio. Elegí un centro para activarlo.';
        syncAdvancedFilterIndicator();
        loadIncidents();
    }

    function requestNearMe() {
        if (!navigator.geolocation) {
            elements.radiusStatus.textContent = 'Tu navegador no ofrece ubicación. Podés usar el centro del mapa.';
            showStatus('Tu navegador no ofrece geolocalización.', true);
            return;
        }

        const requestId = ++state.geolocationRequest;
        elements.radiusStatus.textContent = 'Esperando permiso de ubicación. También podés usar el centro del mapa.';
        showStatus('Solicitando tu ubicación…');
        navigator.geolocation.getCurrentPosition(position => {
            if (requestId !== state.geolocationRequest) return;
            elements.status.hidden = true;
            applyRadiusCenter(position.coords.latitude, position.coords.longitude, 'location');
        }, () => {
            if (requestId !== state.geolocationRequest) return;
            elements.radiusStatus.textContent = 'No se obtuvo tu ubicación. Podés usar el centro del mapa.';
            showStatus('No pudimos acceder a tu ubicación. Revisá el permiso del navegador.', true);
        }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 });
    }

    elements.nearMe.addEventListener('click', requestNearMe);
    elements.nearMeFilter.addEventListener('click', requestNearMe);
    elements.nearMapCenter.addEventListener('click', () => {
        const center = map.getCenter();
        applyRadiusCenter(center.lat, center.lng, 'map');
    });
    elements.clearRadius.addEventListener('click', clearRadiusFilter);

    elements.sourceFilter.addEventListener('change', event => {
        state.source = event.target.value;
        syncAdvancedFilterIndicator();
        loadIncidents();
    });

    elements.nearRadius.addEventListener('change', event => {
        if (Number.isFinite(state.nearLatitude) && Number.isFinite(state.nearLongitude)) {
            applyRadiusCenter(state.nearLatitude, state.nearLongitude, state.nearOrigin);
        }
    });

    elements.mobileFilterToggle.addEventListener('click', () => {
        const open = elements.filters.classList.toggle('is-open');
        if (open) {
            closeIncidentDetail();
        }
        syncPanelAccessibility();
        focusPanelControl(open ? elements.filtersClose || elements.toggleCategories : elements.mobileFilterToggle);
    });

    function openReportDialog() {
        hideReportError();
        elements.reportDialog.showModal();
    }

    function closeReportDialog() {
        elements.reportDialog.close();
        resetReportForm();
    }

    function beginReportLocationSelection() {
        elements.reportDialog.close();
        closeFloatingPanels();
        state.reportSelectingLocation = true;
        elements.mapShell.classList.add('is-report-picking');
        elements.status.classList.remove('is-error');
        elements.status.hidden = false;
        elements.status.querySelector('span:last-child').textContent = 'Hacé clic en el mapa para marcar la ubicación. Presioná Esc para cancelar.';
        map.getContainer().focus();
    }

    function finishReportLocationSelection(latitude, longitude) {
        state.reportSelectingLocation = false;
        elements.mapShell.classList.remove('is-report-picking');
        elements.status.hidden = true;
        elements.reportLatitude.value = latitude.toFixed(7);
        elements.reportLongitude.value = longitude.toFixed(7);
        elements.reportLocationCard.classList.add('has-location');
        elements.reportLocationStatus.textContent = 'Ubicación marcada';
        elements.reportCoordinates.textContent = `${latitude.toFixed(5)}, ${longitude.toFixed(5)}`;

        if (state.reportLocationMarker !== null) {
            map.removeLayer(state.reportLocationMarker);
        }

        const markerNode = document.createElement('div');
        markerNode.className = 'report-location-draft';
        markerNode.setAttribute('aria-hidden', 'true');
        markerNode.appendChild(visualIcons.node('community'));
        state.reportLocationMarker = L.marker([latitude, longitude], {
            title: 'Ubicación seleccionada para el reporte',
            icon: L.divIcon({
                html: markerNode.outerHTML,
                className: '',
                iconSize: [42, 42],
                iconAnchor: [21, 21],
            }),
        }).addTo(map);

        elements.reportDialog.showModal();
    }

    function cancelReportLocationSelection() {
        if (!state.reportSelectingLocation) return;
        state.reportSelectingLocation = false;
        elements.mapShell.classList.remove('is-report-picking');
        elements.status.hidden = true;
        elements.reportDialog.showModal();
    }

    function clearReportPhoto(clearInput = true) {
        if (state.reportPhotoUrl !== null) {
            URL.revokeObjectURL(state.reportPhotoUrl);
            state.reportPhotoUrl = null;
        }
        if (clearInput) elements.reportPhoto.value = '';
        elements.reportPhotoImage.removeAttribute('src');
        elements.reportPhotoName.textContent = '';
        elements.reportPhotoPreview.hidden = true;
    }

    function resetReportForm() {
        state.reportSelectingLocation = false;
        elements.mapShell.classList.remove('is-report-picking');
        elements.reportForm.reset();
        elements.reportLatitude.value = '';
        elements.reportLongitude.value = '';
        elements.reportLocationCard.classList.remove('has-location');
        elements.reportLocationStatus.textContent = 'Falta marcar la ubicación';
        elements.reportCoordinates.textContent = 'Elegí el punto exacto donde ocurre el incidente.';
        clearReportPhoto();
        hideReportError();
        if (state.reportLocationMarker !== null) {
            map.removeLayer(state.reportLocationMarker);
            state.reportLocationMarker = null;
        }
    }

    function showReportError(message) {
        elements.reportError.textContent = message;
        elements.reportError.hidden = false;
    }

    function hideReportError() {
        elements.reportError.textContent = '';
        elements.reportError.hidden = true;
    }

    function reportErrorMessage(payload, fallback) {
        const validationMessages = Object.values(payload?.errors || {}).flat();
        return validationMessages[0] || payload?.message || fallback;
    }

    map.on('click', event => {
        if (state.reportSelectingLocation) {
            finishReportLocationSelection(event.latlng.lat, event.latlng.lng);
        }
    });

    elements.reportOpenButtons.forEach(button => button.addEventListener('click', openReportDialog));
    elements.reportDialogClose.addEventListener('click', closeReportDialog);
    elements.reportDialogCancel.addEventListener('click', closeReportDialog);
    elements.reportPickLocation.addEventListener('click', beginReportLocationSelection);
    elements.reportPhotoRemove.addEventListener('click', clearReportPhoto);
    elements.reportDialog.addEventListener('click', event => {
        if (event.target === elements.reportDialog) closeReportDialog();
    });
    elements.reportDialog.addEventListener('cancel', resetReportForm);
    elements.reportPhoto.addEventListener('change', () => {
        const file = elements.reportPhoto.files?.[0];
        clearReportPhoto(false);
        if (!file) return;

        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
            clearReportPhoto();
            showReportError('La foto debe ser JPG, PNG o WEBP y no superar los 5 MB.');
            return;
        }

        state.reportPhotoUrl = URL.createObjectURL(file);
        elements.reportPhotoImage.src = state.reportPhotoUrl;
        elements.reportPhotoName.textContent = file.name;
        elements.reportPhotoPreview.hidden = false;
        hideReportError();
    });

    elements.reportForm.addEventListener('submit', async event => {
        event.preventDefault();
        hideReportError();

        if (!elements.reportLatitude.value || !elements.reportLongitude.value) {
            showReportError('Marcá primero la ubicación exacta en el mapa.');
            return;
        }

        if (!elements.reportForm.reportValidity()) return;

        elements.reportSubmit.disabled = true;
        elements.reportSubmit.textContent = 'Publicando…';

        try {
            const response = await fetch(app.dataset.reportUrl, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new FormData(elements.reportForm),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(reportErrorMessage(payload, 'No fue posible publicar el incidente.'));
            }

            const incident = payload.data;
            const latitude = Number(incident.location.latitude);
            const longitude = Number(incident.location.longitude);
            elements.reportDialog.close();
            resetReportForm();
            state.city = '';
            if (Number.isFinite(latitude) && Number.isFinite(longitude)) {
                map.setView([latitude, longitude], 14);
            }
            await loadIncidents();
            await loadIncidentDetail(incident.id);
        } catch (error) {
            showReportError(error.message || 'No fue posible publicar el incidente.');
        } finally {
            elements.reportSubmit.disabled = false;
            elements.reportSubmit.replaceChildren(
                visualIcons.node('report'),
                document.createTextNode('Publicar incidente'),
            );
        }
    });

    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        if (state.reportSelectingLocation) {
            event.preventDefault();
            cancelReportLocationSelection();
        } else if (elements.reportDialog.open || elements.aboutDialog.open) {
            return;
        } else if (!elements.detail.hidden) {
            event.preventDefault();
            closeIncidentDetail({ restoreFocus: true });
        } else if (mobileFiltersMedia.matches && elements.filters.classList.contains('is-open')) {
            event.preventDefault();
            closeFiltersPanel({ restoreFocus: true });
        }
    });

    const closeAboutDialog = () => elements.aboutDialog.close();
    elements.aboutButton.addEventListener('click', () => elements.aboutDialog.showModal());
    elements.aboutDialogClose.addEventListener('click', closeAboutDialog);
    elements.aboutDialogAccept.addEventListener('click', closeAboutDialog);
    elements.aboutDialog.addEventListener('click', event => {
        if (event.target === elements.aboutDialog) closeAboutDialog();
    });

    function pageIsActive() {
        return !document.hidden && document.hasFocus();
    }

    let pageActivationDebounce;
    function activatePageData() {
        if (!pageIsActive()) return;
        window.clearTimeout(pageActivationDebounce);
        pageActivationDebounce = window.setTimeout(() => {
            loadIncidents({ silent: true });
            loadWeatherStations();
        }, 120);
    }

    document.addEventListener('visibilitychange', activatePageData);
    window.addEventListener('focus', activatePageData);

    window.setInterval(() => {
        if (pageIsActive()) {
            if (!state.loading) loadIncidents({ silent: true });
        }
    }, 60000);

    window.setInterval(updateRelativeRefreshLabels, 30000);

    if (pageIsActive()) {
        loadIncidents();
        loadWeatherStations();
        window.setTimeout(loadCartographyLayers, 180);
    }
})();

