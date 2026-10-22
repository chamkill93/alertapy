const test = require('node:test');
const assert = require('node:assert/strict');
const { createMapHarness, deferred, flush } = require('./map-script-harness.cjs');

const station = { kind: 'weather', is_collecting_data: true, name: 'Asunción', latitude: -25.3, longitude: -57.6 };
const facility = kind => ({ kind, name: `${kind} fixture`, latitude: -25.3, longitude: -57.6 });
const payload = (...kinds) => ({ data: kinds.map(facility), meta: {} });
const geoJson = count => ({ features: [], metadata: { source_feature_count: count } });

test('DMH stays off when an already received response body finishes after disabling', async () => {
    const app = createMapHarness();
    app.elements.monitoringFilter.select('dmh');
    const loading = app.loadWeatherStations();
    const body = deferred();
    app.requests[0].jsonPending(body);
    await flush();
    app.elements.monitoringFilter.select();
    await app.elements.monitoringFilter.dispatch('change');
    assert.equal(app.requests[0].signal.aborted, true);
    body.resolve({ data: [station] });
    await loading;
    assert.equal(app.weatherStationsLayer.additions, 0);
    assert.equal(app.weatherStationsLayer.markers.length, 0);
    assert.equal(app.elements.monitoringStatus.dataset.state, 'idle');
});

test('an old DMH failure cannot overwrite a newer successful selection', async () => {
    const app = createMapHarness();
    app.elements.monitoringFilter.select('dmh');
    const old = app.loadWeatherStations();
    const current = app.loadWeatherStations();
    app.requests[1].resolve({ data: [station] });
    await current;
    app.requests[0].reject(new Error('old network failure'));
    await old;
    assert.equal(app.elements.monitoringStatus.textContent, '1 estaciones DMH activas.');
    assert.equal(app.elements.monitoringStatus.dataset.state, 'ready');
    assert.equal(app.weatherStationsLayer.markers.length, 1);
});

test('infrastructure changes remove unchecked layers and abort before debounce runs', async () => {
    const app = createMapHarness();
    app.elements.infrastructureFilter.select('health');
    app.infrastructureLayers.health.addTo(app.map);
    app.infrastructureLayers.health.markers.push({ old: true });
    const old = app.loadInfrastructureLayers();
    app.elements.infrastructureFilter.select('police');
    app.elements.infrastructureFilter.dispatch('change');
    assert.equal(app.requests[0].signal.aborted, true);
    assert.equal(app.map.hasLayer(app.infrastructureLayers.health), false);
    assert.equal(app.infrastructureLayers.health.markers.length, 0);
    assert.equal(app.requests.length, 1, 'the new request is still debounced');
    const current = app.runTimers();
    app.requests[1].resolve(payload('police'));
    await current;
    app.requests[0].resolve(payload('health'));
    await old;
    assert.equal(app.map.hasLayer(app.infrastructureLayers.health), false);
    assert.equal(app.infrastructureLayers.police.markers.length, 1);
    assert.match(app.elements.infrastructureStatus.textContent, /1 de policía/);
});

test('disabling all infrastructure cancels its timer and ignores a late failure', async () => {
    const app = createMapHarness();
    app.elements.infrastructureFilter.select('health');
    const old = app.loadInfrastructureLayers();
    app.elements.infrastructureFilter.select('police');
    app.elements.infrastructureFilter.dispatch('change');
    app.elements.infrastructureFilter.select();
    app.elements.infrastructureFilter.dispatch('change');
    assert.equal(app.timers.size, 0);
    app.requests[0].reject(new Error('late failure'));
    await old;
    assert.equal(app.elements.infrastructureStatus.dataset.state, 'idle');
    assert.equal(app.activeLayers.size, 0);
});

