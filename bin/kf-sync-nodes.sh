#!/usr/bin/env bash
# kf-sync-nodes.sh —— v2.91.46 发布流程的收尾步骤：把最新代码同步到所有分控节点
#
# 为什么必须有这一步：
#   租户工作台/访客页实际跑在【分控节点】上（如 123.xiaomiyun.vip、moupz.cn、a2agzgtj.zjknww.shop），
#   节点每 5 分钟才主动向主站拉一次更新包 —— 所以主站发版后，用户那边最多要等 5 分钟才生效。
#   多次出现"我改完了但用户看到还是旧的"，都被误判成"浏览器缓存"，实际是节点没同步。
#   改完版本后跑一次本脚本，立即把所有节点推到最新，省掉那 5 分钟等待。
#
# 用法（在主站上执行）：  bash bin/kf-sync-nodes.sh
set -u

KEY="${KF_NODE_KEY:-/root/.ssh/kf_landing_ed25519}"
MAIN_VER=$(sed -n "s/.*return[[:space:]]*'\([0-9.]*\)'.*/\1/p" "$(dirname "$0")/../version.php" 2>/dev/null | head -1)
[ -z "$MAIN_VER" ] && MAIN_VER=$(sed -n "s/.*return[[:space:]]*'\([0-9.]*\)'.*/\1/p" /www/wwwroot/ad.yluu.top/version.php | head -1)
echo "主站版本: $MAIN_VER"
echo

# 节点清单：<名称>|<IP>|<SSH端口>|<站点目录>
NODES="
落地机A-访客节点|216.167.66.249|45652|/www/wwwroot/a2agzgtj.zjknww.shop
落地机B-工作台节点|23.224.141.164|28397|/www/wwwroot/123.xiaomiyun.vip
落地机B-访客节点|23.224.141.164|28397|/www/wwwroot/moupz.cn
"

ok=0; fail=0
# 注意：ssh 会吃掉循环的 stdin，导致 while 只跑一轮 —— 所以给 ssh 加 -n
while IFS='|' read -r name ip port dir; do
  [ -z "${name:-}" ] && continue
  printf "%-22s %-16s " "$name" "$ip"
  out=$(ssh -n -i "$KEY" -p "$port" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
        -o ConnectTimeout=12 -o BatchMode=yes "root@$ip" \
        "cd '$dir' 2>/dev/null && bash node/cron-node.sh 2>&1 | tail -2" 2>/dev/null)
  ver=$(ssh -n -i "$KEY" -p "$port" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
        -o ConnectTimeout=12 -o BatchMode=yes "root@$ip" \
        "sed -n \"s/.*return[[:space:]]*'\([0-9.]*\)'.*/\1/p\" '$dir/version.php' 2>/dev/null | head -1" 2>/dev/null | tr -d '\r')
  if [ "$ver" = "$MAIN_VER" ]; then
    echo "✔ 已是最新 ($ver)"
  else
    echo "★ 仍是 $ver（期望 $MAIN_VER）"
    echo "$out" | sed 's/^/      /'
  fi
done <<< "$NODES"

echo
echo "提示：若某个节点没同步，可在该节点上手动执行：cd <站点目录> && bash node/cron-node.sh"
