#!/usr/bin/env python3
"""HTTP-level test: real GLPI web app (:8080) + mock Azure DevOps (:8098).
Run after integration.php:  python3 tests/http_test.py <ticket id with links>   (integration.php writes /tmp/ado_last_ticket_id)
"""
import html, os, sys, re, json, subprocess, urllib.parse
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
sql("delete from glpi_plugin_azuredevops_projects_groups where projects_id in (select id from glpi_plugin_azuredevops_projects where name like 'Web%'); delete from glpi_plugin_azuredevops_projects where name like 'Web%'")
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
check('create form offers the projects of the user (Toto, Tata, Open)', all(n in opts_create for n in ('>Toto<', '>Tata<', '>Open<')), opts_create)
check('inactive / empty projects not offered', '>Off<' not in page and '>Ghost<' not in page)
check('existing work items listed with their state', '#100' in page and 'Closed' in page, text(page)[:300])
check('parent chain displayed (Epic > Feature)', 'Epic #500' in page and 'Feature #501' in page)
check('work item type badge and link', 'User Story</span>' in page and 'http://127.0.0.1:8098/acme/_workitems/edit/100' in page)
check('followup choice and requester box', 'name="followup"' in page and 'name="notify_requester"' in page and 'name="parent_id"' in page)
check('select2 finders target the plugin', '/Projects/' in page and 'select2' in page)
check('no secret in the page', 'pat-toto' not in page and 'pat-tata' not in page)

s, page, _ = a.req(f'/front/ticket.form.php?id={ticket_id}')
check('ticket page lists the tab, with the Azure icon', s == 200 and 'ti-brand-azure' in page and '<i class="ti ti-brand-azure me-2"></i>Azure DevOps' in page, str(s))

def ajax_tab(c, token, target, itemtype, tabclass, n, pid):
    """HTML of one tab, loaded the way GLPI's page loads it (ajax)."""
    u = (f'/ajax/common.tabs.php?_target={target}&_itemtype=GlpiPlugin%5CAzuredevops%5C{itemtype}&_glpi_tab=GlpiPlugin%5CAzuredevops%5C{tabclass}%24{n}&id={pid}')
    s_, body, _ = c.req(u, headers={'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': token or ''})
    return s_, body


def config_tab(c, n, token=None):
    return ajax_tab(c, token or tok, '/plugins/azuredevops/Config', 'ConfigPage', 'ConfigTabs', n, 0)


def project_tab(c, pid, n, token=None):
    return ajax_tab(c, token or tok, '/plugins/azuredevops/Config/Project', 'ProjectPage', 'ProjectTabs', n, pid)


def tab_labels(pg, tabclass):
    """[(tab number, label)] of the tabs written in a page."""
    return re.findall(r"data-glpi-ajax-content='[^']*" + tabclass + r"%24(\d)[^']*'[^>]*?aria-label='([^']*)'", pg, re.S)


ZONE_TAB = {'connection': 1, 'work_items': 2, 'fields': 3, 'access': 4}


def post_zone(pid, zone, data):
    """What the form of a tab does: POST its zone (the CSRF token is the one of that tab's form)."""
    s_, body = project_tab(a, pid, ZONE_TAB[zone])
    return a.req(f'{P}/Config/Project', {'_glpi_csrf_token': form_token(body, f'{P}/Config/Project'), 'id': str(pid), '_zone': zone, **data})


def setting(name):
    return sql(f"select value from glpi_configs where context='plugin:azuredevops' and name='{name}'")


print('== administrator: the configuration is a GLPI page with the tabs on the left, one per zone of settings')
s, page, _ = a.req(f'{P}/Config')
s_p, prof, _ = a.req('/front/profile.form.php?id=4')
vertical = "class='d-flex card-tabs flex-column flex-md-row vertical'"
check('same layout as GLPI\'s own administration pages (the profile page): vertical tabs on the left', s == 200 and vertical in page and vertical in prof and "id='tabspanel'" in page and 'flex-md-column' in page, str(s))
labels = dict(tab_labels(page, 'ConfigTabs'))
expected = [('Azure DevOps projects', 'Projets Azure DevOps'), ('Work item creation', "Création des éléments de travail"), ('When a work item is done', 'Quand un élément de travail est terminé'), ('Advanced', 'Avancé'), ('Rights by profile', 'Droits par profil')]
check('the completion tab is called "When a work item is done" / "Quand un élément de travail est terminé", without "on Azure DevOps"', 'sur Azure DevOps' not in labels.get('3', '') and 'on Azure DevOps' not in labels.get('3', '') and labels.get('3') in ('When a work item is done', 'Quand un élément de travail est terminé'), labels.get('3'))
check('one tab per zone of settings: projects, creation, completion, advanced, rights', list(labels) == ['1', '2', '3', '4', '5'] and all(labels[str(i + 1)] in pair for i, pair in enumerate(expected)), str(labels))
check('the old full page is gone: nothing but the tabs on the page itself', 'name="title_template"' not in page and 'name="profiles[]"' not in page)
s1, c1 = config_tab(a, 1); s2, c2 = config_tab(a, 2); s3, c3 = config_tab(a, 3); s4, c4 = config_tab(a, 4); s5, c5 = config_tab(a, 5)
check('every tab loads', (s1, s2, s3, s4, s5) == (200,) * 5 and all(len(c) > 200 for c in (c1, c2, c3, c4, c5)))
check('projects tab: the projects, their edit page and the add button', 'Toto' in c1 and 'Tata' in c1 and f'{P}/Config/Project/{toto}' in c1 and f'{P}/Config/Project"' in c1, text(c1)[:200])
check('projects tab: who can use each project is shown', 'ado_group1' in c1 and 'ado_group2' in c1)
check('tokens never sent to the browser', 'pat-toto' not in c1 and 'pat-tata' not in c1)
check('creation tab: its settings and nothing of the other zones', 'name="title_template"' in c2 and 'name="upload_attachments"' in c2 and 'name="close_followup"' not in c2 and 'name="api_base_url"' not in c2)
check('completion tab: one single choice for the requester', all(f'name="requester_close_mode" value="{v}"' in c3 for v in ('none', 'followup', 'solve')) and 'name="close_followup"' in c3)
check('advanced tab: the addresses', 'name="api_base_url"' in c4 and 'name="entra_authority"' in c4)
check('rights tab: rights per profile', 'name="profiles[]"' in c5 and 'name="rights[6][]"' in c5)
s_, _b, _h = a.req(f'{P}/Config', {'_tab': 'creation', 'title_template': 'x'})
check('config POST without CSRF token rejected', s_ in (400, 403), str(s_))

