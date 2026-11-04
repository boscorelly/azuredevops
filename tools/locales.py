#!/usr/bin/env python3
"""Maintains the translation catalogues. Run from the plugin root:  python3 tools/locales.py

- extracts every translatable string (__('…', 'azuredevops'), _n('…', '…', …, 'azuredevops')) from the PHP and Twig files;
- writes locales/azuredevops.pot (template for translators);
- writes en_GB.po and en_US.po (English: the source strings, listed explicitly so that English users always get English);
- rewrites the other .po files in the order of the sources, keeping their translations and dropping obsolete entries, and reports the missing translations (fr_FR.po is translated by hand);
- compiles every .po to .mo with msgfmt.
To add a language: copy azuredevops.pot to locales/<xx_XX>.po, translate it, run this script.
"""
import glob, os, re, subprocess, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOMAIN = 'azuredevops'
ENGLISH = ('en_GB', 'en_US')


def unescape_php(s, quote):
    return s.replace('\\' + quote, quote).replace('\\\\', '\\')


def extract():
    files = glob.glob(f'{ROOT}/src/**/*.php', recursive=True) + glob.glob(f'{ROOT}/templates/**/*.twig', recursive=True) + [f'{ROOT}/setup.php', f'{ROOT}/hook.php']
    found = {}  # (msgid, plural) -> first file, in discovery order
    simple = re.compile(r"""__\(\s*(['"])((?:\\.|(?!\1).)*)\1\s*,\s*'%s'\s*\)""" % DOMAIN)
    plural = re.compile(r"""_n\(\s*'((?:\\.|[^'])*)'\s*,\s*'((?:\\.|[^'])*)'\s*,[^;]*?'%s'""" % DOMAIN)
    marker = re.compile(r"""i18n\(\s*'((?:\\.|[^'])*)'\s*\)""")
    for f in sorted(files):
        text = open(f, encoding='utf-8').read()
        rel = os.path.relpath(f, ROOT)
        for m in simple.finditer(text):
            found.setdefault((unescape_php(m.group(2), m.group(1)), None), rel)
        for m in marker.finditer(text):
            found.setdefault((unescape_php(m.group(1), "'"), None), rel)
        for m in plural.finditer(text):
            found.setdefault((unescape_php(m.group(1), "'"), unescape_php(m.group(2), "'")), rel)
    return found


def po_escape(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')


def po_unescape(s):
    return s.replace('\\n', '\n').replace('\\"', '"').replace('\\\\', '\\')


def read_po(path):
    """{(msgid, plural|None): [msgstr, ...]} (single-line entries, which is what this project uses)"""
    entries, cur = {}, {}
    def flush():
        if cur.get('id'):
            entries[(cur['id'], cur.get('plural'))] = cur.get('str', [''])
    for line in open(path, encoding='utf-8').read().splitlines() + ['']:
        m = re.match(r'(msgid|msgid_plural|msgstr|msgstr\[(\d)\]) "(.*)"$', line)
        if not line.strip():
            flush(); cur.clear(); continue
        if not m:
            continue
        key, idx, val = m.group(1), m.group(2), po_unescape(m.group(3))
        if key == 'msgid': cur['id'] = val
        elif key == 'msgid_plural': cur['plural'] = val
        elif key == 'msgstr': cur['str'] = [val]
        else: cur.setdefault('str', []).append(val)
    return entries


def header(lang):
    return ['# Azure DevOps plugin for GLPI', 'msgid ""', 'msgstr ""',
            '"Project-Id-Version: azuredevops\\n"',
            f'"Language: {lang}\\n"' if lang else '"Language: \\n"',
            '"MIME-Version: 1.0\\n"', '"Content-Type: text/plain; charset=UTF-8\\n"', '"Content-Transfer-Encoding: 8bit\\n"',
            '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"' if lang != 'fr_FR' else '"Plural-Forms: nplurals=2; plural=(n > 1);\\n"', '']


def write_po(path, lang, found, translate):
    out = header(lang)
    for (msgid, plural), where in found.items():
        out.append(f'#: {where}')
        out.append(f'msgid "{po_escape(msgid)}"')
        if plural is None:
            out.append(f'msgstr "{po_escape(translate(msgid, None, 0))}"')
        else:
            out.append(f'msgid_plural "{po_escape(plural)}"')
            out.append(f'msgstr[0] "{po_escape(translate(msgid, plural, 0))}"')
            out.append(f'msgstr[1] "{po_escape(translate(msgid, plural, 1))}"')
        out.append('')
    open(path, 'w', encoding='utf-8').write('\n'.join(out))


def main():
    found = extract()
    loc = f'{ROOT}/locales'
    print(f'{len(found)} translatable strings found')
    write_po(f'{loc}/{DOMAIN}.pot', '', found, lambda i, p, n: '')
    for lang in ENGLISH:
        write_po(f'{loc}/{lang}.po', lang, found, lambda i, p, n: i if (p is None or n == 0) else p)
    status = 0
    for po in sorted(glob.glob(f'{loc}/*.po')):
        lang = os.path.basename(po)[:-3]
        if lang in ENGLISH:
            continue
        have = read_po(po)
        missing = [k for k in found if k not in have or not any(have[k])]
        obsolete = [k for k in have if k not in found]
        print(f'{lang}: {len(found) - len(missing)}/{len(found)} translated, {len(obsolete)} obsolete entries dropped')
        for k in missing:
            print(f'   MISSING  {k[0][:90]}')
        status |= 1 if missing else 0
        # Rewrite in the order of the sources: keeps the translations, drops the obsolete entries.
        def tr(msgid, plural, n, have=have):
            strs = have.get((msgid, plural)) or ['']
            return strs[n] if n < len(strs) else ''
        write_po(po, lang, found, tr)
    for po in sorted(glob.glob(f'{loc}/*.po')):
        r = subprocess.run(['msgfmt', '-o', po[:-3] + '.mo', po], capture_output=True, text=True)
        if r.returncode:
            print('msgfmt failed for', po, r.stderr); status |= 2
    return status


if __name__ == '__main__':
    sys.exit(main())
