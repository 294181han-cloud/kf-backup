// app-components.js - 工作台共享组件（Vue 解构 + CrudTable；由 public/app.html 内联脚本提取）
const { createApp, ref, reactive, computed, onMounted, onUnmounted, nextTick, defineComponent, h } = Vue;

/* ---------- 通用 CRUD 表格组件 ---------- */
const CrudTable = defineComponent({
  props: { rows: Array, cols: Array, fields: Array, title: String, addText: String, api: String },
  emits: ['reload'],
  template: `
  <div class="panel">
    <div class="panel-title" style="justify-content:flex-start;gap:12px;">
      {{ title }}（{{ rows.length }}）
      <el-button size="small" type="primary" @click="open()">{{ addText }}</el-button>
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
                <img :src="img.url" style="width:54px;height:54px;object-fit:cover;border-radius:8px;border:1px solid #e5e6eb;display:block;">
                <span @click="rmImg(f.k, ii)" style="position:absolute;top:-7px;right:-7px;width:18px;height:18px;border-radius:50%;background:#f53f3f;color:#fff;text-align:center;line-height:18px;font-size:11px;cursor:pointer;">✕</span>
              </div>
              <button type="button" v-if="(form[f.k] || []).length < (f.max || 3)" @click="pickImg(f.k)" style="width:54px;height:54px;border:1px dashed #c9cdd4;border-radius:8px;background:#fafbfc;font-size:20px;color:#86909c;cursor:pointer;">＋</button>
            </div>
            <input :ref="el => setImgRef(f.k, el)" type="file" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none" @change="onImg($event, f.k)">
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
      if (file.size > 5 * 1024 * 1024) { ELEMENT.ElMessage.warning('图片不能超过 5MB'); return; }
      const data = await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(file); });
      try {
        const up = await API.post('/api/upload', { data, kind: 'image', filename: file.name });
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
    return { dlg, editId, form, open, save, del, pickImg, onImg, rmImg, setImgRef };
  }
});
