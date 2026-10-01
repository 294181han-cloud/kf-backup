# -*- coding: utf-8 -*-
# app.html v3.2 补丁：个人设置头像上传 + 1:1 裁剪器
import io, sys
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

f = r"D:\kfxt\kf\public\app.html"
c = open(f, encoding="utf-8").read()
ok = 0; total = 0

def rep(old, new, name):
    global c, ok, total
    total += 1
    if old in c:
        c = c.replace(old, new, 1); ok += 1; print("[OK]", name)
    else:
        print("[MISS]", name, "!!!")

# ---- 1. 个人设置：头像区块（资料面板顶部） ----
rep('''        <div class="panel" style="max-width:560px;margin:0 auto;">
          <div class="panel-title">个人资料</div>
          <el-form label-width="80px">''',
'''        <div class="panel" style="max-width:560px;margin:0 auto;">
          <div class="panel-title">个人资料</div>
          <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
            <div style="position:relative;">
              <div style="width:64px;height:64px;border-radius:50%;overflow:hidden;background:#f0f7ff;display:flex;align-items:center;justify-content:center;font-size:30px;border:2px solid #1890ff33;">
                <img v-if="avatarPreview" :src="avatarPreview" style="width:100%;height:100%;object-fit:cover;">
                <template v-else>{{ me && me.role==='tenant' ? '👑' : '🎧' }}</template>
              </div>
              <span style="position:absolute;bottom:-2px;right:-2px;width:14px;height:14px;border-radius:50%;background:#13c56b;border:2px solid #fff;"></span>
            </div>
            <div>
              <el-button size="small" type="primary" plain @click="openCrop">上传头像</el-button>
              <div class="muted" style="font-size:11px;margin-top:5px;">支持 1:1 裁剪， JPG/PNG ≤ 5MB</div>
            </div>
          </div>
          <el-form label-width="80px">''', '头像区块')

# ---- 2. 裁剪 dialog（插在 img-viewer 前） ----
anchor = '<div class="img-viewer" v-if="viewing" @click="viewing=\'\'">'
if anchor in c and 'crop-stage' not in c:
    crop_dlg = '''  <!-- 头像裁剪 -->
  <el-dialog v-model="cropDlg" title="裁剪头像（1:1）" width="440px" @closed="cropClose">
    <div style="display:flex;justify-content:center;">
      <div class="crop-stage" ref="cropStage"
        @pointerdown="cropDown" @pointermove="cropMove" @pointerup="cropUp" @pointercancel="cropUp"
        @wheel.prevent="cropWheel">
        <img :src="cropSrc" class="crop-img" :style="cropStyle" draggable="false"
          @dragstart.prevent @load="cropImgLoaded">
        <div class="crop-box"></div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:12px;margin-top:14px;justify-content:center;">
      <span class="muted" style="font-size:12px;">缩小</span>
      <el-slider v-model="cropZoomPct" style="flex:1;max-width:220px;" :min="10" :max="100" :step="1" @input="cropZoomInput"/>
      <span class="muted" style="font-size:12px;">放大</span>
    </div>
    <p class="muted" style="text-align:center;font-size:12px;margin:8px 0 0;">拖动图片调整位置，滑动条缩放，方框内为最终头像</p>
    <template #footer>
      <el-button @click="cropDlg=false">取消</el-button>
      <el-button type="primary" @click="cropConfirm">确认并上传</el-button>
    </template>
  </el-dialog>

  <div class="img-viewer" v-if="viewing" @click="viewing=''"><img :src="viewing"></div>'''
    c = c.replace(anchor, crop_dlg, 1)
    ok += 1; print("[OK] 裁剪 dialog")

# ---- 3. 裁剪 CSS ----
css_anchor = "    .unread-chip { background: #e12c2c; color: #fff; font-size: 12px; padding: 2px 10px; border-radius: 999px; }"
if css_anchor in c and 'crop-stage' not in c:
    css_new = css_anchor + '''
    .crop-stage {
      width: 320px; height: 320px; background: #26292e; position: relative;
      overflow: hidden; border-radius: 10px; cursor: grab; touch-action: none;
      user-select: none;
    }
    .crop-stage:active { cursor: grabbing; }
    .crop-img { position: absolute; transform-origin: 0 0; pointer-events: none; }
    .crop-box {
      position: absolute; left: 50%; top: 50%; width: 200px; height: 200px;
      transform: translate(-50%, -50%);
      border: 2px solid #1890ff; border-radius: 8px;
      box-shadow: 0 0 0 9999px rgba(0,0,0,.55);
      pointer-events: none;
    }'''
    c = c.replace(css_anchor, css_new, 1)
    ok += 1; print("[OK] 裁剪 CSS")

# ---- 4. JS：裁剪逻辑 ----
js_anchor = "    // ===== 获客码页（统一码） ====="
if js_anchor in c and "const cropDlg" not in c:
    js_new = '''    // ===== 头像裁剪（1:1） =====
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

    // ===== 获客码页（统一码） ====='''
    c = c.replace(js_anchor, js_new, 1)
    ok += 1; print("[OK] 裁剪 JS")

# ---- 5. 深色模式裁剪器适配 ----
dark_anchor = "body.dark .quick-btn { background: #464649ad; color: #fff; }"
if dark_anchor in c and "body.dark .crop-stage" not in c:
    c = c.replace(dark_anchor, dark_anchor + "\nbody.dark .crop-stage { background: #14171c; }\nbody.dark .crop-box { border-color: #69b1ff; }", 1)
    ok += 1; print("[OK] 深色裁剪器")

open(f, "w", encoding="utf-8").write(c)
print(f"\n补丁完成: {ok}/{total}")
