package cool.cnb.devindun.easylog.logback;

import ch.qos.logback.classic.spi.ILoggingEvent;
import ch.qos.logback.classic.spi.IThrowableProxy;
import ch.qos.logback.classic.spi.ThrowableProxyUtil;
import ch.qos.logback.core.AppenderBase;
import cool.cnb.devindun.easylog.EasyLogClient;
import cool.cnb.devindun.easylog.LogEntry;

import java.util.HashMap;
import java.util.Map;

/**
 * Logback Appender：把应用日志桥接到 EasyLog。
 *
 * logback.xml 用法：
 * <pre>
 * &lt;appender name="EASYLOG" class="cool.cnb.devindun.easylog.logback.EasyLogAppender"&gt;
 *     &lt;appId&gt;app_xxxxxxxx&lt;/appId&gt;
 *     &lt;appSecret&gt;your-secret&lt;/appSecret&gt;
 *     &lt;endpoint&gt;http://log.example.com:9514&lt;/endpoint&gt;
 *     &lt;topic&gt;topic-uuid&lt;/topic&gt;
 *     &lt;service&gt;order-api&lt;/service&gt;
 * &lt;/appender&gt;
 * </pre>
 *
 * 映射规则：
 * - logger 名 → fields.logger；MDC 全量 → fields；service 配置 → fields.service；
 * - 异常堆栈 → fields.exception；
 * - 级别 TRACE/DEBUG→DEBUG、INFO→INFO、WARN→WARN、ERROR→ERROR。
 */
public class EasyLogAppender extends AppenderBase<ILoggingEvent> {

    private String appId;
    private String appSecret;
    private String endpoint;
    private String topic;
    private String service;
    private int batchSize = 100;
    private int flushSec = 1;

    private volatile EasyLogClient client;

    public void setAppId(String appId)       { this.appId = appId; }
    public void setAppSecret(String secret)  { this.appSecret = secret; }
    public void setEndpoint(String endpoint) { this.endpoint = endpoint; }
    public void setTopic(String topic)       { this.topic = topic; }
    public void setService(String service)   { this.service = service; }
    public void setBatchSize(int n)          { this.batchSize = n; }
    public void setFlushSec(int n)           { this.flushSec = n; }

    @Override
    protected void append(ILoggingEvent event) {
        ensureClient();

        Map<String, Object> fields = new HashMap<>();
        if (service != null) {
            fields.put("service", service);
        }
        fields.put("logger", event.getLoggerName());
        Map<String, String> mdc = event.getMDCPropertyMap();
        if (mdc != null) {
            fields.putAll(mdc);
        }
        IThrowableProxy throwable = event.getThrowableProxy();
        if (throwable != null) {
            fields.put("exception", ThrowableProxyUtil.asString(throwable));
        }

        client.log(new LogEntry(
                event.getFormattedMessage(),
                mapLevel(event.getLevel().toString()),
                fields));
    }

    @Override
    public void stop() {
        if (client != null) {
            client.close();
        }
        super.stop();
    }

    private void ensureClient() {
        if (client == null) {
            synchronized (this) {
                if (client == null) {
                    client = new EasyLogClient(
                            cool.cnb.devindun.easylog.EasyLogConfig
                                    .builder(appId, appSecret, endpoint, topic)
                                    .batchSize(batchSize)
                                    .flushSec(flushSec)
                                    .build());
                }
            }
        }
    }

    private static String mapLevel(String logbackLevel) {
        return switch (logbackLevel) {
            case "TRACE", "DEBUG" -> "DEBUG";
            case "INFO" -> "INFO";
            case "WARN" -> "WARN";
            default -> "ERROR"; // ERROR 及未知级别
        };
    }
}
