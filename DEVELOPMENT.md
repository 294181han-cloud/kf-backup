# DEVELOPMENT.md — 单仓库 · 多节点开发与发布逻辑（1+2+n）

> 一句话：**所有功能都在这一个代码库里**；每台服务器跑同一份代码，靠 `config.php` 的
> `site_role` + `hosts` 域名覆盖决定"这台机器是谁"，靠 git 的推/拉节奏实现"主控发版、分控跟随"。

## 1. 仓库地图（改哪类需求动哪个目录）

| 目录/文件 | 内容 | 谁会用到 |
|-----------|------|---------|
| index.php | 唯一入口：站点门禁、页面分发、API 路由、安装/升级 | 架构级改动 |
| public/ | 五个页面（login / app / admin / visitor / gate）+ 根目录 install.php 安装器 | 页面/交互 |
| assets/ | 前端（api.js、app/admin/visitor.js、css、ui/ 款式系统、vendor、qr-poster.js） | 前端 |
| routes/ | 按角色分文件的后端：auth / agent / tenant / admin / visitor / telegram / tgadmin | 后端接口 |
| helpers.php | 全局工具：JWT、上传（含分节点中转）、IP/限频、**站点角色、内存态、节点登记/同步** | 基础设施 |
| db.php | PDO 连接 + 建表 + 种子（仅主站执行） | 数据结构 |
| config.php | 全局配置 + **hosts 按域名覆盖段**（每台节点的身份和数据库都配置在这里） | 部署 |
| node/ | 分控部署辅助：config 模板、setup/cron 脚本、README | 加节点 |
| version.php | 统一版本号（主控/分控对版本） | 发版必 bump |
| selftest.ps1 | 112 项线上回归（打主站） | 每次推送后 |

## 2. 节点身份：一台服务器 = 一段 config

同一份代码推到所有服务器，`config.php` 用 `hosts` 段按**访问域名**区分节点：

```php
'hosts' => [
    'ad.yluu.top'  => [ 'db' => [...], 'site_role' => 'main',    'site_main_url' => 'https://ad.yluu.top',  'relay_secret' => '...' ],
    'agent1.x.com' => [ 'site_role' => 'agent',   'site_main_url' => 'https://ad.yluu.top',  'relay_secret' => '...' ],
    'v1.x.com'     => [ 'site_role' => 'visitor', 'site_main_url' => 'https://ad.yluu.top',  'relay_secret' => '...' ],
],
```

- **主站（main）**：数据库在本机，admin.html 管理台，全部接口。hosts 段带 `db`。
- **客服入口（agent）**：login.html + app.html + 客服/租户接口；**不持有数据库凭证**，
  数据读写经 nginx 反代到主站（`/api/` → 主站）。
- **访客站点（visitor）**：visitor.html / 防红壳页 + 访客接口；同样零数据库凭证。
- 加新节点 = 在 hosts 段加一条 + push + 该节点 DNS/nginx 指向到位。节点**无需改本地文件**。

## 3. 发布与更新流（推一次，全网跟随）

``开发 → commit → push 到 GitHub →``
1. 总控 webhook 自动 pull（即时生效，version.php 版本 +1）
2. 分控节点 cron（每 5 分钟）调 node/update-node.php：向总控检查版本 → 拉取更新包应用
   （总控按白名单打包代码，**config.php 永不下发**——分控无数据库凭证、无 GitHub 访问需求）
   并向总控心跳登记（IP/角色/版本）
3. 主控「节点管理」页核对：节点版本落后会标红；可点「推送更新」把更新包直接推给该节点
4. selftest.ps1（打主站，112 项）回归
5. 分控配置变更：改 config.php 的 hosts 段提交（全网自动生效）——分控本地不再改配置

> 修改某节点的专属配置（如数据库指向）改 config.php 的 hosts 段提交即可；
> 若临时本地调试改了 config.php，记得 `git update-index --skip-worktree config.php` 防覆盖。

## 4. 数据与状态归属（谁存什么）

| 数据 | 位置 | 说明 |
|------|------|------|
| 业务库（MySQL） | 仅主站可达 | 边缘节点零数据库凭证；读写经反代到主站接口 |
| 上传文件（uploads/） | 主站磁盘 | 分节点上传自动中转到主站，返回绝对 URL；旧相对路径由节点 nginx 302 |
| 在线状态（在线表） | 主控内存（APCu） | 随请求隐式刷新、只存**在线的**、停活即删；APCu 未装则回退 DB 判定 |
| 节点登记（nodes 表） | 主站库 | 分控心跳/安装登记，IP 为名 |
| 黑名单 | 主站库 | 租户级 + 平台级全局黑名单；写操作 bl_version+1，边缘按版本同步后本地拦截 |

## 5. 消息同步（现状与枢纽下的行为）

- 无 WebSocket；两端各自轮询 `/api/poll`（访客带活动门控：活跃 2.5s → 闲置降频 → 长期闲置暂停）
- 枢纽模式下轮询经边缘反代到总控执行；写消息 = 总控写库 + bl/内存版本更新
- 撤回/已读增量仍随 poll 返回；改 poll 结构需两端（agent+visitor）一起改（AGENTS.md 铁律）

## 6. 开发约定（在 AGENTS.md 基础上）

- 推送即全网发布：**push 前必须验证**（本地冒烟 + selftest）；分控 5 分钟内自动跟随
- 涉及新表/新配置键：幂等写进 index.php 的 ensure_upgrade + config.example.php 同步
- 涉及边缘/主站行为差异：一律走 `site_role()` 判断，禁止在节点本地写死
- 新增分控：管理台生成授权码 → 节点 /install.php 安装（或 hosts 段 + cron）
