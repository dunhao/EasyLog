<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import type { FormInstance, FormRules } from 'element-plus';
import PageHeader from '@/components/PageHeader.vue';
import {
  endpointApi,
  topicApi,
  type IngestionEndpoint,
  type Topic,
  type EndpointPayload,
  type SourceType,
} from '@/api';
import { useWorkspaceStore } from '@/stores/workspace';

const workspaceStore = useWorkspaceStore();
const currentWsUuid = computed(() => workspaceStore.currentUuid);

const loading = ref(false);
const list = ref<IngestionEndpoint[]>([]);
const total = ref(0);
const page = ref(1);
const pageSize = ref(20);
const topics = ref<Topic[]>([]);

async function fetchList() {
  if (!currentWsUuid.value) return;
  loading.value = true;
  try {
    const res = await endpointApi.list(currentWsUuid.value, {
      page: page.value,
      page_size: pageSize.value,
    });
    list.value = res.data;
    total.value = res.meta.total;
  } finally {
    loading.value = false;
  }
}

async function fetchTopics() {
  if (!currentWsUuid.value) return;
  const res = await topicApi.list(currentWsUuid.value, { page: 1, page_size: 100 });
  topics.value = res.data;
}

watch(currentWsUuid, () => {
  page.value = 1;
  fetchList();
  fetchTopics();
});

const topicNameById = (id: number) => topics.value.find((t) => t.id === id)?.name || `topic#${id}`;

// ---- 新建 / 编辑 ----
const dialogVisible = ref(false);
const submitting = ref(false);
const editing = ref<IngestionEndpoint | null>(null);
const formRef = ref<FormInstance>();
const form = reactive<EndpointPayload>({
  name: '',
  topic_id: 0,
  source_type: 'http',
  config: {},
  rate_limit_per_sec: 1000,
  daily_quota_gb: 0,
});
const configText = ref('{}');

const sourceTypes: { value: SourceType; label: string }[] = [
  { value: 'http', label: 'HTTP API' },
  { value: 'sdk', label: 'SDK' },
  { value: 'kafka', label: 'Kafka' },
  { value: 'rabbitmq', label: 'RabbitMQ' },
  { value: 'redis', label: 'Redis Stream' },
  { value: 'activemq', label: 'ActiveMQ' },
  { value: 'syslog', label: 'Syslog' },
  { value: 'filebeat', label: 'Filebeat' },
];

// 各 MQ 类型接入点 config 预设（与服务端 validateConfig 必填项对齐）
const configPresets: Partial<Record<SourceType, string>> = {
  kafka: JSON.stringify({ brokers: ['broker:9092'], topic: 'app-logs', group_id: 'easylog' }, null, 2),
  rabbitmq: JSON.stringify({ host: 'rabbitmq', port: 5672, user: 'easylog', password: '', queue: 'app-logs' }, null, 2),
  redis: JSON.stringify({ host: 'redis', port: 6379, stream: 'logs' }, null, 2),
  activemq: JSON.stringify({ host: '', port: 61616, queue: 'app-logs' }, null, 2),
};

const rules: FormRules = {
  name: [{ required: true, message: '请输入接入点名称', trigger: 'blur' }],
  topic_id: [{ required: true, message: '请选择目标主题', trigger: 'change' }],
  source_type: [{ required: true, message: '请选择来源类型', trigger: 'change' }],
};

function openCreate() {
  editing.value = null;
  Object.assign(form, {
    name: '', topic_id: topics.value[0]?.id ?? 0, source_type: 'http',
    config: {}, rate_limit_per_sec: 1000, daily_quota_gb: 0,
  });
  configText.value = '{}';
  dialogVisible.value = true;
}

function openEdit(row: IngestionEndpoint) {
  editing.value = row;
  Object.assign(form, {
    name: row.name,
    topic_id: row.topic_id,
    source_type: row.source_type,
    config: row.config ?? {},
    rate_limit_per_sec: row.rate_limit_per_sec,
    daily_quota_gb: row.daily_quota_gb,
  });
  configText.value = JSON.stringify(row.config ?? {}, null, 2);
  dialogVisible.value = true;
}

function onSourceTypeChange() {
  const preset = configPresets[form.source_type as SourceType];
  if (preset && !editing.value) configText.value = preset;
}

async function submit() {
  if (!formRef.value || !currentWsUuid.value) return;
  const valid = await formRef.value.validate().catch(() => false);
  if (!valid) return;

  let config: Record<string, any> = {};
  if (configText.value.trim()) {
    try {
      config = JSON.parse(configText.value);
    } catch {
      ElMessage.error('config 不是合法的 JSON');
      return;
    }
  }

  submitting.value = true;
  try {
    if (editing.value) {
      await endpointApi.update(currentWsUuid.value, editing.value.uuid, { ...form, config });
      ElMessage.success('已更新');
    } else {
      const created = await endpointApi.create(currentWsUuid.value, { ...form, config });
      showSecret(created.name, created.app_id, created.app_secret);
      await fetchList();
    }
    dialogVisible.value = false;
  } finally {
    submitting.value = false;
  }
}

// ---- 凭据展示（创建/轮换后仅此一次） ----
const secretDialog = ref(false);
const secretInfo = reactive({ name: '', appId: '', appSecret: '' });

