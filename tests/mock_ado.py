#!/usr/bin/env python3
"""Tiny stateful Azure DevOps (work item tracking, API 7.1) mock used by the integration tests. No dependencies.

Organization "acme", two projects with their own token and process:
  Toto  token pat-toto  Agile-like: Epic > Feature > User Story > Task; states carry their category in the type list;
                        closing fills Microsoft.VSTS.Common.ClosedBy / ClosedDate
  Tata  token pat-tata  Basic-like: Epic > Issue > Task; the type list has no states (read from the /states endpoint);
                        closing fills nothing but System.ChangedBy / ChangedDate

Control endpoints (not part of Azure DevOps), JSON bodies:
  POST /_mock/seed    {"id":500,"project":"Toto","type":"Epic","title":"..","state":"New","parent":0}
  POST /_mock/state   {"id":101,"state":"Closed","by":"Jane Dev"}   set a state (closing fields filled when "Completed")
  POST /_mock/delete  {"id":101}
  POST /_mock/hide    {"id":101,"hidden":true}                        unreadable with the token (omitted / 404)
  POST /_mock/move    {"id":101,"project":"Tata"}
  POST /_mock/parent  {"id":101,"parent":500}
  POST /_mock/fail    {"status":500,"retry_after":30}                 every API request fails (0 = stop)
  POST /_mock/token   {"mode":"html"}                                 refused tokens answer 203 + sign-in page ("401" = default)
  POST /_mock/entra   {"expires_in":3600,"secret_expired":false,"org_access":true}   behaviour of the Entra sign-in
  POST /_mock/rules   {"project":"Tata","type":"Issue","fields":{"Microsoft.VSTS.Common.Priority":{"name":"Priorité","allowed":["1","2","3","4"]}}}
                                                                      fields the process requires when creating that type ({} = none)
  POST /_mock/tags    {"project":"Toto","tags":["Backend","Urgent"],"preview_only":false,"fail":0}
                                                                      tags of a project; preview_only = the stable API version answers 404; fail = HTTP status of the tags list
  POST /_mock/type    {"project":"Toto","type":"Incident","remove":false}   add (or remove) a work item type in the project's process
  POST /_mock/revoke_bearers                                          every access token issued so far stops working
  GET  /_mock/dump    -> everything received so far
  POST /_mock/reset
"""
import base64, json, re, sys, time, urllib.parse, uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ORG = 'acme'
PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8098

PROJECTS = {
    'Toto': {
        'token': 'pat-toto', 'id': 'p-toto', 'user': 'Bot Toto',
        'types': {
            'Epic':       {'states': [('New', 'Proposed'), ('Active', 'InProgress'), ('Closed', 'Completed'), ('Removed', 'Removed')]},
            'Feature':    {'states': [('New', 'Proposed'), ('Active', 'InProgress'), ('Resolved', 'Resolved'), ('Closed', 'Completed'), ('Removed', 'Removed')]},
            'User Story': {'states': [('New', 'Proposed'), ('Active', 'InProgress'), ('Resolved', 'Resolved'), ('Closed', 'Completed'), ('Removed', 'Removed')]},
            'Bug':        {'states': [('New', 'Proposed'), ('Active', 'InProgress'), ('Resolved', 'Resolved'), ('Closed', 'Completed'), ('Removed', 'Removed')]},
            'Task':       {'states': [('New', 'Proposed'), ('Active', 'InProgress'), ('Closed', 'Completed'), ('Removed', 'Removed')]},
        },
        'hierarchy': {'Feature': ['Epic'], 'User Story': ['Feature', 'Epic'], 'Bug': ['Feature', 'Epic'], 'Task': ['User Story', 'Bug']},
        'states_in_list': True, 'closed_fields': True,
    },
    'Tata': {
        'token': 'pat-tata', 'id': 'p-tata', 'user': 'Bot Tata',
        'types': {
            'Epic':  {'states': [('To Do', 'Proposed'), ('Doing', 'InProgress'), ('Done', 'Completed')]},
            'Issue': {'states': [('To Do', 'Proposed'), ('Doing', 'InProgress'), ('Done', 'Completed')]},
            'Task':  {'states': [('To Do', 'Proposed'), ('Doing', 'InProgress'), ('Done', 'Completed')]},
        },
        'hierarchy': {'Issue': ['Epic'], 'Task': ['Issue']},
        'states_in_list': False, 'closed_fields': False,
    },
}
# Microsoft Entra application: signs in at /_entra/<tenant>/oauth2/v2.0/token and is a user of the organization,
# with access to one project (like a PAT, but not tied to a person)
APPS = {'app-tata': {'tenant': 'tenant-1', 'secret': 'secret-tata', 'project': 'Tata', 'user': 'App Tata'}}
SCOPE = '499b84ac-1321-427f-aa17-267ca6975798/.default'
SIGNIN_HTML = '<!DOCTYPE html><html><head><title>Sign In</title></head><body>Sign in to Azure DevOps</body></html>'