print('== administrator: rights by profile list every profile')
sql("delete from glpi_profilerights where profiles_id in (select id from glpi_profiles where name like 'Web probe %'); delete from glpi_profiles where name like 'Web probe %'")
sql("insert into glpi_profiles (name, interface, is_default) values ('Web probe standard', 'central', 0), ('Web probe simple', 'helpdesk', 0)")
pidc = int(sql("select id from glpi_profiles where name='Web probe standard'")); pidh = int(sql("select id from glpi_profiles where name='Web probe simple'"))
c5n = config_tab(a, 5)[1]
boxes = lambda pid: re.findall(rf'<input[^>]*name="rights\[{pid}\]\[\]"[^>]*>', c5n, re.S)
check('a profile created after the plugin (standard interface) is in the table, with editable boxes', 'Web probe standard' in c5n and f'name="profiles[]" value="{pidc}"' in c5n and len(boxes(pidc)) == 4 and not any('disabled' in b for b in boxes(pidc)), len(boxes(pidc)))
check('a profile of the simplified interface is in the table too, marked with its interface', 'Web probe simple' in c5n and either(c5n, 'Simplified interface', 'Interface simplifiée'))
check('but with its boxes disabled and not submitted', len(boxes(pidh)) == 4 and all('disabled' in b for b in boxes(pidh)) and f'name="profiles[]" value="{pidh}"' not in c5n)
check('and a note says why', either(html.unescape(c5n), 'simplified interface of a new profile', "interface simplifiée d'un nouveau profil"))
check('the default profiles are all there as before', all(n in c5n for n in ('Super-Admin', 'Technician', 'Supervisor', 'Self-Service')))
vals = re.findall(rf'name="rights\[{pidc}\]\[\]" value="(\d+)"', c5n)
s, _, _ = a.req(f'{P}/Config/Rights', {'_glpi_csrf_token': form_token(c5n, f'{P}/Config/Rights'), 'profiles[]': [str(pidc), str(pidh)], f'rights[{pidc}][]': [vals[0], vals[2]], f'rights[{pidh}][]': vals})
right_of = lambda pid: int(sql(f"select ifnull((select rights from glpi_profilerights where profiles_id={pid} and name='plugin_azuredevops_workitem'), 0)"))
check('rights saved for the new standard profile (read is added), never for the simplified one even when forged', s in (302, 303) and right_of(pidc) == (int(vals[0]) | int(vals[2]) | 1) and right_of(pidh) == 0, f'{s} {right_of(pidc)} {right_of(pidh)}')
c5n = config_tab(a, 5)[1]
check('the saved rights are shown ticked', len([b for b in boxes(pidc) if 'checked' in b]) >= 2)
sql("delete from glpi_profilerights where profiles_id in (select id from glpi_profiles where name like 'Web probe %'); delete from glpi_profiles where name like 'Web probe %'")

print('== administrator: each tab saves its own zone only')
title0 = setting('title_template'); followup0 = setting('close_followup'); base0 = setting('api_base_url')
s_, _b, h = a.req(f'{P}/Config', {'_glpi_csrf_token': form_token(c2, f'{P}/Config'), '_tab': 'creation', 'title_template': 'ZONE {id}', 'max_attachment_mb': '7', 'close_followup': '0', 'api_base_url': 'http://evil.example', 'unknown_setting': 'x'})
check('creation tab: saved, and redirected to the page', s_ in (302, 303) and setting('title_template') == 'ZONE {id}' and setting('max_attachment_mb') == '7', str(s_))
check('what belongs to the other tabs, or to no setting, is ignored', setting('close_followup') == followup0 and setting('api_base_url') == base0 and setting('unknown_setting') == '')
check('the box left unticked counts as unticked, in this tab only', setting('include_people') == '0' and setting('close_followup') == followup0)
s_, _b, _h = a.req(f'{P}/Config', {'_glpi_csrf_token': form_token(config_tab(a, 2)[1], f'{P}/Config'), '_tab': 'creation', 'title_template': 'NEVER', 'max_attachment_mb': 'abc'})
s_, pg_, _ = a.req(f'{P}/Config')
check('an invalid form is refused as a whole, with a message', setting('title_template') == 'ZONE {id}' and either(pg_, 'Invalid form data', 'Données de formulaire invalides'), text(pg_)[:150])
s_, _b, _h = a.req(f'{P}/Config', {'_glpi_csrf_token': form_token(config_tab(a, 4)[1], f'{P}/Config'), '_tab': 'advanced', 'api_base_url': 'ftp://nope', 'entra_authority': 'https://login.microsoftonline.com'})
s_, pg_, _ = a.req(f'{P}/Config')
check('advanced tab: an address that is not http(s) is refused', setting('api_base_url') == base0 and either(pg_, 'must start with http', 'doit commencer par http'), text(pg_)[:150])
s_, _b, _h = a.req(f'{P}/Config', {'_glpi_csrf_token': form_token(config_tab(a, 3)[1], f'{P}/Config'), '_tab': 'closure', 'close_followup': '1', 'requester_close_mode': 'bogus'})
check('completion tab: an unknown choice for the requester refuses the form', setting('close_solve_ticket') in ('0', ''))
s_, _b, _h = a.req(f'{P}/Config', {'_glpi_csrf_token': form_token(config_tab(a, 2)[1], f'{P}/Config'), '_tab': 'creation', 'title_template': title0, 'include_people': '1', 'upload_attachments': '1', 'max_attachment_mb': '10', 'creation_followup': '1', 'creation_followup_private': '1', 'notify_requester_text': ''})
check('creation tab: back to its original values', setting('title_template') == title0 and setting('include_people') == '1')
s_, _b, _h = a.req(f'{P}/Config', {'_glpi_csrf_token': form_token(c4, f'{P}/Config'), '_tab': 'nope', 'title_template': 'x'})
check('an unknown tab saves nothing', setting('title_template') == title0)

