<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import type { FormInstance, FormRules } from 'element-plus';
import PageHeader from '@/components/PageHeader.vue';
import {
  shippingApi,
  topicApi,
  type ShippingTask,
  type ShippingTaskPayload,
  type ShippingTargetType,
} from '@/api';
import { useWorkspaceStore } from '@/stores/workspace';

const workspaceStore = useWorkspaceStore();
const currentWsUuid = computed(() => workspaceStore.currentUuid);

const loading = ref(false);
const list = ref<ShippingTask[]>([]);
const total = ref(0);
const page = ref(1);
const pageSize = ref(20);
const topics = ref<{ id: number; name: string }[]>([]);

async function fetchList() {
  if (!currentWsUuid.value) return;
  loading.value = true;
  try {
    const res = await shippingApi.list(currentWsUuid.value, { page: page.value, page_size: pageSize.value });
    list.value = res.data;
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

watch(currentWsUuid, () => {
  page.value = 1;
  fetchList();
  fetchTopics();
});

const topicNameById = (id: number) => topics.value.find((t) => t.id === id)?.name || `topic#${id}`;

// ---- 新建 / 编辑 ----
const dialogVisible = ref(false);
const submitting = ref(false);
const editing = ref<ShippingTask | null>(null);
const formRef = ref<FormInstance>();
const form = reactive<ShippingTaskPayload>({
  name: '',
  topic_id: 0,
  target_type: 'http',
  target_config: {},
  filter: null,
  status: 'active',
});
const configText = ref('{}');

const targetTypes: { value: ShippingTargetType; label: string }[] = [
  { value: 'http', label: 'HTTP Webhook' },
  { value: 'oss', label: '对象存储（OSS）' },
  { value: 'file', label: '文件' },
  { value: 'kafka', label: 'Kafka' },
  { value: 'rabbitmq', label: 'RabbitMQ' },
  { value: 'redis', label: 'Redis Stream' },
  { value: 'activemq', label: 'ActiveMQ' },
];

const rules: FormRules = {
  name: [{ required: true, message: '请输入任务名称', trigger: 'blur' }],
  topic_id: [{ required: true, message: '请选择源主题', trigger: 'change' }],
  target_type: [{ required: true, message: '请选择目标类型', trigger: 'change' }],
};

function openCreate() {
  editing.value = null;
  Object.assign(form, {
    name: '', topic_id: topics.value[0]?.id ?? 0, target_type: 'http',
    target_config: {}, filter: null, status: 'active',
  });
  configText.value = JSON.stringify({ url: 'https://example.com/hook' }, null, 2);
  dialogVisible.value = true;
}

function openEdit(row: ShippingTask) {
  editing.value = row;
  Object.assign(form, {
    name: row.name,
    topic_id: row.topic_id,
    target_type: row.target_type,
    target_config: row.target_config ?? {},
    filter: row.filter,
    status: row.status,
  });
  configText.value = JSON.stringify(row.target_config ?? {}, null, 2);
  dialogVisible.value = true;
}

async function submit() {
  if (!formRef.value || !currentWsUuid.value) return;
  const valid = await formRef.value.validate().catch(() => false);
  if (!valid) return;

  let targetConfig: Record<string, any> = {};
  if (configText.value.trim()) {
    try {
      targetConfig = JSON.parse(configText.value);
    } catch {
      ElMessage.error('目标配置不是合法的 JSON');
      return;
    }
  }

  submitting.value = true;
  try {
    if (editing.value) {
      await shippingApi.update(currentWsUuid.value, editing.value.id, { ...form, target_config: targetConfig });
      ElMessage.success('已更新');
    } else {
      await shippingApi.create(currentWsUuid.value, { ...form, target_config: targetConfig });
      ElMessage.success('已创建');
    }
    dialogVisible.value = false;
    await fetchList();
  } finally {
    submitting.value = false;
  }
}

async function handleDelete(row: ShippingTask) {
  await ElMessageBox.confirm(`删除投递任务「${row.name}」？`, '删除确认', {
    type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消',
  });
  await shippingApi.delete(currentWsUuid.value!, row.id);
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
    <PageHeader title="投递与导出" subtitle="把主题日志持续投递到外部系统">
      <template #actions>
        <el-button type="primary" :disabled="!currentWsUuid" @click="openCreate">
          <el-icon><Plus /></el-icon>新建投递任务
        </el-button>
      </template>
    </PageHeader>

    <el-card shadow="never">
      <el-table :data="list" v-loading="loading" stripe>
        <el-table-column prop="name" label="名称" min-width="150" show-overflow-tooltip />
        <el-table-column label="源主题" min-width="130">
          <template #default="{ row }">{{ topicNameById(row.topic_id) }}</template>
        </el-table-column>
        <el-table-column prop="target_type" label="目标类型" width="120" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 'active' ? 'success' : row.status === 'paused' ? 'info' : 'warning'" size="small">
              {{ ({ active: '投递中', paused: '已暂停', completed: '已完成' } as Record<string, string>)[row.status] ?? row.status }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="170">
          <template #default="{ row }">{{ (row.created_at || '').replace('T', ' ').slice(0, 19) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row as ShippingTask)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row as ShippingTask)">删除</el-button>
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

    <el-dialog v-model="dialogVisible" :title="editing ? '编辑投递任务' : '新建投递任务'" width="600px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
        <el-form-item label="任务名称" prop="name">
          <el-input v-model="form.name" />
        </el-form-item>
        <el-form-item label="源主题" prop="topic_id">
          <el-select v-model="form.topic_id" style="width: 100%">
            <el-option v-for="t in topics" :key="t.id" :label="t.name" :value="t.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="目标类型" prop="target_type">
          <el-select v-model="form.target_type" style="width: 100%">
            <el-option v-for="t in targetTypes" :key="t.value" :label="t.label" :value="t.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="目标配置">
          <el-input v-model="configText" type="textarea" :rows="5" class="config-editor" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" active-value="active" inactive-value="paused"
            active-text="投递中" inactive-text="已暂停" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.config-editor :deep(textarea) {
  font-family: 'SF Mono', Menlo, Consolas, monospace;
  font-size: 12px;
}
</style>
