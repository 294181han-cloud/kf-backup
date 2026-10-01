# -*- coding: utf-8 -*-
# app.html v3.1 第三批：管理码弹窗 + CSS + JS + 导出
import io, sys
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

f = r"D:\kfxt\kf\public\app.html"
c = open(f, encoding="utf-8").read()
ok = 0; total = 0

def rep(old, new, name, count=1):
    global c, ok, total
    total += 1
    if old in c:
        c = c.replace(old, new, count); ok += 1; print("[OK]", name)
    else:
        print("[MISS]", name, "!!!")

# ---- 1. 管理码弹窗（插在 img-viewer 前） ----
anchor = '<div class="img-viewer" v-if="viewing" @click="viewing = \'\'">'
if anchor in c and '管理码 - 历史记录' not in c:
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

  <div class="img-viewer" v-if="viewing" @click="viewing = ''">'''
    c = c.replace(anchor, manage, 1)
    ok += 1; print("[OK] 管理码弹窗")
else:
    print("[SKIP] 管理码弹窗（已存在或锚点缺失）")

# ---- 2. 获客码 CSS ----
css_anchor = "/* 快捷条：布局内占位（不遮挡），位于工具栏上方，向右展开 */"
if css_anchor in c and "code-page-card" not in c:
    css_new = '''/* 获客码页（对标截图） */
