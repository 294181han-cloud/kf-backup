# -*- coding: utf-8 -*-
# app.html v3.1 补丁（Python 版）
import io, sys, re
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

f = r"D:\kfxt\kf\public\app.html"
c = open(f, encoding="utf-8").read()
ok = 0; total = 0

def patch(name, old, new):
    global c, ok, total
    total += 1
    if old in c:
        c = c.replace(old, new); ok += 1; print("[OK]", name)
    else:
        print("[MISS]", name, "!!!")

# 1. hero 改版
patch('hero改版', '''      <div class="side-hero" v-if="me">
        <div style="display:flex;align-items:center;gap:9px;margin-bottom:7px;">
          <span style="width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.15);display:inline-flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;">{{ me.role === 'tenant' ? '👑' : '🎧' }}</span>
          <div class="hero-name" style="margin:0;">{{ me.nickname || me.kami }}</div>
        </div>
        <div class="hero-meta">
          <span class="hero-badge" :class="me.role">{{ me.role === 'tenant' ? '卡密主人' : '子客服' }}</span>
          <span class="mono hero-kami">{{ me.role === 'tenant' ? me.kami : (me.kami + '/' + me.username) }}</span>
        </div>
        <div class="hero-expire">{{ expireText }}</div>
      </div>''', '''      <div class="side-hero" v-if="me">
        <div class="hero-avatar">
          <img v-if="meAvatarUrl" :src="meAvatarUrl">
          <template v-else>{{ me.role === 'tenant' ? '👑' : '🎧' }}</template>
        </div>
        <div class="hero-online">在线</div>
      </div>''')

patch('hero样式', '''.hero-name { color: #333; font-size: 14px; font-weight: 700; margin-bottom: 5px; }''',
'''.hero-avatar {
  width: 44px; height: 44px; border-radius: 50%; margin: 0 auto 6px;
  background: linear-gradient(135deg,#1890ff,#4d9bff);
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; overflow: hidden; position: relative;
  border: 2px solid #fff; box-shadow: 0 2px 8px rgba(24,144,255,.25);
}
.hero-avatar img { width: 100%; height: 100%; object-fit: cover; }
.hero-online { font-size: 11px; color: #13c56b; }''')

patch('hero移除旧meta', '''        <div class="hero-meta">
          <span class="hero-badge" :class="me.role">{{ me.role === 'tenant' ? '卡密主人' : '子客服' }}</span>
          <span class="mono hero-kami">{{ me.role === 'tenant' ? me.kami : (me.kami + '/' + me.username) }}</span>
        </div>
        <div class="hero-expire">{{ expireText }}</div>''', '')

# 2. 菜单去表情（7 处）
patch('菜单1', '''        <div class="nav-item" :class="{active: tab==='stats'}" @click="tab='stats'">📊 <span class="nv-text">数据面板</span></div>
        <div class="nav-item" :class="{active: tab==='work'}" @click="tab='work'; restartPoll()">💬 <span class="nv-text">工作台</span></div>
        <div class="nav-item" :class="{active: tab==='visitors'}" @click="tab='visitors'; loadVisitors()">📋 <span class="nv-text">访客列表</span></div>''',
'''        <div class="nav-item" :class="{active: tab==='stats'}" @click="tab='stats'; restartPoll()"><span class="nv-text">数据面板</span></div>
        <div class="nav-item" :class="{active: tab==='work'}" @click="tab='work'; restartPoll()"><span class="nv-text">工作台</span></div>
        <div class="nav-item" :class="{active: tab==='visitors'}" @click="tab='visitors'; loadVisitors()"><span class="nv-text">访客列表</span></div>''')

patch('菜单2', '''        <div class="nav-item" :class="{active: tab==='agents'}" @click="tab='agents'">👨‍💼 <span class="nv-text">坐席管理</span></div>
        <div class="nav-item" :class="{active: tab==='links'}" @click="tab='links'">🔗 <span class="nv-text">客服码</span></div>''',
'''        <div class="nav-item" :class="{active: tab==='agents'}" @click="tab='agents'"><span class="nv-text">坐席管理</span></div>
        <div class="nav-item" :class="{active: tab==='links'}" @click="tab='links'"><span class="nv-text">获客码</span></div>''')

patch('菜单3', '''        <div class="nav-item" :class="{active: tab==='quick'}" @click="tab='quick'">⚡ <span class="nv-text">快捷消息</span></div>
        <div class="nav-item" :class="{active: tab==='welcome'}" @click="tab='welcome'">👋 <span class="nv-text">欢迎语</span></div>
        <div class="nav-item" :class="{active: tab==='auto'}" @click="tab='auto'">🤖 <span class="nv-text">智能回复</span></div>''',
'''        <div class="nav-item" :class="{active: tab==='quick'}" @click="tab='quick'"><span class="nv-text">快捷消息</span></div>
        <div class="nav-item" :class="{active: tab==='welcome'}" @click="tab='welcome'"><span class="nv-text">欢迎语</span></div>
        <div class="nav-item" :class="{active: tab==='auto'}" @click="tab='auto'"><span class="nv-text">智能回复</span></div>''')

patch('菜单4', '''        <div class="nav-item" :class="{active: tab==='broadcast'}" @click="tab='broadcast'">📣 <span class="nv-text">群发模板</span></div>''',
'''        <div class="nav-item" :class="{active: tab==='broadcast'}" @click="tab='broadcast'"><span class="nv-text">群发模板</span></div>''')

patch('菜单5', '''        <div class="nav-item" :class="{active: tab==='blacklist'}" @click="tab='blacklist'">🚫 <span class="nv-text">IP黑名单</span></div>''',
'''        <div class="nav-item" :class="{active: tab==='blacklist'}" @click="tab='blacklist'"><span class="nv-text">IP黑名单</span></div>''')

patch('菜单6', '''        <div class="nav-item" :class="{active: tab==='brand'}" @click="tab='brand'">🎨 <span class="nv-text">访客端模板</span></div>
        <div class="nav-item" :class="{active: tab==='preinq'}" @click="tab='preinq'">📝 <span class="nv-text">询前表单</span></div>
        <div class="nav-item" :class="{active: tab==='policy'}" @click="tab='policy'">🧩 <span class="nv-text">访客策略</span></div>
        <div class="nav-item" :class="{active: tab==='security'}" @click="tab='security'; loadSecurityPage()">🛡️ <span class="nv-text">安全与维护</span></div>''',
'''        <div class="nav-item" :class="{active: tab==='brand'}" @click="tab='brand'"><span class="nv-text">访客端模板</span></div>
        <div class="nav-item" :class="{active: tab==='preinq'}" @click="tab='preinq'"><span class="nv-text">询前表单</span></div>
        <div class="nav-item" :class="{active: tab==='policy'}" @click="tab='policy'"><span class="nv-text">访客策略</span></div>
        <div class="nav-item" :class="{active: tab==='security'}" @click="tab='security'; loadSecurityPage()"><span class="nv-text">安全与维护</span></div>''')

patch('菜单7', '''        <div class="nav-item" :class="{active: tab==='profile'}" @click="tab='profile'">👤 <span class="nv-text">个人设置</span></div>''',
'''        <div class="nav-item" :class="{active: tab==='profile'}" @click="tab='profile'"><span class="nv-text">个人设置</span></div>''')

patch('tabTitle', "links: '客服码',", "links: '获客码',")

open(f, "w", encoding="utf-8").write(c)
print(f"\n第一批完成: {ok}/{total}")