print('== administrator: the page of a project has one tab per zone too')
s, page, _ = a.req(f'{P}/Config/Project/{toto}')
plabels = dict(tab_labels(page, 'ProjectTabs'))
pexpected = [('Connection', 'Connexion'), ('Work items', 'Éléments de travail'), ('Fields and tags', 'Champs et tags'), ('Who can use this project', 'Qui peut utiliser ce projet')]
check('a project page: GLPI layout, vertical tabs, four zones', s == 200 and vertical in page and list(plabels) == ['1', '2', '3', '4'] and all(plabels[str(i + 1)] in pair for i, pair in enumerate(pexpected)), str(plabels))
pc1 = project_tab(a, toto, 1)[1]; pc2 = project_tab(a, toto, 2)[1]; pc3 = project_tab(a, toto, 3)[1]; pc4 = project_tab(a, toto, 4)[1]
check('connection tab: name, organization, project, token', all(f'name="{n}"' in pc1 for n in ('name', 'organization', 'ado_project', 'token', 'is_active', 'auth_mode')) and 'name="workitem_type"' not in pc1, text(pc1)[:150])
check('token not sent back, field optional when one is stored', 'pat-toto' not in pc1 and not re.search(r'name="token"[^>]*required', pc1))
check('work items tab: the type chosen from the types read at the last test', 'name="workitem_type"' in pc2 and re.search(r'<option value="User Story" selected', pc2) is not None and 'value="Feature"' in pc2 and 'name="token"' not in pc2)
check('work items tab: the restriction of the types is an explicit option', 'name="restrict_types"' in pc2 and 'name="offered_types[]"' in pc2 and 'id="ado_offered"' in pc2)
check('fields tab: default tags, default fields', 'name="default_tags[]"' in pc3 and 'name="restrict_tags"' in pc3 and 'name="default_fields"' in pc3 and 'name="workitem_type"' not in pc3)
check('access tab: allowed groups are preselected', re.search(rf'<option value="{g1}" selected>ado_group1', pc4) is not None and 'name="is_restricted"' in pc4 and 'id="ado_groups"' in pc4 and 'id="ado_users"' in pc4)
check('connection tab: delete is disabled while tickets are linked', re.search(r'<button[^>]*disabled[^>]*>.*?trash', pc1, re.S) is not None)
check('every tab has a way back to the configuration and its own form', all(f'{P}/Config"' in c and '<form' in c for c in (pc1, pc2, pc3, pc4)))

s, page, _ = a.req(f'{P}/Config/Project')
nlabels = dict(tab_labels(page, 'ProjectTabs'))
check('a new project: only the connection tab (the other zones need a saved project)', s == 200 and list(nlabels) == ['1'], str(nlabels))
nc1 = project_tab(a, 0, 1)[1]
check('new project: token required', re.search(r'name="token"[^>]*required', nc1) is not None and 'name="name"' in nc1)
check('new project: the other tabs give nothing, even asked directly', all('<form' not in project_tab(a, 0, n)[1] for n in (2, 3, 4)))
check('csrf token in the form', bool(form_token(nc1, f'{P}/Config/Project')))

print('== administrator: add a project with its token (save and test)')
mock('/_mock/rules', {'project': 'Tata', 'type': 'Issue', 'fields': {'Microsoft.VSTS.Common.Priority': {'name': 'Priorité', 'allowed': ['1', '2', '3', '4']}}})
s, _, h = post_zone(0, 'connection', {'name': 'WebProj', 'organization': 'https://dev.azure.com/acme/', 'ado_project': 'Tata', 'auth_mode': 'pat', 'token': 'pat-tata', 'is_active': '1', '_test': '1',
                                      'workitem_type': 'Issue', 'is_restricted': '0'})
