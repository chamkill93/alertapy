const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../public/js/alertapy-map.js'), 'utf8');

function sourceSection(start, end) {
    const from = source.indexOf(start);
    const to = source.indexOf(end, from + start.length);
    assert.ok(from >= 0 && to > from, `Map script section exists: ${start}`);
    return source.slice(from, to);
}

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((done, fail) => { resolve = done; reject = fail; });
    return { promise, resolve, reject };
}

function eventTarget(extra = {}) {
    const listeners = new Map();
    return {
        ...extra,
        addEventListener(name, callback) { listeners.set(name, callback); },
        dispatch(name, event = {}) { return listeners.get(name)?.(event); },
    };
}

function filter(values) {
    const inputs = values.map(value => ({ value, checked: false, disabled: false }));
    return eventTarget({
        inputs,
        extraInputs: [],
        attrs: {},
        setAttribute(name, value) { this.attrs[name] = value; },
        classList: { toggle() {} },
        querySelectorAll(selector) {
            if (selector === '[data-category-extra="true"]') return this.extraInputs;
            if (selector === 'input:checked') return inputs.filter(input => input.checked);
            if (selector === 'input:not(:disabled)') return inputs.filter(input => !input.disabled);
            return inputs;
        },
        querySelector(selector) { return inputs.find(input => input.value === selector.match(/value="([^"]+)"/)[1]); },
        select(...values) { inputs.forEach(input => { input.checked = values.includes(input.value); }); },
    });
}

function node() {
    return { children: [], outerHTML: '<div></div>', append(...children) { this.children.push(...children); }, appendChild(child) { this.children.push(child); } };
}

function createMapHarness(categories = ['traffic', 'weather', 'works']) {
    const status = () => ({ textContent: '', dataset: {}, attrs: {}, setAttribute(name, value) { this.attrs[name] = value; } });
    const periodButtons = ['now', '24h', '7d', '1h', '6h'].map(period => ({
        ...status(), dataset: { period }, classList: { toggle() {} }, closest() { return this; },
    }));
    const quickButtons = ['all', 'electricity', 'water', 'hydrology'].map(value => eventTarget({
        dataset: { quickCategory: value }, attrs: {},
        setAttribute(name, content) { this.attrs[name] = content; },
        closest() { return this; },
    }));
    const elements = {
        monitoringFilter: filter(['dmh']), monitoringStatus: status(),
        roadFilter: filter(['roads', 'rivers']), roadStatus: status(),
        infrastructureFilter: filter(['health', 'education', 'police']), infrastructureStatus: status(),
        categoryFilter: filter(categories), toggleCategories: quickButtons[0],
        quickCategories: eventTarget({
            buttons: quickButtons,
            querySelectorAll() { return this.buttons; },
        }),
        combineCategories: eventTarget({ checked: false }), categorySelectionHelp: status(),
        categoryMoreStatus: status(),
        periodFilter: { querySelectorAll: () => periodButtons.slice(0, 3) },
        precisePeriodFilter: { querySelectorAll: () => periodButtons.slice(3) },
        precisePeriodStatus: status(),
        advancedFilterIndicator: status(),
    };
    const state = { mapLayerPromises: new Map(), categoriesVisible: true, allCategories: true };
    const activeLayers = new Set();
    const map = {
        hasLayer: layer => activeLayers.has(layer),
        removeLayer: layer => activeLayers.delete(layer),
        getZoom: () => 6,
        getBounds: () => ({ getSouth: () => -26, getWest: () => -59, getNorth: () => -25, getEast: () => -58 }),
        setView() {},
    };
    function layer() {
        return {
            options: {}, markers: [], additions: 0,
            clearLayers() { this.markers = []; },
            addLayers(markers) { this.markers.push(...markers); },
            addTo() { this.additions++; activeLayers.add(this); return this; },
            addData(payload) { this.payload = payload; },
            bringToBack() {},
        };
    }
    const weatherStationsLayer = layer();
    const roadNetworkLayer = layer();
    const riverNetworkLayer = layer();
    const infrastructureLayers = { health: layer(), education: layer(), police: layer() };
    const requests = [];
    const timers = new Map();
    let timerId = 0;
    let incidentLoads = 0;
    const context = {
        elements, state, map, weatherStationsLayer, roadNetworkLayer, riverNetworkLayer, infrastructureLayers,
        app: { dataset: { stationsUrl: '/stations', roadLayerUrl: '/roads', riversLayerUrl: '/rivers', infrastructureUrl: '/infrastructure' } },
        window: {
            location: { origin: 'http://localhost' },
            setTimeout(callback) { const id = ++timerId; timers.set(id, callback); return id; },
            clearTimeout(id) { timers.delete(id); },
        },
        document: { createElement: node, createTextNode: text => ({ textContent: text }) },
        L: { marker: (position, options) => ({ position, options, bindTooltip() {}, on() {} }), divIcon: options => options },
        visualIcons: { node }, infrastructureIconNames: {}, dmhStationIconUrl: '/station.svg', dmhMeasurementIcons: {},
        createTextElement: () => node(),
        loadIncidents: () => { incidentLoads++; },
        fetch(url, options) {
            // Deliberately ignore cancellation: a response/body can already be queued when abort runs.
            const pending = deferred();
            requests.push({
                url: String(url), signal: options?.signal,
                resolve: payload => pending.resolve({ ok: true, json: async () => payload }),
                jsonPending: body => pending.resolve({ ok: true, json: () => body.promise }),
                reject: pending.reject,
            });
            return pending.promise;
        },
    };
    const code = sourceSection('    function selectedCategories()', '    function buildIncidentUrl()')
        + sourceSection('    function syncAdvancedFilterIndicator()', '    function syncCategoryToggle()')
        + sourceSection('    async function loadWeatherStations()', '    function prefetchNationalInfrastructure()')
        + sourceSection('    function syncCategoryToggle()', "    elements.periodFilter.addEventListener(")
        + sourceSection('    function showAllCategories()', '    elements.layersToggle?.addEventListener(')
        + '\nreturn {loadWeatherStations, loadCartographyLayers, loadInfrastructureLayers, syncInfrastructureSelection, syncCategoryToggle, selectedCategories, selectPeriod};';
    const functions = new Function(...Object.keys(context), code)(...Object.values(context));
    return {
        ...context, ...functions, requests, timers, activeLayers, periodButtons,
        get incidentLoads() { return incidentLoads; },
        runTimers() { const pending = [...timers.values()]; timers.clear(); return Promise.all(pending.map(callback => callback())); },
    };
}

module.exports = { createMapHarness, deferred, eventTarget, sourceSection, flush: () => new Promise(resolve => setImmediate(resolve)) };

