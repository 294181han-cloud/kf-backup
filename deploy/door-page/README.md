# 门牌页（netlify 免费托管）—— 部署与批量更新

## 这是什么

访客二维码指向的**第一跳**页面：一个只有十几行的静态 `index.html`，做两件事：

1. 把 URL 里 `?xx=<base64url>` 解出来（内容是 `https://<跳板主机>/api/go?e=<入口ID>`），塞进全屏 `iframe`；
2. **听内层报到**：访客页挂载后会 `postMessage({kf:'ready',brand})`；壳页把这条消息**转发**给门牌，
   门牌据此把标题改成客服品牌名、收起「加载太慢？点此直接打开」提示条。

> 关键点：浏览器里 `window.parent.postMessage` **只到直接父窗口**（= 壳页），所以门牌能收到全靠壳页
> 转发一层（`helpers.php` → `gate_loader_html()` 的 message 监听里那段 `window.parent.postMessage`）。
> 只改门牌不改壳页，标题照样不会变 —— 2026-10-12 就是这么踩了一次坑。

## 文件

- `index.html` —— 门牌页本体（拖到 Netlify 即可）。带 `_kfDoor` 标记，批量脚本靠它判断版本。
- `update-doors.js` —— 用 Netlify Personal Access Token **批量更新**账号下所有门牌站。

## 新建一张门牌（3 分钟，免费）

1. 建个空文件夹，把本目录的 `index.html` 拷进去（文件名必须是 `index.html`，这样链接才是 `https://xxx.netlify.app/?xx=…`）；
2. 打开 <https://app.netlify.com/drop>，把文件夹**拖进去**；
3. 拿到形如 `xxxx-yyyy-123456.netlify.app` 的域名；
4. 把域名加进 `helpers.php` 的 `door_ext_hosts()` 数组（一行），提交推送 → 之后发新码会随机用到它。

**注意事项**

- **别绑自家域名**：门牌吃的是大厂域名的信誉，绑自家域名等于把门牌送到和落地同一条线上（代码里也有"同域跳过"防护）。
- **`referrer` 必须是 `origin`**：壳页要靠 `document.referrer` 认出"父页是自家门牌"，写成 `no-referrer` 会被拦。
- **门牌是消耗品**：被举报多了会红 → 所以要**池子**（`door_ext_hosts()` 现在 12 个，建议保持 8~15 个）。
- **`robots noindex`**：别让搜索引擎收录。

## 批量更新已有门牌

```bash
# 1) Netlify → User settings → Applications → Personal access tokens → New access token
# 2) 预演（只读，列出哪些站需要更新）
node update-doors.js <TOKEN> --dry
# 3) 真跑（逐站 zip 部署，域名不变 → 已发出去的二维码一起修好）
node update-doors.js <TOKEN>
# 4) 只想改某几个站
node update-doors.js <TOKEN> --only=regal-blancmange,chimerical
```

脚本行为：列站点 → 逐个拉线上 `index.html` 判断是否已是新版（含 `_kfDoor`）→ 旧版才部署 →
部署后复核。**不删除、不改名任何站点**。用完记得在 Netlify 把令牌删掉。

## ⚠️ 2026-10-12 的两次事故与结论（重要，别再踩）

**事故 1：用 Windows 自带 `tar` 打的 zip 部署后，Netlify 把 `index.html` 当 `text/plain` 发** ——
访客看到的是**整页源码**。原因：`tar -a`（bsdtar）产出的 zip 在 Netlify 侧丢了文件类型信息。
**结论：必须用标准 zip**（UTF-8 文件名 + 正确目录项），本目录 `update-doors.js` 已改用
PowerShell 的 `[System.IO.Compression.ZipFile]::CreateFromDirectory()`。

**事故 2：Netlify Free 计划按 credits 计费，额度用尽后新部署一律 `403 Account credit usage exceeded`。**
（本次 3 天内 12 个站 × 多次部署把额度耗光。）此时**回滚仍然可用**：

```bash
node rollback-doors.js <TOKEN> --list                 # 看每站部署历史
node rollback-doors.js <TOKEN> --rollback=站点名       # 回滚到上一个部署
node rollback-all.js <TOKEN>                          # 批量回滚到今天之前那份
# 或直接 POST /api/v1/sites/<site_id>/deploys/<deploy_id>/restore
```

**结论：门牌站要少而稳**（够分散即可，别每个站频繁重部署），并优先用
`drop/` 目录（含 `_headers` 强制 `Content-Type: text/html`）通过 **Netlify 网页端拖拽部署**
（不消耗 API 额度，且不会出现 text/plain 问题）。


## 改了这个文件之后

正常情况下**不需要动门牌**（标题/重试/超时这些逻辑都在服务端壳页里）。只有门牌自身的
"监听 + 标题"逻辑变了才需要重新部署 —— 用上面的批量脚本一次搞定。