check('save redirects to the project page', s in (302, 303) and re.search(rf'{P}/Config/Project/\d+', h.get('Location', '')) is not None, f'{s} {h.get("Location")}')
new_id = int(re.search(r'/Config/Project/(\d+)', h.get('Location', '/0')).group(1))
s, page, _ = a.req(f'{P}/Config/Project/{new_id}')
check('connection test message shown', either(page, 'Connection successful', 'Connexion réussie') and 'Bot Tata' in page, text(page)[:300])
row = sql(f"select organization,ado_project,is_restricted,token,if(workitem_type='','-',workitem_type) from glpi_plugin_azuredevops_projects where id={new_id}").split('\t')
check('project stored: organization normalised, token encrypted', row[0] == 'acme' and row[1] == 'Tata' and row[3] not in ('', 'pat-tata'), str(row))
check('a new project is restricted whatever the connection form says, and ignores the fields of other zones', row[2] == '1' and row[4] == '-', str(row))
check('so nobody can use it until users or groups are chosen', sql(f"select count(*) from glpi_plugin_azuredevops_projects_groups where projects_id={new_id}") == '0')
s, _, _ = post_zone(new_id, 'work_items', {'workitem_type': 'Issue', 'parent_types[]': ['Epic'], 'followup_only_types[]': ['Epic'], 'done_categories[]': ['Completed']})
s, _, _ = post_zone(new_id, 'access', {'is_restricted': '1', 'groups[]': [str(g2)]})
s, _, _ = post_zone(new_id, 'fields', {'default_tags[]': ['glpi']})
check('access stored (group 2)', sql(f"select groups_id from glpi_plugin_azuredevops_projects_groups where projects_id={new_id}") == str(g2))
check('work items zone stored', sql(f"select concat(workitem_type,'|',parent_types,'|',followup_only_types) from glpi_plugin_azuredevops_projects where id={new_id}") == 'Issue|Epic|Epic')
nc1 = project_tab(a, new_id, 1)[1]; nc2 = project_tab(a, new_id, 2)[1]; nc3 = project_tab(a, new_id, 3)[1]
check('types now offered as a list', re.search(r'<select id="ado_type"', nc2) is not None and 'value="Issue" selected' in nc2)
check('token still hidden', 'pat-tata' not in nc1)
check('the form has the default fields box', 'name="default_fields"' in nc3)
check('required fields of the type listed with their allowed values and what is missing', 'Microsoft.VSTS.Common.Priority' in nc3 and 'Priorité' in nc3 and '1, 2, 3, 4' in nc3 and either(nc3, 'Needs a value', 'Valeur à renseigner'), text(nc3)[:200])
s, _, _ = post_zone(new_id, 'fields', {'default_tags[]': ['glpi'], 'default_fields': 'Microsoft.VSTS.Common.Priority = {priority}'})
nc3 = project_tab(a, new_id, 3)[1]
check('default fields saved and shown, the required field is now covered', 'Microsoft.VSTS.Common.Priority = {priority}' in nc3 and either(nc3, 'Has a value', 'Valeur renseignée') and not either(nc3, 'Needs a value', 'Valeur à renseigner'))
# the creation form of the ticket offers the required fields
s, pg, _ = tab(a, ticket_id, tok)
m = re.search(r'id="ado_create_\d+"\s+data-config="([^"]*)"', pg)
cfg = (json.loads(html.unescape(m.group(1))) if m else {}).get(str(new_id), {})
ff = (cfg.get('fields') or {}).get('Issue', [])
check('ticket tab embeds the types (all the types of the project, the default first) and the required fields for the creation form', cfg.get('types') and cfg['types'][0] == 'Issue' and set(cfg['types']) == {'Issue', 'Epic', 'Task'} and cfg.get('default') == 'Issue' and len(ff) == 1 and ff[0]['ref'] == 'Microsoft.VSTS.Common.Priority' and ff[0]['name'] == 'Priorité' and ff[0]['allowed'] == ['1', '2', '3', '4'] and ff[0]['value'] == '2', str(cfg))
check('the form has the place where they are shown', 'id="ado_fields_' in pg)
t6 = new_ticket()
s, pg, _ = tab(a, t6, tok)
s, _, h = a.req(f'{P}/Ticket/{t6}/WorkItem', {'_glpi_csrf_token': form_token(pg, f'{P}/Ticket/{t6}/WorkItem'), 'project_id': str(new_id), 'followup': 'none', 'notify_requester': '0', 'fields[Microsoft.VSTS.Common.Priority]': '1'})
wid = sql(f"select workitem_id from glpi_plugin_azuredevops_links where tickets_id={t6} and projects_id={new_id}")
check('created from the form with the priority chosen there', wid != '' and mock('/_mock/dump')['items'][wid]['fields'].get('Microsoft.VSTS.Common.Priority') == '1', wid)
t7 = new_ticket()
s, pg, _ = tab(a, t7, tok)
s, _, h = a.req(f'{P}/Ticket/{t7}/WorkItem', {'_glpi_csrf_token': form_token(pg, f'{P}/Ticket/{t7}/WorkItem'), 'project_id': str(new_id), 'followup': 'none', 'notify_requester': '0', 'fields[System.AssignedTo]': 'someone'})
s, pg, _ = a.req(f'/front/ticket.form.php?id={t7}')
check('a forged field is refused with a message, nothing created', sql(f"select count(*) from glpi_plugin_azuredevops_links where tickets_id={t7}") == '0' and either(pg, 'cannot be set from the ticket', 'ne peut pas être défini depuis le ticket'), text(pg)[:200])
sql(f"delete from glpi_plugin_azuredevops_links where projects_id={new_id}")
# required fields: ask them in the ticket or not, and refresh without re-testing
check('the required fields table has the process default and a box to ask each one in the ticket', 'name="ask_fields[Issue][]"' in nc3 and 'name="listed_fields[Issue][]"' in nc3 and either(nc3, 'Default in the process', 'Valeur par défaut du processus'), text(nc3)[:200])
check('the refresh button is on the work items and the fields tabs', f'{P}/Config/Project/{new_id}/Refresh' in nc3 and f'{P}/Config/Project/{new_id}/Refresh' in nc2)
fdata = {'default_tags[]': ['glpi'], 'default_fields': 'Microsoft.VSTS.Common.Priority = {priority}'}
s, _, h = post_zone(new_id, 'fields', {**fdata, 'listed_fields[Issue][]': ['Microsoft.VSTS.Common.Priority']})
nc3 = project_tab(a, new_id, 3)[1]
check('the field left unticked is no longer asked: shown as "not asked"', either(nc3, 'Not asked', 'Non demandé') and sql(f"select hidden_fields from glpi_plugin_azuredevops_projects where id={new_id}") == 'Issue: Microsoft.VSTS.Common.Priority', sql(f"select hidden_fields from glpi_plugin_azuredevops_projects where id={new_id}"))
s, pg, _ = tab(a, ticket_id, tok)
m = re.search(r'id="ado_create_\d+"\s+data-config="([^"]*)"', pg)
cfg2 = (json.loads(html.unescape(m.group(1))) if m else {}).get(str(new_id), {})
check('and the ticket form does not ask it', (cfg2.get('fields') or {}).get('Issue') == [], str(cfg2.get('fields')))
s, _, h = post_zone(new_id, 'fields', {**fdata, 'listed_fields[Issue][]': ['Microsoft.VSTS.Common.Priority'], 'ask_fields[Issue][]': ['Microsoft.VSTS.Common.Priority']})
check('ticking it again asks it again', sql(f"select ifnull(hidden_fields,'')='' from glpi_plugin_azuredevops_projects where id={new_id}") == '1')
sql(f"update glpi_plugin_azuredevops_projects set types_cache=JSON_SET(types_cache, '$.fetched_at', '2000-01-01 00:00:00') where id={new_id}")
nc2 = project_tab(a, new_id, 2)[1]
s, _, h = a.req(f'{P}/Config/Project/{new_id}/Refresh', {'_glpi_csrf_token': form_token(nc2, f'{P}/Config/Project/{new_id}/Refresh')})
check('the refresh button reads the types again without saving anything', s in (302, 303) and sql(f"select JSON_VALUE(types_cache, '$.fetched_at') >= '2026-01-01' from glpi_plugin_azuredevops_projects where id={new_id}") == '1', str(s))
s, page, _ = a.req(f'{P}/Config/Project/{new_id}')   # the message is shown by the first page displayed after the action
check('and says so', either(page, 'Connection successful', 'Connexion réussie'), text(page)[:200])
nc3 = project_tab(a, new_id, 3)[1]
check('the date of the last reading is shown', either(nc3, 'Last read', 'Dernière lecture'))
s, _, _ = a.req(f'{P}/Config/Project/{new_id}/Refresh', {'x': 'y'})
check('the refresh without CSRF token is rejected', s in (400, 403), str(s))
s, _, h = post_zone(new_id, 'fields', {'default_tags[]': ['glpi'], 'default_fields': 'garbage'})
s, page, _ = a.req(f'{P}/Config/Project/{new_id}')
nc3 = project_tab(a, new_id, 3)[1]
check('an invalid default field is refused with a message, nothing lost', ('Reference.Name' in page or 'garbage' in page) and 'Microsoft.VSTS.Common.Priority = {priority}' in nc3, text(page)[:200])
s, _, h = post_zone(new_id, 'work_items', {'workitem_type': 'Issue', 'name': 'HACKED', 'token': 'forged', 'is_restricted': '0'})
check('a tab form cannot change the settings of another zone, even when forged', sql(f"select concat(name,'|',is_restricted) from glpi_plugin_azuredevops_projects where id={new_id}") == 'WebProj|1')
mock('/_mock/rules', {'project': 'Tata', 'type': 'Issue', 'fields': {}})

