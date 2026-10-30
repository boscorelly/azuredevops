#!/usr/bin/env python3
"""HTTP-level test: real GLPI web app (:8080) + mock Azure DevOps (:8098).
Run after integration.php:  python3 tests/http_test.py <ticket id with links>   (integration.php writes /tmp/ado_last_ticket_id)
"""
import os, sys, re, json, subprocess, urllib.parse
from webclient import *

ticket_id = int(sys.argv[1])
TAB = urllib.parse.quote('GlpiPlugin\\Azuredevops\\Link$1', safe='')
P = '/plugins/azuredevops'


def sql(q):
    return subprocess.run(['mysql', '-uroot', 'glpi', '-N', '-e', q], capture_output=True, text=True).stdout.strip()


def new_ticket():
    here = os.path.dirname(os.path.abspath(__file__))
    out = subprocess.run(['php', os.path.join(here, 'make_ticket.php')], capture_output=True, text=True, env={'GLPI_ROOT': '/opt/glpi', 'PATH': '/usr/bin:/bin'})
    return int(out.stdout.strip().splitlines()[-1])


def text(page):
    return re.sub(r'\s+', ' ', re.sub('<[^>]+>', ' ', page))


def either(page, *needles):
    return any(n in page for n in needles)


# clean what a previous (possibly interrupted) run left
sql("delete from glpi_plugin_azuredevops_projects_groups where projects_id in (select id from glpi_plugin_azuredevops_projects where name like 'WebProj%'); delete from glpi_plugin_azuredevops_projects where name like 'WebProj%'")
toto = int(sql("select id from glpi_plugin_azuredevops_projects where name='Toto'"))
tata = int(sql("select id from glpi_plugin_azuredevops_projects where name='Tata'"))
g1 = int(sql("select id from glpi_groups where name='ado_group1'"))
g2 = int(sql("select id from glpi_groups where name='ado_group2'"))
# "tech" (Technician profile) is in group 1 only: Toto and the open project, not Tata
sql(f"delete from glpi_groups_users where users_id=4; insert into glpi_groups_users (users_id,groups_id,is_dynamic,is_manager,is_userdelegate) values (4,{g1},0,0,0)")

print('== administrator (glpi): ticket tab')
a = Client()
check('login', a.login('glpi', 'glpi'))
tok = a.ajax_token()
check('csrf token found in page', bool(tok))
XH = {'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': tok or ''}


def tab(client, tid, token):
    return client.req(f'/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab={TAB}&id={tid}',
                      headers={'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': token or ''})


s, page, _ = tab(a, ticket_id, tok)
check('tab renders', s == 200 and 'ado_cp_' in page, f'{s} {page[:300]}')
check('create form points to the plugin route', f'{P}/Ticket/{ticket_id}/WorkItem"' in page)
check('link form points to the plugin route', f'{P}/Ticket/{ticket_id}/WorkItem/Link"' in page)
opts_create = re.search(r'<select id="ado_cp_\d+".*?</select>', page, re.S).group(0)
check('create form offers the projects of the user (Toto, Tata, Open) with their type', all(n in opts_create for n in ('>Toto<', '>Tata<', '>Open<')) and 'data-type="User Story"' in opts_create and 'data-type="Issue"' in opts_create, opts_create)
check('inactive / empty projects not offered', '>Off<' not in page and '>Ghost<' not in page)
check('existing work items listed with their state', '#100' in page and 'Closed' in page, text(page)[:300])
check('parent chain displayed (Epic > Feature)', 'Epic #500' in page and 'Feature #501' in page)
check('work item type badge and link', 'User Story</span>' in page and 'http://127.0.0.1:8098/acme/_workitems/edit/100' in page)
check('followup choice and requester box', 'name="followup"' in page and 'name="notify_requester"' in page and 'name="parent_id"' in page)
check('select2 finders target the plugin', '/Projects/' in page and 'select2' in page)
check('no secret in the page', 'pat-toto' not in page and 'pat-tata' not in page)

s, page, _ = a.req(f'/front/ticket.form.php?id={ticket_id}')
check('ticket page lists the tab', s == 200 and either(page, 'Azure DevOps work item', 'Éléments de travail Azure DevOps', 'Élément de travail Azure DevOps'), str(s))

