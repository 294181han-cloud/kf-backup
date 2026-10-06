# DEVELOPMENT.md — 开发与更新规范（单仓库 · 1+2+n 多节点 · 总控枢纽）

> 本文是**唯一的权威规范**：架构、打包器、推送通道、节点部署、数据归属、排错。
> 与旧文档/旧记忆冲突时以本文为准。修改架构前先更新本文。

## 1. 架构总览

```
                    GitHub（私有仓库，协作开发）
                          │ push → webhook 自动 pull
                          ▼
   ┌─────────────── 总控 ad.yluu.top（site_role=main）───────────────┐
   │  MySQL（唯一数据库）· admin.html 管理台 · 授权码/节点管理/打包分发  │
   └──────────────┬───────────────────────────────────────────────────┘
                  │ 更新包（按白名单打包，config.php 永不下发）
        ┌─────────┴─────────┐
        ▼                   ▼            ……（n 个）
   客服入口 ×2            访客站点 ×n
（site_role=agent）   （site_role=visitor）
  login.html+app.html   visitor.html+防红壳页
```

- **所有节点跑同一份代码**（同一仓库 push 到所有服务器），节点身份由 config.php 决定
- **只有总控碰数据库**；边缘节点零数据库凭证，数据读写经 `/api/` 反代到总控
- 边缘被攻破 ≠ 数据库泄露（分控本地 config 只有角色和密钥，无库密码）

## 2. 仓库地图（改什么动哪里）

| 路径 | 内容 |
|------|------|
| index.php | 唯一入口：站点角色门禁、页面分发、API 路由、边缘转发兜底、安装/升级 |
| public/ | 五个页面：login / app / admin / visitor / gate |
| assets/ | 前端：api.js、app/admin/visitor.js、css、ui/ 款式、vendor/、qr-poster.js |
| routes/ | 后端按角色：auth / agent / tenant / admin / visitor / telegram / tgadmin |
| helpers.php | 全局工具：JWT、上传中转、**站点角色、打包器、节点登记、在线状态、黑名单** |
| db.php | PDO + 建表 + 种子（仅总站执行） |
| config.php | 全局配置 + **hosts 按域名覆盖段**（每台节点的身份/数据库都配置在这里） |
| version.php | 统一版本号（发版必 +1；主控/分控对版本用） |
| node/ | 分控辅助：update-node.php 自更新器、cron-node.sh（可选 cron）、README |
| bootstrap.php | 分控单文件网页安装器（新节点部署入口） |
| selftest.ps1 | 112 项线上回归（打主站） |

## 3. 节点身份：config.php 的 hosts 段

同一份 config.php 服务所有节点，`hosts` 段按**访问域名**精确覆盖：

```php
'hosts' => [
    'ad.yluu.top'    => [ 'db' => [...], 'site_role' => 'main',    'site_main_url' => 'https://ad.yluu.top',  'relay_secret' => '...' ],
    'agent1.xxx.com' => [ 'site_role' => 'agent',   'site_main_url' => 'https://ad.yluu.top',  'relay_secret' => '...' ],   // 边缘无需 db 段
    'v1.xxx.com'     => [ 'site_role' => 'visitor', 'site_main_url' => 'https://ad.yluu.top',  'relay_secret' => '...' ],
],
```

- 改某节点的配置 = 改对应段 → commit → push → **全网自动生效**（节点本地不再改配置）
- `config()` 读取时按 `HTTP_HOST` 自动合并；无 Host（CLI）用基础段
- 临时本地调试：`git update-index --skip-worktree config.php` 防覆盖

## 4. 更新与打包机制（核心：helpers.php 的 node_bundle_* 函数族）

### 4.1 打包器（node_bundle_build）

主控按**白名单**把当前代码打成更新包 `{version, files{路径: base64 内容}}`：

**会推送的（白名单，精确清单）**：

| 类型 | 清单 |
|------|------|
| 目录整体 | `assets/`、`public/`、`routes/`、`vendor/` |
| node/ 内仅 | `cron-node.sh`（可选 cron 工具） |
| 根文件 | `index.php`、`helpers.php`、`db.php`、`version.php` |

**永远不会推送的**：

| 排除项 | 原因 |
|--------|------|
| `config.php` | 节点各自本地配置（安装器生成），含密钥不下发 |
| `data/`、`uploads/` | 运行数据，节点本地所有 |
| `bootstrap.php`、`*.md`、`selftest.ps1`、`config.example.php`、隐藏文件、`*.log` | 非运行代码 |

- 包体约 5.4MB / 57 文件，**仅版本变化时传输**（同版本返回 up_to_date）
- 打包器不遍历 `.git`、`data`、`uploads` 目录

### 4.2 应用端（apply_node_bundle / 节点侧）

- 受保护路径（config.php、data/、uploads/）**永不覆盖**
- 写入用**临时文件 + 原子重命名**（防止覆盖正在执行的脚本导致 500——v2.40 实测教训）
- 按包清单清理总控已删除的文件；清单落 data/node-manifest.json