s, page, _ = a.req(f'{P}/Config/Project', {'name': 'x', 'id': str(new_id), '_zone': 'connection'})
check('project POST without CSRF rejected', s in (400, 403), str(s))


print('== administrator: a project that signs in as a Microsoft Entra application')
nc1 = project_tab(a, 0, 1)[1]
check('form offers both authentication modes', 'name="auth_mode" value="pat"' in nc1 and 'name="auth_mode" value="entra"' in nc1 and 'name="tenant_id"' in nc1 and 'name="client_id"' in nc1 and 'name="client_secret"' in nc1)
check('the Entra set-up instructions are on the form', either(nc1, 'Organization settings (gear, bottom left)', 'Organization settings (roue dentée, en bas à gauche)'), text(nc1)[:200])
s, _, h = post_zone(0, 'connection', {'name': 'WebEntra', 'organization': 'acme', 'ado_project': 'Tata', 'auth_mode': 'entra', 'tenant_id': 'tenant-1', 'client_id': 'app-tata', 'client_secret': 'secret-tata', 'is_active': '1', '_test': '1'})
check('Entra project saved without any personal token', s in (302, 303) and re.search(rf'{P}/Config/Project/\d+', h.get('Location', '')) is not None, f'{s} {h.get("Location")}')
eid = int(re.search(r'/Config/Project/(\d+)', h.get('Location', '/0')).group(1))
row = sql(f"select auth_mode,ifnull(token,''),client_secret,tenant_id,client_id from glpi_plugin_azuredevops_projects where id={eid}").split('\t')
check('stored: mode, tenant and application ID in clear, secret encrypted, no PAT', row[0] == 'entra' and row[1] == '' and row[2] not in ('', 'secret-tata') and 'secret-tata' not in row[2] and row[3] == 'tenant-1' and row[4] == 'app-tata', str(row))
s, page, _ = a.req(f'{P}/Config/Project/{eid}')
check('connection test: signed in as the application', either(page, 'Connection successful', 'Connexion réussie') and 'App Tata' in page, text(page)[:300])
ec1 = project_tab(a, eid, 1)[1]
check('the form shows the Entra mode, the IDs, and never the secret', 'name="auth_mode" value="entra" checked' in ec1 and 'value="tenant-1"' in ec1 and 'value="app-tata"' in ec1 and 'secret-tata' not in ec1)
check('the secret field is optional once one is stored', not re.search(r'name="client_secret"[^>]*required', ec1))
check('the access token obtained from Entra is not in the page', 'eyJ.' not in ec1 and 'eyJ.' not in page)
c1 = config_tab(a, 1)[1]
check('project list shows the authentication mode', either(c1, 'Entra application', 'Application Entra') and either(c1, 'Personal token', 'Jeton personnel'))
check('advanced settings: Microsoft Entra sign-in address', 'name="entra_authority"' in config_tab(a, 4)[1])
s, _, _ = a.req(f'{P}/Config/Project/{eid}/Delete', {'_glpi_csrf_token': form_token(ec1, f'{P}/Config/Project/{eid}/Delete')})
check('Entra project deleted', s in (302, 303) and sql(f"select count(*) from glpi_plugin_azuredevops_projects where id={eid}") == '0', str(s))

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


print('== administrator: errors of Azure DevOps are shown, and logged')
LOG = '/opt/glpi/files/_log/azuredevops.log'
def log_text():
    return open(LOG).read() if os.path.exists(LOG) else ''
mock('/_mock/fail', {'status': 500})
s, body, _ = a.req(f'{P}/Projects/{toto}/Search?term=x&scope=parents', headers={'X-Requested-With': 'XMLHttpRequest'})
check('a failing search answers 502 with the message of Azure DevOps (displayed in the list)', s == 502 and 'forced failure' in json.loads(body).get('error', ''), f'{s} {body[:200]}')
check('the search failure is in the plugin log', 'Search failed' in log_text())
t5 = new_ticket()
s, page, _ = tab(a, t5, tok)
ft5 = form_token(page, f'{P}/Ticket/{t5}/WorkItem')
s, _, _ = a.req(f'{P}/Ticket/{t5}/WorkItem', {'_glpi_csrf_token': ft5, 'project_id': str(toto), 'followup': 'none', 'notify_requester': '0'})
s, page, _ = a.req(f'/front/ticket.form.php?id={t5}')
check('a failing creation shows the message of Azure DevOps on the ticket', 'forced failure' in page and sql(f"select count(*) from glpi_plugin_azuredevops_links where tickets_id={t5}") == '0', text(page)[:200])
check('the creation failure is in the plugin log', 'Creation failed' in log_text())
mock('/_mock/fail', {'status': 0})


