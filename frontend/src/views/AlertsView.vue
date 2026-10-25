<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import type { FormInstance, FormRules } from 'element-plus';
import PageHeader from '@/components/PageHeader.vue';
import {
  alertApi,
  topicApi,
  type AlertRule,
  type AlertRulePayload,
  type AlertEvent,
  type NotifyChannel,
} from '@/api';
import { useWorkspaceStore } from '@/stores/workspace';

const workspaceStore = useWorkspaceStore();
const currentWsUuid = computed(() => workspaceStore.currentUuid);

// ---- 规则列表 ----
const loading = ref(false);
const rules = ref<AlertRule[]>([]);
const total = ref(0);
const page = ref(1);
const pageSize = ref(20);
const topics = ref<{ id: number; name: string }[]>([]);

const severityMeta: Record<string, { label: string; tag: 'info' | 'warning' | 'danger' }> = {
  info: { label: '信息', tag: 'info' },
  warning: { label: '警告', tag: 'warning' },
  critical: { label: '严重', tag: 'danger' },
};

async function fetchList() {
  if (!currentWsUuid.value) return;
  loading.value = true;
  try {
    const res = await alertApi.rules.list(currentWsUuid.value, {
      page: page.value,
      page_size: pageSize.value,
    });
    rules.value = res.data;
    total.value = res.meta.total;
  } finally {
    loading.value = false;
  }
}

async function fetchTopics() {
  if (!currentWsUuid.value) return;
  const res = await topicApi.list(currentWsUuid.value, { page: 1, page_size: 100 });
  topics.value = res.data.map((t) => ({ id: t.id, name: t.name }));
}

const topicNameById = (id: number) => topics.value.find((t) => t.id === id)?.name || `topic#${id}`;

watch(currentWsUuid, () => {
  page.value = 1;
  fetchList();
  fetchTopics();
});

// ---- 新建 / 编辑 ----
const dialogVisible = ref(false);
const submitting = ref(false);
const editing = ref<AlertRule | null>(null);
const formRef = ref<FormInstance>();
// 表单字段全部必填（区别于可省略的 Payload 类型），模板可直接访问 condition.metric
const form = reactive<Required<AlertRulePayload>>({
  name: '',
  topic_id: 0,
  condition: { metric: 'count', baseline_windows: 10, direction: 'both' },
  window_sec: 60,
  threshold: 100,
  severity: 'warning',
  notify_channels: [],
  status: 'active',
});

const channelOptions = ['webhook', 'email', 'sms', 'phone'];
// 渠道编辑用中间态：选中的类型 + 各类型配置
const selectedChannels = ref<string[]>(['webhook']);
const webhookUrl = ref('');
const emailTo = ref('');

function buildChannelPayload(): NotifyChannel[] {
  return selectedChannels.value.map((type) => {
    if (type === 'webhook') return { type, config: { url: webhookUrl.value.trim() } };
    if (type === 'email') return { type, config: { to: emailTo.value.trim() } };
    return { type, config: {} } as NotifyChannel;
  });
}

function loadChannelConfigs(channels: NotifyChannel[] | undefined) {
  selectedChannels.value = (channels ?? []).map((c) => c.type);
  webhookUrl.value = String(channels?.find((c) => c.type === 'webhook')?.config?.url ?? '');
  emailTo.value = String(channels?.find((c) => c.type === 'email')?.config?.to ?? '');
}
const metricOptions = [
  { value: 'count', label: '日志条数' },
  { value: 'rate', label: '错误率' },
  { value: 'anomaly', label: '异常检测' },
];

const rules2: FormRules = {
  name: [{ required: true, message: '请输入规则名称', trigger: 'blur' }],
  topic_id: [{ required: true, message: '请选择监控主题', trigger: 'change' }],
};

function openCreate() {
  editing.value = null;
  Object.assign(form, {
    name: '', topic_id: topics.value[0]?.id ?? 0, condition: { metric: 'count', baseline_windows: 10, direction: 'both' },
    window_sec: 60, threshold: 100, severity: 'warning', notify_channels: [], status: 'active',
  });
  selectedChannels.value = ['webhook'];
  webhookUrl.value = '';
  emailTo.value = '';
  dialogVisible.value = true;
}