test('a superseded national-cache request cannot fall back using a newer controller', async () => {
    const app = createMapHarness();
    const cache = deferred();
    app.state.nationalInfrastructurePromise = cache.promise;
    app.elements.infrastructureFilter.select('health');
    const old = app.loadInfrastructureLayers();
    const oldSignal = app.state.infrastructureAbortController.signal;
    app.elements.infrastructureFilter.select('police');
    const current = app.loadInfrastructureLayers();
    const currentSignal = app.state.infrastructureAbortController.signal;
    cache.resolve(null);
    await flush();
    assert.equal(oldSignal.aborted, true);
    assert.equal(app.requests.length, 1);
    assert.match(app.requests[0].url, /kinds=police/);
    assert.equal(app.requests[0].signal, currentSignal);
    app.requests[0].resolve(payload('police'));
    await Promise.all([old, current]);
    assert.equal(app.infrastructureLayers.health.additions, 0);
    assert.equal(app.infrastructureLayers.police.markers.length, 1);
});

test('national-cache results do not restore infrastructure after disabling all', async () => {
    const app = createMapHarness();
    const cache = deferred();
    app.state.nationalInfrastructurePromise = cache.promise;
    app.elements.infrastructureFilter.select('health');
    const old = app.loadInfrastructureLayers();
    app.elements.infrastructureFilter.select();
    app.elements.infrastructureFilter.dispatch('change');
    cache.resolve(payload('health'));
    await old;
    assert.equal(app.infrastructureLayers.health.additions, 0);
    assert.equal(app.elements.infrastructureStatus.dataset.state, 'idle');
});

test('late cartography data stays cached without restoring layers or a ready status', async () => {
    const app = createMapHarness();
    app.elements.roadFilter.select('roads', 'rivers');
    const old = app.loadCartographyLayers();
    app.elements.roadFilter.select();
    await app.elements.roadFilter.dispatch('change');
    app.requests[0].resolve(geoJson(22));
    await old;
    assert.equal(app.requests.length, 1, 'obsolete selection does not start its second download');
    assert.equal(app.roadNetworkLayer.options.alertapyLoaded, true);
    assert.equal(app.activeLayers.size, 0);
    assert.equal(app.elements.roadStatus.textContent, 'Capas apagadas.');
    assert.equal(app.elements.roadStatus.dataset.state, 'idle');
    app.elements.roadFilter.select('roads');
    await app.loadCartographyLayers();
    assert.equal(app.requests.length, 1, 're-enabling reuses the cached data');
    assert.equal(app.map.hasLayer(app.roadNetworkLayer), true);
    assert.match(app.elements.roadStatus.textContent, /22 tramos viales/);
});

test('an obsolete cartography failure cannot replace the current river-layer status', async () => {
    const app = createMapHarness();
    app.elements.roadFilter.select('roads');
    const old = app.loadCartographyLayers();
    app.elements.roadFilter.select('rivers');
    const current = app.loadCartographyLayers();
    app.requests[1].resolve(geoJson(7));
    await current;
    app.requests[0].reject(new Error('old download failed'));
    await old;
    assert.equal(app.elements.roadStatus.dataset.state, 'ready');
    assert.match(app.elements.roadStatus.textContent, /7 tramos hidrográficos/);
    assert.equal(app.map.hasLayer(app.roadNetworkLayer), false);
    assert.equal(app.map.hasLayer(app.riverNetworkLayer), true);
});

test('a single category replaces all categories and Todas restores the complete selection', () => {
    const app = createMapHarness();
    assert.deepEqual(app.selectedCategories(), ['traffic', 'weather', 'works']);
    assert.equal(app.elements.toggleCategories.attrs['aria-pressed'], 'true');
    app.elements.categoryFilter.select('traffic');
    app.elements.categoryFilter.dispatch('change');
    assert.deepEqual(app.selectedCategories(), ['traffic']);
    assert.equal(app.elements.toggleCategories.attrs['aria-pressed'], 'false');
    app.elements.categoryFilter.select('weather');
    app.elements.categoryFilter.dispatch('change');
    assert.deepEqual(app.selectedCategories(), ['weather']);
    app.elements.toggleCategories.dispatch('click');
    assert.equal(app.elements.categoryFilter.querySelectorAll('input:checked').length, 0);
    assert.deepEqual(app.selectedCategories(), ['traffic', 'weather', 'works']);
    assert.equal(app.elements.toggleCategories.attrs['aria-pressed'], 'true');
    assert.equal(app.incidentLoads, 3);
});

