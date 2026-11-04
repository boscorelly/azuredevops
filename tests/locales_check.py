#!/usr/bin/env python3
"""Consistency of the language catalogues (run from anywhere): every string is translated in every language, the
placeholders (%s, %1$d, {id}...) are the same in the translation as in the original, the tab is called "Azure DevOps"
everywhere, and the French uses "élément de travail" for "work item"."""
import glob, os, re, sys
here = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(here, '..', 'tools'))
import locales

ok_count = fail_count = 0
def check(name, cond, detail=''):
    global ok_count, fail_count
    if cond:
        ok_count += 1; print('  ok   - ' + name)
    else:
        fail_count += 1; print('  FAIL - ' + name + ('  [' + str(detail)[:300] + ']' if detail else ''))

os.chdir(os.path.join(here, '..'))
source = locales.extract()
keys = {k for k in source}
# An independent scan (not the extractor's): every text written with __() / _n() anywhere in the plugin must be in the catalogues.
found_in_files = set()
for pattern in ('src/**/*.php', 'templates/**/*.twig', 'setup.php', 'hook.php'):
    for f in glob.glob(pattern, recursive=True):
        text_ = open(f, encoding='utf-8').read()
        for m in re.finditer(r"\b_?_n?\(\s*'((?:[^'\\]|\\.)*)'\s*,(?:\s*'((?:[^'\\]|\\.)*)'\s*,\s*[^,)]+,)?\s*'azuredevops'", text_):
            found_in_files.add(m.group(1).replace("\\'", "'").replace('\\\\', '\\'))
check('the scan of the files finds the texts (templates in sub-folders included)', len(found_in_files) > 150 and any(f.startswith('templates/') for f in glob.glob('templates/**/*.twig', recursive=True)), len(found_in_files))
fr_ids = {k[0] for k in locales.read_po('locales/fr_FR.po')}
missing_in_po = sorted(t for t in found_in_files if t not in fr_ids and t.replace('"', '\\"') not in fr_ids)
check('every text used anywhere in the plugin is in the catalogue (nothing dropped as "obsolete")', not missing_in_po, missing_in_po[:3])
PLACEHOLDER = re.compile(r'%(?:\d+\$)?[sd]|\{[a-z_]+\}')

for path in sorted(glob.glob('locales/*.po')):
    lang = os.path.basename(path)[:-3]
    po = locales.read_po(path)
    check(f'{lang}: same strings as the source code', set(po) == keys, (set(po) ^ keys))
    empty = [k for k, v in po.items() if not all(v)]
    check(f'{lang}: nothing left untranslated', not empty, empty[:2])
    bad = []
    for (msgid, plural), forms in po.items():
        for i, form in enumerate(forms):
            original = plural if (plural and i > 0) else msgid
            if sorted(PLACEHOLDER.findall(original)) != sorted(PLACEHOLDER.findall(form)):
                # a plural form may drop the number ("one work item") but never add or change a placeholder
                if not (plural and i == 0 and set(PLACEHOLDER.findall(form)) <= set(PLACEHOLDER.findall(original))):
                    bad.append(msgid[:60])
    check(f'{lang}: placeholders identical to the original', not bad, bad[:3])
    check(f'{lang}: the tab and the plugin are called "Azure DevOps"', po.get(('Azure DevOps', None)) == ['Azure DevOps'], po.get(('Azure DevOps', None)))
    check(f'{lang}: no heading still called "work items"', ('Azure DevOps work items', None) not in po and ('Azure DevOps work item', 'Azure DevOps work items') not in po)

fr = locales.read_po('locales/fr_FR.po')
wrong = [k[0][:70] for k, v in fr.items() if re.search(r'work items?\b', k[0], re.I) and 'Work Items' not in k[0]
         and (not re.search(r'[ée]l[ée]ments?', ' '.join(v), re.I) or re.search(r'work items?\b', ' '.join(v), re.I))]
check('fr_FR: "work item" is translated (élément), never left in English', not wrong, wrong[:3])
labels = [k[0][:70] for k, v in fr.items() if re.search(r'work items?\b', k[0], re.I) and len(k[0]) < 40 and not re.search(r'[ée]l[ée]ments? de travail', ' '.join(v), re.I)]
check('fr_FR: the short labels say "élément de travail"', not labels, labels[:3])
en = locales.read_po('locales/en_GB.po')
check('en_GB: the English texts are the source texts', all(v[0] == k[0] for k, v in en.items() if k[1] is None), [k[0][:50] for k, v in en.items() if k[1] is None and v[0] != k[0]][:2])
for path in glob.glob('locales/*.po'):
    mo = path[:-3] + '.mo'
    check(os.path.basename(mo) + ' is compiled and up to date', os.path.exists(mo) and os.path.getmtime(mo) >= os.path.getmtime(path) - 5)
print(f'\n{ok_count} passed, {fail_count} failed')
sys.exit(1 if fail_count else 0)
