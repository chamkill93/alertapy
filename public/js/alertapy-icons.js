(function (global) {
    'use strict';

    const paths = {
        electricity: '<path d="m13.5 2-9 12h6l-1 8 10-13h-6l.5-7Z"/>',
        water: '<path d="M12 2.5c-2 3-7 8-7 12a7 7 0 0 0 14 0c0-4-5-9-7-12Z"/><path d="M8 14.5a4 4 0 0 0 4 4"/>',
        security: '<path d="m12 2 8 3v6c0 5-3 8.5-8 11-5-2.5-8-6-8-11V5l8-3Z"/><path d="m8.5 11.5 2.5 2.5 4.5-5"/>',
        traffic_accident: '<path d="m4 12 2-5h11l2 5M3 12h17v6H3zM5 18v3m13-3v3M6 15h1m9 0h1M20 3l-2 2m3 3h1"/>',
        fire: '<path d="M13 2c1 5-4 6-3 10 2-1 3-2.5 3-4 3 2.5 5 5 5 8a6 6 0 0 1-12 0c0-3 1.5-6 4-8 0 2 1 3 1 3"/><path d="M12 15c-2 2-2 4 0 5 2-1 2-3 0-5Z"/>',
        weather_flood: '<path d="M6.5 15H18a3.5 3.5 0 0 0 .2-7 6 6 0 0 0-11-1A4 4 0 0 0 6.5 15ZM8 18l-1 3m6-3-1 3m6-3-1 3"/>',
        roads: '<path d="m7 3-4 18M17 3l4 18M12 3v3m0 5v3m0 5v2"/>',
        favorite: '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z"/>',
        home: '<path d="m3 11 9-8 9 8v10H3z"/><path d="M9 21v-6h6v6"/>',
        work: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V4h8v3M3 12h18M10 12v2h4v-2"/>',
        family: '<circle cx="8" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M2.5 20c0-4 2-6 5.5-6s5.5 2 5.5 6M14 15c4-.7 7 1.1 7 5"/>',
        borders: '<path d="M4 21v-7h5v7M6.5 14V7M7 8l13-5 1 3L8 11M12 6l1 3m4-5 1 3M12 21h9M18 11v10"/>',
        health_vector: '<ellipse cx="12" cy="14" rx="2" ry="5"/><path d="M12 9V3m-2 8C3 11 2 6 5 6c3 0 4 3 5 5Zm4 0c7 0 8-5 5-5-3 0-4 3-5 5ZM10 14H4m10 0h6M10 17l-4 4m8-4 4 4"/>',
        health_facility: '<rect x="4" y="6" width="16" height="15" rx="2"/><path d="M9 6V3h6v3m-3 4v6m-3-3h6M10 21v-3h4v3"/>',
        education: '<path d="m2 8 10-5 10 5-10 5-10-5Zm4 2v7c4 3 8 3 12 0v-7m4-2v9"/>',
        police_station: '<path d="m12 2 8 4v6c0 5-4 8-8 10-4-2-8-5-8-10V6l8-4Z"/><path d="m12 7 1.3 2.6 2.9.4-2.1 2 .5 2.9-2.6-1.4-2.6 1.4.5-2.9-2.1-2 2.9-.4L12 7Z"/>',
        animal_health: '<ellipse cx="12" cy="15.5" rx="5" ry="4" fill="currentColor" stroke="none"/><circle cx="6" cy="10" r="2" fill="currentColor" stroke="none"/><circle cx="10" cy="7" r="2" fill="currentColor" stroke="none"/><circle cx="14" cy="7" r="2" fill="currentColor" stroke="none"/><circle cx="18" cy="10" r="2" fill="currentColor" stroke="none"/>',
        hydrology: '<path d="M3 8c2-2 4-2 6 0s4 2 6 0 4-2 6 0M3 14c2-2 4-2 6 0s4 2 6 0 4-2 6 0M3 20c2-2 4-2 6 0s4 2 6 0 4-2 6 0"/>',
        air_quality: '<path d="M3 8h10c2.8 0 2.8-4 0-4-1.4 0-2.2.7-2.5 1.5M3 12h15c3.5 0 3.5 5 0 5-1.4 0-2.3-.6-2.8-1.5M3 16h7"/>',
        emergency: '<path d="M3 6h11v13H3V6Zm11 5h4l3 4v4h-7M6 11h5m-2.5-2.5v5M17 4v3m-3-3 1 1m5-1-1 1"/><circle cx="7" cy="19" r="2"/><circle cx="18" cy="19" r="2"/>',
        territorial_news: '<rect x="3" y="5" width="18" height="15" rx="2"/><rect x="6" y="8" width="5" height="5" rx=".5" fill="currentColor" stroke="none"/><path d="M14 8h4M14 11h4M6 16h12"/>',
        report: '<path d="M4 10h4l11-5v14L8 14H4v-4Zm4 4 2 7H6l-2-7M8 10v4M22 10v4"/>',
        official: '<path d="m3 9 9-5 9 5M5 10v8m4-8v8m6-8v8m4-8v8M3 20h18"/><path d="M10 13h4"/>',
        press: '<path d="M4 3h13v16a2 2 0 0 0 4 0V8h-4M4 3v18h15"/><path d="M7 7h7M7 11h7M7 15h3m3 0h1M7 18h7"/>',
        community: '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 20c0-4 2.2-6 6-6s6 2 6 6M14 15c4-.7 7 1.1 7 5"/>',
        sensor: '<circle cx="12" cy="17" r="2" fill="currentColor"/><path d="M12 15V9M8.5 12.5a5 5 0 0 1 7 0M5.5 9.5a9 9 0 0 1 13 0M12 19v3"/>',
        search: '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m15.5 15.5 5 5"/>',
        near: '<path d="m3 11 18-8-8 18-2.5-7.5z" fill="currentColor" stroke="none"/>',
        filters: '<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="8" cy="6" r="2" fill="var(--icon-surface, currentColor)"/><circle cx="16" cy="12" r="2" fill="var(--icon-surface, currentColor)"/><circle cx="10" cy="18" r="2" fill="var(--icon-surface, currentColor)"/>',
        layers: '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/>',
        refresh: '<path d="M20 7V3l-3 3a8 8 0 1 0 2 9M4 17v4l3-3"/>',
        map: '<path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3z"/><path d="M9 3v15m6-12v15"/>',
        list: '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1" fill="currentColor"/><circle cx="3.5" cy="12" r="1" fill="currentColor"/><circle cx="3.5" cy="18" r="1" fill="currentColor"/>',
        share: '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.3 10.8 7.4-4.5m-7.4 6.9 7.4 4.5"/>',
        link: '<path d="m10 13 4-4m-6 7-1 1a4 4 0 0 1-6-6l4-4a4 4 0 0 1 6 0m2 2 1-1a4 4 0 0 1 6 6l-4 4a4 4 0 0 1-6 0"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
        calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18"/>',
        clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v6l4 2"/>',
        image: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8" cy="9" r="1.5"/><path d="m4 18 5-5 3 3 3-4 5 6"/>',
        external: '<path d="M14 3h7v7M21 3l-10 10"/><path d="M18 13v7H4V6h7"/>',
        close: '<path d="m6 6 12 12M18 6 6 18"/>',
        check: '<path d="m5 12 4 4L19 6"/>',
        bell: '<path d="M5 17h14l-2-3v-4a5 5 0 0 0-10 0v4z"/><path d="M10 20h4"/>',
        settings: '<circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1l2-1.5-2-3.4-2.4 1A7 7 0 0 0 15 6l-.3-2.6h-4L10.4 6A7 7 0 0 0 9 7.1l-2.4-1-2 3.4 2 1.5a7 7 0 0 0 0 2l-2 1.5 2 3.4 2.4-1A7 7 0 0 0 10.4 18l.3 2.6h4L15 18a7 7 0 0 0 1.5-1.1l2.4 1 2-3.4-2-1.5c.1-.3.1-.7.1-1Z"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        infinity: '<path d="M8.5 8.5c-3.5-3.5-7.5 3-4 6.5 3 3 5.5-1 7.5-3s4.5-6 7.5-3c3.5 3.5-.5 10-4 6.5L8.5 8.5Z"/>',
        exact: '<circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2" fill="currentColor"/><path d="M12 1v4m0 14v4M1 12h4m14 0h4"/>',
        street: '<path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3z"/><path d="M9 3v15m6-12v15"/>',
        neighborhood: '<path d="M3 21V9h7v12M14 21V4h7v17M6 12h1m-1 4h1m10-8h1m-1 4h1m-1 4h1M2 21h20"/>',
        city: '<path d="m3 11 9-8 9 8v10H3z"/><path d="M9 21v-6h6v6"/>',
        district: '<path d="m12 2 8 4v12l-8 4-8-4V6z"/><path d="m4 8 8 4 8-4m-8 4v10"/>',
        department: '<path d="M12 22s7-6.2 7-13a7 7 0 1 0-14 0c0 6.8 7 13 7 13Z"/><circle cx="12" cy="9" r="2.5"/>',
        approximate: '<circle cx="12" cy="12" r="9" stroke-dasharray="2.5 2.5"/>',
        verified: '<path d="m12 2 3 2 3 .5.5 3 2 3-2 3-.5 3-3 .5-3 2-3-2-3-.5-.5-3-2-3 2-3 .5-3 3-.5 3-2Z"/><path d="m8 11 2.5 2.5 5-5"/>',
        warning: '<path d="m12 3 10 18H2L12 3ZM12 9v5m0 3h.01"/>',
        dashboard: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="11" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="18" width="7" height="3" rx="1"/>',
        connections: '<circle cx="5" cy="12" r="3"/><circle cx="19" cy="5" r="3"/><circle cx="19" cy="19" r="3"/><path d="m8 10.5 8-4M8 13.5l8 4"/>',
        logout: '<path d="M10 4H4v16h6m-1-8h12m-4-4 4 4-4 4"/>',
        arrow_right: '<path d="M4 12h16m-6-6 6 6-6 6"/>',
        arrow_left: '<path d="M20 12H4m6-6-6 6 6 6"/>',
        lock: '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V6a4 4 0 0 1 8 0v4m-4 5v2"/>',
        alert_calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18m-6 4v2m0 2h.01"/>',
    };

    function node(name, className = '') {
        const wrapper = document.createElement('span');
        wrapper.className = ['app-icon', className].filter(Boolean).join(' ');
        wrapper.setAttribute('aria-hidden', 'true');
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.8');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.innerHTML = paths[name] || paths.info;
        wrapper.appendChild(svg);
        return wrapper;
    }

    function hydrate(root = document) {
        root.querySelectorAll('[data-app-icon]').forEach(element => {
            element.classList.add('app-icon');
            element.setAttribute('aria-hidden', 'true');
            element.replaceChildren(node(element.dataset.appIcon).firstChild);
        });
    }

    global.AlertaIcons = Object.freeze({ node, hydrate });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => hydrate(), { once: true });
    } else {
        hydrate();
    }
})(window);
