<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EasyLog</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/element-plus/dist/index.css">
</head>
<body>
    <div id="app">
        <div style="display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#0B0E14;color:#E4E7EF;">
            <div style="text-align:center;">
                <div style="width:64px;height:64px;margin:0 auto 16px;border-radius:12px;background:linear-gradient(135deg,#4F7CFF,#A625FF);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:28px;">E</div>
                <h1 style="font-size:24px;margin-bottom:8px;">EasyLog</h1>
                <p style="color:#9CA3B0;">多租户 SaaS 日志服务平台</p>
                <p style="margin-top:24px;">
                    <a href="/api/v1/health" style="color:#4F7CFF;text-decoration:none;font-size:14px;">查看健康检查</a>
                    <span style="color:#6B7180;margin:0 8px;">·</span>
                    <a href="https://github.com/easylog/easylog" style="color:#4F7CFF;text-decoration:none;font-size:14px;">GitHub</a>
                    <span style="color:#6B7180;margin:0 8px;">·</span>
                    <a href="/docs/04-usage-guide.md" style="color:#4F7CFF;text-decoration:none;font-size:14px;">文档</a>
                </p>
                <p style="margin-top:24px;color:#6B7180;font-size:12px;">
                    控制台前端由 <code>frontend/</code> 目录构建，运行 <code>npm run dev</code> 启动
                </p>
            </div>
        </div>
    </div>
    <script>
        fetch('/api/v1/health')
            .then(r => r.json())
            .then(d => console.log('EasyLog health:', d))
            .catch(e => console.warn('Health check failed:', e));
    </script>
</body>
</html>
