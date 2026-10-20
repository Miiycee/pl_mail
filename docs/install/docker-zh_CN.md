# plMail 简体中文分支：Docker（linux/amd64）部署

本文适用于 x86-64 / AMD64 Linux 主机。此分支的 Compose 配置会从当前源码构建本地镜像 `plmail-custom:local`，并将应用服务和 PostgreSQL 固定为 `linux/amd64`；不会自动拉取上游发布镜像来覆盖本分支修改。

## 1. 准备环境

安装 Docker Engine 和 Docker Compose v2。确认主机架构为 x86-64：

```bash
uname -m
docker compose version
```

`uname -m` 通常应显示 `x86_64`。

## 2. 获取分支并构建启动

```bash
git clone --branch feature/chinese-ui-notifications https://github.com/Miiycee/pl_mail.git
cd pl_mail
docker compose build
docker compose up -d
```

首次构建需要下载依赖，耗时取决于网络和机器性能。查看服务状态及日志：

```bash
docker compose ps
docker compose logs -f --tail=200 php worker
```

然后在浏览器打开 `https://<服务器地址>/`，按页面提示完成初始化。默认 HTTPS 使用自签名证书时，浏览器会提示证书风险；只有确认访问的是自己的服务器后才继续。

## 3. 更新

```bash
git pull --ff-only origin feature/chinese-ui-notifications
docker compose build
docker compose up -d
```

## 4. 备份与注意事项

- 定期备份 PostgreSQL 数据卷以及 `app_secrets` 卷中的生成密钥；邮件凭据依赖密钥解密。
- 不要公开 `.env`、应用密钥、邮箱密码、Webhook URL 中的令牌或 Telegram Bot Token。
- 当前分支正在开发中；简体中文翻译尚未覆盖全部界面，邮件 Webhook / Telegram 通知功能也仍在开发，不能将当前提交视为完整功能版本。
- 该配置面向 `linux/amd64`，不承诺 ARM 镜像构建或运行。
