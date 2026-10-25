package cool.cnb.devindun.easylog;

import com.sun.net.httpserver.HttpServer;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.Test;

import java.io.IOException;
import java.net.InetSocketAddress;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.CopyOnWriteArrayList;
import java.util.concurrent.atomic.AtomicInteger;

import static org.junit.jupiter.api.Assertions.*;

/**
 * 客户端测试：JDK 内置 HttpServer 模拟 EasyLog ingestion 网关。
 */
class EasyLogClientTest {

    private HttpServer server;
    private final List<String> bodies = new CopyOnWriteArrayList<>();
    private final List<Map<String, List<String>>> headers = new CopyOnWriteArrayList<>();
    private final AtomicInteger failNext = new AtomicInteger(0);
    private final AtomicInteger status = new AtomicInteger(202);

    private HttpServer startServer() {
        try {
            server = HttpServer.create(new InetSocketAddress(0), 0);
        } catch (IOException e) {
            throw new RuntimeException(e);
        }
        server.createContext("/api/v1/ingest/batch", exchange -> {
            byte[] body = exchange.getRequestBody().readAllBytes();
            bodies.add(new String(body, StandardCharsets.UTF_8));
            headers.add(exchange.getRequestHeaders());

            if (failNext.getAndDecrement() > 0) {
                exchange.sendResponseHeaders(500, -1);
                exchange.close();
                return;
            }
            byte[] resp = "{\"accepted\":1,\"rejected\":0}".getBytes(StandardCharsets.UTF_8);
            exchange.sendResponseHeaders(status.get(), resp.length);
            exchange.getResponseBody().write(resp);
            exchange.close();
        });
        server.createContext("/api/v1/ingest/heartbeat", exchange -> {
            headers.add(exchange.getRequestHeaders());
            exchange.sendResponseHeaders(200, 2);
            exchange.getResponseBody().write("ok".getBytes(StandardCharsets.UTF_8));
            exchange.close();
        });
        server.start();
        return server;
    }

    @AfterEach
    void tearDown() {
        if (server != null) {
            server.stop(0);
        }
    }

    private EasyLogConfig config() {
        return EasyLogConfig.builder("app_test", "sec_test",
                        "http://localhost:" + server.getAddress().getPort(), "tpc_test")
                .failLogPath(failPath())
                .build();
    }

    private String failPath() {
        try {
            return Files.createTempFile("easylog-fail", ".log").toString();
        } catch (IOException e) {
            throw new RuntimeException(e);
        }
    }

    @Test
    void headersAndPayloadReachServer() {
        startServer();
        try (EasyLogClient client = new EasyLogClient(config())) {
            client.info("order created", Map.of("order_id", "A123"));
            client.flush();
        }

        assertEquals(1, bodies.size());
        Map<String, List<String>> h = headers.get(0);
        assertEquals(List.of("app_test"), h.get("X-EasyLog-App"));
        assertEquals(List.of("Bearer sec_test"), h.get("Authorization"));
        assertEquals(List.of("tpc_test"), h.get("X-EasyLog-Topic"));

        assertTrue(bodies.get(0).contains("\"msg\":\"order created\""));
        assertTrue(bodies.get(0).contains("\"level\":\"INFO\""));
        assertTrue(bodies.get(0).contains("\"order_id\":\"A123\""));
    }

    @Test
    void builtinFieldsCannotBeOverridden() {
        startServer();
        try (EasyLogClient client = new EasyLogClient(config())) {
            Map<String, Object> fields = new HashMap<>();
            fields.put("level", "HACK");
            fields.put("msg", "HACK");
            fields.put("user", "u1");
            client.log("WARN", "real message", fields);
            client.flush();
        }

        String body = bodies.get(0);
        assertTrue(body.contains("\"level\":\"WARN\""));
        assertTrue(body.contains("\"msg\":\"real message\""));
        assertFalse(body.contains("HACK"));
        assertTrue(body.contains("\"user\":\"u1\""));
    }

    @Test
    void batchingByBatchSize() {
        startServer();
        EasyLogConfig cfg = EasyLogConfig.builder("app_test", "sec_test",
                        "http://localhost:" + server.getAddress().getPort(), "tpc_test")
                .batchSize(5).bufferMax(10).failLogPath(failPath()).build();
        try (EasyLogClient client = new EasyLogClient(cfg)) {
            for (int i = 0; i < 7; i++) {
                client.info("msg-" + i, Map.of("i", i));
            }
            client.flush();
        }

        assertEquals(2, bodies.size());
        int total = bodies.stream().mapToInt(b -> countOccurrences(b, "\"msg\":\"msg-")).sum();
        assertEquals(7, total);
    }

    @Test
    void retryOn500ThenSuccess() {
        startServer();
        failNext.set(1);

        EasyLogConfig cfg = EasyLogConfig.builder("app_test", "sec_test",
                        "http://localhost:" + server.getAddress().getPort(), "tpc_test")
                .failLogPath(failPath()).build();
        try (EasyLogClient client = new EasyLogClient(cfg)) {
            client.error("flaky", null);
            client.flush();
        }

        // 第一次 500 + 一次重试成功
        assertEquals(2, bodies.size());
    }

    @Test
    void clientErrorNoRetryWritesFailLog() throws Exception {
        startServer();
        status.set(400);

        String path = failPath();
        EasyLogConfig cfg = EasyLogConfig.builder("app_test", "sec_test",
                        "http://localhost:" + server.getAddress().getPort(), "tpc_test")
                .failLogPath(path).build();
        try (EasyLogClient client = new EasyLogClient(cfg)) {
            client.warn("bad request", Map.of("k", "v"));
            client.flush();
        }

        assertEquals(1, bodies.size());
        String content = Files.readString(Path.of(path));
        assertTrue(content.contains("topic=tpc_test"));
        assertTrue(content.contains("count=1"));
        assertTrue(content.contains("HTTP 400"));
    }

    @Test
    void heartbeatSendsAuthHeaders() {
        startServer();
        try (EasyLogClient client = new EasyLogClient(config())) {
            EasyLogClient.HeartbeatResult result = client.heartbeat();
            assertTrue(result.ok());
            assertEquals(200, result.status());
        }
        assertEquals(1, headers.size());
        assertEquals(List.of("app_test"), headers.get(0).get("X-EasyLog-App"));
    }

    @Test
    void configValidation() {
        assertThrows(IllegalArgumentException.class,
                () -> EasyLogConfig.builder("", "s", "http://x", "t"));
        assertThrows(IllegalArgumentException.class,
                () -> EasyLogConfig.builder("a", "s", "http://x", "t").batchSize(0));
        assertThrows(IllegalArgumentException.class,
                () -> EasyLogConfig.builder("a", "s", "http://x", "t").bufferMax(1));
        assertThrows(IllegalArgumentException.class,
                () -> EasyLogConfig.builder("a", "s", "http://x", "t").flushSec(0));

        EasyLogConfig cfg = EasyLogConfig.builder("a", "s", "http://x/", "t").build();
        assertEquals("http://x", cfg.endpoint());
        assertEquals("http://x/api/v1/ingest/batch", cfg.batchUrl());
    }

    private static int countOccurrences(String haystack, String needle) {
        int count = 0;
        int idx = 0;
        while ((idx = haystack.indexOf(needle, idx)) != -1) {
            count++;
            idx += needle.length();
        }
        return count;
    }
}
