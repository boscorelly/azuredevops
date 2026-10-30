#!/usr/bin/env python3
"""Helpers shared by the HTTP tests: a cookie-aware GLPI client, a mock Azure DevOps control function, check()."""
import re, sys, json, urllib.request, urllib.parse, http.cookiejar, html

BASE = 'http://127.0.0.1:8080'
ok_n = fail_n = 0

def check(name, cond, detail=''):
    global ok_n, fail_n
    if cond: ok_n += 1; print('  ok   - ' + name)
    else: fail_n += 1; print('  FAIL - ' + name + (f'  [{detail[:300]}]' if detail else ''))

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None

class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
        self.csrf = None
    def req(self, path, data=None, headers=None, method=None):
        url = path if path.startswith('http') else BASE + path
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        r = urllib.request.Request(url, data=body, headers=headers or {}, method=method)
        try:
            resp = self.op.open(r, timeout=60)
        except urllib.error.HTTPError as e:
            resp = e
        return resp.status if hasattr(resp, 'status') else resp.code, resp.read().decode('utf-8', 'replace'), dict(resp.headers)
    def token(self, page):
        m = re.search(r'name="_glpi_csrf_token"\s+value="([0-9a-f]+)"', page) or re.search(r'value="([0-9a-f]{64})"\s+name="_glpi_csrf_token"', page)
        m = m or re.search(r'csrf[_-]token[^0-9a-f]{1,20}([0-9a-f]{64})', page, re.I)
        return m.group(1) if m else None
    def login(self, user, pwd):
        s, page, _ = self.req('/')
        tok = self.token(page)
        s, body, h = self.req('/front/login.php', {'_glpi_csrf_token': tok, 'login_name': user, 'login_password': pwd, 'auth': 'local', 'submit': 'Post'})
        return s in (302, 303)
    def ajax_token(self):
        s, page, _ = self.req('/front/central.php')
        m = re.search(r'property="glpi:csrf_token"\s+content="([0-9a-f]+)"', page)
        return m.group(1) if m else None

def form_token(page, action):
    # the CSRF token of the <form> posting to `action` (every form has its own single-use token)
    m = re.search(r'action="[^"]*' + re.escape(action) + r'"[^>]*>\s*<input type="hidden" name="_glpi_csrf_token" value="([0-9a-f]+)"', page)
    return m.group(1) if m else None

def mock(path, body=None):
    r = urllib.request.Request('http://127.0.0.1:8098' + path, data=json.dumps(body or {}).encode() if path != '/_mock/dump' else None,
                               method='GET' if path == '/_mock/dump' else 'POST', headers={'Content-Type': 'application/json'})
    return json.loads(urllib.request.urlopen(r).read())


def summary():
    print(f'\n{ok_n} passed, {fail_n} failed')
    return 1 if fail_n else 0
