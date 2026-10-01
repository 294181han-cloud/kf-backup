import json, urllib.request, urllib.error

BASE = 'https://kf.ylcz.cc'

def req(method, path, body=None, token=None):
    data = json.dumps(body).encode() if body is not None else None
    r = urllib.request.Request(BASE + path, data=data, method=method)
    r.add_header('Content-Type', 'application/json')
    if token: r.add_header('Authorization', 'Bearer ' + token)
    try:
        with urllib.request.urlopen(r) as resp:
            return resp.read().decode(), resp.status
    except urllib.error.HTTPError as e:
        return e.read().decode(), e.code

def jreq(method, path, body=None, token=None):
    b, _ = req(method, path, body, token)
    return json.loads(b)

atok = json.loads(req('POST', '/api/auth/login', {'account': 'admin', 'password': '123456'})[0])['token']
req('POST', '/api/admin/tenants', {'kami': 'OFF1', 'password': 'off12345'}, atok)
ttok = json.loads(req('POST', '/api/auth/login', {'account': 'OFF1', 'password': 'off12345'})[0])['token']

# 访客进入后【不发心跳】（25 秒后即离线；直接模拟离线效果：先 init 再立刻把 last_online_at 置旧——通过接口做不到，直接测当前状态）
i = json.loads(req('POST', '/api/visitor/init', {'t': 'OFF1', 'name': '离线测试访客', 'visitor_token': 'vt_off1'})[0])
sid = i['session_id']
print('sid =', sid)

# 会话列表应显示 offline（无心跳）
pj = json.loads(req('GET', '/api/poll?role=agent&session=0&sinceMsg=0', token=ttok)[0])
sess = pj.get('sessions', [])
print('列表会话数:', len(sess), '状态:', [s.get('status') for s in sess])

# 依次调用 openSession 的 4 个接口，检查 body 是否纯 JSON（有无 Warning 前缀）
tests = [
    ('GET', f'/api/sessions/{sid}/messages', None, 'messages'),
    ('POST', f'/api/sessions/{sid}/read', {}, 'read'),
    ('GET', f'/api/sessions/{sid}/profile', None, 'profile'),
    ('GET', f'/api/sessions/{sid}/contacts', None, 'contacts'),
]
for method, path, body, name in tests:
    body_txt, status = req(method, path, body, ttok)
    pure = body_txt.startswith('{') or body_txt.startswith('[')
    print(f'[{name}] HTTP {status} 纯JSON={pure} 长度={len(body_txt)}')
    if not pure:
        print('  ---- 响应前 500 字符 ----')
        print('  ' + body_txt[:500].replace('\n', '\n  '))

# 清理
tenants = json.loads(req('GET', '/api/admin/tenants', token=atok)[0])
for t in tenants:
    if t['kami'] == 'OFF1':
        req('DELETE', f"/api/admin/tenants/{t['id']}", token=atok)
print('已清理 OFF1')
