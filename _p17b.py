# -*- coding: utf-8 -*-
# app.html v3.1 剩余补丁（Python 精确处理）
import io, sys, re
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

f = r"D:\kfxt\kf\public\app.html"
c = open(f, encoding="utf-8").read()
ok = 0; total = 0

def rep(old, new, name):
    global c, ok, total
    total += 1
    if old in c:
        c = c.replace(old, new); ok += 1; print("[OK]", name)
    else:
        print("[MISS]", name)

# ---- 1. hero HTML 改版（找配对的 div 块） ----
start = c.find('<div class="side-hero" v-if="me">')
if start >= 0:
    # 手动配对 div
    i = start; depth = 0; end = -1
    while i < len(c):
        o = c.find('<div', i); cl = c.find('</div>', i)
        if o >= 0 and (o < cl or cl < 0): depth += 1; i = o + 4
        elif cl >= 0: depth -= 1; i = cl + 6
        else: break
        if depth == 0: end = i; break
    hero_old = c[start:end]
    hero_new = '''<div class="side-hero" v-if="me">
        <div class="hero-avatar">
          <img v-if="meAvatarUrl" :src="meAvatarUrl">
          <template v-else>{{ me.role === 'tenant' ? '👑' : '🎧' }}</template>
        </div>
        <div class="hero-online">在线</div>
      </div>'''
    c = c[:start] + hero_new + c[end:]
    ok += 1; print("[OK] hero HTML 改版, 旧块", len(hero_old), "字符")
else:
    print("[MISS] hero 起点")

# ---- 2. 菜单 emoji 删除（>emoji <span → ><span） ----
pat = re.compile(r'(nav-item"[^>]*>\s*)\S+\s+(<span class="nv-text")')
c2, n = pat.subn(r'\1\2', c)
if n: c = c2; ok += 1; print("[OK] 菜单 emoji 删除 x", n)
else: print("[WARN] 菜单 emoji 无匹配（可能已删）")

# ---- 3. 获客码页整体替换 ----
seg_start = c.find('<div class="content" v-if="tab===\'links\'')
if seg_start < 0:
    seg_start = c.find('获客链接 ============')
if seg_start >= 0:
    seg_end = c.find('<!-- ============ 子客服管理 ============ -->', seg_start)
    if seg_end < 0: seg_end = c.find('============ 坐席管理 ============', seg_start)
    if seg_end < 0:
        # 找下一个 content 块开头
        seg_end = c.find('<div class="content"', seg_start + 50)
    if seg_end > seg_start:
        new_block = '''<!-- ============ 获客码（统一码） ============ -->
      <div class="content" v-if="tab==='links' && me.role==='tenant'">
        <div class="code-page-card">
          <div class="code-tabs">
            <div class="code-tab active">正式码</div>
          </div>
          <div class="code-body">
            <div class="code-img-panel">
              <div class="cip-title">码图像</div>
              <div class="qr-stage">
                <div class="qr-headline">{{ me.nickname || '客服' }}</div>
                <div class="qr-wrap" ref="qrWrap">
                  <img v-if="mainQrUrl" :src="mainQrUrl">
                  <div class="qr-logo" v-if="codeCfg.logoType !== 'none'">
                    <img v-if="codeCfg.logoType==='avatar' && codeLogoImg" :src="codeLogoImg">
                    <template v-else>{{ codeLogoEmoji }}</template>
                  </div>
                </div>
                <div class="qr-footnote">支持微信、支付宝、淘宝、浏览器等通道扫码进入</div>
              </div>
              <div class="cip-btns">
                <el-button size="small" @click="copyQrImage">复制二维码</el-button>
                <el-button size="small" @click="downloadQrImage">下载二维码</el-button>
              </div>
              <div class="cip-note">注意：请不要截整个手机扫码，避免码死的太快!</div>
            </div>
            <div class="code-set-panel">
              <div class="cip-title">码设置</div>
              <div class="cs-row"><span class="cs-label">码头像：</span>
                <el-radio-group v-model="codeCfg.logoType" size="small" @change="saveCodeCfg">
                  <el-radio-button value="avatar">头像</el-radio-button>
                  <el-radio-button value="wechat">微信</el-radio-button>
                  <el-radio-button value="alipay">支付宝</el-radio-button>
                  <el-radio-button value="mix">混合</el-radio-button>
                </el-radio-group>
              </div>
              <div class="cs-row"><span class="cs-label">码设置：</span>
                <span style="margin-right:14px;">昵称显示：<el-switch v-model="codeCfg.nicknameOn" @change="saveCodeCfg"/></span>
                <span>支持显示：<el-switch v-model="codeCfg.supportOn" @change="saveCodeCfg"/></span>
              </div>
              <div class="cs-row"><span class="cs-label">码链接：</span>
                <el-button size="small" type="primary" plain @click="copy(currentLink)">复制链接</el-button>
                <span class="mono" style="font-size:12px;color:#666;word-break:break-all;">{{ currentLink || '尚未生成' }}</span>
              </div>
              <div class="cs-row"><span class="cs-label">码状态：</span><span style="color:#13c56b;">正常，新老客户均可访问</span></div>
              <div class="cs-row"><span class="cs-label">码到期：</span><span>{{ expireText }}</span></div>
              <div class="cs-btns">
                <el-popconfirm :title="codeRotateHint" @confirm="rotateCode">
                  <template #reference><el-button type="primary">更换码</el-button></template>
                </el-popconfirm>
                <el-button type="success" @click="manageDlg = true; loadHistory()">管理码</el-button>
              </div>
            </div>
          </div>
        </div>
      </div>

'''
        c = c[:seg_start] + new_block + c[seg_start if False else seg_end:]
        ok += 1; print("[OK] 获客码页整体替换")
    else:
        print("[MISS] 获客码页 结束锚点")
