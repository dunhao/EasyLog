// Package easylog 提供 EasyLog 日志平台的 Go 接入 SDK。
//
// 使用方式：
//
//	cfg, _ := easylog.NewConfig("app_xxx", "secret", "http://easylog:9514", "tpc_uuid")
//	client := easylog.NewClient(cfg)
//	defer client.Close()
//
//	client.Info("order created", map[string]any{"order_id": "A123"})
//
// 或桥接标准库 slog：
//
//	logger := slog.New(easylog.NewSlogHandler(client, slog.LevelInfo))
//	logger.Info("hello", "key", "value")
//
// 行为与 PHP SDK 对齐：内存缓冲、满批/定时触发投递、失败重试 3 次
// （5xx/429/网络错误指数退避，4xx 不重试），重试耗尽落本地文件避免静默丢失。
package easylog

import (
	"fmt"
	"net/http"
	"strings"
	"time"
)

// Config SDK 配置（不可变，NewConfig 一次性校验）。
type Config struct {
	AppID       string
	AppSecret   string
	Endpoint    string // 服务端基地址，如 http://log.example.com:9514
	Topic       string // 目标 topic uuid（X-EasyLog-Topic）
	BatchSize   int    // 满多少条触发投递
	BufferMax   int    // 缓冲上限，超限同步 flush 保护
	FlushSec    int    // 定时投递间隔（秒）
	Timeout     time.Duration
	FailLogPath string // 重试耗尽后落盘路径

	// HTTPClient 可选：测试或自定义代理时注入，nil 用默认
	HTTPClient *http.Client
}

// NewConfig 创建并校验配置（必填：appID/appSecret/endpoint/topic）。
func NewConfig(appID, appSecret, endpoint, topic string) (*Config, error) {
	if appID == "" || appSecret == "" || endpoint == "" || topic == "" {
		return nil, fmt.Errorf("easylog: missing required config (app_id/app_secret/endpoint/topic)")
	}

	cfg := &Config{
		AppID:       appID,
		AppSecret:   appSecret,
		Endpoint:    strings.TrimRight(endpoint, "/"),
		Topic:       topic,
		BatchSize:   1000,
		BufferMax:   5000,
		FlushSec:    5,
		Timeout:     2 * time.Second,
		FailLogPath: "/tmp/easylog-fail.log",
	}
	return cfg, nil
}

// WithBatchSize 设置批量大小（1~10000）。
func (c *Config) WithBatchSize(n int) (*Config, error) {
	if n < 1 || n > 10000 {
		return nil, fmt.Errorf("easylog: batch_size must be between 1 and 10000")
	}
	c.BatchSize = n
	return c, nil
}

// WithBufferMax 设置缓冲上限（必须 >= BatchSize）。
func (c *Config) WithBufferMax(n int) (*Config, error) {
	if n < c.BatchSize {
		return nil, fmt.Errorf("easylog: buffer_max must be >= batch_size")
	}
	c.BufferMax = n
	return c, nil
}

// WithFlushSec 设置定时投递间隔（1~300 秒）。
func (c *Config) WithFlushSec(n int) (*Config, error) {
	if n < 1 || n > 300 {
		return nil, fmt.Errorf("easylog: flush_sec must be between 1 and 300")
	}
	c.FlushSec = n
	return c, nil
}

// WithTimeout 设置单次请求超时。
func (c *Config) WithTimeout(d time.Duration) (*Config, error) {
	if d <= 0 {
		return nil, fmt.Errorf("easylog: timeout must be positive")
	}
	c.Timeout = d
	return c, nil
}

// WithFailLogPath 设置失败落盘路径。
func (c *Config) WithFailLogPath(p string) (*Config, error) {
	if p == "" {
		return nil, fmt.Errorf("easylog: fail_log_path must not be empty")
	}
	c.FailLogPath = p
	return c, nil
}

func (c *Config) batchURL() string     { return c.Endpoint + "/api/v1/ingest/batch" }
func (c *Config) heartbeatURL() string { return c.Endpoint + "/api/v1/ingest/heartbeat" }