state = {}


def reset():
    for p, t in state.get('added_types', []):   # types added by /_mock/type are forgotten
        PROJECTS[p]['types'].pop(t, None)
    state.clear()
    state.update({'items': {}, 'attachments': {}, 'requests': [], 'next_id': 100, 'fail': 0, 'retry_after': 0, 'token_mode': '401',
                  'bearers': {}, 'token_requests': [], 'rules': {}, 'tags': {'Toto': ['Backend', 'Urgent', 'glpi'], 'Tata': ['Infra', 'glpi']}, 'tags_flags': {}, 'added_types': [], 'entra': {'expires_in': 3600, 'secret_expired': False, 'org_access': True}})


reset()


def identity(name):
    return {'displayName': name, 'uniqueName': name.lower().replace(' ', '.') + '@acme.test', 'id': str(uuid.uuid5(uuid.NAMESPACE_DNS, name))}


def now():
    return '2026-10-27T10:30:00.000Z'


def new_item(project, wtype, title, st=None, parent=0, item_id=None, by='Bot'):
    if item_id is None:
        item_id = state['next_id']
        state['next_id'] += 1
    proj = PROJECTS[project]
    first = proj['types'][wtype]['states'][0][0]
    item = {'id': item_id, 'rev': 1, 'hidden': False, 'relations': [],
            'fields': {'System.Id': item_id, 'System.WorkItemType': wtype, 'System.Title': title, 'System.State': st or first,
                       'System.TeamProject': project, 'System.ChangedDate': now(), 'System.ChangedBy': identity(by)}}
    if parent:
        item['fields']['System.Parent'] = parent
    state['items'][item_id] = item
    return item


def category(item):
    proj = PROJECTS[item['fields']['System.TeamProject']]
    for name, cat in proj['types'][item['fields']['System.WorkItemType']]['states']:
        if name == item['fields']['System.State']:
            return cat
    return ''


def set_state(item, st, by):
    item['fields']['System.State'] = st
    item['fields']['System.ChangedDate'] = '2026-10-27T11:45:00.000Z'
    item['fields']['System.ChangedBy'] = identity(by)
    if category(item) == 'Completed' and PROJECTS[item['fields']['System.TeamProject']]['closed_fields']:
        item['fields']['Microsoft.VSTS.Common.ClosedBy'] = identity(by)
        item['fields']['Microsoft.VSTS.Common.ClosedDate'] = '2026-10-27T11:44:00.000Z'
        item['fields']['Microsoft.VSTS.Common.StateChangeDate'] = '2026-10-27T11:44:00.000Z'


def public(item, fields=None):
    out = {'id': item['id'], 'rev': item['rev'], 'fields': dict(item['fields']),
           'url': f'http://127.0.0.1:{PORT}/{ORG}/_apis/wit/workItems/{item["id"]}'}
    if fields:
        out['fields'] = {k: v for k, v in out['fields'].items() if k in fields}
    return out


