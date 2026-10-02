# UI-DEV.md — 多 UI 开发指南（交给 AI 执行 UI 任务时随任务附上本文件）

> 你（AI）只负责「模板/配色文件 + 注册表登记」，其余一律不动。做完必须按第 6 节自验。
> 涉及页面结构、JS 行为、后端的改动不属于本文件范围（那需要走 AGENTS.md 的完整流程）。

## 1. 系统结构（30 秒理解）

体系：**分类 → 模板 → 配色**。换色不算一个完整 UI；完整 UI = 模板，模板之下再带配色。

| 分类 | 适用页面 | 基础样式 | 目录 |
|------|---------|---------|---------|
| pc（电脑端） | /app.html、/admin.html | /assets/css/app.css、admin.css + /assets/ui/pc/_base.css | /assets/ui/pc/ |
| mobile（手机端） | /visitor.html | /assets/css/visitor.css | /assets/ui/mobile/ |

- **模板**（结构性外观）：`assets/ui/<分类>/<模板id>/template.css`（可选；为 null 表示沿用基础样式结构）。
  当前只有「默认」模板。
- **配色**（颜色方案）：`assets/ui/<分类>/<模板目录>/<配色id>/theme.css`（历史原因，默认模板的配色
  直接放在 `<分类>/<配色id>/theme.css`，注册表里有明确路径，照抄登记即可）。
- `assets/ui/ui-registry.js`：注册表（分类 → 模板 → 配色三级）。**新增必须在这里登记，否则加载器不认识。**
- `assets/ui/ui-loader.js`：加载器。在页面 head 注入 base + 模板 css + 配色 css，
  并在 `<html>` 上设 `data-ui-cat="pc|mobile"`、`data-ui-template="<模板id>"`、`data-ui="<配色id>"`。
- 选择优先级：URL `?ui=<配色id>` / `?uit=<模板id>`（预览用，写入 localStorage）> localStorage > 注册表默认。
- 配色切换即时生效（工作台「系统 → 后台UI」页选择，保存在本浏览器）。
- 深色模式（工作台 `body.dark`）是独立开关，与模板/配色正交，不要在配色里管它。

## 2. 变量契约（配色 theme.css 必须覆盖全部变量）

### PC 配色（app/admin）
```
--theme       主题色（经 _base.css 映射为 Element Plus 主色：按钮/链接/菜单/开关全跟随）
--theme-grad  主题渐变（135deg 两色）
```
`_base.css` 已做变量→组件的接线（含 el-color-primary-light-3/5/7/8/9 派生），**不要在配色里重复写这些映射**。

除变量外，配色文件还用 `html[data-ui="<配色id>"]` 作用域规则覆盖 app.css/admin.css 里硬编码的
蓝色点缀（侧栏活动项/发送按钮/自方消息气泡/获客码页/条形图等，见现有配色文件）——
**每套配色都必须带这份覆盖**，否则切换后大半界面不变色（v2.18 前的"紫色的点了没反应"即此原因）。

### Mobile 配色（visitor）
```
--theme       主题色（注：租户品牌色/平台皮肤模板会经 JS 内联覆盖它——预期行为，别对抗）
--theme-grad  主题渐变（头部/发送按钮/访客气泡）
--bg          页面背景          --card   卡片/输入区/客服气泡背景
--ink         主文字            --ink-2  次要文字       --ink-3  弱文字（时间戳/占位）
--line        分隔线/描边       --radius 全局圆角基准
```
强调面（气泡阴影/按钮光晕/头像底/加载圈）用 `color-mix(in srgb, var(--theme) N%, …)` 派生，
租户品牌色覆盖 --theme 后依旧协调。

## 3. 新增一个配色（标准步骤，照抄）

