# 安全策略

## 报告漏洞

发现安全问题请**私下**报告：

1. 在 GitHub 上通过 Security Advisories（Repository → Security → Report a vulnerability）提交；或
2. 邮件联系仓库所有者（见仓库 Profile）。

请勿通过公开 Issue / PR / Discussion 披露未修复的安全问题。我们会在 72 小时内响应。

## 支持版本

| 版本 | 支持状态 |
|---|---|
| main / 最新 release | ✅ |
| 更早版本 | ❌ |

## 部署安全基线（生产前自查）

- [ ] 修改默认管理员密码（`EASYLOG_ADMIN_PASSWORD`），禁用 seed 演示账号
- [ ] 轮换接入点 AppSecret（控制台 → 接入点 → 密钥轮换）
- [ ] `APP_DEBUG=false`，`APP_ENV=production`
- [ ] 接入网关（ingestion :9514）不暴露公网，走内网/网关白名单
- [ ] MySQL/Redis/ES 仅内网可达；凭据走环境变量或密钥管理，不落仓库
- [ ] `deploy/compose/.env` 与 `easylog-agent.json` 权限 600
- [ ] HTTPS（Ingress/网关层终止 TLS）
