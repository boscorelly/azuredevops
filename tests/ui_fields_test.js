// Behaviour of the creation form of the ticket tab (required fields of the process), in jsdom.
// Usage: NODE_PATH=<dir with jsdom and jquery> node tests/ui_fields_test.js <html of the tab>  (see ui_fields_test.py)
const fs = require('fs');
const { JSDOM } = require('jsdom');
const html = fs.readFileSync(process.argv[2], 'utf8');
const options = [...html.matchAll(/<option value="(\d+)" data-type="([^"]*)" data-parents="(\d)">([^<]*)<\/option>/g)].map(m => ({id: m[1], name: m[4]}));
const byName = Object.fromEntries(options.map(o => [o.name, o.id]));
const data = {};
data[byName['Tata']] = [
  {ref: 'Microsoft.VSTS.Common.Priority', name: 'Priorité', allowed: ['1','2','3','4'], value: '2'},
  {ref: 'Custom.Note', name: '<img src=x onerror=alert(1)>Note', allowed: [], value: 'abc "q"'},
];
data[byName['Toto']] = [];
let page = html.replace(/data-fields="[^"]*"/, 'data-fields="' + JSON.stringify(data).replace(/&/g,'&amp;').replace(/"/g,'&quot;') + '"');
const dom = new JSDOM('<body>' + page + '</body>', {runScripts: 'outside-only', url: 'http://localhost/'});
const w = dom.window;
w.eval(fs.readFileSync(require.resolve('jquery'), 'utf8'));
w.$.fn.select2 = function () { return this; };      // select2 is GLPI's, not under test
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
pick(byName['Tata']);
const prio = box.find('select[name="fields[Microsoft.VSTS.Common.Priority]"]');
t('Tata: a list for the Priority with an empty choice and the 4 allowed values', prio.length === 1 && prio.find('option').length === 5 && prio.find('option').first().val() === '', prio.html());
t('the suggested value is preselected', prio.val() === '2', String(prio.val()));
t('label shows the name of the field', box.find('label[for="' + prio.attr('id') + '"]').text() === 'Priorité');
const note = box.find('input[name="fields[Custom.Note]"]');
t('a field without list is a text box with its value (quotes kept)', note.length === 1 && note.val() === 'abc "q"', note.length + ' ' + note.val());
t('the title of the section is shown', !title.hasClass('d-none'));
t('a field name is displayed as text, never as HTML (no injection)', box.find('img').length === 0 && box.find('label').eq(1).text().includes('<img'), box.html());
pick(byName['Toto']);
t('switching to a project without required field clears them', box.children().length === 0 && title.hasClass('d-none'));
pick(byName['Tata']);
t('back to Tata: they come back, nothing duplicated', box.find('select').length === 1 && box.find('input[type=text]').length === 1);
process.exit(fail ? 1 : 0);
}, 200);