function openEdit(row: AlertRule) {
  editing.value = row;
  Object.assign(form, {
    name: row.name,
    topic_id: row.topic_id,
    condition: { ...(row.condition ?? {}) },
    window_sec: row.window_sec,
    threshold: row.threshold,
    severity: row.severity,
    notify_channels: row.notify_channels ?? [],
    status: row.status,
  });
  loadChannelConfigs(row.notify_channels);
  dialogVisible.value = true;
}

async function submit() {
  if (!formRef.value || !currentWsUuid.value) return;
  const valid = await formRef.value.validate().catch(() => false);
  if (!valid) return;
  submitting.value = true;
  try {
    if (editing.value) {
      await alertApi.rules.update(currentWsUuid.value, editing.value.uuid, { ...form, notify_channels: buildChannelPayload() });
      ElMessage.success('已更新');
    } else {
      await alertApi.rules.create(currentWsUuid.value, { ...form, notify_channels: buildChannelPayload() });
      ElMessage.success('已创建');
    }
    dialogVisible.value = false;
    await fetchList();
  } finally {
    submitting.value = false;
  }
}

async function handleDelete(row: AlertRule) {
  await ElMessageBox.confirm(`删除告警规则「${row.name}」？`, '删除确认', {
    type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消',
  });
  await alertApi.rules.delete(currentWsUuid.value!, row.uuid);
  ElMessage.success('已删除');
  await fetchList();
}

// ---- 事件抽屉 ----
const eventDrawer = ref(false);
const eventLoading = ref(false);
const eventRule = ref<AlertRule | null>(null);
const events = ref<AlertEvent[]>([]);
const eventTotal = ref(0);
const eventPage = ref(1);

async function fetchEvents() {
  if (!eventRule.value || !currentWsUuid.value) return;
  eventLoading.value = true;
  try {
    const res = await alertApi.events.list(currentWsUuid.value, eventRule.value.uuid, {
      page: eventPage.value,
      page_size: 20,
    });
    events.value = res.data;
    eventTotal.value = res.meta.total;
  } finally {
    eventLoading.value = false;
  }
}

function openEvents(row: AlertRule) {
  eventRule.value = row;
  eventPage.value = 1;
  eventDrawer.value = true;
  fetchEvents();
}

async function handleResolve(ev: AlertEvent) {
  if (!eventRule.value || !currentWsUuid.value) return;
  await alertApi.events.resolve(currentWsUuid.value, eventRule.value.uuid, ev.uuid);
  ElMessage.success('已标记处理');
  await fetchEvents();
}

onMounted(() => {
  fetchList();
  fetchTopics();
});
</script>

