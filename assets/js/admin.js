// admin.js - 平台管理台应用（由 public/admin.html 内联脚本提取）
const { createApp, ref, reactive, computed, onMounted } = Vue;
createApp({
  setup() {
    const tab = ref('overview');
    const booted = ref(false);
    const me = ref(null);
    const ov = ref(null);
    const tenants = ref([]);
    const domains = ref([]);
    const newDomain = ref('');
    const rootDomain = ref('');
    const defVal = reactive({ default_quota_web: 200, default_quota_app: 200, default_max_agents: 5 });
    const pwOld = ref(''); const pwNew = ref('');
    /* v2.90.10 批量发码弹窗状态 */
    const mintVisible = ref(false);
    const mintRow = ref(null);
    const mintSkipRed = ref(false);
    const mintReplace = ref(false);
    const mintBusy = ref(false);
    const mintResult = ref(null);
    /* v2.90.14 专属主机弹窗 */
    const hostVisible = ref(false);
    const hostRow = ref(null);
    const hostValue = ref('');
    const hostBusy = ref(false);
    const hostMsg = ref('');
    const hostOk = ref(true);

    /* v2.90.99 全国线路体检（数据来自 v2.90.98 的访客探针 line_probe） */
    const healthLoading = ref(false);
    const healthHours = ref(24);
    const healthHost = ref('');
    const healthHosts = ref([]);
    const healthDetail = ref([]);
    const healthNow = ref('');
    const loadHealth = async () => {
      healthLoading.value = true;
      try {
        let u = '/api/admin/line-health?hours=' + healthHours.value;
        if (healthHost.value) u += '&host=' + encodeURIComponent(healthHost.value.trim());
        const r = await API.get(u);
        healthHosts.value = r.hosts || [];
        healthDetail.value = r.detail || [];
        healthNow.value = r.now || '';
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { healthLoading.value = false; }
    };

    /* v2.91.25 门牌管理（门牌红了自动下架 + 后台增删启停）
       门牌 = 二维码第一跳的大厂托管站；红了该门牌下所有码都打不开，
       所以巡检红了立即 enabled=0，租户「换码」会自动换到别的门牌（落地域名不动）。 */
    const doors = ref([]);
    const doorNew = ref('');
    const doorLoading = ref(false);
    const doorBusy = ref(false);
    const doorChecking = ref(false);
    const doorsNow = ref('');
    const loadDoors = async () => {
      doorLoading.value = true;
      try {
        const r = await API.get('/api/admin/door-pool');
        doors.value = Array.isArray(r) ? r : [];
        doorsNow.value = new Date().toISOString();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { doorLoading.value = false; }
    };
    const addDoor = async () => {
      const d = (doorNew.value || '').trim();
      if (!d) { ElementPlus.ElMessage.warning('请输入门牌域名'); return; }
      doorBusy.value = true;
      try {
        await API.post('/api/admin/door-pool', { domain: d, enabled: 1 });
        ElementPlus.ElMessage.success('已新增门牌 ' + d);
        doorNew.value = '';
        await loadDoors();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { doorBusy.value = false; }
    };
    const toggleDoor = async (row, on) => {
      try {
        await API.put('/api/admin/door-pool/' + row.id, { enabled: on ? 1 : 0 });
        ElementPlus.ElMessage.success(on ? '已启用（认为已恢复）' : '已停用');
        await loadDoors();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const blockDoor = async (row) => {
      try {
        await ElementPlus.ElMessageBox.confirm(
          '确认「' + row.domain + '」在微信里已经打不开？\n会立即下架并记入红名单，之后换码不会再抽到它。',
          '人工判红', { type: 'warning', confirmButtonText: '确认已红', cancelButtonText: '取消' });
      } catch (e) { return; }
      try {
        await API.post('/api/admin/door-pool/' + row.id + '/block', {});
        ElementPlus.ElMessage.success('已下架并记入红名单');
        await loadDoors();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const delDoor = async (row) => {
      try {
        await ElementPlus.ElMessageBox.confirm('删除门牌「' + row.domain + '」？删掉后不再参与发码。', '删除门牌', { type: 'warning' });
      } catch (e) { return; }
      try {
        await API.del('/api/admin/door-pool/' + row.id);
        ElementPlus.ElMessage.success('已删除');
        await loadDoors();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const checkDoors = async () => {
      doorChecking.value = true;
      try {
        const r = await API.post('/api/admin/door-pool/check', {});
        const red = (r && r.red) || 0;
        ElementPlus.ElMessage[red ? 'warning' : 'success'](
          '巡检完成：共查 ' + ((r && r.checked) || 0) + ' 个门牌，其中 ' + red + ' 个被拦截' + (red ? '，已自动下架' : ''));
        await loadDoors();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { doorChecking.value = false; }
    };

    const tabTitle = computed(() => ({ overview: '平台概览', tenants: '租户管理', domains: '域名池', health: '线路体检', doors: '门牌管理', nodes: '节点管理', tg: '发卡机器人', settings: '全局设置' }[tab.value]));
    const locationOrigin = location.origin;

    const barWidth = (v, list) => {
      const max = Math.max(1, ...list.map(x => x.count ?? x.c));
      return Math.max(0, Math.round(v / max * 100)) + '%';
    };

    const loadOverview = async () => { ov.value = await API.get('/api/admin/overview'); };
    const loadTenants = async () => { tenants.value = await API.get('/api/admin/tenants'); };
    /* v2.77 「是否已到期」改成前端实时算：
       原来用的是列表接口返回的 expired 快照 —— 只有重新拉列表（刷新/增删改）才会变，
       所以卡密到点了、页面还显示"正常"，得手动刷新一下才对。
       现在每 30 秒重算一次 nowTick，配合 expires_at 实时判断，到点立刻变红。 */
    const nowTick = ref(Date.now());
    setInterval(() => { nowTick.value = Date.now(); }, 30000);
    const isExpired = (row) => {
      void nowTick.value;                       // 依赖它 → 每 30 秒触发一次重渲染
      const t = row && row.expires_at;
      if (!t) return false;
      const ts = new Date(String(t).replace(/-/g, '/')).getTime();
      return !!ts && ts < Date.now();
    };
    /* 管理员开着页面时也别看到旧数据：租户页每 60 秒自动拉一次（状态/到期/额度） */
    setInterval(() => {
      if (tab.value === 'tenants') { loadTenants(); loadOverview(); }
    }, 60000);
    const loadDomains = async () => { domains.value = await API.get('/api/admin/domain-pool'); };
    const loadSettings = async () => {
      const r = await API.get('/api/admin/root-domain'); rootDomain.value = r.root_domain;
      Object.assign(defVal, await API.get('/api/admin/defaults'));
    };
    // ---------- 发卡机器人（v2.68）----------
    const tg = ref(null);
    const tgCfg = reactive({ token: '', pay_addr: '', support: '', admins: '', ttl: 30, tron_key: '', enabled: true, dry: false, trial_on: true, trial_hours: 24 });
    const tgSaving = ref(false); const tgHooking = ref(false); const tgChecking = ref(false);
    const loadTg = async () => {
      const r = await API.get('/api/admin/tg');
      tg.value = r;
      tgCfg.token = r.cfg.token || '';
      tgCfg.pay_addr = r.cfg.pay_addr || '';
      tgCfg.support = r.cfg.support || '';
      tgCfg.admins = r.cfg.admins || '';
      tgCfg.ttl = r.cfg.ttl || 30;
      tgCfg.tron_key = r.cfg.tron_key || '';
      tgCfg.enabled = !!r.cfg.enabled;
      tgCfg.dry = !!r.cfg.dry;
      tgCfg.trial_on = !!r.cfg.trial_on;
      tgCfg.trial_hours = r.cfg.trial_hours || 24;
    };
    const saveTg = async () => {
      tgSaving.value = true;
      try {
        await API.post('/api/admin/tg/save', {
          token: tgCfg.token, pay_addr: tgCfg.pay_addr, support: tgCfg.support, admins: tgCfg.admins,
          ttl: tgCfg.ttl, tron_key: tgCfg.tron_key, enabled: tgCfg.enabled ? 1 : 0, dry: tgCfg.dry ? 1 : 0,
          trial_on: tgCfg.trial_on ? 1 : 0, trial_hours: tgCfg.trial_hours,
        });
        ElementPlus.ElMessage.success('已保存');
        await loadTg();
      } catch (e) { ElementPlus.ElMessage.error(e.message); } finally { tgSaving.value = false; }
    };
    const setTgWebhook = async () => {
      tgHooking.value = true;
      try {
        const r = await API.post('/api/admin/tg/webhook');
        if (r.ok) ElementPlus.ElMessage.success('Webhook 设置成功');
        else ElementPlus.ElMessage.error('设置失败：' + JSON.stringify(r.resp));
        await loadTg();
      } catch (e) { ElementPlus.ElMessage.error(e.message); } finally { tgHooking.value = false; }
    };
    const runTgCheck = async () => {
      tgChecking.value = true;
      try {
        const r = await API.post('/api/admin/tg/check');
        ElementPlus.ElMessage.success('核款完成，本轮到账发货 ' + r.delivered + ' 单');
        await loadTg();
      } catch (e) { ElementPlus.ElMessage.error(e.message); } finally { tgChecking.value = false; }
    };
    const pkgVisible = ref(false); const pkgSaving = ref(false);
    const pkgForm = reactive({ id: 0, name: '', days: 30, quota_web: 200, quota_app: 200, max_agents: 5, price: 9.9, badge: '', sort: 0, enabled: true });
    const openPkg = (row) => {
      Object.assign(pkgForm, row
        ? { id: row.id, name: row.name, days: row.days, quota_web: row.quota_web, quota_app: row.quota_app,
            max_agents: row.max_agents, price: Number(row.price), badge: row.badge, sort: row.sort, enabled: Number(row.enabled) === 1 }
        : { id: 0, name: '', days: 30, quota_web: 200, quota_app: 200, max_agents: 5, price: 9.9, badge: '', sort: 0, enabled: true });
      pkgVisible.value = true;
    };
    const savePkg = async () => {
      if (!pkgForm.name.trim()) { ElementPlus.ElMessage.warning('请填套餐名称'); return; }
      pkgSaving.value = true;
      try {
        await API.post('/api/admin/tg/pkg', Object.assign({}, pkgForm, { enabled: pkgForm.enabled ? 1 : 0 }));
        ElementPlus.ElMessage.success('已保存');
        pkgVisible.value = false;
        await loadTg();
      } catch (e) { ElementPlus.ElMessage.error(e.message); } finally { pkgSaving.value = false; }
    };
    const delPkg = async (row) => {
      try { await API.post('/api/admin/tg/pkg-delete?id=' + row.id); ElementPlus.ElMessage.success('已删除'); await loadTg(); }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const tgOrder = async (row, action) => {
      try {
        const r = await API.post('/api/admin/tg/order', { order_no: row.order_no, action: action });
        ElementPlus.ElMessage.success(action === 'deliver' ? ('已发货：' + (r.kami || '')) : '已取消');
        await loadTg();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const loadAll = async () => {
      try {
        me.value = await API.me();
        if (me.value.role !== 'admin') { location.href = '/login.html'; return; }
        await Promise.all([loadOverview(), loadTenants(), loadDomains(), loadReds(), loadSettings(), loadEntrances().catch(() => {})]);
        booted.value = true;
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    // 打开「发卡机器人」页签时才去拉数据（里面有 Telegram API 调用，别拖慢首屏）
    Vue.watch(tab, (v) => {
      if (v === 'tg') loadTg().catch(e => ElementPlus.ElMessage.error(e.message));
      if (v === 'nodes') {
        loadNodes().catch(e => ElementPlus.ElMessage.error(e.message));
        loadNodeCodes().catch(e => ElementPlus.ElMessage.error(e.message));
        loadGips().catch(e => ElementPlus.ElMessage.error(e.message));
        loadEntrances().catch(e => ElementPlus.ElMessage.error(e.message));
      }
    });

    // ===== 节点管理（1+2+n 分域部署） =====
    const nodes = ref([]);
    const mainVersion = ref('');
    const pushingIp = ref('');
    // ===== 客服登录入口（卡密交付文案：入口1、入口2…） =====
    const entrancesText = ref('');
    const entranceText = computed(() => {
      const urls = entrancesText.value.split('\n').map(s => s.trim()).filter(Boolean);
      if (!urls.length) return locationOrigin + '/login.html';
      return urls.map((u, i) => '入口' + (i + 1) + '：' + u).join('　');
    });
    const loadEntrances = async () => {
      const r = await API.get('/api/admin/agent-entrances');
      entrancesText.value = (r.urls || []).join('\n');
    };
    const saveEntrances = async () => {
      try {
        const urls = entrancesText.value.split('\n').map(s => s.trim()).filter(Boolean);
        const r = await API.put('/api/admin/agent-entrances', { urls });
        entrancesText.value = (r.urls || []).join('\n');
        ElementPlus.ElMessage.success('已保存');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const nodeCodes = ref([]);
    const newCodeRole = ref('visitor');
    const lastCode = ref('');
    const gips = ref([]);
    const newGip = ref('');
    const newGipNote = ref('');
    const gipVersion = ref(0);
    const loadNodes = async () => {
      const r = await API.get('/api/admin/nodes');
      nodes.value = r.nodes || [];
      mainVersion.value = r.main_version || '';
    };
    const loadNodeCodes = async () => {
      const r = await API.get('/api/admin/node-codes');
      nodeCodes.value = r.codes || [];
    };
    const genNodeCode = async () => {
      try {
        const r = await API.post('/api/admin/node-codes', { action: 'gen', role: newCodeRole.value });
        lastCode.value = r.code;
        await loadNodeCodes();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const revokeNodeCode = async (row) => {
      try {
        await API.post('/api/admin/node-codes', { action: 'revoke', code: row.code });
        await loadNodeCodes();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const loadGips = async () => {
      const r = await API.get('/api/admin/gblacklist');
      gips.value = r.ips || [];
      gipVersion.value = r.version || 0;
    };
    const addGip = async () => {
      if (!newGip.value.trim()) return;
      try {
        await API.post('/api/admin/gblacklist', { ip: newGip.value.trim(), note: newGipNote.value.trim() });
        newGip.value = ''; newGipNote.value = '';
        await loadGips();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const delGip = async (row) => {
      try {
        await API.del('/api/admin/gblacklist?ip=' + encodeURIComponent(row.ip));
        await loadGips();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const pushNode = async (row) => {
      pushingIp.value = row.ip;
      try {
        const r = await API.post('/api/admin/nodes', { action: 'push', ip: row.ip, role: row.role });
        /* v2.90.71 三种结果说人话：已是最新 / 推送失败 / 推送成功。
           原来「已是最新」和「推送失败」都落到成功分支，弹的是「已推送，节点版本 」，看着像没反应。 */
        if (r.need_cron) ElementPlus.ElMessage.warning(r.error || '请依赖节点 cron 自动更新');
        else if (r.up_to_date) { ElementPlus.ElMessage.info('这台已经是最新（' + (r.app_version || r.version || '') + '），无需推送'); await loadNodes(); }
        else if (r.ok === false) {
          /* v2.90.72 这类节点 nginx 把 /api/* 反代回总控，本就推不进去、靠自己拉更新；
             这种预期情况给蓝字说明，别再报红当成故障。 */
          const msg = String(r.error || '节点没有回应');
          if (msg.indexOf('反代回总控') >= 0) ElementPlus.ElMessage.info('这台节点靠自动更新跟随版本（节点 cron / 边缘自维护，约 5 分钟），不用手动推');
          else ElementPlus.ElMessage.error('推送失败：' + msg);
        }
        else { ElementPlus.ElMessage.success('已推送，节点版本 ' + (r.app_version || '')); await loadNodes(); }
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { pushingIp.value = ''; }
    };
    const dlBootstrap = () => { location.href = '/api/admin/bootstrap-dl'; };
    const forgetNode = async (row) => {
      try { await API.post('/api/admin/nodes', { action: 'forget', ip: row.ip, role: row.role }); await loadNodes(); }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
    };

    // 租户 CRUD
    const dlgVisible = ref(false);
    const dlgMode = ref('create');
    const dlgSaving = ref(false);
    const dlg = reactive({ id: 0, kami: '', nickname: '', expires_at: null, quota_web: 200, quota_app: 200, max_agents: 5, max_devices: 3, closed_keep_days: 3, archived_keep_days: 0, max_sessions: 1000, remark: '', password: '' });
    const createdVisible = ref(false);
    const created = reactive({ kami: '', password: '', note: '' });
    /* v2.56 一键测试卡：20 分钟后自动失效，专门用来验货/演示
       （卡密、密码都随机生成，额度走平台默认值；到期时间按浏览器本地时间算，和服务器同时区） */
    const openTrial = async () => {
      try {
        await ElementPlus.ElMessageBox.confirm(
          '立即创建一张 20 分钟后自动失效的测试卡？\n（随机卡密 + 随机密码，额度用平台默认值，到期后无法登录工作台）',
          '创建测试卡', { confirmButtonText: '创建', cancelButtonText: '取消', type: 'warning' });
      } catch (e) { return; }
      const pad = (n) => String(n).padStart(2, '0');
      const d = new Date(Date.now() + 20 * 60 * 1000);
      const exp = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
      try {
        const r = await API.post('/api/admin/tenants', {
          nickname: '测试卡', remark: '20 分钟测试卡（到期自动失效）', expires_at: exp,
        });
        created.kami = r.plain.kami;
        created.password = r.plain.password;
        created.note = '这是 20 分钟测试卡：' + exp + ' 到期，到期后无法登录工作台（想延长就在列表里「编辑 → 有效期」改一下）。';
        createdVisible.value = true;
        await loadTenants();
        await loadOverview();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const genVisible = ref(false);
    const genPair = ref(null);

    const openCreate = () => {
      dlgMode.value = 'create';
      Object.assign(dlg, { id: 0, kami: '', nickname: '', expires_at: null, quota_web: defVal.default_quota_web, quota_app: defVal.default_quota_app, max_agents: defVal.default_max_agents, max_devices: 3, closed_keep_days: 3, archived_keep_days: 0, max_sessions: 1000, remark: '', password: '' });
      dlgVisible.value = true;
    };
    const openEdit = (row) => {
      dlgMode.value = 'edit';
      Object.assign(dlg, { id: row.id, kami: row.kami, nickname: row.nickname, expires_at: row.expires_at, quota_web: row.quota_web, quota_app: row.quota_app, max_agents: row.max_agents, max_devices: (row.max_devices != null ? row.max_devices : 3), closed_keep_days: row.closed_keep_days != null ? row.closed_keep_days : 3, archived_keep_days: row.archived_keep_days != null ? row.archived_keep_days : 0, max_sessions: row.max_sessions != null ? row.max_sessions : 0, remark: row.remark, password: '' });
      dlgVisible.value = true;
    };
    const fillGenKami = async () => {
      try { const r = await API.post('/api/admin/tenants/gen'); dlg.kami = r.kami; } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const saveTenant = async () => {
      dlgSaving.value = true;
      try {
        if (dlgMode.value === 'create') {
          const r = await API.post('/api/admin/tenants', dlg);
        created.kami = r.plain.kami; created.password = r.plain.password;
        created.note = dlg.expires_at ? ('有效期至 ' + dlg.expires_at + '，到期后无法登录工作台。') : '';
          createdVisible.value = true;
        } else {
          await API.put('/api/admin/tenants/' + dlg.id, dlg);
          ElementPlus.ElMessage.success('已保存');
        }
        dlgVisible.value = false;
        await loadTenants();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { dlgSaving.value = false; }
    };
    const toggleTenant = async (row) => {
      try {
        await API.put('/api/admin/tenants/' + row.id, { status: row.status === 'active' ? 'banned' : 'active' });
        await loadTenants();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const delTenant = async (row) => {
      try { await API.del('/api/admin/tenants/' + row.id); ElementPlus.ElMessage.success('已删除'); await loadTenants(); await loadOverview(); }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    /* v2.90.10 批量发码：给这个租户在域名池每台域名上各发一条客服码（可以连红名单一起发），
       发完就在他自己工作台「管理码」里，想用哪条用哪条。 */
    const openMint = (row) => {
      mintRow.value = row; mintResult.value = null; mintSkipRed.value = false; mintReplace.value = false; mintVisible.value = true;
    };
    const doMint = async () => {
      if (!mintRow.value) return;
      mintBusy.value = true;
      try {
        const r = await API.post('/api/admin/tenants/' + mintRow.value.id + '/mint-codes',
          { skip_red: mintSkipRed.value ? 1 : 0, replace_all: mintReplace.value ? 1 : 0 });
        mintResult.value = r;
        ElementPlus.ElMessage.success('已发 ' + (r.created_count || 0) + ' 条客服码');
        await loadTenants();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { mintBusy.value = false; }
    };
    /* v2.90.14 专属主机：绑一台主机给这个账号（他的码都发这台），或者解绑 */
    const openHost = (row) => {
      hostRow.value = row; hostValue.value = row.content_host || ''; hostMsg.value = ''; hostOk.value = true;
      hostVisible.value = true;
    };
    const saveHost = async () => {
      if (!hostRow.value) return;
      hostBusy.value = true; hostMsg.value = '';
      try {
        const r = await API.post('/api/admin/tenants/' + hostRow.value.id + '/host', { host: hostValue.value.trim() });
        hostOk.value = true; hostMsg.value = r.msg || '已绑定';
        ElementPlus.ElMessage.success(r.msg || '已绑定');
        await loadTenants();
      } catch (e) { hostOk.value = false; hostMsg.value = e.message; }
      finally { hostBusy.value = false; }
    };
    const clearHost = async () => {
      if (!hostRow.value) return;
      hostBusy.value = true; hostMsg.value = '';
      try {
        const r = await API.post('/api/admin/tenants/' + hostRow.value.id + '/host', { host: '' });
        hostOk.value = true; hostMsg.value = r.msg || '已解绑'; hostValue.value = '';
        ElementPlus.ElMessage.success(r.msg || '已解绑');
        await loadTenants();
      } catch (e) { hostOk.value = false; hostMsg.value = e.message; }
      finally { hostBusy.value = false; }
    };
    const copyCreated = () => {
      const s = `卡密：${created.kami}\n密码：${created.password}\n登录入口：${entranceText.value}`;
      navigator.clipboard && navigator.clipboard.writeText(s);
      ElementPlus.ElMessage.success('已复制');
    };
    const openGen = async () => { genVisible.value = true; if (!genPair.value) await doGen(); };
    const doGen = async () => { try { genPair.value = await API.post('/api/admin/tenants/gen'); } catch (e) { ElementPlus.ElMessage.error(e.message); } };

    // 域名池
    const addDomain = async () => {
      if (!newDomain.value.trim()) return;
      try { await API.post('/api/admin/domain-pool', { domain: newDomain.value.trim() }); newDomain.value = ''; await loadDomains(); }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const toggleDomain = async (row) => {
      try { await API.put('/api/admin/domain-pool/' + row.id, { enabled: row.enabled ? 0 : 1 }); await loadDomains(); }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const delDomain = async (row) => {
      try { await API.del('/api/admin/domain-pool/' + row.id); await loadDomains(); }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    /* v2.73 人工判红：检测接口（麒麟/urlsec）测不出"微信客户端拦截"，
       所以拿微信实扫一遍，打不开的在这里标记一下 —— 停用 + 进红名单 + 通知租户换码。 */
    const blockDomain = async (row) => {
      try {
        const r = await API.post('/api/admin/domain-pool/' + row.id + '/block', {});
        ElementPlus.ElMessage.success('已标记并停用 ' + (r.domain || row.domain) + '（影响 ' + (r.affected || 0) + ' 个客服码，已通知对应租户）');
        await loadDomains(); if (typeof loadReds === 'function') await loadReds();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };

    // 子域明细 · 状态表：拉这个主域下每个子域的实时状态（红码/使用中/已作废/待分配）
    const subVisible = ref(false);
    const subRow = ref(null);
    const subRows = ref([]);
    const subLoading = ref(false);
    const showSubs = async (row) => {
      subRow.value = row; subVisible.value = true; subLoading.value = true; subRows.value = [];
      try { subRows.value = await API.get('/api/admin/domain-pool/subs?domain=' + encodeURIComponent(row.domain)); }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
      subLoading.value = false;
    };
    const copySubs = async () => {
      if (!subRows.value.length) return;
      const text = subRows.value.map(r => r.host).join('\n');
      try { await navigator.clipboard.writeText(text); ElementPlus.ElMessage.success('已复制 ' + subRows.value.length + ' 个子域'); }
      catch (e) { ElementPlus.ElMessage.error('复制失败，请手动全选表格复制'); }
    };

    // 已红域名名单（自动退役）：查看 + 恢复
    const reds = ref([]);
    const loadReds = async () => { try { reds.value = await API.get('/api/admin/red-domains'); } catch (e) {} };
    const restoreRed = async (row) => {
      try {
        await API.del('/api/admin/red-domains/' + row.id);
        ElementPlus.ElMessage.success('已恢复 ' + row.host);
        await Promise.all([loadReds(), loadDomains()]);
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };

    // aaPanel 快速导入（v2.61）：一个站点=主域+固定随机子域别名，一张 SAN 证书，写 v2.60.6 固定子域池
    const aaVisible = ref(false);
    const aaBusy = ref(false);
    const aaPhp = ref([]);
    const aaResult = ref(null);
    const aaTip = ref('');
    const aaTipType = ref('info');
    const aaForm = reactive({ panel: '', api_key: '', main_domain: '', sub_count: 64, sub_len: 8, php_version: '84' });
    const openAa = () => { aaVisible.value = true; aaResult.value = null; aaTip.value = ''; };
    const aaCheck = () => {
      if (!aaForm.panel.trim() || !aaForm.api_key.trim()) { ElementPlus.ElMessage.warning('请先填面板地址和 API 密钥'); return false; }
      if (!/^https?:\/\//i.test(aaForm.panel.trim())) { ElementPlus.ElMessage.warning('面板地址要以 http(s):// 开头'); return false; }
      return true;
    };
    const aaTest = async () => {
      if (!aaCheck()) return;
      aaBusy.value = true; aaTip.value = '';
      try {
        const r = await API.post('/api/admin/aa-test', { panel: aaForm.panel.trim(), api_key: aaForm.api_key.trim() });
        aaPhp.value = r.php || [];
        if (aaPhp.value.length && !aaPhp.value.includes(aaForm.php_version)) aaForm.php_version = aaPhp.value[aaPhp.value.length - 1];
        aaTip.value = '连接成功，可选 PHP 版本：' + (aaPhp.value.join(' / ') || '未获取到（可手动填，如 84）');
        aaTipType.value = 'success';
      } catch (e) {
        aaTip.value = e.message; aaTipType.value = 'error';
      }
      aaBusy.value = false;
    };
    const aaImport = async () => {
      if (!aaCheck()) return;
      if (!aaForm.main_domain.trim() || !aaForm.main_domain.trim().includes('.')) { ElementPlus.ElMessage.warning('请填主域名，例如 moupz.cn'); return; }
      aaBusy.value = true; aaTip.value = '导入中：建站 → 签证书（可能要 10~60 秒）→ 写域名池…'; aaTipType.value = 'info';
      try {
        const r = await API.post('/api/admin/aa-import', {
          panel: aaForm.panel.trim(), api_key: aaForm.api_key.trim(),
          main_domain: aaForm.main_domain.trim(), sub_count: aaForm.sub_count,
          sub_len: aaForm.sub_len, php_version: aaForm.php_version,
        });
        aaResult.value = r;
        if (r.cert_ok) { aaTip.value = '导入完成：站点+证书+子域池全部就绪'; aaTipType.value = 'success'; }
        else { aaTip.value = '站点和子域池已就绪，但证书没签成——确认泛解析已生效后点「开始导入」重试补签（建站是幂等的）'; aaTipType.value = 'warning'; }
        await loadDomains();
      } catch (e) {
        aaTip.value = e.message; aaTipType.value = 'error';
      }
      aaBusy.value = false;
    };

    // 设置
    const saveRootDomain = async () => {
      try { await API.post('/api/admin/root-domain', { root_domain: rootDomain.value }); ElementPlus.ElMessage.success('已保存'); }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const saveDefaults = async () => {
      try { await API.post('/api/admin/defaults', defVal); ElementPlus.ElMessage.success('已保存'); }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const changePw = async () => {
      if (!pwOld.value || !pwNew.value) { ElementPlus.ElMessage.warning('请输入旧密码和新密码'); return; }
      try { await API.changePassword(pwOld.value, pwNew.value); ElementPlus.ElMessage.success('密码已修改'); pwOld.value = pwNew.value = ''; me.value = await API.me(); }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const logout = () => { API.clearToken(); location.href = '/login.html'; };

    onMounted(loadAll);
    return {
      tab, tabTitle, booted, me, ov, tenants, domains, newDomain, rootDomain, defVal, pwOld, pwNew,
      aaVisible, aaBusy, aaPhp, aaResult, aaTip, aaTipType, aaForm, openAa, aaTest, aaImport,
      subVisible, subRow, subRows, subLoading, showSubs, copySubs,
      reds, loadReds, restoreRed,
      healthLoading, healthHours, healthHost, healthHosts, healthDetail, healthNow, loadHealth,
      nodes, mainVersion, pushingIp, loadNodes, pushNode, forgetNode, dlBootstrap,
      nodeCodes, newCodeRole, lastCode, genNodeCode, revokeNodeCode,
      gips, newGip, newGipNote, gipVersion, addGip, delGip,
      entrancesText, entranceText, saveEntrances,
      tg, tgCfg, tgSaving, tgHooking, tgChecking, saveTg, setTgWebhook, runTgCheck,
      pkgVisible, pkgForm, pkgSaving, openPkg, savePkg, delPkg, tgOrder,
      barWidth, locationOrigin,
      dlgVisible, dlgMode, dlg, dlgSaving, openCreate, openEdit, saveTenant, toggleTenant, delTenant,
      mintVisible, mintRow, mintSkipRed, mintReplace, mintBusy, mintResult, openMint, doMint,
      hostVisible, hostRow, hostValue, hostBusy, hostMsg, hostOk, openHost, saveHost, clearHost,
      createdVisible, created, copyCreated, openGen, genVisible, genPair, doGen, fillGenKami, openTrial,
      addDomain, toggleDomain, delDomain, blockDomain, saveRootDomain, saveDefaults, changePw, logout, isExpired,
      /* v2.91.25 门牌管理 */
      doors, doorNew, doorLoading, doorBusy, doorChecking, doorsNow,
      loadDoors, addDoor, toggleDoor, blockDoor, delDoor, checkDoors,
    };
  }
}).use(ElementPlus).mount('#app');
