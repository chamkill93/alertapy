const test = require('node:test');
const assert = require('node:assert/strict');
const { sourceSection } = require('./map-script-harness.cjs');

function renderImpact(impact) {
    const node = tag => ({ tag, textContent: '', children: [], append(...children) { this.children.push(...children); }, appendChild(child) { this.children.push(child); } });
    const context = {
        document: { createElement: node, createTextNode: text => ({ textContent: text, children: [] }) },
        visualIcons: { node: name => ({ icon: name, textContent: '', children: [] }) },
        createTextElement(tag, text, className) { return { ...node(tag), textContent: String(text ?? ''), className }; },
        safeExternalUrl: url => /^https?:\/\//.test(url || '') ? url : null,
    };
    const code = sourceSection('    function createTerritorialImpact(', '    function createNearbyInfrastructure(')
        + '\nreturn createTerritorialImpact;';
    const render = new Function(...Object.keys(context), code)(...Object.values(context));
    const section = render(impact);
    const descendants = current => [current, ...current.children.flatMap(descendants)];
    return {
        section,
        all: descendants(section),
        text: descendants(section).map(child => child.textContent).filter(Boolean).join(' '),
        heading: section.children[0].textContent,
        population: section.children[1].children[0].children[1].textContent,
        populationLabel: section.children[1].children[0].children[2].textContent,
    };
}

const format = value => new Intl.NumberFormat('es-PY').format(value);
const territory = { district: 'San Lorenzo', department: 'Central' };
const notice = 'Población censal del distrito de referencia; no equivale a personas afectadas.';

test('nearby-only composite context keeps its territory without inventing a population', () => {
    // IncidentImpactService can return nearby infrastructure even without a census profile.
    const result = renderImpact({
        territory: { district: null, department: 'Canindeyú' },
        population_potential: null,
        impact_method: 'UNKNOWN',
        nearby: { infrastructure: { total: 2 }, roads: [], dmh_stations: 0 },
        methodology_notice: 'La estimación utiliza únicamente geometría y evidencia territorial almacenadas.',
    });
    assert.equal(result.heading, 'Contexto territorial');
    assert.equal(result.population, 'Sin dato');
    assert.equal(result.populationLabel, 'población de referencia no disponible');
    assert.match(result.text, /Canindeyú Departamento de referencia/);
    assert.match(result.text, /geometría y evidencia territorial almacenadas/);
    assert.doesNotMatch(result.text, /NaN|undefined|Infinity/);
});

test('a numeric census reference preserves the affected-population distinction and source', () => {
    const result = renderImpact({
        territory, population_reference: 225395, population_potential: null, census_year: 2022,
        disclaimer: notice, source: { name: 'Instituto Nacional de Estadística', url: 'https://www.ine.gov.py/' },
    });
    assert.equal(result.population, format(225395));
    assert.equal(result.heading, 'Contexto territorial');
    assert.match(result.populationLabel, /referencia · no equivale a afectados/);
    assert.match(result.text, /no equivale a personas afectadas/);
    assert.equal(result.all.find(child => child.tag === 'a').href, 'https://www.ine.gov.py/');
});

test('valid potential population takes precedence and omits an unavailable census year', () => {
    const result = renderImpact({ territory, population_potential: '1200', population_reference: 225395, methodology_notice: notice });
    assert.equal(result.population, format(1200));
    assert.equal(result.heading, 'Impacto territorial estimado');
    assert.equal(result.populationLabel, 'población potencial');
    const withYear = renderImpact({ territory, population_potential: 1200, census_year: 2022, methodology_notice: notice });
    assert.equal(withYear.populationLabel, 'población potencial · Censo 2022');
});

test('explicit zero remains valid instead of falling through to a different population', () => {
    const potential = renderImpact({ territory, population_potential: 0, population_reference: 225395, disclaimer: notice });
    assert.equal(potential.population, '0');
    assert.equal(potential.heading, 'Impacto territorial estimado');
    const reference = renderImpact({ territory, population_potential: null, population_reference: 0, disclaimer: notice });
    assert.equal(reference.population, '0');
    assert.equal(reference.heading, 'Contexto territorial');
});

test('null, empty and composite population fields are not coerced into counts', () => {
    for (const value of [null, undefined, '', ' ', false, true, [], [1200], { value: 1200 }, -1, NaN, Infinity, 'no disponible']) {
        const result = renderImpact({ territory, population_potential: value, population_reference: value, disclaimer: notice });
        assert.equal(result.population, 'Sin dato', `invalid count ${JSON.stringify(value)}`);
        assert.equal(result.heading, 'Contexto territorial');
        assert.doesNotMatch(result.text, /NaN|undefined|Infinity|\[object Object\]/);
    }
});

test('an invalid potential field falls back only to a valid reference', () => {
    const result = renderImpact({ territory, population_potential: { total: 1200 }, population_reference: '225395', disclaimer: notice });
    assert.equal(result.population, format(225395));
    assert.equal(result.heading, 'Contexto territorial');
    assert.match(result.populationLabel, /no equivale a afectados/);
});