### 4.3 推送通道（两条，互为备份）

| 通道 | 触发 | 流程 |
|------|------|------|
| **自动（推荐）** | 边缘随流量每 5 分钟惰性自维护（edge_maintenance，无需 cron） | 边缘 → 总控查版本 → 有新版拉包应用 + 黑名单同步 + 心跳登记 |
| **手动** | 主控「节点管理 → 推送更新」 | 主控打包 → 直推节点 `/api/internal/apply`（共享 relay_secret 校验） |

- 节点登记三条途径自动汇合：bootstrap 安装登记 / 边缘反代请求头登记（X-Edge-Secret/Role/Origin，总控校验后自动入列）/ 心跳登记——**无需手工录入**
- 手动「推送更新」在节点未开放 shell_exec 时也可用（纯 HTTP + 文件写入）

## 5. 节点部署（只要 bootstrap.php）

1. 主控「节点管理」生成**授权码**（一次性，选角色）
2. 管理台下载 `bootstrap.php` → 放进节点站点根目录
3. 浏览器打开 → 填主站地址 + 授权码 + 选角色 → 开始安装

安装器自动完成：授权码验证（用后即焚）→ **从总控拉取全部代码** → 生成节点 config（无数据库凭证）→ 写 `.user.ini` 加固 → 写 `nginx-edge.conf`（伪静态 + /api/ 反代 + 边缘身份头，二合一）→ 黑名单首次同步 → 自删除。

**人工步骤仅剩**：宝塔建站 + PHP 8.4 + 粘贴 `nginx-edge.conf`。

## 6. 日常开发流程（每次改动）

```
0. git fetch --prune：先确认没有同事的未合并分支（git branch -r / git ls-remote --heads origin 看 origin/feat-*）
   有就先 git merge origin/<分支> 合进 main 再发版——漏合 = 下次 git reset --hard origin/main 会把同事的改动静默丢掉
1. git pull（多人协作先同步）
2. 改代码（改哪类需求动哪个目录，见第 2 节地图）
3. 本地冒烟（如涉及页面/接口）
4. commit（类型: 描述）→ push
   → 总控 webhook 即时生效
   → 分控 5 分钟内自动跟随
   → 主控「节点管理」核对版本无标红
5. 涉及后端/架构：selftest.ps1 回归（112 项）
6. 功能级变更：DEPLOY.md 文末追加变更记录
```

- 版本号：每次**功能级发布** version.php +1；纯修复/文档可不 bump
- 分控配置变更（hosts 段）：随代码 push 全网生效，无需登录各节点
- **发版前必查同事分支**：`git fetch --prune && git branch -r`，把 `origin/feat-*` 先合进 main 再发版
  （合并只涉及后台功能的改动，对正在使用的客服码**无影响**——不会重置 `service_codes`，旧码照常可用）

## 7. 数据与状态归属

| 数据 | 存放 | 说明 |
|------|------|------|
| 业务数据库（MySQL） | 仅总控可达 | 边缘零凭证；读写经反代 |
| 上传文件 | 总控磁盘 | 分节点上传自动中转，返回绝对 URL；旧相对路径节点 nginx 302 |
| 在线状态 | 总控内存（APCu，未装则回退 DB 判定） | 只存**在线的**，随请求刷新，75 秒停活即删，不落数据库 |
| 节点登记（nodes 表） | 总控库 | IP+角色 唯一；心跳/安装/反代头三途径自动维护 |
| 黑名单 | 总控库（租户级 + 全局） | 写操作 bl_version+1；边缘按版本同步后**本地拦截** |
| 卡密登录入口列表 | 总控 kv | 管理台「节点管理」维护；发卡文案按 入口1、入口2… 展示 |
| 节点配置 | 各节点本地 config.php（由安装器生成/hosts 段定义） | 无数据库凭证 |

## 8. 排错速查

| 症状 | 原因/处理 |
|------|-----------|
| 分控登录 404 页 | 边缘缺 /api/ 反代——v2.40 起有 PHP 转发兜底，更新节点即修复；根治仍建议补 nginx 片段 |
| 响应解析失败(200) | 历史压缩头 bug（已修复 v2.40）；若复发检查边缘 nginx 是否重复压缩 |
| Table tg_users doesn't exist | 并发初始化竞态（已加固：每请求幂等建表）；重试即自愈 |
| 节点不出现在「节点管理」 | 边缘未登记：确认节点 cron/流量存在；或用 install 登记模式补登记 |
| 节点版本标红 | 节点落后于主站：等 cron 自更新或点「推送更新」 |
| 节点推送报 500 | 历史自覆盖 bug（已修复：原子写入）；若复发查节点磁盘权限 |
| 主站 JSON 变 404 页 | .user.ini open_basedir 指向错误路径（每服务器本地文件，已移出仓库） |
