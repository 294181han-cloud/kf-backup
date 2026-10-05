#!/usr/bin/env bash
# cron-node.sh — 分控节点定时任务（纯静态节点版，无需 PHP；建议每 5 分钟）：
#   1) 向总控检查版本 + 壳页指纹并拉取更新包（tar.gz）应用到站点目录（config.php 永不覆盖）
#   2) 心跳登记（IP/角色/版本），主控「节点管理」页即可见
#
# cron 示例（node-setup.sh 会自动安装）：
#   */5 * * * * bash /www/wwwroot/<站点目录>/node/cron-node.sh >> /tmp/cron-node.log 2>&1

set -u
SITE_DIR="${SITE_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$SITE_DIR" || exit 1

# v2.52 修 bug：原来用 grep -o + `sed "s/.*' //"` 取值，sed 贪婪匹配会切错
# （'site_role' => 'visitor' 取出来是 `=> visitor'`），于是请求 URL/角色全是坏的 →
# 脚本自上线起就没成功更新过一次节点。改成按 key 精确取引号里的内容。
cfgval() {
  sed -n "s/.*'$1'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1/p" config.php 2>/dev/null | head -1
}
MAIN_URL=$(cfgval site_main_url)
ROLE=$(cfgval site_role)
SECRET=$(cfgval relay_secret)
VER=$(sed -n "s/.*return[[:space:]]*'\([0-9.]*\)'.*/\1/p" version.php 2>/dev/null | head -1)
REV=$(tr -d '[:space:]' < data/node-codes-rev.txt 2>/dev/null || true)

if [ -z "$MAIN_URL" ] || [ -z "$SECRET" ]; then
  echo "$(date '+%F %T') 跳过：config.php 缺少 site_main_url / relay_secret"
  exit 0
fi

# 1) 检查版本并拉取更新包（static=1 纯静态包；codes_rev 变了即使版本相同也发——新客服码壳页即时生效）
curl -fsS -m 60 -X POST "$MAIN_URL/api/internal/update" \
  -H "Content-Type: application/json" \
  -d "{\"secret\":\"$SECRET\",\"role\":\"$ROLE\",\"app_version\":\"$VER\",\"codes_rev\":\"$REV\",\"static\":1,\"format\":\"tgz\"}" \
  -o /tmp/node-update.tar.gz
if [ $? -eq 0 ] && [ -s /tmp/node-update.tar.gz ]; then
  # v2.60.2 修：原来直接 tar -xzf。两种情况会出事——
  #   ① 总控说"无需更新"时返回的是 JSON，不是 gzip（tar 报 not in gzip format，纯噪音）
  #   ② 下载被截断时 tar 会「先解压一半再报错」：version.php 已更新、app.html 还是旧的，
  #      节点从此卡在旧前端且再也不会自愈（现场踩过两次）。
  # 现在先整包校验（tar -tzf 会读完整个流），校验通过才落地。
  if tar -tzf /tmp/node-update.tar.gz >/dev/null 2>&1; then
    tar -xzf /tmp/node-update.tar.gz -C "$SITE_DIR" && echo "$(date '+%F %T') 已应用更新包"
  else
    echo "$(date '+%F %T') 无更新或更新包不完整（$(head -c 60 /tmp/node-update.tar.gz 2>/dev/null | tr -d '\n' | cut -c1-60)），跳过本次，下轮重试"
  fi
else
  echo "$(date '+%F %T') 无更新或拉取失败"
fi

# 2) 心跳登记（IP/角色/版本；origin 由服务端从请求自行取）
curl -fsS -m 15 -X POST "$MAIN_URL/api/internal/heartbeat" \
  -H "Content-Type: application/json" \
  -d "{\"secret\":\"$SECRET\",\"role\":\"$ROLE\",\"app_version\":\"$VER\"}" \
  || echo "$(date '+%F %T') 心跳失败"
echo "$(date '+%F %T') 节点维护完成 ver=$VER role=$ROLE rev=$REV"