function showSecret(name: string, appId: string, appSecret: string) {
  Object.assign(secretInfo, { name, appId, appSecret });
  secretDialog.value = true;
}

async function handleRotate(row: IngestionEndpoint) {
  await ElMessageBox.confirm(
    '轮换后旧 app_secret 立即失效，业务方需更换。确认轮换？',
    '轮换密钥',
    { type: 'warning', confirmButtonText: '轮换', cancelButtonText: '取消' }
  );
  const res = await endpointApi.rotateSecret(currentWsUuid.value!, row.uuid);
  showSecret(row.name, res.app_id, res.app_secret);
}

async function handleTest(row: IngestionEndpoint) {
  const res = await endpointApi.testConnection(currentWsUuid.value!, row.uuid);
  if (res.success) {
    ElMessage.success(`连接成功${res.message ? `：${res.message}` : ''}`);
  } else {
    ElMessage.error(`连接失败：${res.message || '未知错误'}`);
  }
}

async function handleDelete(row: IngestionEndpoint) {
  await ElMessageBox.confirm(`删除接入点「${row.name}」？其业务方接入将立即失效。`, '删除确认', {
    type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消',
  });
  await endpointApi.delete(currentWsUuid.value!, row.uuid);
  ElMessage.success('已删除');
  await fetchList();
}

onMounted(() => {
  fetchList();
  fetchTopics();
});
</script>

<template>
  <div class="page">
    <PageHeader title="接入点" subtitle="日志来源的配置与凭证（AppID + Secret）">
      <template #actions>
        <el-button type="primary" :disabled="!currentWsUuid" @click="openCreate">
          <el-icon><Plus /></el-icon>新建接入点
        </el-button>
      </template>
    </PageHeader>

    <el-card shadow="never">
      <el-table :data="list" v-loading="loading" stripe>
        <el-table-column prop="name" label="名称" min-width="140" show-overflow-tooltip />
        <el-table-column label="目标主题" min-width="130">
          <template #default="{ row }">{{ topicNameById(row.topic_id) }}</template>
        </el-table-column>
        <el-table-column prop="source_type" label="来源类型" width="110" />
        <el-table-column prop="app_id" label="AppID" min-width="150" show-overflow-tooltip />
        <el-table-column label="限流" width="110">
          <template #default="{ row }">{{ row.rate_limit_per_sec }}/s</template>
        </el-table-column>
        <el-table-column label="日配额" width="90">
          <template #default="{ row }">{{ row.daily_quota_gb ? row.daily_quota_gb + ' GB' : '不限' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">
              {{ row.status === 'active' ? '启用' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="240" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="handleTest(row as IngestionEndpoint)">测试</el-button>
            <el-button link type="primary" @click="handleRotate(row as IngestionEndpoint)">轮换密钥</el-button>
            <el-button link type="primary" @click="openEdit(row as IngestionEndpoint)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row as IngestionEndpoint)">删除</el-button>
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

    <el-dialog v-model="dialogVisible" :title="editing ? '编辑接入点' : '新建接入点'" width="600px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px">
        <el-form-item label="名称" prop="name">
          <el-input v-model="form.name" placeholder="如：订单服务接入" />
        </el-form-item>
        <el-form-item label="目标主题" prop="topic_id">
          <el-select v-model="form.topic_id" style="width: 100%" placeholder="选择日志写入的主题">
            <el-option v-for="t in topics" :key="t.id" :label="t.name" :value="t.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="来源类型" prop="source_type">
          <el-select v-model="form.source_type" style="width: 100%" @change="onSourceTypeChange">
            <el-option v-for="s in sourceTypes" :key="s.value" :label="s.label" :value="s.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="接入配置">
          <el-input
            v-model="configText"
            type="textarea"
            :rows="6"
            placeholder='JSON，如 {"brokers": ["broker:9092"]}'
            class="config-editor"
          />
        </el-form-item>
        <el-form-item label="限流">
          <el-input-number v-model="form.rate_limit_per_sec" :min="1" :max="100000" />
          <span class="form-unit">条/秒</span>
        </el-form-item>
        <el-form-item label="日配额">
          <el-input-number v-model="form.daily_quota_gb" :min="0" :max="10240" />
          <span class="form-unit">GB（0 = 不限）</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">确定</el-button>
      </template>
    </el-dialog>

    <el-dialog title="接入凭据（仅展示一次）" v-model="secretDialog" width="520px">
      <el-alert type="warning" :closable="false" style="margin-bottom: 14px"
        title="请立即保存：app_secret 关闭后不再显示，遗失只能轮换密钥。" />
      <el-descriptions :column="1" border>
        <el-descriptions-item label="接入点">{{ secretInfo.name }}</el-descriptions-item>
        <el-descriptions-item label="AppID">{{ secretInfo.appId }}</el-descriptions-item>
        <el-descriptions-item label="AppSecret">
          <span class="secret">{{ secretInfo.appSecret }}</span>
        </el-descriptions-item>
      </el-descriptions>
      <template #footer>
        <el-button type="primary" @click="secretDialog = false">我已保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.form-unit {
  margin-left: 8px;
  color: var(--el-text-color-secondary);
}
.config-editor :deep(textarea) {
  font-family: 'SF Mono', Menlo, Consolas, monospace;
  font-size: 12px;
}
.secret {
  font-family: 'SF Mono', Menlo, Consolas, monospace;
  word-break: break-all;
}
</style>
