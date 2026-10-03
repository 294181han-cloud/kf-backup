#!/usr/bin/env bash
# cron-node.sh — 分控节点定时任务（建议每 5 分钟一次）：
#   1) git pull 跟随主站版本（本地 config.php 用 skip-worktree 保护，不会被覆盖）
#   2) 向主站心跳登记（IP / 角色 / 版本），主控「节点管理」页即可看到
#
# cron 示例（node-setup.sh 会自动安装）：
#   */5 * * * * bash /www/wwwroot/<站点目录>/node/cron-node.sh >> /tmp/cron-node.log 2>&1

set -u
SITE_DIR="${SITE_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$SITE_DIR" || exit 1

# 1) 跟随主站更新代码
git pull --autostash -q 2>&1 | head -3

# 2) 心跳登记
MAIN_URL=$(php -r '$c = require "config.php"; echo rtrim((string)($c["site_main_url"] ?? ""), "/");')
SECRET=$(php -r '$c = require "config.php"; echo (string)($c["relay_secret"] ?? "");')
ROLE=$(php -r '$c = require "config.php"; echo (string)($c["site_role"] ?? "");')
VER=$(php -r 'echo (string)(require "version.php");')

if [ -z "$MAIN_URL" ] || [ -z "$SECRET" ]; then
  echo "$(date '+%F %T') 心跳跳过：site_main_url / relay_secret 未配置"
  exit 0
fi

curl -fsS -m 15 -X POST "$MAIN_URL/api/internal/heartbeat" \
  -H "Content-Type: application/json" \
  -d "{\"secret\":\"$SECRET\",\"role\":\"$ROLE\",\"app_version\":\"$VER\",\"origin\":\"$SITE_ORIGIN\"}" \
  || echo "$(date '+%F %T') 心跳失败"
echo "$(date '+%F %T') 心跳完成 ver=$VER role=$ROLE"
