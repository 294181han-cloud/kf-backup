# app.html v3.1 补丁：hero 头像改版 / 获客码页重做（对标截图）/ 菜单去表情 / 个人头像 1:1 裁剪
$f = "D:\kfxt\kf\public\app.html"
$c = Get-Content -Raw -Encoding UTF8 $f
$ok = 0; $total = 0
function Patch([string]$name, [string]$old, [string]$new) {
  $script:total++
  if ($script:c.Contains($old)) { $script:c = $script:c.Replace($old, $new); $script:ok++; Write-Host "[OK] $name" }
  else { Write-Host "[MISS] $name !!!" }
}

# ============ 1. hero 头像改版（圆头像 + 在线状态，对标截图左栏顶部） ============
Patch 'hero改版' @'
      <div class="side-hero" v-if="me">
        <div style="display:flex;align-items:center;gap:9px;margin-bottom:7px;">
          <span style="width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.15);display:inline-flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;">{{ me.role === 'tenant' ? '👑' : '🎧' }}</span>
          <div class="hero-name" style="margin:0;">{{ me.nickname || me.kami }}</div>
        </div>
        <div class="hero-meta">
          <span class="hero-badge" :class="me.role">{{ me.role === 'tenant' ? '卡密主人' : '子客服' }}</span>
          <span class="mono hero-kami">{{ me.role === 'tenant' ? me.kami : (me.kami + '/' + me.username) }}</span>
        </div>
        <div class="hero-expire">{{ expireText }}</div>
      </div>
'@ @'
      <div class="side-hero" v-if="me">
        <div class="hero-avatar">
          <img v-if="meAvatarUrl" :src="meAvatarUrl">
          <template v-else>{{ me.role === 'tenant' ? '👑' : '🎧' }}</template>
        </div>
        <div class="hero-online">在线</div>
      </div>
