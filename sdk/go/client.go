package easylog

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"sync"
	"time"
)

// Client EasyLog 推送客户端：内存缓冲 + 满批/定时投递 + 失败重试与落盘。
//
// 并发安全；Close 前未投递的日志会在最终 Flush 中发出。
type Client struct {
	config *Config
	http   *http.Client

	mu     sync.Mutex
	buffer []LogEntry

	ticker *time.Ticker
	stop   chan struct{}

	wg sync.WaitGroup // in-flight 投递协程

	closed  bool
	closeMu sync.Mutex
}

// NewClient 创建客户端并启动后台定时投递协程。
func NewClient(cfg *Config) *Client {
	httpClient := cfg.HTTPClient
	if httpClient == nil {
		httpClient = &http.Client{Timeout: cfg.Timeout}
	}

	c := &Client{
		config: cfg,
		http:   httpClient,
		stop:   make(chan struct{}),
	}
	c.ticker = time.NewTicker(time.Duration(cfg.FlushSec) * time.Second)
	go c.backgroundFlush()
	return c
}

// Log 写入一条日志到缓冲；满批或缓冲超限时立即投递。
func (c *Client) Log(level, msg string, fields map[string]any) {
	c.LogEntry(NewEntry(msg, level, fields))
}

// LogEntry 写入一个预构造的条目。
func (c *Client) LogEntry(e LogEntry) {
	c.mu.Lock()
	// 缓冲超限保护：直接同步 flush 一批再接收
	if len(c.buffer) >= c.config.BufferMax {
		c.mu.Unlock()
		c.Flush()
		c.mu.Lock()
	}
	c.buffer = append(c.buffer, e)
	full := len(c.buffer) >= c.config.BatchSize
	var batch []LogEntry
	if full {
		batch = c.takeBufferLocked()
	}
	c.mu.Unlock()

	if batch != nil {
		c.dispatch(batch)
	}
}

func (c *Client) Info(msg string, fields map[string]any)  { c.Log("INFO", msg, fields) }
func (c *Client) Warn(msg string, fields map[string]any)  { c.Log("WARN", msg, fields) }
func (c *Client) Error(msg string, fields map[string]any) { c.Log("ERROR", msg, fields) }
func (c *Client) Debug(msg string, fields map[string]any) { c.Log("DEBUG", msg, fields) }

// Flush 把当前缓冲切批并发送（并行多批），阻塞直到全部在途请求完成。
func (c *Client) Flush() {
	c.mu.Lock()
	batch := c.takeBufferLocked()
	c.mu.Unlock()

	if len(batch) > 0 {
		c.dispatchChunks(batch)
	}
	// 满批自动投递的在途请求也要等
	c.wg.Wait()
}

// Close 停止后台协程并投递剩余缓冲；重复调用安全。
func (c *Client) Close() {
	c.closeMu.Lock()
	if c.closed {
		c.closeMu.Unlock()
		return
	}
	c.closed = true
	c.closeMu.Unlock()

	close(c.stop)
	c.Flush()
}

// Heartbeat 向平台发心跳，返回 (ok, httpStatus, body)。
func (c *Client) Heartbeat() (bool, int, string) {
	req, err := http.NewRequest(http.MethodGet, c.config.heartbeatURL(), nil)
	if err != nil {
		return false, 0, err.Error()
	}
	c.setAuthHeaders(req)

	resp, err := c.http.Do(req)
	if err != nil {
		return false, 0, err.Error()
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
	return resp.StatusCode >= 200 && resp.StatusCode < 300, resp.StatusCode, string(body)
}

// ---------- internals ----------

func (c *Client) backgroundFlush() {
	for {
		select {
		case <-c.stop:
			return
		case <-c.ticker.C:
			c.Flush()
		}
	}
}

// takeBufferLocked 取走全部缓冲（调用方持锁）。
func (c *Client) takeBufferLocked() []LogEntry {
	if len(c.buffer) == 0 {
		return nil
	}
	batch := c.buffer
	c.buffer = nil
	return batch
}

// dispatch 切批并行投递，不阻塞调用方（等 Close/Flush 时 wg.Wait 收口）。
func (c *Client) dispatch(entries []LogEntry) {
	c.dispatchChunks(entries)
}

func (c *Client) dispatchChunks(entries []LogEntry) {
	for len(entries) > 0 {
		n := c.config.BatchSize
		if n > len(entries) {
			n = len(entries)
		}
		chunk := entries[:n]
		entries = entries[n:]

		c.wg.Add(1)
		go func(chunk []LogEntry) {
			defer c.wg.Done()
			c.sendWithRetry(chunk)
		}(chunk)
	}
}

// sendWithRetry 投递一批：2xx 成功；4xx（除 429）不重试；5xx/429/网络错误
// 重试 3 次（0.5s/1s/2s 指数退避），耗尽后落本地文件。
func (c *Client) sendWithRetry(entries []LogEntry) {
	payload, err := json.Marshal(map[string]any{
		"logs": toMaps(entries),
	})
	if err != nil {
		c.persistFailures(entries, "marshal: "+err.Error())
		return
	}

	var lastErr string
	for attempt := 0; attempt <= 3; attempt++ {
		if attempt > 0 {
			time.Sleep(time.Duration(500*(1<<(attempt-1))) * time.Millisecond)
		}
		status, err := c.postBatch(payload)
		if err == nil && status >= 200 && status < 300 {
			return
		}
		if err != nil {
			lastErr = err.Error()
		} else {
			lastErr = fmt.Sprintf("HTTP %d", status)
			// 4xx 客户端错误（除 429 限流）不重试；5xx/429 重试
			if status >= 400 && status < 500 && status != 429 {
				break
			}
		}
	}
	c.persistFailures(entries, lastErr)
}

func (c *Client) postBatch(payload []byte) (int, error) {
	req, err := http.NewRequest(http.MethodPost, c.config.batchURL(), bytes.NewReader(payload))
	if err != nil {
		return 0, err
	}
	req.Header.Set("Content-Type", "application/json")
	c.setAuthHeaders(req)

	resp, err := c.http.Do(req)
	if err != nil {
		return 0, err
	}
	defer resp.Body.Close()
	io.Copy(io.Discard, io.LimitReader(resp.Body, 4096))
	return resp.StatusCode, nil
}

func (c *Client) setAuthHeaders(req *http.Request) {
	req.Header.Set("Authorization", "Bearer "+c.config.AppSecret)
	req.Header.Set("X-EasyLog-App", c.config.AppID)
	req.Header.Set("X-EasyLog-Topic", c.config.Topic)
}

func toMaps(entries []LogEntry) []map[string]any {
	out := make([]map[string]any, 0, len(entries))
	for _, e := range entries {
		out = append(out, e.toMap())
	}
	return out
}

// persistFailures 重试耗尽后落本地文件，避免静默丢失（与 PHP SDK 行为一致）。
func (c *Client) persistFailures(entries []LogEntry, reason string) {
	line := fmt.Sprintf(
		"[%s] %s topic=%s count=%d reason=%s\n",
		time.Now().UTC().Format(time.RFC3339),
		c.config.AppID,
		c.config.Topic,
		len(entries),
		reason,
	)
	f, err := os.OpenFile(c.config.FailLogPath, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o644)
	if err != nil {
		log.Printf("easylog: persist failures failed: %v (lost %d entries)", err, len(entries))
		return
	}
	defer f.Close()
	if _, err := f.WriteString(line); err != nil {
		log.Printf("easylog: write fail log failed: %v", err)
	}
}