print('== administrator: configuration pages')
s, page, _ = a.req(f'{P}/Config')
check('config page renders', s == 200 and 'Toto' in page and 'Tata' in page, f'{s} {text(page)[:200]}')
check('projects link to their edit page', f'{P}/Config/Project/{toto}' in page and f'{P}/Config/Project"' in page)
check('who can use each project is shown', 'ado_group1' in page and 'ado_group2' in page)
check('tokens never sent to the browser', 'pat-toto' not in page and 'pat-tata' not in page)
check('rights per profile on the config page', 'name="profiles[]"' in page and 'name="rights[6][]"' in page)
check('one single closure choice for the requester', all(f'name="requester_close_mode" value="{v}"' in page for v in ('none', 'followup', 'solve')))
s, page, _ = a.req(f'{P}/Config', {'title_template': 'x'})
check('config POST without CSRF token rejected', s in (400, 403), str(s))

s, page, _ = a.req(f'{P}/Config/Project/{toto}')
check('project form renders', s == 200 and 'name="token"' in page and 'name="workitem_type"' in page, f'{s} {text(page)[:200]}')
check('token not sent back, field optional when one is stored', 'pat-toto' not in page and not re.search(r'name="token"[^>]*required', page))
check('work item type chosen from the types read at the last test', re.search(r'<option value="User Story" selected', page) is not None and 'value="Feature"' in page)
check('allowed groups are preselected', re.search(rf'<option value="{g1}" selected>ado_group1', page) is not None)
check('access section', 'name="is_restricted"' in page and 'id="ado_groups"' in page and 'id="ado_users"' in page)
check('delete disabled while tickets are linked', re.search(r'<button[^>]*disabled[^>]*>.*?trash', page, re.S) is not None)

s, page, _ = a.req(f'{P}/Config/Project')
check('new project form: token required, type as free text', s == 200 and re.search(r'name="token"[^>]*required', page) is not None and re.search(r'<input[^>]*name="workitem_type"', page) is not None, str(s))
ctok = form_token(page, f'{P}/Config/Project')
check('csrf token in the form', bool(ctok))

print('== administrator: add a project with its token (save and test)')
before = sql("select count(*) from glpi_plugin_azuredevops_projects")
s, _, h = a.req(f'{P}/Config/Project', {'_glpi_csrf_token': ctok, 'id': '0', 'name': 'WebProj', 'organization': 'https://dev.azure.com/acme/', 'ado_project': 'Tata',
                'token': 'pat-tata', 'is_active': '1', 'is_restricted': '1', 'workitem_type': 'Issue', 'parent_types': 'Epic', 'followup_only_types': 'Epic',
                'done_categories[]': ['Completed'], 'default_tags': 'glpi', 'groups[]': [str(g2)], '_test': '1'})
check('save redirects to the project page', s in (302, 303) and re.search(rf'{P}/Config/Project/\d+', h.get('Location', '')) is not None, f'{s} {h.get("Location")}')
new_id = int(re.search(r'/Config/Project/(\d+)', h.get('Location', '/0')).group(1))
row = sql(f"select organization,ado_project,is_restricted,token from glpi_plugin_azuredevops_projects where id={new_id}").split('\t')
check('project stored: organization normalised, token encrypted', row[0] == 'acme' and row[1] == 'Tata' and row[3] not in ('', 'pat-tata'), str(row))
check('access stored (group 2)', sql(f"select groups_id from glpi_plugin_azuredevops_projects_groups where projects_id={new_id}") == str(g2))
s, page, _ = a.req(f'{P}/Config/Project/{new_id}')
check('connection test message shown', either(page, 'Connection successful', 'Connexion réussie') and 'Bot Tata' in page, text(page)[:300])
check('types now offered as a list', re.search(r'<select id="ado_type"', page) is not None and 'value="Issue" selected' in page)
check('token still hidden', 'pat-tata' not in page)

s, page, _ = a.req(f'{P}/Config/Project', {'name': 'x', 'id': str(new_id)})
check('project POST without CSRF rejected', s in (400, 403), str(s))

print('== administrator: access search and work item search')
s, body, _ = a.req(f'{P}/Config/Search/Users?term=ado_a', headers={'X-Requested-With': 'XMLHttpRequest'})
check('user search', s == 200 and any('Ado_a' in r['text'] for r in json.loads(body)['results']), body[:200])
s, body, _ = a.req(f'{P}/Config/Search/Groups?term=ado_group', headers={'X-Requested-With': 'XMLHttpRequest'})
check('group search', s == 200 and {r['text'] for r in json.loads(body)['results']} >= {'ado_group1', 'ado_group2'}, body[:200])
s, body, _ = a.req(f'{P}/Projects/{toto}/Search?term=alpha&scope=parents', headers={'X-Requested-With': 'XMLHttpRequest'})
check('parent search (Epic / Feature)', s == 200 and [r['id'] for r in json.loads(body)['results']] == [500], body[:200])
s, body, _ = a.req(f'{P}/Projects/{toto}/Search?term=story&scope=parents', headers={'X-Requested-With': 'XMLHttpRequest'})
check('parent search does not return User Stories', s == 200 and json.loads(body)['results'] == [], body[:200])
s, body, _ = a.req(f'{P}/Projects/{toto}/Search?term=story&scope=items', headers={'X-Requested-With': 'XMLHttpRequest'})
check('item search (link existing) does', s == 200 and 700 in [r['id'] for r in json.loads(body)['results']], body[:300])

