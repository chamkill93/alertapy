const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const script = fs.readFileSync(path.join(__dirname, '../../public/js/alertapy-map.js'), 'utf8');
const view = fs.readFileSync(path.join(__dirname, '../../resources/views/map.blade.php'), 'utf8');

test('the public map does not request the operational news feed or connection status', () => {
    assert.doesNotMatch(script, /updatesUrl|sourcesStatusUrl|loadLatestUpdates|loadCollectionHealth|\/api\/sources/);
    assert.doesNotMatch(view, /data-updates-url|data-sources-status-url|id="latest-panel"|id="source-health-panel"|data-collection-status/);
});

test('the public map still refreshes incident markers and keeps original incident references', () => {
    assert.match(script, /if \(!state\.loading\) loadIncidents\({ silent: true }\)/);
    assert.match(script, /loadIncidentDetail\(incident\.id\)/);
    assert.match(script, /safeExternalUrl\(source\.original_url\)/);
    assert.match(view, /data-incidents-url=/);
    assert.match(view, /data-incident-base-url=/);
    assert.match(view, /data-report-url=/);
});
