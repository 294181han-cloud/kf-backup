// app.js - 客服工作台主应用（createApp + setup；由 public/app.html 内联脚本提取）
// 依赖顺序：vue.global.js -> element-plus.js -> qrcode -> qr-poster -> api.js -> app-components.js -> 本文件
const app = createApp({
  setup() {
    const tab = ref('work');
    const booted = ref(false);
    const me = ref(null);
    const stats = ref(null);

    // 会话与聊天
    const sessions = ref([]);
    const sessSearch = ref('');
    const currentId = ref(0);
    const msgs = ref([]);
    const maxMsgId = ref(0);
    const draft = ref('');
    const sending = ref(false);
    const chatBody = ref(null);
    const viewing = ref('');
    const profile = ref(null);
    const contactList = ref([]);
    const noteDraft = ref('');
    const transferDlg = ref(false);
    const transferTo = ref(null);
    const agentOptions = ref([]);

    // 快捷回复
    const quickMsgs = ref([]);
    const showQuick = ref(true);

    // CRUD 数据
    const welcomeMsgs = ref([]);
    const autoReplies = ref([]);
    const blacklist = ref([]);
    const preInq = ref([]);
    const preqOn = ref(true);

    // 租户
    const links = ref(null);
    const agents = ref([]);
    const brand = reactive({ company_name: '', title: '', sub: '', avatar: '💬', color: '#1677ff', tpl: 'default', tpl_nav_on: true, tpl_nav_title: '', vip_badge: '', footer_text: '' });
    const bcText = ref('');
    const bcSending = ref(false);

    // 个人
    const profileForm = reactive({ nickname: '', avatar: '👨‍💼', auto_accept: true, sound_enabled: true });
    const pwOld = ref(''); const pwNew = ref('');

    // 录音
    const recording = ref(false); const recSecs = ref(0);
    let mediaRecorder = null, recChunks = [], recTimer = null, recStartAt = 0, recSendFlag = false;

    // 二维码 + 海报
    const qrVisible = ref(false); const qrData = ref(''); const qrBox = ref(null);
    const posterUrl = ref('');
    const posterOpts = reactive({
      size: 230, correctLevel: 'medium', dotStyle: 'square', finderStyle: 'square',
      colorMode: 'solid', colorMain: '#07C160', colorGrad1: '#07C160', colorGrad2: '#0BB96B',
      colorBg: '#ffffff', logoShape: 'circle', logoShadow: 'on',
      title: '微信扫码咨询', footer: '官方客服 24h 在线', logoDataUrl: '',
    });
    try { const saved = JSON.parse(localStorage.getItem('kfxt_poster_opts') || '{}'); Object.assign(posterOpts, saved); } catch (e) {}
    const savePosterOpts = () => { try { localStorage.setItem('kfxt_poster_opts', JSON.stringify(posterOpts)); } catch (e) {} };
    const renderPoster = () => {
      if (!window.CSQrPoster || !qrData.value) { posterUrl.value = ''; return; }
      const c = document.createElement('canvas');
      try {
        window.CSQrPoster.draw(c, qrData.value, JSON.parse(JSON.stringify(posterOpts)), () => {
          try { posterUrl.value = c.toDataURL('image/png'); } catch (e) {}
        });
      } catch (e) { posterUrl.value = ''; }
    };
    Vue.watch(posterOpts, () => { savePosterOpts(); renderPoster(); }, { deep: true });
    const onQrOpen = () => nextTick(() => {
      if (qrBox.value) qrBox.value.innerHTML = '';
      if (window.QRCode && qrData.value) new QRCode(qrBox.value, { text: qrData.value, width: 220, height: 220 });
      renderPoster();
    });
    const showQr = (url) => { qrData.value = url; qrVisible.value = true; };
    const downloadPoster = () => {
      if (!posterUrl.value) return;
      const a = document.createElement('a');
      a.href = posterUrl.value; a.download = 'poster_' + Date.now() + '.png';
      document.body.appendChild(a); a.click(); a.remove();
    };
    const pickPosterLogo = () => {
      const inp = document.createElement('input');
      inp.type = 'file'; inp.accept = 'image/*';
      inp.onchange = (e) => {
        const f = e.target.files && e.target.files[0];
        if (!f) return;
        const rd = new FileReader();
        rd.onload = () => { posterOpts.logoDataUrl = rd.result; };
        rd.readAsDataURL(f);
      };
      inp.click();
    };
    const downloadQrBox = (which, prefix) => {
      const box = which === 'wa' ? waQrBox.value : (which === 'tg' ? tgQrBox.value : qrBox.value);
      const img = box && (box.querySelector('img') || box.querySelector('canvas'));
      if (!img) { ElementPlus.ElMessage.warning('二维码尚未生成'); return; }
      const url = img.src || (img.toDataURL && img.toDataURL());
      if (!url) { ElementPlus.ElMessage.warning('无法导出'); return; }
      const a = document.createElement('a');
      a.href = url; a.download = prefix + '-' + Date.now() + '.png';
      document.body.appendChild(a); a.click(); a.remove();
    };

    // 获客渠道（WhatsApp / Telegram）
    const waNumber = ref(''); const tgUser = ref('');
    const waQrBox = ref(null); const tgQrBox = ref(null);
    const genWaQr = () => nextTick(() => {
      if (!waQrBox.value) return;
      waQrBox.value.innerHTML = '';
      const num = waNumber.value.replace(/[^0-9]/g, '');
      if (num && window.QRCode) new QRCode(waQrBox.value, { text: 'https://wa.me/' + num, width: 150, height: 150 });
    });
    const genTgQr = () => nextTick(() => {
      if (!tgQrBox.value) return;
      tgQrBox.value.innerHTML = '';
      const u = tgUser.value.replace(/^@/, '');
      if (u && window.QRCode) new QRCode(tgQrBox.value, { text: 'https://t.me/' + u, width: 150, height: 150 });
    });

    let pollTimer = null;

    const tabTitle = computed(() => ({
      work: '会话接待', stats: '数据面板', quick: '快捷回复', welcome: '欢迎语', auto: '自动回复',
      broadcast: '群发', blacklist: 'IP 黑名单', preinq: '询前表单', links: '获客链接', agents: '子客服管理',
      brand: '访客端模板', policy: '访客策略', visitors: '访客列表', security: '安全与维护', profile: '个人设置', ui: '后台 UI',
      quick: '快捷消息', auto: '智能回复', agents: '坐席管理', broadcast: '群发模板', links: '获客码',
    }[tab.value]));
    const current = computed(() => sessions.value.find(s => s.id === currentId.value) || null);
    const filteredSessions = computed(() => {
      const q = sessSearch.value.trim().toLowerCase();
      if (!q) return sessions.value;
      return sessions.value.filter(s => (s.name || '').toLowerCase().includes(q) || (s.lastMsg || '').toLowerCase().includes(q));
    });
    const expireText = computed(() => {
      if (!me.value || !me.value.expires_at) return '永久有效';
      const d = new Date(me.value.expires_at);
      const days = Math.ceil((d - Date.now()) / 86400000);
      return days > 0 ? `剩余 ${days} 天` : '已到期';
    });

    const shortTime = (t) => { if (!t) return ''; const d = new Date(t); return `${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`; };
    const fmtTime = shortTime;
    const sourceText = (s) => ({ app: '客服码', web: '网页链接', direct: '直接访问' }[s] || s);
    const contactLabel = (t) => ({ note: '备注', mobile: '手机', qq: 'QQ', wechat: '微信', email: '邮箱', other: '其他' }[t] || t);
    const barW = (v, list) => { const max = Math.max(1, ...list.map(x => x.count)); return Math.round(v / max * 100) + '%'; };
    const quotaW = (u, l) => Math.min(100, l > 0 ? Math.round(u / l * 100) : 100) + '%';

    // ---------- 数据加载 ----------
    const loadMe = async () => {
      me.value = await API.me();
      if (me.value.role === 'admin') { location.href = '/admin.html'; return; }
      profileForm.nickname = me.value.nickname || '';
      if (me.value.role === 'agent') {
        profileForm.avatar = me.value.avatar || '👨‍💼';
        profileForm.auto_accept = !!me.value.auto_accept;
        profileForm.sound_enabled = !!me.value.sound_enabled;
      }
    };

    const loadQuick = async () => { try { quickMsgs.value = await API.get('/api/quick-msgs'); } catch (e) {} };
    const loadWelcome = async () => { try { welcomeMsgs.value = await API.get('/api/welcome-messages'); } catch (e) {} };
    const loadAuto = async () => { try { autoReplies.value = await API.get('/api/auto-reply'); } catch (e) {} };
    const loadBlacklist = async () => { try { blacklist.value = await API.get('/api/ip-blacklist'); } catch (e) {} };
    const loadPreInq = async () => { try { preInq.value = await API.get('/api/pre-inquiry'); } catch (e) {} };
    const loadStats = async () => { try { stats.value = await API.get('/api/stats/dashboard'); } catch (e) {} };
    const loadLinks = async () => { try { links.value = await API.get('/api/tenant/links'); renderCodeQrs(); renderMainQr(); } catch (e) {} };
    const loadAgents = async () => { try { agents.value = await API.get('/api/tenant/agents'); } catch (e) {} };
    const loadBrand = async () => {
      try {
        const b = await API.get('/api/tenant/settings');
        brand.company_name = b.brand_company; brand.title = b.brand_title; brand.sub = b.brand_sub;
        brand.avatar = b.brand_avatar; brand.color = b.brand_color; brand.tpl = b.visitor_template || 'default';
        brand.tpl_nav_on = b.tpl_nav_on !== false; brand.tpl_nav_title = b.tpl_nav_title || '';
        codeCfg.logoType = b.code_logo_type || 'wechat';
        codeCfg.nicknameOn = b.code_nickname_on !== false;
        codeCfg.supportOn = b.code_support_on !== false;
        brand.vip_badge = b.vip_badge || ''; brand.footer_text = b.footer_text || '';
        preqOn.value = !!b.pre_inquiry_on;
        kwList.value = (b.filter_keywords || '').split(',').map(s => s.trim()).filter(Boolean);
        Object.assign(pol, {
          robot: b.robot || 'no', robot_frequency: b.robot_frequency || 'first', robot_code: b.robot_code || '',
          filtration_wx: !!b.filtration_wx, filtration_pc: !!b.filtration_pc, filtration_area: !!b.filtration_area,
          link_mode: b.link_mode || 'no', user_head_type: b.user_head_type || 'default', user_head: b.user_head || '',
          user_name_type: b.user_name_type || 'default',
          publicity_on: !!b.publicity_on, publicity_title: b.publicity_title || '', publicity_content: b.publicity_content || '',
          inform_on: !!b.inform_on, inform_text: b.inform_text || '',
          tpl_nav_on: b.tpl_nav_on !== false, tpl_nav_title: b.tpl_nav_title || '',
          vip_badge: b.vip_badge || '', footer_text: b.footer_text || '',
        });
        Object.assign(sec, {
          notice_on: !!b.notice_on, notice_text: b.notice_text || '',
          filter_keywords: b.filter_keywords || '', auto_blacklist: !!b.auto_blacklist,
          dedup_on: !!b.dedup_on, ip_limit_on: !!b.ip_limit_on, ip_limit_count: b.ip_limit_count || 5,
        });
      } catch (e) {}
    };
    const loadAgentOptions = async () => {
      try { const r = await API.get('/api/agents'); agentOptions.value = r.agents || []; } catch (e) {}
    };

    // ---------- 轮询 ----------
    let lastUnreadTotal = 0;
    const pollOnce = async () => {
      try {
        const r = await API.get('/api/poll?role=agent&session=' + currentId.value + '&sinceMsg=' + maxMsgId.value);
        sessions.value = r.sessions || [];
        readUpto.value = r.readUpto != null ? r.readUpto : readUpto.value;
        // 撤回同步：最近撤回的消息标记（客服撤回→胶囊；访客撤回→内容保留+撤回角标）
        if (Array.isArray(r.recalledIds) && r.recalledIds.length) {
          const rset = new Set(r.recalledIds);
          msgs.value.forEach(m => { if (rset.has(m.id)) { m.recalled = true; m.recalledByVisitor = m.from === 'visitor'; } });
        }
        if ((r.messages || []).some(m => m.from === 'visitor') || (unreadTotal > lastUnreadTotal && lastUnreadTotal >= 0)) beep();
        lastUnreadTotal = unreadTotal;
        if (r.messages && r.messages.length) {
          const fresh = r.messages.filter(m => !msgs.value.some(x => x.id === m.id)); // 去重防重复显示
          msgs.value.push(...fresh);
          scrollBottom();
          if (r.messages.some(m => m.from === 'visitor')) markRead(currentId.value);
        }
      } catch (e) { if (String(e.message).includes('登录已失效')) throw e; }
    };
    // 自适应轮询：接待中/有未读 1.2s，空闲 3s（比 WebSocket 在 FPM 环境更稳）
    let pollGap = 2500;
    const loopPoll = async () => {
      try { await pollOnce(); } catch (e) {}
      const hasUnread = sessions.value.some(s => s.unread > 0);
      pollGap = (tab.value === 'work' && (currentId.value || hasUnread)) ? 1200 : 3000;
      pollTimer = setTimeout(loopPoll, pollGap);
    };
    const startPoll = () => { stopPoll(); loopPoll(); };
    const restartPoll = () => { if (pollTimer) { stopPoll(); loopPoll(); } };
    const stopPoll = () => { if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; } };

    const beep = () => {
      if (me.value && me.value.role === 'agent' && !me.value.sound_enabled) return;
      try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const o = ctx.createOscillator(); const g = ctx.createGain();
        o.connect(g); g.connect(ctx.destination);
        o.frequency.value = 880; g.gain.value = .12;
        o.start(); o.stop(ctx.currentTime + .18);
      } catch (e) {}
    };

    const scrollBottom = () => nextTick(() => { if (chatBody.value) chatBody.value.scrollTop = chatBody.value.scrollHeight; });

    const openSession = async (id) => {
      currentId.value = id;
      msgs.value = [];
      maxMsgId.value = 0;
      profile.value = null;
      try {
        const r = await API.get('/api/sessions/' + id + '/messages');
        msgs.value = r.messages || [];
        if (msgs.value.length) maxMsgId.value = msgs.value[msgs.value.length - 1].id;
        scrollBottom();
        markRead(id);
        // 资料与联系方式
        profile.value = await API.get('/api/sessions/' + id + '/profile');
        contactList.value = await API.get('/api/sessions/' + id + '/contacts');
        noteDraft.value = (contactList.value.find(c => c.type === 'note') || {}).value || '';
        if (!agentOptions.value.length) await loadAgentOptions();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const markRead = async (id) => {
      try { await API.post('/api/sessions/' + id + '/read'); const s = sessions.value.find(x => x.id === id); if (s) s.unread = 0; } catch (e) {}
    };

    const send = async () => {
      const text = draft.value.trim();
      if (!text || !currentId.value || sending.value) return;
      sending.value = true;
      try {
        const r = await API.post('/api/sessions/' + currentId.value + '/messages', { text });
        msgs.value.push(r.message);
        maxMsgId.value = r.message.id;
        draft.value = '';
        showQuick.value = false;
        scrollBottom();
        const s = sessions.value.find(x => x.id === currentId.value);
        if (s) { s.lastMsg = text.slice(0, 60); s.agent_id = s.agent_id || (me.value.role === 'agent' ? me.value.id : s.agent_id); }
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { sending.value = false; }
    };
    const onKey = (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } };

    // 快捷回复：点击直接发送全部内容
    const sendQuick = async (q) => {
      if (!currentId.value || sending.value) { ElementPlus.ElMessage.warning('请先选择会话'); return; }
      const text = q.content || '';
      const atts = (q.attachments || []).filter(a => a && a.url);
      if (!text && !atts.length) return;
      sending.value = true;
      try {
        const r = await API.post('/api/sessions/' + currentId.value + '/messages', { text, attachments: atts });
        msgs.value.push(r.message);
        maxMsgId.value = r.message.id;
        scrollBottom();
        const s = sessions.value.find(x => x.id === currentId.value);
        if (s) s.lastMsg = (text || (atts.length ? '[图片]' : '')).slice(0, 60);
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { sending.value = false; }
    };
    const useQuick = (q) => { draft.value = q.content; showQuick.value = false; };

    // ===== 聊天内搜索 =====
    const chatSearchOn = ref(false);
    const chatSearchKw = ref('');
    const searchHit = Vue.computed(() => {
      if (!chatSearchKw.value.trim()) return 0;
      return msgs.value.filter(m => !m.recalled && m.text && m.text.includes(chatSearchKw.value.trim())).length;
    });

    // ===== 撤回 =====
    const canRecall = (m) => m.from === 'agent' && !m.recalled && (Date.now() - new Date(m.time).getTime()) < 120000;
    const recallMsg = async (m) => {
      if (m.recalled) return;
      try {
        await API.post('/api/sessions/' + currentId.value + '/recall', { msg_id: m.id });
        m.recalled = true; m.text = '消息已撤回'; m.attachments = [];
        ElementPlus.ElMessage.success('已撤回');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };

    // ===== 已读回执 =====
    const readUpto = ref(0);
    const isRead = (m) => m.id <= readUpto.value;

    // ===== 可见消息（撤回的显示为系统胶囊） =====
    const visibleMsgs = Vue.computed(() => msgs.value);

    // 图片/语音
    const onImage = async (ev) => {
      const file = ev.target.files[0];
      ev.target.value = '';
      if (!file || !currentId.value) return;
      if (file.size > 5 * 1024 * 1024) { ElementPlus.ElMessage.warning('图片不能超过 5MB'); return; }
      const data = await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(file); });
      try {
        const up = await API.post('/api/upload', { data, kind: 'image', filename: file.name });
        const r = await API.post('/api/sessions/' + currentId.value + '/messages', { text: '', attachments: [up] });
        msgs.value.push(r.message); maxMsgId.value = r.message.id; scrollBottom();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const startRec = async () => {
      if (!navigator.mediaDevices || !window.MediaRecorder) { ElementPlus.ElMessage.warning('当前浏览器不支持录音'); return; }
      try {
        let mime = '';
        for (const m of ['audio/mp4', 'audio/webm;codecs=opus', 'audio/webm']) { if (MediaRecorder.isTypeSupported(m)) { mime = m; break; } }
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream);
        recChunks = [];
        mediaRecorder.ondataavailable = (e) => { if (e.data && e.data.size) recChunks.push(e.data); };
        mediaRecorder.onstop = async () => {
          stream.getTracks().forEach(t => t.stop());
          if (!recSendFlag || !recChunks.length) return;
          const blob = new Blob(recChunks, { type: mediaRecorder.mimeType || 'audio/webm' });
          const data = await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(blob); });
          try {
            const up = await API.post('/api/upload', { data, kind: 'audio', filename: 'voice.webm' });
            const r = await API.post('/api/sessions/' + currentId.value + '/messages', { text: '', attachments: [up] });
            msgs.value.push(r.message); maxMsgId.value = r.message.id; scrollBottom();
          } catch (e) { ElementPlus.ElMessage.error(e.message); }
        };
        recSendFlag = false;
        mediaRecorder.start();
        recording.value = true; recSecs.value = 0; recStartAt = Date.now();
        recTimer = setInterval(() => { recSecs.value = Math.floor((Date.now() - recStartAt) / 1000); if (recSecs.value >= 60) stopRec(true); }, 500);
      } catch (e) { ElementPlus.ElMessage.warning('无法获取麦克风权限'); }
    };
    const stopRec = (sendIt) => {
      if (!mediaRecorder) return;
      recSendFlag = sendIt;
      clearInterval(recTimer);
      recording.value = false;
      try { mediaRecorder.stop(); } catch (e) {}
    };

    // 会话操作
    const closeSession = async () => {
      try {
        await API.post('/api/sessions/' + currentId.value + '/close');
        sessions.value = sessions.value.filter(s => s.id !== currentId.value);
        currentId.value = 0; msgs.value = [];
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const doTransfer = async () => {
      if (transferTo.value === null) { ElementPlus.ElMessage.warning('请选择目标'); return; }
      try {
        await API.post('/api/sessions/' + currentId.value + '/transfer', { to_agent_id: transferTo.value });
        ElementPlus.ElMessage.success('已转接');
        transferDlg.value = false;
        currentId.value = 0; msgs.value = [];
        await pollOnce();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };

    // 联系方式
    const contactDlg = ref(false);
    const editingContact = reactive({ type: '', value: '' });
    const editContact = (c) => { editingContact.type = c.type; editingContact.value = c.value; contactDlg.value = true; };
    const saveContact = async () => {
      try {
        await API.put('/api/sessions/' + currentId.value + '/contacts/' + editingContact.type, { value: editingContact.value });
        const c = contactList.value.find(x => x.type === editingContact.type);
        if (c) c.value = editingContact.value;
        contactDlg.value = false;
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const saveNote = async () => {
      if (!currentId.value) return;
      try { await API.put('/api/sessions/' + currentId.value + '/contacts/note', { value: noteDraft.value }); } catch (e) {}
    };

    // 群发
    const sendBroadcast = async () => {
      bcSending.value = true;
      try {
        const r = await API.post('/api/broadcast/send', { text: bcText.value.trim() });
        ElementPlus.ElMessage.success('已群发 ' + r.count + ' 个会话');
        bcText.value = '';
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { bcSending.value = false; }
    };

    // 获客链接
    const copy = (s) => { navigator.clipboard && navigator.clipboard.writeText(s); ElementPlus.ElMessage.success('已复制'); };
    const createLink = async () => { try { await API.post('/api/tenant/link'); ElementPlus.ElMessage.success('已生成'); await loadLinks(); } catch (e) { ElementPlus.ElMessage.error(e.message); } };
    const renewLink = async () => { try { await API.post('/api/tenant/link/renew'); ElementPlus.ElMessage.success('已更换'); await loadLinks(); } catch (e) { ElementPlus.ElMessage.error(e.message); } };
    const newCode = async () => { try { await API.post('/api/tenant/service-code'); ElementPlus.ElMessage.success('已生成新客服码'); await loadLinks(); } catch (e) { ElementPlus.ElMessage.error(e.message); } };
    const delCode = async (c) => { try { await API.del('/api/tenant/service-code?code=' + c.code); await loadLinks(); } catch (e) { ElementPlus.ElMessage.error(e.message); } };
    const blockSession = async () => {
      if (!currentId.value) return;
      try {
        const r = await API.post('/api/sessions/' + currentId.value + '/block');
        ElementPlus.ElMessage.success('已拉黑 ' + r.ip);
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const blockIp = async (ip) => {
      try {
        await API.post('/api/ip-blacklist', { ip, reason: '访客资源手动拉黑' });
        ElementPlus.ElMessage.success('已拉黑 ' + ip);
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    // 关键词标签式管理
    const kwList = ref([]);
    const kwInput = ref('');
    const kwSaving = ref(false);
    const addKw = () => {
      const v = kwInput.value.trim().slice(0, 30);
      if (!v) return;
      if (kwList.value.includes(v)) { kwInput.value = ''; return; }
      kwList.value.push(v);
      kwInput.value = '';
    };
    const removeKw = (kw) => { kwList.value = kwList.value.filter(x => x !== kw); };
    const saveFilter = async () => {
      kwSaving.value = true;
      try {
        await API.put('/api/tenant/settings', { filter_keywords: kwList.value.join(','), auto_blacklist: sec.auto_blacklist });
        sec.filter_keywords = kwList.value.join(',');
        ElementPlus.ElMessage.success('已保存');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { kwSaving.value = false; }
    };
    const agentCmd = async (cmd, row) => {
      if (cmd === 'toggle') { await toggleAgent(row); }
      else if (cmd === 'del') {
        try {
          await ElementPlus.ElMessageBox.confirm('删除该子客服？其名下会话将退回公共池', '警告', { type: 'warning' });
          await delAgent(row);
        } catch (e) {}
      }
    };
    const renameVisitor = async () => {
      if (!currentId.value) return;
      try {
        const { value } = await ElementPlus.ElMessageBox.prompt('修改访客名称（列表与聊天中将显示新名称）', '重命名', { inputValue: current.value ? current.value.name : '', inputPattern: /\S+/, inputErrorMessage: '名称不能为空' });
        const name = value.trim().slice(0, 30);
        if (!name) return;
        await API.put('/api/sessions/' + currentId.value + '/name', { name });
        const s = sessions.value.find(x => x.id === currentId.value);
        if (s) s.name = name;
        if (profile.value) profile.value.session.name = name;
        ElementPlus.ElMessage.success('已重命名');
      } catch (e) { /* 取消 */ }
    };
    const renderCodeQrs = () => {
      nextTick(() => {
        document.querySelectorAll('.code-qr[data-code]').forEach((el) => {
          if (el.dataset.done) return;
          el.dataset.done = '1';
          el.innerHTML = '';
          if (window.QRCode) new QRCode(el, { text: el.dataset.code, width: 72, height: 72 });
        });
      });
    };
    Vue.watch(links, () => renderCodeQrs(), { deep: false });

    // 子客服
    const agentDlg = ref(false);
    const agentMode = ref('create');
    const agentForm = reactive({ username: '', nickname: '', password: '' });
    const createdAgentVisible = ref(false);
    const createdAgent = reactive({ account: '', password: '' });
    const openAgentEdit = (row) => { agentMode.value = 'edit'; agentForm.username = row.username; agentForm.nickname = row.nickname; agentForm.password = ''; agentDlg.value = true; };
    const saveAgent = async () => {
      try {
        if (agentMode.value === 'create') {
          const r = await API.post('/api/tenant/agents', { username: agentForm.username, nickname: agentForm.nickname, password: agentForm.password });
          createdAgent.account = r.login_account; createdAgent.password = r.plain.password;
          createdAgentVisible.value = true;
        } else {
          const found = agents.value.find(a => a.username === agentForm.username); const id = found ? found.id : 0;
          if (id) await API.put('/api/tenant/agents/' + id, { nickname: agentForm.nickname });
        }
        agentDlg.value = false;
        await loadAgents();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const resetAgentPw = async (row) => {
      try {
        ElementPlus.ElMessageBox.prompt('输入新密码（至少 6 位）', '重置密码', { inputValue: '' }).then(async ({ value }) => {
          if (!value || value.length < 6) { ElementPlus.ElMessage.warning('密码至少 6 位'); return; }
          await API.put('/api/tenant/agents/' + row.id, { password: value });
          ElementPlus.ElMessage.success('已重置为：' + value);
        }).catch(() => {});
      } catch (e) {}
    };
    const toggleAgent = async (row) => { try { await API.put('/api/tenant/agents/' + row.id, { status: row.status === 'active' ? 'disabled' : 'active' }); await loadAgents(); } catch (e) { ElementPlus.ElMessage.error(e.message); } };
    const delAgent = async (row) => { try { await API.del('/api/tenant/agents/' + row.id); ElementPlus.ElMessage.success('已删除'); await loadAgents(); } catch (e) { ElementPlus.ElMessage.error(e.message); } };

    // 品牌与设置
    const saveBrand = async () => {
      try {
        await API.put('/api/tenant/settings', {
          brand_company: brand.company_name, brand_title: brand.title, brand_sub: brand.sub,
          brand_avatar: brand.avatar, brand_color: brand.color, visitor_template: brand.tpl,
          tpl_nav_on: brand.tpl_nav_on, tpl_nav_title: brand.tpl_nav_title,
          vip_badge: brand.vip_badge, footer_text: brand.footer_text,
        });
        ElementPlus.ElMessage.success('已保存');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const savePreqOn = async () => {
      try { await API.put('/api/tenant/settings', { pre_inquiry_on: preqOn.value }); } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const saveProfile = async () => {
      try {
        const data = { nickname: profileForm.nickname };
        if (me.value.role === 'agent') { data.avatar = profileForm.avatar; data.auto_accept = profileForm.auto_accept; data.sound_enabled = profileForm.sound_enabled; }
        await API.updateProfile(data);
        ElementPlus.ElMessage.success('已保存');
        await loadMe();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const changePw = async () => {
      if (!pwOld.value || !pwNew.value) { ElementPlus.ElMessage.warning('请填写完整'); return; }
      try { await API.changePassword(pwOld.value, pwNew.value); ElementPlus.ElMessage.success('密码已修改'); pwOld.value = pwNew.value = ''; }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const exportCsv = async () => {
      try {
        const res = await API.get('/api/export/sessions');
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = 'sessions_' + Date.now() + '.csv';
        document.body.appendChild(a); a.click(); a.remove();
        URL.revokeObjectURL(url);
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const logout = () => { API.clearToken(); location.href = '/login.html'; };

    // ===== 访客策略 =====
    const pol = reactive({
      robot: 'no', robot_frequency: 'first', robot_code: '',
      filtration_wx: false, filtration_pc: false, filtration_area: false,
      link_mode: 'no', user_head_type: 'default', user_head: '', user_name_type: 'default',
      publicity_on: false, publicity_title: '', publicity_content: '',
      inform_on: false, inform_text: '',
    });
    const uploadPolHead = async (ev) => {
      const file = ev.target.files[0];
      ev.target.value = '';
      if (!file) return;
      const data = await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(file); });
      try {
        const up = await API.post('/api/upload', { data, kind: 'image', filename: file.name });
        pol.user_head = up.url;
        ElementPlus.ElMessage.success('已上传，记得保存');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const savePolicy = async () => {
      try {
        await API.put('/api/tenant/settings', { ...pol });
        ElementPlus.ElMessage.success('已保存');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    // ===== 安全与维护 / 访客资源 / 引导 / 深色 =====
    const sec = reactive({ notice_on: false, notice_text: '', filter_keywords: '', auto_blacklist: false, dedup_on: false, ip_limit_on: false, ip_limit_count: 5 });
    const loginLogs = ref([]);
    const visitors = ref([]);
    const cleanupOpts = [
      { label: '清理 1 天前的会话', days: 1 },
      { label: '清理 3 天前的会话', days: 3 },
      { label: '清理 7 天前的会话', days: 7 },
      { label: '清理全部会话', days: -1 },
    ];
    const loadSecurityPage = async () => {
      try {
        loginLogs.value = await API.get('/api/tenant/login-logs');
      } catch (e) {}
    };
    const saveSecurity = async () => {
      try {
        await API.put('/api/tenant/settings', { ...sec });
        ElementPlus.ElMessage.success('已保存');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const doCleanup = async (days, scope) => {
      try {
        const r = await API.post('/api/tenant/cleanup', { days, scope });
        ElementPlus.ElMessage.success('已清理 ' + (r.deleted || 0) + ' 条' + (r.label ? '（' + r.label + '）' : ''));
        if (scope === 'blacklist') await loadBlacklist();
        else { await pollOnce(); if (currentId.value) { msgs.value = []; await openSession(currentId.value); } currentId.value = 0; msgs.value = []; }
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const loadVisitors = async () => {
      try { visitors.value = await API.get('/api/tenant/visitors'); } catch (e) {}
    };
    const openVisitorSessions = (row) => {
      if (row.last_session_id) { tab.value = 'work'; openSession(row.last_session_id); }
      else ElementPlus.ElMessage.info('该访客暂无会话记录');
    };

    // 接入引导（真实配置状态）
    const guide = computed(() => {
      if (!me.value || me.value.role !== 'tenant') return [];
      return [
        { k: 'welcome', icon: '👋', label: '设置欢迎语', desc: '访客进入自动打招呼', done: welcomeMsgs.value.length > 0, go: () => tab.value = 'welcome' },
        { k: 'auto', icon: '🤖', label: '设置自动回复', desc: '关键词自动应答', done: autoReplies.value.length > 0, go: () => tab.value = 'auto' },
        { k: 'link', icon: '🔗', label: '生成获客链接', desc: '专属链接 / 客服码', done: !!(links.value && (links.value.domain_link || (links.value.codes || []).length)), go: () => tab.value = 'links' },
      ];
    });
    const guideDone = computed(() => guide.value.filter(g => g.done).length);

    // 访客端模板列表（与 visitor.html TEMPLATES 对齐）
    const tplList = [
      { k: 'default', label: '默认客服', g1: '#1677ff' },
      { k: 'weixin', label: '微信', g1: '#07C160' },
      { k: 'alipay', label: '支付宝', g1: '#1677FF' },
      { k: 'meituan', label: '美团', g1: '#FFC300' },
      { k: 'jingdong', label: '京东', g1: '#E2231A' },
      { k: 'pinduoduo', label: '拼多多', g1: '#E02E24' },
      { k: 'douyin', label: '抖音', g1: '#161823' },
      { k: 'xianyu', label: '闲鱼', g1: '#FFE311' },
      { k: 'tmall', label: '天猫', g1: '#FF0036' },
      { k: 'taobao', label: '淘宝', g1: '#FF5000' },
      { k: 'tencent', label: '腾讯客服', g1: '#0052D9' },
      { k: 'baitiao', label: '京东白条', g1: '#4A5AFF' },
      { k: 'fenqile', label: '分期乐', g1: '#FF6A00' },
      { k: 'delivery', label: '外卖平台', g1: '#00A6FF' },
      { k: 'tongcheng58', label: '58同城', g1: '#FF6E00' },
      { k: 'weilidai', label: '微粒贷', g1: '#00B389' },
      { k: 'whatsapp', label: 'WhatsApp', g1: '#25D366' },
      { k: 'worldcup', label: '世界杯', g1: '#1B5E20' },
      { k: 'xiaohongshu', label: '小红书', g1: '#FF2442' },
      { k: 'yangqianguan', label: '钱罐子', g1: '#FF8800' },
    ];
    // ===== 后台UI（模板+配色选择；注册表 assets/ui/ui-registry.js，加载器 ui-loader.js） =====
    // pc 分类由本页加载器实例管理（即时生效）；mobile 分类写 localStorage 供访客页本机预览。
    const uiReg = window.UI_STYLES || { categories: {} };
    const uiSel = {};
    Object.keys(uiReg.categories || {}).forEach((key) => {
      const cat = uiReg.categories[key];
      const tpls = cat.templates || [];
      let tplId = cat.defaultTemplate, colorId = null;
      try {
        tplId = localStorage.getItem('kfxt_ui_' + key + '_tpl') || tplId;
        colorId = localStorage.getItem('kfxt_ui_' + key) || (key === 'pc' && window.UI ? UI.current().id : null);
      } catch (e) {}
      if (key === 'pc' && window.UI) { tplId = UI.template().id; colorId = UI.current().id; }
      const t = tpls.find((x) => x.id === tplId) || tpls[0];
      const colors = (t && t.colors) || [];
      if (!colors.find((x) => x.id === colorId)) colorId = colors.length ? colors[0].id : '';
      uiSel[key] = { tpl: t ? t.id : '', color: colorId };
    });
    const uiCatList = Object.keys(uiReg.categories || {}).map((key) => ({
      key,
      label: uiReg.categories[key].label,
      pages: (uiReg.categories[key].pages || []).join(' / '),
    }));
    const uiTplList = (key) => (uiReg.categories[key] ? uiReg.categories[key].templates || [] : []);
    const uiColorList = (key) => {
      const t = (uiReg.categories[key] ? uiReg.categories[key].templates || [] : []).find((x) => x.id === uiSel[key].tpl);
      return t ? t.colors || [] : [];
    };
    const uiPick = (key, tplId, colorId) => {
      const sel = uiSel[key];
      const tplChanged = tplId !== sel.tpl;
      sel.tpl = tplId;
      sel.color = colorId;
      if (key === 'pc' && window.UI) {
        if (tplChanged) UI.setTemplate(tplId);
        UI.set(colorId);
        sel.tpl = UI.template().id;   // 加载器可能因配色回退修正状态
        sel.color = UI.current().id;
      } else {
        try {
          localStorage.setItem('kfxt_ui_' + key + '_tpl', tplId);
          localStorage.setItem('kfxt_ui_' + key, colorId);
        } catch (e) {}
      }
    };

    // ===== 头像裁剪（1:1） =====
    const cropDlg = ref(false);
    const cropSrc = ref('');
    const cropZoomPct = ref(30);
    const cropStage = ref(null);
    const cropImgEl = Vue.ref(null);
    const cropStyle = ref({ left: '0px', top: '0px', width: '320px' });
    let cropNatural = { w: 0, h: 0 };
    const cropImgLoaded = (e) => {
      cropNatural = { w: e.target.naturalWidth || 1, h: e.target.naturalHeight || 1 };
      cropZoomPct.value = 30;
      centerImage();
    };
    const cropSize = () => 320 * (cropZoomPct.value / 100);
    const centerImage = () => {
      const stage = cropStage.value; if (!stage) return;
      const sw = cropStageWidth(); const sh = 320;
      const disp = cropNatural.w * (cropSize() / cropNatural.w) || cropSize();
      const w = Math.max(cropSize(), 40);
      const h = w * cropNatural.h / cropNatural.w;
      const stageEl = cropStage.value;
      const stW = stageEl ? stageEl.clientWidth : 320;
      const stH = stageEl ? stageEl.clientHeight : 320;
      const ox = (stW - w) / 2, oy = (stH - h) / 2;
      cropStyle.value = { left: ox + 'px', top: oy + 'px', width: w + 'px' };
    };
    const cropStageWidth = () => { const el = cropStage.value; return el ? el.clientWidth : 320; };
    const cropZoomInput = () => {
      const before = parseFloat(cropStyle.value.width) || 320;
      const w = Math.max(60, cropNatural.w * (cropZoomPct.value / 100));
      const scale = w / before;
      const stage = cropStage.value;
      const stW = stage ? stage.clientWidth : 320, stH = stage ? stage.clientHeight : 320;
      const cx = stW / 2, cy = stH / 2;
      let ox = (cx - (parseFloat(cropStyle.value.left))) * scale * -1 + cx * 0;
      ox = cx - (cx - parseFloat(cropStyle.value.left)) * scale;
      let oy = cy - (cy - parseFloat(cropStyle.value.top)) * scale;
      cropStyle.value = { left: ox + 'px', top: oy + 'px', width: w + 'px' };
    };
    let cropDrag = null;
    const cropDown = (e) => {
      const img = cropStage.value ? cropStage.value.querySelector('.crop-img') : null;
      if (!img) return;
      cropDrag = { sx: e.clientX, sy: e.clientY, ox: parseFloat(img.style.left) || 0, oy: parseFloat(img.style.top) || 0 };
      img.setPointerCapture && e.target.setPointerCapture && 0;
      cropStage.value.setPointerCapture(e.pointerId);
    };
    const cropMove = (e) => {
      if (!cropDrag) return;
      cropStyle.value = {
        left: (cropDrag.ox + (e.clientX - cropDrag.sx)) + 'px',
        top: (cropDrag.oy + (e.clientY - cropDrag.sy)) + 'px',
        width: cropStyle.value.width,
      };
    };
    const cropUp = () => { cropDrag = null; };
    const cropWheel = (e) => {
      if (!cropDlg.value) return;
      const d = e.deltaY > 0 ? -4 : 4;
      const v = Math.max(10, Math.min(100, cropZoomPct.value + d));
      if (v !== cropZoomPct.value) cropZoomInput();
    };
    const cropClose = () => { cropSrc.value = ''; };
    const openCrop = (ev) => {
      const file = ev && ev.target ? ev.target.files[0] : null;
      if (ev && ev.target) ev.target.value = '';
      if (!file) return;
      if (file.size > 5 * 1024 * 1024) { ElementPlus.ElMessage.warning('图片不能超过 5MB'); return; }
      const fr = new FileReader();
      fr.onload = () => { cropSrc.value = fr.result; cropDlg.value = true; };
      fr.readAsDataURL(file);
    };
    const cropConfirm = async () => {
      const stage = cropStage.value;
      const img = stage ? stage.querySelector('.crop-img') : null;
      if (!stage || !img) return;
      const stW = stage.clientWidth, stH = stage.clientHeight;
      const box = 200, cx = stW / 2 - box / 2, cy = stH / 2 - box / 2;
      const ox = parseFloat(cropStyle.value.left) || 0, oy = parseFloat(cropStyle.value.top) || 0;
      const dispW = parseFloat(cropStyle.value.width) || 1;
      const zoom = dispW / cropNatural.w;
      const srcX = (cx - ox) / zoom, srcY = (cy - oy) / zoom, srcS = box / zoom;
      const cv = document.createElement('canvas');
      cv.width = 200; cv.height = 200;
      const ctx = cv.getContext('2d');
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, 200, 200);
      ctx.drawImage(img, srcX, srcY, srcS, srcS, 0, 0, 200, 200);
      const data = cv.toDataURL('image/png');
      try {
        const up = await API.post('/api/upload', { data, kind: 'image', filename: 'avatar.png' });
        if (me.value && me.value.role === 'tenant') {
          await API.put('/api/tenant/settings', { me_avatar: up.url });
        } else {
          await API.updateProfile({ avatar: up.url });
        }
        me.value.avatar = up.url;
        profileForm.avatar = up.url;
        ElementPlus.ElMessage.success('头像已更新');
        cropDlg.value = false;
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };

    // ===== 获客码页（统一码） =====
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

    // 顶栏时钟 + 未读总数
    const clock = ref('');
    const tickClock = () => { clock.value = new Date().toLocaleString('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' }); };
    tickClock(); setInterval(tickClock, 1000);
    const unreadTotal = Vue.computed(() => sessions.value.reduce((a, s) => a + (s.unread || 0), 0));
    const meAvatarUrl = Vue.computed(() => (me.value && me.value.avatar) || '');
    const avatarPreview = meAvatarUrl;
    // 地域标签格式化：国家 省 市（去 0/空，去重相邻）
    const geoTag = (g) => {
      if (!g) return '';
      const p = String(g).split('|');
      const parts = [];
      for (const x of [p[0], p[1], p[2]]) {
        const v = (x || '').trim();
        if (v && v !== '0' && parts.indexOf(v) === -1) parts.push(v);
      }
      return parts.join(' ');
    };

    // 深色主题
    const dark = ref(false);
    const sessCollapsed = ref(false);
    const sideCollapsed = ref(false);
    try { dark.value = localStorage.getItem('kfxt_dark') === '1'; } catch (e) {}
    const applyDark = () => { document.body.classList.toggle('dark', dark.value); };
    const toggleDark = () => { dark.value = !dark.value; try { localStorage.setItem('kfxt_dark', dark.value ? '1' : '0'); } catch (e) {} applyDark(); };
    applyDark();

    // CRUD 字段配置
    const quickFields = [
      { k: 'title', label: '标题' },
      { k: 'content', label: '文字内容', type: 'textarea' },
      { k: 'attachments', label: '图片', type: 'images', max: 3, tip: '点击按钮直接发送文字与图片，最多 3 张' },
      { k: 'group_name', label: '分组', def: '通用' },
      { k: 'sort', label: '排序', type: 'number', def: 0 },
    ];
    const welcomeFields = [
      { k: 'content', label: '文字内容', type: 'textarea' },
      { k: 'attachments', label: '配图', type: 'images', max: 3, tip: '欢迎语配图（访客端最多显示 3 张）' },
      { k: 'trig', label: '触发方式', type: 'select', def: 'once', options: [{ v: 'once', l: '仅首次进入' }, { v: 'every', l: '每次进入' }] },
      { k: 'enabled', label: '启用', type: 'bool' },
      { k: 'sort', label: '顺序', type: 'number', def: 0 },
    ];
    const autoFields = [
      { k: 'keyword', label: '关键词' },
      { k: 'reply', label: '回复内容', type: 'textarea' },
      { k: 'enabled', label: '启用', type: 'bool' },
    ];
    const blFields = [
      { k: 'ip', label: 'IP 地址' },
      { k: 'reason', label: '原因' },
    ];
    const preqFields = [
      { k: 'label', label: '字段名' },
      { k: 'type', label: '类型', type: 'select', def: 'text', options: [{ v: 'text', l: '单行文本' }, { v: 'textarea', l: '多行文本' }] },
      { k: 'required', label: '必填', type: 'bool' },
      { k: 'placeholder', label: '提示语' },
      { k: 'sort', label: '排序', type: 'number', def: 0 },
    ];

    // 头像上传（图片，走 /api/upload；emoji 直接输入）
    const uploadAvatar = async (ev, target) => {
      const file = ev.target.files[0];
      ev.target.value = '';
      if (!file) return;
      if (file.size > 2 * 1024 * 1024) { ElementPlus.ElMessage.warning('头像不能超过 2MB'); return; }
      const data = await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(file); });
      try {
        const up = await API.post('/api/upload', { data, kind: 'image', filename: file.name });
        if (target === 'brand') brand.avatar = up.url;
        else profileForm.avatar = up.url;
        ElementPlus.ElMessage.success('头像已上传，记得保存');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const isImg = (v) => typeof v === 'string' && (v.startsWith('http') || v.startsWith('/uploads'));

    onMounted(async () => {
      try {
        await loadMe();
        if (me.value.role === 'admin') { location.href = '/admin.html'; return; }
        await Promise.all([pollOnce(), loadQuick(), loadWelcome(), loadAuto(), loadStats()]);
        if (me.value.role === 'tenant') {
          await Promise.all([loadLinks(), loadAgents(), loadBrand(), loadBlacklist(), loadPreInq(), loadAgentOptions()]);
        }
        booted.value = true;
        startPoll();
      } catch (e) {
        // 401 时 api.js 已跳转登录页；此处保持加载态不渲染骨架
        if (!String(e.message).includes('登录已失效')) ElementPlus.ElMessage.error(e.message);
      }
    });
    onUnmounted(stopPoll);

    return {
      tab, tabTitle, booted, me, expireText, stats, sessions, sessSearch, filteredSessions, currentId, current,
      msgs, draft, sending, chatBody, viewing, profile, contactList, noteDraft, transferDlg, transferTo, agentOptions,
      quickMsgs, showQuick, welcomeMsgs, autoReplies, blacklist, preInq, preqOn, links, agents, brand,
      bcText, bcSending, profileForm, pwOld, pwNew, recording, recSecs, qrVisible, qrData, qrBox,
      posterUrl, posterOpts, downloadPoster, pickPosterLogo, onQrOpen, downloadQrBox,
      waNumber, tgUser, waQrBox, tgQrBox, genWaQr, genTgQr,
      uploadAvatar, isImg,
      sec, loginLogs, visitors, cleanupOpts, loadSecurityPage, saveSecurity, doCleanup, loadVisitors, openVisitorSessions,
      guide, guideDone, dark, toggleDark, renameVisitor, renderCodeQrs, tplList,
      uiCatList, uiSel, uiTplList, uiColorList, uiPick, sideCollapsed,
      blockSession, blockIp, kwList, kwInput, kwSaving, addKw, removeKw, saveFilter, agentCmd, restartPoll,
      chatSearchOn, chatSearchKw, searchHit, visibleMsgs, canRecall, recallMsg, isRead,
      pol, savePolicy, uploadPolHead, clock, unreadTotal, sessCollapsed,
      manageDlg, history, codeCfg, mainQrUrl, qrWrap, codeLogoEmoji, codeLogoImg, currentLink, renderMainQr,
      cropDlg, cropSrc, cropZoomPct, cropStage, cropStyle, cropImgLoaded, cropDown, cropMove, cropUp, cropWheel, cropClose, openCrop, cropConfirm, avatarPreview,
      geoTag,copyQrImage, downloadQrImage, codeRotateHint, rotateCode, loadHistory, disableLink, saveCodeCfg, meAvatarUrl,
      quickFields, welcomeFields, autoFields, blFields, preqFields,
      shortTime, fmtTime, sourceText, contactLabel, barW, quotaW,
      openSession, send, onKey, useQuick, sendQuick, onImage, startRec, stopRec, closeSession, doTransfer,
      editContact, saveContact, contactDlg, editingContact, saveNote, sendBroadcast,
      copy, createLink, renewLink, newCode, delCode, showQr,
      agentDlg, agentMode, agentForm, openAgentEdit, saveAgent, resetAgentPw, toggleAgent, delAgent,
      createdAgentVisible, createdAgent,
      saveBrand, savePreqOn, saveProfile, changePw, exportCsv, logout,
      loadQuick, loadWelcome, loadAuto, loadBlacklist, loadPreInq,
    };
  }
});
app.component('crud-table', CrudTable);
app.use(ElementPlus).mount('#app');
