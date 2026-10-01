/* api.js - 前端 API 客户端 */
(function () {
  const API = {
    token: null,
    setToken(t) { this.token = t; try { localStorage.setItem('kfxt_token', t); } catch (e) {} },
    loadToken() { try { this.token = localStorage.getItem('kfxt_token'); } catch (e) {} return this.token; },
    clearToken() { this.token = null; try { localStorage.removeItem('kfxt_token'); } catch (e) {} },

    async request(method, path, data) {
      const opts = { method, headers: {} };
      if (data !== undefined && data !== null) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(data);
      }
      if (this.token) opts.headers['Authorization'] = 'Bearer ' + this.token;
      let res;
      try {
        res = await fetch(path, opts);
      } catch (e) {
        throw new Error('网络异常，请稍后重试');
      }
      if (res.status === 401) {
        this.clearToken();
        if (!location.pathname.startsWith('/visitor')) {
          const isLogin = location.pathname.includes('login');
          if (!isLogin) { location.href = '/login.html'; throw new Error('登录已失效'); }
        }
      }
      const ct = res.headers.get('Content-Type') || '';
      if (ct.includes('text/csv') || ct.includes('octet-stream')) return res;
      let j;
      try { j = await res.json(); } catch (e) { throw new Error('响应解析失败(' + res.status + ')'); }
      if (!res.ok) throw new Error(j && j.error ? j.error : '请求失败(' + res.status + ')');
      return j;
    },
    get(path) { return this.request('GET', path); },
    post(path, data) { return this.request('POST', path, data || {}); },
    put(path, data) { return this.request('PUT', path, data || {}); },
    del(path) { return this.request('DELETE', path); },

    // 登录
    login(account, password) { return this.post('/api/auth/login', { account, password }); },
    me() { return this.get('/api/auth/me'); },
    changePassword(oldP, newP) { return this.put('/api/auth/password', { old_password: oldP, new_password: newP }); },
    updateProfile(data) { return this.put('/api/auth/profile', data); },
  };
  window.API = API;
  API.loadToken();
})();
