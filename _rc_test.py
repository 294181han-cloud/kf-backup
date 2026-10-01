import json, urllib.request

BASE = 'https://kf.ylcz.cc'

def req(method, path, body=None, token=None):
    data = json.dumps(body).encode() if body is not None else None
    r = urllib.request.Request(BASE + path, data=data, method=method)
    r.add_header('Content-Type', 'application/json')
    if token: r.add_header('Authorization', 'Bearer ' + token)
    try:
        with urllib.request.urlopen(r) as resp:
            return json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        return json.loads(e.read().decode())

atok = req('POST', '/api/auth/login', {'account': 'admin', 'password': '123456'})['token']
req('POST', '/api/admin/tenants', {'kami': 'RCT1', 'password': 'rc123456'}, atok)
ttok = req('POST', '/api/auth/login', {'account': 'RCT1', 'password': 'rc123456'})['token']

# 访客进入
i = req('POST', '/api/visitor/init', {'t': 'RCT1', 'name': '撤回测试', 'visitor_token': 'vt_rc1'})
sid = i['session_id']

# 客服发一条消息
r = req('POST', f'/api/sessions/{sid}/messages', {'text': '客服原话ABC'}, ttok)
mid_agent = r['message']['id']

# 访客发一条消息
r = req('POST', '/api/visitor/send', {'session_id': sid, 'text': '访客原话XYZ'})
mid_vis = r['message']['id']

# 客服撤回自己的消息
req('POST', f'/api/sessions/{sid}/recall', {'msg_id': mid_agent}, ttok)

# 访客撤回自己的消息
req('POST', '/api/visitor/recall', {'session_id': sid, 'msg_id': mid_vis})

print('=== 场景A：客服端视角（增量poll sinceMsg=0 之前已拉过，模拟状态同步缺失）===')
p = req('GET', f'/api/poll?role=agent&session={sid}&sinceMsg={mid_vis}', token=ttok)
print('  增量poll返回 recalledIds:', p.get('recalledIds', '【无此字段=撤回状态不同步bug确认】'))

print('=== 场景B：访客端视角（全量拉取，检查客服撤回/自己撤回的展示数据）===')
m = req('GET', f'/api/visitor/messages?session_id={sid}')
for msg in m['messages']:
    if msg['id'] in (mid_agent, mid_vis):
        print(f"  id={msg['id']} from={msg['from']} text=[{msg['text']}] recalled={msg.get('recalled')}")
print('  访客poll返回 recalledIds 缺失检查:')
p = req('GET', f'/api/poll?session={sid}&sinceMsg=0')
print('  recalledIds:', p.get('recalledIds', '【无此字段】'))

print('=== 场景C：客服全量拉取，检查访客撤回后内容是否被清空（需求：应保留内容+标记）===')
m = req('GET', f'/api/sessions/{sid}/messages', token=ttok)
for msg in m['messages']:
    if msg['id'] == mid_vis:
        print(f"  访客撤回后客服看到: text=[{msg['text']}] recalled={msg.get('recalled')}  （需求：text=访客原话XYZ + recalled=True）")
    if msg['id'] == mid_agent:
        print(f"  客服撤回后自己看到: text=[{msg['text']}] recalled={msg.get('recalled')}")

# 清理
tenants = req('GET', '/api/admin/tenants', token=atok)
for t in tenants:
    if t['kami'] == 'RCT1':
        req('DELETE', f"/api/admin/tenants/{t['id']}", token=atok)
print('已清理 RCT1')

