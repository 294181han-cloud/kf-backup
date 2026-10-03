# node/ — 分控节点部署包（客服入口 / 访客站点通用）

快速把一台新服务器变成 `1+2+n` 架构里的一个分控节点。代码与主站同仓库，
节点唯一差异是 `config.php`（`site_role` / 数据库指向 / 中转密钥）。

## 文件说明

| 文件 | 用途 |
|------|------|
| `config.node.example.php` | 节点配置模板（setup 脚本自动填充） |
| `node-setup.sh` | 一键初始化：拉代码 → 生成 config → 装 cron 心跳 |
| `cron-node.sh` | 定时任务本体：git pull 跟随主站 + 向主站心跳登记 |
| `README.md` | 本文件 |

## 快速开始（新节点服务器上）——网页安装器（推荐）

前置：nginx + PHP 8.4（php-cli、curl、pdo_mysql、mbstring、fileinfo）+ git；
主站 MySQL 已对该节点 IP 授权（3306）；主控管理台已生成节点授权码（一次性）。

```bash
git clone https://github.com/devninawallace/kf.git /www/wwwroot/kf-node
```

然后浏览器打开 `https://<节点域名>/install.php`：

1. 环境自检（PHP / curl / git / 目录可写）
2. 填 主站地址 + 节点授权码，选择角色（访客站点 / 客服入口）
3. 安装器向总控验证授权码（一次性，用后即焚）→ 自动登记节点 + 拉入边缘白名单
   → 生成 config.php（**边缘节点不含数据库凭证**）→ 首次黑名单同步 → 自锁
4. 按安装页给出的 nginx 片段配置 /api/ 反代到总控，crontab 加黑名单同步行

## 快速开始（无头安装，备选）

```bash
SITE_DIR=/www/wwwroot/kf-agent1 \
SITE_ORIGIN=https://agent1.yourdomain.com \
DB_HOST=<主站IP> DB_PASS=<数据库密码> \
ROLE=agent MAIN_URL=https://kf.ylcz.cc \
RELAY_SECRET=<主站 relay_secret> \
bash /tmp/kf-node-src/node/node-setup.sh
```

然后：
1. nginx 建站点（root 指向 SITE_DIR，伪静态与主站相同：非静态请求 → index.php）+ SSL 证书
2. 浏览器打开 `https://<节点域名>/api/health` 确认 `"site_role":"agent|visitor"`
3. 主控管理台 → 「节点管理」页签应能看到该节点（IP、角色、版本、心跳时间）

## 更新机制（总控分发，节点无需 git/GitHub）

- 节点 cron 每 5 分钟执行 `node/update-node.php`：向总控检查版本 → 有新版则拉取更新包并应用
  （更新包不含 config.php / data / uploads，受保护路径永不覆盖）
- 主控「节点管理」页看到版本标红的节点，可点「推送更新」把更新包直接推给该节点
- 节点无需访问 GitHub，也无需 git/shell_exec

## 安全清单

- 客服入口域名不公开传播；访客站点域名全部套 CDN/WAF
- 主站 MySQL 3306 仅对节点 IP 开放（防火墙/安全组）
- 主站域名（admin 管理台所在）不进域名池、不对外公开，建议加 IP 白名单
