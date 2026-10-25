EasyLog 告警事件
----------------------------------------
规则：{{ $event->rule?->name ?? '-' }}
级别：{{ strtoupper($event->severity) }}
触发时间：{{ $event->fired_at?->format('Y-m-d H:i:s') ?? '-' }}
事件 ID：{{ $event->uuid }}

上下文：
{{ json_encode($event->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}
