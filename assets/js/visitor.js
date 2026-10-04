// visitor.js - 访客端应用（由 public/visitor.html 内联脚本提取）
const { createApp, ref, reactive, onMounted, onUnmounted, nextTick } = Vue;

createApp({
  setup() {
    const qs = new URLSearchParams(location.search);
    const page = ref('loading');
    const blockedTitle = ref('暂时无法接入');
    const blockedDesc = ref('');
    const brand = reactive({ company_name: '在线客服', welcome_title: '您好，欢迎咨询！', welcome_sub: '', avatar: '💬', theme_color: '#1677ff', pre_inquiry_on: true });
    const messages = ref([]);
    const draft = ref('');
    const sending = ref(false);
    const sessionId = ref(0);
    const maxMsgId = ref(0);
    const visitorToken = ref('');
    const bodyRef = ref(null);
    const inputRef = ref(null);
    const imgInput = ref(null);
    const viewing = ref('');
    const ended = ref(false);   // 会话已被客服结束

    // 询前表单
    const showForm = ref(false);
    const formItems = ref([]);
    const formValues = reactive({});
    const formSubmitting = ref(false);

    // 录音
    const recording = ref(false);
    const recSecs = ref(0);
    let mediaRecorder = null, recChunks = [], recTimer = null, recStartAt = 0;

    let pollTimer = null;

    const params = () => {
      const p = {};
      // v2.59 防红中转：空壳页 /MM-DD/xxxx.html?key=… 会把真实页面 document.write 进来，
      // 此时地址栏里没有 code，客服码由中转页注入到 window.__KF_CODE__
      const inj = (typeof window !== 'undefined' && window.__KF_CODE__) || '';
      if (qs.get('code')) p.code = qs.get('code');
      else if (inj) p.code = inj;
      if (qs.get('t')) p.t = qs.get('t');
      return p;
    };

    const scrollBottom = () => nextTick(() => { if (bodyRef.value) bodyRef.value.scrollTop = bodyRef.value.scrollHeight; });

    const isImg = (v) => typeof v === 'string' && (v.startsWith('http') || v.startsWith('/uploads'));

    // ===== 访客端模板（只保留有真实界面结构的；纯换色的伪模板已于 v2.21 清理）=====
    const TEMPLATES = {
      default:      { label: '默认客服', nav: '在线客服',   color: '#1677ff', g1: '#1677ff', g2: '#4d9bff', dark: false },
      whatsapp:     { label: 'WhatsApp', nav: 'Customer Service', color: '#008069', g1: '#008069', g2: '#00a884', dark: false },
    };
    const applyTemplate = (key) => {
      const k = TEMPLATES[key] ? key : 'default';
      const t = TEMPLATES[k];
      document.body.dataset.tpl = k;
      document.documentElement.style.setProperty('--theme', t.color);
      document.documentElement.style.setProperty('--theme-grad', `linear-gradient(135deg, ${t.g1}, ${t.g2})`);
      const meta = document.querySelector('meta[name="theme-color"]');
      if (meta) meta.setAttribute('content', t.color);
      return t;
    };
    const fmtTime = (t) => {
      if (!t) return '';
      const d = new Date(t);
      const pad = (n) => String(n).padStart(2, '0');
      return pad(d.getHours()) + ':' + pad(d.getMinutes());
    };

    // 声音设置（v2.27，租户级）：访客端接收/发送分开（默认开启，与后台默认一致）
    const snd = reactive({ recv_on: true, recv_which: 'dindon', send_on: true, send_which: 'water' });
    const audioCache = {};
    const playSnd = (which) => {
      const key = ['water', 'pling', 'weixin', 'dindon', 'click'].includes(which) ? which : 'dindon';
      try {
        if (!audioCache[key]) audioCache[key] = new Audio('/assets/sounds/' + key + '.mp3');
        const a = audioCache[key].cloneNode();
        a.volume = 0.8;
        a.play().catch(() => {});   // 浏览器自动播放策略：未交互过会被拒，静默忽略
      } catch (e) {}
    };

    const applyBrand = (b) => {
      Object.assign(brand, b);
      if (b.theme_color) document.documentElement.style.setProperty('--theme', b.theme_color);
      snd.recv_on = !!b.snd_recv_on;
      snd.recv_which = b.snd_recv_which || 'dindon';
      snd.send_on = !!b.snd_send_on;
      snd.send_which = b.snd_send_which || 'water';
    };

    // 中转进来的访客也要跟直连用同一个本地标识 key，否则会被当成两个人
    const visitorTkKey = () => 'kfxt_vt_' + (qs.get('code')
      || (typeof window !== 'undefined' && window.__KF_CODE__) || qs.get('t') || location.hostname);
    const storeToken = (t) => {
      visitorToken.value = t;
      const key = visitorTkKey();
      try { localStorage.setItem(key, t); } catch (e) {}
    };
    const loadToken = () => {
      const key = visitorTkKey();
      try { return localStorage.getItem(key) || ''; } catch (e) { return ''; }
    };

    // 访客端不显示系统提示（转接/结束等）；欢迎语按客服消息展示
    // 自己撤回的 → 直接隐藏；客服撤回的 → 显示"对方撤回了一条消息"胶囊；欢迎语保留
    const visibleMessages = Vue.computed(() => messages.value.filter(m =>
      (m.recalled && m.from === 'visitor') ? false :
      (m.recalled && m.from === 'agent') ? true :
      (m.from !== 'system' || m.sender_name === '欢迎语')
    ));
    const canRecall = (m) => m.from === 'visitor' && !m.recalled && m.id > 0 && (Date.now() - new Date(m.time).getTime()) < 120000;
    // 日期分隔（今天 / 昨天 / M月D日）
    const dayKey = (t) => { const d = new Date(t); return d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate(); };
    const needDaySep = (mi) => {
      const list = visibleMessages.value;
      if (!list.length) return false;
      if (mi === 0) return true;
      return dayKey(list[mi].time) !== dayKey(list[mi - 1].time);
    };
    const dayText = (t) => {
      const d = new Date(t), now = new Date();
      const k = (x) => x.getFullYear() + '-' + x.getMonth() + '-' + x.getDate();
      const y = new Date(now.getTime() - 86400000);
      if (k(d) === k(now)) return '今天';
      if (k(d) === k(y)) return '昨天';
      return (d.getMonth() + 1) + '月' + d.getDate() + '日';
    };
    const recallMine = async (m) => {
      try {
        await fetch((window.KF_API_BASE||'') + '/api/visitor/recall', { method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ session_id: sessionId.value, msg_id: m.id }) });
        m.recalled = true; // 仅标记，内容保留（客服端可见+撤回标记），自己端由过滤规则隐藏
      } catch (e) { alert('撤回失败：' + e.message); }
    };
    const pushMessages = (list) => {
      let added = false;
      let gotAgent = false;
      for (const m of (list || [])) {
        if (m.id > maxMsgId.value) {
          maxMsgId.value = m.id;
          messages.value.push(m);
          added = true;
          if (m.from === 'agent') gotAgent = true;   // 客服新消息 → 接收提示音
        }
      }
      prefetchAudio(list);
      if (added) scrollBottom();
      if (gotAgent && snd.recv_on) playSnd(snd.recv_which);
    };

    // ---- 初始化流程 ----
    const boot = async () => {
      try {
        const p = params();
        if (!p.code && !p.t) {
          // 纯子域名访问也允许（后端按 host 识别）
        }
        const q = new URLSearchParams(p);
        const cfg = await fetch((window.KF_API_BASE||'') + '/api/visitor/config?' + q.toString()).then(r => r.json());
        if (!cfg.available) {
          blockedDesc.value = cfg.unavailable_reason || '该链接已失效';
          page.value = 'blocked';
          return;
        }
        applyBrand(cfg.brand);
        const tplKey = cfg.brand.template || 'whatsapp';
        window.__tpl = applyTemplate(tplKey);
        navTitle.value = TEMPLATES[tplKey] ? TEMPLATES[tplKey].nav : '在线客服';
        robotType.value = cfg.brand.robot || 'no';
        linkMode.value = cfg.brand.link_mode || 'no';
        if (!cfg.brand.tpl_nav_on) document.body.dataset.navOff = '1';
        if (cfg.brand.tpl_nav_title) navTitle.value = cfg.brand.tpl_nav_title;
        if (cfg.brand.publicity_on && cfg.brand.publicity_content) {
          const pk = 'kfxt_pub_' + (qs.get('code') || qs.get('t') || 'x');
          let seen = false; try { seen = sessionStorage.getItem(pk) === '1'; } catch (e) {}
          if (!seen) { pubTitle.value = cfg.brand.publicity_title || '公告'; pubContent.value = cfg.brand.publicity_content; pubShow.value = true; try { sessionStorage.setItem(pk, '1'); } catch (e) {} }
        }
        if (cfg.quota_full && cfg.quota_full !== '') {
          blockedDesc.value = '本周客服名额已满，请稍后再来';
          page.value = 'blocked';
          return;
        }

        // 已有会话则直接恢复
        const vt = loadToken();
        if (vt) {
          try {
            const r = await fetch((window.KF_API_BASE||'') + '/api/visitor/init', {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ ...p, visitor_token: vt, from_url: document.referrer || '直接访问' })
            }).then(x => x.json());
            if (r && r.session_id) {
              sessionId.value = r.session_id;
              storeToken(r.visitor_token);
              page.value = 'chat';
              await loadHistory();
              startPoll();
              return;
            }
          } catch (e) { /* 恢复失败走新流程 */ }
        }

        // 询前表单 or 直接入口
        if (cfg.brand.pre_inquiry_on) {
          try {
            const items = await fetch((window.KF_API_BASE||'') + '/api/visitor/pre-inquiry?' + q.toString()).then(r => r.json());
            if (Array.isArray(items) && items.length) {
              formItems.value = items;
              showForm.value = true;
              page.value = 'chat';
              return;
            }
          } catch (e) { /* 拉取失败跳过表单 */ }
        }
        await startSession({});
      } catch (e) {
        const em = String(e.message);
        if (em.includes('验证') || em.includes('邀请码') || em.includes('密码')) {
          verifyErr.value = (em.includes('邀请码') || em.includes('密码')) ? em : '';
          page.value = 'verify';
          if (robotType.value === 'code') genCode();
          return;
        }
        blockedDesc.value = em || '链接无效，请联系客服获取新的链接';
        page.value = 'blocked';
      }
    };

    const startSession = async (extra) => {
      const p = { ...params(), ...extra, from_url: document.referrer || '直接访问' };
      const vt = loadToken();
      if (vt) p.visitor_token = vt;
      if (verifyPassed.value) p.verify_passed = 1;
      if (verifyValue.value.trim()) p.verify_value = verifyValue.value.trim();
      const r = await fetch((window.KF_API_BASE||'') + '/api/visitor/init', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(p)
      }).then(x => { if (!x.ok) return x.json().then(j => { throw new Error(j.error || '接入失败'); }); return x.json(); });
      if (!r.session_id) throw new Error(r.error || '接入失败');
      sessionId.value = r.session_id;
      storeToken(r.visitor_token);
      if (r.visitor_avatar) myAvatar.value = r.visitor_avatar;
      page.value = 'chat';
      await loadHistory();
      // 欢迎语
      try {
        await fetch((window.KF_API_BASE||'') + '/api/visitor/welcome', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ session_id: sessionId.value, name: extra.name || '' })
        });
      } catch (e) {}
      await pollOnce();
      startPoll();
    };

    const loadHistory = async () => {
      const r = await fetch((window.KF_API_BASE||'') + '/api/visitor/messages?session_id=' + sessionId.value).then(x => x.json());
      pushMessages(r.messages || []);
    };

    const submitForm = async () => {
      for (const f of formItems.value) {
        if (f.required && !(formValues[f.key] || '').trim()) {
          alert('请填写：' + f.label);
          return;
        }
      }
      formSubmitting.value = true;
      try {
        await startSession({
          name: formValues.name || '',
          phone: formValues.phone || '',
          question: formValues.question || '',
        });
        showForm.value = false;
      } catch (e) {
        alert(e.message);
      } finally { formSubmitting.value = false; }
    };

    // ---- 轮询 ----
    // 客服结束会话：按结束提示清除访客端聊天记录（本地记录 + 会话令牌）
    const handleEnded = () => {
      if (ended.value) return;
      ended.value = true;
      try { stopPoll(); } catch (e) {}
      messages.value = [];
      maxMsgId.value = 0;
      draft.value = '';
      showForm.value = false;
      try { localStorage.removeItem('kfxt_vt_' + (qs.get('code') || qs.get('t') || location.hostname)); } catch (e) {}
    };
    // 重新咨询：清掉本地会话令牌后重载页面，开启一段全新会话
    const restartChat = () => { try { location.reload(); } catch (e) {} };
    const pollOnce = async () => {
      if (!sessionId.value) return;
      try {
        const r = await fetch((window.KF_API_BASE||'') + '/api/poll?session=' + sessionId.value + '&sinceMsg=' + maxMsgId.value).then(x => x.json());
        pushMessages(r.messages || []);
        if (r.agent_avatar) agentAvatar.value = r.agent_avatar;
        // 会话被客服结束：按提示清除聊天记录并停轮询
        if (r.session_status === 'closed') handleEnded();
        // 撤回同步：把最近撤回的消息标记为已撤回（自己撤回的会被过滤隐藏，客服撤回的显示胶囊）
        if (Array.isArray(r.recalledIds) && r.recalledIds.length) {
          const set = new Set(r.recalledIds);
          messages.value.forEach(m => { if (set.has(m.id)) m.recalled = true; });
        }
      } catch (e) {}
    };
    // 活动门控轮询（1+2+n 枢纽模式）：只在有人访问时轮询——活跃 2.5s，闲置逐步降频，长期闲置暂停，一有操作立即恢复
    let lastActivity = Date.now();
    const bumpActivity = () => { lastActivity = Date.now(); if (pollTimer === null && sessionId.value && page.value === 'chat') startPoll(); };
    const pollGap = () => {
      if (document.hidden) return 30000;                       // 页面切到后台
      const idle = Date.now() - lastActivity;
      if (idle > 15 * 60000) return -1;                        // 长期无操作：暂停重型轮询，保留轻量探针
      if (idle > 2 * 60000) return 15000;                      // 闲置 2 分钟
      if (idle > 30000) return 8000;                           // 闲置 30 秒
      return 2500;                                             // 活跃
    };
    const startPoll = () => {
      stopPoll();
      if (pollGap() === -1) { lightLoop(); return; }
      const tick = async () => {
        try { await pollOnce(); } catch (e) {}
        const gap = pollGap();
        if (gap === -1) { pollTimer = null; lightLoop(); return; }   // 转轻量探针：有新消息立即恢复全量轮询
        pollTimer = setTimeout(tick, gap);
      };
      pollTimer = setTimeout(tick, 100);
    };
    // 轻量探针：总控只查一个索引（有无新消息）；有新消息 → 立即恢复全量轮询
    const lightLoop = () => {
      if (pollTimer !== null) return;
      const probe = async () => {
        try {
          const r = await fetch((window.KF_API_BASE || '') + '/api/poll?session=' + sessionId.value + '&sinceMsg=' + maxMsgId.value + '&light=1').then(x => x.json());
          if (r.new) { pollTimer = null; startPoll(); return; }
        } catch (e) {}
        if (pollTimer === null) return;
        pollTimer = setTimeout(probe, 30000);
      };
      probe();
    };
    const stopPoll = () => { if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; } };
    ['click', 'keydown', 'touchstart', 'scroll', 'visibilitychange'].forEach(ev =>
      document.addEventListener(ev, bumpActivity, { passive: true }));

    // ---- 发送 ----
    // v2.57 访客端同理：中文输入法选词时回车是「上屏」，不能当发送
    const onInputKey = (e) => {
      if (e.isComposing || e.keyCode === 229) return;
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    };
    const send = async () => {
      const text = draft.value.trim();
      const atts = pendingAtts.value.slice();          // v2.43.1：待发附件跟文字一起发
      if ((!text && !atts.length) || sending.value || !sessionId.value) return;
      sending.value = true;
      draft.value = '';
      try {
        const body = { session_id: sessionId.value, text };
        if (atts.length) body.attachments = atts;
        if (replyTo.value) body.reply_to = replyTo.value.id;   // v2.43 引用回复
        const r = await fetch((window.KF_API_BASE||'') + '/api/visitor/send', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body)
        }).then(x => { if (!x.ok) return x.json().then(j => { throw new Error(j.error); }); return x.json(); });
        if (r.message) { pushMessages([r.message]); replyTo.value = null; pendingAtts.value = []; if (snd.send_on) playSnd(snd.send_which); }
        if (r.auto_reply) { pushMessages([r.auto_reply]); }
      } catch (e) {
        draft.value = text;
        if (String(e.message).includes('会话已结束')) handleEnded();
        else alert(e.message);
      } finally { sending.value = false; }
    };

    // ---- 图片 ----
    /* ===== v2.43 访客端：引用回复 / 长按菜单 / 粘贴图片 ===== */
    const replyTo = ref(null);
    const flashId = ref(0);
    const actSheet = reactive({ show: false, msg: null });
    const meName = () => (formValues && formValues.nickname) || '访客';
    const quoteOf = (m) => (m && m.reply_to) ? messages.value.find(x => x.id === m.reply_to) : null;
    const quoteWho = (m) => !m ? '' : (m.from === 'visitor' ? '我' : (brand.company_name || '客服'));
    const quoteText = (m) => {
      if (!m) return '';
      if (m.recalled) return '消息已撤回';
      const t = (m.text || '').trim();
      if (t) return t.length > 50 ? t.slice(0, 50) + '…' : t;
      const a = (m.attachments || [])[0] || {};
      return { image: '[图片]', audio: '[语音]', video: '[视频]',
        file: '[文件] ' + (a.name || ''), location: '[位置] ' + (a.name || '') }[a.type] || '[消息]';
    };
    const fmtSize = (n) => {
      const b = Number(n) || 0;
      if (b < 1024) return b + ' B';
      if (b < 1024 * 1024) return (b / 1024).toFixed(0) + ' KB';
      return (b / 1024 / 1024).toFixed(1) + ' MB';
    };
    const mapUrl = (a) => {
      if (a && a.url) return a.url;
      if (a && a.lat && a.lng) return 'https://uri.amap.com/marker?position=' + a.lng + ',' + a.lat + '&name=' + encodeURIComponent(a.name || '位置');
      return 'https://uri.amap.com/search?keyword=' + encodeURIComponent((a && a.name) || '');
    };
    const jumpTo = (id) => {
      const el = document.getElementById('vm' + id);
      if (!el) { toast('这条消息在更早的记录里'); return; }
      el.scrollIntoView({ block: 'center', behavior: 'smooth' });
      flashId.value = id;
      setTimeout(() => { if (flashId.value === id) flashId.value = 0; }, 1400);
    };
    const startReply = (m) => {
      if (!m) return;
      actSheet.show = false;
      replyTo.value = m;
      const el = inputRef.value;
      if (el) el.focus();
    };
    const openActSheet = (m) => { actSheet.msg = m; actSheet.show = true; };
    const copyMsg = async (m) => {
      actSheet.show = false;
      const t = (m && m.text) || '';
      if (!t) { toast('这条消息没有文字'); return; }
      try { await navigator.clipboard.writeText(t); toast('已复制'); } catch (e) { toast('复制失败'); }
    };
    // 长按 600ms = 打开操作菜单（移动端没有右键）
    let lpTimer = null;
    const onMsgTouchStart = (m, ev) => {
      clearTimeout(lpTimer);
      lpTimer = setTimeout(() => { openActSheet(m); }, 600);
    };
    const onMsgTouchEnd = () => { clearTimeout(lpTimer); };
    // Ctrl+V 粘贴图片直接发
    const onPaste = async (ev) => {
      if (!sessionId.value) return;
      const items = (ev.clipboardData && ev.clipboardData.items) || [];
      const files = [];
      for (const it of items) { if (it.kind === 'file') { const f = it.getAsFile(); if (f) files.push(f); } }
      if (!files.length) return;
      ev.preventDefault();
      let ok = 0;
      for (const f of files) { if (await stageImage(f)) ok++; }
      if (ok) toast('已粘贴 ' + ok + ' 张图片，可继续输入文字后一起发送');
    };
    // v2.43.1：图片先进待发区，不直接发送；发送时和文字、引用一起带出去
    const pendingAtts = ref([]);
    const rmPending = (i) => { pendingAtts.value.splice(i, 1); };
    const stageImage = async (file) => {
      if (!file) return false;
      if (!/^image\//.test(file.type)) { toast('只能发送图片'); return false; }
      if (file.size > 5 * 1024 * 1024) { toast('图片不能超过 5MB'); return false; }
      const data = await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(file); });
      try {
        const up = await fetch((window.KF_API_BASE||'') + '/api/visitor/upload', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ session_id: sessionId.value, data, kind: 'image', filename: file.name || 'image.png' })
        }).then(x => { if (!x.ok) return x.json().then(j => { throw new Error(j.error); }); return x.json(); });
        pendingAtts.value.push(up);
        return true;
      } catch (e) {
        if (String(e.message).includes('会话已结束')) handleEnded(); else toast(e.message || '上传失败');
        return false;
      }
    };

    const pickImage = () => imgInput.value && imgInput.value.click();
    const onImage = async (ev) => {
      const files = Array.from(ev.target.files || []);
      ev.target.value = '';
      if (!files.length) return;
      let ok = 0;
      for (const f of files) { if (await stageImage(f)) ok++; }
      if (ok) toast('已加入 ' + ok + ' 张图片，可继续输入文字后一起发送');
    };

    // ---- 录音 ----
    const startRec = async () => {
      if (!navigator.mediaDevices || !window.MediaRecorder) { alert('当前浏览器不支持录音，请使用文字沟通'); return false; }
      try {
        // 优先 mp4（iOS 微信兼容），否则 webm
        let mime = '';
        for (const m of ['audio/mp4', 'audio/webm;codecs=opus', 'audio/webm']) {
          if (MediaRecorder.isTypeSupported(m)) { mime = m; break; }
        }
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream);
        recChunks = [];
        mediaRecorder.ondataavailable = (e) => { if (e.data && e.data.size) recChunks.push(e.data); };
        mediaRecorder.onstop = async () => {
          stream.getTracks().forEach(t => t.stop());
          if (!recSendFlag || !recChunks.length) return;
          const blob = new Blob(recChunks, { type: mediaRecorder.mimeType || 'audio/webm' });
          if (blob.size > 10 * 1024 * 1024) { alert('语音过长'); return; }
          const data = await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(blob); });
          try {
            const up = await fetch((window.KF_API_BASE||'') + '/api/visitor/upload', {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ session_id: sessionId.value, data, kind: 'audio', filename: 'voice.webm' })
            }).then(x => { if (!x.ok) return x.json().then(j => { throw new Error(j.error); }); return x.json(); });
            const r = await fetch((window.KF_API_BASE||'') + '/api/visitor/send', {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ session_id: sessionId.value, text: '', attachments: [up] })
            }).then(x => x.json());
            if (r.message) pushMessages([r.message]);
          } catch (e) { alert(e.message); }
        };
        recSendFlag = false;
        mediaRecorder.start();
        recording.value = true;
        recSecs.value = 0;
        recStartAt = Date.now();
        recTimer = setInterval(() => {
          recSecs.value = Math.floor((Date.now() - recStartAt) / 1000);
          if (recSecs.value >= 60) stopRec(true);
        }, 500);
        return true;
      } catch (e) {
        alert('无法获取麦克风权限，请使用文字沟通');
        return false;
      }
    };
    let recSendFlag = false;
    const stopRec = (sendIt) => {
      if (!mediaRecorder) return;
      recSendFlag = sendIt;
      clearInterval(recTimer);
      recording.value = false;
      try { mediaRecorder.stop(); } catch (e) {}
    };

    // ===== 语音：按住说话（微信式，松开发送 / 上滑取消）=====
    const voiceMode = ref(false);
    const holding = ref(false);
    const holdSecs = ref(0);
    const holdCancel = ref(false);
    let holdTimer = null, holdY0 = 0;
    let holdPressed = false;   // 手指是否仍按住（麦克风授权期间可能已松手）
    const toggleVoice = () => {
      voiceMode.value = !voiceMode.value;
      if (!voiceMode.value) nextTick(() => { if (inputRef.value) inputRef.value.focus(); });
    };
    const startHold = async (e) => {
      if (holding.value || holdPressed) return;
      holdPressed = true;
      // 捕获指针：手指移出按钮也能收到 pointerup
      try { if (e.target && e.target.setPointerCapture && e.pointerId != null) e.target.setPointerCapture(e.pointerId); } catch (err) {}
      const t = (e.touches && e.touches[0]) ? e.touches[0] : e;
      holdY0 = t.clientY;
      holdCancel.value = false;
      const ok = await startRec();
      if (!ok) { holdPressed = false; voiceMode.value = false; return; }
      // 权限弹窗期间用户已经松手 → 直接取消这次录音，避免"松手后还在录"
      if (!holdPressed) { stopRec(false); return; }
      holding.value = true;
      holdSecs.value = 0;
      clearInterval(holdTimer);
      holdTimer = setInterval(() => { holdSecs.value = Math.floor((Date.now() - recStartAt) / 1000); }, 200);
    };
    const moveHold = (e) => {
      if (!holding.value) return;
      const t = (e.touches && e.touches[0]) ? e.touches[0] : e;
      holdCancel.value = (holdY0 - t.clientY) > 56;   // 上滑 56px 进入取消
    };
    const endHold = () => {
      holdPressed = false;
      if (!holding.value) return;
      clearInterval(holdTimer);
      const secs = Math.floor((Date.now() - recStartAt) / 1000);
      const tooShort = secs < 1;
      const cancel = holdCancel.value || tooShort;
      holding.value = false;
      holdCancel.value = false;
      stopRec(!cancel);
      if (tooShort && !holdCancel.value) toast('说话时间太短');
    };

    // ===== 轻提示 =====
    const toastMsg = ref('');
    let toastTimer = null;
    const toast = (t) => {
      toastMsg.value = t;
      clearTimeout(toastTimer);
      toastTimer = setTimeout(() => { toastMsg.value = ''; }, 1800);
    };

    // ===== 语音气泡（播放键 + 波形 + 秒数，替代原生 audio 控件）=====
    const audioPlaying = ref('');
    const audioDur = reactive({});
    let curAudio = null;
    // 固定长度的伪波形（按 url 生成，稳定不变）
    const waveBars = (url) => {
      let h = 0; const s = String(url || '');
      for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) % 9973;
      const out = [];
      for (let i = 0; i < 14; i++) { h = (h * 1103515245 + 12345) % 2147483648; out.push(6 + (h % 14)); }
      return out;
    };
    const prefetchAudio = (list) => {
      (list || []).forEach(m => (m.attachments || []).forEach(a => {
        if (a.type !== 'audio' || audioDur[a.url] != null) return;
        audioDur[a.url] = 0;
        try {
          const el = new Audio(); el.preload = 'metadata'; el.src = a.url;
          el.onloadedmetadata = () => { audioDur[a.url] = Math.max(1, Math.round(el.duration || 0)); };
        } catch (e) {}
      }));
    };
    const toggleAudio = (url) => {
      try {
        if (curAudio && audioPlaying.value === url) { curAudio.pause(); audioPlaying.value = ''; return; }
        if (curAudio) { try { curAudio.pause(); } catch (e) {} }
        curAudio = new Audio(url);
        curAudio.onloadedmetadata = () => { audioDur[url] = Math.max(1, Math.round(curAudio.duration || 0)); };
        curAudio.onended = () => { audioPlaying.value = ''; };
        curAudio.play().then(() => { audioPlaying.value = url; }).catch(() => { audioPlaying.value = ''; toast('播放失败，请重试'); });
      } catch (e) { toast('播放失败'); }
    };

    // ===== 首屏快捷提问 =====
    const SUGGEST = ['你好，在吗？', '我想咨询一下', '怎么联系你们？'];
    const suggest = (t) => {
      voiceMode.value = false;
      draft.value = t;
      nextTick(() => { if (inputRef.value) inputRef.value.focus(); scrollBottom(); });
    };

    const viewImage = (url) => { viewing.value = url; imgZoom.value = 1; };
    // v2.73 一条消息里的图片（多图并排，文字在下面）
    const imgsOf = (m) => ((m && m.attachments) || []).filter(a => a && (!a.type || a.type === 'image') && a.url);
    /* v2.69 看图：把当前聊天里的图片排一列，能左右翻（键盘 ← → 也行），按钮全部用文字 */
    const imgZoom = ref(1);
    const viewImages = Vue.computed(() => {
      const out = [];
      (messages.value || []).forEach(m => (m.attachments || []).forEach(a => {
        if (a && a.type === 'image' && a.url) out.push(a.url);
      }));
      return out;
    });
    const viewIdx = Vue.computed(() => viewImages.value.indexOf(viewing.value));
    const showToast = (t, ms = 1200) => { toastMsg.value = t; clearTimeout(toastTimer); toastTimer = setTimeout(() => { toastMsg.value = ''; }, ms); };
    const imgGo = (step) => {
      const list = viewImages.value;
      if (list.length < 2) { showToast('只有这一张图'); return; }
      let i = list.indexOf(viewing.value);
      if (i < 0) i = 0;
      i = (i + step % list.length + list.length) % list.length;
      viewing.value = list[i];
      imgZoom.value = 1;
      showToast('第 ' + (i + 1) + ' / ' + list.length + ' 张');
    };
    const imgKey = (e) => {
      if (!viewing.value) return;
      if (e.key === 'ArrowLeft')  { e.preventDefault(); imgGo(-1); }
      else if (e.key === 'ArrowRight') { e.preventDefault(); imgGo(1); }
      else if (e.key === 'Escape') { viewing.value = ''; }
      else if (e.key === '+' || e.key === '=') { imgZoom.value = Math.min(6, imgZoom.value + 0.25); }
      else if (e.key === '-' || e.key === '_') { imgZoom.value = Math.max(0.3, imgZoom.value - 0.25); }
    };
    onMounted(() => window.addEventListener('keydown', imgKey));

    // ===== 滚动状态：不在底部时显示「回到最新」按钮 =====
    const atBottom = ref(true);
    const onBodyScroll = () => {
      const el = bodyRef.value; if (!el) return;
      atBottom.value = (el.scrollHeight - el.scrollTop - el.clientHeight) < 60;
    };
    const scrollBottomNow = () => { scrollBottom(); atBottom.value = true; };
    // 输入框随内容自动长高（最多 5 行）
    const onDraftInput = (e) => {
      const el = e && e.target; if (!el) return;
      el.style.height = 'auto';
      el.style.height = Math.min(110, el.scrollHeight) + 'px';
    };

    const onlineText = ref('客服在线');
    const navTitle = ref('在线客服');

    // ===== 验证策略 =====
    const robotType = ref('no');
    const verifyTitle = ref('安全验证');
    const verifyBg = ref('');
    const verifyPassed = ref(false);
    const verifyValue = ref('');
    const verifyErr = ref('');
    const codeText = ref('');
    const codeInput = ref('');
    const genCode = () => {
      const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
      codeText.value = Array.from({length: 4}, () => chars[Math.floor(Math.random()*chars.length)]).join('');
    };
    const codeVerify = () => {
      if (codeInput.value.toUpperCase() === codeText.value.toUpperCase()) { verifyPassed.value = true; verifyErr.value = ''; doEnter(); }
      else { verifyErr.value = '验证码错误'; genCode(); codeInput.value = ''; }
    };
    const verifyGo = async () => {
      if (!verifyValue.value.trim()) { verifyErr.value = '请输入'; return; }
      verifyErr.value = '';
      await doEnter({ verify_value: verifyValue.value.trim() });
    };
    // 滑块
    let slideDragging = false, slideStartX = 0, slideW = 0;
    const slideStart = (e) => { slideDragging = true; slideStartX = e.clientX; slideW = e.target.parentElement.clientWidth - 44; e.target.setPointerCapture(e.pointerId); };
    const slideMove = (e) => {
      if (!slideDragging) return;
      let dx = Math.max(0, Math.min(slideW, e.clientX - slideStartX));
      e.target.style.left = (3 + dx) + 'px';
      if (dx >= slideW - 2) { slideDragging = false; verifyPassed.value = true; doEnter(); }
    };
    const slideEnd = (e) => {
      if (!verifyPassed.value && e.target) e.target.style.left = '3px';
      slideDragging = false;
    };
    // 验证通过后进入（无询前表单直接入口场景）
    const doEnter = async (extra) => {
      try {
        await startSession(extra || {});
        page.value = 'chat';
      } catch (e) {
        verifyErr.value = e.message;
        if (robotType.value === 'code') { genCode(); codeInput.value = ''; }
      }
    };

    // ===== 弹窗公告 =====
    const pubShow = ref(false);
    const pubTitle = ref('');
    const pubContent = ref('');

    // ===== 链接处理 =====
    const linkMode = ref('no');
    const URL_RE = /(https?:\/\/[^\s<>"']+)/g;
    const linkSegs = (text) => {
      const segs = []; let last = 0; let mm;
      URL_RE.lastIndex = 0;
      while ((mm = URL_RE.exec(text)) !== null) {
        if (mm.index > last) segs.push({ t: text.slice(last, mm.index), isUrl: false });
        segs.push({ t: mm[1], isUrl: true });
        last = mm.index + mm[1].length;
      }
      if (last < text.length) segs.push({ t: text.slice(last), isUrl: false });
      return segs;
    };
    const copyLink = (url) => { navigator.clipboard && navigator.clipboard.writeText(url); alert('链接已复制，请在浏览器中打开'); };

    // ===== 访客自己头像 =====
    const myAvatar = ref('');
    const myName = ref('');
    // ===== 接待客服头像（poll 返回；子客服/主账号/品牌 三级由后端解析）=====
    const agentAvatar = ref('');
    const ui = Vue.computed(() => ({
      navOn: document.body.dataset.navOff !== '1',
      navTitle: navTitle.value,
    }));

    onMounted(boot);
    onUnmounted(stopPoll);

    return {
      page, blockedTitle, blockedDesc, brand, messages, draft, sending, agentAvatar,
      bodyRef, inputRef, imgInput, viewing, viewImage,
      imgZoom, viewImages, viewIdx, imgGo,
      imgsOf,
      showForm, formItems, formValues, formSubmitting, submitForm,
      recording, recSecs, startRec, stopRec,
      voiceMode, holding, holdSecs, holdCancel, toggleVoice, startHold, moveHold, endHold,
      toastMsg, SUGGEST, suggest,
      send, pickImage, onImage, fmtTime, scrollBottom, onlineText, isImg, visibleMessages, navTitle, canRecall, recallMine,
      onInputKey,
      needDaySep, dayText,
      atBottom, onBodyScroll, scrollBottomNow, onDraftInput,
      audioPlaying, audioDur, waveBars, toggleAudio,
      ui, robotType, verifyTitle, verifyBg, verifyPassed, verifyValue, verifyErr, codeText, codeInput,
      genCode, codeVerify, verifyGo, slideStart, slideMove, slideEnd, doEnter,
      pubShow, pubTitle, pubContent, linkMode, linkSegs, copyLink, myAvatar,
      ended, handleEnded, restartChat,
      replyTo, quoteOf, quoteWho, quoteText, fmtSize, mapUrl, jumpTo, startReply,
      actSheet, openActSheet, copyMsg, onMsgTouchStart, onMsgTouchEnd, onPaste, flashId,
      pendingAtts, rmPending,
    };
  }
}).mount('#app');

/* v2.52 挂载成功标记：① 去掉"正在接入"兜底提示；② 中转壳页据此判断这页真的跑起来了 */
(function () {
  window.__KF_MOUNTED__ = 1;
  var tip = document.getElementById('boot-tip');
  if (tip && tip.parentNode) tip.parentNode.removeChild(tip);
})();
