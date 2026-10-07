"""给 nginx server 块注入「爬虫/扫描器 UA -> 空白页」规则（幂等，带 .bak-bot 备份）。

用法:  python3 bin/add_bot_block.py /www/server/panel/vhost/nginx/edge-shop-domains.conf
       python3 bin/add_bot_block.py /www/server/panel/vhost/nginx/<节点站点>.conf
然后:  nginx -t && nginx -s reload

说明：落地域名（含泛解析子域）有的是纯静态站点，请求根本不经过 PHP，
      所以这层拦截必须放在 nginx 上；PHP 侧（helpers.php gate_is_bot）另有一份兜底。
"""
import re
import shutil
import sys

MARK = "# v2.71 反爬"
HTML = "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>id-0000</title></head><body></body></html>"
REGEX = ("(bot|spider|crawler|slurp|curl|wget|python|java/|go-http|okhttp|headless|phantom|selenium"
         "|puppeteer|playwright|scrapy|httpclient|libwww|httrack|winhttp|aria2|axios|node-fetch|postman"
         "|zgrab|masscan|nmap|nuclei|sqlmap|facebookexternalhit|bytespider|baiduspider|sogou|360spider"
         "|yisou|applebot|petalbot|semrush|ahrefs|censys|shodan|telegrambot|whatsapp)")


def block():
    out = [MARK + "：爬虫/扫描器 UA 直接给空白页（200 空文档，不跳转、不断连接）",
           "# 目的：微信/安全厂商的自动预检拿不到任何内容，只有真人举报才会红",
           "default_type text/html;",
           "set $kf_ua $http_user_agent;",
           'if ($kf_ua ~* "%s") {' % REGEX,
           "    return 200 '%s';" % HTML.replace('"', "&quot;"),
           "}",
           'if ($kf_ua = "") {',
           "    return 200 '%s';" % HTML.replace('"', "&quot;"),
           "}"]
    return "\n".join("    " + ln for ln in out) + "\n"


def main():
    path = sys.argv[1]
    src = open(path, encoding="utf-8", errors="replace").read()
    if MARK in src:
        print("已存在，跳过: " + path)
        return
    shutil.copy2(path, path + ".bak-bot")
    m = re.search(r"^\s*server\s*\{", src, re.M)
    if not m:
        print("没找到 server 块: " + path)
        return
    loc = re.search(r"^\s{0,8}location\s", src[m.end():], re.M)
    pos = m.end() + loc.start() if loc else m.end()
    open(path, "w", encoding="utf-8").write(src[:pos] + "\n" + block() + src[pos:])
    print("已注入: " + path)


if __name__ == "__main__":
    main()