'@
Patch 'hero样式' @'
.hero-name { color: #333; font-size: 14px; font-weight: 700; margin-bottom: 5px; }
'@ @'
.hero-avatar {
  width: 44px; height: 44px; border-radius: 50%; margin: 0 auto 6px;
  background: linear-gradient(135deg,#1890ff,#4d9bff);
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; overflow: hidden; position: relative;
  border: 2px solid #fff; box-shadow: 0 2px 8px rgba(24,144,255,.25);
}
.hero-avatar img { width: 100%; height: 100%; object-fit: cover; }
.hero-online { font-size: 11px; color: #13c56b; }
'@
Patch 'hero移除旧meta' @'
        <div class="hero-meta">
          <span class="hero-badge" :class="me.role">{{ me.role === 'tenant' ? '卡密主人' : '子客服' }}</span>
          <span class="mono hero-kami">{{ me.role === 'tenant' ? me.kami : (me.kami + '/' + me.username) }}</span>
        </div>
        <div class="hero-expire">{{ expireText }}</div>
'@ @'
'@

# ============ 2. 菜单表情删除 ============
Patch '菜单去表情' @'
        <div class="nav-item" :class="{active: tab==='stats'}" @click="tab='stats'">📊 <span class="nv-text">数据面板</span></div>
        <div class="nav-item" :class="{active: tab==='work'}" @click="tab='work'; restartPoll()">💬 <span class="nv-text">工作台</span></div>
        <div class="nav-item" :class="{active: tab==='visitors'}" @click="tab='visitors'; loadVisitors()">📋 <span class="nv-text">访客列表</span></div>
'@ @'
        <div class="nav-item" :class="{active: tab==='stats'}" @click="tab='stats'; restartPoll()"><span class="nv-text">数据面板</span></div>
        <div class="nav-item" :class="{active: tab==='work'}" @click="tab='work'; restartPoll()"><span class="nv-text">工作台</span></div>
        <div class="nav-item" :class="{active: tab==='visitors'}" @click="tab='visitors'; loadVisitors()"><span class="nv-text">访客列表</span></div>
'@
Patch '菜单去表情2' @'
        <div class="nav-item" :class="{active: tab==='agents'}" @click="tab='agents'">👨‍💼 <span class="nv-text">坐席管理</span></div>
        <div class="nav-item" :class="{active: tab==='links'}" @click="tab='links'">🔗 <span class="nv-text">客服码</span></div>
'@ @'
        <div class="nav-item" :class="{active: tab==='agents'}" @click="tab='agents'"><span class="nv-text">坐席管理</span></div>
        <div class="nav-item" :class="{active: tab==='links'}" @click="tab='links'"><span class="nv-text">获客码</span></div>
'@
Patch '菜单去表情3' @'
        <div class="nav-item" :class="{active: tab==='quick'}" @click="tab='quick'">⚡ <span class="nv-text">快捷消息</span></div>
        <div class="nav-item" :class="{active: tab==='welcome'}" @click="tab='welcome'">👋 <span class="nv-text">欢迎语</span></div>
        <div class="nav-item" :class="{active: tab==='auto'}" @click="tab='auto'">🤖 <span class="nv-text">智能回复</span></div>
'@ @'
        <div class="nav-item" :class="{active: tab==='quick'}" @click="tab='quick'"><span class="nv-text">快捷消息</span></div>
        <div class="nav-item" :class="{active: tab==='welcome'}" @click="tab='welcome'"><span class="nv-text">欢迎语</span></div>
        <div class="nav-item" :class="{active: tab==='auto'}" @click="tab='auto'"><span class="nv-text">智能回复</span></div>
'@
Patch '菜单去表情4' @'
        <div class="nav-item" :class="{active: tab==='broadcast'}" @click="tab='broadcast'">📣 <span class="nv-text">群发模板</span></div>
'@ @'
        <div class="nav-item" :class="{active: tab==='broadcast'}" @click="tab='broadcast'"><span class="nv-text">群发模板</span></div>
'@
Patch '菜单去表情5' @'
        <div class="nav-item" :class="{active: tab==='blacklist'}" @click="tab='blacklist'">🚫 <span class="nv-text">IP黑名单</span></div>
'@ @'
        <div class="nav-item" :class="{active: tab==='blacklist'}" @click="tab='blacklist'"><span class="nv-text">IP黑名单</span></div>
'@
Patch '菜单去表情6' @'
        <div class="nav-item" :class="{active: tab==='brand'}" @click="tab='brand'">🎨 <span class="nv-text">访客端模板</span></div>
        <div class="nav-item" :class="{active: tab==='preinq'}" @click="tab='preinq'">📝 <span class="nv-text">询前表单</span></div>
        <div class="nav-item" :class="{active: tab==='policy'}" @click="tab='policy'">🧩 <span class="nv-text">访客策略</span></div>
        <div class="nav-item" :class="{active: tab==='security'}" @click="tab='security'; loadSecurityPage()">🛡️ <span class="nv-text">安全与维护</span></div>
'@ @'
        <div class="nav-item" :class="{active: tab==='brand'}" @click="tab='brand'"><span class="nv-text">访客端模板</span></div>
        <div class="nav-item" :class="{active: tab==='preinq'}" @click="tab='preinq'"><span class="nv-text">询前表单</span></div>
        <div class="nav-item" :class="{active: tab==='policy'}" @click="tab='policy'"><span class="nv-text">访客策略</span></div>
        <div class="nav-item" :class="{active: tab==='security'}" @click="tab='security'; loadSecurityPage()"><span class="nv-text">安全与维护</span></div>
'@
Patch '菜单去表情7' @'
        <div class="nav-item" :class="{active: tab==='profile'}" @click="tab='profile'">👤 <span class="nv-text">个人设置</span></div>
'@ @'
        <div class="nav-item" :class="{active: tab==='profile'}" @click="tab='profile'"><span class="nv-text">个人设置</span></div>
'@
# tabTitle 文案同步
Patch 'tabTitle获客码' "links: '客服码'," "links: '获客码',"

# ============ 3. 获客码页重做（对标截图：统一码 + 码图像 + 码设置 + 管理码） ============
Patch '获客码页重做' @'
      <!-- ============ 获客链接 ============ -->
      <div class="content" v-if="tab==='links' && me.role==='tenant'">
        <div class="panel">
          <div class="panel-title">专属链接（固定子域名）</div>
          <div v-if="links && links.domain_link" class="link-line">
            <span style="flex:1;" class="mono">{{ links.domain_link.link }}</span>
            <el-button size="small" @click="copy(links.domain_link.link)">复制</el-button>
            <el-button size="small" @click="showQr(links.domain_link.link)">二维码</el-button>
            <el-popconfirm title="更换后旧链接立即失效，确定？" @confirm="renewLink">
              <template #reference><el-button size="small" type="warning">更换</el-button></template>
            </el-popconfirm>
          </div>
          <div v-else>
            <p class="muted" style="margin:0 0 10px;">
              <template v-if="links && links.root_domain">您还没有专属链接，点击生成：https://子域.{{ links.root_domain }}/visitor.html</template>
              <template v-else>平台尚未设置主域名，请联系平台管理员。</template>
            </p>
            <el-button type="primary" :disabled="!links || !links.root_domain" @click="createLink">生成专属链接</el-button>
          </div>
        </div>
        <div class="panel">
          <div class="panel-title" style="justify-content:flex-start;gap:12px;">
            客服码（可随时换码，旧码作废）
            <el-button size="small" type="primary" @click="newCode">＋ 一键换码</el-button>
          </div>
          <div v-for="c in (links ? links.codes : [])" :key="c.code" class="code-card">
            <div class="code-qr" :data-code="c.code"></div>
            <div style="flex:1;min-width:0;">
              <div class="mono" style="font-weight:600;font-size:16px;letter-spacing:1px;margin-bottom:6px;">{{ c.code }}</div>
              <div class="muted mono" style="word-break:break-all;font-size:12px;">{{ c.link }}</div>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
              <el-button size="small" @click="copy(c.link)">复制</el-button>
              <el-button size="small" @click="showQr(c.link)">海报</el-button>
              <el-popconfirm title="作废该客服码？" @confirm="delCode(c)">
                <template #reference><el-button size="small" type="danger" plain>作废</el-button></template>
              </el-popconfirm>
            </div>
          </div>
          <div v-if="links && !links.codes.length" class="muted">还没有客服码，点击上方「一键换码」生成。</div>
        </div>
      </div>
'@ @'
      <!-- ============ 获客码（统一码，对标截图） ============ -->
      <div class="content" v-if="tab==='links' && me.role==='tenant'">
        <div class="code-page-card">
          <div class="code-tabs">
            <div class="code-tab active">正式码</div>
          </div>
          <div class="code-body">
            <!-- 左：码图像 -->
            <div class="code-img-panel">
              <div class="cip-title">码图像</div>
              <div class="qr-stage">
                <div class="qr-headline">{{ codeCfg.headText || '客服' }}</div>
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
              <div class="cip-note">注意：请不要录整个手机扫码，避免码死的太快!</div>
            </div>
            <!-- 右：码设置 -->
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
'@

# ============ 4. 管理码弹窗（历史码列表 + 作废） ============
Patch '管理码弹窗' @'
  <!-- 图片预览 -->
  <div class="img-viewer" v-if="viewing" @click="viewing = ''">
'@ @'
  <!-- 管理码：历史记录 -->
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

  <!-- 图片预览 -->
  <div class="img-viewer" v-if="viewing" @click="viewing = ''">
'@

# ============ 5. 获客码 CSS ============
Patch '码页CSS' @'
/* 快捷条：布局内占位（不遮挡），位于工具栏上方，向右展开 */
'@ @'
/* 获客码页（对标截图） */
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
.recall-flag-v { margin-left: 8px; }

/* 快捷条：布局内占位（不遮挡），位于工具栏上方，向右展开 */
'@

# ============ 6. JS：codeCfg/主码/历史/复制下载图片 ============
Patch 'JS码逻辑' @'
    // 顶栏时钟 + 未读总数
'@ @'
    // ===== 获客码页（统一码） =====
    const manageDlg = ref(false);
    const history = ref({ links: [], codes: [] });
    const codeCfg = reactive({ logoType: 'wechat', nicknameOn: true, supportOn: true, headText: '客服' });
    const mainQrUrl = ref('');
    const qrWrap = ref(null);
    const codeLogoEmoji = Vue.computed(() => ({
      wechat: '💚', alipay: '🔵', mix: ['💚','🔵','🎲'][Math.floor(Math.random()*3)],
    }[codeCfg.logoType] || '💚'));
    const codeLogoImg = Vue.computed(() => codeCfg.logoType === 'avatar' ? (me.value && me.value.avatar) || '' : '');
    const currentLink = Vue.computed(() => {
      if (!links.value) return '';
      if (links.value.domain_link && links.value.domain_link.active) return links.value.domain_link.link;
      const c = (links.value.codes || []).find(x => x.link);
      return c ? c.link : '';
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
        const canvas = qrWrap.value.querySelector('canvas') || qrWrap.value.querySelector('img');
        if (!canvas) return;
        const url = canvas.toDataURL ? canvas.toDataURL() : canvas.src;
        const blob = await (await fetch(url)).blob();
        await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
        ElementPlus.ElMessage.success('二维码已复制到剪贴板');
      } catch (e) { ElementPlus.ElMessage.warning('复制失败，请使用下载功能'); }
    };
    const downloadQrImage = () => {
      const canvas = qrWrap.value.querySelector('canvas') || qrWrap.value.querySelector('img');
      if (!canvas) return;
      const url = canvas.toDataURL ? canvas.toDataURL() : canvas.src;
      const a = document.createElement('a');
      a.href = url; a.download = 'qrcode-' + Date.now() + '.png';
      document.body.appendChild(a); a.click(); a.remove();
    };
    const codeRotateHint = Vue.computed(() =>
      (links.value && links.value.domain_link) ? '更换后旧链接立即失效，确定？' : '生成一个新的客服码？');
    const rotateCode = async () => {
      try {
        if (links.value && links.value.domain_link) await API.post('/api/tenant/link/renew');
        else { await API.post('/api/tenant/service-code'); }
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

    // 顶栏时钟 + 未读总数
'@
# loadBrand/loadMe 同步 codeCfg 与头像
Patch 'loadBrand同步码设置' @'
        brand.tpl_nav_on = b.tpl_nav_on !== false; brand.tpl_nav_title = b.tpl_nav_title || '';
'@ @'
        brand.tpl_nav_on = b.tpl_nav_on !== false; brand.tpl_nav_title = b.tpl_nav_title || '';
        codeCfg.logoType = b.code_logo_type || 'wechat';
        codeCfg.nicknameOn = b.code_nickname_on !== false;
        codeCfg.supportOn = b.code_support_on !== false;
'@
# (loadMe头像 段已移除：无需修改，loadMe 自动带 avatar)
Patch 'loadLinks刷新' "    const loadLinks = async () => { try { links.value = await API.get('/api/tenant/links'); renderCodeQrs(); } catch (e) {} };" "    const loadLinks = async () => { try { links.value = await API.get('/api/tenant/links'); renderCodeQrs(); renderMainQr(); } catch (e) {} };"
    const loadLinks = async () => { try { links.value = await API.get('/api/tenant/links'); renderCodeQrs(); } catch (e) {} };
'@ @'
    const loadLinks = async () => { try { links.value = await API.get('/api/tenant/links'); renderCodeQrs(); renderMainQr(); } catch (e) {} };
'@

# ============ 7. return 导出 ============
Patch 'return导出码' "      pol, savePolicy, uploadPolHead, clock, unreadTotal, sessCollapsed," "      pol, savePolicy, uploadPolHead, clock, unreadTotal, sessCollapsed,`n      manageDlg, history, codeCfg, mainQrUrl, qrWrap, codeLogoEmoji, codeLogoImg, currentLink, renderMainQr,`n      copyQrImage, downloadQrImage, codeRotateHint, rotateCode, loadHistory, disableLink, saveCodeCfg, meAvatarUrl,"

[System.IO.File]::WriteAllText($f, $c, (New-Object System.Text.UTF8Encoding $false))
Write-Host "补丁完成: $ok / $total"
