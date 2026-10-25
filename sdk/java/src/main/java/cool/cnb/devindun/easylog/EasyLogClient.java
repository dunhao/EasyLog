package cool.cnb.devindun.easylog;

import java.io.IOException;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardOpenOption;
import java.time.Duration;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ArrayBlockingQueue;
import java.util.concurrent.BlockingQueue;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;

/**
 * EasyLog 推送客户端：内存缓冲 + 满批/定时投递 + 失败重试与落盘。
 *
 * 与 PHP SDK 行为对齐：
 * - 满 batchSize 或 flushSec 到期触发投递；
 * - 重试 3 次（0.5s/1s/2s 指数退避），4xx（除 429）不重试；
 * - 重试耗尽 → 落本地 failLogPath，避免静默丢失。
 */
public final class EasyLogClient implements AutoCloseable {

    private static final int MAX_RETRIES = 3;
    private static final long[] BACKOFF_MS = {500, 1000, 2000};

    private final EasyLogConfig config;
    private final HttpClient http;
    private final ScheduledExecutorService scheduler;
    private final ExecutorService sendPool = Executors.newCachedThreadPool(r -> {
        Thread t = new Thread(r, "easylog-send");
        t.setDaemon(true);
        return t;
    });

    private final BlockingQueue<LogEntry> buffer;

    private volatile boolean closed;

    public EasyLogClient(EasyLogConfig config) {
        this.config = config;
        this.buffer = new ArrayBlockingQueue<>(config.bufferMax());
        this.http = HttpClient.newBuilder()
                .connectTimeout(config.timeout())
                .build();
        this.scheduler = Executors.newSingleThreadScheduledExecutor(r -> {
            Thread t = new Thread(r, "easylog-flush");
            t.setDaemon(true);
            return t;
        });
        this.scheduler.scheduleAtFixedRate(
                this::flush,
                config.flushSec(), config.flushSec(), TimeUnit.SECONDS);
    }

    /** 写入一条日志；缓冲满时阻塞直到有空间（背压保护）。 */
    public void log(String level, String msg, Map<String, Object> fields) {
        log(new LogEntry(msg, level, fields));
    }

    public void log(LogEntry entry) {
        try {
            buffer.put(entry);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            return;
        }
        if (buffer.size() >= config.batchSize()) {
            flush();
        }
    }

    public void info(String msg, Map<String, Object> fields)  { log("INFO", msg, fields); }
    public void warn(String msg, Map<String, Object> fields)  { log("WARN", msg, fields); }
    public void error(String msg, Map<String, Object> fields) { log("ERROR", msg, fields); }
    public void debug(String msg, Map<String, Object> fields) { log("DEBUG", msg, fields); }

    /** 取走全部缓冲，切批并行投递，阻塞直到全部完成。 */
    public synchronized void flush() {
        List<LogEntry> drained = new ArrayList<>();
        buffer.drainTo(drained);
        if (drained.isEmpty()) {
            return;
        }

        int size = config.batchSize();
        List<List<LogEntry>> chunks = new ArrayList<>();
        for (int i = 0; i < drained.size(); i += size) {
            chunks.add(drained.subList(i, Math.min(i + size, drained.size())));
        }

        CountDownLatch latch = new CountDownLatch(chunks.size());
        for (List<LogEntry> chunk : chunks) {
            sendPool.submit(() -> {
                try {
                    sendWithRetry(chunk);
                } finally {
                    latch.countDown();
                }
            });
        }
        await(latch);
    }

    /** 停止定时器并投递剩余缓冲；重复调用安全。 */
    @Override
    public void close() {
        if (closed) {
            return;
        }
        closed = true;
        scheduler.shutdownNow();
        flush();
        sendPool.shutdown();
    }

    /** 心跳：GET /api/v1/ingest/heartbeat。 */
    public HeartbeatResult heartbeat() {
        try {
            HttpRequest req = builder(config.heartbeatUrl())
                    .GET().build();
            HttpResponse<String> resp = http.send(req, HttpResponse.BodyHandlers.ofString());
            return new HeartbeatResult(
                    resp.statusCode() >= 200 && resp.statusCode() < 300,
                    resp.statusCode(), resp.body());
        } catch (IOException | InterruptedException e) {
            if (e instanceof InterruptedException) Thread.currentThread().interrupt();
            return new HeartbeatResult(false, 0, String.valueOf(e.getMessage()));
        }
    }