class H(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def send(self, code, data=None, ctype='application/json', headers=None):
        body = data.encode() if isinstance(data, str) else (json.dumps(data).encode() if data is not None else b'')
        self.send_response(code)
        self.send_header('Content-Type', ctype)
        self.send_header('Content-Length', str(len(body)))
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def error(self, code, msg, headers=None):
        self.send(code, {'$id': '1', 'message': msg, 'typeKey': 'Error', 'errorCode': 0}, headers=headers)

    def token_project(self):
        """Project the credentials give access to (None = refused). Basic = a PAT, Bearer = an Entra access token."""
        auth = self.headers.get('Authorization', '')
        self.who_user = None
        if auth.startswith('Bearer '):
            b = state['bearers'].get(auth[7:])
            if b is None or b['exp'] < time.time() or not state['entra']['org_access']:
                return None
            self.who_user = APPS[b['client']]['user']
            return b['project']
        if not auth.startswith('Basic '):
            return None
        try:
            user_pass = base64.b64decode(auth[6:]).decode()
        except Exception:
            return None
        token = user_pass.split(':', 1)[1] if ':' in user_pass else ''
        for name, p in PROJECTS.items():
            if p['token'] == token:
                self.who_user = p['user']
                return name
        return None

    def entra_token(self, tenant, raw):
        form = {k: v[0] for k, v in urllib.parse.parse_qs(raw.decode()).items()}
        state['token_requests'].append({'tenant': tenant, 'form': form, 'time': time.time()})
        app = APPS.get(form.get('client_id', ''))
        err = lambda code, e, desc: self.send(code, {'error': e, 'error_description': desc + '\r\nTrace ID: 0000\r\nCorrelation ID: 1111\r\nTimestamp: 2026-10-27 10:30:00Z'})
        if state['fail']:
            return self.send(state['fail'], {'error': 'temporarily_unavailable'})
        if form.get('grant_type') != 'client_credentials' or form.get('scope') != SCOPE:
            return err(400, 'invalid_scope', 'AADSTS70011: The provided request must include a scope.')
        if app is None or app['tenant'] != tenant:
            return err(400, 'unauthorized_client', "AADSTS700016: Application with identifier '%s' was not found in the directory." % form.get('client_id'))
        if state['entra']['secret_expired']:
            return err(401, 'invalid_client', "AADSTS7000222: The provided client secret keys for app '%s' are expired." % form['client_id'])
        if form.get('client_secret') != app['secret']:
            return err(401, 'invalid_client', 'AADSTS7000215: Invalid client secret provided. Ensure the secret being sent in the request is the client secret value, not the client secret ID.')
        token = 'eyJ.' + str(uuid.uuid4())
        state['bearers'][token] = {'project': app['project'], 'client': form['client_id'], 'exp': time.time() + state['entra']['expires_in']}
        return self.send(200, {'token_type': 'Bearer', 'expires_in': state['entra']['expires_in'], 'ext_expires_in': state['entra']['expires_in'], 'access_token': token})

    def handle_any(self, method):
        url = urllib.parse.urlparse(self.path)
        n = int(self.headers.get('Content-Length') or 0)
        raw = self.rfile.read(n) if n else b''
        path = urllib.parse.unquote(url.path)
        qs = urllib.parse.parse_qs(url.query)

        if path.startswith('/_mock/'):
            return self.control(method, path, raw)

        mm = re.match(r'^/_entra/([^/]+)/oauth2/v2\.0/token$', path)
        if mm and method == 'POST':
            return self.entra_token(mm.group(1), raw)

        ctype = self.headers.get('Content-Type', '')
        state['requests'].append({'method': method, 'path': path, 'query': url.query, 'ctype': ctype, 'auth': bool(self.headers.get('Authorization')),
                                  'auth_type': self.headers.get('Authorization', '').split(' ')[0],
                                  'body': raw.decode('utf-8', 'replace')[:60000] if 'octet' not in ctype else '<%d bytes>' % len(raw)})

        who = self.token_project()
        if who is None:
            if state['token_mode'] == 'html':
                return self.send(203, SIGNIN_HTML, ctype='text/html; charset=utf-8')
            return self.error(401, 'TF400813: The user is not authorized to access this resource.')
        if state['fail']:
            return self.error(state['fail'], 'forced failure', headers={'Retry-After': str(state['retry_after'])} if state['retry_after'] else None)

        m = re.match(r'^/%s/(.*)$' % ORG, path)
        if not m:
            return self.error(404, 'VS800075: organization not found')
        rest = m.group(1)

        # ---- organization level
        if rest == '_apis/connectionData' and method == 'GET':
            return self.send(200, {'authenticatedUser': {'providerDisplayName': self.who_user}})
        mm = re.match(r'^_apis/projects/([^/]+)$', rest)
        if mm and method == 'GET':
            name = mm.group(1)
            if name not in PROJECTS or PROJECTS[name]['token'] != PROJECTS[who]['token']:
                return self.error(404, f'TF200016: The following project does not exist: {name}.')
            return self.send(200, {'id': PROJECTS[name]['id'], 'name': name, 'state': 'wellFormed'})
        if rest == '_apis/wit/workitemsbatch' and method == 'POST':
            body = json.loads(raw or b'{}')
            if len(body.get('ids', [])) > 200:
                return self.error(400, 'VS402337: The maximum number of ids is 200.')
            out = []
            for i in body.get('ids', []):
                it = state['items'].get(i)
                out.append(None if (it is None or it['hidden']) else public(it, body.get('fields')))
            return self.send(200, {'count': len(out), 'value': out})
        mm = re.match(r'^_apis/wit/workitems/(\d+)$', rest)
        if mm and method == 'GET':
            it = state['items'].get(int(mm.group(1)))
            if it is None or it['hidden']:
                return self.error(404, f'TF401232: Work item {mm.group(1)} does not exist, or you do not have permissions to read it.')
            return self.send(200, public(it))

        # ---- project level
        mm = re.match(r'^([^/]+)/_apis/(.*)$', rest)
        if not mm or mm.group(1) not in PROJECTS:
            return self.error(404, 'TF200016: The following project does not exist.')
        project, api = mm.group(1), mm.group(2)
        proj = PROJECTS[project]
        if proj['token'] != PROJECTS[who]['token']:
            return self.error(403, 'TF400813: The user is not authorized to access this resource.')

        if api == 'wit/workitemtypes' and method == 'GET':
            value = []
            for name, t in proj['types'].items():
                e = {'name': name, 'referenceName': 'Mock.' + name.replace(' ', ''), 'isDisabled': False}
                if proj['states_in_list']:
                    e['states'] = [{'name': s, 'color': 'ffffff', 'category': c} for s, c in t['states']]
                value.append(e)
            value.append({'name': 'Hidden Type', 'isDisabled': True})
            return self.send(200, {'count': len(value), 'value': value})
        if api == 'wit/tags' and method == 'GET':
            flags = state['tags_flags'].get(project, {})
            if flags.get('fail'):
                return self.error(int(flags['fail']), 'tags list failed')
            if flags.get('preview_only') and (qs.get('api-version') or [''])[0] == '7.1':
                return self.error(404, 'VS402892: the requested API version is not available')
            names = state['tags'].get(project, [])
            return self.send(200, {'count': len(names), 'value': [{'id': 'tag-%d' % i, 'name': n, 'url': 'x'} for i, n in enumerate(names)]})
        mm = re.match(r'^wit/workitemtypes/([^/]+)/fields$', api)
        if mm and method == 'GET':
            t = proj['types'].get(mm.group(1))
            if t is None:
                return self.error(404, 'VS402323: work item type not found')
            out = [{'referenceName': 'System.Title', 'name': 'Title', 'alwaysRequired': True, 'defaultValue': None},
                   {'referenceName': 'System.State', 'name': 'State', 'alwaysRequired': True, 'defaultValue': t['states'][0][0]},
                   {'referenceName': 'System.AreaPath', 'name': 'Area Path', 'alwaysRequired': True, 'defaultValue': project},
                   {'referenceName': 'System.Description', 'name': 'Description', 'alwaysRequired': False},
                   {'referenceName': 'Microsoft.VSTS.Common.Activity', 'name': 'Activity', 'alwaysRequired': False, 'allowedValues': ['Design', 'Development']}]
            for ref, r in state['rules'].get(f'{project}|{mm.group(1)}', {}).items():
                out.append({'referenceName': ref, 'name': r['name'], 'alwaysRequired': True, 'allowedValues': r.get('allowed', [])})
            return self.send(200, {'count': len(out), 'value': out})
        mm = re.match(r'^wit/workitemtypes/([^/]+)/states$', api)
        if mm and method == 'GET':
            t = proj['types'].get(mm.group(1))
            if t is None:
                return self.error(404, 'VS402323: work item type not found')
            return self.send(200, {'count': len(t['states']), 'value': [{'name': s, 'color': 'ffffff', 'stateCategory': c} for s, c in t['states']]})

        mm = re.match(r'^wit/workitems/\$(.+)$', api)
        if mm and method == 'POST':
            return self.create(project, proj, mm.group(1), ctype, raw)

        if api == 'wit/attachments' and method == 'POST':
            name = (qs.get('fileName') or ['file'])[0]
            aid = str(uuid.uuid4())
            state['attachments'][aid] = {'name': name, 'size': len(raw), 'ctype': ctype}
            return self.send(201, {'id': aid, 'url': f'http://127.0.0.1:{PORT}/{ORG}/_apis/wit/attachments/{aid}?fileName={urllib.parse.quote(name)}'})

        if api == 'wit/wiql' and method == 'POST':
            return self.wiql(project, qs, raw)

        return self.error(404, 'VS402892: unknown API ' + api)

    def create(self, project, proj, wtype, ctype, raw):
        if 'json-patch+json' not in ctype:
            return self.error(415, 'VS402903: expected application/json-patch+json')
        if wtype not in proj['types']:
            return self.error(400, f'TF401243: The work item type {wtype} does not exist in project {project}.')
        ops = json.loads(raw or b'[]')
        fields, rels, parent = {}, [], 0
        for op in ops:
            if op.get('op') != 'add':
                return self.error(400, 'only add is supported by the mock')
            p = op['path']
            if p.startswith('/fields/'):
                fields[p[len('/fields/'):]] = op['value']
            elif p == '/relations/-':
                v = op['value']
                rels.append(v)
                if v.get('rel') == 'System.LinkTypes.Hierarchy-Reverse':
                    parent = int(v['url'].rstrip('/').split('/')[-1])
        if not fields.get('System.Title'):
            return self.error(400, 'TF401320: Rule Error for field Title. Error code: Required, InvalidEmpty.')
        if parent:
            pit = state['items'].get(parent)
            if pit is None:
                return self.error(400, f'TF401349: An error occurred while linking: work item {parent} does not exist.')
            allowed = proj['hierarchy'].get(wtype, [])
            if pit['fields']['System.WorkItemType'] not in allowed:
                return self.error(400, f'TF26194: The link type is not allowed between a {pit["fields"]["System.WorkItemType"]} and a {wtype}.')
        errors = []
        for ref, r in state['rules'].get(f'{project}|{wtype}', {}).items():
            v = fields.get(ref)
            if v in (None, ''):
                errors.append(f"TF401320: Rule Error for field {r['name']}. Error code: Required, HasValues, LimitedToValues, AllowsOldValue, InvalidEmpty.")
            elif r.get('allowed') and str(v) not in r['allowed']:
                errors.append(f"TF401320: Rule Error for field {r['name']}. Error code: LimitedToValues, InvalidListValue.")
        if errors:
            return self.error(400, errors[0] + (' One additional error occurred during validation of the work item.' if len(errors) > 1 else '') + ' Please correct all errors and try again.')
        item = new_item(project, wtype, fields['System.Title'], parent=parent, by=proj['user'])
        item['relations'] = rels
        for k, v in fields.items():
            if k not in ('System.Title',):
                item['fields'][k] = v
        return self.send(200, public(item))

    def wiql(self, project, qs, raw):
        q = json.loads(raw or b'{}').get('query', '')
        types = re.search(r"\[System\.WorkItemType\] IN \(([^)]*)\)", q)
        types = re.findall(r"'((?:[^']|'')*)'", types.group(1)) if types else []
        contains = re.search(r"\[System\.Title\] CONTAINS '((?:[^']|'')*)'", q)
        by_id = re.search(r"\[System\.Id\] = (\d+)", q)
        top = int((qs.get('$top') or ['200'])[0])
        out = []
        for it in sorted(state['items'].values(), key=lambda i: -i['id']):
            f = it['fields']
            if it['hidden'] or f['System.TeamProject'] != project or (types and f['System.WorkItemType'] not in types):
                continue
            if contains or by_id:
                ok = (contains and contains.group(1).replace("''", "'").lower() in f['System.Title'].lower()) or (by_id and it['id'] == int(by_id.group(1)))
                if not ok:
                    continue
            out.append({'id': it['id'], 'url': f'http://127.0.0.1:{PORT}/{ORG}/_apis/wit/workItems/{it["id"]}'})
        return self.send(200, {'queryType': 'flat', 'workItems': out[:top]})

    def control(self, method, path, raw):
        data = json.loads(raw or b'{}')
        if path == '/_mock/dump':
            return self.send(200, {**state, 'items': {str(k): v for k, v in state['items'].items()}, 'attachments': state['attachments']})
        if path == '/_mock/reset':
            reset()
            return self.send(200, {})
        if path == '/_mock/fail':
            state['fail'] = int(data['status'])
            state['retry_after'] = int(data.get('retry_after', 0))
            return self.send(200, {})
        if path == '/_mock/rules':
            key = f"{data['project']}|{data['type']}"
            if data.get('fields'):
                state['rules'][key] = data['fields']
            else:
                state['rules'].pop(key, None)
            return self.send(200, {})
        if path == '/_mock/type':
            types = PROJECTS[data['project']]['types']
            if data.get('remove'):
                types.pop(data['type'], None)
            else:
                types[data['type']] = {'states': [('New', 'Proposed'), ('Active', 'InProgress'), ('Closed', 'Completed')]}
                state['added_types'].append((data['project'], data['type']))
            return self.send(200, {})
        if path == '/_mock/tags':
            if 'tags' in data:
                state['tags'][data['project']] = data['tags']
            state['tags_flags'][data['project']] = {'preview_only': bool(data.get('preview_only')), 'fail': int(data.get('fail', 0))}
            return self.send(200, {})
        if path == '/_mock/entra':
            state['entra'].update(data)
            return self.send(200, {})
        if path == '/_mock/revoke_bearers':
            state['bearers'].clear()
            return self.send(200, {})
        if path == '/_mock/token':
            state['token_mode'] = data['mode']
            return self.send(200, {})
        if path == '/_mock/seed':
            it = new_item(data['project'], data['type'], data['title'], data.get('state'), data.get('parent', 0), data['id'])
            return self.send(200, public(it))
        it = state['items'].get(int(data.get('id', 0)))
        if path == '/_mock/delete':
            state['items'].pop(int(data['id']), None)
            return self.send(200, {})
        if it is None:
            return self.send(404, {})
        if path == '/_mock/state':
            set_state(it, data['state'], data.get('by', 'Jane Dev'))
        elif path == '/_mock/hide':
            it['hidden'] = bool(data.get('hidden', True))
        elif path == '/_mock/move':
            it['fields']['System.TeamProject'] = data['project']
        elif path == '/_mock/parent':
            if data.get('parent'):
                it['fields']['System.Parent'] = int(data['parent'])
            else:
                it['fields'].pop('System.Parent', None)
        return self.send(200, public(it))

    def do_GET(self):
        self.handle_any('GET')

    def do_POST(self):
        self.handle_any('POST')


if __name__ == '__main__':
    ThreadingHTTPServer(('127.0.0.1', PORT), H).serve_forever()
