<?php

// 注：easylog:consume / easylog:writer / easylog:alert-evaluator /
//     easylog:rollover-index / easylog:dispatch-alert-notifications
//     已由 app/Console/Commands/ 下的命令类实现，此处不得重复注册（同名覆盖）。
//
//     接入网关由 Octane 直接启动（SERVICE_ROLE=ingestion 时 entrypoint 执行
//     `php artisan octane:start`），无需额外的 artisan 启动命令。
