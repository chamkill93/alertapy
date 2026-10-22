const test = require('node:test');
const assert = require('node:assert/strict');
const { sourceSection, deferred, eventTarget, flush } = require('./map-script-harness.cjs');

function harness() {
    const state = { period: '7d', radiusKm: 5, nearLatitude: null, nearLongitude: null, nearOrigin: null, geolocationRequest: 0 };
    const elements = {
        nearRadius: eventTarget({ value: '5', disabled: true }),
        radiusStatus: { textContent: '' }, clearRadius: eventTarget({ hidden: true }),
        nearMe: eventTarget(), nearMeFilter: eventTarget(), nearMapCenter: eventTarget(),
        zoneNow: { hidden: true }, zoneNowContent: { replaceChildren() {} }, status: { hidden: true },
    };
    const circles = [], requests = [], renders = [], geoCallbacks = [];
    let loads = 0;
    const context = {
        state, elements,
        map: {
            getCenter: () => ({ lat: -25.3, lng: -57.6 }),
            getBounds: () => ({ getSouth: () => -26, getWest: () => -59, getNorth: () => -25, getEast: () => -57 }),
            fitBounds() {}, removeLayer(circle) { circle.removed = true; },
        },
        L: { circle(point, options) {
            const circle = { point, options, addTo() { return this; }, getBounds() { return []; } };
            circles.push(circle); return circle;
        } },
        app: { dataset: { incidentsUrl: '/api/incidents', zoneNowUrl: '/api/nearby/summary' } },
        window: { location: { origin: 'http://localhost' } },
        navigator: { geolocation: { getCurrentPosition(success, failure) { geoCallbacks.push({ success, failure }); } } },
        loadIncidents: () => { loads++; }, selectedCategories: () => ['weather_flood'],
        showStatus() {}, createTextElement: () => ({}), renderZoneNow: data => renders.push(data),
        fetch(url, options) {
            const pending = deferred();
            requests.push({ url: String(url), signal: options.signal, resolve: data => pending.resolve({ ok: true, json: async () => ({ data }) }) });
            return pending.promise;
        },
    };
    const code = sourceSection('    function buildIncidentUrl()', '    async function loadIncidents(')
        + sourceSection('    async function loadZoneNow()', '    function renderZoneNow(')
        + sourceSection('    function applyRadiusCenter(', "    elements.sourceFilter.addEventListener(")
        + sourceSection("    elements.nearRadius.addEventListener(", "    elements.mobileFilterToggle.addEventListener(")
        + '\nreturn {buildIncidentUrl, applyRadiusCenter, clearRadiusFilter, requestNearMe};';
    const functions = new Function(...Object.keys(context), code)(...Object.values(context));
    return { ...context, ...functions, circles, requests, renders, geoCallbacks, get loads() { return loads; } };
}

test('a map center activates radius filtering and clearing restores viewport queries', () => {
    const h = harness();
    h.elements.nearMapCenter.dispatch('click');
    assert.equal(h.elements.nearRadius.disabled, false);
    assert.equal(h.elements.clearRadius.hidden, false);
    assert.match(h.elements.radiusStatus.textContent, /centro elegido/);
    const activeUrl = h.buildIncidentUrl();
    assert.equal(activeUrl.searchParams.get('near_lat'), '-25.3000000');
    assert.equal(activeUrl.searchParams.get('radius_km'), '5');
    assert.equal(activeUrl.searchParams.has('bbox'), false);
    h.clearRadiusFilter();
    assert.equal(h.buildIncidentUrl().searchParams.has('near_lat'), false);
    assert.equal(h.buildIncidentUrl().searchParams.has('bbox'), true);
    assert.equal(h.elements.nearRadius.disabled, true);
    assert.equal(h.elements.clearRadius.hidden, true);
    assert.equal(h.elements.zoneNow.hidden, true);
    assert.equal(h.circles[0].removed, true);
});

test('changing the radius updates the same selected center and the request', () => {
    const h = harness();
    h.applyRadiusCenter(-25.3, -57.6, 'map');
    h.elements.nearRadius.value = '25';
    h.elements.nearRadius.dispatch('change');
    assert.equal(h.buildIncidentUrl().searchParams.get('radius_km'), '25');
    assert.equal(h.state.nearLatitude, -25.3);
    assert.equal(h.circles[1].options.radius, 25000);
    assert.equal(h.circles[0].removed, true);
});

test('centers outside coverage do not send invalid queries or replace the current filter', () => {
    const h = harness();
    assert.equal(h.applyRadiusCenter(-35, -65, 'map'), false);
    assert.equal(h.loads, 0);
    assert.equal(h.requests.length, 0);
    assert.equal(h.state.nearLatitude, null);
    assert.match(h.elements.radiusStatus.textContent, /Paraguay/);
});

test('a location permission result arriving after clearing cannot reactivate the filter', () => {
    const h = harness();
    h.requestNearMe();
    h.clearRadiusFilter();
    h.geoCallbacks[0].success({ coords: { latitude: -25.3, longitude: -57.6 } });
    assert.equal(h.state.nearLatitude, null);
    assert.equal(h.circles.length, 0);
    assert.equal(h.elements.nearRadius.disabled, true);
});

test('a late zone response is ignored after the radius is removed', async () => {
    const h = harness();
    h.applyRadiusCenter(-25.3, -57.6, 'map');
    h.clearRadiusFilter();
    h.requests[0].resolve({ message: 'obsolete' });
    await flush();
    assert.equal(h.requests[0].signal.aborted, true);
    assert.equal(h.renders.length, 0);
    assert.equal(h.elements.zoneNow.hidden, true);
});
