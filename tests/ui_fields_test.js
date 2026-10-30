// Behaviour of the creation form of the ticket tab (required fields of the process), in jsdom.
// Usage: NODE_PATH=<dir with jsdom and jquery> node tests/ui_fields_test.js <html of the tab>  (see ui_fields_test.py)
const fs = require('fs');
const { JSDOM } = require('jsdom');
const html = fs.readFileSync(process.argv[2], 'utf8');
const options = [...html.matchAll(/<option value="(\d+)" data-parents="(\d)">([^<]*)<\/option>/g)].map(m => ({id: m[1], name: m[3]}));
const byName = Object.fromEntries(options.map(o => [o.name, o.id]));
const data = {};
data[byName['Tata']] = {
  'default': 'Issue', types: ['Issue', 'Bug', 'Task'], default_tags: ['glpi', 'client x'], restrict_tags: true,
  fields: {
    'Issue': [
      {ref: 'Microsoft.VSTS.Common.Priority', name: 'Priorité', allowed: ['1','2','3','4'], value: '2'},
      {ref: 'Custom.Note', name: '<img src=x onerror=alert(1)>Note', allowed: [], value: 'abc "q"'},
    ],
    'Bug': null,    // not read from Azure DevOps yet: the form asks for them when the type is chosen
    'Task': null,
  },
};
data[byName['Toto']] = {'default': 'User Story', types: ['User Story'], fields: {'User Story': []}, default_tags: [], restrict_tags: false};
let page = html.replace(/data-config="[^"]*"/, 'data-config="' + JSON.stringify(data).replace(/&/g,'&amp;').replace(/"/g,'&quot;') + '"');
const dom = new JSDOM('<body>' + page + '</body>', {runScripts: 'outside-only', url: 'http://localhost/'});
const w = dom.window;
w.eval(fs.readFileSync(require.resolve('jquery'), 'utf8'));
w.$.fn.select2 = function () { return this; };      // select2 is GLPI's, not under test
let pending = null;                                   // the request for the fields of a type, answered by the test
w.$.getJSON = function (url, params) {
  const p = pending = {url, params, doneFn: null, failFn: null};
  const api = {done(fn) { p.doneFn = fn; return api; }, fail(fn) { p.failFn = fn; return api; }};
  return api;
};
const scripts = [...dom.window.document.querySelectorAll('script')].map(s => s.textContent);
const mine = scripts.find(s => s.includes('renderFields'));
w.eval(mine);
const $ = w.$;
setTimeout(() => {
let fail = 0;
const t = (name, cond, detail) => { console.log((cond ? '  ok   - ' : '  FAIL - ') + name + (cond ? '' : '  [' + detail + ']')); if (!cond) fail++; };
const box = $('[id^=ado_fields_]:not([id^=ado_fields_title])');
const title = $('[id^=ado_fields_title_]');
const sel = $('select[id^=ado_cp_]');
const pick = (id) => { sel.val(id).trigger('change'); };

t('first project (no required field): nothing shown, title hidden', box.children().length === 0 && title.hasClass('d-none'), box.html());
const hint = $('[id^=ado_tags_hint_]');
const tagsSel = $('select[name="tags[]"]');
t('the tags select box exists in the form', tagsSel.length === 1 && tagsSel.prop('multiple') === true);
t('no default tag: no hint', hint.text() === '', hint.text());
pick(byName['Tata']);
t('the tags always added by the project are shown as a hint', hint.text().includes('glpi') && hint.text().includes('client x'), hint.text());
const prio = box.find('select[name="fields[Microsoft.VSTS.Common.Priority]"]');
t('Tata: a list for the Priority with an empty choice and the 4 allowed values', prio.length === 1 && prio.find('option').length === 5 && prio.find('option').first().val() === '', prio.html());
t('the suggested value is preselected', prio.val() === '2', String(prio.val()));
t('label shows the name of the field', box.find('label[for="' + prio.attr('id') + '"]').text() === 'Priorité');
const note = box.find('input[name="fields[Custom.Note]"]');
t('a field without list is a text box with its value (quotes kept)', note.length === 1 && note.val() === 'abc "q"', note.length + ' ' + note.val());
t('the title of the section is shown', !title.hasClass('d-none'));
t('a field name is displayed as text, never as HTML (no injection)', box.find('img').length === 0 && box.find('label').eq(1).text().includes('<img'), box.html());
const typeSel = $('select[id^=ado_wtype_]');
t('Tata: the types of the project are offered, the default one selected', typeSel.find('option').map((i, o) => o.value).get().join() === 'Issue,Bug,Task' && typeSel.val() === 'Issue', typeSel.html());
pending = null;
typeSel.val('Bug').trigger('change');
t('a type whose fields were not read yet: they are asked for (project, type and ticket in the request)', pending !== null && pending.url.endsWith('/Projects/' + byName['Tata'] + '/TypeFields') && pending.params.type === 'Bug' && Number(pending.params.ticket) > 0, JSON.stringify(pending));
t('a message says they are being read, and the fields of the previous type are gone', box.text().length > 0 && box.find('select, input').length === 0, box.html());
pending.doneFn({fields: [{ref: 'Microsoft.VSTS.Common.Severity', name: 'Severity', allowed: ['1 - Critical', '2 - High'], value: '2 - High'}]});
t('the answer shows the fields of that type, with the preselected value', box.find('select[name="fields[Microsoft.VSTS.Common.Severity]"]').length === 1 && box.find('select').val() === '2 - High' && box.find('select[name="fields[Microsoft.VSTS.Common.Priority]"]').length === 0, box.html());
typeSel.val('Issue').trigger('change');
t('back to the first type: its fields are back', box.find('select[name="fields[Microsoft.VSTS.Common.Priority]"]').length === 1);
pending = null;
typeSel.val('Bug').trigger('change');
t('the fields already read are kept: no second request', pending === null && box.find('select[name="fields[Microsoft.VSTS.Common.Severity]"]').length === 1);
typeSel.val('Task').trigger('change');
pending.failFn({responseJSON: {error: 'Azure DevOps said <b>boom</b>'}});
t('an error is shown as text (never as HTML), and nothing is offered', box.text().includes('boom') && box.text().includes('<b>') && box.find('b').length === 0 && box.find('select, input').length === 0, box.html());
pending = null;
typeSel.val('Task').trigger('change');
t('choosing it again asks again (the failed answer was not kept)', pending !== null);
typeSel.val('Issue').trigger('change');
pick(byName['Toto']);
t('switching project updates the tags hint', hint.text() === '', hint.text());
t('switching project resets the type list to that project', typeSel.find('option').map((i, o) => o.value).get().join() === 'User Story' && typeSel.val() === 'User Story');
t('switching to a project without required field clears them', box.children().length === 0 && title.hasClass('d-none'));
pick(byName['Tata']);
t('back to Tata: they come back, nothing duplicated', box.find('select').length === 1 && box.find('input[type=text]').length === 1);
process.exit(fail ? 1 : 0);
}, 200);
