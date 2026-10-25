package cool.cnb.devindun.easylog;

import java.time.OffsetDateTime;
import java.time.format.DateTimeFormatter;
import java.util.LinkedHashMap;
import java.util.Map;

/**
 * 单条日志的归一化结构（与 PHP SDK / 服务端约定一致）。
 *
 * msg 必填；level 默认 INFO 统一大写；ts 为 ISO8601（空则服务端补齐）；
 * fields 为业务上下文，不允许覆盖内置字段（level/msg/ts）。
 */
public final class LogEntry {

    private static final Map<String, Boolean> BUILTINS = Map.of("level", true, "msg", true, "ts", true);

    private final String msg;
    private final String level;
    private final String ts;
    private final Map<String, Object> fields;

    public LogEntry(String msg, String level, Map<String, Object> fields) {
        this(msg, level, null, fields);
    }

    public LogEntry(String msg, String level, String ts, Map<String, Object> fields) {
        this.msg = msg == null ? "" : msg;
        this.level = normalizeLevel(level);
        this.ts = (ts == null || ts.isBlank()) ? null : ts;
        this.fields = fields == null ? Map.of() : fields;
    }

    public String msg()    { return msg; }
    public String level()  { return level; }
    public String ts()     { return ts; }
    public Map<String, Object> fields() { return fields; }

    /** 转为服务端 batch 接口期望的 JSON 结构；fields 不允许覆盖内置字段。 */
    public Map<String, Object> toMap() {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("level", level);
        m.put("msg", msg);
        if (ts != null) {
            m.put("ts", ts);
        }
        for (Map.Entry<String, Object> e : fields.entrySet()) {
            if (!BUILTINS.containsKey(e.getKey())) {
                m.put(e.getKey(), e.getValue());
            }
        }
        return m;
    }

    public static String normalizeLevel(String level) {
        String l = level == null ? "" : level.trim().toUpperCase();
        return l.isEmpty() ? "INFO" : l;
    }

    /** 当前时间 ISO8601（业务方自定义 ts 时可用）。 */
    public static String nowTs() {
        return OffsetDateTime.now().format(DateTimeFormatter.ISO_OFFSET_DATE_TIME);
    }
}
