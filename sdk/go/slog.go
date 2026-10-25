package easylog

import (
	"context"
	"log/slog"
)

// EasyLogHandler 把 slog 日志桥接到 EasyLog 客户端。
//
//	logger := slog.New(easylog.NewSlogHandler(client, slog.LevelInfo))
//	logger.Info("order created", "order_id", "A123")
type EasyLogHandler struct {
	client   *Client
	minLevel slog.Level
	attrs    []slog.Attr
	group    string
}

// 编译期保证实现了 slog.Handler 接口。
var _ slog.Handler = (*EasyLogHandler)(nil)

// NewSlogHandler 创建 slog Handler；低于 minLevel 的记录直接丢弃。
func NewSlogHandler(client *Client, minLevel slog.Level) *EasyLogHandler {
	return &EasyLogHandler{client: client, minLevel: minLevel}
}

func (h *EasyLogHandler) Enabled(_ context.Context, level slog.Level) bool {
	return level >= h.minLevel
}

func (h *EasyLogHandler) Handle(_ context.Context, r slog.Record) error {
	fields := make(map[string]any, len(h.attrs)+r.NumAttrs())
	for _, a := range h.attrs {
		h.setField(fields, a, h.group)
	}
	r.Attrs(func(a slog.Attr) bool {
		h.setField(fields, a, h.group)
		return true
	})

	entry := NewEntry(r.Message, levelString(r.Level), fields)
	if !r.Time.IsZero() {
		entry.Ts = r.Time.UTC().Format("2006-01-02T15:04:05.000Z07:00")
	}
	h.client.LogEntry(entry)
	return nil
}

func (h *EasyLogHandler) WithAttrs(attrs []slog.Attr) slog.Handler {
	merged := make([]slog.Attr, 0, len(h.attrs)+len(attrs))
	merged = append(merged, h.attrs...)
	merged = append(merged, attrs...)
	return &EasyLogHandler{client: h.client, minLevel: h.minLevel, attrs: merged, group: h.group}
}

func (h *EasyLogHandler) WithGroup(name string) slog.Handler {
	if name == "" {
		return h
	}
	g := name
	if h.group != "" {
		g = h.group + "." + name
	}
	return &EasyLogHandler{client: h.client, minLevel: h.minLevel, attrs: h.attrs, group: g}
}

// setField 写入单个属性；group 前缀展开，slog.Value 为 Group 时递归。
func (h *EasyLogHandler) setField(fields map[string]any, a slog.Attr, group string) {
	key := a.Key
	if group != "" {
		key = group + "." + a.Key
	}
	v := a.Value.Resolve()
	if v.Kind() == slog.KindGroup {
		for _, sub := range v.Group() {
			h.setField(fields, sub, key)
		}
		return
	}
	fields[key] = v.Any()
}

// levelString slog 级别 → EasyLog 级别（slog 无 FATAL/TRACE）。
func levelString(l slog.Level) string {
	switch {
	case l < slog.LevelInfo:
		return "DEBUG"
	case l < slog.LevelWarn:
		return "INFO"
	case l < slog.LevelError:
		return "WARN"
	default:
		return "ERROR"
	}
}
