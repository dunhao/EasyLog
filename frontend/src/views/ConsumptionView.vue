<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import type { FormInstance, FormRules } from 'element-plus';
import PageHeader from '@/components/PageHeader.vue';
import {
  consumptionApi,
  topicApi,
  type ConsumptionGroup,
} from '@/api';
import { useWorkspaceStore } from '@/stores/workspace';

const workspaceStore = useWorkspaceStore();
const currentWsUuid = computed(() => workspaceStore.currentUuid);

const loading = ref(false);
const list = ref<ConsumptionGroup[]>([]);
const total = ref(0);
const page = ref(1);
const pageSize = ref(20);
const topics = ref<{ id: number; name: string }[]>([]);

async function fetchList() {
  if (!currentWsUuid.value) return;
  loading.value = true;
  try {
    const res = await consumptionApi.list(currentWsUuid.value, { page: page.value, page_size: pageSize.value });
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

// ---- 新建 ----
const dialogVisible = ref(false);
const submitting = ref(false);
const formRef = ref<FormInstance>();
const form = reactive({ topic_id: 0, consumer_group: '' });

const rules: FormRules = {
  topic_id: [{ required: true, message: '请选择主题', trigger: 'change' }],
  consumer_group: [{ required: true, message: '请输入消费组名称', trigger: 'blur' }],
};

function openCreate() {
  Object.assign(form, { topic_id: topics.value[0]?.id ?? 0, consumer_group: '' });
  dialogVisible.value = true;
}

async function submit() {
  if (!formRef.value || !currentWsUuid.value) return;
  const valid = await formRef.value.validate().catch(() => false);
  if (!valid) return;
  submitting.value = true;
  try {
    const created = await consumptionApi.create(currentWsUuid.value, { ...form });
    showToken(created.consumer_group, created.auth_token);
    dialogVisible.value = false;
    await fetchList();
  } finally {
    submitting.value = false;
  }
}

// ---- 令牌展示（创建/轮换后仅此一次） ----
const tokenDialog = ref(false);
const tokenInfo = reactive({ group: '', token: '' });

function showToken(group: string, token: string) {
  Object.assign(tokenInfo, { group, token });
  tokenDialog.value = true;
}

async function handleRotate(row: ConsumptionGroup) {
  await ElMessageBox.confirm('轮换后旧 auth_token 立即失效。确认轮换？', '轮换令牌', {
    type: 'warning', confirmButtonText: '轮换', cancelButtonText: '取消',
  });
  const res = await consumptionApi.rotateToken(currentWsUuid.value!, row.id);
  showToken(res.consumer_group, res.auth_token);
}

async function handleDelete(row: ConsumptionGroup) {
  await ElMessageBox.confirm(`删除消费组「${row.consumer_group}」？`, '删除确认', {
    type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消',
  });
  await consumptionApi.delete(currentWsUuid.value!, row.id);
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
    <PageHeader title="消费明细" subtitle="消费组：第三方通过 Bearer Token 拉取主题日志">
      <template #actions>
        <el-button type="primary" :disabled="!currentWsUuid" @click="openCreate">
          <el-icon><Plus /></el-icon>新建消费组
        </el-button>
      </template>
    </PageHeader>

    <el-card shadow="never">
      <el-table :data="list" v-loading="loading" stripe>
        <el-table-column prop="consumer_group" label="消费组" min-width="160" />
        <el-table-column label="订阅主题" min-width="140">
          <template #default="{ row }">{{ topicNameById(row.topic_id) }}</template>
        </el-table-column>
        <el-table-column label="创建时间" width="170">
          <template #default="{ row }">{{ (row.created_at || '').replace('T', ' ').slice(0, 19) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="handleRotate(row as ConsumptionGroup)">轮换令牌</el-button>
            <el-button link type="danger" @click="handleDelete(row as ConsumptionGroup)">删除</el-button>
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

    <el-dialog v-model="dialogVisible" title="新建消费组" width="480px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-form-item label="订阅主题" prop="topic_id">
          <el-select v-model="form.topic_id" style="width: 100%">
            <el-option v-for="t in topics" :key="t.id" :label="t.name" :value="t.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="消费组名" prop="consumer_group">
          <el-input v-model="form.consumer_group" placeholder="如：order-bi-sync" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">确定</el-button>
      </template>
    </el-dialog>

    <el-dialog title="消费令牌（仅展示一次）" v-model="tokenDialog" width="520px">
      <el-alert type="warning" :closable="false" style="margin-bottom: 14px"
        title="请立即保存：auth_token 关闭后不再显示，遗失只能轮换。" />
      <el-descriptions :column="1" border>
        <el-descriptions-item label="消费组">{{ tokenInfo.group }}</el-descriptions-item>
        <el-descriptions-item label="Auth Token">
          <span class="token">{{ tokenInfo.token }}</span>
        </el-descriptions-item>
      </el-descriptions>
      <template #footer>
        <el-button type="primary" @click="tokenDialog = false">我已保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.token {
  font-family: 'SF Mono', Menlo, Consolas, monospace;
  word-break: break-all;
}
</style>
