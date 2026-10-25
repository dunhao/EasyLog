package easylog

import (
	"strings"
	"time"
)

// 内置字段：业务 fields 不允许覆盖（与 PHP SDK / 服务端归一化一致）。
var builtinFields = map[string]bool{
	"level": true, "msg": true, "ts": true,
}

// LogEntry 单条日志的归一化结构。
type LogEntry struct {
	Msg    string
	Level  string // DEBUG/INFO/WARN/ERROR/FATAL/TRACE
	Ts     string // ISO8601；空则由服务端补齐
	Fields map[string]any
}

// NewEntry 创建日志条目；level 为空默认 INFO，统一转大写。
func NewEntry(msg, level string, fields map[string]any) LogEntry {
	level = strings.ToUpper(strings.TrimSpace(level))
	if level == "" {
		level = "INFO"
	}
	return LogEntry{Msg: msg, Level: level, Fields: fields}
}

// toMap 转为服务端 batch 接口期望的结构；fields 不允许覆盖内置字段。
func (e LogEntry) toMap() map[string]any {
	m := map[string]any{
		"level": e.Level,
		"msg":   e.Msg,
	}
	if e.Ts != "" {
		m["ts"] = e.Ts
	}
	for k, v := range e.Fields {
		if !builtinFields[k] {
			m[k] = v
		}
	}
	return m
}

// NowTs 当前时间的 ISO8601 字符串（业务方自定义 ts 时可用）。
func NowTs() string {
	return time.Now().UTC().Format("2006-01-02T15:04:05.000Z07:00")
}
