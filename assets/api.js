/* api.js - 前端 API 客户端 */
(function () {
  const API = {
    token: null,
    base: (typeof window !== 'undefined' && window.KF_API_BASE) || '',   // 分控静态节点：总控地址（主站同源部署为空）
    setToken(t) { this.token = t; try { localStorage.setItem('kfxt_token', t); } catch (e) {} },
    loadToken() { try { this.token = localStorage.getItem('kfxt_token'); } catch (e) {} return this.token; },
    clearToken() { this.token = null; try { localStorage.removeItem('kfxt_token'); } catch (e) {} },
    /* 登录页跳转时用 sessionStorage 交接一次 token（取完立刻删），兼容老流程。
       v2.85 起登录状态长期保存在 localStorage：刷新 / 关闭再打开都不用重新登录，
       只有「1 小时不操作」（工作台 startIdleWatch）或账号到期 / 停用 / 被踢才退出登录。 */
    handoff(t) { try { sessionStorage.setItem('kfxt_handoff', t); } catch (e) {} },

    async request(method, path, data) {
      const opts = { method, headers: {} };
      if (data !== undefined && data !== null) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(data);
      }
      if (this.token) opts.headers['Authorization'] = 'Bearer ' + this.token;
      let res;
      try {
        res = await fetch(this.base + path, opts);
      } catch (e) {
        throw new Error('网络异常，请稍后重试');
      }
      /* v2.60.16 发版瞬间的 500（PHP 文件正在被替换，可能读到半截）：
         GET 自动重试一次，用户基本感觉不到；写操作不重试，避免重复提交。 */
      if (res.status >= 500 && (method === 'GET' || method === 'get')) {
        await new Promise(function (r) { setTimeout(r, 700); });
        try {
          res = await fetch(this.base + path, opts);
        } catch (e) {
          throw new Error('网络异常，请稍后重试');
        }
      }
      if (res.status === 401) {
        this.clearToken();
        if (!location.pathname.startsWith('/visitor')) {
          const isLogin = location.pathname.includes('login');
          if (!isLogin) { location.href = '/login.html'; throw new Error('登录已失效'); }
        }
      }
      /* v2.75 账号状态类 403（已到期 / 已停用 / 被踢下线）也要强制退出登录并说明原因，
         否则用户会停在页面里，接口一直报错却不知道发生了什么。 */
      if (res.status === 403) {
        let jj = null;
        try { jj = JSON.parse(await res.clone().text()); } catch (e) {}
        const reason = (jj && jj.code) || '';
        if (reason === 'expired' || reason === 'disabled' || reason === 'kicked') {
          this.clearToken();
          if (!location.pathname.startsWith('/visitor') && !location.pathname.includes('login')) {
            try { sessionStorage.setItem('kfxt_kick', (jj && jj.error) || '账号状态异常，请重新登录'); } catch (e) {}
            location.href = '/login.html';
            throw new Error((jj && jj.error) || '账号状态异常');
          }
        }
      }
      const ct = res.headers.get('Content-Type') || '';
      if (ct.includes('text/csv') || ct.includes('octet-stream')) return res;
      let j;
      try {
        const txt = await res.text();
        try { j = JSON.parse(txt); }                       // 正常路径：对象/数组均可
        catch (e2) {
          const s = txt.indexOf('{');                      // 兜底：剥离可能混入的警告前缀（仅对象响应）
          j = JSON.parse(s >= 0 ? txt.slice(s) : txt);
        }
      } catch (e) { throw new Error('响应解析失败(' + res.status + ')'); }
      if (!res.ok) throw new Error(j && j.error ? j.error : '请求失败(' + res.status + ')');
      return j;
    },
    get(path) { return this.request('GET', path); },
    post(path, data) { return this.request('POST', path, data || {}); },
    put(path, data) { return this.request('PUT', path, data || {}); },
    del(path) { return this.request('DELETE', path); },

    // 登录
    /* v2.60.10 登录设备：本地生成一个稳定的设备号（清缓存/换浏览器会变成新设备），
       同一卡密默认只允许 3 台设备在线，防盗用。 */
    deviceId() {
      const KEY = 'kfxt_dev';
      try {
        let d = localStorage.getItem(KEY);
        if (!d) {
          const rnd = (self.crypto && crypto.randomUUID)
            ? crypto.randomUUID().replace(/-/g, '')
            : (Date.now().toString(36) + Math.random().toString(36).slice(2));
          d = rnd.slice(0, 24);
          localStorage.setItem(KEY, d);
        }
        return d;
      } catch (e) { return ''; }
    },
    login(account, password) { return this.post('/api/auth/login', { account, password, dev: this.deviceId() }); },
    me() { return this.get('/api/auth/me'); },
    changePassword(oldP, newP) { return this.put('/api/auth/password', { old_password: oldP, new_password: newP }); },
    updateProfile(data) { return this.put('/api/auth/profile', data); },
  };
  window.API = API;
  /* v2.85 登录状态恢复：直接读 localStorage —— 刷新 / 关闭再打开都保持登录。
     只有「1 小时不操作」（工作台里的 startIdleWatch）或账号到期/停用/被踢，才会退出登录。 */
  try {
    const h = sessionStorage.getItem('kfxt_handoff');   // 兼容：登录跳转时的一次性交接
    if (h) { sessionStorage.removeItem('kfxt_handoff'); API.setToken(h); }
    else API.loadToken();
  } catch (e) { /* 出错按未登录处理 */ }
})();
