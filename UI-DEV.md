# UI-DEV.md — 多 UI 款式开发指南（交给 AI 执行 UI 任务时随任务附上本文件）

> 你（AI）只负责「款式文件 + 注册表登记」，其余一律不动。做完必须按第 6 节自验。

## 1. 系统结构（30 秒理解）

每个页面 = 固定的基础样式 + 可替换的「款式」。款式分两大类：

| 分类 | 适用页面 | 基础样式 | 款式目录 |
|------|---------|---------|---------|
| pc | /app.html、/admin.html | /assets/css/app.css、admin.css + /assets/ui/pc/_base.css | /assets/ui/pc/<款式id>/theme.css |
| mobile | /visitor.html | /assets/css/visitor.css | /assets/ui/mobile/<款式id>/theme.css |

- `assets/ui/ui-registry.js`：款式注册表。**新增款式必须在这里登记，否则加载器不认识。**
- `assets/ui/ui-loader.js`：加载器（**只读，禁止修改**）。在页面 head 注入 base + 当前款式的 theme.css，
  并在 `<html>` 上设 `data-ui-cat="pc|mobile"` 与 `data-ui="<款式id>"`。
- 选择优先级：URL `?ui=<id>`（预览用，会写入 localStorage）> localStorage > 注册表 default。
- 深色模式（工作台 `body.dark`）是独立开关，与款式正交，不要在款式里管它。

## 2. 变量契约（theme.css 必须覆盖全部变量）

### PC 款式（app/admin）
```
--theme       主题色（经 _base.css 映射为 Element Plus 主色：按钮/链接/菜单/开关全跟随）
--theme-grad  主题渐变（135deg 两色）
```
`_base.css` 已做变量→组件的接线（含 el-color-primary-light-3/5/7/8/9 派生），**不要在款式里重复写这些映射**。

### Mobile 款式（visitor）
```
--theme       主题色（注：租户品牌色/平台皮肤模板会经 JS 内联覆盖它——预期行为，别对抗）
--theme-grad  主题渐变（头部/发送按钮/访客气泡）
--bg          页面背景          --card   卡片/输入区/客服气泡背景
--ink         主文字            --ink-2  次要文字       --ink-3  弱文字（时间戳/占位）
--line        分隔线/描边       --radius 全局圆角基准
```

## 3. 新增一个款式（标准步骤，照抄）

1. 复制目录：`assets/ui/mobile/default/` → `assets/ui/mobile/<新id>/`（id 用小写英文/数字/连字符，如 `mint`）
2. 改 `<新id>/theme.css`：重写 `:root` 里的全部契约变量（参考 mobile/dark 的写法）
3. 若基础样式里有硬编码颜色（搜 visitor.css 中非 `var(--` 的颜色），在 theme.css 里用作用域规则覆盖：
   ```css
   html[data-ui="<新id>"] .v-input { background: #xxxxxx; }
   ```
4. 登记注册表 `assets/ui/ui-registry.js`：在对应分类的 `styles` 数组加一行
   `{ id: '<新id>', name: '<中文名>', chip: '<主题色>', css: '/assets/ui/mobile/<新id>/theme.css' },`
5. 把 `ui-registry.js` 顶部的 `version` +1（如 2.2.0 → 2.2.1）——**每次改任何 theme.css 都要 +1**，否则浏览器用 7 天旧缓存
6. 自验（第 6 节），通过后提交：`ui: 新增手机端款式 <中文名>`

PC 款式同理，目录在 `assets/ui/pc/`，只需 `--theme/--theme-grad` 两个变量。

## 4. 预览与调试

- 任意页面 URL 加 `?ui=<id>` 立即预览：`/visitor.html?t=卡密&ui=dark`、`/app.html?ui=purple`（会记住选择；删掉参数后仍生效，切换其他款式即可覆盖）
- 本地起静态服务（仓库根目录）：`python -m http.server 8000` → 浏览器开 `http://localhost:8000/public/visitor.html?ui=<id>`
- 工作台侧栏底部的色点即 PC 款式切换器（读注册表自动出现新款式）
- 加载失败的表现：页面回退默认款式（加载器容错，不会白屏）。控制台看 `[ui]` 前缀警告

## 5. 红线（违反 = 改坏整个系统）

- **禁止修改**：`ui-loader.js`、`assets/css/*.css` 基础样式、`assets/js/*.js`、`public/*.html` 模板、`routes/`、后端一切
  （例外：登记注册表 ui-registry.js 的 styles 数组与 version）
- 款式只影响"外观"；任何行为/文案/接口改动都不属于 UI 任务
- 不新增第三方库；不使用 JS；一切用 CSS 完成
- 移动端租户品牌色优先于款式 `--theme`，这是产品设定，不要试图覆盖优先级
- `color-mix()` 可用（现代浏览器均支持）；不要用尚未普及的 CSS 特性

## 6. 交付前自验清单（全部通过才算完成）

1. 默认款式零变化：`/visitor.html`（不带 ?ui）与改动前视觉一致
2. 新款式生效：`?ui=<新id>` 后配色全变（头部/按钮/气泡/输入框/文字）
3. 控制台无红错（访客页顶部会显示 JS 错误条，出现即失败）
4. localStorage 切换记忆：刷新页面仍保持新款式
5. 移动端额外：在 375px 宽度（手机模拟）下检查布局不破
6. PC 款式额外：工作台按钮/菜单/链接颜色跟随、四个色点可互切
7. `git diff` 里只有：新款式目录、ui-registry.js 两处（登记行 + version）

## 7. 现有款式清单（2026-10）

- PC：default 经典蓝 / purple 优雅紫 / sky 天空蓝 / green 清新绿
- Mobile：default 默认亮色 / dark 暗色（含结构覆盖示例）
- 注意：访客端「19 套平台皮肤模板」（微信/支付宝皮肤等）是**租户营销功能**（body[data-tpl]），与本款式系统无关，两回事，勿混淆
