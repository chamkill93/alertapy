const test = require('node:test');
const assert = require('node:assert/strict');
const { sourceSection, eventTarget, deferred, flush } = require('./map-script-harness.cjs');

function panelHarness(initialWidth = 390) {
    let width = initialWidth;
    const document = eventTarget();
    function classList() {
        const values = new Set();
        return {
            contains: value => values.has(value),
            add: value => values.add(value),
            remove: value => values.delete(value),
            toggle(value, force = !values.has(value)) { force ? values.add(value) : values.delete(value); return force; },
        };
    }
    function element(id, parent = null, visible = () => true) {
        return eventTarget({
            id, parent, isConnected: true, hidden: false, inert: false, open: false, attrs: {}, classList: classList(),
            setAttribute(name, value) { this.attrs[name] = value; },
            contains(other) { return other === this || Boolean(other?.parent && this.contains(other.parent)); },
            closest() { return this.hidden || this.inert ? this : this.parent?.closest() || null; },
            getClientRects() { return visible() ? [{}] : []; },
            focus() {
                assert.equal(this.closest(), null, `focus target ${id} is not hidden or inert`);
                assert.ok(this.getClientRects().length, `focus target ${id} is displayed`);
                document.activeElement = this;
            },
            close() { this.open = false; },
            replaceChildren(...children) { this.children = children; },
        });
    }
    document.body = element('body');
    document.activeElement = document.body;
    const app = element('app');
    app.dataset = { incidentBaseUrl: '/incidents' };
    const mapNode = element('map');
    const map = { getContainer: () => mapNode, invalidateSize() {} };
    const elements = {
        filters: element('filters'), detail: element('detail'),
        mobileFilterToggle: element('filters-toggle', null, () => width <= 760),
        layersToggle: element('layers-toggle', null, () => width > 760),
        reportDialog: element('report-dialog'), aboutDialog: element('about-dialog'),
        mapShell: element('map-shell'), status: element('status'), detailContent: element('detail-content'),
    };
    elements.detail.hidden = true;
    elements.filtersClose = element('filters-close', elements.filters, () => width <= 760);
    elements.toggleCategories = element('categories-toggle', elements.filters);
    elements.detailClose = element('detail-close', elements.detail);
    elements.status.querySelector = () => ({ textContent: '' });
    const media = new Map();
    const requests = [];
    const rendered = [];
    const state = { reportSelectingLocation: false };
    const context = {
        document, app, map, elements, state,
        window: {
            matchMedia(query) {
                const limit = Number(query.match(/(\d+)px/)[1]);
                const entry = eventTarget({ matches: width <= limit, limit });
                media.set(query, entry);
                return entry;
            },
            setTimeout: callback => callback(),
        },
        fetch(url, options) {
            const pending = deferred();
            requests.push({ url, signal: options.signal, reject: pending.reject, resolve: data => pending.resolve({ ok: true, json: async () => ({ data }) }) });
            return pending.promise;
        },
        createTextElement: (tag, text) => ({ tag, text }),
        renderIncidentDetail: incident => rendered.push(incident.id),
        cancelReportLocationSelection() { state.reportSelectingLocation = false; },
    };
    const code = sourceSection('    const mobileFiltersMedia =', '    let mapDebounce;')
        + sourceSection("    elements.layersToggle?.addEventListener(", "    elements.manualRefresh.addEventListener(")
        + sourceSection("    elements.detailClose.addEventListener(", '    function applyRadiusCenter(')
        + sourceSection("    elements.mobileFilterToggle.addEventListener(", '    function openReportDialog()')
        + sourceSection('    function beginReportLocationSelection()', '    function finishReportLocationSelection(')
        + sourceSection("    document.addEventListener('keydown'", '    const closeAboutDialog =')
        + sourceSection('    async function loadIncidentDetail(', '    function renderIncidentDetail(')
        + '\nreturn {openIncidentDetailPanel, closeIncidentDetail, beginReportLocationSelection, loadIncidentDetail, syncPanelAccessibility};';
    const functions = new Function(...Object.keys(context), code)(...Object.values(context));
    return {
        ...context, ...functions, mapNode, rendered, requests,
        setWidth(value) {
            width = value;
            const changed = [...media.values()].filter(entry => entry.matches !== (width <= entry.limit));
            media.forEach(entry => { entry.matches = width <= entry.limit; });
            changed.forEach(entry => entry.dispatch('change'));
        },
        escape() {
            let prevented = false;
            document.dispatch('keydown', { key: 'Escape', preventDefault() { prevented = true; } });
            return prevented;
        },
    };
}