print('== administrator: create, link and unlink from the ticket tab')
t2 = new_ticket()
s, page, _ = tab(a, t2, tok)
ft = form_token(page, f'{P}/Ticket/{t2}/WorkItem')
check('tab of a fresh ticket has its forms', s == 200 and bool(ft), f'{s}')
s, _, h = a.req(f'{P}/Ticket/{t2}/WorkItem', {'_glpi_csrf_token': ft, 'project_id': str(toto), 'parent_id': '500', 'followup': 'private', 'tags': 'web', 'notify_requester': '0'})
check('create redirects back to the ticket tab', s in (302, 303) and f'/front/ticket.form.php?id={t2}' in h.get('Location', ''), f'{s} {h}')
lk = sql(f"select workitem_id,parent_id,projects_id from glpi_plugin_azuredevops_links where tickets_id={t2}").split('\t')
check('work item created under Epic 500 in Toto', len(lk) == 3 and lk[1] == '500' and lk[2] == str(toto), str(lk))
wi = mock('/_mock/dump')['items'][lk[0]]
check('Azure DevOps got title, tag and parent', wi['fields']['System.Parent'] == 500 and 'web' in wi['fields']['System.Tags'] and wi['fields']['System.TeamProject'] == 'Toto')
s, page, _ = a.req(f'/front/ticket.form.php?id={t2}')
check('success message with a link to the work item', either(page, 'work item created', 'Élément de travail Azure DevOps créé', 'Azure DevOps work item created') and f'_workitems/edit/{lk[0]}' in page, text(page)[:300])
s, page, _ = tab(a, t2, tok)
fl = form_token(page, f'{P}/Ticket/{t2}/WorkItem/Link')
s, _, h = a.req(f'{P}/Ticket/{t2}/WorkItem/Link', {'_glpi_csrf_token': fl, 'project_id': str(toto), 'workitem_id': '700', 'followup': 'private'})
check('link an existing work item', s in (302, 303) and sql(f"select workitem_type from glpi_plugin_azuredevops_links where tickets_id={t2} and workitem_id=700") == 'User Story')
s, _, h = a.req(f'{P}/Ticket/{t2}/WorkItem', {'workitem': 'x'})
check('create without CSRF token rejected', s in (400, 403), str(s))

print('== technician (group 1 only): access is limited to the allowed projects')
t = Client()
check('login tech', t.login('tech', 'tech'))
ttok = t.ajax_token()
s, page, _ = tab(t, ticket_id, ttok)
check('tab renders for the technician', s == 200 and 'ado_cp_' in page, f'{s} {page[:200]}')
opts = re.search(r'<select id="ado_cp_\d+".*?</select>', page, re.S).group(0)
check('only Toto and Open are offered (not Tata)', '>Toto<' in opts and '>Open<' in opts and '>Tata<' not in opts, opts)
check('links of Tata are not shown, the number of hidden ones is', 'Tata' not in re.sub(r'<script.*?</script>', '', page, flags=re.S) and either(page, 'cannot use', 'ne pouvez pas utiliser'), text(page)[:400])
check('Toto links are shown', '#100' in page)
s, body, _ = t.req(f'{P}/Projects/{toto}/Search?term=alpha&scope=parents', headers={'X-Requested-With': 'XMLHttpRequest'})
check('search allowed on Toto', s == 200 and [r['id'] for r in json.loads(body)['results']] == [500], f'{s} {body[:100]}')
s, body, _ = t.req(f'{P}/Projects/{tata}/Search?term=&scope=parents', headers={'X-Requested-With': 'XMLHttpRequest'})
check('search refused on Tata', s == 403, str(s))
s, body, _ = t.req(f'{P}/Config')
check('configuration refused (no Setup right)', s in (302, 403), str(s))
s, body, _ = t.req(f'{P}/Config/Search/Users?term=a', headers={'X-Requested-With': 'XMLHttpRequest'})
check('access search refused too', s in (302, 403), str(s))