.code-page-card { background: #fff; border-radius: 14px; padding: 24px; border: 1px solid #eeeaea; }
.code-tabs { display: flex; justify-content: center; border-bottom: 1px solid #eeeaea; margin-bottom: 24px; }
.code-tab { padding: 12px 28px; font-size: 15px; cursor: pointer; color: #666; position: relative; }
.code-tab.active { color: #1890ff; font-weight: 600; }
.code-tab.active::after { content: ''; position: absolute; left: 20%; right: 20%; bottom: -1px; height: 2px; background: #1890ff; }
.code-body { display: flex; gap: 24px; flex-wrap: wrap; }
.code-img-panel, .code-set-panel { flex: 1; min-width: 300px; background: #f8fafcd2; border-radius: 12px; padding: 16px; }
.cip-title { font-size: 15px; font-weight: 600; margin-bottom: 14px; }
.qr-stage { background: #fff; border: 1px solid #eeeaea; border-radius: 10px; padding: 14px; text-align: center; }
.qr-headline { color: #1890ff; font-weight: 600; margin-bottom: 8px; }
.qr-wrap { position: relative; display: inline-block; }
.qr-wrap img { width: 210px; height: 210px; display: block; }
.qr-logo {
  position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
  width: 46px; height: 46px; border-radius: 10px; background: #fff;
  display: flex; align-items: center; justify-content: center; font-size: 26px;
  box-shadow: 0 0 0 4px #fff;
}
.qr-logo img { width: 100%; height: 100%; object-fit: cover; border-radius: 8px; }
.qr-footnote { font-size: 11px; color: #999; margin-top: 8px; }
.cip-btns { text-align: center; margin-top: 12px; }
.cip-note { color: #f53f3f; font-size: 12px; text-align: center; margin-top: 10px; }
.cs-row { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.cs-label { width: 66px; color: #666; font-size: 13px; flex-shrink: 0; }
.cs-btns { display: flex; justify-content: space-between; margin-top: 20px; }

/* 快捷条：布局内占位（不遮挡），位于工具栏上方，向右展开 */'''
    c = c.replace(css_anchor, css_new, 1)
    ok += 1; print("[OK] 码页 CSS")
else:
    print("[SKIP] 码页 CSS（已存在或锚点缺失）")

# ---- 3. JS 逻辑 ----
js_anchor = "    // 顶栏时钟 + 未读总数"
if js_anchor in c and "const manageDlg" not in c:
    js_new = '''    // ===== 获客码页（统一码） =====
    const manageDlg = ref(false);
    const history = ref({ links: [], codes: [] });
    const codeCfg = reactive({ logoType: 'wechat', nicknameOn: true, supportOn: true });
    const mainQrUrl = ref('');
    const qrWrap = ref(null);
    const codeLogoEmoji = Vue.computed(() => ({
      wechat: '💚', alipay: '🔵', mix: ['💚','🔵','🎲'][Math.floor(Math.random()*3)],
    }[codeCfg.logoType] || '💚'));
    const codeLogoImg = Vue.computed(() => codeCfg.logoType === 'avatar' ? ((me.value && me.value.avatar) || '') : '');
    const currentLink = Vue.computed(() => {
      if (!links.value) return '';
      if (links.value.domain_link && links.value.domain_link.active) return links.value.domain_link.link;
      const cs = (links.value.codes || []).filter(x => x.link);
      return cs.length ? cs[0].link : '';
    });
    const renderMainQr = () => {
      nextTick(() => {
        if (!qrWrap.value || !currentLink.value || !window.QRCode) return;
        qrWrap.value.innerHTML = '';
        try { new QRCode(qrWrap.value, { text: currentLink.value, width: 210, height: 210, correctLevel: QRCode.CorrectLevel.M }); } catch (e) {}
      });
    };
    Vue.watch([currentLink, () => codeCfg.logoType], renderMainQr);
    const copyQrImage = async () => {
      try {
        const cv = qrWrap.value.querySelector('canvas') || qrWrap.value.querySelector('img');
        if (!cv) return;
        const url = cv.toDataURL ? cv.toDataURL() : cv.src;
        const blob = await (await fetch(url)).blob();
        await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
        ElementPlus.ElMessage.success('二维码已复制到剪贴板');
      } catch (e) { ElementPlus.ElMessage.warning('复制失败，请使用下载功能'); }
    };
    const downloadQrImage = () => {
      const cv = qrWrap.value.querySelector('canvas') || qrWrap.value.querySelector('img');
      if (!cv) return;
      const url = cv.toDataURL ? cv.toDataURL() : cv.src;
      const a = document.createElement('a');
      a.href = url; a.download = 'qrcode-' + Date.now() + '.png';
      document.body.appendChild(a); a.click(); a.remove();
    };
    const codeRotateHint = Vue.computed(() =>
      (links.value && links.value.domain_link) ? '更换后旧链接立即失效，确定？' : '生成一个新的客服码？');
    const rotateCode = async () => {
      try {
        if (links.value && links.value.domain_link) await API.post('/api/tenant/link/renew');
        else await API.post('/api/tenant/service-code');
        ElementPlus.ElMessage.success('已更换');
        await loadLinks(); renderMainQr();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const loadHistory = async () => {
      try { history.value = await API.get('/api/tenant/link/history'); } catch (e) {}
    };
    const disableLink = async (row) => {
      try { await API.post('/api/tenant/link/disable/' + row.id); ElementPlus.ElMessage.success('已作废'); await loadHistory(); await loadLinks(); }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const saveCodeCfg = async () => {
      try { await API.put('/api/tenant/settings', { code_logo_type: codeCfg.logoType, code_nickname_on: codeCfg.nicknameOn, code_support_on: codeCfg.supportOn }); }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
    };

    // 顶栏时钟 + 未读总数'''
    c = c.replace(js_anchor, js_new, 1)
    ok += 1; print("[OK] JS 码逻辑")
else:
    print("[SKIP] JS 码逻辑")

# ---- 4. loadBrand 同步 codeCfg ----
lb_old = "        codeCfg.logoType = b.code_logo_type || 'wechat';"
if lb_old in c:
    print("[SKIP] loadBrand 已同步")
else:
    lb_anchor = "        brand.tpl_nav_on = b.tpl_nav_on !== false; brand.tpl_nav_title = b.tpl_nav_title || '';"
    if lb_anchor in c:
        c = c.replace(lb_anchor, lb_anchor + "\n        codeCfg.logoType = b.code_logo_type || 'wechat';\n        codeCfg.nicknameOn = b.code_nickname_on !== false;\n        codeCfg.supportOn = b.code_support_on !== false;", 1)
        ok += 1; print("[OK] loadBrand 同步")
    else:
        print("[MISS] loadBrand 锚点")

# ---- 5. loadLinks 刷新主码 ----
ll_old = "renderCodeQrs(); } catch (e) {} };"
if "renderMainQr(); } catch (e) {} };" in c:
    print("[SKIP] loadLinks 已刷新")
elif ll_old in c:
    c = c.replace(ll_old, "renderCodeQrs(); renderMainQr(); } catch (e) {} };", 1)
    ok += 1; print("[OK] loadLinks 刷新")
else:
    print("[MISS] loadLinks")

# ---- 6. meAvatarUrl 计算 ----
if "const meAvatarUrl" in c:
    print("[SKIP] meAvatarUrl 已存在")
else:
    ma_anchor = "    const unreadTotal = Vue.computed(() => sessions.value.reduce((a, s) => a + (s.unread || 0), 0));"
    if ma_anchor in c:
        c = c.replace(ma_anchor, ma_anchor + "\n    const meAvatarUrl = Vue.computed(() => (me.value && me.value.avatar) || '');", 1)
        ok += 1; print("[OK] meAvatarUrl")
    else:
        print("[MISS] meAvatarUrl 锚点")

# ---- 7. return 导出 ----
ret_old = "      pol, savePolicy, uploadPolHead, clock, unreadTotal, sessCollapsed,"
ret_new = '''      pol, savePolicy, uploadPolHead, clock, unreadTotal, sessCollapsed,
      manageDlg, history, codeCfg, mainQrUrl, qrWrap, codeLogoEmoji, codeLogoImg, currentLink, renderMainQr,
      copyQrImage, downloadQrImage, codeRotateHint, rotateCode, loadHistory, disableLink, saveCodeCfg, meAvatarUrl,'''
if ret_old in c:
    if "meAvatarUrl," in c and "manageDlg, history" in c:
        print("[SKIP] return 已导出")
    else:
        c = c.replace(ret_old, ret_new, 1)
        ok += 1; print("[OK] return 导出")
else:
    print("[MISS] return 锚点")

open(f, "w", encoding="utf-8").write(c)
print(f"\n第三批完成: {ok}/{total}")