print('== administrator: tags that exist in Azure DevOps')
s, body, _ = a.req(f'{P}/Projects/{toto}/Tags?term=ur', headers=XH)
check('the tags select box of the ticket gets the existing tags matching what is typed', s == 200 and [r['id'] for r in json.loads(body)['results']] == ['Urgent'], f'{s} {body[:200]}')
s, body, _ = a.req(f'{P}/Projects/{toto}/Tags', headers=XH)
check('without a term: all of them', s == 200 and [r['id'] for r in json.loads(body)['results']] == ['Backend', 'glpi', 'Urgent'], body[:200])
s, body, _ = a.req(f'{P}/Config/Project/{toto}/Tags?term=back', headers=XH)
check('the project form gets them too (configuration right)', s == 200 and [r['id'] for r in json.loads(body)['results']] == ['Backend'], f'{s} {body[:200]}')
mock('/_mock/tags', {'project': 'Toto', 'fail': 500})
def forget_tags(project_id):
    """The tags are kept a few minutes in GLPI's cache: forget them to see the next error / change."""
    here = os.path.dirname(os.path.abspath(__file__))
    subprocess.run(['php', os.path.join(here, 'forget_tags.php'), str(project_id)], env={'GLPI_ROOT': '/opt/glpi', 'PATH': '/usr/bin:/bin'}, capture_output=True)


forget_tags(toto)
s, body, _ = a.req(f'{P}/Projects/{toto}/Tags?term=x', headers=XH)
check('an error of Azure DevOps is returned with its message (shown in the list) and logged', s == 502 and json.loads(body).get('error'), f'{s} {body[:200]}')
check('the tags failure is in the plugin log', 'Tags not read' in log_text())
mock('/_mock/tags', {'project': 'Toto'})
s, pg, _ = tab(a, ticket_id, tok)
m = re.search(r'id="ado_create_\d+"\s+data-config="([^"]*)"', pg)
tc = (json.loads(html.unescape(m.group(1))) if m else {}).get(str(toto), {})
check('the tab gives the form the default tags and whether only existing tags are allowed', tc.get('default_tags') == ['glpi'] and tc.get('restrict_tags') is False, str(tc))
check('the tags select box is in the form', 'name="tags[]"' in pg and 'id="ado_tags_hint_' in pg)
t10 = new_ticket()
s, pg, _ = tab(a, t10, tok)
s, _, h = a.req(f'{P}/Ticket/{t10}/WorkItem', {'_glpi_csrf_token': form_token(pg, f'{P}/Ticket/{t10}/WorkItem'), 'project_id': str(toto), 'followup': 'none', 'notify_requester': '0', 'tags[]': ['Urgent', 'Backend']})
w10 = sql(f"select workitem_id from glpi_plugin_azuredevops_links where tickets_id={t10}")
check('tags chosen in the form are sent (with the default ones)', w10 != '' and set(t.strip() for t in mock('/_mock/dump')['items'][w10]['fields']['System.Tags'].split(';')) == {'glpi', 'Urgent', 'Backend'}, w10)
page = project_tab(a, toto, 3)[1]
check('the fields tab: default tags as a list and the option for accounts that cannot create tags', 'name="default_tags[]"' in page and 'name="restrict_tags"' in page and re.search(r'<option value="glpi" selected', page) is not None)

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
s, body, _ = t.req(f'{P}/Projects/{tata}/TypeFields?type=Issue&ticket={ticket_id}', headers={'X-Requested-With': 'XMLHttpRequest'})
check('fields endpoint refused on Tata too', s == 403, str(s))
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
page = project_tab(a, new_id, 1)[1]
dt = form_token(page, f'/Config/Project/{new_id}/Delete')
s, _, _ = a.req(f'{P}/Config/Project/{new_id}/Delete', {'_glpi_csrf_token': dt})
check('unused project deleted from its page', s in (302, 303) and sql(f"select count(*) from glpi_plugin_azuredevops_projects where id={new_id}") == '0', str(s))

