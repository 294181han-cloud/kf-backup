import json, time, urllib.request

BASE = 'https://kf.ylcz.cc'
LAST_COST = 0

def req(method, path, body=None, token=None):
    global LAST_COST
    data = json.dumps(body).encode() if body is not None else None
    r = urllib.request.Request(BASE + path, data=data, method=method)
    r.add_header('Content-Type', 'application/json')
    if token: r.add_header('Authorization', 'Bearer ' + token)
    t0 = time.time()
    try:
        with urllib.request.urlopen(r) as resp:
            out = json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        out = json.loads(e.read().decode())
    LAST_COST = round(time.time() - t0, 2)
    return out

atok = req('POST', '/api/auth/login', {'account': 'admin', 'password': '123456'})['token']
req('POST', '/api/admin/tenants', {'kami': 'RCP1', 'password': 'rcp12345', 'max_agents': 5}, atok)
ttok = req('POST', '/api/auth/login', {'account': 'RCP1', 'password': 'rcp12345'})['token']
req('POST', '/api/tenant/agents', {'username': 'kfA', 'password': 'a123456'}, ttok)
req('POST', '/api/tenant/agents', {'username': 'kfB', 'password': 'b123456'}, ttok)
atokA = req('POST', '/api/auth/login', {'account': 'RCP1/kfA', 'password': 'a123456'})['token']
atokB = req('POST', '/api/auth/login', {'account': 'RCP1/kfB', 'password': 'b123456'})['token']

sids = []
for n in range(3):
    i = req('POST', '/api/visitor/init', {'t': 'RCP1', 'name': '访客' + str(n+1), 'visitor_token': 'vt_rcp' + str(n)})
    sids.append(i['session_id'])
print('会话:', sids)

print('\n=== 场景1：kefuA 连续 poll 稳定性（5 次）===')
for n in range(5):
    r = req('GET', '/api/poll?role=agent&session=' + str(sids[0]) + '&sinceMsg=0', token=atokA)
    cnt = len(r.get('sessions', [])) if isinstance(r, dict) and 'sessions' in r else '-'
    print('  第' + str(n+1) + '次: 耗时' + str(LAST_COST) + 's 会话数=' + str(cnt) + ' error=' + str(r.get('error', '-')))

print('\n=== 场景2：会话被转走后，currentId 仍指向它继续 poll（复现冻结）===')
req('POST', '/api/sessions/' + str(sids[0]) + '/messages', {'text': 'kfA 先接待'}, atokA)
req('POST', '/api/sessions/' + str(sids[0]) + '/messages', {'text': 'kfB 接手'}, atokB)
for n in range(3):
    r = req('GET', '/api/poll?role=agent&session=' + str(sids[0]) + '&sinceMsg=99999', token=atokA)
    print('  第' + str(n+1) + '次: 耗时' + str(LAST_COST) + 's error=' + str(r.get('error', '-')) + '  <- 每次都失败=前端列表冻结')

print('\n=== 场景3：无 session 的纯列表 poll ===')
r = req('GET', '/api/poll?role=agent&session=0&sinceMsg=0', token=atokA)
print('  会话数=', len(r.get('sessions', [])), ' 耗时=', LAST_COST, 's error=', r.get('error', '-'))

print('\n=== 场景4：高频压测（12 次 x 1.2s 模拟自适应轮询）===')
errs = 0; times = []
for n in range(12):
    r = req('GET', '/api/poll?role=agent&session=' + str(sids[1]) + '&sinceMsg=0', token=atokA)
    times.append(LAST_COST)
    if r.get('error'): errs += 1
    time.sleep(1.2)
print('  错误: ' + str(errs) + '/12  耗时 min=' + str(min(times)) + ' max=' + str(max(times)) + ' avg=' + str(round(sum(times)/len(times), 2)))

tenants = req('GET', '/api/admin/tenants', token=atok)
for t in tenants:
    if t['kami'] == 'RCP1':
        req('DELETE', '/api/admin/tenants/' + str(t['id']), token=atok)
print('已清理 RCP1')
