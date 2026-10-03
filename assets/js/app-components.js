// app-components.js - 工作台共享组件（Vue 解构 + CrudTable；由 public/app.html 内联脚本提取）
const { createApp, ref, reactive, computed, onMounted, onUnmounted, nextTick, defineComponent, h } = Vue;

/* ---------- 通用 CRUD 表格组件 ---------- */
const CrudTable = defineComponent({
  props: { rows: Array, cols: Array, fields: Array, title: String, addText: String, api: String, io: Boolean },
  emits: ['reload'],
  template: `
  <div class="panel">
    <div class="panel-title" style="justify-content:flex-start;gap:12px;">
      {{ title }}（{{ rows.length }}）
      <el-button size="small" type="primary" @click="open()">{{ addText }}</el-button>
      <span v-if="io" style="display:inline-flex;gap:6px;align-items:center;margin-left:auto;flex-wrap:wrap;">
        <span class="muted" style="font-size:12px;">批量：</span>
        <el-button size="small" :disabled="!rows.length" @click="expJson">导出 JSON</el-button>
        <el-button size="small" :disabled="!rows.length" @click="expCsv">导出 CSV</el-button>
        <el-button size="small" type="primary" plain @click="pickImport">从文件导入</el-button>
        <input ref="impRef" type="file" accept=".json,.csv,.txt" style="display:none" @change="doImport">
      </span>
    </div>
    <el-table :data="rows" stripe size="default">
      <el-table-column v-for="c in cols" :key="c.k" :label="c.l">
        <template #default="{row}">
          <template v-if="c.bool"><el-tag size="small" :type="row[c.k]?'success':'info'">{{ row[c.k]?'启用':'停用' }}</el-tag></template>
          <template v-else-if="c.imgs">
            <template v-if="(row[c.k]||[]).length">
              <img v-for="(im, ii) in row[c.k].slice(0,3)" :key="ii" :src="im.url" style="width:26px;height:26px;object-fit:cover;border-radius:5px;margin-right:4px;vertical-align:middle;border:1px solid #e5e6eb;">
              <span v-if="row[c.k].length > 3" class="muted" style="font-size:11px;">+{{ row[c.k].length - 3 }}</span>
            </template>
            <span class="muted">—</span>
          </template>
          <template v-else>{{ c.map ? (c.map[row[c.k]] || row[c.k]) : row[c.k] }}</template>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="130">
        <template #default="{row}">
          <el-button size="small" @click="open(row)">编辑</el-button>
          <el-popconfirm title="确认删除？" @confirm="del(row)">
            <template #reference><el-button size="small" type="danger">删除</el-button></template>
          </el-popconfirm>
        </template>
      </el-table-column>
    </el-table>
    <el-dialog v-model="dlg" :title="editId ? '编辑' : '新增'" width="440px">
      <el-form label-width="90px">
        <el-form-item v-for="f in fields" :key="f.k" :label="f.label">
          <el-switch v-if="f.type==='bool'" v-model="form[f.k]"/>
          <el-select v-else-if="f.type==='select'" v-model="form[f.k]">
            <el-option v-for="o in f.options" :key="o.v" :label="o.l" :value="o.v"/>
          </el-select>
          <el-input-number v-else-if="f.type==='number'" v-model="form[f.k]"/>
          <el-input v-else-if="f.type==='textarea'" type="textarea" :rows="3" v-model="form[f.k]"/>
          <div v-else-if="f.type==='images'" style="width:100%;">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
              <div v-for="(img, ii) in (form[f.k] || [])" :key="ii" style="position:relative;">
                <img v-if="!img.type || img.type==='image'" :src="img.url" style="width:54px;height:54px;object-fit:cover;border-radius:8px;border:1px solid #e5e6eb;display:block;">
                <div v-else :title="img.name || ''" style="width:54px;height:54px;border-radius:8px;border:1px solid #e5e6eb;background:#f7f9fc;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:11px;color:#6b7688;overflow:hidden;">
                  <span style="font-size:18px;line-height:1.1;">{{ img.type==='video' ? '🎬' : '📄' }}</span>
                  <span style="max-width:50px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ img.type==='video' ? '视频' : '文件' }}</span>
                </div>
                <span @click="rmImg(f.k, ii)" style="position:absolute;top:-7px;right:-7px;width:18px;height:18px;border-radius:50%;background:#f53f3f;color:#fff;text-align:center;line-height:18px;font-size:11px;cursor:pointer;">✕</span>
              </div>
              <button type="button" v-if="(form[f.k] || []).length < (f.max || 3)" @click="pickImg(f.k)" style="width:54px;height:54px;border:1px dashed #c9cdd4;border-radius:8px;background:#fafbfc;font-size:20px;color:#86909c;cursor:pointer;">＋</button>
            </div>
            <input :ref="el => setImgRef(f.k, el)" type="file" accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.7z,.txt,.csv,.apk" style="display:none" @change="onImg($event, f.k)">
            <div class="muted" style="font-size:11px;margin-top:5px;">{{ f.tip || '发送该条消息时将附带图片' }}</div>
          </div>
          <el-input v-else v-model="form[f.k]"/>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dlg=false">取消</el-button>
        <el-button type="primary" @click="save">保存</el-button>
      </template>
    </el-dialog>
  </div>`,
  setup(props, { emit }) {
    const dlg = ref(false);
    const editId = ref(0);
    const form = reactive({});
    const imgRefs = {};
    const setImgRef = (k, el) => { if (el) imgRefs[k] = el; };
    const open = (row) => {
      editId.value = row ? row.id : 0;
      for (const f of props.fields) {
        if (f.type === 'images') {
          form[f.k] = row ? (Array.isArray(row[f.k]) ? row[f.k] : []) : [];
        } else {
          form[f.k] = row ? (row[f.k] !== undefined ? row[f.k] : (f.type === 'bool' ? false : '')) : (f.def !== undefined ? f.def : (f.type === 'bool' ? true : f.type === 'number' ? 0 : ''));
        }
      }
      dlg.value = true;
    };
    const pickImg = (k) => { if (imgRefs[k]) imgRefs[k].click(); };
    const onImg = async (ev, k) => {
      const file = ev.target.files[0];
      ev.target.value = '';
      if (!file) return;
      // v2.43：附件不再只支持图片，视频 / 文件也能挂到快捷消息上
      const isImg = /^image\//.test(file.type);
      const isVid = /^video\//.test(file.type);
      const kind = isImg ? 'image' : (isVid ? 'video' : 'file');
      const limit = isImg ? 5 * 1024 * 1024 : 20 * 1024 * 1024;
      if (file.size > limit) { ELEMENT.ElMessage.warning(isImg ? '图片不能超过 5MB' : '视频/文件不能超过 20MB'); return; }
      const data = await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(file); });
      try {
        const up = await API.post('/api/upload', { data, kind, filename: file.name });
        if (!Array.isArray(form[k])) form[k] = [];
        form[k].push(up);
      } catch (e) { ELEMENT.ElMessage.error(e.message); }
    };
    const rmImg = (k, i) => { if (Array.isArray(form[k])) form[k].splice(i, 1); };
    const save = async () => {
      try {
        if (editId.value) await API.put(props.api + '/' + editId.value, { ...form });
        else await API.post(props.api, { ...form });
        ElementPlus.ElMessage.success('已保存');
        dlg.value = false;
        emit('reload');
      } catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    const del = async (row) => {
      try { await API.del(props.api + '/' + row.id); ElementPlus.ElMessage.success('已删除'); emit('reload'); }
      catch (e) { ElementPlus.ElMessage.error(e.message); }
    };
    /* ===== v2.44 通用导入 / 导出（任何用 crud-table 的页签，加 :io="true" 即可） ===== */
    const impRef = ref(null);
    const csvCell = (v) => {
      const s = String(v == null ? '' : v);
      return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    };
    const downloadText = (text, mime, filename) => {
      const url = URL.createObjectURL(new Blob([text], { type: mime + ';charset=utf-8' }));
      const a = document.createElement('a');
      a.href = url; a.download = filename;
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 4000);
    };
    const stampName = () => new Date().toISOString().slice(0, 10).replace(/-/g, '');
    const isAtt = (f) => f.type === 'images';
    // 导出：JSON 全量（含附件），CSV 只含可读字段（bool 输出 是/否，select 输出中文）
    const expJson = () => {
      const rows = props.rows || [];
      if (!rows.length) { ElementPlus.ElMessage.warning('还没有数据可导出'); return; }
      const data = { type: 'kfxt-' + (props.api || '').replace(/[^a-z-]/gi, ''), version: 1,
        exported_at: new Date().toISOString(), count: rows.length, items: rows };
      downloadText(JSON.stringify(data, null, 2), 'application/json', (props.title || '数据') + '-' + stampName() + '.json');
      ElementPlus.ElMessage.success('已导出 ' + rows.length + ' 条');
    };
    const expCsv = () => {
      const rows = props.rows || [];
      const fs = (props.fields || []).filter(f => !isAtt(f));
      if (!rows.length) { ElementPlus.ElMessage.warning('还没有数据可导出'); return; }
      const head = fs.map(f => f.label || f.k);
      const lines = [head.map(csvCell).join(',')];
      for (const r of rows) {
        lines.push(fs.map(f => {
          const v = r[f.k];
          if (f.type === 'bool') return v ? '是' : '否';
          if (f.type === 'select') {
            const o = (f.options || []).find(x => x.v === v);
            return o ? o.l : (v == null ? '' : v);
          }
          return v == null ? '' : v;
        }).map(csvCell).join(','));
      }
      downloadText('\uFEFF' + lines.join('\r\n'), 'text/csv', (props.title || '数据') + '-' + stampName() + '.csv');
      ElementPlus.ElMessage.success('已导出 ' + rows.length + ' 条');
    };
    const pickImport = () => { if (impRef.value) impRef.value.click(); };
    const csvRows = (text) => {
      const s = String(text || '').replace(/^\uFEFF/, '');
      const rows = []; let row = []; let cell = ''; let inQ = false;
      for (let i = 0; i < s.length; i++) {
        const c = s[i];
        if (inQ) {
          if (c === '"') { if (s[i + 1] === '"') { cell += '"'; i++; } else inQ = false; }
          else cell += c;
        } else if (c === '"') inQ = true;
        else if (c === ',') { row.push(cell); cell = ''; }
        else if (c === '\n') { row.push(cell); rows.push(row); row = []; cell = ''; }
        else if (c !== '\r') cell += c;
      }
      if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
      return rows.filter(r => r.some(x => String(x).trim() !== ''));
    };
    // 把 CSV 单元格按字段类型还原成接口需要的值
    const castCell = (f, raw) => {
      const s = String(raw == null ? '' : raw).trim();
      if (f.type === 'bool') return ['1', '是', 'true', '启用', 'yes', 'y', 'on', '开'].includes(s.toLowerCase());
      if (f.type === 'number') return Number(s) || 0;
      if (f.type === 'select') {
        const o = (f.options || []).find(x => String(x.v) === s || String(x.l) === s);
        return o ? o.v : s;
      }
      return s;
    };
    const doImport = async (ev) => {
      const file = ev.target.files[0];
      ev.target.value = '';
      if (!file) return;
      const text = await new Promise(res => {
        const fr = new FileReader();
        fr.onload = () => res(String(fr.result || ''));
        fr.readAsText(file, 'utf-8');
      });
      const fs = props.fields || [];
      let items = [];
      try {
        const t = text.trim();
        if (/\.json$/i.test(file.name) || t.startsWith('{') || t.startsWith('[')) {
          const j = JSON.parse(text);
          items = Array.isArray(j) ? j : (Array.isArray(j.items) ? j.items : []);
        } else {
          const rows = csvRows(text);
          if (!rows.length) items = [];
          else {
            const head = rows[0].map(x => String(x).trim());
            const keyOf = (h) => {
              const f = fs.find(x => x.k === h || x.label === h);
              return f ? f.k : null;
            };
            const keys = head.map(keyOf);
            const hasHeader = keys.some(Boolean);
            const body = hasHeader ? rows.slice(1) : rows;
            items = body.map(r => {
              const o = {};
              fs.forEach((f, i) => {
                const col = hasHeader ? keys.indexOf(f.k) : i;
                if (col >= 0) o[f.k] = castCell(f, r[col]);
              });
              return o;
            });
          }
        }
      } catch (e) { ElementPlus.ElMessage.error('文件解析失败：' + e.message); return; }
      // 至少要有第一个字段的值，否则这条算空行
      const firstKey = fs[0] && fs[0].k;
      items = items.filter(o => o && firstKey && String(o[firstKey] || '').trim() !== '');
      if (!items.length) {
        ElementPlus.ElMessage.warning('没解析到有效数据（表格第一列不能为空：' + ((fs[0] || {}).label || '') + '）');
        return;
      }
      try {
        await ElementPlus.ElMessageBox.confirm(
          '解析到 ' + items.length + ' 条，会「追加」到现有列表（不删原有的）。确认导入？',
          '导入' + (props.title || ''), { confirmButtonText: '开始导入', cancelButtonText: '取消' });
      } catch (e) { return; }
      let ok = 0, fail = 0;
      for (const o of items) {
        try { await API.post(props.api, o); ok++; } catch (e) { fail++; }
      }
      emit('reload');
      ElementPlus.ElMessage.success('导入完成：成功 ' + ok + ' 条' + (fail ? '，失败 ' + fail + ' 条' : ''));
    };
    return { dlg, editId, form, open, save, del, pickImg, onImg, rmImg, setImgRef,
      impRef, expJson, expCsv, pickImport, doImport };
  }
});
