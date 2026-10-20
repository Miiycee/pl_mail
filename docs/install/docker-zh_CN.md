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

## 4. 配置新邮件通知（Webhook / Telegram）

在 plMail 网页中打开 **设置 → 通知**：

1. 启用“新邮件通知”总开关。
2. 选择要使用的渠道：Webhook、Telegram，或同时启用两者。
3. 填写渠道参数并保存，然后点击“发送测试通知”验证连通性。

### Webhook / Message Nest

- Webhook 使用 HTTP POST，正文为 `application/json`。可以将 URL 指向 Message Nest，再由 Message Nest 转发为钉钉群机器人消息。
- 正常新邮件事件的 JSON 结构如下（字段值仅为示例）：

```json
{
  "event": "mail.received",
  "account": "work@example.com",
  "account_id": 12,
  "message_id": 345,
  "from": "张三 <sender@example.net>",
  "from_address": "sender@example.net",
  "from_name": "张三",
  "subject": "会议安排",
  "received_at": "2026-10-20T10:30:00+08:00",
  "snippet": "邮件正文摘要……"
}
```

- 测试通知使用相同的事件名称，并额外包含 `"test": true`。
- 正文摘要最多 500 个字符；邮件同步成功不依赖通知服务是否在线。当前版本不会自动重发失败的外部通知，请使用测试按钮并查看应用日志排查。
- 如果 Message Nest 部署在内网或私有地址，需将其主机名加入 `INTEGRATIONS_ALLOWED_HOSTS` 允许列表。为避免意外跳转，Webhook 请求不会跟随 HTTP 重定向。

### Telegram

1. 在 Telegram 中通过官方 `@BotFather` 创建机器人，并复制 Bot Token。
2. 打开机器人私聊并发送一条消息；若要发到群组，请先把机器人加入群组并按 Telegram 的规则赋予必要权限。
3. 获取目标会话的 Chat ID，将 Bot Token 和 Chat ID 填入 plMail 的通知设置，保存后发送测试通知。
4. Token 会使用 `APP_ENCRYPTION_KEY` 加密后保存。Token 输入框是密码字段；已保存后留空表示保留原值。更换加密密钥可能导致已保存的 Token 无法解密。

通知针对新到达收件箱的邮件，不会因为首次同步就把历史邮件全部推送。Webhook 与 Telegram 可以独立启停；关闭通知总开关后，普通新邮件不会触发通知。

## 5. 备份与注意事项

- 定期备份 PostgreSQL 数据卷以及 `app_secrets` 卷中的生成密钥；邮件凭据和通知凭据依赖密钥解密。
- 不要公开 `.env`、应用密钥、邮箱密码、Webhook URL 中的令牌或 Telegram Bot Token。
- 简体中文界面已覆盖英文翻译文件中的全部翻译键。此分支仍是定制开发分支；部署前请先备份数据库与密钥，并在自己的环境中验证邮箱同步和通知渠道。
- 该配置面向 `linux/amd64`，不承诺 ARM 镜像构建或运行。
