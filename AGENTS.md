# AGENTS.md — 开发协作规范（人类 + AI 共用）

> 目标读者：参与本项目的两个人、以及任何被指派到本仓库的 AI 编程助手。
> 原则：模块化、小步提交、推送即上线——所以**推送前必须验证**。

## 1. 项目一句话

多租户客服 SaaS（PHP 8.4 + MySQL 8，无 Composer；前端 Vue 3 全局构建 + Element Plus，无打包步骤）。
平台管理员发卡密 → 每个卡密是一个完全隔离的客服系统（子客服/话术/获客链接/额度计费）。

| 角色 | 账号格式 | 页面 |
|------|---------|------|
| 平台管理员 | `admin` | /admin.html |
| 租户（卡密） | `卡密` | /app.html |
| 子客服 | `卡密/用户名` | /app.html |
| 访客 | 免登录 | /visitor.html?t=卡密 或 ?code=客服码 |

## 2. 目录地图（改东西前先看这里）

```
├── index.php            # 唯一入口：安装/升级、静态兜底、页面分发、API 路由表
├── config.php           # 数据库等配置（有意入库：私人仓库，服务器直读）
├── db.php               # PDO 单例 + 全部建表 + 种子数据
├── helpers.php          # JWT / 上传 / 限频 / IP 归属地 / json_out 等工具函数
├── routes/              # API 处理器，按角色分文件：
│   ├── auth.php         #   登录 / me / 改密 / 资料
│   ├── visitor.php      #   访客公开接口（init/send/poll…）
│   ├── agent.php        #   工作台（会话/消息/轮询）
│   ├── tenant.php       #   租户管理（子客服/客服码/设置/访客资源…）
│   └── admin.php        #   平台管理（租户/域名池/全局设置）
├── public/              # 四个页面，只放 HTML 模板（v2.1.0 起无内联样式/脚本）
│   ├── login.html       #   登录页（唯一保留内联 style/script 的页面，量小勿拆）
│   ├── app.html         #   客服工作台（模板引用 assets/js/app*.js）
│   ├── admin.html       #   平台管理台
│   └── visitor.html     #   访客端（19 套平台皮肤）
├── assets/
│   ├── api.js           # 共享 API 客户端（fetch 封装、401 跳登录、JSON 容错）
│   ├── qr-poster.js     # 共享二维码海报绘制
│   ├── css/             # 页面基础样式：app.css / admin.css / visitor.css
│   ├── js/              # 页面逻辑：app.js / app-components.js / app-boot.js / admin.js / visitor.js
│   ├── ui/              # 多 UI 系统：客服后台的 分类(pc电脑端/mobile手机端)→模板→配色（见 UI-DEV.md）
│   │   ├── ui-registry.js  # 注册表：分类→模板→配色三级（新增在此登记）
│   │   ├── ui-loader.js    # 加载器（base+模板+配色注入；app.html data-ui-cat="auto" 按宽度自动选端）
│   │   ├── pc/          # 电脑端后台配色：_base.css + default/purple/sky/green/rose/sunset/indigo/graphite
│   │   └── mobile/      # 手机端后台（app.html 手机浏览器）：_base.css + 默认模板(抽屉+单屏) + 6 配色
│   └── vendor/          # 第三方库（vue/element-plus/echarts/qrcode），不修改
├── selftest.ps1         # 112 项全流程回归（直接跑线上！幂等可重跑）
├── DEPLOY.md            # 部署运维 + v1~v5 全部变更记录（新变更追加在文末）
└── uploads/ data/       # 运行数据（有意入库；服务器上 git pull 不覆盖）
```

## 3. 前端模块约定（v2.1.0 重构后；v2.2.0 起含多 UI 款式系统）

- **模板**（`public/*.html`）：只写 Vue 模板标记与结构，不放 `<style>`/`<script>` 内联块。
- **逻辑**（`assets/js/<page>.js`）：每页一个主文件；跨页共享组件放 `app-components.js`。
- **样式**（`assets/css/<page>.css`）：每页一个，类名与模板对应。
- **加载顺序（app.html）**：vue → element-plus → qrcode → qr-poster → api.js → app-components.js → app.js → app-boot.js。
  app.js 顶层 `const` 依赖 app-components.js 先执行（经典 script 共享全局词法环境，顺序不可乱）。
