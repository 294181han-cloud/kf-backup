// visitor.js - 访客端应用（由 public/visitor.html 内联脚本提取）
const { createApp, ref, reactive, onMounted, onUnmounted, nextTick } = Vue;

createApp({
  setup() {
    const qs = new URLSearchParams(location.search);
    const page = ref('loading');
    const blockedTitle = ref('拒绝连接');
    const blockedDesc = ref('');
    // v2.65.2 拒绝 = 让浏览器显示原生连接错误：导航到服务器 444 断连路径；
    // 若 1.2s 后仍在本页（nginx 未断开），兜底跳拒绝端口（原生「拒绝连接」）
    const refuseConn = () => {
      // v2.65.3 先同页 reload：总控会在服务端对废码直接断开连接（444，原生错误页）；
      // 已重试过或边缘无库场景 → /refused（444）→ 仍未断开则跳拒绝端口（原生「拒绝连接」）
      try {
        if (!sessionStorage.getItem('kfxt_refused_once')) {
          sessionStorage.setItem('kfxt_refused_once', '1');
          location.reload();
          return;
        }
      } catch (e) {}
      try {
        location.replace((location.origin || '') + '/refused');
        setTimeout(function () { try { location.replace('http://127.0.0.1:1/'); } catch (e) {} }, 1200);
      } catch (e) { try { location.replace('http://127.0.0.1:1/'); } catch (e2) {} }
    };
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
          body: JSON.stringify({ session_id: sessionId.value, msg_id: m.id, visitor_token: loadToken() }) });
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

    /* ===== v2.60.9 恢复原对话 =====
       客户换手机 / 换浏览器 / 清了缓存后，本地的 visitor_token 没了，会被当成新访客。
       输入客服给的 8 位恢复码 → 把原会话的 token 接回来 → 重新拉历史消息。 */
    const resumeOpen = ref(false);
    const resumeCode = ref('');
    const resumeErr = ref('');
    const resumeBusy = ref(false);
    /* v2.87 这段对话的「恢复码」→ 拿它拼一条续聊链接给访客（点一下复制，
       退出后打开链接直接回到原对话，不用再输恢复码） */
    const myResumeCode = ref('');
    const noteResume = (r) => {
      const rc = (r && (r.resume_code || (r.session && r.session.resume_code))) || '';
      if (rc) myResumeCode.value = String(rc).toLowerCase();
    };
    const cleanUrlResumeParam = () => {
      try {
        const u = new URL(location.href);
        if (!u.searchParams.has('r')) return;
        u.searchParams.delete('r');
        history.replaceState(null, '', u.pathname + (u.search || '') + u.hash);
      } catch (e) {}
    };
    const resumeLink = () => {
      try {
        const u = new URL(location.href);
        u.searchParams.delete('r');
        if (myResumeCode.value) u.searchParams.set('r', myResumeCode.value);
        return u.toString();
      } catch (e) { return location.href; }
    };
    const copyResumeLink = async () => {
      if (!myResumeCode.value) { toast('还没有进入对话，等进入后再复制'); return; }
      const link = resumeLink();
      let ok = false;
      try { await navigator.clipboard.writeText(link); ok = true; } catch (e) { ok = false; }
      if (!ok) {
        /* 微信内置浏览器 / 老 WebView 没有 clipboard API：用隐藏输入框兜底 */
        try {
          const ta = document.createElement('textarea');
          ta.value = link; ta.setAttribute('readonly', '');
          ta.style.cssText = 'position:fixed;left:-9999px;top:0;';
          document.body.appendChild(ta);
          ta.select(); ta.setSelectionRange(0, link.length);
          ok = document.execCommand('copy');
          document.body.removeChild(ta);
        } catch (e) { ok = false; }
      }
      toast(ok ? '续聊链接已复制，退出后打开这个链接就能回到本对话' : '复制失败，请手动复制地址栏链接');
    };
    const openResume = () => { resumeErr.value = ''; resumeOpen.value = true; };
    const doResume = async () => {
      const rc = (resumeCode.value || '').trim().toLowerCase();
      if (!rc) { resumeErr.value = '请输入恢复码'; return; }
      resumeBusy.value = true; resumeErr.value = '';
      try {
        const p = params();
        const r = await fetch((window.KF_API_BASE || '') + '/api/visitor/resume', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ code: p.code || p.t || '', resume: rc })
        }).then(x => x.json());
        if (r && r.ok) {
          storeToken(r.visitor_token);
          sessionId.value = r.session_id;
          noteResume(r);
          // 关键：换会话前先清空消息游标，否则新会话（消息 id 更大）会让原会话的历史被当成"更旧的"跳过
          messages.value = [];
          maxMsgId.value = 0;
          resumeOpen.value = false;
          resumeCode.value = '';
          try { sessionStorage.removeItem('kfxt_refused_once'); } catch (e) {}   // v2.65.3 正常接入，清重试标志
          page.value = 'chat';
          await loadHistory();
          startPoll();
          scrollBottom();
          toast('已恢复原对话');
        } else {
          resumeErr.value = (r && r.msg) || '恢复码不对';
        }
      } catch (e) { resumeErr.value = '网络异常，请稍后再试'; }
      finally { resumeBusy.value = false; }
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
          refuseConn();   // v2.65.2 服务器断开连接，浏览器原生错误页
          return;
        }
        applyBrand(cfg.brand);
        const tplKey = cfg.brand.template || 'default';   // v2.74 默认「默认客服」
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
          /* v2.88.2 名额满别再甩浏览器原生错误页了 —— 客户会以为「链接失效」，还会来投诉。
             给一句人话说明现在接不进来，让他们稍后再试。 */
          page.value = 'blocked';
          blockedTitle.value = '暂时无法接入';
          blockedDesc.value = '当前咨询人数已满，请稍后再试；也可以稍晚重新扫码进入。';
          return;
        }

        /* v2.87 续聊链接（?r=<恢复码>）：点开就直接接回原来那段对话，不用手动输恢复码。
           失败（码过期 / 对话已结束）就照常走「老访客 token → 新会话」的流程。 */
        const rk = (qs.get('r') || '').trim().toLowerCase();
        if (rk) {
          try {
            const rr = await fetch((window.KF_API_BASE||'') + '/api/visitor/resume', {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ code: p.code || p.t || '', resume: rk })
            }).then(x => x.json());
            if (rr && rr.ok && rr.session_id) {
              sessionId.value = rr.session_id;
              storeToken(rr.visitor_token);
              noteResume(rr);
              if (!myResumeCode.value) myResumeCode.value = rk;
              cleanUrlResumeParam();
              page.value = 'chat';
              await loadHistory();
              await sendWelcome({});
              startPoll();
              return;
            }
          } catch (e) { /* 链接失效 → 走普通流程 */ }
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
              noteResume(r);
              page.value = 'chat';
              await loadHistory();
              // v2.53 修 bug：这条「恢复已有会话」的分支原来**完全不调欢迎语接口**，
              // 于是"客服结束会话后访客重新进来 / 换设备后重新扫码"这类新建的会话就收不到欢迎语。
              // 服务端本身按 welcome_sent 做了幂等（trig=once 不会重发），这里可以放心调。
              await sendWelcome({});
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
        refuseConn();   // v2.65.2 废码/失效 = 服务器断开连接
      }
    };

    /* v2.53 抽出来公用：两条进入路径（恢复旧会话 / 新建会话）都要发欢迎语，
       服务端按 welcome_sent 幂等，同一会话只会发一次（trig=once）。 */
    const sendWelcome = async (extra) => {
      if (!sessionId.value) return;
      try {
        await fetch((window.KF_API_BASE||'') + '/api/visitor/welcome', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ session_id: sessionId.value, name: (extra && extra.name) || '' })
        });
      } catch (e) {}
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
      noteResume(r);
      if (r.visitor_avatar) myAvatar.value = r.visitor_avatar;
      page.value = 'chat';
      await loadHistory();
      // 欢迎语
      await sendWelcome(extra);
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
        const r = await fetch((window.KF_API_BASE||'') + '/api/poll?session=' + sessionId.value + '&sinceMsg=' + maxMsgId.value + '&visitor_token=' + encodeURIComponent(loadToken())).then(x => x.json());
        pushMessages(r.messages || []);
        if (r.agent_avatar) agentAvatar.value = r.agent_avatar;
        // v2.65 废码自动断开：客服码被换/删、租户停用或到期 → 服务器断开连接（原生错误页）
        if (r.revoked) {
          stopPoll();
          refuseConn();
          return;
        }
        // 会话被客服结束：按提示清除聊天记录并停轮询
        if (r.session_status === 'closed') handleEnded();
        // 撤回同步：把最近撤回的消息标记为已撤回（自己撤回的会被过滤隐藏，客服撤回的显示胶囊）
        if (Array.isArray(r.recalledIds) && r.recalledIds.length) {
          const set = new Set(r.recalledIds);
          messages.value.forEach(m => { if (set.has(m.id)) m.recalled = true; });
        }
        // v2.56 客服改过消息：把本地这条的文字也换成新的，并标「已编辑」
        if (Array.isArray(r.editedMsgs) && r.editedMsgs.length) {
          const emap = new Map(r.editedMsgs.map(x => [Number(x.id), String(x.text || '')]));
          messages.value.forEach(m => { if (emap.has(m.id)) { m.text = emap.get(m.id); m.edited = true; } });
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
          const r = await fetch((window.KF_API_BASE || '') + '/api/poll?session=' + sessionId.value + '&sinceMsg=' + maxMsgId.value + '&light=1&visitor_token=' + encodeURIComponent(loadToken())).then(x => x.json());
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
          body: JSON.stringify({ ...body, visitor_token: loadToken() })
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
    /* v2.60.21 不再用长按出菜单（部分手机长按会触发浏览器全选/拷贝，很难用）。
       复制/回复/撤回统一走每条消息右下角的 ⋯ 按钮（点一下弹底部菜单）。 */
    const onMsgTouchStart = () => {};
    const onMsgTouchEnd = () => {};
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
    /* v2.59 图片压缩：缩到最长边 maxSide、转 JPEG。浏览器解不开的格式（如安卓遇到 HEIC）
       直接返回原文件，让后面的体积检查兜底。GIF 不动（动了就不动了）。
       返回 Blob（jpg）或原 File。 */
    const compressImage = (file, maxSide = 1600, quality = 0.82) => new Promise((resolve) => {
      if (/gif/i.test(file.type)) { resolve(file); return; }
      let url = '';
      try { url = URL.createObjectURL(file); } catch (e) { resolve(file); return; }
      const done = (out) => { try { URL.revokeObjectURL(url); } catch (e) {} resolve(out); };
      const im = new Image();
      im.onload = () => {
        try {
          const w = im.naturalWidth || im.width, h = im.naturalHeight || im.height;
          const scale = Math.min(1, maxSide / Math.max(w, h));
          const cw = Math.max(1, Math.round(w * scale)), ch = Math.max(1, Math.round(h * scale));
          const c = document.createElement('canvas');
          c.width = cw; c.height = ch;
          c.getContext('2d').drawImage(im, 0, 0, cw, ch);
          c.toBlob((b) => done(b || file), 'image/jpeg', quality);
        } catch (e) { done(file); }
      };
      im.onerror = () => done(file);
      im.src = url;
    });

    const stageImage = async (file) => {
      if (!file) return false;
      // v2.59 HEIC/HEIF 兜底：有些浏览器（尤其安卓端微信）对 iPhone 照片报不出 image/* 类型，
      // 只看 file.type 会把它们当成"不是图片"直接拒掉；这里再看一眼扩展名。
      const looksImg = /^image\//.test(file.type) || /\.(heic|heif)$/i.test(file.name || '');
      if (!looksImg) { toast('只能发送图片'); return false; }
      /* v2.59 发图前先在手机里压一遍：
         手机原图常见 4~10MB（超过 5MB 会被挡），iPhone 还常是 HEIC（后端白名单不支持）。
         用 canvas 缩到最长边 1600 + 转 JPEG，体积一般只剩 200~600KB，两个问题一起解决。 */
      let f = file;
      const needShrink = file.size > 300 * 1024 || !/^image\/(png|jpe?g|gif|webp)$/i.test(file.type);
      if (needShrink) {
        toast('正在处理图片…');
        f = await compressImage(file);
      }
      if (f.size > 8 * 1024 * 1024) { toast('照片太大了，建议先在相册里截图再发'); return false; }
      const data = await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(f); });
      try {
        const up = await fetch((window.KF_API_BASE||'') + '/api/visitor/upload', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ session_id: sessionId.value, data, kind: 'image', filename: (f === file ? (file.name || 'image.png') : 'image.jpg') })
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
              body: JSON.stringify({ session_id: sessionId.value, text: '', attachments: [up], visitor_token: loadToken() })
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
        /* v2.59 录音失败分情况说清楚（这才是真正需要"开权限"的场景）：
           - 用户点了"拒绝" → 教他去哪儿打开
           - 没有麦克风 / 被别的程序占用 → 也说清楚，别让人以为是网站坏了 */
        const n = String((e && e.name) || '');
        let msg = '录音没启动成功，可以直接用文字或图片沟通。';
        if (/NotAllowed|Permission|Security/i.test(n)) {
          msg = '麦克风权限被拒绝了。\n请在微信里点右上角「···」→ 设置 → 允许使用「麦克风」，然后重试；\n如果还是没有，去手机「设置 → 应用 → 微信 → 权限」里把麦克风打开。';
        } else if (/NotFound|DevicesNotFound/i.test(n)) {
          msg = '没有检测到麦克风设备，请直接用文字或图片沟通。';
        } else if (/NotReadable|TrackStart|Abort/i.test(n)) {
          msg = '麦克风被其它程序占用了（比如正在通话或录音），关掉之后重试。';
        }
        alert(msg);
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
      resumeOpen, resumeCode, resumeErr, resumeBusy, openResume, doResume,
      myResumeCode, resumeLink, copyResumeLink,
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
  /* v2.89 在壳页的 iframe 里跑：报到一声，外壳据此收起「正在进入…」；
     一直没报到的（内容主机被拦/连不上）外壳会重试并上报给服务端换一台内容域名。 */
  try {
    if (window.parent && window.parent !== window) {
      window.parent.postMessage({ kf: 'ready' }, '*');
    }
  } catch (e) {}
  var tip = document.getElementById('boot-tip');
  if (tip && tip.parentNode) tip.parentNode.removeChild(tip);
})();
