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

## 快速开始（新节点服务器上）

前置：nginx + PHP 8.4（php-cli、curl、pdo_mysql、mbstring、fileinfo）+ git；
主站 MySQL 已对该节点 IP 授权（3306）；主站 config.php 里的 `relay_secret` 已知。

```bash
git clone https://github.com/devninawallace/kf.git /tmp/kf-node-src
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

## 更新机制

- 节点 cron 每 5 分钟 `git pull` 跟随主站版本 + 心跳登记（版本号自动对账）
- 主控「节点管理」页看到版本标红的节点，可点「推送更新」即时指令拉取
  （节点 PHP 未开放 shell_exec 时按钮会提示走 cron，无需处理）

## 安全清单

- 客服入口域名不公开传播；访客站点域名全部套 CDN/WAF
- 主站 MySQL 3306 仅对节点 IP 开放（防火墙/安全组）
- 主站域名（admin 管理台所在）不进域名池、不对外公开，建议加 IP 白名单
