"""Run against a disposable loopback shop with synthetic admin/non-admin accounts.
EL_TEST_ADMIN_CREDENTIALS points to a private JSON list with username/password entries.
No credentials or OAuth tokens are printed.
"""
import json
import os
from pathlib import Path
import urllib.error
import urllib.request
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
from local_http import local_origin, opener

base = local_origin(os.environ.get('EL_TEST_SHOP_URL', 'http://127.0.0.1:8098'))
users = json.loads(Path(os.environ['EL_TEST_ADMIN_CREDENTIALS']).read_text())
http = opener()
def call(path, method='GET', body=None, token=None):
    headers = {'Content-Type': 'application/json'}
    if token: headers['Authorization'] = 'Bearer '+token
    request = urllib.request.Request(base+path, data=None if body is None else json.dumps(body).encode(), headers=headers, method=method)
    try:
        with http.open(request, timeout=30) as r: return r.status, r.read()
    except urllib.error.HTTPError as e: return e.code, e.read()

path='/api/_action/shopware-meilisearch/reindex'
assert call(path)[0] in (401,403), 'Anonymous status access allowed'
for i,user in enumerate(users):
    status,body=call('/api/oauth/token','POST',dict(user,client_id='administration',grant_type='password',scopes='write'))
    assert status==200, 'Synthetic login failed'
    token=json.loads(body)['access_token']
    status,body=call(path,'POST',{},token)
    if i==0:
        assert status==202 and json.loads(body)['pending'], 'Admin rebuild request failed'
        status,body=call(path,token=token);assert status==200 and json.loads(body)['pending'], 'Admin status unavailable'
        status,body=call('/api/_action/system-config/schema?domain=ShopwareMeilisearch.config',token=token)
        assert status==200 and b'sw-meilisearch-reindex' in body, 'Backend control missing'
    else: assert status==403, 'Non-admin without config update privileges can rebuild'
print('Admin integration passed: anonymous denied, privileged rebuild accepted, unprivileged rebuild denied, backend control present.')