    public record HeartbeatResult(boolean ok, int status, String body) {}

    // ---------- internals ----------

    private void sendWithRetry(List<LogEntry> entries) {
        String payload = toJson(entries);
        String lastReason = "unknown";

        for (int attempt = 0; attempt <= MAX_RETRIES; attempt++) {
            if (attempt > 0) {
                sleep(BACKOFF_MS[attempt - 1]);
            }
            try {
                HttpRequest req = builder(config.batchUrl())
                        .header("Content-Type", "application/json")
                        .POST(HttpRequest.BodyPublishers.ofString(payload, StandardCharsets.UTF_8))
                        .build();
                HttpResponse<String> resp = http.send(req, HttpResponse.BodyHandlers.ofString());
                int code = resp.statusCode();
                if (code >= 200 && code < 300) {
                    return;
                }
                lastReason = "HTTP " + code;
                // 4xx 客户端错误（除 429 限流）不重试；5xx/429 重试
                if (code >= 400 && code < 500 && code != 429) {
                    break;
                }
            } catch (IOException e) {
                lastReason = String.valueOf(e.getMessage());
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                lastReason = "interrupted";
                break;
            }
        }
        persistFailures(entries, lastReason);
    }

    private HttpRequest.Builder builder(String url) {
        return HttpRequest.newBuilder(URI.create(url))
                .timeout(config.timeout())
                .header("Authorization", "Bearer " + config.appSecret())
                .header("X-EasyLog-App", config.appId())
                .header("X-EasyLog-Topic", config.topic());
    }

    /** 重试耗尽后落本地文件（与 PHP SDK 行为一致）。 */
    private void persistFailures(List<LogEntry> entries, String reason) {
        String line = String.format(
                "[%s] %s topic=%s count=%d reason=%s%n",
                Instant.now(), config.appId(), config.topic(), entries.size(), reason);
        try {
            Files.writeString(
                    Path.of(config.failLogPath()), line,
                    StandardCharsets.UTF_8,
                    StandardOpenOption.CREATE, StandardOpenOption.APPEND);
        } catch (IOException e) {
            System.err.printf("easylog: persist failures failed: %s (lost %d entries)%n",
                    e.getMessage(), entries.size());
        }
    }

    private static void await(CountDownLatch latch) {
        try {
            // 上限兜底：单批最长等待 = 重试总退避 + 若干请求超时
            latch.await(60, TimeUnit.SECONDS);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }

    private static void sleep(long ms) {
        try {
            Thread.sleep(ms);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }

    private static String toJson(List<LogEntry> entries) {
        StringBuilder sb = new StringBuilder("{\"logs\":[");
        for (int i = 0; i < entries.size(); i++) {
            if (i > 0) sb.append(',');
            sb.append(mapToJson(entries.get(i).toMap()));
        }
        return sb.append("]}").toString();
    }

    private static String mapToJson(Map<String, Object> m) {
        StringBuilder sb = new StringBuilder("{");
        boolean first = true;
        for (Map.Entry<String, Object> e : m.entrySet()) {
            if (!first) sb.append(',');
            first = false;
            sb.append(quote(e.getKey())).append(':').append(valueToJson(e.getValue()));
        }
        return sb.append('}').toString();
    }

    private static String valueToJson(Object v) {
        if (v == null) return "null";
        if (v instanceof Number || v instanceof Boolean) return v.toString();
        if (v instanceof Map<?, ?> mv) return mapToJson(castMap(mv));
        return quote(v.toString());
    }

    @SuppressWarnings("unchecked")
    private static Map<String, Object> castMap(Map<?, ?> m) {
        return (Map<String, Object>) m;
    }

    private static String quote(String s) {
        StringBuilder sb = new StringBuilder("\"");
        for (int i = 0; i < s.length(); i++) {
            char c = s.charAt(i);
            switch (c) {
                case '"' -> sb.append("\\\"");
                case '\\' -> sb.append("\\\\");
                case '\n' -> sb.append("\\n");
                case '\r' -> sb.append("\\r");
                case '\t' -> sb.append("\\t");
                default -> {
                    if (c < 0x20) sb.append(String.format("\\u%04x", (int) c));
                    else sb.append(c);
                }
            }
        }
        return sb.append('"').toString();
    }
}