# forged create on Tata
t3 = new_ticket()
s, page, _ = tab(t, t3, ttok)
ft = form_token(page, f'{P}/Ticket/{t3}/WorkItem')
n_items = len(mock('/_mock/dump')['items'])
s, _, h = t.req(f'{P}/Ticket/{t3}/WorkItem', {'_glpi_csrf_token': ft, 'project_id': str(tata), 'followup': 'none'})
check('forged create on Tata: refused, nothing created', s in (302, 303) and len(mock('/_mock/dump')['items']) == n_items and sql(f"select count(*) from glpi_plugin_azuredevops_links where tickets_id={t3}") == '0')
s, page, _ = t.req(f'/front/ticket.form.php?id={t3}')
check('forged create: error message', either(page, 'not allowed to use this project', 'autorisé à utiliser ce projet'), text(page)[:300])
s, page, _ = tab(t, t3, ttok)
ft = form_token(page, f'{P}/Ticket/{t3}/WorkItem')
s, _, h = t.req(f'{P}/Ticket/{t3}/WorkItem', {'_glpi_csrf_token': ft, 'project_id': str(toto), 'followup': 'none', 'notify_requester': '0'})
check('create on an allowed project works', sql(f"select count(*) from glpi_plugin_azuredevops_links where tickets_id={t3} and projects_id={toto}") == '1')

tata_link = sql(f"select id from glpi_plugin_azuredevops_links where tickets_id={ticket_id} and projects_id={tata} limit 1")


def fresh_token(client):
    """A valid CSRF token for the technician's session (taken from a form of the tab)."""
    s_, pg, _ = tab(client, ticket_id, client.ajax_token())
    return form_token(pg, f'{P}/Ticket/{ticket_id}/WorkItem')


# Prove that the refusal comes from the project access, not from the CSRF check: with Tata opened to everybody the very
# same request (same kind of token) is accepted.
sql(f"update glpi_plugin_azuredevops_projects set is_restricted=0 where id={tata}")
s, _, _ = t.req(f'{P}/Link/{tata_link}/Refresh', {'_glpi_csrf_token': fresh_token(t)})
check('control: the same request is accepted once Tata is open to everybody', s in (302, 303), str(s))
sql(f"update glpi_plugin_azuredevops_projects set is_restricted=1 where id={tata}")
s, _, _ = t.req(f'{P}/Link/{tata_link}/Refresh', {'_glpi_csrf_token': fresh_token(t)})
check('refresh of a Tata link refused (valid token, no access)', s == 403, str(s))
s, _, _ = t.req(f'{P}/Link/{tata_link}/Unlink', {'_glpi_csrf_token': fresh_token(t)})
check('unlink of a Tata link refused too, and the link is still there', s == 403 and sql(f"select count(*) from glpi_plugin_azuredevops_links where id={tata_link}") == '1', str(s))

print('== administrator: unlink and delete')
s, page, _ = tab(a, t2, tok)
m = re.search(rf'action="[^"]*/Link/(\d+)/Unlink"[^>]*>\s*<input type="hidden" name="_glpi_csrf_token" value="([0-9a-f]+)"', page)
check('unlink button present', m is not None)
s, _, _ = a.req(f'{P}/Link/{m.group(1)}/Unlink', {'_glpi_csrf_token': m.group(2)})
check('unlink removes the link only (nothing deleted on Azure DevOps)', s in (302, 303) and sql(f"select count(*) from glpi_plugin_azuredevops_links where id={m.group(1)}") == '0')
s, page, _ = a.req(f'{P}/Config/Project/{new_id}')
dt = form_token(page, f'/Config/Project/{new_id}/Delete')
s, _, _ = a.req(f'{P}/Config/Project/{new_id}/Delete', {'_glpi_csrf_token': dt})
check('unused project deleted from its page', s in (302, 303) and sql(f"select count(*) from glpi_plugin_azuredevops_projects where id={new_id}") == '0', str(s))

print('== languages: the user language if the plugin has it, otherwise English')
s, page, _ = tab(a, ticket_id, tok)
check('administrator (instance language fr_FR): French', 'Créer un élément de travail' in page and 'Lier un élément de travail existant' in page, text(page)[:200])
for lang, expect, label in (('en_GB', 'Create a work item', 'en_GB: English'), ('de_DE', 'Create a work item', 'de_DE (no catalogue): English, not the French of the instance'), ('fr_FR', 'Créer un élément de travail', 'fr_FR: French')):
    sql(f"update glpi_users set language='{lang}' where id=4")
    tl = Client(); tl.login('tech', 'tech')
    s, pg, _ = tab(tl, ticket_id, tl.ajax_token())
    check(label, expect in pg, text(pg)[:160])
sql("update glpi_users set language=NULL where id=4")

sys.exit(summary())
