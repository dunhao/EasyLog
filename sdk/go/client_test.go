package easylog

import (
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

// captured 汇总测试服务端收到的请求。
type captured struct {
	mu       sync.Mutex
	headers  []http.Header
	bodies   []string
	failNext atomic.Int32 // 剩余失败次数（500）
}

func (c *captured) count() int {
	c.mu.Lock()
	defer c.mu.Unlock()
	return len(c.bodies)
}

func newTestServer(t *testing.T, cap *captured) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		cap.mu.Lock()
		cap.headers = append(cap.headers, r.Header.Clone())
		cap.bodies = append(cap.bodies, string(body))
		cap.mu.Unlock()

		if cap.failNext.Add(-1) >= 0 {
			w.WriteHeader(http.StatusInternalServerError)
			return
		}
		w.WriteHeader(http.StatusAccepted)
		w.Write([]byte(`{"accepted":1,"rejected":0}`))
	}))
	t.Cleanup(srv.Close)
	return srv
}

func newTestClient(t *testing.T, srv *httptest.Server, mutate func(*Config)) *Client {
	t.Helper()
	cfg, err := NewConfig("app_test", "sec_test", srv.URL, "tpc_test")
	if err != nil {
		t.Fatal(err)
	}
	cfg.FailLogPath = filepath.Join(t.TempDir(), "easylog-fail.log")
	if mutate != nil {
		mutate(cfg)
	}
	return NewClient(cfg)
}

func TestIngestHeadersAndPayload(t *testing.T) {
	cap := &captured{}
	srv := newTestServer(t, cap)
	client := newTestClient(t, srv, nil)
	defer client.Close()

	client.Info("order created", map[string]any{"order_id": "A123"})
	client.Flush()

	if cap.count() != 1 {
		t.Fatalf("expected 1 request, got %d", cap.count())
	}
	h := cap.headers[0]
	if got := h.Get("X-EasyLog-App"); got != "app_test" {
		t.Errorf("X-EasyLog-App = %q", got)
	}
	if got := h.Get("Authorization"); got != "Bearer sec_test" {
		t.Errorf("Authorization = %q", got)
	}
	if got := h.Get("X-EasyLog-Topic"); got != "tpc_test" {
		t.Errorf("X-EasyLog-Topic = %q", got)
	}

	var payload struct {
		Logs []map[string]any `json:"logs"`
	}
	if err := json.Unmarshal([]byte(cap.bodies[0]), &payload); err != nil {
		t.Fatal(err)
	}
	if len(payload.Logs) != 1 {
		t.Fatalf("expected 1 log entry, got %d", len(payload.Logs))
	}
	entry := payload.Logs[0]
	if entry["msg"] != "order created" || entry["level"] != "INFO" || entry["order_id"] != "A123" {
		t.Errorf("unexpected entry: %v", entry)
	}
	if _, ok := entry["ts"]; ok {
		t.Errorf("ts should be omitted when not provided (server fills it): %v", entry)
	}
}

func TestFieldsCannotOverrideBuiltins(t *testing.T) {
	e := NewEntry("hello", "warn", map[string]any{"level": "HACK", "msg": "HACK", "ts": "HACK", "user": "u1"})
	m := e.toMap()
	if m["level"] != "WARN" || m["msg"] != "hello" {
		t.Errorf("builtin overwritten: %v", m)
	}
	if m["ts"] != "" && m["ts"] == "HACK" {
		t.Errorf("ts overwritten: %v", m)
	}
	if m["user"] != "u1" {
		t.Errorf("custom field missing: %v", m)
	}
}

func TestBatchingByBatchSize(t *testing.T) {
	cap := &captured{}
	srv := newTestServer(t, cap)

	cfg, _ := NewConfig("app_test", "sec_test", srv.URL, "tpc_test")
	cfg.FailLogPath = filepath.Join(t.TempDir(), "easylog-fail.log")
	cfg.BatchSize = 5
	cfg.BufferMax = 10
	client := NewClient(cfg)

	for i := 0; i < 7; i++ {
		client.Info("msg", map[string]any{"i": i})
	}
	// 满批 5 条立即发出
	client.Flush()
	if cap.count() != 2 {
		t.Fatalf("expected 2 batches (5+2), got %d", cap.count())
	}

	var p1, p2 struct {
		Logs []map[string]any `json:"logs"`
	}
	cap.mu.Lock()
	json.Unmarshal([]byte(cap.bodies[0]), &p1)
	json.Unmarshal([]byte(cap.bodies[1]), &p2)
	cap.mu.Unlock()

	total := len(p1.Logs) + len(p2.Logs)
	if total != 7 {
		t.Fatalf("expected 7 entries total, got %d", total)
	}
	// 并行投递不保证到达顺序，只断言批次大小集合 {5, 2}
	sizes := []int{len(p1.Logs), len(p2.Logs)}
	if (sizes[0] == 5 && sizes[1] == 2) || (sizes[0] == 2 && sizes[1] == 5) {
		// ok
	} else {
		t.Errorf("expected batch sizes {5,2}, got %v", sizes)
	}
}

