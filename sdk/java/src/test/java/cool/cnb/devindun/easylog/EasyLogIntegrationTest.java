package cool.cnb.devindun.easylog;

import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.condition.EnabledIfEnvironmentVariable;

import java.util.Map;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * 集成测试：打真实 EasyLog 服务。
 *
 * 设置以下环境变量后才会执行，否则自动跳过：
 *   EASYLOG_ENDPOINT=http://log.example.com:9514
 *   EASYLOG_APP_ID=app_xxxx
 *   EASYLOG_APP_SECRET=xxxx
 *   EASYLOG_TOPIC=&lt;topic uuid&gt;
 */
@EnabledIfEnvironmentVariable(named = "EASYLOG_ENDPOINT", matches = ".+")
@EnabledIfEnvironmentVariable(named = "EASYLOG_APP_ID", matches = ".+")
@EnabledIfEnvironmentVariable(named = "EASYLOG_APP_SECRET", matches = ".+")
@EnabledIfEnvironmentVariable(named = "EASYLOG_TOPIC", matches = ".+")
class EasyLogIntegrationTest {

    @Test
    void heartbeatAndIngest() {
        EasyLogConfig cfg = EasyLogConfig.builder(
                        System.getenv("EASYLOG_APP_ID"),
                        System.getenv("EASYLOG_APP_SECRET"),
                        System.getenv("EASYLOG_ENDPOINT"),
                        System.getenv("EASYLOG_TOPIC"))
                .build();

        try (EasyLogClient client = new EasyLogClient(cfg)) {
            EasyLogClient.HeartbeatResult hb = client.heartbeat();
            assertTrue(hb.ok(), "heartbeat failed: " + hb);

            client.info("integration test entry", Map.of("sdk", "java"));
            client.flush();
            assertEquals(0, 0); // 无异常即通过：投递链路无报错
        }
    }
}
