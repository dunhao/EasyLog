package cool.cnb.devindun.easylog;

import ch.qos.logback.classic.Level;
import ch.qos.logback.classic.Logger;
import ch.qos.logback.classic.LoggerContext;
import com.sun.net.httpserver.HttpServer;
import cool.cnb.devindun.easylog.logback.EasyLogAppender;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.slf4j.LoggerFactory;
import org.slf4j.MDC;

import java.io.IOException;
import java.net.InetSocketAddress;
import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.concurrent.CopyOnWriteArrayList;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * Logback Appender 桥接测试：程序化挂载 appender，验证日志事件 → EasyLog 字段映射。
 */
class EasyLogAppenderTest {

    private HttpServer server;
    private final List<String> bodies = new CopyOnWriteArrayList<>();
    private Logger logger;

    @BeforeEach
    void setUp() throws IOException {
        server = HttpServer.create(new InetSocketAddress(0), 0);
        server.createContext("/api/v1/ingest/batch", exchange -> {
            byte[] body = exchange.getRequestBody().readAllBytes();
            bodies.add(new String(body, StandardCharsets.UTF_8));
            byte[] resp = "{\"accepted\":1,\"rejected\":0}".getBytes(StandardCharsets.UTF_8);
            exchange.sendResponseHeaders(202, resp.length);
            exchange.getResponseBody().write(resp);
            exchange.close();
        });
        server.start();

        LoggerContext ctx = (LoggerContext) LoggerFactory.getILoggerFactory();
        EasyLogAppender appender = new EasyLogAppender();
        appender.setContext(ctx);
        appender.setName("EASYLOG-TEST");
        appender.setAppId("app_test");
        appender.setAppSecret("sec_test");
        appender.setEndpoint("http://localhost:" + server.getAddress().getPort());
        appender.setTopic("tpc_test");
        appender.setService("order-api");
        appender.setBatchSize(1);
        appender.setFlushSec(1);
        appender.start();

        logger = ctx.getLogger(EasyLogAppenderTest.class);
        logger.setLevel(Level.DEBUG);
        logger.setAdditive(false); // 不向 root 传播，测试输出干净
        logger.addAppender(appender);
    }

    @AfterEach
    void tearDown() {
        logger.detachAndStopAllAppenders();
        MDC.clear();
        server.stop(0);
    }

    @Test
    void logEventBridgesWithFieldsAndMdc() {
        MDC.put("trace_id", "t-123");
        try {
            logger.info("order created");
        } finally {
            MDC.remove("trace_id");
        }

        assertEquals(1, bodies.size());
        String body = bodies.get(0);
        assertTrue(body.contains("\"msg\":\"order created\""));
        assertTrue(body.contains("\"level\":\"INFO\""));
        assertTrue(body.contains("\"service\":\"order-api\""));
        assertTrue(body.contains("\"trace_id\":\"t-123\""));
        assertTrue(body.contains("\"logger\":\"" + getClass().getName() + "\""));
    }

    @Test
    void errorLevelAndExceptionStackAreMapped() {
        logger.error("payment failed", new IllegalStateException("boom"));

        assertEquals(1, bodies.size());
        String body = bodies.get(0);
        assertTrue(body.contains("\"level\":\"ERROR\""));
        assertTrue(body.contains("java.lang.IllegalStateException: boom"));
    }

    @Test
    void debugLevelMapsToDebug() {
        logger.debug("debug detail");
        assertEquals(1, bodies.size());
        assertTrue(bodies.get(0).contains("\"level\":\"DEBUG\""));
    }
}
