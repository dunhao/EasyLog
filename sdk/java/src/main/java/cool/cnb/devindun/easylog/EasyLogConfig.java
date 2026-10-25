package cool.cnb.devindun.easylog;

import java.time.Duration;
import java.util.Objects;

/**
 * SDK 配置（不可变，Builder 一次性校验）。
 *
 * 必填：appId / appSecret / endpoint / topic
 * 可选：batchSize(1000) / bufferMax(5000) / flushSec(5) / timeout(2s) / failLogPath
 */
public final class EasyLogConfig {

    private final String appId;
    private final String appSecret;
    private final String endpoint;
    private final String topic;
    private final int batchSize;
    private final int bufferMax;
    private final int flushSec;
    private final Duration timeout;
    private final String failLogPath;

    private EasyLogConfig(Builder b) {
        this.appId = b.appId;
        this.appSecret = b.appSecret;
        this.endpoint = b.endpoint.replaceAll("/+$", "");
        this.topic = b.topic;
        this.batchSize = b.batchSize;
        this.bufferMax = b.bufferMax;
        this.flushSec = b.flushSec;
        this.timeout = b.timeout;
        this.failLogPath = b.failLogPath;
    }

    public String appId()      { return appId; }
    public String appSecret()  { return appSecret; }
    public String endpoint()   { return endpoint; }
    public String topic()      { return topic; }
    public int batchSize()     { return batchSize; }
    public int bufferMax()     { return bufferMax; }
    public int flushSec()      { return flushSec; }
    public Duration timeout()  { return timeout; }
    public String failLogPath() { return failLogPath; }

    public String batchUrl()     { return endpoint + "/api/v1/ingest/batch"; }
    public String heartbeatUrl() { return endpoint + "/api/v1/ingest/heartbeat"; }

    public static Builder builder(String appId, String appSecret, String endpoint, String topic) {
        return new Builder(appId, appSecret, endpoint, topic);
    }

    public static final class Builder {
        private final String appId;
        private final String appSecret;
        private final String endpoint;
        private final String topic;
        private int batchSize = 1000;
        private int bufferMax = 5000;
        private int flushSec = 5;
        private Duration timeout = Duration.ofSeconds(2);
        private String failLogPath = "/tmp/easylog-fail.log";

        private Builder(String appId, String appSecret, String endpoint, String topic) {
            if (isBlank(appId) || isBlank(appSecret) || isBlank(endpoint) || isBlank(topic)) {
                throw new IllegalArgumentException("missing required config (app_id/app_secret/endpoint/topic)");
            }
            this.appId = appId;
            this.appSecret = appSecret;
            this.endpoint = endpoint;
            this.topic = topic;
        }

        public Builder batchSize(int n) {
            if (n < 1 || n > 10000) throw new IllegalArgumentException("batch_size must be between 1 and 10000");
            this.batchSize = n;
            return this;
        }

        public Builder bufferMax(int n) {
            if (n < batchSize) throw new IllegalArgumentException("buffer_max must be >= batch_size");
            this.bufferMax = n;
            return this;
        }

        public Builder flushSec(int n) {
            if (n < 1 || n > 300) throw new IllegalArgumentException("flush_sec must be between 1 and 300");
            this.flushSec = n;
            return this;
        }

        public Builder timeout(Duration d) {
            Objects.requireNonNull(d);
            if (d.isZero() || d.isNegative()) throw new IllegalArgumentException("timeout must be positive");
            this.timeout = d;
            return this;
        }

        public Builder failLogPath(String p) {
            if (isBlank(p)) throw new IllegalArgumentException("fail_log_path must not be empty");
            this.failLogPath = p;
            return this;
        }

        public EasyLogConfig build() {
            return new EasyLogConfig(this);
        }
    }

    private static boolean isBlank(String s) {
        return s == null || s.isBlank();
    }
}
