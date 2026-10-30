#!/usr/bin/env python3
"""Runs the creation form script of the ticket tab in jsdom (needs node, and `npm install jsdom jquery` in tests/ or NODE_PATH).
Usage: python3 tests/ui_fields_test.py <ticket id>   (skipped when node or jsdom is missing)"""
import os, shutil, subprocess, sys, tempfile, urllib.parse
from webclient import *

here = os.path.dirname(os.path.abspath(__file__))
candidates = [p for p in os.environ.get('NODE_PATH', '').split(os.pathsep) if p] + [os.path.join(here, 'node_modules')]
node_path = next((p for p in candidates if os.path.isdir(os.path.join(p, 'jsdom'))), '')
if not shutil.which('node') or not node_path:
    print('  skip - node or jsdom not available (npm install jsdom jquery in tests/)')
    sys.exit(0)

a = Client()
check('login', a.login('glpi', 'glpi'))
tab = urllib.parse.quote('GlpiPlugin\\Azuredevops\\Link$1', safe='')
s, page, _ = a.req(f'/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab={tab}&id={sys.argv[1]}',
                   headers={'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': a.ajax_token() or ''})
check('the tab renders', s == 200 and 'ado_create_' in page, str(s))
with tempfile.NamedTemporaryFile('w', suffix='.html', delete=False) as f:
    f.write(page)
r = subprocess.run(['node', os.path.join(here, 'ui_fields_test.js'), f.name], capture_output=True, text=True, env={**os.environ, 'NODE_PATH': node_path})
print(r.stdout, end='')
check('the form script behaves as expected (jsdom)', r.returncode == 0, r.stderr[:300])
sys.exit(summary())