print('== administrator: choose the type of work item in the ticket')
s, pg, _ = tab(a, ticket_id, tok)
m = re.search(r'id="ado_create_\d+"\s+data-config="([^"]*)"', pg)
tc = (json.loads(html.unescape(m.group(1))) if m else {}).get(str(toto), {})
check('by default the tab offers every type of the project, the default one first', tc.get('types', [''])[0] == 'User Story' and set(tc.get('types', [])) == {'User Story', 'Epic', 'Feature', 'Bug', 'Task'}, str(tc))
sql(f"update glpi_plugin_azuredevops_projects set restrict_types=1, offered_types='Bug' where id={toto}")
s, pg, _ = tab(a, ticket_id, tok)
m = re.search(r'id="ado_create_\d+"\s+data-config="([^"]*)"', pg)
tc = (json.loads(html.unescape(m.group(1))) if m else {}).get(str(toto), {})
check('restricted: only the default type and the chosen ones, the default one first', tc.get('types') == ['User Story', 'Bug'] and tc.get('default') == 'User Story', str(tc))
check('every type has its own fields entry', set((tc.get('fields') or {}).keys()) == {'User Story', 'Bug'})
check('the type selector is in the form', 'name="work_item_type"' in pg)
t8 = new_ticket()
s, pg, _ = tab(a, t8, tok)
s, _, h = a.req(f'{P}/Ticket/{t8}/WorkItem', {'_glpi_csrf_token': form_token(pg, f'{P}/Ticket/{t8}/WorkItem'), 'project_id': str(toto), 'work_item_type': 'Bug', 'followup': 'none', 'notify_requester': '0'})
row = sql(f"select workitem_type,workitem_id from glpi_plugin_azuredevops_links where tickets_id={t8}").split('\t')
check('a Bug is created from the tab', row[0] == 'Bug' and mock('/_mock/dump')['items'][row[1]]['fields']['System.WorkItemType'] == 'Bug', str(row))
t9 = new_ticket()
s, pg, _ = tab(a, t9, tok)
s, _, h = a.req(f'{P}/Ticket/{t9}/WorkItem', {'_glpi_csrf_token': form_token(pg, f'{P}/Ticket/{t9}/WorkItem'), 'project_id': str(toto), 'work_item_type': 'Task', 'followup': 'none', 'notify_requester': '0'})
s, pg, _ = a.req(f'/front/ticket.form.php?id={t9}')
check('a type the project does not offer (forged request) is refused with a message', sql(f"select count(*) from glpi_plugin_azuredevops_links where tickets_id={t9}") == '0' and either(pg, 'cannot be created from a ticket', 'ne peut pas être créé depuis un ticket'), text(pg)[:200])
mock('/_mock/seed', {'id': 800, 'project': 'Toto', 'type': 'Bug', 'title': 'Crash on save'})
s, body, _ = a.req(f'{P}/Projects/{toto}/Search?term=crash&scope=items', headers=XH)
check('the search to link an existing work item also finds the offered types (Bug)', s == 200 and 800 in [r['id'] for r in json.loads(body)['results']], body[:200])
s, body, _ = a.req(f'{P}/Projects/{toto}/Search?term=crash&scope=parents', headers=XH)
check('but not when looking for a parent', s == 200 and json.loads(body)['results'] == [], body[:200])
sql(f"delete from glpi_plugin_azuredevops_links where tickets_id in ({t8}); update glpi_plugin_azuredevops_projects set restrict_types=0, offered_types='' where id={toto}")

print('== administrator: the fields of a type are read when it is first chosen')
mock('/_mock/rules', {'project': 'Toto', 'type': 'Task', 'fields': {'Custom.Effort': {'name': 'Effort', 'allowed': ['S', 'M']}}})
sql(f"update glpi_plugin_azuredevops_projects set types_cache=JSON_REMOVE(types_cache, '$.required.Task') where id={toto}")
s, pg, _ = tab(a, ticket_id, tok)
m = re.search(r'id="ado_create_\d+"\s+data-config="([^"]*)"', pg)
tc = (json.loads(html.unescape(m.group(1))) if m else {}).get(str(toto), {})
check('a type not read yet is sent as "not read" (null), not as "no field"', tc.get('fields', {}).get('Task', 'missing') is None and isinstance(tc['fields'].get('Bug'), list), str(tc.get('fields', {}).keys()))
s, body, _ = a.req(f'{P}/Projects/{toto}/TypeFields?type=Task&ticket={ticket_id}', headers=XH)
fl = json.loads(body).get('fields', []) if s == 200 else []
check('the endpoint reads them from Azure DevOps and returns the form fields', s == 200 and [f['ref'] for f in fl] == ['Custom.Effort'] and fl[0]['allowed'] == ['S', 'M'], f'{s} {body[:200]}')
s, pg, _ = tab(a, ticket_id, tok)
m = re.search(r'id="ado_create_\d+"\s+data-config="([^"]*)"', pg)
tc = (json.loads(html.unescape(m.group(1))) if m else {}).get(str(toto), {})
check('and keeps them: the next display embeds them', [f['ref'] for f in (tc.get('fields', {}).get('Task') or [])] == ['Custom.Effort'])
s, body, _ = a.req(f'{P}/Projects/{toto}/TypeFields?type=Task&ticket=99999999', headers=XH)
check('unknown ticket: not found', s == 404, str(s))
sql(f"update glpi_plugin_azuredevops_projects set restrict_types=1, offered_types='Bug' where id={toto}")
s, body, _ = a.req(f'{P}/Projects/{toto}/TypeFields?type=Task&ticket={ticket_id}', headers=XH)
check('a type the project does not offer is refused', s == 403, str(s))
sql(f"update glpi_plugin_azuredevops_projects set restrict_types=0, offered_types='' where id={toto}")
mock('/_mock/rules', {'project': 'Toto', 'type': 'Task', 'fields': {}})
sql(f"update glpi_plugin_azuredevops_projects set types_cache=JSON_REMOVE(types_cache, '$.required.Task') where id={toto}")

print('== administrator: the types are refreshed when a ticket is opened')
mock('/_mock/type', {'project': 'Toto', 'type': 'Incident'})
sql(f"update glpi_plugin_azuredevops_projects set types_cache=JSON_SET(types_cache, '$.fetched_at', '2000-01-01 00:00:00') where id={toto}")
s, pg, _ = tab(a, ticket_id, tok)
m = re.search(r'id="ado_create_\d+"\s+data-config="([^"]*)"', pg)
tc = (json.loads(html.unescape(m.group(1))) if m else {}).get(str(toto), {})
check('a type added in Azure DevOps is offered when the ticket is opened, without any test or button', 'Incident' in tc.get('types', []), str(tc.get('types')))
check('its fields are read when it is chosen (not read yet)', tc.get('fields', {}).get('Incident', 'missing') is None)
mock('/_mock/type', {'project': 'Toto', 'type': 'Incident', 'remove': True})
sql(f"update glpi_plugin_azuredevops_projects set types_cache=JSON_SET(types_cache, '$.fetched_at', '2000-01-01 00:00:00') where id={toto}")
s, pg, _ = tab(a, ticket_id, tok)
m = re.search(r'id="ado_create_\d+"\s+data-config="([^"]*)"', pg)
tc = (json.loads(html.unescape(m.group(1))) if m else {}).get(str(toto), {})
check('and removed from the list when it disappears', 'Incident' not in tc.get('types', []), str(tc.get('types')))