test('the mobile filter panel is inert while hidden and focuses its close control when opened', () => {
    const app = panelHarness();
    assert.equal(app.elements.filters.inert, true);
    app.elements.mobileFilterToggle.dispatch('click');
    assert.equal(app.elements.filters.inert, false);
    assert.equal(app.elements.mobileFilterToggle.attrs['aria-expanded'], 'true');
    assert.equal(app.document.activeElement, app.elements.filtersClose);
    app.elements.mobileFilterToggle.dispatch('click');
    assert.equal(app.elements.filters.inert, true);
    assert.equal(app.elements.mobileFilterToggle.attrs['aria-expanded'], 'false');
    assert.equal(app.document.activeElement, app.elements.mobileFilterToggle);
});

test('the filter close button and Escape restore keyboard focus', () => {
    const app = panelHarness();
    app.elements.mobileFilterToggle.dispatch('click');
    app.elements.filtersClose.dispatch('click');
    assert.equal(app.elements.filters.inert, true);
    assert.equal(app.document.activeElement, app.elements.mobileFilterToggle);
    app.elements.mobileFilterToggle.dispatch('click');
    assert.equal(app.escape(), true);
    assert.equal(app.elements.filters.inert, true);
    assert.equal(app.document.activeElement, app.elements.mobileFilterToggle);
});

test('opening detail closes overlays and restores the marker focus when dismissed', () => {
    const app = panelHarness();
    app.elements.mobileFilterToggle.dispatch('click');
    app.mapNode.focus();
    app.openIncidentDetailPanel();
    assert.equal(app.elements.filters.inert, true);
    assert.equal(app.elements.detail.hidden, false);
    assert.equal(app.document.activeElement, app.elements.detailClose);
    app.elements.detailClose.dispatch('click');
    assert.equal(app.elements.detail.hidden, true);
    assert.equal(app.document.activeElement, app.mapNode);
});

test('choosing a report location clears floating panels before returning to the map', () => {
    const app = panelHarness();
    app.elements.filters.classList.add('is-open');
    app.elements.detail.hidden = false;
    app.elements.reportDialog.open = true;
    app.beginReportLocationSelection();
    assert.equal(app.elements.reportDialog.open, false);
    assert.equal(app.state.reportSelectingLocation, true);
    assert.equal(app.elements.filters.inert, true);
    assert.equal(app.elements.detail.hidden, true);
    assert.equal(app.document.activeElement, app.mapNode);
});

test('resize moves focus away from filter controls hidden on desktop', () => {
    const app = panelHarness(1024);
    assert.equal(app.elements.filters.inert, false);
    app.setWidth(390);
    assert.equal(app.elements.filters.inert, true);
    app.elements.mobileFilterToggle.dispatch('click');
    app.setWidth(1440);
    assert.equal(app.elements.filters.inert, false);
    assert.equal(app.document.activeElement, app.elements.toggleCategories);
    assert.equal(app.elements.mobileFilterToggle.attrs['aria-expanded'], 'false');
});

test('desktop collapsed filters stay inert and their mobile visibility is independent', () => {
    const app = panelHarness(1440);
    app.elements.toggleCategories.focus();
    app.elements.layersToggle.dispatch('click');
    assert.equal(app.elements.filters.inert, true);
    assert.equal(app.document.activeElement, app.elements.layersToggle);
    app.setWidth(390);
    app.elements.mobileFilterToggle.dispatch('click');
    assert.equal(app.elements.filters.inert, false);
    app.setWidth(1440);
    assert.equal(app.elements.filters.inert, true);
    assert.equal(app.document.activeElement, app.elements.layersToggle);
});

test('Escape leaves native dialogs to handle their own dismissal', () => {
    const app = panelHarness();
    app.openIncidentDetailPanel();
    app.elements.reportDialog.open = true;
    assert.equal(app.escape(), false);
    assert.equal(app.elements.detail.hidden, false);
});

test('late detail requests cannot replace a newer selection or write after closing', async () => {
    const app = panelHarness();
    const first = app.loadIncidentDetail(1);
    const second = app.loadIncidentDetail(2);
    assert.equal(app.requests[0].signal.aborted, true);
    app.requests[1].resolve({ id: 2 });
    await second;
    app.requests[0].resolve({ id: 1 });
    await first;
    assert.deepEqual(app.rendered, [2]);
    const third = app.loadIncidentDetail(3);
    app.elements.mobileFilterToggle.dispatch('click');
    assert.equal(app.requests[2].signal.aborted, true);
    app.requests[2].reject(new Error('late request failed'));
    await third;
    await flush();
    assert.deepEqual(app.rendered, [2]);
    assert.equal(app.elements.detail.hidden, true);
    assert.equal(app.elements.detailContent.children[0].text, 'Consultando detalle verificable…');
});
