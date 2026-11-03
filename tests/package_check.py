#!/usr/bin/env python3
"""Checks of the package metadata: azuredevops.xml (well-formed, the right tags, the logo), the versions (setup.php,
xml, changelog agree, no duplicate), and the logo file."""
import os, re, struct, sys
import xml.dom.minidom as minidom

root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
ok_count = fail_count = 0
def check(name, cond, detail=''):
    global ok_count, fail_count
    if cond:
        ok_count += 1; print('  ok   - ' + name)
    else:
        fail_count += 1; print('  FAIL - ' + name + ('  [' + str(detail)[:300] + ']' if detail else ''))

read = lambda p: open(os.path.join(root, p), encoding='utf-8').read()
setup = read('setup.php')
version = re.search(r"define\('PLUGIN_AZUREDEVOPS_VERSION', '([^']+)'\)", setup).group(1)

doc = minidom.parseString(read('azuredevops.xml').encode())
top = doc.documentElement
tag = lambda name: [c for c in top.childNodes if c.nodeType == 1 and c.tagName == name]
text = lambda el: ''.join(n.data for n in el.childNodes if n.nodeType in (3, 4)).strip()

check('the plugin name is in <name> (and not in a misspelt tag)', len(tag('name')) == 1 and text(tag('name')[0]) == 'Azure DevOps' and not tag('n'))
check('<key> is the plugin directory', len(tag('key')) == 1 and text(tag('key')[0]) == 'azuredevops')
logo = text(tag('logo')[0]) if tag('logo') else ''
check('<logo> points to logo.png of the repository (raw.githubusercontent.com, like the other plugins)', re.fullmatch(r'https://raw\.githubusercontent\.com/boscorelly/azuredevops/main/logo\.png', logo) is not None, logo)
check('<logo> comes right after <state>, as in the reference plugins', [c.tagName for c in top.childNodes if c.nodeType == 1][2:4] == ['state', 'logo'])
png = os.path.join(root, 'logo.png')   # GLPI serves the logo of a plugin from this exact file name
data = open(png, 'rb').read() if os.path.exists(png) else b''
w, h = struct.unpack('>II', data[16:24]) if data[:8] == b'\x89PNG\r\n\x1a\n' else (0, 0)
check('logo.png (the name GLPI looks for) is a PNG, square, at least 40 px, and light', data[:8] == b'\x89PNG\r\n\x1a\n' and w == h and w >= 40 and len(data) < 100_000, (w, h, len(data)))
for t in ('homepage', 'download', 'issues', 'readme', 'license', 'description', 'authors', 'versions', 'langs', 'tags'):
    check(f'<{t}> is present', len(tag(t)) == 1)

nums = [text(n.getElementsByTagName('num')[0]) for n in tag('versions')[0].getElementsByTagName('version')]
check('no version is listed twice in the xml', len(nums) == len(set(nums)), nums)
check('the newest version of the xml is the one of setup.php', nums[0] == version, (nums[0], version))
urls = [text(n) for n in tag('versions')[0].getElementsByTagName('download_url')]
check('every download_url matches its version', all(f'/download/{n}/glpi-azuredevops-{n}.tar.bz2' in u for n, u in zip(nums, urls)))
changelog = read('CHANGELOG.md')
headers = re.findall(r'^## (\S+)', changelog, re.M)
check('no version is described twice in the changelog', len(headers) == len(set(headers)), headers[:6])
check('the changelog describes the current version first', headers and headers[0] == version, headers[:2])
check('the changelog and the xml list the same versions', headers == nums, (headers, nums))
check('the old file name is gone (GLPI would ignore it)', not os.path.exists(os.path.join(root, 'azuredevops.png')))
export_rules = read('.gitattributes')
check('logo.png is not left out of the release archives', 'logo.png' not in export_rules)
print(f'\n{ok_count} passed, {fail_count} failed')
sys.exit(1 if fail_count else 0)