print('== administrator: nothing can be done on a solved or closed ticket')
tL = new_ticket()
s, pg, _ = tab(a, tL, tok)
ftL = form_token(pg, f'{P}/Ticket/{tL}/WorkItem')
s, _, h = a.req(f'{P}/Ticket/{tL}/WorkItem', {'_glpi_csrf_token': ftL, 'project_id': str(toto), 'followup': 'none', 'notify_requester': '0'})
linkL = sql(f"select id from glpi_plugin_azuredevops_links where tickets_id={tL}")
check('open ticket: a work item can be created', linkL != '')
s, pg, _ = tab(a, tL, tok)
check('open ticket: the forms and the Refresh / Unlink buttons are there', 'name="project_id"' in pg and f'/Link/{linkL}/Refresh' in pg and f'/Link/{linkL}/Unlink' in pg)
for label, status in (('solved', 5), ('closed', 6)):
    sql(f"update glpi_tickets set status={status} where id={tL}")
    s, pg, _ = tab(a, tL, tok)
    check(f'{label} ticket: the links are shown, read only', f'#{sql(f"select workitem_id from glpi_plugin_azuredevops_links where id={linkL}")}' in pg and 'ado_create_' not in pg and 'name="project_id"' not in pg and f'/Link/{linkL}/Refresh' not in pg and f'/Link/{linkL}/Unlink' not in pg, text(pg)[:200])
    check(f'{label} ticket: a note says why', either(pg, 'solved or closed', 'résolu ou clos') and 'ti-lock' in pg)
    s, pg2, _ = tab(a, ticket_id, tok)
    tokL = form_token(pg2, f'{P}/Ticket/{ticket_id}/WorkItem')   # a valid token, from another ticket
    n_links = sql("select count(*) from glpi_plugin_azuredevops_links")
    n_items = len(mock('/_mock/dump')['items'])
    s, _, h = a.req(f'{P}/Ticket/{tL}/WorkItem', {'_glpi_csrf_token': tokL, 'project_id': str(toto), 'followup': 'none', 'notify_requester': '0', 'allow_duplicate': '1'})
    s, pg3, _ = a.req(f'/front/ticket.form.php?id={tL}')
    check(f'{label} ticket: a forged create is refused, with the message, and nothing is created', sql("select count(*) from glpi_plugin_azuredevops_links") == n_links and len(mock('/_mock/dump')['items']) == n_items and either(pg3, 'solved or closed', 'résolu ou clos'))
    s, pg2, _ = tab(a, ticket_id, tok)
    s, _, _ = a.req(f'{P}/Ticket/{tL}/WorkItem/Link', {'_glpi_csrf_token': form_token(pg2, f'{P}/Ticket/{ticket_id}/WorkItem'), 'project_id': str(toto), 'workitem_id': '700', 'followup': 'none'})
    check(f'{label} ticket: a forged link is refused', sql(f"select count(*) from glpi_plugin_azuredevops_links where tickets_id={tL}") == '1')
    s, pg2, _ = tab(a, ticket_id, tok)
    s, _, h = a.req(f'{P}/Link/{linkL}/Refresh', {'_glpi_csrf_token': form_token(pg2, f'{P}/Ticket/{ticket_id}/WorkItem')})
    s, pg3, _ = a.req(f'/front/ticket.form.php?id={tL}')
    check(f'{label} ticket: a forged refresh is refused with the message', s == 200 and either(pg3, 'solved or closed', 'résolu ou clos'), str(s))
    s, pg2, _ = tab(a, ticket_id, tok)
    s, _, h = a.req(f'{P}/Link/{linkL}/Unlink', {'_glpi_csrf_token': form_token(pg2, f'{P}/Ticket/{ticket_id}/WorkItem')})
    check(f'{label} ticket: a forged unlink is refused, the link stays', sql(f"select count(*) from glpi_plugin_azuredevops_links where id={linkL}") == '1')
    s, body, _ = a.req(f'{P}/Projects/{toto}/TypeFields?type=Bug&ticket={tL}', headers=XH)
    check(f'{label} ticket: the form helpers refuse too', s == 403, str(s))
sql(f"update glpi_tickets set status=2 where id={tL}")
s, pg, _ = tab(a, tL, tok)
check('reopened ticket: the forms and buttons are back', 'name="project_id"' in pg and f'/Link/{linkL}/Unlink' in pg)
s, pg2, _ = tab(a, tL, tok)
s, _, _ = a.req(f'{P}/Link/{linkL}/Unlink', {'_glpi_csrf_token': form_token(pg2, f'{P}/Ticket/{tL}/WorkItem')})
check('reopened ticket: the link can be unlinked', sql(f"select count(*) from glpi_plugin_azuredevops_links where id={linkL}") == '0')

print('== the tab is called "Azure DevOps"')
for who, client in (('administrator (French)', a),):
    s, pg, _ = client.req(f'/front/ticket.form.php?id={ticket_id}')
    check(f'{who}: the tab of the ticket is "Azure DevOps", not "work items"', re.search(r'ti-brand-azure[^>]*></i>\s*(?:<span[^>]*>)?\s*Azure DevOps\s*<', pg) is not None and 'Éléments de travail Azure DevOps' not in pg and 'Azure DevOps work items' not in pg, re.findall(r'.{60}ti-brand-azure.{80}', pg, re.S)[:1])
s, pg, _ = tab(a, ticket_id, tok)
check('inside the tab: the card is "Azure DevOps" too, with the same icon as before', re.search(r'<h3 class="card-title"><i class="ti ti-brand-azure me-2"></i>Azure DevOps</h3>', pg) is not None, re.findall(r'<h3 class="card-title">.{0,90}', pg)[:1])

print('== the logo of the plugin is shown by GLPI')
s, body, h = a.req('/Plugin/azuredevops/Logo')
check('GLPI serves the logo of the plugin (logo.png at the root of the plugin)', s == 200 and h.get('Content-Type', '').startswith('image/png') and len(body) > 1000, f'{s} {h.get("Content-Type")} {len(body)}')
s, page, _ = a.req('/ajax/marketplace.php?action=refresh_plugin_list&tab=installed', headers=XH)
check('the list of the installed plugins shows it', s == 200 and re.search(r"<img[^>]*/Plugin/azuredevops/Logo", page) is not None, f'{s} {len(page)}')

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
