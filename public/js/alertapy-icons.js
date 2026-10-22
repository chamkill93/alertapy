(function (global) {
    'use strict';

    const paths = {
        electricity: '<path d="M13.2 1.5 4.8 13h6.3l-1 9.5L19.3 10h-6.4z" fill="currentColor" stroke="none"/>',
        water: '<path d="M12 2.2S5.8 9.3 5.8 14.2a6.2 6.2 0 0 0 12.4 0C18.2 9.3 12 2.2 12 2.2Z" fill="currentColor" stroke="none"/><path d="M9 15.2c.4 1.6 1.4 2.5 3 2.8"/>',
        security: '<path d="M12 2.4 20 5v6.1c0 5.1-3.1 8.6-8 10.5-4.9-1.9-8-5.4-8-10.5V5z"/><path d="m12 7 1.2 2.4 2.7.4-2 1.9.5 2.7-2.4-1.3-2.4 1.3.5-2.7-2-1.9 2.7-.4z" fill="currentColor" stroke="none"/>',
        traffic_accident: '<path d="m5 11 1.6-4h10.8l1.6 4"/><path d="M4 11h16v6H4z"/><circle cx="7" cy="14" r="1" fill="currentColor"/><circle cx="17" cy="14" r="1" fill="currentColor"/><path d="M6 17v2m12-2v2"/>',
        fire: '<path d="M13.2 2.2c.7 4.2-2.7 5.2-2.1 8.6 1.5-1 2.3-2.2 2.5-3.7 2.7 2.2 4.4 4.8 4.4 7.6a6 6 0 0 1-12 0c0-2.9 1.8-5.8 5.2-8.6-.1 2.3.5 3.3 1.4 4.1 1.1-2.8-.7-4.7.6-8Z" fill="currentColor" stroke="none"/>',
        weather_flood: '<path d="M7.2 16.2h10.1a3.7 3.7 0 0 0 .3-7.4A5.7 5.7 0 0 0 6.8 7.2a4.5 4.5 0 0 0 .4 9Z" fill="currentColor" stroke="none"/><path d="m8 18-1 2m5-2-1 2m5-2-1 2"/>',
        roads: '<path d="M9 3h6l4 18H5z" fill="currentColor" stroke="none"/><path d="M8 10h8M7 15h10" stroke="white" stroke-width="2"/>',
        favorite: '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z"/>',
        home: '<path d="m3 11 9-8 9 8v10H3z"/><path d="M9 21v-6h6v6"/>',
        work: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V4h8v3M3 12h18M10 12v2h4v-2"/>',
        family: '<circle cx="8" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M2.5 20c0-4 2-6 5.5-6s5.5 2 5.5 6M14 15c4-.7 7 1.1 7 5"/>',
        borders: '<path d="M4 21v-8h13v8M7 13v-3h8v3M17 16h3v5"/><path d="m3 8 14-6 1.5 3.5-14 6z" fill="currentColor" stroke="none"/><path d="m7 6 2 3m3-5 2 3" stroke="white"/>',
        health_vector: '<ellipse cx="12" cy="13" rx="2.8" ry="5" fill="currentColor" stroke="none"/><circle cx="12" cy="6.2" r="1.8" fill="currentColor" stroke="none"/><path d="M9.5 9 6 6m8.5 3L18 6M9 12H4m11 0h5M9.5 15 6 19m8.5-4 3.5 4M12 4V2"/>',
        health_facility: '<path d="m3 10 9-6 9 6M5 9.5V21h14V9.5M3 21h18"/><path d="M10.5 10h3v3h3v3h-3v3h-3v-3h-3v-3h3z" fill="currentColor" stroke="none"/>',
        education: '<path d="M3 21V9l9-5 9 5v12M2 21h20M8 21v-6h8v6"/><circle cx="12" cy="10" r="2"/><path d="M12 8V6M12 10l1.2.8M17 6V2m0 0 4 1.5L17 5"/>',
        police_station: '<path d="M4 13c1-4 4-7 8-8 4 1 7 4 8 8-5 3-11 3-16 0Z" fill="currentColor" stroke="none"/><path d="M6 16c4 2 8 2 12 0M8 19c3 1 5 1 8 0"/><path d="m12 7 .8 1.6 1.8.3-1.3 1.2.3 1.8-1.6-.9-1.6.9.3-1.8-1.3-1.2 1.8-.3z" fill="white" stroke="none"/>',
        animal_health: '<ellipse cx="12" cy="15.5" rx="5" ry="4" fill="currentColor" stroke="none"/><circle cx="6" cy="10" r="2" fill="currentColor" stroke="none"/><circle cx="10" cy="7" r="2" fill="currentColor" stroke="none"/><circle cx="14" cy="7" r="2" fill="currentColor" stroke="none"/><circle cx="18" cy="10" r="2" fill="currentColor" stroke="none"/>',
        hydrology: '<path d="M2 8c2.5-2.2 4.5-2.2 7 0s4.5 2.2 7 0 4.5-2.2 6 0M2 13c2.5-2.2 4.5-2.2 7 0s4.5 2.2 7 0 4.5-2.2 6 0M2 18c2.5-2.2 4.5-2.2 7 0s4.5 2.2 7 0 4.5-2.2 6 0"/>',
        air_quality: '<path d="M3 8h10c2.8 0 2.8-4 0-4-1.4 0-2.2.7-2.5 1.5M3 12h15c3.5 0 3.5 5 0 5-1.4 0-2.3-.6-2.8-1.5M3 16h7"/>',
        emergency: '<path d="M3 10h11l3 3h3v6H3z"/><path d="M14 10V6H7v4M9 7.5h3M10.5 6v3"/><circle cx="7" cy="19" r="2" fill="currentColor"/><circle cx="17" cy="19" r="2" fill="currentColor"/><path d="M16 4v3m-3-2 1.5 1M19 5l-1.5 1"/>',
        territorial_news: '<rect x="3" y="5" width="18" height="15" rx="2"/><rect x="6" y="8" width="5" height="5" rx=".5" fill="currentColor" stroke="none"/><path d="M14 8h4M14 11h4M6 16h12"/>',
        report: '<path d="m4 13 11-5v10L4 13Z" fill="currentColor" stroke="none"/><path d="M15 10c2-1 4-3 4-5v16c0-2-2-4-4-5M6 14l1 6h3l-1-5"/>',
        official: '<path d="m3 9 9-5 9 5M5 10v8m4-8v8m6-8v8m4-8v8M3 20h18"/><path d="M10 13h4"/>',
        press: '<path d="M5 3h12v4h2v14H5z"/><path d="M8 7h6M8 11h7M8 15h7M8 18h5"/>',
        community: '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 20c0-4 2.2-6 6-6s6 2 6 6M14 15c4-.7 7 1.1 7 5"/>',
        sensor: '<circle cx="12" cy="17" r="2" fill="currentColor"/><path d="M12 15V9M8.5 12.5a5 5 0 0 1 7 0M5.5 9.5a9 9 0 0 1 13 0M12 19v3"/>',
        search: '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m15.5 15.5 5 5"/>',
        near: '<path d="m3 11 18-8-8 18-2.5-7.5z" fill="currentColor" stroke="none"/>',
        filters: '<path d="M3 5h18l-7 8v6l-4 2v-8z" fill="currentColor" stroke="none"/>',
        layers: '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/>',
        refresh: '<path d="M20 7V3l-3 3a8 8 0 1 0 2 9M4 17v4l3-3"/>',
        map: '<path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3z"/><path d="M9 3v15m6-12v15"/>',
        list: '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1" fill="currentColor"/><circle cx="3.5" cy="12" r="1" fill="currentColor"/><circle cx="3.5" cy="18" r="1" fill="currentColor"/>',
        share: '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.3 10.8 7.4-4.5m-7.4 6.9 7.4 4.5"/>',
        link: '<path d="M9 15 7 17a4 4 0 1 1-6-6l3-3a4 4 0 0 1 6 0M15 9l2-2a4 4 0 1 1 6 6l-3 3a4 4 0 0 1-6 0"/><path d="m8 12 8 0"/>',
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
        verified: '<circle cx="12" cy="12" r="9" fill="currentColor" stroke="none"/><path d="m8 12 2.5 2.5L16.5 8.5" stroke="white"/>',
        warning: '<path d="m12 3 10 18H2z" fill="currentColor" stroke="none"/><path d="M12 9v5m0 3h.01" stroke="white"/>',
    };

    function node(name, className = '') {
        const wrapper = document.createElement('span');
        wrapper.className = ['app-icon', className].filter(Boolean).join(' ');
        wrapper.setAttribute('aria-hidden', 'true');
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.innerHTML = paths[name] || paths.info;
        wrapper.appendChild(svg);
        return wrapper;
    }

    function hydrate(root = document) {
        root.querySelectorAll('[data-app-icon]').forEach(element => {
            element.replaceChildren(node(element.dataset.appIcon));
        });
    }

    global.AlertaIcons = Object.freeze({ node, hydrate });
})(window);
