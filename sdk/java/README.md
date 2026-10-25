# EasyLog Java SDK

EasyLog 日志平台 Java 接入 SDK（JDK 17+，零第三方依赖，可选桥接 Logback）。
行为与 [PHP SDK](../php) 对齐：内存缓冲、满批/定时投递、失败重试 3 次
（5xx/429/网络错误指数退避 0.5s/1s/2s，4xx 不重试），重试耗尽落本地文件。

## 引入

Maven（本地 `mvn install` 后）：

```xml
<dependency>
    <groupId>cool.cnb.devindun</groupId>
    <artifactId>easylog-sdk</artifactId>
    <version>0.1.0</version>
</dependency>
```

## 直接使用客户端

```java
try (EasyLogClient client = new EasyLogClient(
        EasyLogConfig.builder("app_xxxxxxxx", "your-secret",
                        "http://log.example.com:9514", "topic-uuid")
                .batchSize(100)
                .flushSec(1)
                .build())) {

    client.info("order created", Map.of("order_id", "A123"));
    client.error("payment failed", Map.of("reason", "timeout"));
    // close() 时自动投递剩余缓冲
}
```

## 桥接 Logback

`logback.xml`：

```xml
<appender name="EASYLOG" class="cool.cnb.devindun.easylog.logback.EasyLogAppender">
    <appId>app_xxxxxxxx</appId>
    <appSecret>your-secret</appSecret>
    <endpoint>http://log.example.com:9514</endpoint>
    <topic>topic-uuid</topic>
    <service>order-api</service>
    <batchSize>100</batchSize>
    <flushSec>1</flushSec>
</appender>

<logger name="com.example" level="INFO" additivity="false">
    <appender-ref ref="EASYLOG"/>
</logger>
```

映射规则：logger 名与 MDC 全量进 `fields`；异常堆栈进 `fields.exception`；
TRACE/DEBUG→DEBUG、INFO→INFO、WARN→WARN、ERROR→ERROR。

## 配置项

| 项 | 默认 | 说明 |
|---|---|---|
| batchSize | 1000 | 满多少条触发投递（1~10000） |
| bufferMax | 5000 | 缓冲上限（>= batchSize，满了阻塞写入形成背压） |
| flushSec | 5 | 定时投递间隔秒（1~300） |
| timeout | 2s | 单次请求超时（连接 + 读取） |
| failLogPath | /tmp/easylog-fail.log | 重试耗尽后的落盘路径 |

## 测试

```bash
mvn test   # 使用 JDK 内置 HttpServer 模拟网关，无需真实服务
```