func TestRetryOn500ThenSuccess(t *testing.T) {
	cap := &captured{}
	srv := newTestServer(t, cap)
	cap.failNext.Store(1) // 第一次 500，之后成功

	cfg, _ := NewConfig("app_test", "sec_test", srv.URL, "tpc_test")
	cfg.FailLogPath = filepath.Join(t.TempDir(), "easylog-fail.log")
	cfg.BatchSize = 1
	client := NewClient(cfg)

	client.Error("flaky", nil)
	client.Flush()

	if cap.count() != 2 {
		t.Fatalf("expected retry (2 requests), got %d", cap.count())
	}
	if _, err := os.Stat(cfg.FailLogPath); !os.IsNotExist(err) {
		t.Errorf("fail log should not exist after successful retry")
	}
}

func TestClientErrorNoRetryWritesFailLog(t *testing.T) {
	cap := &captured{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		cap.mu.Lock()
		cap.bodies = append(cap.bodies, string(body))
		cap.mu.Unlock()
		w.WriteHeader(http.StatusBadRequest)
	}))
	t.Cleanup(srv.Close)

	cfg, _ := NewConfig("app_test", "sec_test", srv.URL, "tpc_test")
	failPath := filepath.Join(t.TempDir(), "easylog-fail.log")
	cfg.FailLogPath = failPath
	cfg.BatchSize = 1
	client := NewClient(cfg)

	client.Warn("bad request", map[string]any{"k": "v"})
	client.Flush()

	cap.mu.Lock()
	n := len(cap.bodies)
	cap.mu.Unlock()
	if n != 1 {
		t.Fatalf("4xx must not retry, got %d requests", n)
	}
	data, err := os.ReadFile(failPath)
	if err != nil {
		t.Fatalf("fail log missing: %v", err)
	}
	if !strings.Contains(string(data), "topic=tpc_test") || !strings.Contains(string(data), "count=1") {
		t.Errorf("unexpected fail log content: %q", string(data))
	}
}

func TestHeartbeat(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			t.Errorf("heartbeat must be GET, got %s", r.Method)
		}
		if r.Header.Get("X-EasyLog-App") != "app_test" {
			t.Errorf("missing app header")
		}
		w.WriteHeader(http.StatusOK)
	}))
	t.Cleanup(srv.Close)

	client := newTestClient(t, srv, nil)
	defer client.Close()

	ok, status, _ := client.Heartbeat()
	if !ok || status != 200 {
		t.Errorf("heartbeat failed: ok=%v status=%d", ok, status)
	}
}

func TestConfigValidation(t *testing.T) {
	if _, err := NewConfig("", "s", "http://x", "t"); err == nil {
		t.Error("missing app_id should fail")
	}
	cfg, _ := NewConfig("a", "s", "http://x/", "t")
	if cfg.Endpoint != "http://x" {
		t.Errorf("endpoint should be right-trimmed, got %q", cfg.Endpoint)
	}
	if _, err := cfg.WithBatchSize(0); err == nil {
		t.Error("batch_size=0 should fail")
	}
	if _, err := cfg.WithBufferMax(1); err == nil { // < batch_size(1000)
		t.Error("buffer_max < batch_size should fail")
	}
	if _, err := cfg.WithFlushSec(0); err == nil {
		t.Error("flush_sec=0 should fail")
	}
}

func TestSlogHandlerBridgesRecords(t *testing.T) {
	cap := &captured{}
	srv := newTestServer(t, cap)
	client := newTestClient(t, srv, nil)
	defer client.Close()

	logger := slog.New(NewSlogHandler(client, slog.LevelInfo)).
		With("service", "order-api")

	logger.Info("order created", "order_id", "A123")
	logger.Debug("hidden below min level")
	client.Flush()

	if cap.count() != 1 {
		t.Fatalf("expected 1 request, got %d", cap.count())
	}
	var payload struct {
		Logs []map[string]any `json:"logs"`
	}
	cap.mu.Lock()
	json.Unmarshal([]byte(cap.bodies[0]), &payload)
	cap.mu.Unlock()

	if len(payload.Logs) != 1 {
		t.Fatalf("expected 1 entry, got %d", len(payload.Logs))
	}
	entry := payload.Logs[0]
	if entry["msg"] != "order created" || entry["level"] != "INFO" {
		t.Errorf("unexpected entry: %v", entry)
	}
	if entry["service"] != "order-api" || entry["order_id"] != "A123" {
		t.Errorf("fields missing: %v", entry)
	}
	if _, ok := entry["ts"].(string); !ok {
		t.Errorf("slog record time should become ts: %v", entry)
	}
}

// 编译期辅助引用，避免 time 导入告警（Flush 间接使用）。
var _ = time.Second