1. 复制目录：`assets/ui/mobile/default/` → `assets/ui/mobile/<新id>/`（id 用小写英文/数字/连字符）
2. 改 `<新id>/theme.css`：重写全部契约变量 + 照抄现有配色的作用域覆盖块（替换 data-ui 值）
3. 登记注册表 `assets/ui/ui-registry.js`：在对应分类「默认」模板的 `colors` 数组加一行
   `{ id: '<新id>', name: '<中文名>', chip: '<主题色>', css: '/assets/ui/<分类>/<新id>/theme.css' },`
4. 把 `ui-registry.js` 顶部的 `version` +1——**每次改任何模板/配色文件都要 +1**，否则浏览器用 7 天旧缓存
5. 自验（第 6 节），通过后提交：`ui: 新增<分类>配色 <中文名>`

## 4. 新增一个模板（结构性 UI，标准步骤）

1. 建目录 `assets/ui/<分类>/<模板id>/`，写 `template.css`（结构性覆盖：布局/形状/间距，
   用 `html[data-ui-template="<模板id>"]` 作用域；颜色一律引用契约变量，不写死）
2. 在注册表该分类 `templates` 数组登记：`{ id, name, css: '/assets/ui/<分类>/<模板id>/template.css', colors: [...] }`
3. version +1，自验后提交：`ui: 新增<分类>模板 <中文名>`

## 5. 预览与调试

- 任意页面 URL 加 `?ui=<配色id>`（或 `?uit=<模板id>`）立即预览：`/visitor.html?t=卡密&ui=dark`、`/app.html?ui=rose`
- 本地起静态服务（仓库根目录）：`python -m http.server 8000` → `http://localhost:8000/public/visitor.html?ui=<id>`
- 工作台入口：「系统 → 后台UI」配置页（读注册表自动出现新模板/配色）
- 加载失败的表现：页面回退默认（加载器容错，不会白屏）。控制台看 `[ui]` 前缀警告

## 6. 红线（违反 = 改坏整个系统）

- **禁止修改**：`assets/css/*.css` 基础样式、`assets/js/*.js`、`public/*.html` 模板、`routes/`、后端一切
  （例外：ui-loader.js / 注册表 / 页面模板的改动需用户明确指派，走完整验证流程，如 v2.19）
- 模板/配色只影响"外观"；任何行为/文案/接口改动都不属于 UI 任务
- 不新增第三方库；预览之外的逻辑一律用 CSS 完成
- 移动端租户品牌色优先于配色 `--theme`，这是产品设定，不要试图覆盖优先级
- `color-mix()` 可用（现代浏览器均支持）；`:has()` 可用但必须有安全回退；不要用其他尚未普及的 CSS 特性

## 7. 交付前自验清单（全部通过才算完成）

1. 默认（默认模板 + 经典蓝/默认亮色）零变化：不带 ?ui 打开页面与改动前视觉一致
2. 新配色/模板生效：`?ui=<新id>` 后配色全变（头部/按钮/气泡/输入框/文字），切换后无明显残留旧色
3. 控制台无红错（访客页顶部会显示 JS 错误条，出现即失败）
4. localStorage 切换记忆：刷新页面仍保持选择
5. 移动端额外：375px 宽度（手机模拟）下布局不破
6. PC 配色额外：工作台按钮/菜单/链接颜色跟随、深色模式开启后侧栏为深色且活动项仍醒目
7. `git diff` 范围：新配色/模板目录 + ui-registry.js（登记行 + version）+（如涉引用文件）?v= 提升

## 8. 现有清单（2026-10，v2.19）

- 分类：pc 电脑端（app/admin）/ mobile 手机端（visitor）；各只有一个「默认」模板
- PC 配色：经典蓝（default）/ 优雅紫 / 天空蓝 / 清新绿 / 玫瑰红 / 暖阳橙 / 靛蓝 / 石墨灰
- Mobile 配色：默认亮色（default）/ 暗色 / 薄荷 / 樱花粉 / 暖阳橙 / 香芋紫
- 注意：访客端「19 套平台皮肤模板」（微信/支付宝皮肤等，body[data-tpl]）是**租户营销功能**，
  与本模板/配色系统无关，两回事，勿混淆
