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
  GET  /_mock/dump    -> everything received so far
  POST /_mock/reset
"""
import base64, json, re, sys, urllib.parse, uuid
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
            'Task':       {'states': [('New', 'Proposed'), ('Active', 'InProgress'), ('Closed', 'Completed'), ('Removed', 'Removed')]},
        },
        'hierarchy': {'Feature': ['Epic'], 'User Story': ['Feature', 'Epic'], 'Task': ['User Story']},
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
SIGNIN_HTML = '<!DOCTYPE html><html><head><title>Sign In</title></head><body>Sign in to Azure DevOps</body></html>'

state = {}


def reset():
    state.clear()
    state.update({'items': {}, 'attachments': {}, 'requests': [], 'next_id': 100, 'fail': 0, 'retry_after': 0, 'token_mode': '401'})


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
        auth = self.headers.get('Authorization', '')
        if not auth.startswith('Basic '):
            return None
        try:
            user_pass = base64.b64decode(auth[6:]).decode()
        except Exception:
            return None
        token = user_pass.split(':', 1)[1] if ':' in user_pass else ''
        for name, p in PROJECTS.items():
            if p['token'] == token:
                return name
        return None

    def handle_any(self, method):
        url = urllib.parse.urlparse(self.path)
        n = int(self.headers.get('Content-Length') or 0)
        raw = self.rfile.read(n) if n else b''
        path = urllib.parse.unquote(url.path)
        qs = urllib.parse.parse_qs(url.query)

        if path.startswith('/_mock/'):
            return self.control(method, path, raw)

        ctype = self.headers.get('Content-Type', '')
        state['requests'].append({'method': method, 'path': path, 'query': url.query, 'ctype': ctype, 'auth': bool(self.headers.get('Authorization')),
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
            return self.send(200, {'authenticatedUser': {'providerDisplayName': PROJECTS[who]['user']}})
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