test('combining categories preserves the initial selection and reports a partial or empty selection accurately', () => {
    const app = createMapHarness();
    app.elements.combineCategories.checked = true;
    app.elements.combineCategories.dispatch('change');
    assert.equal(app.elements.categoryFilter.attrs.role, 'group');
    assert.equal(app.elements.advancedFilterIndicator.textContent, '1 filtro · Combinación');
    assert.ok(app.elements.categoryFilter.inputs.every(input => input.type === 'checkbox' && input.checked));
    app.elements.categoryFilter.select('traffic', 'works');
    app.elements.categoryFilter.dispatch('change');
    assert.deepEqual(app.selectedCategories(), ['traffic', 'works']);
    assert.equal(app.elements.toggleCategories.attrs['aria-pressed'], 'false');
    app.elements.categoryFilter.select();
    app.elements.categoryFilter.dispatch('change');
    assert.deepEqual(app.selectedCategories(), []);
    app.elements.toggleCategories.dispatch('click');
    assert.deepEqual(app.selectedCategories(), ['traffic', 'weather', 'works']);
    assert.ok(app.elements.categoryFilter.inputs.every(input => input.checked));
});

test('returning to one category keeps the first selected category and leaves independent map layers untouched', () => {
    const app = createMapHarness();
    app.elements.roadFilter.select('rivers');
    app.riverNetworkLayer.addTo(app.map);
    app.elements.combineCategories.checked = true;
    app.elements.combineCategories.dispatch('change');
    app.elements.categoryFilter.select('weather', 'works');
    app.elements.categoryFilter.dispatch('change');
    app.elements.combineCategories.checked = false;
    app.elements.combineCategories.dispatch('change');
    assert.deepEqual(app.selectedCategories(), ['weather']);
    assert.equal(app.elements.categoryFilter.attrs.role, 'radiogroup');
    assert.equal(app.elements.advancedFilterIndicator.hidden, true);
    assert.ok(app.elements.categoryFilter.inputs.every(input => input.type === 'radio'));
    assert.equal(app.elements.toggleCategories.attrs['aria-pressed'], 'false');
    assert.equal(app.map.hasLayer(app.riverNetworkLayer), true);
    assert.deepEqual(app.elements.roadFilter.querySelectorAll('input:checked').map(input => input.value), ['rivers']);
});

test('returning to one category from an empty combination restores all categories', () => {
    const app = createMapHarness();
    app.elements.combineCategories.checked = true;
    app.elements.combineCategories.dispatch('change');
    app.elements.categoryFilter.select();
    app.elements.categoryFilter.dispatch('change');
    app.elements.combineCategories.checked = false;
    app.elements.combineCategories.dispatch('change');
    assert.deepEqual(app.selectedCategories(), ['traffic', 'weather', 'works']);
    assert.equal(app.elements.toggleCategories.attrs['aria-pressed'], 'true');
});

test('primary and precise periods share one selection and a collapsed advanced period stays identifiable', () => {
    const app = createMapHarness();
    for (const button of app.periodButtons) {
        app.selectPeriod({ target: button });
        assert.equal(app.state.period, button.dataset.period);
        assert.equal(app.periodButtons.filter(item => item.attrs['aria-pressed'] === 'true').length, 1);
        assert.equal(button.attrs['aria-pressed'], 'true');
        assert.equal(app.elements.precisePeriodStatus.hidden, !['1h', '6h'].includes(button.dataset.period));
    }
    assert.equal(app.elements.precisePeriodStatus.textContent, 'Últimas 6 horas');
    assert.equal(app.elements.advancedFilterIndicator.textContent, '1 filtro · 6 h');
    app.selectPeriod({ target: app.periodButtons[0] });
    assert.equal(app.elements.precisePeriodStatus.hidden, true);
    assert.equal(app.elements.advancedFilterIndicator.hidden, true);
    assert.equal(app.state.period, 'now');
});
