#!/usr/bin/env bash
#
# sync-github.sh —— 将 cnb 主仓 HEAD 同步到 GitHub 公开镜像（脱敏）
#
# 流程：clone GitHub → 用 cnb HEAD 的 git archive 覆盖 → 删除内部文档 →
#       内网地址/模块路径脱敏 → 敏感词自检（命中即中止）→ 有差异才提交推送
#
# 用法：./scripts/sync-github.sh [GitHub 仓库 URL]
#       默认 https://github.com/dunhao/EasyLog.git
#
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
GITHUB_URL="${1:-https://github.com/dunhao/EasyLog.git}"

# 公开镜像必须排除的内部文档（cnb 保留，GitHub 不发布）
INTERNAL_DOCS=(
  docs/06-completion-plan.md
  docs/07-project-review-2026-09-29.md
  docs/acceptance-192.168.3.3.html
)

# 敏感词自检（历史泄漏过的令牌 / 内网地址 / 私有标识）
SENSITIVE_PATTERNS='192\.168\.3\.|cwG31|OiZn8Fry|Rhg7bKARJU8h|devindun@noreply'

WORK="$(mktemp -d /tmp/easylog-sync-XXXXXX)"
trap 'rm -rf "$WORK"' EXIT

echo ">>> 克隆 GitHub 镜像..."
git clone -q "$GITHUB_URL" "$WORK/repo"

echo ">>> 用 cnb HEAD 覆盖工作区..."
git -C "$ROOT" archive HEAD | tar -x -C "$WORK/repo"

echo ">>> 移除内部文档..."
(cd "$WORK/repo" && rm -f "${INTERNAL_DOCS[@]}")

echo ">>> 脱敏替换..."
python3 - "$WORK/repo" <<'EOF'
import re, sys, pathlib
repo = pathlib.Path(sys.argv[1])

db_files = ['deploy/compose/.env.example', 'deploy/compose/docker-compose.yml']
url_files = ['docs/04-usage-guide.md', 'sdk/go/README.md', 'sdk/go/config.go',
             'sdk/go/integration_test.go', 'sdk/java/README.md',
             'sdk/java/src/main/java/cool/cnb/devindun/easylog/logback/EasyLogAppender.java',
             'sdk/java/src/test/java/cool/cnb/devindun/easylog/EasyLogIntegrationTest.java']
for f in db_files:
    p = repo / f
    if p.exists():
        p.write_text(p.read_text().replace('192.168.3.3', '192.168.1.100'))
for f in url_files:
    p = repo / f
    if p.exists():
        s = p.read_text()
        s = re.sub(r'http://192\.168\.3\.3', 'http://log.example.com', s)
        p.write_text(s.replace('192.168.3.3', 'log.example.com'))

p = repo / 'deploy/compose/start.sh'
if p.exists():
    s = p.read_text()
    s = s.replace('#   - 访问地址里的 HOST IP 可用 EASYLOG_HOST_IP 覆盖，否则自动探测（优先 192.168.3.3）。',
                  '#   - 访问地址里的 HOST IP 可用 EASYLOG_HOST_IP 覆盖，否则自动探测。')
    p.write_text(s.replace('192.168.3.3', '127.0.0.1'))

p = repo / 'README.md'
if p.exists():
    s = p.read_text()
    s = s.replace('- [完善计划（缺口清单/分阶段任务/里程碑对齐）](docs/06-completion-plan.md)\n', '')
    s = re.sub(r'- \[项目复盘.*?\]\(docs/07[^\)]*\)\n', '', s)
    p.write_text(s)

p = repo / 'deploy/README.md'
if p.exists():
    s = p.read_text()
    s = re.sub(r'## CI 凭据.*$',
               '## CI 凭据\n\n构建推送相关访问令牌请在代码托管平台控制台创建与轮换，**不要写入仓库文件**。\n安全报告与生产加固自查清单见 [SECURITY.md](../SECURITY.md)。\n',
               s, flags=re.S)
    p.write_text(s)

for f in ['sdk/go/go.mod', 'sdk/go/README.md', 'docs/04-usage-guide.md']:
    p = repo / f
    if p.exists():
        p.write_text(p.read_text().replace('cnb.cool/devindun/EasyLog/sdk/go', 'github.com/dunhao/EasyLog/sdk/go'))

p = repo / 'README.md'
if p.exists():
    s = p.read_text()
    s = s.replace('- ✅ CI：cnb.cool 流水线（`.cnb.yml`）——后端 phpunit / 前端构建 / Go SDK / Java SDK 四条并行门禁',
                  '- ✅ CI：GitHub Actions（`.github/workflows/ci.yml`）五条并行流水线——后端 phpunit / 前端构建 / Go SDK / Java SDK / Agent 采集器')
    p.write_text(s)
print('  done')
EOF

echo ">>> 敏感词自检..."
if (cd "$WORK/repo" && grep -rIlE "$SENSITIVE_PATTERNS" --exclude-dir=.git --exclude=sync-github.sh . ); then
  echo "❌ 自检失败：上述文件命中敏感词，已中止推送（内容仍在 $WORK 可人工检查）" >&2
  exit 1
fi
echo "  通过（零命中）"

cd "$WORK/repo"
if [ -z "$(git status --porcelain)" ]; then
  echo "✅ GitHub 镜像已是最新，无需推送"
  exit 0
fi

git config user.name "dunhao"
git config user.email "127227517+dunhao@users.noreply.github.com"
git add -A
MSG="sync: 镜像 cnb@$(git -C "$ROOT" rev-parse --short HEAD)（$(git -C "$ROOT" log -1 --format=%s | head -c 60)）"
git commit -q -m "$MSG"
git push origin main
echo "✅ 已推送：$MSG"
