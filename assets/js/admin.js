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

    const tabTitle = computed(() => ({ overview: '平台概览', tenants: '租户管理', domains: '域名池', settings: '全局设置' }[tab.value]));
    const locationOrigin = location.origin;

    const barWidth = (v, list) => {
      const max = Math.max(1, ...list.map(x => x.count ?? x.c));
      return Math.max(0, Math.round(v / max * 100)) + '%';
    };

    const loadOverview = async () => { ov.value = await API.get('/api/admin/overview'); };
    const loadTenants = async () => { tenants.value = await API.get('/api/admin/tenants'); };
    const loadDomains = async () => { domains.value = await API.get('/api/admin/domain-pool'); };
    const loadSettings = async () => {
      const r = await API.get('/api/admin/root-domain'); rootDomain.value = r.root_domain;
      Object.assign(defVal, await API.get('/api/admin/defaults'));
    };
    const loadAll = async () => {
      try {
        me.value = await API.me();
        if (me.value.role !== 'admin') { location.href = '/login.html'; return; }
        await Promise.all([loadOverview(), loadTenants(), loadDomains(), loadSettings()]);
        booted.value = true;
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };

    // 租户 CRUD
    const dlgVisible = ref(false);
    const dlgMode = ref('create');
    const dlgSaving = ref(false);
    const dlg = reactive({ id: 0, kami: '', nickname: '', expires_at: null, quota_web: 200, quota_app: 200, max_agents: 5, closed_keep_days: 3, max_sessions: 1000, remark: '', password: '' });
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
      Object.assign(dlg, { id: 0, kami: '', nickname: '', expires_at: null, quota_web: defVal.default_quota_web, quota_app: defVal.default_quota_app, max_agents: defVal.default_max_agents, closed_keep_days: 3, max_sessions: 1000, remark: '', password: '' });
      dlgVisible.value = true;
    };
    const openEdit = (row) => {
      dlgMode.value = 'edit';
      Object.assign(dlg, { id: row.id, kami: row.kami, nickname: row.nickname, expires_at: row.expires_at, quota_web: row.quota_web, quota_app: row.quota_app, max_agents: row.max_agents, closed_keep_days: row.closed_keep_days != null ? row.closed_keep_days : 3, max_sessions: row.max_sessions != null ? row.max_sessions : 0, remark: row.remark, password: '' });
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
    const copyCreated = () => {
      const s = `卡密：${created.kami}\n密码：${created.password}\n登录地址：${locationOrigin}/login.html`;
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
      barWidth, locationOrigin,
      dlgVisible, dlgMode, dlg, dlgSaving, openCreate, openEdit, saveTenant, toggleTenant, delTenant,
      createdVisible, created, copyCreated, openGen, genVisible, genPair, doGen, fillGenKami, openTrial,
      addDomain, toggleDomain, delDomain, saveRootDomain, saveDefaults, changePw, logout,
    };
  }
}).use(ElementPlus).mount('#app');