else:
    print("[MISS] 获客码页 起始锚点")

# ---- 4. 管理码弹窗 + CSS + JS ----
anchor = '  <!-- 图片预览 -->'
if anchor in c:
    manage = '''  <!-- 管理码：历史记录 -->
  <el-dialog v-model="manageDlg" title="管理码 - 历史记录" width="760px">
    <div class="panel" style="margin-bottom:12px;">
      <div class="panel-title" style="font-size:14px;">历史客服码</div>
      <el-table :data="history.codes || []" size="small" stripe max-height="240">
        <el-table-column prop="code" label="码" width="120"><template #default="{row}"><span class="mono">{{ row.code }}</span></template></el-table-column>
        <el-table-column prop="link" label="链接" min-width="200" show-overflow-tooltip/>
        <el-table-column label="创建时间" width="150"><template #default="{row}">{{ (row.created_at||'').slice(0,16) }}</template></el-table-column>
        <el-table-column label="操作" width="90">
          <template #default="{row}">
            <el-popconfirm title="作废该码？" @confirm="delCode(row)"><template #reference><el-button size="small" type="danger" plain>作废</el-button></template></el-popconfirm>
          </template>
        </el-table-column>
      </el-table>
    </div>
    <div class="panel" style="margin-bottom:0;">
      <div class="panel-title" style="font-size:14px;">历史专属链接</div>
      <el-table :data="history.links || []" size="small" stripe max-height="200">
        <el-table-column prop="subdomain" label="子域" width="130"><template #default="{row}"><span class="mono">{{ row.subdomain }}</span></template></el-table-column>
        <el-table-column prop="link" label="链接" min-width="220" show-overflow-tooltip/>
        <el-table-column label="状态" width="80">
          <template #default="{row}"><el-tag size="small" :type="row.active?'success':'info'">{{ row.active?'使用中':'已作废' }}</el-tag></template>
        </el-table-column>
        <el-table-column label="创建时间" width="150"><template #default="{row}">{{ (row.created_at||'').slice(0,16) }}</template></el-table-column>
        <el-table-column label="操作" width="90">
          <template #default="{row}">
            <el-popconfirm v-if="row.active" title="作废该链接？" @confirm="disableLink(row)">
              <template #reference><el-button size="small" type="danger" plain>作废</el-button></template>
            </el-popconfirm>
            <span v-else class="muted" style="font-size:12px;">—</span>
          </template>
        </el-table-column>
      </el-table>
    </div>
  </el-dialog>

  <!-- 图片预览 -->'''
    c = c.replace(anchor, manage, 1)
    ok += 1; print("[OK] 管理码弹窗")
else:
    print("[MISS] 管理码弹窗锚点")

open(f, "w", encoding="utf-8").write(c)
print(f"\n第二批完成: {ok}/{total}")
