package easylog

import (
	"os"
	"testing"
)

// 集成测试：打真实 EasyLog 服务。
// 设置以下环境变量后 go test 才会执行，否则自动跳过：
//
//	EASYLOG_ENDPOINT=http://log.example.com:9514
//	EASYLOG_APP_ID=app_xxxx
//	EASYLOG_APP_SECRET=xxxx
//	EASYLOG_TOPIC=<topic uuid>
func integrationEnv(t *testing.T) *Config {
	t.Helper()
	endpoint := os.Getenv("EASYLOG_ENDPOINT")
	appID := os.Getenv("EASYLOG_APP_ID")
	secret := os.Getenv("EASYLOG_APP_SECRET")
	topic := os.Getenv("EASYLOG_TOPIC")
	if endpoint == "" || appID == "" || secret == "" || topic == "" {
		t.Skip("integration env not set (EASYLOG_ENDPOINT/APP_ID/APP_SECRET/TOPIC)")
	}
	cfg, err := NewConfig(appID, secret, endpoint, topic)
	if err != nil {
		t.Fatal(err)
	}
	return cfg
}

func TestIntegrationIngest(t *testing.T) {
	cfg := integrationEnv(t)
	client := NewClient(cfg)
	defer client.Close()

	ok, status, body := client.Heartbeat()
	if !ok {
		t.Fatalf("heartbeat failed: status=%d body=%s", status, body)
	}

	client.Info("integration test entry", map[string]any{
		"sdk":     "go",
		"test_ts": NowTs(),
	})
	client.Flush()
}
