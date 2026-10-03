#!/usr/bin/env bash
# node-setup.sh — 分控节点一键初始化（在节点服务器上执行）
# 用法：
#   SITE_DIR=/www/wwwroot/kf-agent SITE_ORIGIN=https://agent1.example.com bash node-setup.sh
# 前置：nginx + PHP 8.4(php-cli/curl/pdo_mysql) + git 已装好；DB 已在主站授权
set -euo pipefail

SITE_DIR=${SITE_DIR:-/www/wwwroot/kf-node}
SITE_ORIGIN=${SITE_ORIGIN:-}
DB_HOST=${DB_HOST:-}
DB_PASS=${DB_PASS:-}
ROLE=${ROLE:-}
MAIN_URL=${MAIN_URL:-}
RELAY_SECRET=${RELAY_SECRET:-}
REPO=${REPO:-git@github.com:devninawallace/kf.git}
BRANCH=${BRANCH:-main}

[ -d "$SITE_DIR" ] || { echo "clone $REPO -> $SITE_DIR"; git clone -b "$BRANCH" "$REPO" "$SITE_DIR"; }
cd "$SITE_DIR"

# 生成节点专属 config.php（本地改完 skip-worktree 保护，git pull 不覆盖）
if [ ! -f config.php ]; then
  cp node/config.node.example.php config.php
  sed -i "s|<主站IP或内网地址>|$DB_HOST|; s|<主站授权给该节点的密码>|$DB_PASS|; s|<agent 或 visitor>|$ROLE|; s|https://<主站域名>|$MAIN_URL|; s|<主站 config.php 里的 relay_secret 值>|$RELAY_SECRET|" config.php
  echo "config.php 已生成"
else
  echo "config.php 已存在，跳过生成"
fi

# 本地配置不被后续 git pull 覆盖
git update-index --skip-worktree config.php || true

# 心跳/自动拉码定时任务（每 5 分钟）
CRON_LINE="*/5 * * * * SITE_ORIGIN=$SITE_ORIGIN bash $SITE_DIR/node/cron-node.sh >> /tmp/cron-node.log 2>&1"
( crontab -l 2>/dev/null | grep -vF "cron-node.sh" ; echo "$CRON_LINE" ) | crontab -

# 数据目录
mkdir -p data uploads && chmod -R 775 data uploads 2>/dev/null || true

echo "=============================="
echo "节点初始化完成：$SITE_ORIGIN ($ROLE)"
echo "版本：$(php -r 'echo (string)(require "version.php");')"
echo "验收：curl -s $SITE_ORIGIN/api/health"
echo "nginx：按主站相同伪静态配置本站点（非静态请求 → index.php）"
