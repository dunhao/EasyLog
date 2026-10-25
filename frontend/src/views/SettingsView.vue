<script setup lang="ts">
import { onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { auditApi, http, type AuditLog } from '@/api';

// ---- 审计日志 ----
const loading = ref(false);
const logs = ref<AuditLog[]>([]);
const total = ref(0);
const page = ref(1);
const pageSize = ref(20);
const action = ref('');
const targetType = ref('');

async function fetchLogs() {
  loading.value = true;
  try {
    const res = await auditApi.list({
      page: page.value,
      page_size: pageSize.value,
      action: action.value || undefined,
      target_type: targetType.value || undefined,
    });
    logs.value = res.data;
    total.value = res.meta.total;
  } finally {
    loading.value = false;
  }
}

// ---- 系统信息 ----
const health = ref<'checking' | 'ok' | 'down'>('checking');
const healthDetail = ref('');

async function checkHealth() {
  health.value = 'checking';
  try {
    const res = await http.request<{ status: string }>({ method: 'GET', url: '/health' });
    healthDetail.value = `status=${res.status}`;
    health.value = 'ok';
  } catch (e: any) {
    healthDetail.value = e?.message || 'unreachable';
    health.value = 'down';
  }
}

onMounted(() => {
  fetchLogs();
  checkHealth();
});
</script>

<template>
  <div class="page">
    <PageHeader title="平台设置" subtitle="审计日志与系统状态" />

    <el-tabs model-value="audit">
      <el-tab-pane label="审计日志" name="audit">
        <el-card shadow="never">
          <div class="toolbar">
            <el-input v-model="action" placeholder="按操作过滤，如 created" clearable style="width: 180px" />
            <el-input v-model="targetType" placeholder="按目标类型过滤，如 topic" clearable style="width: 200px" />
            <el-button @click="() => { page = 1; fetchLogs(); }">筛选</el-button>
            <el-button @click="fetchLogs">刷新</el-button>
          </div>

          <el-table :data="logs" v-loading="loading" stripe size="small">
            <el-table-column prop="id" label="ID" width="70" />
            <el-table-column prop="user_id" label="操作人ID" width="90" />
            <el-table-column prop="action" label="操作" width="150" show-overflow-tooltip />
            <el-table-column prop="target_type" label="目标类型" width="110" />
            <el-table-column prop="target_id" label="目标ID" min-width="120" show-overflow-tooltip>
              <template #default="{ row }">{{ row.target_id || '—' }}</template>
            </el-table-column>
            <el-table-column prop="ip" label="来源 IP" width="140">
              <template #default="{ row }">{{ row.ip || '—' }}</template>
            </el-table-column>
            <el-table-column label="时间" width="170">
              <template #default="{ row }">{{ (row.created_at || '').replace('T', ' ').slice(0, 19) }}</template>
            </el-table-column>
          </el-table>

          <el-pagination
            v-model:current-page="page"
            v-model:page-size="pageSize"
            :total="total"
            layout="total, prev, pager, next, sizes"
            :page-sizes="[20, 50, 100]"
            style="margin-top: 14px; justify-content: flex-end"
            @current-change="fetchLogs"
            @size-change="() => { page = 1; fetchLogs(); }"
          />
        </el-card>
      </el-tab-pane>

      <el-tab-pane label="系统状态" name="system">
        <el-card shadow="never" style="max-width: 640px">
          <el-descriptions :column="1" border>
            <el-descriptions-item label="控制台版本">v0.1（阶段一）</el-descriptions-item>
            <el-descriptions-item label="后端健康">
              <el-tag :type="health === 'ok' ? 'success' : health === 'down' ? 'danger' : 'info'" size="small">
                {{ health === 'ok' ? '正常' : health === 'down' ? '异常' : '检测中' }}
              </el-tag>
              <span class="health-detail">{{ healthDetail }}</span>
            </el-descriptions-item>
          </el-descriptions>
          <el-button style="margin-top: 14px" @click="checkHealth">重新检测</el-button>
        </el-card>
      </el-tab-pane>
    </el-tabs>
  </div>
</template>

<style scoped lang="scss">
.toolbar {
  display: flex;
  gap: 8px;
  margin-bottom: 14px;
}
.health-detail {
  margin-left: 10px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
</style>