- **缓存版本**：改了任何 css/js 内容，必须把该文件引用的 `?v=` 号 +1（当前 2.1.0），否则用户拿到 7 天旧缓存。
- **多 UI 系统（v2.19 起：分类→模板→配色）**：工作台「系统 → 后台UI」页选择，`?ui=<配色id>` 可预览。
  **纯配色/模板外观任务一律按 [UI-DEV.md](UI-DEV.md) 执行**——该文档是交给轻量模型（如 GLM Flash）做 UI 的
  任务边界与自验清单，配色开发只动配色目录 + 注册表；涉及加载器/页面/JS 的改动需明确指派并走完整验证。
- vendor 库永远不改；缺什么加文件到 vendor/ 并在页面引用。
- **in-DOM 模板禁用自闭合组件标签**（`<el-input/>`）：浏览器会把后续兄弟元素解析进组件内部导致消失，
  必须显式闭合（`<el-input></el-input>`）；原生标签（input/img/br）不受影响。v2.23 曾全站修复 91 处。

## 4. 后端约定

- 新接口 = 在 `routes/<角色>.php` 写 `handle_xxx()`，再在 `index.php` 的 `dispatch_api()` 注册一行。
- 所有业务查询必须带 `tenant_id` 过滤；跨租户一律 404/403（防越权是本项目安全基线）。
- 出错用 `json_error('...', 400)` —— 错误码避开 404/500（宝塔错误页会拦截 JSON）。
- `index.php` 关闭了 `display_errors`：任何 Warning 文本混入 JSON 都会打挂前端解析，别打开它。
- 新表/新列：幂等写法放 `ensure_upgrade()`（try ALTER + 忽略已存在），别要求手工迁移。
- 消息轮询是 2.5s 自适应（接待中 1.2s / 空闲 3s），无 WebSocket（FPM 环境勿引入常驻进程）。

## 5. 协作与部署流程（多人 + AI）

### 5.1 三个仓库分别是什么（2026-10-12 核实）

| 远端 | 地址 | 作用 |
|---|---|---|
| `origin` | `ssh://root@95.169.14.164/www/git/kf.git` | **推它 = 上线**。裸库的 `post-receive` 钩子会执行 `cd /www/wwwroot/ad.yluu.top && git pull --ff-only origin main`，回显 `DEPLOY OK` 即部署成功（日志 `/www/git/kf-deploy.log`） |
| `prodssh` | `ssh://root@95.169.14.164/www/wwwroot/ad.yluu.top` | 生产工作区本身（只用来核对，不要往里推） |
| `mygit` | `git@github-xm:ylbc222/Xiaomiyun.git` | GitHub 备份/协作镜像。`main` 是**孤儿单提交快照**（每次 force-push 覆盖），`dev` 是**完整历史**分支，给协作者 clone、开 PR 用 |

### 5.2 分工（重要）

- **只有账号主人往 `origin` 推**（推 = 上线，没有测试环境、没有二次确认）。
- **协作者只推 GitHub**：`git clone … → git checkout dev → git checkout -b feat/xxx → push → 开 PR（base 选 dev）`。
- 主人 review 后：本地 `git fetch mygit && git merge mygit/dev`（或直接 cherry-pick）→ 并进 `main` → `git push origin main` 上线。
- GitHub 上给 `main` 打开 **Settings → Branches → Add branch protection rule**（勾 "Require a pull request before merging"），协作者即使点错也上不了线。
- 给协作者加 **Settings → Collaborators → Write** 权限即可，**不要**把服务器 root 私钥给他。

### 5.3 三个仓库的自动同步（v2.91.13 起）