<template>
  <div class="page">
    <PageHeader title="告警" subtitle="基于查询条件的定时规则评估与通知">
      <template #actions>
        <el-button type="primary" :disabled="!currentWsUuid" @click="openCreate">
          <el-icon><Plus /></el-icon>新建规则
        </el-button>
      </template>
    </PageHeader>

    <el-card shadow="never">
      <el-table :data="rules" v-loading="loading" stripe>
        <el-table-column prop="name" label="规则" min-width="150" show-overflow-tooltip />
        <el-table-column label="监控主题" min-width="130">
          <template #default="{ row }">{{ topicNameById(row.topic_id) }}</template>
        </el-table-column>
        <el-table-column label="条件" min-width="180">
          <template #default="{ row }">
            {{ row.condition?.metric === 'rate' ? '错误率' : '日志条数' }}
            ≥ {{ row.threshold }} / {{ row.window_sec }}s
          </template>
        </el-table-column>
        <el-table-column label="级别" width="80">
          <template #default="{ row }">
            <el-tag :type="severityMeta[row.severity]?.tag ?? 'info'" size="small">
              {{ severityMeta[row.severity]?.label ?? row.severity }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="通知渠道" min-width="120">
          <template #default="{ row }">
            {{ (row.notify_channels ?? []).map((c: any) => c.type).join('、') || '—' }}
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">
              {{ row.status === 'active' ? '启用' : '暂停' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="最近触发" width="170">
          <template #default="{ row }">
            {{ row.last_fired_at ? row.last_fired_at.replace('T', ' ').slice(0, 19) : '从未' }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="180" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEvents(row as AlertRule)">事件</el-button>
            <el-button link type="primary" @click="openEdit(row as AlertRule)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row as AlertRule)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-model:current-page="page"
        v-model:page-size="pageSize"
        :total="total"
        layout="total, prev, pager, next, sizes"
        :page-sizes="[10, 20, 50]"
        style="margin-top: 14px; justify-content: flex-end"
        @current-change="fetchList"
        @size-change="() => { page = 1; fetchList(); }"
      />
    </el-card>

    <el-dialog v-model="dialogVisible" :title="editing ? '编辑告警规则' : '新建告警规则'" width="600px">
      <el-form ref="formRef" :model="form" :rules="rules2" label-width="100px">
        <el-form-item label="规则名称" prop="name">
          <el-input v-model="form.name" placeholder="如：订单服务错误激增" />
        </el-form-item>
        <el-form-item label="监控主题" prop="topic_id">
          <el-select v-model="form.topic_id" style="width: 100%">
            <el-option v-for="t in topics" :key="t.id" :label="t.name" :value="t.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="指标">
          <el-select v-model="form.condition.metric" style="width: 160px">
            <el-option v-for="m in metricOptions" :key="m.value" :label="m.label" :value="m.value" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="form.condition.metric === 'anomaly'" label="基线窗口">
          <el-input-number v-model="form.condition.baseline_windows" :min="3" :max="60" />
          <span class="form-unit">个历史窗口参与统计</span>
        </el-form-item>
        <el-form-item v-if="form.condition.metric === 'anomaly'" label="检测方向">
          <el-select v-model="form.condition.direction" style="width: 160px">
            <el-option label="双向（突增/骤降）" value="both" />
            <el-option label="仅突增" value="spike" />
            <el-option label="仅骤降" value="drop" />
          </el-select>
        </el-form-item>
        <el-form-item :label="form.condition.metric === 'anomaly' ? 'σ 倍数' : '触发阈值'">
          <el-input-number v-model="form.threshold" :min="0" />
          <span class="form-unit">{{ form.condition.metric === 'anomaly' ? '（偏离基线多少个标准差，平坦基线下为绝对条数）' : '（窗口内）' }}</span>
        </el-form-item>
        <el-form-item label="统计窗口">
          <el-input-number v-model="form.window_sec" :min="1" :max="86400" />
          <span class="form-unit">秒</span>
        </el-form-item>
        <el-form-item label="告警级别">
          <el-select v-model="form.severity" style="width: 160px">
            <el-option label="信息" value="info" />
            <el-option label="警告" value="warning" />
            <el-option label="严重" value="critical" />
          </el-select>
        </el-form-item>
        <el-form-item label="通知渠道">
          <el-select v-model="selectedChannels" multiple style="width: 100%">
            <el-option v-for="c in channelOptions" :key="c" :label="c" :value="c" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="selectedChannels.includes('webhook')" label="Webhook">
          <el-input v-model="webhookUrl" placeholder="https://hooks.example.com/xxx" />
        </el-form-item>
        <el-form-item v-if="selectedChannels.includes('email')" label="邮件收件">
          <el-input v-model="emailTo" placeholder="多个邮箱用英文逗号分隔" />
        </el-form-item>
        <el-form-item label="启用">
          <el-switch v-model="form.status" active-value="active" inactive-value="paused" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">确定</el-button>
      </template>
    </el-dialog>

    <el-drawer v-model="eventDrawer" :title="`告警事件 · ${eventRule?.name || ''}`" size="640px">
      <el-table :data="events" v-loading="eventLoading" size="small">
        <el-table-column label="触发时间" width="165">
          <template #default="{ row }">{{ (row.fired_at || '').replace('T', ' ').slice(0, 19) }}</template>
        </el-table-column>
        <el-table-column label="级别" width="80">
          <template #default="{ row }">
            <el-tag :type="severityMeta[row.severity]?.tag ?? 'info'" size="small">
              {{ severityMeta[row.severity]?.label ?? row.severity }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="通知" width="80">
          <template #default="{ row }">{{ row.notify_status }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.resolved_at ? 'success' : 'danger'" size="small">
              {{ row.resolved_at ? '已处理' : '未处理' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="90">
          <template #default="{ row }">
            <el-button v-if="!row.resolved_at" link type="primary" @click="handleResolve(row as AlertEvent)">标记处理</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-model:current-page="eventPage"
        :total="eventTotal"
        :page-size="20"
        layout="total, prev, pager, next"
        style="margin-top: 12px; justify-content: flex-end"
        @current-change="fetchEvents"
      />
    </el-drawer>
  </div>
</template>

<style scoped lang="scss">
.form-unit {
  margin-left: 8px;
  color: var(--el-text-color-secondary);
}
</style>
