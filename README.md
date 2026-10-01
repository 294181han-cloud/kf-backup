# 多租户客服系统（kfxt）

平台管理员生成「卡密账户」分发给客户；每个卡密即一个完全隔离的独立客服系统（含子客服、话术、获客链接、额度计费）。主要用于微信等平台的引流接待。

## 快速部署（宝塔 / Nginx / PHP 8.4 + MySQL 8）

1. 站点根目录 clone 本仓库
2. 复制 `config.example.php` 为 `config.php`，填入数据库凭据
3. 确保 `uploads/`、`data/` 目录可写（首次访问自动创建并建表）
4. Nginx：非静态请求 rewrite 到 `index.php`；`assets/`、`uploads/` 直接命中静态（宝塔 PHP 站点默认配置即可）
5. 访问 `/login.html`，默认管理员 `admin / 123456`（**务必立即修改**）

> 更新：`git pull` 即生效（config.php / uploads / data 均不入库，不受影响）

## 目录结构

```
├── index.php            # 唯一入口 + 路由 + 自动安装/升级
├── config.example.php   # 配置模板（真实 config.php 不入库）
├── db.php               # PDO + 建表 + 种子
├── helpers.php          # JWT / 上传 / 限频 / IP 归属地
├── routes/              # auth / visitor / agent / tenant / admin
├── public/              # login / app / admin / visitor 四个页面
├── assets/              # api.js、qr-poster.js、vendor/
├── selftest.ps1         # 全流程回归测试（112 项，幂等可重跑）
└── DEPLOY.md            # 详细部署与运维文档（含 v1~v5 变更记录）
```

## 角色与登录

| 角色 | 账号格式 | 入口 |
|------|---------|------|
| 平台管理员 | `admin` | /admin.html |
| 租户（卡密账户） | `卡密` | /app.html |
| 子客服 | `卡密/用户名` | /app.html |
| 访客 | 无需登录 | /visitor.html?t=卡密 或 ?code=客服码 |

## 回归测试

```powershell
powershell -ExecutionPolicy Bypass -File selftest.ps1
```

> 注意：测试会创建并清理测试租户（快照-恢复模式，不动真实数据）；生产环境谨慎运行。
