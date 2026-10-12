// app.js - 客服工作台主应用（createApp + setup；由 public/app.html 内联脚本提取）
// 依赖顺序：vue.global.js -> element-plus.js -> qrcode -> qr-poster -> api.js -> app-components.js -> 本文件
const app = createApp({
  setup() {
    const tab = ref('work');
    // 设置页内的子标签（内容 / 访客 / 外观 / 系统）
    const setTab = ref('quick');
    const SET_ITEMS = [
      { v: 'quick', n: '快捷消息', i: '⚡' },
      { v: 'welcome', n: '欢迎语', i: '👋' },
      { v: 'auto', n: '智能回复', i: '🤖' },
      { v: 'broadcast', n: '群发模板', i: '📢', t: 1 },
      { v: 'preinq', n: '询前表单', i: '📝', t: 1 },
      { v: 'policy', n: '访客策略', i: '🛡️', t: 1 },
      { v: 'blacklist', n: 'IP黑名单', i: '🚫', t: 1 },
      { v: 'brand', n: '访客端模板', i: '🎨', t: 1 },
      { v: 'ui', n: '后台UI', i: '🖌️', t: 1 },
      { v: 'agents', n: '坐席管理', i: '🎧', t: 1 },
      { v: 'security', n: '安全与维护', i: '🔒', t: 1 },
      { v: 'profile', n: '个人设置', i: '⚙️' },
    ];
    const SET_GROUP_DEF = [
      { g: '内容', vs: ['quick', 'welcome', 'auto', 'broadcast'] },
      { g: '访客', vs: ['preinq', 'policy', 'blacklist'] },
      { g: '外观', vs: ['brand', 'ui'] },
      { g: '系统', vs: ['agents', 'security', 'profile'] },
    ];
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
    // 图片查看器（v2.28）：滚轮缩放 / 旋转 / 下载
    const imgZoom = ref(1);
    const imgRot = ref(0);
    // v2.55 图片查看器：复制到剪贴板 + 一闪而过的提示（默认 0.8 秒）
    const imgToast = ref('');
    let imgToastTimer = null;
    const showImgToast = (t, ms = 800) => {
      imgToast.value = t;
      clearTimeout(imgToastTimer);
      imgToastTimer = setTimeout(() => { imgToast.value = ''; }, ms);
    };
    const copyImage = async (url) => {
      if (!url) return;
      if (!navigator.clipboard || !window.ClipboardItem) { showImgToast('浏览器不支持复制，请用下载'); return; }
      try {
        // v2.48 图片在总控（绝对地址）、工作台可能在边缘域名上 → 必须走 CORS 才读得到
        // （总控 nginx 已给 /uploads/ 加了 Access-Control-Allow-Origin）
        const blob = await fetch(url, { mode: 'cors', credentials: 'omit' }).then(r => {
          if (!r.ok) throw new Error('取图失败 ' + r.status);
          return r.blob();
        });
        let out = blob;
        if (blob.type !== 'image/png') {
          // 剪贴板大多只认 PNG：其它格式先画到 canvas 再转
          out = await new Promise((res, rej) => {
            const im = new Image();
            im.crossOrigin = 'anonymous';   // 不加这句跨域图会污染 canvas，toBlob 直接报错
            im.onload = () => {
              const c = document.createElement('canvas');
              c.width = im.naturalWidth; c.height = im.naturalHeight;
              c.getContext('2d').drawImage(im, 0, 0);
              try { c.toBlob(b => (b ? res(b) : rej(new Error('转换失败'))), 'image/png'); }
              catch (err) { rej(err); }
            };
            im.onerror = () => rej(new Error('图片加载失败'));
            im.src = url;
          });
        }
        await navigator.clipboard.write([new ClipboardItem({ 'image/png': out })]);
        showImgToast('✅ 已复制图片');
      } catch (e) {
        // 浏览器不支持的场景（如 Safari 旧版 / 非 https）明确提示，别让人以为是图坏了
        const why = String((e && e.message) || '');
        showImgToast(/权限|denied|NotAllowed/i.test(why) ? '浏览器没给剪贴板权限，请用「下载」'
          : (/取图失败|load|CORS/i.test(why) ? '图片跨域取不到，请用「下载」' : '复制失败，请用「下载」'));
      }
    };
    const imgWheel = (e) => { imgZoom.value = Math.max(0.2, Math.min(6, imgZoom.value + (e.deltaY < 0 ? 0.15 : -0.15))); };
    /* v2.69 图片查看器：把当前会话里的图片排成一列，可以左右翻。
       —— 键盘 ← → 翻页、Esc 关闭、+/- 缩放；界面上也给了文字按钮和「第 x / y 张」。 */
    const viewImages = computed(() => {
      const out = [];
      (visibleMsgs.value || []).forEach(m => {
        (m.attachments || []).forEach(a => {
          if (a && a.type === 'image' && a.url && !a.deleted) out.push(a.url);
        });
      });
      return out;
    });
    const viewIdx = computed(() => viewImages.value.indexOf(viewing.value));
    const imgGo = (step) => {
      const list = viewImages.value;
      if (list.length < 2) { showImgToast('这条会话只有这一张图'); return; }
      let i = list.indexOf(viewing.value);
      if (i < 0) i = 0;
      i = (i + step % list.length + list.length) % list.length;
      viewing.value = list[i];
      imgZoom.value = 1; imgRot.value = 0;
      showImgToast('第 ' + (i + 1) + ' / ' + list.length + ' 张', 600);
    };
    const imgKey = (e) => {
      if (!viewing.value) return;
      const t = e.target || {};
      const tag = (t.tagName || '').toLowerCase();
      if (tag === 'input' || tag === 'textarea' || t.isContentEditable) return;
      if (e.key === 'ArrowLeft')       { e.preventDefault(); imgGo(-1); }
      else if (e.key === 'ArrowRight') { e.preventDefault(); imgGo(1); }
      else if (e.key === 'Escape')     { viewing.value = ''; }
      else if (e.key === '+' || e.key === '=') { imgZoom.value = Math.min(6, imgZoom.value + 0.2); }
      else if (e.key === '-' || e.key === '_') { imgZoom.value = Math.max(0.2, imgZoom.value - 0.2); }
      else if (e.key === '0')          { imgZoom.value = 1; imgRot.value = 0; }
    };
    const downloadImage = (url) => {
      if (!url) return;
      const a = document.createElement('a');
      a.href = url; a.download = 'image-' + Date.now() + '.png';
      a.target = '_blank';
      document.body.appendChild(a); a.click(); a.remove();
    };
    const profile = ref(null);
    const contactList = ref([]);
    const noteDraft = ref('');
    const transferDlg = ref(false);
    const transferTo = ref(null);
    const agentOptions = ref([]);

    // 快捷回复
    const quickMsgs = ref([]);
    /* v2.90.48 快捷消息分组：聊天里那排按钮按「分组」显示，鼠标移到某个分组上就直接切到那一组
       （触屏点一下也一样），选中哪个分组会记住。只有一个分组时完全不显示分组条，保持简洁。 */
    const quickGroups = computed(() => {
      const out = [];
      (quickMsgs.value || []).forEach(m => {
        const g = (m.group_name || '通用');
        if (!out.includes(g)) out.push(g);
      });
      return out;
    });
    const quickGroup = ref((function () { try { return localStorage.getItem('kfxt_qgroup') || ''; } catch (e) { return ''; } })());
    const curGroup = computed(() => quickGroups.value.includes(quickGroup.value) ? quickGroup.value : (quickGroups.value[0] || '通用'));
    const visibleQuick = computed(() => (quickMsgs.value || []).filter(m => (m.group_name || '通用') === curGroup.value));
    const multiGroup = computed(() => quickGroups.value.length > 1);
    /* v2.90.64 右侧面板顶部两个页签：访客信息 / 快捷消息（上次看的是哪个记在本机） */
    const infoTab = ref((function () {
      try { return localStorage.getItem('kfxt_info_tab') === 'quick' ? 'quick' : 'info'; } catch (e) { return 'info'; }
    })());
    Vue.watch(infoTab, (v) => { try { localStorage.setItem('kfxt_info_tab', v); } catch (e) {} });
    /* v2.90.67 快捷消息常驻区能不能收起：
       ① 「设置 → 快捷消息」里的开关 = 账号默认（存库，所有设备/子客服都跟着）；
       ② 输入框那排图标里的 ⚡ 按钮 = 本机临时收起/展开（记在这台浏览器，不动账号设置）。 */
    const quickBarOn = ref(true);        // 账号默认（设置页那个开关绑它）
    const quickBarOpen = ref((function () {   // 本机现在是不是展开着
      try { const v = localStorage.getItem('kfxt_qbar'); return v === null ? true : v === '1'; } catch (e) { return true; }
    })());
    const saveQuickBar = async () => {
      try {
        await API.put('/api/tenant/settings', { quick_bar_on: quickBarOn.value });
        ElementPlus.ElMessage.success(quickBarOn.value ? '已开启：工作台常显快捷消息' : '已关闭：工作台默认收起（只留一条⚡）');
      } catch (e) { ElementPlus.ElMessage.error(e.message || '保存失败'); }
      // 本机也跟着切过去，免得跟缓存里存的状态打架
      quickBarOpen.value = quickBarOn.value;
      try { localStorage.setItem('kfxt_qbar', quickBarOn.value ? '1' : '0'); } catch (e) {}
    };
    const toggleQuickBar = () => {
      quickBarOpen.value = !quickBarOpen.value;
      try { localStorage.setItem('kfxt_qbar', quickBarOpen.value ? '1' : '0'); } catch (e) {}
    };
    const pickGroup = (g) => {
      quickGroup.value = g;
      try { localStorage.setItem('kfxt_qgroup', g); } catch (e) {}
      const wrap = document.querySelector('.qk-list');
      if (wrap) wrap.scrollLeft = 0;
    };
    /* v2.90.54 快捷消息改成「往上弹的竖列表」：不再左右横滑（横滑会蹭到分组切换），
       内容竖着列、多了上下滚，分组点一下切，搜索/拖动排序都在这个面板里。 */
    const quickPanelKw = ref('');
    const quickPanelList = computed(() => {
      const kw = quickPanelKw.value.trim().toLowerCase();
      const list = visibleQuick.value;
      if (kw === '') return list;
      return list.filter(q => ((q.title || '') + ' ' + (q.content || '')).toLowerCase().includes(kw));
    });
    /** 面板里把命中的关键词高亮（先转义，防注入） */
    const qkHi = (text) => {
      const s = escHtml(text == null ? '' : text);
      const kw = quickPanelKw.value.trim();
      if (kw === '') return s;
      const safe = escHtml(kw).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      try { return s.replace(new RegExp(safe, 'gi'), m => '<mark>' + m + '</mark>'); } catch (e) { return s; }
    };

    // CRUD 数据
    const welcomeMsgs = ref([]);
    const autoReplies = ref([]);
    const blacklist = ref([]);
    const preInq = ref([]);
    const preqOn = ref(true);

    // 租户
    const links = ref(null);
    const agents = ref([]);
    const brand = reactive({ company_name: '', title: '', sub: '', avatar: '💬', color: '#1677ff', tpl: 'default', tpl_nav_on: true, tpl_nav_title: '', vip_badge: '', footer_text: '', agent_avatar_on: true });
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
      broadcast: '群发', blacklist: 'IP 黑名单', preinq: '询前表单', links: '客服码', agents: '子客服管理',
      brand: '访客端模板', policy: '访客策略', visitors: '访客列表', security: '安全与维护', profile: '个人设置', ui: '后台 UI',
      quick: '快捷消息', auto: '智能回复', agents: '坐席管理', broadcast: '群发模板', links: '客服码',
      setting: '设置 · ' + ((SET_ITEMS.find(x => x.v === setTab.value) || {}).n || ''),
    }[tab.value]));
    /* v2.88.3 正在聊的会话不要因为「列表里暂时没有它」就整块变空：
       以前列表刷新（切页签、会话暂时被过滤掉）会让 current 变 null，聊天区突然变成"选择左侧会话开始接待"。
       现在保留一份快照，只要聊天没换人，面板就一直留着。 */
    const curSnap = ref(null);
    const current = computed(() => {
      const hit = sessions.value.find(s => s.id === currentId.value);
      if (hit) return hit;
      return (curSnap.value && curSnap.value.id === currentId.value) ? curSnap.value : null;
    });
    Vue.watch(current, (v) => { if (v) curSnap.value = v; });
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
    /* v2.45 会话列表右上角的时间：今天只显示 14:39、今年显示 10-04、更早才带年份，
       少占宽度，把地方让给访客名字 */
    const sessTime = (t) => {
      if (!t) return '';
      const d = new Date(t), now = new Date();
      const hm = `${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
      const sameDay = d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
      if (sameDay) return hm;
      if (d.getFullYear() === now.getFullYear()) return `${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
      return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    };
    const fmtTime = shortTime;
    const sourceText = (s) => ({ app: '客服码', web: '网页链接', direct: '直接访问' }[s] || s);
    // 设置页子导航（按角色过滤租户专属项）
    const setGroups = computed(() => {
      const isTenant = !!(me.value && me.value.role === 'tenant');
      return SET_GROUP_DEF.map(g => ({
        g: g.g,
        items: g.vs.map(v => SET_ITEMS.find(x => x.v === v)).filter(it => it && (!it.t || isTenant)),
      })).filter(g => g.items.length);
    });
    // 侧栏直接铺开（中文分组名更直观），不再用顶部子导航
    const NAV_GROUP_LABELS = { '内容': '内 容', '访客': '访 客', '外观': '外 观', '系统': '系 统' };
    const navGroups = computed(() => setGroups.value.map(g => ({ g: NAV_GROUP_LABELS[g.g] || g.g, items: g.items })));
    const openSetting = (v) => {
      tab.value = 'setting';
      if (v) setTab.value = v;
      if (setTab.value === 'security') loadSecurityPage();
      if (setTab.value === 'brand') loadBrand();   // 公告横幅也在这个页，进来先拉一次最新值
    };
    const switchSetting = (v) => {
      setTab.value = v;
      if (v === 'security') loadSecurityPage();
      if (v === 'brand') loadBrand();
    };
    // 会话列表/会话头：按来访设备/平台给一个图标，比纯文字更好认
    // v2.65 「等待接入...」是访客刚打开聊天页、还没说话时的占位（visitor.php 建的初始 last_msg），
    // 列表里不显示它，免得看着像一条系统提示；等他真发了消息，这里自然就有内容了。
    const sessPreview = (s) => {
      const t = ((s && s.lastMsg) || '').trim();
      return t === '等待接入...' ? '' : t;
    };
    const sessIcon = (s) => {
      const u = String((s && (s.ua || '')) || '') + ' ' + String((s && (s.source || '')) || '');
      if (/MicroMessenger|微信/i.test(u)) return '💬';
      if (/aweme|抖音|TikTok/i.test(u)) return '🎵';
      if (/QQBrowser|QQ\//i.test(u)) return '🐧';
      if (/Alipay|支付宝|DingTalk|钉钉/i.test(u)) return '💙';
      if (/iPhone|iPad|iOS|Macintosh|macOS/i.test(u)) return '🍎';
      if (/Android|安卓/i.test(u)) return '🤖';
      if (/Windows/i.test(u)) return '🪟';
      return '🙋';
    };
    const contactLabel = (t) => ({ note: '备注', mobile: '手机', qq: 'QQ', wechat: '微信', email: '邮箱', other: '其他' }[t] || t);
    const barW = (v, list) => { const max = Math.max(1, ...list.map(x => x.count)); return Math.round(v / max * 100) + '%'; };
    const quotaW = (u, l) => Math.min(100, l > 0 ? Math.round(u / l * 100) : 100) + '%';
    /* v2.88.2 名额提醒：客服码/网页名额快满或已满时，工作台顶部自己提醒一条 ——
       原来要等客户反馈「扫码进不来」才知道额度用完了。 */
    const quotaWarn = computed(() => {
      const m = me.value;
      if (!m || m.role !== 'tenant') return null;
      const list = [
        { k: '客服码', u: Number(m.used_app || 0), l: Number(m.quota_app || 0) },
        { k: '网页', u: Number(m.used_web || 0), l: Number(m.quota_web || 0) },
      ].filter(x => x.l > 0);
      const fmt = (arr) => arr.map(x => x.k + ' ' + x.u + ' / ' + x.l).join('、');
      const full = list.filter(x => x.u >= x.l);
      if (full.length) {
        return { level: 'full', text: fmt(full) + ' 名额已满 —— 新客户从这些渠道进来会被挡在外面（客户会看到「暂时无法接入」）' };
      }
      const near = list.filter(x => x.u >= Math.floor(x.l * 0.9));
      if (near.length) {
        return { level: 'near', text: fmt(near) + ' 名额快满了，满了以后新客户就进不来' };
      }
      return null;
    });
    // v2.47 趋势图：新会话 / 活跃会话 两条共用同一刻度（取两者最大值），否则两条没法比
    const trendMax = Vue.computed(() => Math.max(1, ...((stats.value && stats.value.trend7) || [])
        .map(d => Math.max(d.count || 0, d.active || 0))));
    const trendW = (v) => Math.round(((Number(v) || 0) / trendMax.value) * 100) + '%';
    // v2.47.1 图表/明细都按「新日期在上」展示（接口仍按时间正序返回，只在这里倒过来）
    const trendDesc = Vue.computed(() => ((stats.value && stats.value.trend7) || []).slice().reverse());
    const dailyDesc = Vue.computed(() => ((stats.value && stats.value.daily) || []).slice().reverse());

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
    // v2.50 拖动排序：表格拖完把新顺序（id 数组）存库，再重新拉一次让聊天窗口的按钮同步
    const saveQuickOrder = async (ids) => {
      if (!Array.isArray(ids) || !ids.length) return;
      try {
        await API.post('/api/quick-msgs/reorder', { ids });
        await loadQuick();
        ElementPlus.ElMessage.success('顺序已保存');
      } catch (e) {
        ElementPlus.ElMessage.error(e.message || '保存顺序失败');
        await loadQuick();   // 失败就回到服务端的真实顺序，避免界面和数据不一致
      }
    };
    // 快捷消息：控制台里一行输入直接添加（不用开弹窗）
    const qa = reactive({ title: '', content: '', saving: false });
    // v2.73 修 bug：聊天里的弹窗绑的是 qe，但这里只读 qa，导致「在聊天里设置快捷消息」永远加不进去
    const quickAdd = async (form) => {
      // 注意：@click="quickAdd" 会把事件对象传进来，只认「有 title 字段」的表单对象
      const f = (form && typeof form === 'object' && 'title' in form) ? form : qa;
      const atts = (f === qe) ? qeImgs.value.slice() : [];
      const title = (f.title || '').trim();
      if (!title) { ElementPlus.ElMessage.warning('请填写按钮文字'); return; }
      qa.saving = true;
      try {
        await API.post('/api/quick-msgs', { title, content: (f.content || '').trim(), group_name: '通用', sort: 0, attachments: atts });
        f.title = ''; f.content = '';
        if (f === qe) qeImgs.value = [];
        await loadQuick();
        ElementPlus.ElMessage.success('已添加，聊天窗口里一点就发');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { qa.saving = false; }
    };
    // 聊天里的「快捷消息 → 设置」弹窗：增删改 + 直接发送，不用离开会话
    const quickDlg = ref(false);
    const qe = reactive({ title: '', content: '' });
    const qeImgs = ref([]);          // 新增那条待发的图片（可直接粘贴）
    const quickFocus = ref(0);       // 0 = 新增行；>0 = 正在编辑哪一条
    const quickImgInput = ref(null);
    let quickImgTarget = 0;
    const openQuickDlg = async () => { quickDlg.value = true; qeImgs.value = []; quickFocus.value = 0; await loadQuick(); };
    const quickUpload = async (file) => {
      const data = await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(file); });
      return API.post('/api/upload', { data, kind: 'image', filename: file.name || 'image.png' });
    };
    const quickAttachTo = async (target, up) => {
      if (target === 0) { qeImgs.value.push(up); return; }
      const q = quickMsgs.value.find(x => x.id === target);
      if (q) { q.attachments = q.attachments || []; q.attachments.push(up); await quickSave(q); }
    };
    const quickPickImg = (q) => {
      quickImgTarget = q ? q.id : 0;
      nextTick(() => { if (quickImgInput.value) quickImgInput.value.click(); });
    };
    const quickOnImg = async (ev) => {
      const file = ev.target.files[0]; ev.target.value = '';
      if (!file) return;
      if (!/^image\//.test(file.type)) { ElementPlus.ElMessage.warning('只能上传图片'); return; }
      if (file.size > 5 * 1024 * 1024) { ElementPlus.ElMessage.warning('图片不能超过 5MB'); return; }
      try { await quickAttachTo(quickImgTarget, await quickUpload(file)); ElementPlus.ElMessage.success('图片已加上'); }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    // Ctrl+V 粘贴图片：贴到「刚才点过的那一条」上（没点过就是新增那条）
    const quickPaste = async (ev) => {
      const items = (ev.clipboardData && ev.clipboardData.items) || [];
      const files = [];
      for (const it of items) { if (it.kind === 'file') { const f = it.getAsFile(); if (f && /^image\//.test(f.type)) files.push(f); } }
      if (!files.length) return;
      ev.preventDefault();
      const target = quickFocus.value;
      let n = 0;
      for (const f of files) {
        try { await quickAttachTo(target, await quickUpload(f)); n++; }
        catch (e) { ElementPlus.ElMessage.error(e.message); }
      }
      if (n) ElementPlus.ElMessage.success('已粘贴 ' + n + ' 张图片' + (target === 0 ? '，点「添加」保存这条' : ''));
    };
    const quickRmAtt = async (q, i) => {
      if (!q.attachments) return;
      q.attachments.splice(i, 1);
      await quickSave(q);
    };
    const quickSave = async (q) => {
      try {
        await API.put('/api/quick-msgs/' + q.id, { title: q.title, content: q.content, group_name: q.group_name || '通用', sort: q.sort || 0, attachments: q.attachments || [] });
        ElementPlus.ElMessage.success('已保存');
      } catch (e) { ElementPlus.ElMessage.error(e.message); await loadQuick(); }
    };
    const quickDel = async (q) => {
      try { await API.del('/api/quick-msgs/' + q.id); await loadQuick(); ElementPlus.ElMessage.success('已删除'); }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const quickSendNow = async (q) => {
      if (!currentId.value) { ElementPlus.ElMessage.warning('请先选择会话'); return; }
      try {
        await API.post('/api/sessions/' + currentId.value + '/messages', { text: q.content || q.title, attachments: (q.attachments || []).length ? q.attachments : undefined });
        const m = await API.get('/api/sessions/' + currentId.value + '/messages?since=' + maxMsgId.value);
        const list = m.messages || [];
        if (list.length) { msgs.value.push(...list); maxMsgId.value = list[list.length - 1].id; prefetchAudio(list); pinBottom(1200); }
        sendBeep();
        quickDlg.value = false;
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const loadWelcome = async () => { try { welcomeMsgs.value = await API.get('/api/welcome-messages'); } catch (e) {} };
    const loadAuto = async () => { try { autoReplies.value = await API.get('/api/auto-reply'); } catch (e) {} };
    const loadBlacklist = async () => { try { blacklist.value = await API.get('/api/ip-blacklist'); } catch (e) {} };
    const loadPreInq = async () => { try { preInq.value = await API.get('/api/pre-inquiry'); } catch (e) {} };
    const loadStats = async () => { try { stats.value = await API.get('/api/stats/dashboard'); } catch (e) {} };
    const loadLinks = async () => { try { links.value = await API.get('/api/tenant/links'); renderCodeQrs(); renderMainQr(); } catch (e) {} };
    const loadAgents = async () => { try { agents.value = await API.get('/api/tenant/agents'); } catch (e) {} };

    /* ===== v2.60.7 手机端体验（借鉴同行手机端，但按我们自己的结构来）=====
       ① 底部导航：会话 / 访客 / 数据 / 客服码 / 设置 —— 不用再点汉堡菜单；
       ② 顶栏刷新键：按当前页重新拉一次数据，给个轻提示；
       ③ 概览卡用到的「是否已到期」和「日期格式」。 */
    const navTo = (t) => {
      mSideOpen.value = false;
      if (t === 'work') { tab.value = 'work'; restartPoll(); return; }
      if (t === 'stats') { tab.value = 'stats'; loadStats(); restartPoll(); return; }
      if (t === 'visitors') { tab.value = 'visitors'; loadVisitors(); return; }
      if (t === 'links') { tab.value = 'links'; if (me.value && me.value.role === 'tenant') loadLinks(); return; }
      if (t === 'setting') { openSetting(setTab.value); return; }
    };
    const navRefresh = async () => {
      const t = tab.value;
      try {
        if (t === 'stats') await Promise.all([loadStats(), pollOnce()]);
        else if (t === 'visitors') await loadVisitors();
        else if (t === 'links') { await loadLinks(); await loadStats(); }
        else await pollOnce();
        ElementPlus.ElMessage({ message: '已刷新', duration: 800 });
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const meExpired = computed(() => !!(me.value && me.value.expires_at && new Date(me.value.expires_at) < new Date()));
    const fmtDay = (t) => {
      if (!t) return '';
      const s = String(t).replace('T', ' ');
      return s.slice(0, 10);
    };
    const loadBrand = async () => {
      try {
        const b = await API.get('/api/tenant/settings');
        brand.company_name = b.brand_company; brand.title = b.brand_title; brand.sub = b.brand_sub;
        brand.avatar = b.brand_avatar; brand.color = b.brand_color; brand.tpl = b.visitor_template || 'default';   // v2.74 默认「默认客服」
        brand.tpl_nav_on = b.tpl_nav_on !== false; brand.tpl_nav_title = b.tpl_nav_title || '';
        brand.agent_avatar_on = b.agent_avatar_on !== false;
        codeCfg.nicknameOn = b.code_nickname_on !== false;
        codeCfg.supportOn = b.code_support_on !== false;
        // v2.30 客服码卡片样式 + 上次检测结果
        codeStyle.dot = b.code_dot || 'rounded';
        codeStyle.eye = b.code_eye || 'rounded';
        codeStyle.brand = b.code_brand || '#07C160';
        codeStyle.logo = b.code_logo || 'none';
        codeStyle.logoSize = b.code_logo_size || 'std';
        codeStyle.topText = b.code_top_text || '扫码咨询';
        codeStyle.bottomText = b.code_bottom_text || '官方客服';
        codeStyle.colorDots = b.code_color_dots === true;
        codeStyle.custom = b.code_custom_logo || '';
        /* v2.90.21 快捷消息点击行为：direct=单击直接发送（默认）/ edit=单击只填进输入框，编辑后再手动发 */
        quickMode.value = (b.quick_send_mode === 'edit') ? 'edit' : 'direct';
        /* v2.90.67 账号默认是否常显快捷消息；本机没手动切过（localStorage 没值）就跟着账号走 */
        quickBarOn.value = b.quick_bar_on !== false;
        try {
          if (localStorage.getItem('kfxt_qbar') === null) quickBarOpen.value = quickBarOn.value;
        } catch (e) { quickBarOpen.value = quickBarOn.value; }
        codeCheck.state = b.code_check_state || 'idle';
        codeCheck.text = b.code_check_text || '未检测';
        codeCheck.tag = b.code_check_tag || '未检测';
        codeCheck.atText = b.code_check_at || '—';
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
          closed_keep_days: b.closed_keep_days != null ? b.closed_keep_days : 3,
          archived_keep_days: b.archived_keep_days != null ? b.archived_keep_days : 0,
          max_sessions: b.max_sessions != null ? b.max_sessions : 0,
          ask_image_text: b.ask_image_text || '方便的话，请上传一下相关图片，帮您更快定位问题～',
        });
        Object.assign(sndCfg, {
          agent_recv_on: b.agent_snd_recv_on !== false,
          agent_recv_which: b.agent_snd_recv_which || 'dindon',
          agent_send_on: !!b.agent_snd_send_on,
          agent_send_which: b.agent_snd_send_which || 'click',
          visitor_recv_on: !!b.visitor_snd_recv_on,
          visitor_recv_which: b.visitor_snd_recv_which || 'dindon',
          visitor_send_on: !!b.visitor_snd_send_on,
          visitor_send_which: b.visitor_snd_send_which || 'water',
        });
        Object.assign(sec, {
          notice_on: !!b.notice_on, notice_text: b.notice_text || '',
          filter_keywords: b.filter_keywords || '', auto_blacklist: !!b.auto_blacklist,
          dedup_on: !!b.dedup_on, ip_merge_on: b.ip_merge_on === undefined ? true : !!b.ip_merge_on,
          ip_limit_on: !!b.ip_limit_on, ip_limit_count: b.ip_limit_count || 5,
        });
        Object.assign(aiCfg, {
          entitled: !!b.ai_chat_entitled, on: !!b.ai_chat_on,
          base_url: b.ai_base_url || 'https://api.b.ai', model: b.ai_model || 'deepseek-v4.1-flash',
          key_set: !!b.ai_key_set, sign: b.ai_sign || '', tg_token_set: !!b.ai_tg_token_set,
          tg_chat: b.ai_tg_chat || '', bridge_secret: b.ai_bridge_secret || '',
          max_per_pay: b.ai_max_per_pay || '450', retry_pay: b.ai_retry_pay || '100', min_pay: b.ai_min_pay || '50',
        });
      } catch (e) {}
    };
    const loadAgentOptions = async () => {
      try { const r = await API.get('/api/agents'); agentOptions.value = r.agents || []; } catch (e) {}
    };

    // ---------- 轮询 ----------
    let lastUnreadTotal = 0;
    // 三级会话分区（v2.25）：online=在线 / archived=已归档 / closed=已结束
    const sessScope = ref('online');
    const sessCounts = reactive({ online: 0, archived: 0, closed: 0, online_unread: 0, archived_unread: 0 });
    // v2.67 会话多选批量操作
    const sessMulti = ref(false);
    const sessChecked = ref([]);
    // 切换分区会改变未读合计，别把它当成"来了新消息"而响铃
    Vue.watch(sessScope, () => { lastUnreadTotal = -1; });
    const pollOnce = async () => {
      try {
        const r = await API.get('/api/poll?role=agent&session=' + currentId.value + '&sinceMsg=' + maxMsgId.value + '&scope=' + sessScope.value);
        sessions.value = r.sessions || [];
        // 多选模式下：列表刷新后把已经不在列表里的勾选清掉
        if (sessMulti.value && sessChecked.value.length) {
          const alive = new Set(sessions.value.map(s => s.id));
          const kept = sessChecked.value.filter(id => alive.has(id));
          if (kept.length !== sessChecked.value.length) sessChecked.value = kept;
        }
        if (r.counts) Object.assign(sessCounts, r.counts);
        readUpto.value = r.readUpto != null ? r.readUpto : readUpto.value;
        // 撤回同步：最近撤回的消息标记（客服撤回→胶囊；访客撤回→内容保留+撤回角标）
        if (Array.isArray(r.recalledIds) && r.recalledIds.length) {
          const rset = new Set(r.recalledIds);
          msgs.value.forEach(m => { if (rset.has(m.id)) { m.recalled = true; m.recalledByVisitor = m.from === 'visitor'; } });
        }
        // v2.56 编辑同步：对方（或同租户别的客服）改过的消息，把本地文字也换掉
        if (Array.isArray(r.editedMsgs) && r.editedMsgs.length) {
          const emap = new Map(r.editedMsgs.map(x => [Number(x.id), String(x.text || '')]));
          msgs.value.forEach(m => { if (emap.has(m.id)) { m.text = emap.get(m.id); m.edited = true; } });
        }
        // 提示音只在"来了还没读的新消息"时响：
        // ① 未读总数增加（别的会话来了新消息）；② 页面切到后台时当前会话来了新消息。
        // 当前正在看的会话消息会立刻标记已读，不再重复响。
        // 注意：unreadTotal 是 computed，必须取 .value；之前写成 unreadTotal > x 恒为 false，导致完全没提示音。
        const inMsgs = (r.messages || []).filter(m => m.from === 'visitor');
        const unreadNow = unreadTotal.value;
        const unreadIncreased = unreadNow > lastUnreadTotal && lastUnreadTotal >= 0;
        const needNotify = unreadIncreased || (inMsgs.length > 0 && document.hidden);
        if (needNotify) {
          beep();
          if (inMsgs.length > 0 || unreadIncreased) desktopNotify(inMsgs, unreadNow);
        }
        lastUnreadTotal = unreadNow;
        flashTitle(unreadNow);
        if (r.messages && r.messages.length) {
          const fresh = r.messages.filter(m => !msgs.value.some(x => x.id === m.id)); // 去重防重复显示
          msgs.value.push(...fresh);
          prefetchAudio(r.messages);
          // 关键：推进"已拉取到的最大 id"，否则同一条消息每次轮询都被当成新消息（会一直响）
          const lastId = r.messages[r.messages.length - 1].id;
          if (lastId > maxMsgId.value) maxMsgId.value = lastId;
    pinBottom(1000);   // v2.58 新消息进来后也持续贴底一会儿（对方发的图会异步撑高）
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

    // ===== 提示音（v2.27）：租户级设置，客服端接收/发送分开；素材 assets/sounds/*.mp3 =====
    const SOUND_OPTS = [
      { v: 'water', l: '水滴' }, { v: 'pling', l: 'Pling' }, { v: 'weixin', l: '微信' },
      { v: 'dindon', l: 'Dindon' }, { v: 'click', l: '点击' },
    ];
    const sndCfg = reactive({
      agent_recv_on: true, agent_recv_which: 'dindon',
      agent_send_on: true, agent_send_which: 'water',
      visitor_recv_on: true, visitor_recv_which: 'dindon',
      visitor_send_on: true, visitor_send_which: 'water',
    });
    const audioCache = {};
    const playSound = (which) => {
      const key = ['water', 'pling', 'weixin', 'dindon', 'click'].includes(which) ? which : 'dindon';
      try {
        if (!audioCache[key]) audioCache[key] = new Audio('/assets/sounds/' + key + '.mp3');
        const a = audioCache[key].cloneNode();
        a.volume = 0.8;
        a.play().catch(() => {});
      } catch (e) {}
    };
    const beep = () => {   // 接收提示（新访客消息/新未读）；租户设置优先，子客服个人 sound_enabled 仍生效
      if (me.value && me.value.role === 'agent' && !me.value.sound_enabled) return;
      if (!sndCfg.agent_recv_on) return;
      playSound(sndCfg.agent_recv_which);
    };
    const sendBeep = () => {
      if (me.value && me.value.role === 'agent' && !me.value.sound_enabled) return;
      if (!sndCfg.agent_send_on) return;
      playSound(sndCfg.agent_send_which);
    };

    // ===== 聊天工具条：刷新聊天记录 / 请求访客上传图片（v2.32）=====
    const refreshing = ref(false);
    /* v2.90.82 是否正在等访客页面刷新完成：请求时间 reloadReqAt 与访客心跳 lastOnline 比 ——
       访客刷新完回来轮询会把 lastOnline 顶到请求时间之后，即视为完成；25 秒没回来就不算（避免卡死）。 */
    const reloadPending = computed(() => {
      const s = sessions.value.find(x => x.id === currentId.value);
      if (!s || !s.reloadReqAt) return false;
      const req = Date.parse(s.reloadReqAt) || 0;
      const seen = Date.parse(s.lastOnline || '') || 0;
      if (seen > req) return false;
      return (Date.now() - req) < 25000;
    });
    const refreshChat = async () => {
      if (!currentId.value || refreshing.value) return;
      if (reloadPending.value) { ElementPlus.ElMessage.warning('上一次刷新还在等客户页面响应，等它重新连上再点'); return; }
      refreshing.value = true;
      try {
        const r = await API.get('/api/sessions/' + currentId.value + '/messages');
        msgs.value = r.messages || [];
        maxMsgId.value = msgs.value.length ? msgs.value[msgs.value.length - 1].id : 0;
    pinBottom(1400);
        /* v2.89.12 顺手把客户（访客端）的页面也叫刷新一次：客户那边卡住/图片不显示时最有用 */
        let visitorOk = false, pendingMsg = '';
        try {
          const rr = await API.post('/api/sessions/' + currentId.value + '/reload', {});
          if (rr && rr.pending) pendingMsg = rr.error || '上一次刷新还没生效'; else visitorOk = true;
        } catch (e) {}
        if (pendingMsg) ElementPlus.ElMessage.warning(pendingMsg);
        else ElementPlus.ElMessage.success(visitorOk ? '已通知访客页面刷新，等客户页面重新连上后可再次刷新' : '已刷新聊天记录');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { refreshing.value = false; }
    };
    // 请求访客上传图片：发一条 ask_image 卡片，访客端点卡片上的按钮即可选图
    const ASK_DEFAULT = '方便的话，请上传一下相关图片，帮您更快定位问题～';
    const ASK_PRESETS = [
      '方便的话，请上传一下相关图片，帮您更快定位问题～',
      '麻烦发张截图给我看看～',
      '请把出错页面截图发我，我帮您看下',
      '请上传您的付款/转账截图，方便核对',
      '请拍一下产品照片，方便我这边确认',
      '请把相关信息截图发我，我帮您核对一下',
      '方便发一下图片吗？我看看具体情况',
      '请把您看到的提示拍照发我',
      '请上传收货地址截图',
      '请上传证件照（隐私信息请打码）',
    ];
    const askDlg = ref(false);
    const askForm = reactive({ text: '', saveDefault: false });
    const openAskDialog = () => {
      if (!currentId.value) { ElementPlus.ElMessage.warning('请先选择会话'); return; }
      askForm.text = (pol.ask_image_text || '').trim() || ASK_DEFAULT;
      askForm.saveDefault = false;
      askDlg.value = true;
    };
    const sendAskDialog = async () => {
      const text = (askForm.text || '').trim();
      if (!text) { ElementPlus.ElMessage.warning('请填写要发送的话术'); return; }
      if (!currentId.value || sending.value) return;
      sending.value = true;
      try {
        if (askForm.saveDefault) {
          const store = text === ASK_DEFAULT ? '' : text;
          await API.put('/api/tenant/settings', { ask_image_text: store });
          pol.ask_image_text = store;
        }
        const r = await API.post('/api/sessions/' + currentId.value + '/messages', { text, msg_type: 'ask_image' });
        msgs.value.push(r.message);
        maxMsgId.value = r.message.id;
        pinBottom(1200);
        sendBeep();
        const s = sessions.value.find(x => x.id === currentId.value);
        if (s) s.lastMsg = r.message.text || '[请求上传图片]';
        askDlg.value = false;
        if (askForm.saveDefault) ElementPlus.ElMessage.success('已发送，并设为默认话术');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { sending.value = false; }
    };
    const askUpload = async () => {
      if (!currentId.value || sending.value) return;
      sending.value = true;
      try {
        const text = (pol.ask_image_text || '').trim();
        const r = await API.post('/api/sessions/' + currentId.value + '/messages', { text, msg_type: 'ask_image' });
        msgs.value.push(r.message);
        maxMsgId.value = r.message.id;
      pinBottom(1200);
        sendBeep();
        const s = sessions.value.find(x => x.id === currentId.value);
        if (s) s.lastMsg = r.message.text || '[请求上传图片]';
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { sending.value = false; }
    };

    const confirmEndSession = async (name) => {
      await ElementPlus.ElMessageBox.confirm(
        '结束会话后，访客端聊天记录将清除，访客将无法继续在该会话中发送消息；会话会移入「已结束」列表。',
        name ? ('结束会话 · ' + name) : '结束会话',
        { type: 'warning', confirmButtonText: '结束会话', cancelButtonText: '取消' });
    };

    /* ===== v2.58 修：进会话没有停在最新一条 =====
       原来只 nextTick 里滚一次。问题是图片/视频是**异步撑高**的：
       刚渲染时图片高度还是 0，滚到底只是滚到一个很矮的高度；
       等图片加载完把内容撑高，视图就停在中间了 —— 也就是"没停在最新记录"。
       现在改成：进会话/发消息后开一个约 1.6 秒的"贴底窗口"，期间持续贴底；
       另外图片、视频加载完成时，如果还在贴底窗口内（或者本来就在底部附近）再补一次。 */
    let pinUntil = 0;
    const scrollBottom = () => nextTick(() => {
      const el = chatBody.value;
      if (el) el.scrollTop = el.scrollHeight;
    });
    const pinBottom = (ms = 1600) => {
      pinUntil = Date.now() + ms;
      scrollBottom();
      const t0 = Date.now();
      const step = () => {
        if (Date.now() - t0 > ms) return;
        scrollBottom();
        setTimeout(step, 80);
      };
      setTimeout(step, 60);
    };
    // v2.52 「回到底部」：往上翻记录时右下角出现按钮，点一下直接跳到最新
    const chatAtBottom = ref(true);
    const onChatScroll = () => {
      const el = chatBody.value; if (!el) return;
      chatAtBottom.value = (el.scrollHeight - el.scrollTop - el.clientHeight) < 90;
    };
    const toBottom = () => { pinBottom(1200); chatAtBottom.value = true; };
    // 图片/视频加载完成后补滚（只在我们本来就该在底部时才滚，免得把正在翻记录的人拽下来）
    const onMediaLoad = () => {
      const el = chatBody.value;
      if (!el) return;
      const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 120;
      if (Date.now() < pinUntil || nearBottom) scrollBottom();
    };

    /* v2.90.88 会话消息缓存：切回看过的会话先显示上次内容（秒开），同时后台刷新 */
    const loadingMsgs = ref(false);
    const msgCache = new Map();
    const MSG_CACHE_MAX = 8;
    const MSG_CACHE_ITEM_MAX = 300;   // v2.90.90 单会话超过 300 条不进缓存（护内存，长会话照常按需拉）
    const openSession = async (id) => {
      // v2.55 草稿/待发附件按会话隔离：给 A 打了一半的字（或贴好的图）不会跟着你跑到 B 的聊天框里
      saveSessionDraft(currentId.value);
      currentId.value = id;
      /* v2.86 手机端：进会话压一条历史记录，手机返回键 / 返回手势先回会话列表（最多只压一条） */
      try {
        if (history.state && history.state.kfChat) history.replaceState({ kfChat: id }, '');
        else history.pushState({ kfChat: id }, '');
      } catch (e) {}
      restoreSessionDraft(id);
      /* v2.90.88 A+B：先渲染缓存（秒开）或加载占位，避免"切换后一片空白等一秒" */
      const cachedMsgs = msgCache.get(id);
      if (cachedMsgs) {
        msgs.value = cachedMsgs.msgs;
        maxMsgId.value = cachedMsgs.maxMsgId;
        loadingMsgs.value = false;
        pinBottom(0);
      } else {
        msgs.value = [];
        maxMsgId.value = 0;
        loadingMsgs.value = true;
      }
      profile.value = null;
      infoOpen.value = false;   // v2.46 手机端：切会话时收起访客信息抽屉
      chMore.value = false;     // v2.60 手机端：切会话时收起「更多」面板
      chatAtBottom.value = true;   // v2.52 换会话时先当作在底部
      try {
        const r = await API.get('/api/sessions/' + id + '/messages');
        /* v2.90.88 慢响应保护：这期间用户可能已切到别的会话，别把别人的记录盖上去 */
        if (currentId.value !== id) return;
        msgs.value = r.messages || [];
        prefetchAudio(msgs.value);
        if (msgs.value.length) maxMsgId.value = msgs.value[msgs.value.length - 1].id;
        loadingMsgs.value = false;
        /* v2.90.90 缓存保护：单会话超过 300 条就不进缓存。
           长会话的历史记录很大，缓 8 个长会话等于把几万条消息挂在内存里（切会话反而变卡），
           300 条以内才缓存 —— 秒开的收益留给真正轻的会话，长会话每次照常拉最近 300 条。 */
        if (msgs.value.length <= MSG_CACHE_ITEM_MAX) {
          msgCache.set(id, { msgs: msgs.value, maxMsgId: maxMsgId.value });
          if (msgCache.size > MSG_CACHE_MAX) { const oldest = msgCache.keys().next().value; msgCache.delete(oldest); }
        } else {
          msgCache.delete(id);
        }
    pinBottom(1800);   // v2.58 进会话：图片/视频异步撑高，必须持续贴底一会儿
        markRead(id);
        // 资料与联系方式
        profile.value = await API.get('/api/sessions/' + id + '/profile');
        /* v2.88.4 打开「不在当前列表里」的会话（从访客列表点进来、已归档/已结束的会话）：
           给聊天头留一份兜底数据，否则 current 会是空 → 聊天区一片空白（用户反馈「跳转不了」） */
        if (profile.value && profile.value.session && !sessions.value.some(s => s.id === id)) {
          const ps = profile.value.session;
          curSnap.value = {
            id: id, name: ps.name, status: ps.status, unread: ps.unread || 0,
            lastTime: ps.last_time, lastOnline: ps.last_time,
            visitor_avatar: profile.value.avatar || '', agent_name: '', pinned: 0,
          };
        }
        contactList.value = await API.get('/api/sessions/' + id + '/contacts');
        noteDraft.value = (contactList.value.find(c => c.type === 'note') || {}).value || '';
        if (!agentOptions.value.length) await loadAgentOptions();
      } catch (e) { loadingMsgs.value = false; ElementPlus.ElMessage.error(e.message); }
    };
    /* ===== v2.57 到期提醒：剩 5 分钟提示一次，剩 2 分钟弹窗，到期再提醒一次 =====
       租户（卡密主人）才有 expires_at；子客服没有，直接跳过。
       每 15 秒对一次表，页面开着就一定会响；打开时就已进入窗口也会立刻提示。 */
    let expireTimer = null;
    const expireDone = { at5: false, at2: false, end: false };
    /* v2.75 强制退出登录：清 token + 回登录页 + 把原因带过去显示 */
    const forceLogout = (reason) => {
      try { sessionStorage.setItem('kfxt_kick', typeof reason === 'string' && reason ? reason : '账号已到期，请联系平台管理员续期'); } catch (e) {}
      try { API.clearToken(); } catch (e) {}
      location.href = '/login.html';
    };
    const startExpireWatch = () => {
      if (!me.value || me.value.role !== 'tenant' || !me.value.expires_at) return;
      // Safari 不认 "YYYY-MM-DD HH:mm:ss"，换成斜杠格式
      const end = new Date(String(me.value.expires_at).replace(/-/g, '/')).getTime();
      if (!end || isNaN(end)) return;
      const mins = (left) => Math.max(1, Math.round(left / 60000));
      const tick = () => {
        const left = end - Date.now();
        if (left <= 0) {
          clearInterval(expireTimer); expireTimer = null;
          if (!expireDone.end) {
            expireDone.end = true;
            /* v2.75 到期不再只是弹个提示：提示确认后直接退出登录，回到登录页并说明原因
               （否则页面停在工作台，接口一直报 403，用户也不知道发生了什么） */
            ElementPlus.ElMessageBox.alert('账号已到期，工作台将无法继续使用。请联系平台管理员续期。',
              '⏰ 账号已到期', { confirmButtonText: '重新登录', type: 'error' })
              .then(forceLogout).catch(forceLogout);
          }
          return;
        }
        // 已经进到 2 分钟窗口了就别再补发 5 分钟那条（比如页面是这时候才打开的）
        if (left <= 2 * 60 * 1000 && !expireDone.at5) expireDone.at5 = true;
        if (!expireDone.at5 && left <= 5 * 60 * 1000) {
          expireDone.at5 = true;
          ElementPlus.ElNotification({
            title: '账号即将到期', type: 'warning', duration: 9000, position: 'top-right',
            message: '还有约 ' + mins(left) + ' 分钟到期，请及时联系平台管理员续期。',
          });
        }
        if (!expireDone.at2 && left <= 2 * 60 * 1000) {
          expireDone.at2 = true;
          ElementPlus.ElMessageBox.alert(
            '账号将在约 ' + mins(left) + ' 分钟后到期。到期后工作台无法登录、访客也无法接入，请尽快联系平台管理员续期。',
            '⏰ 账号即将到期', { confirmButtonText: '知道了', type: 'warning' });
        }
      };
      tick();
      expireTimer = setInterval(tick, 15000);
    };
    /* ===== v2.76 1 小时不操作 → 自动退出登录（防卡密共享；租户/子客服都适用）=====
       鼠标/键盘/触摸/滚动都算"操作"；到点强制退出登录，回登录页并说明原因。 */
    const IDLE_LIMIT_MS = 60 * 60 * 1000;
    let idleTimer = null;
    let lastActiveAt = Date.now();
    const startIdleWatch = () => {
      const markActive = () => { lastActiveAt = Date.now(); };
      ['mousemove', 'mousedown', 'keydown', 'touchstart', 'touchmove', 'click', 'scroll', 'wheel']
        .forEach(function (ev) { document.addEventListener(ev, markActive, { passive: true }); });
      idleTimer = setInterval(function () {
        if (Date.now() - lastActiveAt >= IDLE_LIMIT_MS) {
          if (idleTimer) { clearInterval(idleTimer); idleTimer = null; }
          forceLogout('超过 1 小时未操作，已自动退出登录');
        }
      }, 30000);
    };
    /* ===== v2.64 红码提醒：服务端每小时巡检所有客服码，这里轮询并把红的弹窗告诉租户 =====
       同一批提醒只弹一次（弹完就标记已读）；某条码恢复正常后服务端会清掉未读提醒，
       所以「红了→修好→又红了」会重新提醒，一直红着则不会每小时骚扰。 */
    let alertTimer = null;
    const checkCodeAlerts = async () => {
      if (!me.value || me.value.role !== 'tenant') return;
      try {
        const r = await API.get('/api/tenant/code/alerts');
        const list = (r && r.alerts) || [];
        // v2.58 不管在哪个页面，先把「当前这条码红没红」刷一遍 —— 顶部的红条靠它
        try { await checkCode(false); } catch (e) {}
        if (!list.length) return;
        /* v2.58 弹窗不再"弹一次就再也不弹"：一直红着的时候，最多每小时再提醒一次
           （提醒了就标记已读，靠上面那条全局红条持续可见，不用反复骚扰）。 */
        const lastPop = Number(localStorage.getItem('kfxt_red_popup_at') || 0);
        if (Date.now() - lastPop < 3600 * 1000) return;
        localStorage.setItem('kfxt_red_popup_at', String(Date.now()));
        // 弹窗消息要带换行，只能走 HTML（值都转义过，第三方返回的内容也不会变成标签）
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const detail = list.slice(0, 6).map(a => '· <b>' + esc(a.code) + '</b>' + (a.msg ? ('：' + esc(a.msg)) : '')).join('<br>');
        let go = false;
        try {
          await ElementPlus.ElMessageBox.alert(
            '<div style="line-height:1.9;">检测到 <b>' + list.length + '</b> 个客服码已被微信拦截（俗称"红了"）：'
            + '<div style="margin:8px 0;padding:8px 10px;background:#fff8e6;border-radius:8px;font-size:13px;">' + detail
            + (list.length > 6 ? ('<br>…共 ' + list.length + ' 个') : '') + '</div>'
            + '建议去「客服码」页点「立即换码」——会新增一条链接（换掉被拦的域名），把新的发给客户即可。<br>'
            + '<b>旧码不会因为换码而失效</b>（新码默认 72 小时到期），可以在「管理码」里续期、停用或删除。</div>',
            '⚠️ 有客服码被微信拦截',
            { confirmButtonText: '去客服码页处理', type: 'warning', dangerouslyUseHTMLString: true });
          go = true;
        } catch (e) {}
        await API.post('/api/tenant/code/alerts', { ids: list.map(a => a.id) });
        if (go) { tab.value = 'links'; }
      } catch (e) {}
    };

    const markRead = async (id) => {
      try { await API.post('/api/sessions/' + id + '/read'); const s = sessions.value.find(x => x.id === id); if (s) s.unread = 0; } catch (e) {}
    };

    /* v2.55 每个会话各自保存「打了一半的文字 + 待发附件」。
       以前 draft / pendingAtts 是全局唯一的，换到另一个访客时上一个人的内容会跟过来，
       既容易发错人，也容易把给 A 写的话发给 B。 */
    const draftBox = reactive({});      // sessionId -> 文字
    const attBox = reactive({});        // sessionId -> 待发附件数组
    const saveSessionDraft = (id) => {
      if (!id) return;
      draftBox[id] = draft.value;
      attBox[id] = pendingAtts.value.slice();
    };
    const restoreSessionDraft = (id) => {
      draft.value = (id && draftBox[id]) || '';
      pendingAtts.value = (id && attBox[id]) ? attBox[id].slice() : [];
    };

    const send = async () => {
      const text = draft.value.trim();
      const atts = pendingAtts.value.slice();
      if ((!text && !atts.length) || !currentId.value || sending.value) return;
      sending.value = true;
      const sidAtSend = currentId.value;
      const keepReply = replyTo.value;
      /* v2.90.75 乐观发送：先本地回显一条「发送中…」，接口回来再换成真消息。
         以前是等接口返回才画出来，客户端到服务器一个来回 ~200ms，点发送就感觉"卡一下"。 */
      const tmp = {
        id: 0, tmp: true, pending: true, from: 'agent',
        sender_id: (me.value && me.value.id) || 0,
        sender_name: (me.value && (me.value.nickname || me.value.username)) || '客服',
        text, attachments: atts, msg_type: atts.length ? 'mixed' : 'text',
        time: new Date().toISOString(),
        reply_to: replyTo.value ? replyTo.value.id : 0,
      };
      msgs.value.push(tmp);
      draft.value = '';
      resetTaHeight();
      pendingAtts.value = [];
      pinBottom(600);
      try {
        const payload = { text, attachments: atts.length ? atts : undefined };
        if (replyTo.value) payload.reply_to = replyTo.value.id;   // v2.43 引用回复
        const r = await API.post('/api/sessions/' + sidAtSend + '/messages', payload);
        if (currentId.value === sidAtSend) {
          const i = msgs.value.indexOf(tmp);
          const already = msgs.value.some(x => x.id === r.message.id);   // 轮询可能已先塞进来
          if (i >= 0) { if (already) msgs.value.splice(i, 1); else msgs.value.splice(i, 1, r.message); }
          else if (!already) msgs.value.push(r.message);
        }
        if (r.message.id > maxMsgId.value) maxMsgId.value = r.message.id;
        // v2.55 发完把该会话的草稿槽也清掉，切回来是干净的
        draftBox[sidAtSend] = '';
        attBox[sidAtSend] = [];
        replyTo.value = null;
        // v2.53 发完消息不再自动收起快捷栏（用户反馈"每次都要点一次才显示"）；
        // 想收起就点「⚡快捷消息」那个按钮，纯手动。
        pinBottom(1400);
        sendBeep();
        const s = sessions.value.find(x => x.id === sidAtSend);
        if (s) { s.lastMsg = (text || (atts.length ? '[图片]' : '')).slice(0, 60); s.agent_id = s.agent_id || (me.value.role === 'agent' ? me.value.id : s.agent_id); }
      } catch (e) {
        tmp.pending = false;
        tmp.failed = true;
        // 失败：把内容还回输入框，方便直接再发一次
        if (currentId.value === sidAtSend) { draft.value = text; pendingAtts.value = atts; replyTo.value = keepReply; }
        ElementPlus.ElMessage.error(e.message);
      }
      finally { sending.value = false; }
    };
    /* v2.90.75 发送失败后的重试：把那条临时消息撤掉，内容塞回输入框再发一次 */
    const retrySend = async (m) => {
      const i = msgs.value.indexOf(m);
      if (i >= 0) msgs.value.splice(i, 1);
      draft.value = String(m.text || '');
      pendingAtts.value = Array.isArray(m.attachments) ? m.attachments.slice() : [];
      replyTo.value = m.reply_to ? { id: m.reply_to } : null;
      await send();
    };
    /* v2.57 修：中文输入法选词时按回车是「上屏」不是「发送」。
       原来没判断 isComposing，于是选词时一回车就把还没上屏的字发出去了，
       紧接着输入法又把这段字写回输入框 —— 用户看到的就是「消息发出去了，输入框里还在」。
       keyCode 229 是部分安卓/旧浏览器的等价信号。 */
    const onKey = (e) => {
      if (e.isComposing || e.keyCode === 229) return;
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    };
    /* v2.60 手机端：输入框随内容长高（最多 ~5 行），不用在里面滚来滚去找字。
       只在手机端生效，电脑端保持原来的固定高度。 */
    /* v2.90.57 输入框高度自己调：拖「输入区顶端那条细手柄」上下拉，拖出来的高度记在本机，双击手柄恢复默认。
       taH = 0 表示跟默认一样（电脑 44 / 手机 50），不拖的话显示和以前完全一致。 */
    const taResizing = ref(false);
    const taH = ref((function () {
      try {
        const v = parseInt(localStorage.getItem('kfxt_ta_h') || '0', 10) || 0;
        return v ? Math.min(420, Math.max(40, v)) : 0;
      } catch (e) { return 0; }
    })());
    const taDefaultH = () => (document.documentElement.getAttribute('data-ui-cat') === 'mobile' ? 50 : 44);
    const applyTaH = () => {
      const el = document.querySelector('.chat-input textarea');
      if (!el) return;
      el.style.height = taH.value ? taH.value + 'px' : '';
    };
    const taResizeStart = (e) => {
      const el = document.querySelector('.chat-input textarea');
      if (!el || e.clientY === undefined) return;
      taResizing.value = true;
      document.body.classList.add('resizing-ta');
      const fromY = e.clientY, fromH = el.offsetHeight || taDefaultH();
      const mv = (ev) => {
        taH.value = Math.min(420, Math.max(40, Math.round(fromH - (ev.clientY - fromY))));   // 往上拖 = 变高
        el.style.height = taH.value + 'px';
      };
      const up = () => {
        taResizing.value = false;
        document.body.classList.remove('resizing-ta');
        try { localStorage.setItem('kfxt_ta_h', String(taH.value)); } catch (e2) {}
        document.removeEventListener('pointermove', mv);
        document.removeEventListener('pointerup', up);
        document.removeEventListener('pointercancel', up);
      };
      document.addEventListener('pointermove', mv);
      document.addEventListener('pointerup', up);
      document.addEventListener('pointercancel', up);
    };
    const taResetH = () => {
      taH.value = 0;
      try { localStorage.removeItem('kfxt_ta_h'); } catch (e) {}
      applyTaH();
    };
    Vue.watch(currentId, () => nextTick(applyTaH));
    const autoGrowTa = (ev) => {
      const el = ev && ev.target;
      if (!el) return;
      // 自己拖过高度：以自己拖的为准，只保证内容不被裁掉
      if (taH.value) { el.style.height = Math.max(taH.value, Math.min(360, el.scrollHeight)) + 'px'; return; }
      if (!el || document.documentElement.getAttribute('data-ui-cat') !== 'mobile') return;
      el.style.height = 'auto';
      /* v2.90.70 下限改用真实默认高度（手机 50 / 电脑 44）：原来写死 44，与 CSS 的 50~52px 不一致，
         结果一打字输入框先矮 6~8px、发完又长回来，也是一次可见的跳动。 */
      el.style.height = Math.min(132, Math.max(taDefaultH(), el.scrollHeight)) + 'px';
    };
    const resetTaHeight = () => {
      const el = document.querySelector('.chat-input textarea');
      if (!el) return;
      /* v2.90.70 发送后回到「本该有」的高度：拖过手柄的用 taH（原来一律清空 inline 高度，
         拖过手柄的人每次发送都塌回默认、下次打字又弹回 taH）；并只给这一次塌陷加 160ms 过渡。 */
      const target = taH.value ? taH.value + 'px' : '';
      if ((el.style.height || '') === target) return;
      el.classList.add('ta-smooth');
      void el.offsetHeight;   // 强制重排：先把过渡登记上，再改高度，否则同一帧改高度不会走动画
      el.style.height = target;
      setTimeout(() => { try { el.classList.remove('ta-smooth'); } catch (e) {} }, 240);
    };
    /* 手机端输入框只有一行高，原来那句长提示（Enter 发送 / Ctrl+V 粘贴）会被截成两行还看不全，
       换成短提示；电脑端保留完整说明。 */
    const taPlaceholder = computed(() => (
      document.documentElement.getAttribute('data-ui-cat') === 'mobile'
        ? '输入消息…'
        : '输入消息，Enter 发送，Shift+Enter 换行；可 Ctrl+V 粘贴图片'));

    // 快捷消息（v2.28 改版）：单击 = 直接发送；Ctrl+单击 = 填入输入框（文字+图片均可编辑）
    /* v2.90.21 单击行为可设置：默认「直接发送」；设成「编辑后发送」时，单击快捷消息只把它填进
       输入框（可以改文字、加图片），自己按发送才发出去。Ctrl+单击 永远是填进输入框。 */
    const quickMode = ref('direct');
    /* v2.90.94 前端版本号（顶栏显示）：用来确认当前页面加载的是哪一版，排查缓存问题一眼就看出来。
       app.html 是原样下发的静态模板（不经 PHP 渲染），所以版本从 /api/health 取（和 version.php 同一个值）。 */
    const appVersion = ref((window.KF_APP_VERSION || ''));
    if (!appVersion.value) {
      (async () => {
        try {
          const r = await API.get('/api/health');
          if (r && r.app_version) appVersion.value = String(r.app_version);
        } catch (e) {}
      })();
    }
    const setQuickMode = async (m) => {
      quickMode.value = (m === 'edit') ? 'edit' : 'direct';
      try { await API.put('/api/tenant/settings', { quick_send_mode: quickMode.value }); } catch (e) {}
    };
    const pendingAtts = ref([]);   // 待发附件（编辑模式带入的图片，发送时随消息发出）
    /* v2.90.22 当前会话之外、同一个 IP 下还没结束的其它会话（会话头那个「同 IP 还有 N 条」按钮用） */
    const sameIpOthers = Vue.computed(() => {
      const cur = current.value;
      if (!cur || !cur.ip) return [];
      return (sessions.value || []).filter(x => x.id !== cur.id && x.ip === cur.ip && x.status !== 'closed' && x.status !== 'archived');
    });
    const mergeSameIpNow = async () => {
      if (!currentId.value) return;
      try {
        const r = await API.post('/api/sessions/merge-by-ip', { id: currentId.value });
        if (r && r.merged) {
          ElementPlus.ElMessage.success('已并入 ' + r.merged + ' 条同 IP 会话（搬过来 ' + (r.moved || 0) + ' 条消息），客户那边看到的是一整段完整记录');
        } else {
          ElementPlus.ElMessage.info('这个 IP 下没有别的未结束会话');
        }
        await pollOnce();
        const keep = currentId.value;
        if (keep) { msgs.value = []; await openSession(keep); }
      } catch (e) { ElementPlus.ElMessage.error(e.message || '合并失败'); }
    };
    const quickClick = (q, ev) => {
      if (qDrag.suppressClick) { qDrag.suppressClick = false; return; }   // 刚拖完，别把消息发出去
      if ((ev && (ev.ctrlKey || ev.metaKey)) || quickMode.value === 'edit') {
        draft.value = q.content || '';
        pendingAtts.value = (q.attachments || []).filter(a => a && a.url).slice();   // 修复：带图话术图片不再丢失
        const input = document.querySelector('.chat-input textarea, .chat-input input');
        if (input) { input.focus(); }
        return;
      }
      sendQuick(q);
    };
    /* v2.90.56 快捷消息面板（窄面板）里点一下：直接发出，发出去就把面板收起来。
       Ctrl/⌘ + 点击 仍然是「填进输入框」，这时候不关面板，方便改完再发。 */
    const quickPanelClick = async (q, ev) => {
      if (qDrag.suppressClick) { qDrag.suppressClick = false; return; }   // 刚拖完，别把消息发出去
      if ((ev && (ev.ctrlKey || ev.metaKey)) || quickMode.value === 'edit') {
        draft.value = q.content || '';
        pendingAtts.value = (q.attachments || []).filter(a => a && a.url).slice();
        const input = document.querySelector('.chat-input textarea, .chat-input input');
        if (input) { input.focus(); }
        return;
      }
      await sendQuick(q);
    };
    /* v2.90.92 「填入」：把这条快捷消息（文字 + 图片/附件）放进输入框，不直接发出去。
       手机上原来只有 Ctrl+单击 能填，等于没有这个功能；现在每行一个「填入」按钮。
       填完把输入框自动撑到合适高度并聚焦，光标在末尾，改完自己按发送。
       v2.90.96：不再弹任何提示（原来没会话时每点一次弹一个「请先选择会话」，连点会叠一屏；
       而且这条路径本来就没有"发出去"的风险 —— 连点几百次也只是覆盖输入框，静默处理即可）。 */
    const quickFill = (q) => {
      /* 没打开会话就什么都不做，也不弹提示：这条路径没有"发错人"的风险，
         连点只会覆盖输入框，静默处理比每次弹一个"请先选择会话"友好（v2.90.96）。 */
      if (!currentId.value) return;
      draft.value = q.content || '';
      pendingAtts.value = (q.attachments || []).filter(a => a && a.url).slice();
      nextTick(() => { applyTaH(); autoGrowTa({ target: document.querySelector('.chat-input textarea') }); });
      const input = document.querySelector('.chat-input textarea, .chat-input input');
      if (input) {
        try { input.focus(); } catch (e) {}
        try { const n = input.value.length; input.setSelectionRange(n, n); } catch (e) {}
        try { input.scrollIntoView({ block: 'nearest' }); } catch (e) {}
      }
    };
    const rmPendingAtt = (i) => { pendingAtts.value.splice(i, 1); };
    // 快捷按钮右键：编辑该条（跳转快捷消息页并打开编辑框）
    const quickCtx = reactive({ show: false, x: 0, y: 0, item: null });
    /* ===== v2.52 聊天里那排快捷按钮：按住拖动 = 调整全局顺序 =====
       鼠标：一动就进拖动；触屏：先按住 250ms 再拖（否则会和「点一下就发」冲突）。
       拖完调 saveQuickOrder 存库（和设置页里拖表格是同一套顺序）。 */
    const qDrag = reactive({ on: false, idx: -1, moved: false, suppressClick: false });
    const qPress = { idx: -1, x: 0, y: 0, touch: false, el: null, pid: 0, timer: null };
    const enterQuickDrag = () => {
      if (qPress.idx < 0) return;
      qDrag.on = true; qDrag.idx = qPress.idx; qDrag.moved = false;
      try { qPress.el.setPointerCapture(qPress.pid); } catch (e) {}
      if (navigator.vibrate) { try { navigator.vibrate(12); } catch (e) {} }
    };
    const quickDragDown = (i, ev) => {
      if (ev.button !== undefined && ev.button > 0) return;   // 只认左键/单指
      clearTimeout(qPress.timer);
      qPress.idx = i; qPress.x = ev.clientX; qPress.y = ev.clientY;
      qPress.el = ev.currentTarget; qPress.pid = ev.pointerId;
      qPress.touch = ev.pointerType !== 'mouse';
      if (qPress.touch) qPress.timer = setTimeout(enterQuickDrag, 250);
    };
    const quickDragMove = (ev) => {
      if (!qDrag.on) {
        if (qPress.idx < 0) return;
        const far = Math.abs(ev.clientX - qPress.x) + Math.abs(ev.clientY - qPress.y) > 8;
        if (!far || qPress.touch) return;   // 触屏必须等长按；没动够就更不算
        enterQuickDrag();
      }
      ev.preventDefault();
      const wrap = document.querySelector('.qk-list');   // v2.90.56 面板改窄后拖动容器跟着换名
      if (!wrap) return;
      const x = ev.clientX, y = ev.clientY;
      let to = qDrag.idx;
      [...wrap.querySelectorAll('.qk-item')].forEach((b, i) => {
        const r = b.getBoundingClientRect();
        if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) to = i;
      });
      /* v2.90.48 分组后拖动的下标是"当前这一组里"的位置，换算回整张表的真实下标再移动，
         这样其它分组的相对顺序不会被弄乱 */
      if (to !== qDrag.idx && to >= 0 && to < visibleQuick.value.length) {
        const fromFull = quickMsgs.value.findIndex(x => x.id === visibleQuick.value[qDrag.idx].id);
        const toFull = quickMsgs.value.findIndex(x => x.id === visibleQuick.value[to].id);
        if (fromFull < 0 || toFull < 0 || fromFull === toFull) return;
        const arr = quickMsgs.value.slice();
        const [it] = arr.splice(fromFull, 1);
        arr.splice(toFull, 0, it);
        quickMsgs.value = arr;
        qDrag.idx = to; qDrag.moved = true;
      }
    };
    const quickDragUp = () => {
      clearTimeout(qPress.timer);
      const wasOn = qDrag.on, moved = qDrag.moved;
      qDrag.on = false; qDrag.idx = -1; qPress.idx = -1;
      if (!wasOn) return;
      if (!moved) return;                       // 只是按住没拖：当普通点击处理
      qDrag.suppressClick = true;               // 松手后会跟一个 click，别顺手把消息发出去
      setTimeout(() => { qDrag.suppressClick = false; }, 500);
      saveQuickOrder(quickMsgs.value.map(x => x.id));
    };
    // 右键菜单里的「往前/往后挪一位」：触屏上万一拖不动还能用这个
    const quickMove = async (dir) => {
      const it = quickCtx.item; quickCtx.show = false;
      if (!it) return;
      const arr = quickMsgs.value.slice();
      const i = arr.findIndex(x => x.id === it.id);
      const j = i + dir;
      if (i < 0 || j < 0 || j >= arr.length) return;
      const t = arr[i]; arr[i] = arr[j]; arr[j] = t;
      quickMsgs.value = arr;
      await saveQuickOrder(arr.map(x => x.id));
    };
    const openQuickCtx = (q, ev) => {
      ev.preventDefault();
      ev.stopPropagation();
      quickCtx.x = ev.clientX; quickCtx.y = ev.clientY;
      quickCtx.item = q; quickCtx.show = true;
    };
    const editQuick = () => {
      const q = quickCtx.item;
      quickCtx.show = false;
      if (!q) return;
      openSetting('quick');
      // 表格渲染 + 数据加载都需要时间，轮询重试（最多 2s）
      let tries = 0;
      const tryOpen = () => {
        tries++;
        try {
          const rows = document.querySelectorAll('.el-table__row');
          for (const row of rows) {
            if ((row.textContent || '').includes(q.title)) {
              const btn = Array.from(row.querySelectorAll('button')).find(b => b.textContent.trim() === '编辑');
              if (btn) { btn.click(); return; }
            }
          }
        } catch (e) {}
        if (tries < 10) { setTimeout(tryOpen, 200); return; }
        ElementPlus.ElMessage.info('请在列表中编辑「' + q.title + '」');
      };
      setTimeout(tryOpen, 250);
    };
    const sendQuick = async (q) => {
      /* v2.90.96 这条原来把两种情形混在一起弹同一个提示：
         ① 真的没选会话 → 提示一下应该的；
         ② 上一条还在发（sending=true）→ 用户连点就会一直弹「请先选择会话」，看着像报错，实际那条已经发出去了。
         现在 ② 静默忽略（多半只是点太快），① 才提示。 */
      if (sending.value) return false;
      if (!currentId.value) { ElementPlus.ElMessage.warning('请先选择会话'); return false; }
      const text = q.content || '';
      const atts = (q.attachments || []).filter(a => a && a.url);
      if (!text && !atts.length) return false;
      sending.value = true;
      try {
        const r = await API.post('/api/sessions/' + currentId.value + '/messages', { text, attachments: atts });
        msgs.value.push(r.message);
        maxMsgId.value = r.message.id;
    pinBottom(1400);
        sendBeep();
        const s = sessions.value.find(x => x.id === currentId.value);
        if (s) s.lastMsg = (text || (atts.length ? '[图片]' : '')).slice(0, 60);
        return true;
      } catch (e) { ElementPlus.ElMessage.error(e.message); return false; }
      finally { sending.value = false; }
    };
    // v2.53 用快捷消息填到输入框后也不收起快捷栏（同上，别让人反复点）
    const useQuick = (q) => { draft.value = q.content; };

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

    // ===== 语音气泡（播放键 + 波形 + 秒数，替代原生 audio 控件）=====
    const audioPlaying = ref('');
    const audioDur = reactive({});
    let curAudio = null;
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
        curAudio.play().then(() => { audioPlaying.value = url; }).catch(() => { audioPlaying.value = ''; });
      } catch (e) {}
    };
    // 语音转文字（AIHubMix / OpenAI 兼容 STT；结果按消息缓存在服务端）
    const asrText = reactive({});
    const asrBusy = ref(0);
    const transcribe = async (m, a) => {
      if (asrText[m.id]) { delete asrText[m.id]; return; }   // 再点一次＝收起
      asrBusy.value = m.id;
      try {
        const r = await API.post('/api/msg/transcribe', { message_id: m.id });
        asrText[m.id] = r.text || '（没听清）';
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { asrBusy.value = 0; }
    };

    // ===== 可见消息（撤回的显示为系统胶囊） =====
    /* v2.60.11 聊天记录快速查找：① 只看图片 ② 按日期跳转 */
    const imgOnly = ref(false);
    const dateOpen = ref(false);
    /* v2.89.12 sys_reload（让访客端刷新的指令）不显示在聊天里 */
    const visibleMsgs = Vue.computed(() => (imgOnly.value
      ? msgs.value.filter(m => ((m && m.attachments) || []).some(a => a && (!a.type || a.type === 'image') && a.url))
      : msgs.value.filter(m => m && m.msg_type !== 'sys_reload')));
    const imgCount = Vue.computed(() => msgs.value.filter(m => ((m && m.attachments) || []).some(a => a && (!a.type || a.type === 'image') && a.url)).length);
    const toggleImgOnly = () => { imgOnly.value = !imgOnly.value; };
    /* 这段对话里出现过的日期（倒序），点一下跳到当天第一条 */
    const dateList = Vue.computed(() => {
      const map = {};
      for (const m of msgs.value) {
        const d = String(m.time || '').slice(0, 10);
        if (!d) continue;
        map[d] = (map[d] || 0) + 1;
      }
      return Object.keys(map).sort().reverse().map(d => {
        const today = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        const td = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;
        const yd = new Date(today.getTime() - 86400000);
        const ydd = `${yd.getFullYear()}-${pad(yd.getMonth() + 1)}-${pad(yd.getDate())}`;
        const label = d === td ? '今天' : (d === ydd ? '昨天' : d.slice(5).replace('-', '月') + '日');
        return { d, n: map[d], label };
      });
    });
    const jumpDate = (d) => {
      dateOpen.value = false;
      const first = msgs.value.find(m => String(m.time || '').slice(0, 10) === d);
      if (first) jumpToMsg(first.id);
    };
    // v2.73 一条消息里的图片（多图并排显示，文字放下面）
    const imgsOf = (m) => ((m && m.attachments) || []).filter(a => a && (!a.type || a.type === 'image') && a.url);

    /* v2.59 图片压缩（与访客端同一套）：手机原图常 4~10MB、iPhone 还常是 HEIC，
       直接传会被 5MB 限制或格式白名单挡掉 → 先缩到最长边 1600、转 JPEG 再传。 */
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

    // 上传一张图并放进「待发区」（不直接发出去），返回是否成功
    const stageImage = async (file) => {
      if (!file) return false;
      // v2.59 HEIC/HEIF 兜底（同访客端）：类型报不准时按扩展名再判一次
      const looksImg = /^image\//.test(file.type) || /\.(heic|heif)$/i.test(file.name || '');
      if (!looksImg) { ElementPlus.ElMessage.warning('只能发送图片'); return false; }
      let f = file;
      if (file.size > 300 * 1024 || !/^image\/(png|jpe?g|gif|webp)$/i.test(file.type)) f = await compressImage(file);
      if (f.size > 8 * 1024 * 1024) { ElementPlus.ElMessage.warning('照片太大了，建议先截图或压缩后再发'); return false; }
      const data = await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(f); });
      try {
        const up = await API.post('/api/upload', { data, kind: 'image', filename: (f === file ? (file.name || 'image.png') : 'image.jpg') });
        pendingAtts.value.push(up);
        return true;
      } catch (e) { ElementPlus.ElMessage.error(e.message); return false; }
    };
    // 图片/语音：选图只放进待发区，可以配文字、多张一起发
    const onImage = async (ev) => {
      const files = Array.from(ev.target.files || []);
      ev.target.value = '';
      if (!files.length || !currentId.value) return;
      let ok = 0;
      for (const f of files) { if (await stageImage(f)) ok++; }
      if (ok) ElementPlus.ElMessage.success('已加入 ' + ok + ' 张图片，可以继续打字或再加图，最后点发送');
    };
    /* ===== v2.43：引用回复 / 消息菜单 / 多选 / 粘贴发图 / 视频·文件·定位 ===== */
    const replyTo = ref(null);
    const infoOpen = ref(false);   // v2.46 手机端访客信息抽屉
    const flashId = ref(0);
    const quoteOf = (m) => (m && m.reply_to) ? msgs.value.find(x => x.id === m.reply_to) : null;
    const quoteWho = (m) => !m ? '' : (m.from === 'agent' ? (m.sender_name || '客服') : ((current.value && current.value.name) || '访客'));
    const quoteText = (m) => {
      if (!m) return '';
      if (m.recalled) return '消息已撤回';
      const t = (m.text || '').trim();
      if (t) return t.length > 60 ? t.slice(0, 60) + '…' : t;
      const a = (m.attachments || [])[0] || {};
      return { image: '[图片]', audio: '[语音]', video: '[视频]',
        file: '[文件] ' + (a.name || ''), location: '[位置] ' + (a.name || '') }[a.type] || '[消息]';
    };
    const startReply = (m) => {
      if (!m) return;
      msgMenu.show = false; msgSheet.show = false;
      replyTo.value = m;
      const el = document.querySelector('.chat-input textarea');
      if (el) el.focus();
    };
    const jumpToMsg = (id) => {
      const el = document.getElementById('km' + id);
      if (!el) { ElementPlus.ElMessage.info('这条消息在更早的记录里'); return; }
      el.scrollIntoView({ block: 'center', behavior: 'smooth' });
      flashId.value = id;
      setTimeout(() => { if (flashId.value === id) flashId.value = 0; }, 1400);
    };
    const copyMsg = async (m) => {
      msgMenu.show = false; msgSheet.show = false;
      const t = (m && m.text) || '';
      if (!t) { ElementPlus.ElMessage.warning('这条消息没有文字'); return; }
      try { await navigator.clipboard.writeText(t); ElementPlus.ElMessage.success('已复制'); }
      catch (e) { ElementPlus.ElMessage.warning('复制失败，请手动选中'); }
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
    // --- 消息右键菜单 ---
    const msgMenu = reactive({ show: false, x: 0, y: 0, msg: null });
    const openMsgMenu = (m, ev) => {
      if (selMode.value) return;
      ev.stopPropagation();   // 别让 #app 上的右键「关闭全部浮层」把刚打开的这个也关掉
      closeCtxMenu(); quickCtx.show = false; msgSheet.show = false;
      msgMenu.msg = m; msgMenu.x = ev.clientX; msgMenu.y = ev.clientY; msgMenu.show = true;
    };
    const closeMsgMenu = () => { msgMenu.show = false; };
    // 点空白处 / 右键空白处都关掉所有浮层（右键不触发 click，必须单独挂）
    const closeAllMenus = () => { ctxMenu.show = false; quickCtx.show = false; msgMenu.show = false; msgSheet.show = false; };
    // v2.49 手机端：长按消息弹底部操作面板（iOS 长按不会触发 contextmenu，右键菜单在手机上等于没有）
    const msgSheet = reactive({ show: false, msg: null });
    const openMsgSheet = (m) => {
      if (selMode.value || !m) return;
      msgSheet.msg = m;
      msgSheet.show = true;
    };
    let lpTimer = null, lpMoved = false;
    const onMsgTouchStart = (m, ev) => {
      if (selMode.value) return;
      lpMoved = false;
      clearTimeout(lpTimer);
      lpTimer = setTimeout(() => {
        if (lpMoved) return;
        openMsgSheet(m);
        if (navigator.vibrate) { try { navigator.vibrate(15); } catch (e) {} }
      }, 450);
    };
    const onMsgTouchMove = () => { lpMoved = true; clearTimeout(lpTimer); };
    const onMsgTouchEnd = () => { lpMoved = true; clearTimeout(lpTimer); };

    /* v2.60 手机端：聊天头部的「⋯ 更多」底部面板。
       原来手机上 6 个操作按钮要占两行（把消息区挤掉三分之一），现在只留「资料 + ⋯」两个，
       归档 / 转接 / 结束 / 拉黑 / 搜索全部收进这个面板，点一下才出现。 */
    const chMore = ref(false);
    const chMoreAction = async (k) => {
      chMore.value = false;
      if (k === 'search') { chatSearchOn.value = !chatSearchOn.value; return; }
      if (k === 'img') { imgOnly.value = !imgOnly.value; return; }
      if (k === 'date') { dateOpen.value = true; return; }
      if (k === 'resume') {
        const rc = profile.value && profile.value.resume_code;
        if (rc) { copy(rc); } else { ElementPlus.ElMessage.info('这个会话还没有恢复码，刷新一下试试'); }
        return;
      }
      if (k === 'resumeFind') { resumeFindOpen.value = true; return; }
      if (k === 'block') {
        try {
          await ElementPlus.ElMessageBox.confirm(
            '拉黑「' + ((current.value && current.value.name) || '该访客') + '」的 IP？拉黑后对方无法再进入或发消息。',
            '拉黑访客', { type: 'warning', confirmButtonText: '拉黑', cancelButtonText: '取消' });
          await blockSession();
        } catch (e) {}
        return;
      }
      if (k === 'archive') { await archiveSession(true); return; }
      if (k === 'unarchive') { await archiveSession(false); return; }
      if (k === 'transfer') { transferDlg.value = true; return; }
      if (k === 'close') {
        try {
          await ElementPlus.ElMessageBox.confirm(
            '结束会话后，访客端聊天记录将清除，访客将无法继续在此会话发送消息。确认结束？',
            '结束会话', { type: 'warning', confirmButtonText: '结束会话', cancelButtonText: '取消' });
          await closeSession();
        } catch (e) {}
        return;
      }
    };
    const deleteMsg = async (m) => {
      msgMenu.show = false; msgSheet.show = false;
      if (!m) return;
      try {
        const r = await API.post('/api/sessions/' + currentId.value + '/messages/' + m.id + '/delete');
        msgs.value = msgs.value.filter(x => x.id !== m.id);
        const s = sessions.value.find(x => x.id === currentId.value);
        if (s && r && r.last_msg) s.lastMsg = r.last_msg;
        ElementPlus.ElMessage.success('已删除');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    // --- v2.56 编辑消息：只有「自己发的纯文字、且没撤回」的能改 ---
    const canEdit = (m) => !!(m && m.from === 'agent' && !m.recalled && (m.msg_type || 'text') === 'text' && !(m.attachments || []).length);
    const editMsg = async (m) => {
      msgMenu.show = false; msgSheet.show = false;
      if (!m || !currentId.value) return;
      let value;
      try {
        ({ value } = await ElementPlus.ElMessageBox.prompt(
          '改完对方那边也会同步变成新内容，消息下方会标「已编辑」', '编辑消息',
          { inputValue: m.text || '', inputType: 'textarea', inputPlaceholder: '输入新的内容',
            confirmButtonText: '保存', cancelButtonText: '取消',
            inputValidator: (v) => (String(v || '').trim() !== '' || '内容不能为空') }));
      } catch (e) { return; }
      const text = String(value || '').trim();
      if (!text || text === m.text) return;
      try {
        const r = await API.post('/api/sessions/' + currentId.value + '/messages/' + m.id + '/edit', { text: text });
        m.text = r.text || text;
        m.edited = true;
        const s = sessions.value.find(x => x.id === currentId.value);
        if (s) s.lastMsg = text.slice(0, 60);
        ElementPlus.ElMessage.success('已修改');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    // --- 多选 ---
    const selMode = ref(false);
    const selIds = ref([]);
    const enterSel = (m) => { msgMenu.show = false; msgSheet.show = false; selMode.value = true; selIds.value = m ? [m.id] : []; };
    const exitSel = () => { selMode.value = false; selIds.value = []; };
    const toggleSel = (id) => {
      const i = selIds.value.indexOf(id);
      if (i >= 0) selIds.value.splice(i, 1); else selIds.value.push(id);
    };
    const selAll = () => { selIds.value = msgs.value.filter(m => m.from !== 'system').map(m => m.id); };
    const selCopy = async () => {
      const picked = msgs.value.filter(m => selIds.value.includes(m.id));
      if (!picked.length) { ElementPlus.ElMessage.warning('还没选消息'); return; }
      const txt = picked.map(m => quoteWho(m) + '：' + (m.text || quoteText(m))).join('\n');
      try { await navigator.clipboard.writeText(txt); ElementPlus.ElMessage.success('已复制 ' + picked.length + ' 条'); }
      catch (e) { ElementPlus.ElMessage.warning('复制失败'); }
    };
    const selDelete = async () => {
      const picked = msgs.value.filter(m => selIds.value.includes(m.id));
      if (!picked.length) { ElementPlus.ElMessage.warning('还没选消息'); return; }
      try {
        await ElementPlus.ElMessageBox.confirm('删除选中的 ' + picked.length + ' 条消息？删除后不可恢复。', '批量删除',
          { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' });
      } catch (e) { return; }
      let ok = 0;
      for (const m of picked) {
        try { await API.post('/api/sessions/' + currentId.value + '/messages/' + m.id + '/delete'); ok++; } catch (e) {}
      }
      const ids = picked.map(m => m.id);
      msgs.value = msgs.value.filter(x => !ids.includes(x.id));
      exitSel();
      ElementPlus.ElMessage.success('已删除 ' + ok + ' 条');
    };
    // --- Ctrl+V 粘贴图片直接发 ---
    const onPaste = async (ev) => {
      if (!currentId.value) return;
      const items = (ev.clipboardData && ev.clipboardData.items) || [];
      const files = [];
      for (const it of items) { if (it.kind === 'file') { const f = it.getAsFile(); if (f) files.push(f); } }
      if (!files.length) return;
      ev.preventDefault();
      // v2.43.1：粘贴的图片只放进「待发区」，不直接发送 —— 可以配文字、连粘多张再一起发
      let ok = 0;
      for (const f of files) { if (await stageImage(f)) ok++; }
      if (ok) ElementPlus.ElMessage.success('已粘贴 ' + ok + ' 张图片到待发区，配好文字后点发送');
    };
    // --- 发视频 / 发文件：同样先进待发区，和文字一起发 ---
    const MAX_UPLOAD_MEDIA = 20 * 1024 * 1024;
    const sendFile = async (ev, kind) => {
      const file = ev.target.files[0];
      ev.target.value = '';
      if (!file || !currentId.value) return;
      if (file.size > MAX_UPLOAD_MEDIA) { ElementPlus.ElMessage.warning('文件不能超过 20MB'); return; }
      const data = await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(file); });
      try {
        const up = await API.post('/api/upload', { data, kind, filename: file.name });
        pendingAtts.value.push(up);
        ElementPlus.ElMessage.success((kind === 'video' ? '视频' : '文件') + '已加入待发区，配好文字后点发送');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    // --- 发定位：能定位就用真实坐标，不能定位就让客服手填地点名 ---
    const sendLocation = () => {
      if (!currentId.value) return;
      const post = async (att) => {
        try {
          const body = { text: '', msg_type: 'location', attachments: [att] };
          if (replyTo.value) body.reply_to = replyTo.value.id;
          const r = await API.post('/api/sessions/' + currentId.value + '/messages', body);
          msgs.value.push(r.message); maxMsgId.value = r.message.id; replyTo.value = null; pinBottom(1400);
        } catch (e) { ElementPlus.ElMessage.error(e.message); }
      };
      const manual = async () => {
        try {
          const r = await ElementPlus.ElMessageBox.prompt('浏览器定位不可用，直接填一个地点名称，访客点击会在地图里搜索', '发送位置',
            { inputPlaceholder: '如：山东省潍坊市奎文区胜利东街 1 号 或 公司名称', confirmButtonText: '发送', cancelButtonText: '取消' });
          const name = ((r && r.value) || '').trim();
          if (name) post({ type: 'location', name, lat: '', lng: '' });
        } catch (e) {}
      };
      if (!navigator.geolocation) { manual(); return; }
      ElementPlus.ElMessage.info('正在获取当前位置…');
      navigator.geolocation.getCurrentPosition((pos) => {
        const lat = pos.coords.latitude.toFixed(6);
        const lng = pos.coords.longitude.toFixed(6);
        post({ type: 'location', name: '我的位置', lat, lng,
          url: 'https://uri.amap.com/marker?position=' + lng + ',' + lat + '&name=' + encodeURIComponent('我的位置') });
      }, manual, { timeout: 8000, enableHighAccuracy: false });
    };

    const startRec = async () => {
      if (!navigator.mediaDevices || !window.MediaRecorder) { ElementPlus.ElMessage.warning('当前浏览器不支持录音'); return false; }
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
            XX
          } catch (e) { ElementPlus.ElMessage.error(e.message); }
        };
        recSendFlag = false;
        mediaRecorder.start();
        recording.value = true; recSecs.value = 0; recStartAt = Date.now();
        recTimer = setInterval(() => { recSecs.value = Math.floor((Date.now() - recStartAt) / 1000); if (recSecs.value >= 60) stopRec(true); }, 500);
        return true;
      } catch (e) { ElementPlus.ElMessage.warning('无法获取麦克风权限'); return false; }
    };
    const stopRec = (sendIt) => {
      if (!mediaRecorder) return;
      recSendFlag = sendIt;
      clearInterval(recTimer);
      recording.value = false;
      try { mediaRecorder.stop(); } catch (e) {}
    };

    // ===== 语音：按住说话（松开发送 / 上滑取消，与访客端一致）=====
    const voiceHold = reactive({ on: false, cancel: false, secs: 0 });
    let vhTimer = null, vhY0 = 0, vhPressed = false;
    const startHoldVoice = async (e) => {
      if (voiceHold.on || vhPressed) return;
      if (!currentId.value) { ElementPlus.ElMessage.warning('请先选择会话'); return; }
      vhPressed = true;
      try { if (e.target && e.target.setPointerCapture && e.pointerId != null) e.target.setPointerCapture(e.pointerId); } catch (err) {}
      const t = (e.touches && e.touches[0]) ? e.touches[0] : e;
      vhY0 = t.clientY;
      voiceHold.cancel = false;
      const ok = await startRec();
      if (!ok) { vhPressed = false; return; }
      if (!vhPressed) { stopRec(false); return; }   // 授权弹窗期间已松手
      voiceHold.on = true; voiceHold.secs = 0;
      clearInterval(vhTimer);
      vhTimer = setInterval(() => { voiceHold.secs = Math.floor((Date.now() - recStartAt) / 1000); }, 200);
    };
    const moveHoldVoice = (e) => {
      if (!voiceHold.on) return;
      const t = (e.touches && e.touches[0]) ? e.touches[0] : e;
      voiceHold.cancel = (vhY0 - t.clientY) > 56;
    };
    const endHoldVoice = () => {
      vhPressed = false;
      if (!voiceHold.on) return;
      clearInterval(vhTimer);
      const tooShort = Math.floor((Date.now() - recStartAt) / 1000) < 1;
      const explicit = voiceHold.cancel;
      const cancel = explicit || tooShort;
      voiceHold.on = false; voiceHold.cancel = false;
      stopRec(!cancel);
      if (tooShort && !explicit) ElementPlus.ElMessage.warning('说话时间太短');
    };

    // 会话操作
    const closeSession = async () => {
      const sid = currentId.value;
      if (!sid) return;
      // 头部「✔ 结束」按钮本身带 popconfirm（含「访客端聊天记录将清除」提示），此处不再二次弹窗
      try {
        await API.post('/api/sessions/' + sid + '/close');
        ElementPlus.ElMessage.success('会话已结束');
        sessions.value = sessions.value.filter(s => s.id !== sid);
        currentId.value = 0; msgs.value = [];
        await pollOnce();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    // 归档 / 取消归档（v2.25）
    const archiveSession = async (on) => {
      const sid = currentId.value;
      if (!sid) return;
      try {
        await API.post('/api/sessions/' + sid + '/archive', { on: on ? 1 : 0 });
        ElementPlus.ElMessage.success(on ? '已归档' : '已取消归档');
        if (!on) await pollOnce();
        else {
          sessions.value = sessions.value.filter(s => s.id !== sid);
          currentId.value = 0; msgs.value = [];
          await pollOnce();
        }
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    // 右键菜单（v2.26）：列表项右键弹出（删除腾额度 / 归档 / 结束）
    const ctxMenu = reactive({ show: false, x: 0, y: 0, sid: 0, name: '', pinned: 0, note: '' });

    // ---------- 多选批量操作（v2.67）----------
    const toggleMulti = () => {
      sessMulti.value = !sessMulti.value;
      sessChecked.value = [];
      if (sessMulti.value) { try { closeCtxMenu(); } catch (e) {} }
    };
    const toggleSessCheck = (id) => {
      const i = sessChecked.value.indexOf(id);
      if (i >= 0) sessChecked.value.splice(i, 1); else sessChecked.value.push(id);
    };
    const checkAllSess = () => { sessChecked.value = filteredSessions.value.map(s => s.id); };
    const bulkSessions = async (action) => {
      const ids = sessChecked.value.slice();
      if (!ids.length) return;
      const label = { archive: '归档', close: '结束', delete: '删除', read: '标为已读' }[action] || action;
      if (action === 'delete' || action === 'close') {
        const tip = action === 'delete'
          ? '彻底删除选中的 ' + ids.length + ' 个会话及其全部聊天记录？删除后不可恢复。'
          : '结束选中的 ' + ids.length + ' 个会话？访客端聊天记录将清除。';
        try {
          await ElementPlus.ElMessageBox.confirm(tip, '批量' + label,
            { type: 'warning', confirmButtonText: label, cancelButtonText: '取消' });
        } catch (e) { return; }
      }
      try {
        const r = await API.post('/api/sessions/bulk', { ids: ids, action: action });
        const extra = r.skipped ? '（' + r.skipped + ' 个状态不允许，已跳过）' : '';
        ElementPlus.ElMessage.success('已' + label + ' ' + r.done + ' 个会话' + extra);
        sessChecked.value = [];
        if (ids.indexOf(currentId.value) >= 0) { currentId.value = 0; msgs.value = []; }
        await pollOnce();
        loadVisitors();   // v2.88.4 批量删/归档/结束后，访客列表也跟着更新
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };

    const openCtxMenu = (s, ev) => {
      ev.preventDefault();
      ev.stopPropagation();
      closeMsgMenu(); quickCtx.show = false;
      ctxMenu.x = ev.clientX; ctxMenu.y = ev.clientY;
      ctxMenu.sid = s.id; ctxMenu.name = s.name; ctxMenu.pinned = s.pinned ? 1 : 0; ctxMenu.note = s.note || '';
      ctxMenu.show = true;
    };
    // 会话行「⋯」按钮：菜单贴着按钮弹（不是鼠标位置）
    const openCtxMenuFromBtn = (s, ev) => {
      ev.preventDefault();
      closeMsgMenu(); quickCtx.show = false;
      const r = ev.currentTarget.getBoundingClientRect();
      const w = 230;
      ctxMenu.x = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8));
      ctxMenu.y = Math.min(r.bottom + 4, window.innerHeight - 300);
      ctxMenu.sid = s.id; ctxMenu.name = s.name; ctxMenu.pinned = s.pinned ? 1 : 0; ctxMenu.note = s.note || '';
      ctxMenu.show = true;
    };
    const closeCtxMenu = () => { ctxMenu.show = false; };
    // v2.43 置顶 / 标未读 / 拉黑（会话列表右键 & ⋯ 菜单）
    const ctxMenuPin = async () => {
      const sid = ctxMenu.sid, to = ctxMenu.pinned ? 0 : 1;
      closeCtxMenu();
      try {
        const r = await API.post('/api/sessions/' + sid + '/pin', { pinned: to });
        const s = sessions.value.find(x => x.id === sid);
        if (s) { s.pinned = r.pinned ? 1 : 0; }
        sessions.value = sessions.value.slice().sort((a, b) =>
          (b.pinned ? 1 : 0) - (a.pinned ? 1 : 0) || String(b.lastTime).localeCompare(String(a.lastTime)));
        ElementPlus.ElMessage.success(r.pinned ? '已置顶' : '已取消置顶');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const ctxMenuUnread = async () => {
      const sid = ctxMenu.sid;
      closeCtxMenu();
      try {
        const s = sessions.value.find(x => x.id === sid);
        if (s) s.unread = Math.max(1, s.unread || 0);
        await API.post('/api/sessions/' + sid + '/read', { unread: 1 });
        ElementPlus.ElMessage.success('已标为未读');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    // v2.73 改成「修改名称」（右键 / ⋯ 菜单）= 重命名这条会话的访客
    const ctxMenuRename = async () => {
      const sid = ctxMenu.sid, name = ctxMenu.name;
      closeCtxMenu();
      let value;
      try {
        ({ value } = await ElementPlus.ElMessageBox.prompt(
          '输入新的访客名称（列表与聊天中将显示新名称）', '修改名称',
          { inputValue: '', inputPlaceholder: '原名称：' + name, inputPattern: /\S+/,
            inputErrorMessage: '名称不能为空', confirmButtonText: '确定', cancelButtonText: '取消' }));
      } catch (e) { return; }
      const v = String(value || '').trim().slice(0, 30);
      if (!v) return;
      try {
        await API.put('/api/sessions/' + sid + '/name', { name: v });
        const s = sessions.value.find(x => x.id === sid);
        if (s) s.name = v;
        if (currentId.value === sid && profile.value) profile.value.session.name = v;
        ElementPlus.ElMessage.success('名称已修改');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    // 备注改到右侧「访客信息 → 备注」里编辑（ctxMenuNote 保留给旧的引用点）
    const ctxMenuNote = async () => {
      const sid = ctxMenu.sid, name = ctxMenu.name, old = ctxMenu.note || '';
      closeCtxMenu();
      let value;
      try {
        ({ value } = await ElementPlus.ElMessageBox.prompt(
          '给「' + name + '」写个备注（只有你能看到，会显示在会话列表上）',
          '修改备注',
          { inputValue: old, inputType: 'textarea', inputPlaceholder: '例如：老客户 / 已报价 / 微信已加',
            confirmButtonText: '保存', cancelButtonText: '取消', inputErrorMessage: '最多 60 个字' }));
      } catch (e) { return; }
      const v = String(value || '').trim().slice(0, 60);
      try {
        await API.put('/api/sessions/' + sid + '/contacts/note', { value: v });
        const s = sessions.value.find(x => x.id === sid);
        if (s) s.note = v;
        if (currentId.value === sid && noteDraft) noteDraft.value = v;
        ElementPlus.ElMessage.success(v ? '备注已保存' : '备注已清空');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const ctxMenuBlock = async () => {
      const sid = ctxMenu.sid, name = ctxMenu.name;
      closeCtxMenu();
      try {
        await ElementPlus.ElMessageBox.confirm('拉黑「' + name + '」的 IP？拉黑后对方无法再进入或发消息。', '拉黑访客',
          { type: 'warning', confirmButtonText: '拉黑', cancelButtonText: '取消' });
        await API.post('/api/sessions/' + sid + '/block');
        ElementPlus.ElMessage.success('已拉黑');
      } catch (e) { if (e !== 'cancel' && e && e.message) ElementPlus.ElMessage.error(e.message); }
    };
    /* v2.90.15 右键的「合并同 IP 的会话」已去掉：同 IP 重复窗口现在由
       auto_merge_duplicates() 自动合并（打开列表/轮询时懒执行），不用客服手点。
       设置页那个「一键合并」还留着，做历史遗留清理用。 */
    /* v2.90 设置页：把全账号「同 IP 的重复会话」一次性合并（历史遗留的一堆重复窗口清理用） */
    const mergeAllByIp = async () => {
      try {
        const r = await API.post('/api/sessions/merge-by-ip', { all: 1 });
        ElementPlus.ElMessage.success(r.merged
          ? ('已处理 ' + r.groups + ' 个 IP：合并 ' + r.merged + ' 段会话（搬进 ' + r.moved + ' 条消息）')
          : '没有发现同 IP 的重复会话');
        await pollOnce();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const ctxMenuArchive = async () => {
      const sid = ctxMenu.sid; closeCtxMenu();
      try {
        await API.post('/api/sessions/' + sid + '/archive', { on: 1 });
        ElementPlus.ElMessage.success('已归档');
        sessions.value = sessions.value.filter(s => s.id !== sid);
        if (currentId.value === sid) { currentId.value = 0; msgs.value = []; }
        await pollOnce();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const ctxMenuClose = async () => {
      const sid = ctxMenu.sid; closeCtxMenu();
      try { await confirmEndSession(ctxMenu.name || ''); } catch (e) { return; }
      try {
        await API.post('/api/sessions/' + sid + '/close');
        ElementPlus.ElMessage.success('已结束');
        sessions.value = sessions.value.filter(s => s.id !== sid);
        if (currentId.value === sid) { currentId.value = 0; msgs.value = []; }
        await pollOnce();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const ctxMenuDelete = async () => {
      const sid = ctxMenu.sid; const name = ctxMenu.name; closeCtxMenu();
      try {
        /* v2.90.77 删除 = 立即永久删除（不再有回收站/撤销），确认文案必须说清不可恢复 */
        await ElementPlus.ElMessageBox.confirm(
          '删除「' + name + '」这段对话？删除后不可恢复：聊天记录、图片/语音/文件都会一起删掉，会话额度立刻腾出。', '永久删除会话',
          { type: 'warning', confirmButtonText: '永久删除', cancelButtonText: '取消' });
        await API.post('/api/sessions/' + sid + '/delete');
        sessions.value = sessions.value.filter(s => s.id !== sid);
        if (currentId.value === sid) { currentId.value = 0; msgs.value = []; }
        await pollOnce();
        loadVisitors();   // v2.88.4 访客列表跟着刷新，删掉的会话那边也要没了
        ElementPlus.ElMessage.success('已永久删除');
      } catch (e) { if (e !== 'cancel' && e && e.message) ElementPlus.ElMessage.error(e.message); }
    };

    /* v2.60.11 回收站：恢复 / 彻底删除（按单条操作，不是一键全恢复） */
    /* v2.60.13 工作台用恢复码找回会话（客户报码 / 会话被删进回收站） */
    const resumeFindOpen = ref(false);
    const resumeFindCode = ref('');
    const resumeFindErr = ref('');
    const resumeFindBusy = ref(false);
    const findByResume = async () => {
      const rc = (resumeFindCode.value || '').trim().toLowerCase();
      if (!rc) { resumeFindErr.value = '请输入恢复码'; return; }
      resumeFindBusy.value = true; resumeFindErr.value = '';
      try {
        const r = await API.post('/api/sessions/by-resume', { code: rc });
        resumeFindOpen.value = false; resumeFindCode.value = '';
        /* v2.60.14 如果当前正开着一段对话，问一句：合并进来，还是跳过去？
           —— 同一个人微信来一次、浏览器又来一次会变成两条会话，客服一般想合并成一条。 */
        if (currentId.value && currentId.value !== r.session_id) {
          const target = currentId.value;
          try {
            await ElementPlus.ElMessageBox.confirm(
              '找到「' + (r.name || '') + '」这段对话。要把它合并到当前会话「' + (current.value ? current.value.name : '') + '」吗？合并后只留一条，聊天记录是完整的。',
              '找到另一段对话',
              { type: 'info', confirmButtonText: '合并到当前会话', cancelButtonText: '跳到那段对话', distinguishCancelAndClose: true });
            const mr = await API.post('/api/sessions/' + target + '/merge', { from: r.session_id });
            await pollOnce();
            msgs.value = []; await openSession(target);
            ElementPlus.ElMessage.success('已合并 ' + (mr.moved || 0) + ' 条消息到「' + (mr.into_name || '') + '」');
          } catch (action) {
            if (action === 'cancel') {
              tab.value = 'work';
              await pollOnce();
              await openSession(r.session_id);
              ElementPlus.ElMessage.success('已打开「' + (r.name || '') + '」');
            }
          }
          return;
        }
        tab.value = 'work';
        await pollOnce();
        await openSession(r.session_id);
        ElementPlus.ElMessage.success(r.from_trash ? '已从回收站恢复并打开「' + (r.name || '') + '」' : '已找到「' + (r.name || '') + '」');
      } catch (e) { resumeFindErr.value = e.message; }
      finally { resumeFindBusy.value = false; }
    };
    /** v2.60.14 右键会话 → 合并到当前会话 */
    const ctxMenuMerge = async () => {
      const from = ctxMenu.sid; const name = ctxMenu.name; const target = currentId.value;
      closeCtxMenu();
      if (!target || !from || target === from) return;
      try {
        await ElementPlus.ElMessageBox.confirm(
          '把「' + name + '」的聊天记录合并到「' + (current.value ? current.value.name : '当前会话') + '」？合并后这条会话会消失，客户那边看到的是一整段完整记录。',
          '合并会话', { type: 'warning', confirmButtonText: '合并', cancelButtonText: '取消' });
        const r = await API.post('/api/sessions/' + target + '/merge', { from });
        ElementPlus.ElMessage.success('已合并 ' + (r.moved || 0) + ' 条消息');
        await pollOnce();
        msgs.value = []; await openSession(target);
      } catch (e) { if (e && e.message && e !== 'cancel') ElementPlus.ElMessage.error(e.message); }
    };
    const restoreSession = async (s) => {
      if (!s) return;
      try {
        await API.post('/api/sessions/' + s.id + '/restore');
        ElementPlus.ElMessage.success('已恢复「' + (s.name || '会话') + '」');
        await pollOnce();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const purgeSession = async (s) => {
      if (!s) return;
      try {
        await API.post('/api/sessions/' + s.id + '/purge');
        ElementPlus.ElMessage.success('已彻底删除');
        await pollOnce();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    /** 带「撤销」按钮的提示（点一下就把刚做的事撤回，只针对这一条） */
    const toastWithUndo = (msg, actionText, undoFn) => {
      const box = ElementPlus.ElMessage({
        duration: 8000, showClose: true, type: 'success', offset: 60,
        message: h('span', { style: 'display:inline-flex;align-items:center;gap:10px;' }, [
          h('span', null, msg),
          h('a', {
            style: 'color:#1677ff;font-weight:600;cursor:pointer;',
            onClick: async () => { try { box.close(); await undoFn(); ElementPlus.ElMessage.success('已撤销'); } catch (e) { ElementPlus.ElMessage.error(e.message); } },
          }, actionText),
        ]),
      });
      return box;
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
    const newCode = async () => { try { await API.post('/api/tenant/service-code'); ElementPlus.ElMessage.success('已换新码（二维码链接不变，顾客手里那张照用）'); await loadLinks(); } catch (e) { ElementPlus.ElMessage.error(e.message); } };
    const delCode = async (c) => { try { await API.del('/api/tenant/service-code?code=' + c.code); await loadLinks(); } catch (e) { ElementPlus.ElMessage.error(e.message); } };
    const blockSession = async () => {
      if (!currentId.value) return;
      try {
        const r = await API.post('/api/sessions/' + currentId.value + '/block');
        await loadBlacklist();
        toastWithUndo('已拉黑 ' + r.ip, '撤销拉黑', async () => { await unblockIp(r.ip); });
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const blockIp = async (ip) => {
      try {
        await API.post('/api/ip-blacklist', { ip, reason: '访客资源手动拉黑' });
        await loadBlacklist();
        toastWithUndo('已拉黑 ' + ip, '撤销拉黑', async () => { await unblockIp(ip); });
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    /** v2.60.11 解除拉黑（按单个 IP）：把黑名单里这条删掉即可立刻恢复 */
    const unblockIp = async (ip) => {
      if (!ip) return;
      let item = (blacklist.value || []).find(b => String(b.ip) === String(ip));
      if (!item) { try { await loadBlacklist(); } catch (e) {} item = (blacklist.value || []).find(b => String(b.ip) === String(ip)); }
      if (!item) { ElementPlus.ElMessage.warning('黑名单里没有这条记录'); return; }
      await API.del('/api/ip-blacklist/' + item.id);
      await loadBlacklist();
    };
    /** 当前会话的 IP 是否在黑名单里（访客信息里给「解除拉黑」用） */
    const currentIpBlocked = computed(() => {
      if (!current.value || !current.value.ip) return false;
      return (blacklist.value || []).some(b => String(b.ip) === String(current.value.ip));
    });
    const unblockCurrent = async () => {
      if (!current.value) return;
      try { await unblockIp(current.value.ip); ElementPlus.ElMessage.success('已解除拉黑'); }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
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
      /* v2.90.60 改成「就地改名」：点名字直接变输入框（不再弹那个 prompt 框） */
      startRename();
    };
    const nameEditing = ref(false);
    const nameDraft = ref('');
    const nameScope = ref('head');   // 在哪儿点的就地编辑：head=会话头，info=右侧访客信息
    const startRename = (scope) => {
      if (!currentId.value) return;
      nameScope.value = (scope === 'info') ? 'info' : 'head';
      nameDraft.value = current.value ? (current.value.name || '') : '';
      nameEditing.value = true;
      nextTick(() => {
        const el = nameScope.value === 'info'
          ? document.querySelector('.info-col .name-input')
          : document.querySelector('.chat-head .name-input');
        if (el) { el.focus(); try { el.select(); } catch (e) {} }
      });
    };
    const cancelRename = () => { nameEditing.value = false; };
    const commitRename = async () => {
      if (!nameEditing.value) return;
      nameEditing.value = false;
      const name = (nameDraft.value || '').trim().slice(0, 30);
      const old = current.value ? current.value.name : '';
      if (!name || !currentId.value || name === old) return;   // 空名字 / 没改：当没发生
      try {
        await API.put('/api/sessions/' + currentId.value + '/name', { name });
        const s = sessions.value.find(x => x.id === currentId.value);
        if (s) s.name = name;
        if (profile.value && profile.value.session) profile.value.session.name = name;
        ElementPlus.ElMessage({ message: '已改名：' + name, duration: 900 });
      } catch (e) { ElementPlus.ElMessage.error(e.message || '改名失败'); }
    };
    const renameVisitorLegacy = async () => {
      if (!currentId.value) return;
      try {
        // v2.71 输入框改成空白——原来预填了旧名字，还得先全选删掉才能改
        const { value } = await ElementPlus.ElMessageBox.prompt('输入新的访客名称（列表与聊天中将显示新名称）', '重命名',
          { inputValue: '', inputPlaceholder: current.value ? ('原名称：' + current.value.name) : '请输入新名称',
            inputPattern: /\S+/, inputErrorMessage: '名称不能为空',
            confirmButtonText: '确定', cancelButtonText: '取消' });
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
          agent_avatar_on: brand.agent_avatar_on,
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
      closed_keep_days: 3, archived_keep_days: 0, max_sessions: 0,
      publicity_on: false, publicity_title: '', publicity_content: '',
      inform_on: false, inform_text: '',
      // 「请求访客上传图片」话术（可自定义）
      ask_image_text: '方便的话，请上传一下相关图片，帮您更快定位问题～',
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
    // 声音设置保存（v2.27）：键映射到租户 settings
    const saveSnd = async () => {
      try {
        await API.put('/api/tenant/settings', {
          agent_snd_recv_on: sndCfg.agent_recv_on, agent_snd_recv_which: sndCfg.agent_recv_which,
          agent_snd_send_on: sndCfg.agent_send_on, agent_snd_send_which: sndCfg.agent_send_which,
          visitor_snd_recv_on: sndCfg.visitor_recv_on, visitor_snd_recv_which: sndCfg.visitor_recv_which,
          visitor_snd_send_on: sndCfg.visitor_send_on, visitor_snd_send_which: sndCfg.visitor_send_which,
        });
        ElementPlus.ElMessage.success('声音设置已保存');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    // ===== 安全与维护 / 访客资源 / 引导 / 深色 =====
    // v2.78 公告默认开 + 默认文案（与后端 routes/visitor.php 的默认值保持一致）
    /* v2.91 AI 聊天（只对白名单租户渲染：aiCfg.entitled） */
    const aiCfg = reactive({
      entitled: false, on: false, base_url: 'https://api.b.ai', model: 'deepseek-v4.1-flash',
      key: '', key_set: false, sign: '', tg_token: '', tg_token_set: false, tg_chat: '',
      bridge_secret: '', max_per_pay: '450', retry_pay: '100', min_pay: '50',
    });
    const aiSaving = ref(false);
    const genAiSecret = () => {
      const a = new Uint8Array(16);
      try { (window.crypto || {}).getRandomValues ? window.crypto.getRandomValues(a) : a.forEach((_, i) => { a[i] = Math.floor(Math.random() * 256); }); }
      catch (e) { a.forEach((_, i) => { a[i] = Math.floor(Math.random() * 256); }); }
      aiCfg.bridge_secret = Array.from(a).map(x => x.toString(16).padStart(2, '0')).join('');
    };
    const saveAi = async () => {
      aiSaving.value = true;
      try {
        // key / token 留空 = 不改（后端也是这个口径）
        await API.put('/api/tenant/settings', {
          ai_chat_on: aiCfg.on, ai_base_url: aiCfg.base_url, ai_model: aiCfg.model,
          ai_sign: aiCfg.sign, ai_tg_chat: aiCfg.tg_chat, ai_bridge_secret: aiCfg.bridge_secret,
          ai_max_per_pay: aiCfg.max_per_pay, ai_retry_pay: aiCfg.retry_pay, ai_min_pay: aiCfg.min_pay,
          ai_key: aiCfg.key, ai_tg_token: aiCfg.tg_token,
        });
        if (aiCfg.key) { aiCfg.key_set = true; aiCfg.key = ''; }
        if (aiCfg.tg_token) { aiCfg.tg_token_set = true; aiCfg.tg_token = ''; }
        ElementPlus.ElMessage.success('AI 聊天设置已保存');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { aiSaving.value = false; }
    };
    const sec = reactive({ notice_on: true, notice_text: '不要关闭此网页，若退出请先复制浏览器打开', filter_keywords: '', auto_blacklist: false, dedup_on: false, ip_merge_on: true, ip_limit_on: false, ip_limit_count: 5 });
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
      await loadDevices();
    };

    /* ===== v2.60.10 登录设备（防卡密共享）：默认 3 台，能看/备注/踢 ===== */
    const devices = ref([]);
    const deviceLimit = ref(3);
    const loadDevices = async () => {
      try {
        const r = await API.get('/api/login-devices');
        devices.value = r.devices || [];
        deviceLimit.value = r.limit || 3;
      } catch (e) {}
    };
    /* v2.86 「这台就是我正在用的设备」：服务端按登录令牌里的设备号标记；
       令牌里没有设备号（老登录态）时，用本机自己的设备号前 8 位兜底对一下。 */
    const isCurDevice = (row) => {
      if (!row) return false;
      if (row.current) return true;
      try {
        const mine = String(API.deviceId() || '').slice(0, 8);
        return !!mine && row.dev_id === mine;
      } catch (e) { return false; }
    };
    const kickDevice = async (d) => {
      try {
        await API.post('/api/login-devices/kick', { id: d.id });
        ElementPlus.ElMessage.success('已踢下线');
        await loadDevices();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const renameDevice = async (d) => {
      try {
        const { value } = await ElementPlus.ElMessageBox.prompt(
          '给这台设备起个名字（例如：老板手机 / 客服小李），以后要踢谁一目了然',
          '设备备注', { inputValue: d.note || '', inputPlaceholder: '如：老板手机', confirmButtonText: '保存', cancelButtonText: '取消' });
        await API.post('/api/login-devices/note', { id: d.id, note: (value || '').trim() });
        await loadDevices();
      } catch (e) {}
    };
    const saveSecurity = async () => {
      try {
        await API.put('/api/tenant/settings', { ...sec });
        ElementPlus.ElMessage.success('已保存');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    /* v2.89.4 归属地重算：清空已有地区，工作台轮询会按新库慢慢补全（每轮 2 条） */
    const refreshGeo = async () => {
      try {
        const r = await API.post('/api/tenant/geo-refresh', {});
        ElementPlus.ElMessage.success('已清空 ' + (r.cleared || 0) + ' 条地区，正在按新库重新解析（列表里会陆续刷新出来）');
        await pollOnce();
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
    /* v2.88.4 访客列表 → 点「会话」跳到会话接待：
       原来直接 openSession 就走了，如果那条会话是「已归档 / 已结束」的，当前列表里根本没有它 →
       聊天区空白，看着就是「跳转不了」。现在先问一下它属于哪个分区，切过去再打开。 */
    const openVisitorSessions = async (row) => {
      const sid = parseInt(row && row.last_session_id, 10) || 0;
      if (!sid) { ElementPlus.ElMessage.info('该访客暂无会话记录'); return; }
      let st = '';
      try {
        const p = await API.get('/api/sessions/' + sid + '/profile');
        st = (p && p.session && p.session.status) || '';
      } catch (e) { /* 拿不到就按在线处理 */ }
      sessScope.value = st === 'archived' ? 'archived' : (st === 'closed' ? 'closed' : 'online');
      tab.value = 'work';
      try { await pollOnce(); } catch (e) {}
      await openSession(sid);
    };

    // 接入引导（真实配置状态）
    const guide = computed(() => {
      if (!me.value || me.value.role !== 'tenant') return [];
      return [
        { k: 'welcome', icon: '👋', label: '设置欢迎语', desc: '访客进入自动打招呼', done: welcomeMsgs.value.length > 0, go: () => openSetting('welcome') },
        { k: 'auto', icon: '🤖', label: '设置自动回复', desc: '关键词自动应答', done: autoReplies.value.length > 0, go: () => openSetting('auto') },
        { k: 'link', icon: '🔗', label: '生成客服码', desc: '扫码咨询入口', done: !!(links.value && (links.value.codes || []).length), go: () => tab.value = 'links' },
      ];
    });
    const guideDone = computed(() => guide.value.filter(g => g.done).length);

    // 访客端模板列表（与 visitor.js TEMPLATES 对齐；只保留有真实界面结构的模板）
    const tplList = [
      { k: 'whatsapp', label: 'WhatsApp', g1: '#008069' },
      { k: 'default', label: '默认客服（默认）', g1: '#1677ff' },
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
        colorId = localStorage.getItem('kfxt_ui_' + key) || null;
      } catch (e) {}
      if (window.UI && window.UI.cat === key) { tplId = UI.template().id; colorId = UI.current().id; }
      const t = tpls.find((x) => x.id === tplId) || tpls[0];
      const colors = (t && t.colors) || [];
      if (!colors.find((x) => x.id === colorId)) colorId = colors.length ? colors[0].id : '';
      uiSel[key] = { tpl: t ? t.id : '', color: colorId };
    });
    // 后台UI 配置页：两端都保留可配，按当前设备自动选中对应端，可手动切换
    const uiCurrentCat = (window.UI && window.UI.cat) || 'pc';
    const uiActiveCat = ref(uiCurrentCat);
    const uiCatList = Object.keys(uiReg.categories || {}).map((key) => ({
      key,
      label: uiReg.categories[key].label,
      pages: (uiReg.categories[key].pages || []).join(' / '),
    }));
    const uiActiveList = Vue.computed(() => uiCatList.filter((c) => c.key === uiActiveCat.value));
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
      if (window.UI && window.UI.cat === key) {
        // 选择的分类就是当前页面加载器实例 → 即时生效
        if (tplChanged) UI.setTemplate(tplId);
        UI.set(colorId);
        sel.tpl = UI.template().id;   // 加载器可能因配色回退修正状态
        sel.color = UI.current().id;
      } else {
        // 另一端的配置：写入 localStorage，供该端浏览器读取
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
    /* v2.61 管理码弹窗：自定义客服码 + 逐条「检测码」（只查微信红没红） */
    const customCode = ref('');
    const customSaving = ref(false);
    const rowChecking = reactive({});   // { 客服码: true } 检测中
    const rowCheck = reactive({});      // { 客服码: {state, tag, text} }
    /* 批量：勾选 → 一键挑红的 → 批量作废。检测按次扣费，服务端有 5 分钟缓存兜底。 */
    const codeTableRef = ref(null);
    const codeSel = ref([]);
    const batchChecking = ref(false);
    const batchDone = ref(0);
    const batchTotal = ref(0);
    const onCodeSel = (rows) => { codeSel.value = rows || []; };
    const selAllCodes = () => {
      const t = codeTableRef.value;
      if (!t) return;
      (history.value.codes || []).forEach(r => t.toggleRowSelection(r, true));
    };
    const clearCodeSel = () => { if (codeTableRef.value) codeTableRef.value.clearSelection(); };
    const selRedCodes = () => {
      const t = codeTableRef.value;
      if (!t) return;
      const reds = (history.value.codes || []).filter(r => (rowCheck[r.code] || {}).state === 'red');
      if (!reds.length) { ElementPlus.ElMessage.warning('还没有检测出"红"的码，先点「批量检测」'); return; }
      (history.value.codes || []).forEach(r => t.toggleRowSelection(r, reds.includes(r)));
      ElementPlus.ElMessage.success('已选中 ' + reds.length + ' 个被拦截的码');
    };
    const checkOne = async (row) => {
      const key = row.code || row.link;
      rowChecking[key] = true;
      try {
        // 永远实时（force:1）：检测要的就是此刻的状态
        const r = await API.post('/api/tenant/code/check', { force: 1, channel: 'wx', link: row.link }) || {};
        rowCheck[key] = { state: r.state || 'err', tag: r.tag || '异常', text: r.text || '', at: r.at || '' };
        return r;
      } catch (e) {
        rowCheck[key] = { state: 'err', tag: '异常', text: e.message };
        return { state: 'err' };
      } finally { rowChecking[key] = false; }
    };
    const batchCheck = async () => {
      const list = codeSel.value.length ? codeSel.value.slice() : (history.value.codes || []).slice();
      if (!list.length) { ElementPlus.ElMessage.warning('这里还没有客服码'); return; }
      if (!codeSel.value.length) {
        try {
          await ElementPlus.ElMessageBox.confirm('没有勾选任何码，要实时检测全部 ' + list.length + ' 个吗？\n（实时检测，按次扣费：这次会消耗 ' + list.length + ' 次）', '批量检测',
            { confirmButtonText: '检测全部', cancelButtonText: '取消' });
        } catch (e) { return; }
      }
      batchChecking.value = true; batchDone.value = 0; batchTotal.value = list.length;
      let red = 0, ok = 0, other = 0;
      for (const row of list) {
        const r = await checkOne(row);
        if (r.state === 'red') red++; else if (r.state === 'ok') ok++; else other++;
        batchDone.value++;
        await new Promise(res => setTimeout(res, 250));   // 稍微错开，别把接口打爆
      }
      batchChecking.value = false;
      ElementPlus.ElMessage({
        type: red ? 'warning' : 'success',
        message: '检测完成：正常 ' + ok + ' 个' + (red ? ('，红了 ' + red + ' 个（可点「只选红的」批量作废）') : '') + (other ? ('，异常 ' + other + ' 个') : ''),
        duration: 6000,
      });
    };
    const batchDeleteCodes = async () => {
      const list = codeSel.value.slice();
      if (!list.length) return;
      try {
        await ElementPlus.ElMessageBox.confirm('作废选中的 ' + list.length + ' 个客服码？作废后这些链接立即失效，不可恢复。', '批量作废',
          { type: 'warning', confirmButtonText: '作废', cancelButtonText: '取消' });
      } catch (e) { return; }
      let n = 0;
      for (const row of list) {
        try { await API.del('/api/tenant/service-code?code=' + row.code); n++; } catch (e) {}
      }
      ElementPlus.ElMessage.success('已作废 ' + n + ' 个');
      clearCodeSel();
      await loadLinks(); await loadHistory(); renderMainQr(); checkCode(true);
    };
    const newCodeCustom = async () => {
      const c = (customCode.value || '').trim();
      if (c && !/^[A-Za-z0-9]{4,32}$/.test(c)) { ElementPlus.ElMessage.warning('客服码只能是 4~32 位字母或数字'); return; }
      customSaving.value = true;
      try {
        await API.post('/api/tenant/service-code', c ? { code: c } : {});
        ElementPlus.ElMessage.success((c ? ('已生成自定义码 ' + c) : '已生成新客服码') + '（旧码已失效，请把新二维码重新发给客户）');
        customCode.value = '';
        await loadLinks(); await loadHistory(); renderMainQr(); checkCode(true);
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      finally { customSaving.value = false; }
    };
    // 逐条检测：指定这条链接，只看微信红没红（不覆盖主卡上的检测状态）
    const checkRowCode = async (row) => {
      if (!row || !row.link) return;
      const key = row.code || row.link;
      rowChecking[key] = true;
      try {
        const r = await API.post('/api/tenant/code/check', { force: 1, channel: 'wx', link: row.link }) || {};
        rowCheck[key] = { state: r.state || 'err', tag: r.tag || '异常', text: r.text || '', at: r.at || '' };
        if (r.state === 'ok') ElementPlus.ElMessage.success('该码在微信里正常（未红）');
        else if (r.state === 'red') ElementPlus.ElMessage.error('该码已被微信拦截（红了）');
        else ElementPlus.ElMessage.warning(r.text || '检测未完成');
      } catch (e) {
        rowCheck[key] = { state: 'err', tag: '异常', text: e.message };
        ElementPlus.ElMessage.error(e.message);
      } finally { rowChecking[key] = false; }
    };
    const codeCfg = reactive({ nicknameOn: true, supportOn: true });
    const qrWrap = ref(null);
    const currentLink = Vue.computed(() => {
      if (!links.value) return '';
      // v2.64 「专属链接」已废弃：入口只认客服码（原来那句 domain_link.active 判断其实是死代码——
      // 接口压根不返回 active 字段，所以本来就没生效；现在直接删掉，避免以后误导）。
      const cs = (links.value.codes || []).filter(x => x.link);
      return cs.length ? cs[0].link : '';
    });
    // ===== 客服码卡片（v2.30）：样式状态 / canvas 卡片 / 微信拦截状态检测 =====
    const codeCanvas = ref(null);
    const logoFile = ref(null);
    const previewDlg = ref(false);
    const previewSrc = ref('');
    /* v2.60 「查看码」：预览/下载指定的那一个客服码（管理码弹窗里用）。
       viewLink 为空时一切照旧走当前主码，不影响原来那套。 */
    const viewLink = ref('');
    const viewCode = ref('');
    const CODE_LOGOS = [['none', '无'], ['avatar', '头像'], ['all', '聚合'], ['wechat', '微信'],
                        ['alipay', '支付宝'], ['qq', 'QQ'], ['douyin', '抖音'], ['upload', '上传']];
    // 二维码主色板：第一个是微信绿（默认）
    const CODE_BRANDS = [
      { c: '#07C160', n: '微信绿' }, { c: '#09BB07', n: '微信绿(旧)' }, { c: '#16181d', n: '黑色' },
      { c: '#2E6BE6', n: '蓝色' }, { c: '#0E7A5F', n: '深绿' }, { c: '#6B3FE0', n: '紫色' }, { c: '#C2410C', n: '橙色' },
    ];
    // 中心 Logo → 检测渠道：微信/QQ 有独立检测通道，其余走微信通道
    // v2.84 中心 Logo → 主色（选哪个图标，二维码就用哪个品牌色）
    const LOGO_BRAND = {
      wechat: '#07C160',   // 微信绿
      alipay: '#1677FF',   // 支付宝蓝
      qq: '#12B7F5',       // QQ 蓝
      taobao: '#FF5000',   // 淘宝橙
      douyin: '#16181d',   // 抖音黑
      all: '#07C160',      // 聚合（默认微信绿）
    };
    const CODE_CHANNEL = { wechat: 'wx', qq: 'qq', all: 'wx', alipay: 'wx', douyin: 'wx', avatar: 'wx', none: 'wx', custom: 'wx' };
    // 当前中心 Logo 对应的拦截检测通道（微信 logo → 查微信是否拦截；QQ logo → 查 QQ）
    const checkChannel = computed(() => CODE_CHANNEL[codeStyle.logo] || 'wx');
    const checkChannelLabel = computed(() => (checkChannel.value === 'qq' ? 'QQ' : '微信'));
    // 当前中心 Logo 的名字 + 是否走"默认微信通道"（支付宝/抖音等没有独立拦截检测接口）
    const checkLogoName = computed(() => ({
      wechat: '微信', qq: 'QQ', alipay: '支付宝', douyin: '抖音', all: '聚合', avatar: '头像',
      none: '无', upload: '上传图', custom: '自定义图',
    }[codeStyle.logo] || '无'));
    const checkUseDefaultChannel = computed(() => codeStyle.logo !== 'wechat' && codeStyle.logo !== 'qq');
    const CODE_STYLE_DEF = { dot: 'rounded', eye: 'rounded', brand: '#07C160', logo: 'none', logoSize: 'std',
                             topText: '扫码咨询', bottomText: '官方客服', colorDots: false, custom: '' };
    const codeStyle = reactive(Object.assign({}, CODE_STYLE_DEF));
    const codeImgCache = {};
    const codeCheck = reactive({ state: 'idle', text: '未检测', tag: '未检测', atText: '—', msg: '', channel: 'wx', loading: false });
    const codeValue = computed(() => {
      const cs = ((links.value && links.value.codes) || []).filter(x => x.code);
      return cs.length ? cs[0].code : '—';
    });
    /* v2.82 当前主码的创建时间 / 到期时间（到期时间跟卡密一致，永久卡显示"永久有效"） */
    const fmtDt = (s) => {
      const t = String(s || '');
      return t ? t.slice(0, 16).replace('T', ' ') : '';
    };
    const codeCreatedAt = Vue.computed(() => {
      const cs = ((links.value && links.value.codes) || []);
      return (cs.length && fmtDt(cs[0].created_at)) || '—';
    });
    const codeExpiresAt = Vue.computed(() => {
      const cs = ((links.value && links.value.codes) || []);
      /* v2.90.97 显示这条码自己的到期时间（新码默认 72 小时）；空 = 永久（老码/存量码仍是永久） */
      return (cs.length && fmtDt(cs[0].expires_at)) || '永久有效';
    });
    const codeIcons = () => (window.KFQCard && window.KFQCard.ICONS) || {};
    const codeLogoSrc = () => {
      if (codeStyle.logo === 'none') return null;
      if (codeStyle.logo === 'custom') return codeStyle.custom || null;
      if (codeStyle.logo === 'upload') return codeStyle.custom || codeIcons().upload || null;
      return codeIcons()[codeStyle.logo] || null;
    };
    const logoThumb = (k) => {
      if (k === 'upload') return codeStyle.custom || codeIcons().upload || '';
      return codeIcons()[k] || '';
    };
    const codeCardOpts = (scale) => {
      /* v2.83 二维码图上带上下文字（跟人家一样）：
         上 = 客服名称（「昵称显示」开关控制）  下 = 支持说明（「支持显示」开关控制）
         两个都关掉就输出一张纯二维码（无文字、中心 logo 加大） */
      /* v2.84 文字可自定义（存 code_top_text / code_bottom_text），默认「微信扫码咨询 / 官方客服」 */
      const topLine = codeCfg.nicknameOn ? (codeStyle.topText || '微信扫码咨询') : '';
      const bottomLine = codeCfg.supportOn ? (codeStyle.bottomText || '官方客服') : '';
      return {
        link: viewLink.value || currentLink.value || 'https://example.com/',
        topText: topLine, bottomText: bottomLine,
        brand: codeStyle.brand, dot: codeStyle.dot, eye: codeStyle.eye, colorDots: !!codeStyle.colorDots,
        knock: 'square', logoRatio: 0.28,
        logo: codeLogoSrc(), logoShape: codeStyle.logo === 'avatar' ? 'circle' : 'round', scale: scale || 2,
      };
    };
    // 码卡片渲染（函数名沿用 renderMainQr：loadLinks / watch 里都在调它）
    const renderMainQr = () => {
      nextTick(() => {
        if (!codeCanvas.value || !window.KFQCard) return;
        const draw = (img) => {
          const o = codeCardOpts(2);
          if (img) o._logoImg = img;
          try { window.KFQCard.drawCodeCard(codeCanvas.value, o); } catch (e) {}
        };
        const src = codeLogoSrc();
        if (!src) return draw(null);
        let im = codeImgCache[src];
        if (im && im.complete) return draw(im);
        im = codeImgCache[src] = new Image();
        im.onload = () => draw(im);
        im.onerror = () => draw(null);
        im.src = src;
      });
      renderPosterQr();
    };
    const renderCodeCard = () => { renderMainQr(); saveCodeStyle(); };
    // 切换/进入客服码页签时也查一次（服务端 5 分钟缓存，不会反复扣费）
    Vue.watch([currentLink, tab], () => { renderMainQr(); if (tab.value === 'links') checkCode(false); });

    const codeExportCanvas = (scale) => {
      const c = document.createElement('canvas');
      const src = codeLogoSrc();
      const im = src ? codeImgCache[src] : null;
      const o = codeCardOpts(scale);
      if (im && im.complete) o._logoImg = im;
      window.KFQCard.drawCodeCard(c, o);
      return c;
    };
    const setCodeStyle = (k, v) => { codeStyle[k] = v; renderCodeCard(); };
    const pickLogo = (k) => {
      if (k === 'upload') { if (logoFile.value) logoFile.value.click(); return; }
      /* v2.84 主色跟着码头像走：微信→微信绿、支付宝→支付宝蓝、QQ→QQ 蓝、淘宝→淘宝橙、抖音→黑，
         并且自动打开「码点同色」（整张码是同一个主色，跟参考图一样） */
      codeStyle.logo = k;
      var bc = LOGO_BRAND[k];
      if (bc) { codeStyle.brand = bc; codeStyle.colorDots = true; saveCodeStyle(); }
      renderCodeCard(); renderMainQr();
      if (CODE_CHANNEL[k]) checkCode(false);
    };
    const onLogoFile = (e) => {
      const f = (e.target.files || [])[0];
      if (!f) return;
      if (f.size > 1024 * 1024) { ElementPlus.ElMessage.warning('图片不要超过 1MB'); e.target.value = ''; return; }
      const rd = new FileReader();
      rd.onload = async () => {
        try {
          const up = await API.post('/api/upload', { data: rd.result, kind: 'image', filename: 'code-logo.png' });
          codeStyle.custom = up.url; codeStyle.logo = 'custom'; renderCodeCard(); checkCode(false);
          ElementPlus.ElMessage.success('Logo 已上传');
        } catch (err) { ElementPlus.ElMessage.error('上传失败，请重试'); }
      };
      rd.readAsDataURL(f);
      e.target.value = '';
    };
    const resetCodeStyle = () => { Object.assign(codeStyle, CODE_STYLE_DEF); renderCodeCard(); };
    const saveCodeStyle = async () => {
      try {
        await API.put('/api/tenant/settings', {
          code_dot: codeStyle.dot, code_eye: codeStyle.eye, code_brand: codeStyle.brand,
          code_logo: codeStyle.logo === 'custom' ? 'upload' : codeStyle.logo, code_logo_size: codeStyle.logoSize,
          code_top_text: codeStyle.topText, code_bottom_text: codeStyle.bottomText,
          code_color_dots: codeStyle.colorDots ? '1' : '0',
          code_custom_logo: codeStyle.logo === 'custom' || codeStyle.logo === 'upload' ? codeStyle.custom : '',
        });
      } catch (e) {}
    };
    // 微信/QQ 拦截状态检测（俗称「红」）：换标、换码、进页面各查一次，服务端 5 分钟缓存
    const checkCode = async (force) => {
      if (codeCheck.loading) return;
      if (!currentLink.value) { codeCheck.state = 'none'; codeCheck.text = '尚未生成'; codeCheck.tag = '未生成'; return; }
      codeCheck.loading = true;
      try {
        const r = (await API.post('/api/tenant/code/check', { force: force ? 1 : 0, channel: CODE_CHANNEL[codeStyle.logo] || 'wx' })) || {};
        codeCheck.state = r.state || 'err';
        codeCheck.text = r.text || '检测失败';
        codeCheck.tag = r.tag || codeCheck.text;
        codeCheck.atText = r.at || '—';
        codeCheck.msg = r.msg || '';
        codeCheck.channel = r.channel || codeCheck.channel;
      } catch (e) {
        codeCheck.state = 'err'; codeCheck.text = '检测失败'; codeCheck.tag = '异常';
      } finally { codeCheck.loading = false; }
    };
    const previewBig = () => {
      viewLink.value = ''; viewCode.value = '';   // 走当前主码
      try { previewSrc.value = codeExportCanvas(2).toDataURL('image/png'); previewDlg.value = true; }
      catch (e) { ElementPlus.ElMessage.error('预览失败'); }
    };
    // v2.60 管理码弹窗里「查看码」：把指定那个码按当前样式渲染出来，可看可下载可复制链接
    const viewQrOf = (row) => {
      if (!row || !row.link) return;
      if (!window.KFQCard) { ElementPlus.ElMessage.warning('二维码组件未就绪，请稍后再试'); return; }
      viewLink.value = row.link; viewCode.value = row.code || '';
      try {
        previewSrc.value = codeExportCanvas(2).toDataURL('image/png');
        previewDlg.value = true;
      } catch (e) { ElementPlus.ElMessage.error('生成预览失败'); }
    };
    const closePreview = () => { previewDlg.value = false; viewLink.value = ''; viewCode.value = ''; };
    const downloadViewed = () => {
      if (!window.KFQCard) return;
      const c = codeExportCanvas(4);
      const a = document.createElement('a');
      a.href = c.toDataURL('image/png');
      a.download = '客服码-' + (viewCode.value || codeValue.value) + '.png';
      document.body.appendChild(a); a.click(); a.remove();
    };
    // 复制「正在查看的那张」二维码图片（对比 copyQrImage：那个固定用当前主码）
    const copyViewedImage = async () => {
      try {
        const c = codeExportCanvas(2);
        const blob = await new Promise(r => c.toBlob(r, 'image/png'));
        await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
        ElementPlus.ElMessage.success('二维码已复制到剪贴板');
      } catch (e) { ElementPlus.ElMessage.warning('复制失败，请用下载按钮'); }
    };
    const downloadQrImage = () => {
      if (!window.KFQCard) return;
      const c = codeExportCanvas(4);
      const a = document.createElement('a');
      a.href = c.toDataURL('image/png'); a.download = '客服码-' + codeValue.value + '.png';
      document.body.appendChild(a); a.click(); a.remove();
    };
    const copyQrImage = async () => {
      try {
        const c = codeExportCanvas(2);
        const blob = await new Promise(r => c.toBlob(r, 'image/png'));
        await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
        ElementPlus.ElMessage.success('二维码已复制到剪贴板');
      } catch (e) { ElementPlus.ElMessage.warning('复制失败，请用下载按钮'); }
    };

    // ===== 推广海报：背景图 + 二维码位置大小 + 上下文字独立定制 =====
    // pos 用九宫值：top-left / top / top-right / left / center / right / bottom-left / bottom / bottom-right
    const POSTER_DEFAULT = {
      style: 'custom',                        // custom（自上传背景自由排版）/ wechat（微信经典）/ ticket（票券卡片）
      bgUrl: '', topText: '', bottomText: '',
      qrPos: 'center', qrScale: 50,          // qrScale：0-100 滑杆 → 导出宽 150-470px
      topPos: 'top', topSize: 36, topColor: '#1a2233', topBold: true,
      botPos: 'bottom', botSize: 30, botColor: '#5c6b85', botBold: false,
      cardOn: true,                           // 二维码底衬白卡
      // 微信经典预设
      wTitle: '微信扫码咨询', wFooter: '官方客服 24 小时在线', badge: '', logoUrl: '',
      // 票券卡片预设
      tName: '', tag: '客服', caption: '扫码联系在线客服',
      subLine: '微信 / 支付宝 / 浏览器 均可识别', steps: '① 扫码 ② 直接开聊',
      note: '本码长期有效 · 请勿转发给无关人员', avatarUrl: '',
    };
    const posterCfg = reactive({ ...POSTER_DEFAULT });
    try {
      const saved = JSON.parse(localStorage.getItem('kfxt_code_poster') || '{}');
      // 存量配置迁移：旧版无 qrPos 等字段，补默认
      Object.assign(posterCfg, saved);
      for (const k of Object.keys(POSTER_DEFAULT)) if (posterCfg[k] === undefined) posterCfg[k] = POSTER_DEFAULT[k];
    } catch (e) {}
    Vue.watch(posterCfg, () => {
      try { localStorage.setItem('kfxt_code_poster', JSON.stringify(posterCfg)); } catch (e) {}
      // 仅在风格切换瞬间填充默认值（避免用户手动清空又被自动补回）
      if (posterCfg.style !== presetLastStyle) { presetLastStyle = posterCfg.style; fillPresetDefaults(); }
      renderPreset();
    }, { deep: true });
    let presetLastStyle = posterCfg.style;
    // ===== 风格预设渲染（wechat / ticket）：canvas 成图，预览即下载内容 =====
    const presetPng = ref('');
    const renderPreset = () => new Promise((res) => {
      if (posterCfg.style === 'custom' || !window.CSQrPoster || !currentLink.value) { presetPng.value = ''; return res(''); }
      const c = document.createElement('canvas');
      try {
        window.CSQrPoster.draw(c, currentLink.value, {
          style: posterCfg.style, correctLevel: 'medium',
          title: posterCfg.wTitle, footer: posterCfg.wFooter, badgeText: posterCfg.badge, logoDataUrl: posterCfg.logoUrl,
          name: posterCfg.tName, tagText: posterCfg.tag, caption: posterCfg.caption,
          subLine: posterCfg.subLine, steps: posterCfg.steps, note: posterCfg.note, avatarDataUrl: posterCfg.avatarUrl,
        }, () => {
          try { presetPng.value = c.toDataURL('image/png'); } catch (e) { presetPng.value = ''; }
          res(presetPng.value);
        });
      } catch (e) { presetPng.value = ''; res(''); }
    });
    const pickPosterPresetImg = (key) => {
      const inp = document.createElement('input');
      inp.type = 'file'; inp.accept = 'image/*';
      inp.onchange = (e) => {
        const f = e.target.files && e.target.files[0];
        if (!f) return;
        if (f.size > 5 * 1024 * 1024) { ElementPlus.ElMessage.warning('图片不能超过 5MB'); return; }
        const rd = new FileReader();
        rd.onload = () => { posterCfg[key] = rd.result; };
        rd.readAsDataURL(f);
      };
      inp.click();
    };
    // 切到预设时填充贴心默认值：角标=当前客服码编号，名称=品牌名，头像=品牌图片头像
    const fillPresetDefaults = () => {
      if (posterCfg.style === 'custom' || !currentLink.value) return;
      if (posterCfg.style === 'wechat' && !posterCfg.badge) {
        const c = (links.value.codes || []).find(x => x.link === currentLink.value);
        if (c && c.code) posterCfg.badge = c.code;
      }
      if (posterCfg.style === 'ticket') {
        if (!posterCfg.tName) posterCfg.tName = String(brand.company_name || (me.value && me.value.nickname) || '官方客服').slice(0, 16);
        if (!posterCfg.avatarUrl && brand.avatar && isImg(brand.avatar)) posterCfg.avatarUrl = brand.avatar;
      }
    };
    Vue.watch([currentLink, tab], () => { fillPresetDefaults(); renderPreset(); });
    const POSTER_POS = [
      { v: 'top-left', l: '左上' }, { v: 'top', l: '上' }, { v: 'top-right', l: '右上' },
      { v: 'left', l: '左' }, { v: 'center', l: '中' }, { v: 'right', l: '右' },
      { v: 'bottom-left', l: '左下' }, { v: 'bottom', l: '下' }, { v: 'bottom-right', l: '右下' },
    ];
    // 区域锚点 → (x 比例, y 比例)；文字用区域中心，二维码用区域左上角再按对齐偏移
    const anchorOf = (pos) => ({
      'top-left': [0.08, 0.08], 'top': [0.5, 0.08], 'top-right': [0.92, 0.08],
      'left': [0.08, 0.5], 'center': [0.5, 0.5], 'right': [0.92, 0.5],
      'bottom-left': [0.08, 0.92], 'bottom': [0.5, 0.92], 'bottom-right': [0.92, 0.92],
    }[pos] || [0.5, 0.5]);
    const posterQrBox = ref(null);
    const posterBgInput = ref(null);
    // 预览层：九宫锚点（百分比定位）+ 缩放（预览舞台宽 280px）
    const qrFreeStyle = Vue.computed(() => {
      const [ax, ay] = anchorOf(posterCfg.qrPos);
      const px = Math.round(56 + (Number(posterCfg.qrScale) || 50) * 1.4);   // 70-196px
      const tx = ax < 0.3 ? `${(ax * 100).toFixed(0)}%` : (ax > 0.7 ? `calc(${(ax * 100).toFixed(0)}% - ${px}px)` : `calc(50% - ${px / 2}px)`);
      const ty = ay < 0.3 ? `${(ay * 100).toFixed(0)}%` : (ay > 0.7 ? `calc(${(ay * 100).toFixed(0)}% - ${px}px)` : `calc(50% - ${px / 2}px)`);
      return { position: 'absolute', left: tx, top: ty, width: px + 'px', height: px + 'px' };
    });
    const qrLayerStyle = Vue.computed(() => {
      const s = qrFreeStyle.value;
      return { position: 'absolute', left: `calc(${s.left} - 8px)`, top: `calc(${s.top} - 8px)`, width: `calc(${s.width} + 16px)`, height: `calc(${s.height} + 16px)` };
    });
    const textStyle = (which) => {
      const pos = which === 'top' ? posterCfg.topPos : posterCfg.botPos;
      const [ax, ay] = anchorOf(pos);
      return {
        position: 'absolute', left: (ax * 100).toFixed(0) + '%', top: (ay * 100).toFixed(0) + '%',
        transform: 'translate(-50%,-50%)', maxWidth: '84%',
        fontSize: Math.max(9, Math.round(((which === 'top' ? posterCfg.topSize : posterCfg.botSize) || 30) / 2.4)) + 'px',
        color: which === 'top' ? (posterCfg.topColor || '#1a2233') : (posterCfg.botColor || '#5c6b85'),
        fontWeight: (which === 'top' ? posterCfg.topBold !== false : posterCfg.botBold === true) ? 700 : 500,
        textShadow: '0 1px 3px rgba(255,255,255,.9)',
        whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis',
      };
    };
    const renderPosterQr = () => {
      nextTick(() => {
        if (!posterQrBox.value) return;
        posterQrBox.value.innerHTML = '';
        if (window.QRCode && currentLink.value) {
          try { new QRCode(posterQrBox.value, { text: currentLink.value, width: 190, height: 190, correctLevel: QRCode.CorrectLevel.M }); } catch (e) {}
        }
      });
    };
    const pickPosterBg = () => { if (posterBgInput.value) posterBgInput.value.click(); };
    const onPosterBg = async (ev) => {
      const file = ev.target.files[0];
      ev.target.value = '';
      if (!file) return;
      if (file.size > 5 * 1024 * 1024) { ElementPlus.ElMessage.warning('背景图不能超过 5MB'); return; }
      const data = await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(file); });
      try {
        const up = await API.post('/api/upload', { data, kind: 'image', filename: file.name });
        posterCfg.bgUrl = up.url;
        ElementPlus.ElMessage.success('背景已更新');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const loadImgOnce = (src) => new Promise((res, rej) => { const im = new Image(); im.onload = () => res(im); im.onerror = rej; im.src = src; });
    const fitText = (ctx, text, maxW, baseSize) => {
      let size = baseSize;
      ctx.font = `700 ${size}px sans-serif`;
      while (size > 16 && ctx.measureText(text).width > maxW) { size -= 2; ctx.font = `700 ${size}px sans-serif`; }
      return size;
    };
    const roundRect = (ctx, x, y, w, h, r) => {
      ctx.beginPath();
      ctx.moveTo(x + r, y);
      ctx.arcTo(x + w, y, x + w, y + h, r);
      ctx.arcTo(x + w, y + h, x, y + h, r);
      ctx.arcTo(x, y + h, x, y, r);
      ctx.arcTo(x, y, x + w, y, r);
      ctx.closePath();
    };
    const downloadPoster2 = async () => {
      if (!currentLink.value) { ElementPlus.ElMessage.warning('尚未生成客服码'); return; }
      // 风格预设：直接下载生成的 PNG
      if (posterCfg.style !== 'custom') {
        const png = presetPng.value || await renderPreset();
        if (!png) { ElementPlus.ElMessage.error('海报生成失败，请重试'); return; }
        const a = document.createElement('a');
        a.href = png; a.download = 'poster_' + posterCfg.style + '_' + Date.now() + '.png';
        document.body.appendChild(a); a.click(); a.remove();
        return;
      }
      try {
        const W = 750;
        const qrSize = Math.round(150 + (Number(posterCfg.qrScale) || 50) * 3.2);   // 150-470px
        let bgImg = null;
        if (posterCfg.bgUrl) { try { bgImg = await loadImgOnce(posterCfg.bgUrl); } catch (e) { bgImg = null; } }
        let H = bgImg ? Math.round(W * bgImg.height / bgImg.width) : 1000;
        const meas = document.createElement('canvas').getContext('2d');
        const topT = (posterCfg.topText || '').trim(), botT = (posterCfg.bottomText || '').trim();
        const topSize = topT ? fitText(meas, topT, W - 100, Number(posterCfg.topSize) || 36) : 0;
        const botSize = botT ? fitText(meas, botT, W - 100, Number(posterCfg.botSize) || 30) : 0;
        const c = document.createElement('canvas');
        c.width = W; c.height = H;
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, W, H);
        if (bgImg) {
          const s = Math.max(W / bgImg.width, H / bgImg.height);
          const dw = bgImg.width * s, dh = bgImg.height * s;
          ctx.drawImage(bgImg, (W - dw) / 2, (H - dh) / 2, dw, dh);
        }
        // 二维码：按九宫锚点定位（x 轴对齐区域内水平位置，y 同理），可选底衬白卡
        const [qx, qy] = anchorOf(posterCfg.qrPos);
        const alignX = qx < 0.3 ? 'left' : (qx > 0.7 ? 'right' : 'center');
        const alignY = qy < 0.3 ? 'top' : (qy > 0.7 ? 'bottom' : 'middle');
        const qrX = alignX === 'left' ? qx * W : (alignX === 'right' ? qx * W - qrSize : qx * W - qrSize / 2);
        const qrY = alignY === 'top' ? qy * H : (alignY === 'bottom' ? qy * H - qrSize : qy * H - qrSize / 2);
        if (posterCfg.cardOn) {
          const cp = 16;
          ctx.fillStyle = 'rgba(255,255,255,.94)';
          roundRect(ctx, qrX - cp, qrY - cp, qrSize + cp * 2, qrSize + cp * 2, 22); ctx.fill();
        }
        let drew = false;
        try {
          const holder = document.createElement('div');
          new QRCode(holder, { text: currentLink.value, width: qrSize, height: qrSize, correctLevel: QRCode.CorrectLevel.M });
          const hi = holder.querySelector('canvas');
          if (hi) { ctx.drawImage(hi, qrX, qrY, qrSize, qrSize); drew = true; }
        } catch (e) {}
        if (!drew) { const qrCv = posterQrBox.value && (posterQrBox.value.querySelector('canvas') || posterQrBox.value.querySelector('img'));
          if (qrCv && qrCv.tagName === 'CANVAS') { ctx.drawImage(qrCv, qrX, qrY, qrSize, qrSize); drew = true; }
          else if (qrCv) { const im = await loadImgOnce(qrCv.src); ctx.drawImage(im, qrX, qrY, qrSize, qrSize); drew = true; }
        }
        if (!drew) { ElementPlus.ElMessage.warning('二维码尚未生成'); return; }
        // 文字：各自九宫定位（锚点即文字中心）
        const drawText = (text, pos, size, color, bold) => {
          const [ax, ay] = anchorOf(pos);
          ctx.fillStyle = color; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
          ctx.font = `${bold ? 700 : 500} ${size}px sans-serif`;
          ctx.fillText(text, ax * W, ay * H);
        };
        if (topT) drawText(topT, posterCfg.topPos, topSize, posterCfg.topColor || '#1a2233', posterCfg.topBold !== false);
        if (botT) drawText(botT, posterCfg.botPos, botSize, posterCfg.botColor || '#5c6b85', posterCfg.botBold === true);
        const a = document.createElement('a');
        a.href = c.toDataURL('image/png'); a.download = '客服码海报-' + Date.now() + '.png';
        document.body.appendChild(a); a.click(); a.remove();
      } catch (e) { ElementPlus.ElMessage.error('海报生成失败：' + (e.message || e)); }
    };
    /* v2.68 入口 ID：二维码链接里的 ?w= 是「入口 ID」（不是客服码），总控在 /api/gate 里
       把它换成本次要用的客服码 + 内容域名 —— 所以：
         ·「新码 / 立即换码」= 原地换码：链接（=顾客手里的二维码）**保持不变**，只是背后换了码；
         ·「换新链接」= 真换一张二维码（旧二维码作废）。 */
    const codeRotateHint = Vue.computed(() => '新增一条客服码？\n\n会生成一条全新的链接（通常换一台域名），方便客户扫新的。\n新码默认 72 小时到期（老码不受影响，仍是永久）—— 快到期可以在「管理码」里点「续 72 小时」，或直接停用旧码。');
    const rotateCode = async () => {
      try {
        await API.post('/api/tenant/service-code', {});
        ElementPlus.ElMessage.success('已新增一条客服码（默认 72 小时到期；旧码继续有效，可在「管理码」里续期或停用）');
        await loadLinks(); renderMainQr(); checkCode(true);
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    /** 真换一张二维码：作废旧链接（顾客需要重新扫码） */
    const rotateLink = async () => {
      try {
        await API.post('/api/tenant/service-code', { new_link: 1 });
        ElementPlus.ElMessage.success('已新增一条客服码（旧码继续有效）——把新链接发给客户即可');
        await loadLinks(); renderMainQr(); checkCode(true);
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
      try { await API.put('/api/tenant/settings', { code_nickname_on: codeCfg.nicknameOn, code_support_on: codeCfg.supportOn }); }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
    };

    /* ===== v2.79 管理码：列出该租户的全部客服码，可单独启用/停用/删除 =====
       旧码默认保留、不失效（换新码只新增一条）；停用等于"这条码改用不了"，随时可恢复。 */
    const manageDlgOpen = ref(false);
    const codeList = ref([]);
    const codeListLoading = ref(false);
    const codeTextDlg = ref(false);
    /* ===== v2.80 登录安全 =====
       ① 新设备第一次登录：弹「确认本次登录信息」，让用户给这台设备起个称呼（存进登录设备备注）
       ② 同账号别处登录：轮询到「新登录通知」就弹提醒（谁/在哪/什么设备/什么时候），5 分钟自动关 */
    const loginInfoDlg = ref(false);
    const loginInfo = ref(null);
    const loginInfoName = ref('');
    const loginHost = ref(location.hostname);
    let loginNoticeTimer = null;
    const checkLoginSecurity = async () => {
      try {
        const r = await API.get('/api/login-notice');
        const dev = r && r.me;
        if (!dev) return;
        loginInfo.value = dev;
        /* v2.86 只在这台设备**第一次**登录时问一次「给这次登录起个称呼」；
           同一个浏览器 / 同一台设备以后登录不再弹（保存过或跳过过都算问过了）。 */
        const seenKey = 'kfxt_loginfo_ok';
        const seenVal = dev.dev_id || '1';
        let asked = false;
        try { asked = localStorage.getItem(seenKey) === seenVal; } catch (e) {}
        if (dev.is_new && !dev.note && !asked && !loginInfoDlg.value) {
          loginInfoName.value = (me.value && me.value.nickname) || '';
          loginInfoDlg.value = true;
        }
        const n = r.notice;
        if (n && n.id && n.dev && n.dev !== dev.dev_id) {
          let shown = '';
          try { shown = localStorage.getItem('kfxt_login_notice') || ''; } catch (e) {}
          if (shown !== n.id) {
            try { localStorage.setItem('kfxt_login_notice', n.id); } catch (e) {}
            ElementPlus.ElNotification({
              title: '⚠️ 新登录通知', type: 'warning', duration: 300000, position: 'top-right',
              dangerouslyUseHTMLString: true,
              message: '<div style="line-height:1.95;font-size:13px;">'
                + '登录身份：<b>' + (n.note || dev.note || '同账号') + '</b><br>'
                /* v2.90.20 这里原来直接吐后端的原始 geo 串（中国|广东省|深圳市|移动|CN），
                   现在后端给的是已经整理好的中文地区（广东 深圳 移动）。 */
                + '登录信息：<b>' + (n.ip || '') + '</b>　' + (n.geo_text || n.geo || '') + '<br>'
                + '登录来源：<b>' + (n.device || '') + '</b><br>'
                + '<span style="color:#86909c;font-size:12px;">' + (n.at || '') + '　5 分钟后自动关闭</span></div>'
            });
          }
        }
      } catch (e) { /* 静默：登录安全接口失败不影响使用 */ }
    };
    /* v2.86 记住了就不再问：同一台设备（同一个浏览器）只问一次 */
    const markLoginInfoAsked = () => {
      try { localStorage.setItem('kfxt_loginfo_ok', (loginInfo.value && loginInfo.value.dev_id) || '1'); } catch (e) {}
    };
    const saveLoginInfoName = async () => {
      try {
        if (loginInfo.value && loginInfoName.value.trim() !== '') {
          await API.post('/api/login-devices/note', { id: loginInfo.value.id, note: loginInfoName.value.trim() });
          ElementPlus.ElMessage.success('已保存，可在「设置 → 安全与维护 → 登录设备」里改');
        }
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
      markLoginInfoAsked();
      loginInfoDlg.value = false;
    };
    const skipLoginInfo = () => {
      markLoginInfoAsked();
      loginInfoDlg.value = false;
    };
    const loadCodes = async () => {
      codeListLoading.value = true;
      try { codeList.value = await API.get('/api/tenant/service-code'); }
      catch (e) { ElementPlus.ElMessage.error(e.message || '加载失败'); }
      finally { codeListLoading.value = false; }
    };
    const openManageCodes = async () => { manageDlgOpen.value = true; await loadCodes(); };
    const toggleCode = async (row, val) => {
      try {
        await API.post('/api/tenant/service-code/toggle', { code: row.code, enabled: !!val });
        row.enabled = !!val;
        ElementPlus.ElMessage.success(val ? '已启用' : '已停用（这条码立刻打不开，随时能再启用）');
        await loadLinks();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const delCodeRow = async (row) => {
      try {
        await API.del('/api/tenant/service-code?code=' + encodeURIComponent(row.code));
        ElementPlus.ElMessage.success('已删除');
        await loadCodes(); await loadLinks();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    /* v2.90.97 续期：新码默认 72 小时到期，快到期/已到期时点一下重新计时（默认从现在起算 +72 小时） */
    const renewCodeRow = async (row) => {
      try {
        const r = await API.put('/api/tenant/service-code', { code: row.code, hours: 72, now: 1 });
        ElementPlus.ElMessage.success('已续期到 ' + String(r.expires_at || '').slice(0, 16).replace('T', ' '));
        await loadCodes(); await loadLinks();
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };

    // 顶栏时钟 + 未读总数
    const clock = ref('');
    const tickClock = () => { clock.value = new Date().toLocaleString('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' }); };
    tickClock(); setInterval(tickClock, 1000);
    /* v2.90.52 归档会话不算「消息提醒」：未读合计只统计没归档的（归档的就当静音了，
       侧栏角标、窗口标题闪动、提示音、桌面通知都不再被它触发）。 */
    const unreadTotal = Vue.computed(() => sessions.value.reduce(
      (a, s) => a + (s.status === 'archived' ? 0 : (s.unread || 0)), 0));
    // 标题栏提示（页面在后台时最直观）：有未读显示 "(3) 小米客服"
    const BASE_TITLE = '小米客服';
    const lastTitle = ref('');
    const flashTitle = (n) => {
      const t = n > 0 ? `(${n > 99 ? '99+' : n}) ${BASE_TITLE}` : BASE_TITLE;
      if (t !== lastTitle.value) { lastTitle.value = t; try { document.title = t; } catch (e) {} }
    };
    // 桌面通知（需浏览器授权；在「个人设置」里开启）
    const notifyOn = ref(false);
    const notifySupported = typeof window !== 'undefined' && 'Notification' in window;
    try { notifyOn.value = localStorage.getItem('kfxt_notify') === '1' && notifySupported && Notification.permission === 'granted'; } catch (e) {}
    const enableNotify = async () => {
      if (!notifySupported) { ElementPlus.ElMessage.warning('当前浏览器不支持桌面通知'); notifyOn.value = false; return; }
      if (notifyOn.value) {
        if (Notification.permission !== 'granted') {
          try { const p = await Notification.requestPermission(); notifyOn.value = (p === 'granted'); } catch (e) { notifyOn.value = false; }
        }
      }
      if (notifyOn.value && Notification.permission !== 'granted') notifyOn.value = false;
      try { localStorage.setItem('kfxt_notify', notifyOn.value ? '1' : '0'); } catch (e) {}
      ElementPlus.ElMessage.success(notifyOn.value ? '已开启桌面通知' : '已关闭桌面通知');
    };
    const desktopNotify = (inMsgs, unreadNow) => {
      if (!notifyOn.value || !notifySupported || Notification.permission !== 'granted') return;
      try {
        const s = sessions.value.filter(x => x.unread > 0)
          .sort((a, b) => new Date(b.lastTime || 0) - new Date(a.lastTime || 0))[0];
        const title = inMsgs.length && !s ? '收到新消息' : `新消息 · ${(s && s.name) || '访客'}`;
        const body = inMsgs.length ? (inMsgs[inMsgs.length - 1].text || '[图片/语音]').slice(0, 60)
          : ((s && s.lastMsg) || '有新的访客咨询').slice(0, 60);
        const n = new Notification(title, { body, tag: 'kfxt-msg', renotify: true });
        n.onclick = () => { try { window.focus(); n.close(); } catch (e) {} };
      } catch (e) {}
    };
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
    // 访客标签（v2.42）：渠道优先 —— 「微信 · 广东揭阳」；没有地区时退化成渠道/设备
    const visitorTag = (s) => {
      if (!s) return '';
      const chn = s.channel || '';
      const geo = s.region || s.city || geoTag(s.geo) || '';
      if (chn && geo) return chn + ' · ' + geo;
      if (chn) return chn;
      if (geo) return geo;
      return s.devTag || '';
    };
    // 渠道配色：微信绿 / QQ 蓝 / 支付宝蓝 / 抖音红，其余中性
    const CHN_CLASS = { '微信': 'chn-wx', 'QQ': 'chn-qq', '支付宝': 'chn-ali', '抖音': 'chn-dy', '钉钉': 'chn-dt' };
    const visitorChnClass = (s) => (s && CHN_CLASS[s.channel]) || 'chn-other';
    const visitorTagFull = (s) => {
      if (!s) return '';
      const bits = [];
      if (s.channel) bits.push('渠道：' + s.channel);
      if (s.region || s.city) bits.push(s.region || s.city);
      if (s.devTag) bits.push(s.devTag);
      if (s.browser && !bits.includes(s.browser)) bits.push(s.browser);
      const g = geoTag(s.geo);
      if (g) bits.push(g);
      if (s.ip) bits.push(s.ip);
      return bits.join(' · ') || '';
    };

    // 深色主题
    const dark = ref(false);
    // v2.45 「收起」= 只留在线聊天，把「已归档 / 已结束」两个页签收起来（列表本身一直都在）
    const sessCollapsed = ref(false);
    const toggleSessTabs = () => {
      sessCollapsed.value = !sessCollapsed.value;
      if (sessCollapsed.value && sessScope.value !== 'online') sessScope.value = 'online';
    };
    /** v2.60.11 切页签立刻刷新（原来要等下一次轮询，切到「已删除」会先闪一下上一页的旧列表） */
    const setScope = (s) => { sessScope.value = s; try { pollOnce(); } catch (e) {} };
    /* v2.89.5 工作台布局自定义：左侧栏收起 / 会话列表拖宽 / 访客信息收起，都记在本地 */
    const sideCollapsed = ref((function () { try { return localStorage.getItem('kfxt_side_collapsed') === '1'; } catch (e) { return false; } })());
    const sessW = ref((function () {
      try { return Math.min(560, Math.max(200, parseInt(localStorage.getItem('kfxt_sess_w') || '326', 10) || 326)); }
      catch (e) { return 326; }
    })());
    /* v2.90.24 会话列窄了以后，页签文字自动收短，别让「在线聊天」被挤成竖排（之前 190px 时会一个字一行） */
    const sessTabText = computed(() => {
      const narrow = sessW.value < 320;
      return { online: narrow ? '在线' : '在线聊天', archived: narrow ? '归档' : '已归档', closed: narrow ? '结束' : '已结束' };
    });
    const sessResizing = ref(false);
    const infoCollapsed = ref((function () { try { return localStorage.getItem('kfxt_info_collapsed') === '1'; } catch (e) { return false; } })());
    let sessDragFrom = null;
    /* v2.90.33 会话列能拖多宽，要留够聊天区的保底宽度（不然聊天区被压成几十像素，按钮盖到访客信息上） */
    const sessMaxW = () => {
      const sideW = (document.querySelector('.side') || {}).offsetWidth || 190;
      const infoW = infoCollapsed.value ? 0 : ((document.querySelector('.info-col') || {}).offsetWidth || 300);
      const room = window.innerWidth - sideW - infoW - 360;
      return Math.max(240, Math.min(560, room));   // 会话列也不低于 240（再窄就看不清名字了）
    };
    const clampSessW = () => { const m = sessMaxW(); if (sessW.value > m) sessW.value = m; };
    window.addEventListener('resize', () => { try { clampSessW(); } catch (e) {} });
    const sessResizeStart = (e) => {
      sessResizing.value = true;
      document.body.classList.add('resizing-col');
      sessDragFrom = { x: e.clientX, w: sessW.value };
      const maxW = sessMaxW();
      const mv = (ev) => {
        if (!sessDragFrom) return;
        sessW.value = Math.min(maxW, Math.max(200, sessDragFrom.w + (ev.clientX - sessDragFrom.x)));
      };
      const up = () => {
        sessDragFrom = null;
        sessResizing.value = false;
        document.body.classList.remove('resizing-col');
        try { localStorage.setItem('kfxt_sess_w', String(sessW.value)); } catch (e2) {}
        document.removeEventListener('mousemove', mv);
        document.removeEventListener('mouseup', up);
      };
      document.addEventListener('mousemove', mv);
      document.addEventListener('mouseup', up);
    };
    const sessResetW = () => {
      sessW.value = 326;
      try { localStorage.setItem('kfxt_sess_w', '326'); } catch (e) {}
    };
    const toggleSide = () => {
      sideCollapsed.value = !sideCollapsed.value;
      try { localStorage.setItem('kfxt_side_collapsed', sideCollapsed.value ? '1' : '0'); } catch (e) {}
    };
    const toggleInfo = () => {
      /* 手机端是右侧抽屉，电脑端是"显示/隐藏这一列" */
      if (document.documentElement.getAttribute('data-ui-cat') === 'mobile') { infoOpen.value = !infoOpen.value; return; }
      infoCollapsed.value = !infoCollapsed.value;
      try { localStorage.setItem('kfxt_info_collapsed', infoCollapsed.value ? '1' : '0'); } catch (e) {}
      // v2.90.33 展开访客信息后位置不够时，自动把会话列收窄一点（会话列/聊天区/访客信息三栏都保住）
      setTimeout(() => { try { clampSessW(); } catch (e) {} }, 60);
    };
    try { dark.value = localStorage.getItem('kfxt_dark') === '1'; } catch (e) {}
    const applyDark = () => { document.body.classList.toggle('dark', dark.value); };
    const toggleDark = () => { dark.value = !dark.value; try { localStorage.setItem('kfxt_dark', dark.value ? '1' : '0'); } catch (e) {} applyDark(); };
    applyDark();
    // 手机端后台：折叠交互无意义，强制展开形态
    const isMobileUi = document.documentElement.getAttribute('data-ui-cat') === 'mobile';
    if (isMobileUi) { sessCollapsed.value = false; sideCollapsed.value = false; }
    // 手机端抽屉导航
    const mSideOpen = ref(false);
    Vue.watch(() => tab.value, () => { mSideOpen.value = false; });

    // CRUD 字段配置
    const quickFields = [
      { k: 'title', label: '标题' },
      { k: 'content', label: '文字内容', type: 'textarea' },
      { k: 'attachments', label: '附件', type: 'images', max: 3, tip: '点击按钮直接发送文字 + 附件：图片 / 视频 / 文件都行，最多 3 个' },
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

    // 浮层关闭：挂在 document 上（模板根是 #app 的 innerHTML，#app 上的 @click 根本绑不上）
    // 打开菜单的地方都做了 stopPropagation，所以这里只要无脑全关就不会误伤刚打开的菜单。
    /* v2.90.49 消息里的数字：蓝色 + 点一下复制（跟微信一样）。用事件委托，不用给每条消息挂 handler。 */
    const onDocClick = (ev) => {
      const t = ev && ev.target;
      if (t && t.classList && t.classList.contains('num')) {
        const v = t.getAttribute('data-copy') || t.textContent || '';
        copyPlain(v);
        return;
      }
      closeAllMenus();
    };
    const copyPlain = (v) => {
      const done = () => { try { ElementPlus.ElMessage.success('已复制 ' + v); } catch (e) {} };
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(v).then(done, fb); return; }
      } catch (e) {}
      fb();
      function fb() {
        try {
          const ta = document.createElement('textarea');
          ta.value = v; ta.style.position = 'fixed'; ta.style.opacity = '0';
          document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
          done();
        } catch (e) { try { ElementPlus.ElMessage.error('复制失败，请手动选择'); } catch (e2) {} }
      }
    };
    const escHtml = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const NUM_RE = /(?<!\d)(?:\d{4,}|(?:\d{2,4}[-\s]){1,}\d{2,4})(?!\d)/g;
    /** 把消息文字里的数字包成可点的蓝色片段（先转义再包，防注入） */
    const richText = (text) => {
      const s = escHtml(text);
      if (!/\d/.test(s)) return s;
      return s.replace(NUM_RE, (tok) => {
        const d = (tok.match(/\d/g) || []).length;
        if (d < 4) return tok;
        if (/[-\s]/.test(tok) && d < 8) return tok;   // 10-09 / 1 2 3 这类不算
        return '<span class="num" data-copy="' + tok.replace(/"/g, '&quot;') + '">' + tok + '</span>';
      });
    };
    const onDocCtx = (ev) => { if (ev && ev.target && ev.target.closest && ev.target.closest('.ctx-menu')) return; closeAllMenus(); };
    /* ===== v2.86 手机端返回 =====
       以前进了会话，只能点顶栏那个小「‹」才回得去；手机返回键 / 返回手势
       会直接退出工作台。现在进会话时压一条历史记录，返回键先回会话列表。 */
    const backToSessList = () => {
      const hadChat = !!currentId.value;
      if (hadChat) currentId.value = null;
      /* 压过历史记录的话顺手回退一格，免得下次按返回直接退出工作台 */
      try { if (history.state && history.state.kfChat) history.back(); } catch (e) {}
    };
    const onPopState = () => { if (currentId.value) currentId.value = null; };
    onMounted(async () => {
      document.addEventListener('click', onDocClick);
      setTimeout(() => { try { clampSessW(); } catch (e) {} }, 1500);   // v2.90.33 窗口小的时候别让上次存的宽度把聊天区挤没
      document.addEventListener('contextmenu', onDocCtx);
      window.addEventListener('keydown', imgKey);   // v2.69 图片查看器：← → 翻页 / Esc 关闭
      window.addEventListener('popstate', onPopState);
      try {
        await loadMe();
        if (me.value.role === 'admin') { location.href = '/admin.html'; return; }
        /* v2.89.11 登录安全检查（新设备弹「确认本次登录信息」）放到最前面：
           一登录马上弹，不用等工作台下面的会话/数据/快捷消息全部加载完（以前要等好几秒）。 */
        checkLoginSecurity();
        if (loginNoticeTimer) clearInterval(loginNoticeTimer);
        loginNoticeTimer = setInterval(checkLoginSecurity, 30000);
        await Promise.all([pollOnce(), loadQuick(), loadWelcome(), loadAuto(), loadStats()]);
        if (me.value.role === 'tenant') {
          await Promise.all([loadLinks(), loadAgents(), loadBrand(), loadBlacklist(), loadPreInq(), loadAgentOptions()]);
        }
        booted.value = true;
        startPoll();
        startExpireWatch();   // v2.57 到期提醒（5 分钟提示 / 2 分钟弹窗）
        startIdleWatch();     // v2.76 1 小时不操作自动退出登录
        checkCodeAlerts();    // v2.64 红码提醒（服务端每小时巡检）
        alertTimer = setInterval(checkCodeAlerts, 10 * 60 * 1000);
      } catch (e) {
        // 401 时 api.js 已跳转登录页；此处保持加载态不渲染骨架
        if (!String(e.message).includes('登录已失效')) ElementPlus.ElMessage.error(e.message);
      }
    });
    onUnmounted(() => {
      stopPoll();
      if (expireTimer) clearInterval(expireTimer);
      if (alertTimer) clearInterval(alertTimer);
      document.removeEventListener('click', onDocClick);
      document.removeEventListener('contextmenu', onDocCtx);
      window.removeEventListener('popstate', onPopState);
    });

    return {
      tab, tabTitle, booted, me, expireText, stats, sessions, sessSearch, filteredSessions, currentId, current,
      navTo, navRefresh, meExpired, fmtDay, backToSessList,
      msgs, draft, sending, chatBody, viewing, profile, contactList, noteDraft, transferDlg, transferTo, agentOptions,
      pinBottom, onMediaLoad,
      chatAtBottom, onChatScroll, toBottom,
      quickMsgs, welcomeMsgs, autoReplies, blacklist, preInq, preqOn, links, agents, brand,
      bcText, bcSending, profileForm, pwOld, pwNew, recording, recSecs, qrVisible, qrData, qrBox,
      posterUrl, posterOpts, downloadPoster, pickPosterLogo, onQrOpen, downloadQrBox,
      waNumber, tgUser, waQrBox, tgQrBox, genWaQr, genTgQr,
      uploadAvatar, isImg,
      sec, loginLogs, visitors, cleanupOpts, loadSecurityPage, saveSecurity, doCleanup, refreshGeo, loadVisitors, openVisitorSessions,
      guide, guideDone, dark, toggleDark, renameVisitor, nameEditing, nameDraft, nameScope, startRename, commitRename, cancelRename, renderCodeQrs, tplList,
      uiCatList, uiCurrentCat, uiActiveCat, uiActiveList, uiSel, uiTplList, uiColorList, uiPick, sideCollapsed, mSideOpen,
      sessW, sessTabText, sessResizing, sessResizeStart, sessResetW, toggleSide, infoCollapsed, toggleInfo,
      blockSession, blockIp, kwList, kwInput, kwSaving, addKw, removeKw, saveFilter, agentCmd, restartPoll,
 aiCfg, aiSaving, saveAi, genAiSecret,
      devices, deviceLimit, loadDevices, kickDevice, renameDevice, isCurDevice,
      chatSearchOn, chatSearchKw, searchHit, visibleMsgs, canRecall, recallMsg, isRead,
      pol, savePolicy, uploadPolHead, clock, unreadTotal, sessCollapsed,
      toggleSessTabs, setScope,
      sessScope, sessCounts, archiveSession,
      sessMulti, sessChecked, toggleMulti, toggleSessCheck, checkAllSess, bulkSessions,
      ctxMenu, openCtxMenu, openCtxMenuFromBtn, closeCtxMenu, ctxMenuArchive, ctxMenuClose, ctxMenuDelete,
      ctxMenuPin, ctxMenuUnread, ctxMenuBlock,
      ctxMenuNote, ctxMenuMerge, mergeAllByIp,
      ctxMenuRename, imgsOf,
      qeImgs, quickFocus, quickImgInput, quickPickImg, quickOnImg, quickPaste, quickRmAtt,
      replyTo, quoteOf, quoteWho, quoteText, startReply, jumpToMsg, copyMsg, flashId, fmtSize, mapUrl,
      infoOpen,
      msgMenu, openMsgMenu, closeMsgMenu, closeAllMenus, deleteMsg,
      canEdit, editMsg,
      msgSheet, openMsgSheet, onMsgTouchStart, onMsgTouchMove, onMsgTouchEnd,
      chMore, chMoreAction,
      imgOnly, toggleImgOnly, imgCount, dateOpen, dateList, jumpDate,
      restoreSession, purgeSession, unblockIp, currentIpBlocked, unblockCurrent,
      resumeFindOpen, resumeFindCode, resumeFindErr, resumeFindBusy, findByResume,
      selMode, selIds, enterSel, exitSel, toggleSel, selAll, selCopy, selDelete,
      onPaste, sendFile, sendLocation,
      SOUND_OPTS, sndCfg, playSound, saveSnd,
      notifyOn, notifySupported, enableNotify,
      voiceHold, startHoldVoice, moveHoldVoice, endHoldVoice,
      manageDlg, history, codeCfg, qrWrap, currentLink, renderMainQr,
      codeCanvas, logoFile, previewDlg, previewSrc, codeValue, codeStyle, codeCheck,
      checkChannel, checkChannelLabel, checkLogoName, checkUseDefaultChannel,
      CODE_LOGOS, CODE_BRANDS, pickLogo, setCodeStyle, saveCodeStyle, renderCodeCard, resetCodeStyle, onLogoFile,
      logoThumb, previewBig, checkCode,
      posterCfg, posterQrBox, posterBgInput, pickPosterBg, onPosterBg, downloadPoster2,
      presetPng, pickPosterPresetImg,
      POSTER_POS, qrFreeStyle, qrLayerStyle, textStyle,
      cropDlg, cropSrc, cropZoomPct, cropStage, cropStyle, cropImgLoaded, cropDown, cropMove, cropUp, cropWheel, cropClose, openCrop, cropConfirm, avatarPreview,
      geoTag, visitorTag, visitorTagFull, visitorChnClass, copyQrImage, downloadQrImage, codeRotateHint, rotateCode, rotateLink, loadHistory, disableLink, saveCodeCfg, meAvatarUrl,
      manageDlgOpen, codeList, codeListLoading, openManageCodes, toggleCode, delCodeRow, renewCodeRow,
      loginInfoDlg, loginInfo, loginInfoName, loginHost, saveLoginInfoName, skipLoginInfo, markLoginInfoAsked,
      codeTextDlg,
      codeCreatedAt, codeExpiresAt,
      quickFields, welcomeFields, autoFields, blFields, preqFields,
      shortTime, fmtTime, sourceText, contactLabel, barW, quotaW, trendW, trendDesc, dailyDesc, quotaWarn,
      sessTime,
      sessIcon, sessPreview,
      setTab, setGroups, openSetting, switchSetting,
      navGroups,
      audioPlaying, audioDur, waveBars, toggleAudio,
      asrText, asrBusy, transcribe,
      openSession, send, onKey, useQuick, sendQuick, quickClick, quickPanelClick, quickFill, quickMode, setQuickMode, retrySend,
      quickGroups, curGroup, visibleQuick, multiGroup, pickGroup,
      appVersion,
      quickPanelKw, quickPanelList, qkHi, infoTab,
      quickBarOn, quickBarOpen, saveQuickBar, toggleQuickBar,
      richText,
      sameIpOthers, mergeSameIpNow,
      onImage, startRec, stopRec, closeSession, doTransfer,
      autoGrowTa,
      taH, taResizing, taResizeStart, taResetH,
      taPlaceholder,
      pendingAtts, rmPendingAtt, quickCtx, openQuickCtx, editQuick,
      qDrag, quickDragDown, quickDragMove, quickDragUp, quickMove,
      imgZoom, imgRot, imgWheel, downloadImage, copyImage, imgToast,
      viewImages, viewIdx, imgGo,
      editContact, saveContact, contactDlg, editingContact, saveNote, sendBroadcast,
      copy, createLink, renewLink, newCode, delCode, showQr,
      viewQrOf, viewLink, viewCode, closePreview, downloadViewed, copyViewedImage,
      customCode, customSaving, newCodeCustom, rowChecking, rowCheck, checkRowCode,
      codeTableRef, codeSel, onCodeSel, selAllCodes, clearCodeSel, selRedCodes,
      batchChecking, batchDone, batchTotal, batchCheck, batchDeleteCodes,
      agentDlg, agentMode, agentForm, openAgentEdit, saveAgent, resetAgentPw, toggleAgent, delAgent,
      createdAgentVisible, createdAgent,
      saveBrand, savePreqOn, saveProfile, changePw, exportCsv, logout,
      loadQuick, saveQuickOrder, loadWelcome, loadAuto, loadBlacklist, loadPreInq,
      refreshing, refreshChat, reloadPending, loadingMsgs, askUpload,
      askDlg, askForm, ASK_PRESETS, ASK_DEFAULT, openAskDialog, sendAskDialog,
      qa, quickAdd,
      quickDlg, qe, openQuickDlg, quickSave, quickDel, quickSendNow,
    };
  }
});
app.component('crud-table', CrudTable);
app.use(ElementPlus).mount('#app');

