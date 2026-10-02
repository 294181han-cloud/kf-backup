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
      if (qs.get('code')) p.code = qs.get('code');
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

    const applyBrand = (b) => {
      Object.assign(brand, b);
      if (b.theme_color) document.documentElement.style.setProperty('--theme', b.theme_color);
    };

    const storeToken = (t) => {
      visitorToken.value = t;
      const key = 'kfxt_vt_' + (qs.get('code') || qs.get('t') || location.hostname);
      try { localStorage.setItem(key, t); } catch (e) {}
    };
    const loadToken = () => {
      const key = 'kfxt_vt_' + (qs.get('code') || qs.get('t') || location.hostname);
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
    const recallMine = async (m) => {
      try {
        await fetch('/api/visitor/recall', { method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ session_id: sessionId.value, msg_id: m.id }) });
        m.recalled = true; // 仅标记，内容保留（客服端可见+撤回标记），自己端由过滤规则隐藏
      } catch (e) { alert('撤回失败：' + e.message); }
    };
    const pushMessages = (list) => {
      let added = false;
      for (const m of (list || [])) {
        if (m.id > maxMsgId.value) {
          maxMsgId.value = m.id;
          messages.value.push(m);
          added = true;
        }
      }
      if (added) scrollBottom();
    };

    // ---- 初始化流程 ----
    const boot = async () => {
      try {
        const p = params();
        if (!p.code && !p.t) {
          // 纯子域名访问也允许（后端按 host 识别）
        }
        const q = new URLSearchParams(p);
        const cfg = await fetch('/api/visitor/config?' + q.toString()).then(r => r.json());
        if (!cfg.available) {
          blockedDesc.value = cfg.unavailable_reason || '该链接已失效';
          page.value = 'blocked';
          return;
        }
        applyBrand(cfg.brand);
        const tplKey = cfg.brand.template || 'default';
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
            const r = await fetch('/api/visitor/init', {
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
            const items = await fetch('/api/visitor/pre-inquiry?' + q.toString()).then(r => r.json());
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
      const r = await fetch('/api/visitor/init', {
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
        await fetch('/api/visitor/welcome', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ session_id: sessionId.value, name: extra.name || '' })
        });
      } catch (e) {}
      await pollOnce();
      startPoll();
    };

    const loadHistory = async () => {
      const r = await fetch('/api/visitor/messages?session_id=' + sessionId.value).then(x => x.json());
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
    const pollOnce = async () => {
      if (!sessionId.value) return;
      try {
        const r = await fetch('/api/poll?session=' + sessionId.value + '&sinceMsg=' + maxMsgId.value).then(x => x.json());
        pushMessages(r.messages || []);
        // 撤回同步：把最近撤回的消息标记为已撤回（自己撤回的会被过滤隐藏，客服撤回的显示胶囊）
        if (Array.isArray(r.recalledIds) && r.recalledIds.length) {
          const set = new Set(r.recalledIds);
          messages.value.forEach(m => { if (set.has(m.id)) m.recalled = true; });
        }
      } catch (e) {}
    };
    const startPoll = () => {
      stopPoll();
      pollTimer = setInterval(pollOnce, 2500);
    };
    const stopPoll = () => { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } };

    // ---- 发送 ----
    const send = async () => {
      const text = draft.value.trim();
      if (!text || sending.value || !sessionId.value) return;
      sending.value = true;
      draft.value = '';
      try {
        const r = await fetch('/api/visitor/send', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ session_id: sessionId.value, text })
        }).then(x => { if (!x.ok) return x.json().then(j => { throw new Error(j.error); }); return x.json(); });
        if (r.message) { pushMessages([r.message]); }
        if (r.auto_reply) { pushMessages([r.auto_reply]); }
      } catch (e) {
        draft.value = text;
        alert(e.message);
      } finally { sending.value = false; }
    };

    // ---- 图片 ----
    const pickImage = () => imgInput.value && imgInput.value.click();
    const onImage = async (ev) => {
      const file = ev.target.files[0];
      ev.target.value = '';
      if (!file) return;
      if (file.size > 5 * 1024 * 1024) { alert('图片不能超过 5MB'); return; }
      const data = await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(file); });
      try {
        const up = await fetch('/api/visitor/upload', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ session_id: sessionId.value, data, kind: 'image', filename: file.name })
        }).then(x => { if (!x.ok) return x.json().then(j => { throw new Error(j.error); }); return x.json(); });
        const r = await fetch('/api/visitor/send', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ session_id: sessionId.value, text: '', attachments: [up] })
        }).then(x => x.json());
        if (r.message) pushMessages([r.message]);
      } catch (e) { alert(e.message); }
    };

    // ---- 录音 ----
    const startRec = async () => {
      if (!navigator.mediaDevices || !window.MediaRecorder) { alert('当前浏览器不支持录音，请使用文字沟通'); return; }
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
            const up = await fetch('/api/visitor/upload', {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ session_id: sessionId.value, data, kind: 'audio', filename: 'voice.webm' })
            }).then(x => { if (!x.ok) return x.json().then(j => { throw new Error(j.error); }); return x.json(); });
            const r = await fetch('/api/visitor/send', {
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
      } catch (e) {
        alert('无法获取麦克风权限，请使用文字沟通');
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

    const viewImage = (url) => { viewing.value = url; };

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
    const ui = Vue.computed(() => ({
      navOn: document.body.dataset.navOff !== '1',
      navTitle: navTitle.value,
    }));

    onMounted(boot);
    onUnmounted(stopPoll);

    return {
      page, blockedTitle, blockedDesc, brand, messages, draft, sending,
      bodyRef, inputRef, imgInput, viewing, viewImage,
      showForm, formItems, formValues, formSubmitting, submitForm,
      recording, recSecs, startRec, stopRec,
      send, pickImage, onImage, fmtTime, scrollBottom, onlineText, isImg, visibleMessages, navTitle, canRecall, recallMine,
      ui, robotType, verifyTitle, verifyBg, verifyPassed, verifyValue, verifyErr, codeText, codeInput,
      genCode, codeVerify, verifyGo, slideStart, slideMove, slideEnd, doEnter,
      pubShow, pubTitle, pubContent, linkMode, linkSegs, copyLink, myAvatar,
    };
  }
}).mount('#app');