- **推 `origin main` = 部署 + 自动同步 GitHub**。裸库 `/www/git/kf.git` 的 `post-receive` 钩子做完
  `git pull --ff-only` 之后，会**后台异步**跑 `/www/git/kf-sync-github.sh`，把主站代码推到
  `git@github.com:ylbc222/Xiaomiyun.git` 的 **`dev`** 分支（失败只写 `/www/git/kf-sync.log`，绝不影响部署）。
  这需要 GitHub 仓库里那把 **Deploy key（Allow write access）**：`kfxt-autosync@95.169.14.164`
  （私钥在服务器 `/www/git/.ssh/kf_sync`；换了要重新在 GitHub → Settings → Deploy keys 增加）。
- **GitHub `main` 是孤儿快照分支**（只有一个提交，每次覆盖），由本机发版后手动推：
  `git push --force-with-lease mygit <快照提交>:refs/heads/main`（做法见 git 历史里的 `mygit-snapshot` 分支）。
  **不要把 `dev` 合并进 `main`**，两者没有共同祖先。
- 手工兜底（钩子没跑成 / 想立刻同步）：
  - 服务器：`/www/git/kf-sync-github.sh`（看 `/www/git/kf-sync.log`）
  - 本机：`git push mygit refs/heads/main:refs/heads/dev`
- 协作者拉代码永远用 **`dev`**；`main`（GitHub 那个）只是备份快照，不参与协作。
### 5.4 每次改动（主人和协作者同一套）

1. **开工先同步**：`git fetch --all --prune`，热点文件（`assets/js/app.js`、`routes/agent.php`）先拉再改。
2. **小步提交**：一个提交只做一件事，message 用 `类型: 描述`（`feat:` / `fix:` / `perf:` / `chore:` / `revert:`）。中文 message 只用 `git commit -F <文件>`（PowerShell here-string 会把消息拆坏）。
3. **改了 css/js 必须把 `?v=` +1**（`public/app.html` 里的引用），否则用户拿 7 天旧缓存；功能级改动再 `version.php` +1。
4. **验证三步**：
   - 后端改了 → `powershell -ExecutionPolicy Bypass -File selftest.ps1`（直打线上、幂等、自建自清测试租户）；
   - 前端改了 → 打开 https://ad.yluu.top/ 对应页面走一遍；控制台不能有红错（`app-boot.js` 会在页面顶部显示错误条）；
   - 大改 → 本地 `python -m http.server 8000` 起在仓库根目录，开 `http://localhost:8000/public/<page>.html` 冒烟（API 404 属预期，看的是 JS 报错）。
5. **推 `origin main` → 看服务器回显 `DEPLOY OK` → 立刻核线上**：`curl https://ad.yluu.top/api/health` 的 `app_version` 应对上新版本号（工作台顶栏那个 `vX.Y.Z` 标签也能看）。
6. **变更记录**：功能级变更在 `DEPLOY.md` 文末追加一节 `## vN.N 变更记录`。

## 6. 历史坑（勿重复踩）

- Element Plus 全局名是 `ElementPlus`（不是 `ELEMENT`）。
- PowerShell 5.1 的 curl.exe 多行输出是数组：判断内容前先 `-join "`n"`。
- api.js 的 JSON 解析有容错（剥离混入前缀），但根因永远是后端混入了非 JSON 输出。
- 撤回/已读等增量同步依赖 poll 返回的 `recalledIds`/`readUpto`，改动 poll 结构要两端（agent+visitor）一起改。
- 服务器禁用 shell_exec（无 ffmpeg），语音按原始格式存，前端选兼容编码录制。

## 7. AI 助手指令

- 改前端逻辑前先读对应模板页，确认引用的变量/方法在 `assets/js/<page>.js` 的 setup() 返回对象里存在。
- `app.js` 的 setup() 是单函数作用域，内部互相引用密集；拆分/重命名需全文搜索引用点。
- 不要生成新的根目录散装脚本（`_p*.py` 之类一次性补丁已清理，用后即删，勿再现）。
- 不要动 `uploads/`、`data/`、`config.php` 的入库状态（这是项目约定，非失误）。


> 多节点（1+2+n）开发与发布逻辑见 [DEVELOPMENT.md](DEVELOPMENT.md)；分控部署物料在 node/。
