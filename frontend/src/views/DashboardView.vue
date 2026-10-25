<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import dayjs from 'dayjs';
import PageHeader from '@/components/PageHeader.vue';
import { useEchart } from '@/composables/useEchart';
import {
  alertApi,
  topicApi,
  usageApi,
  workspaceApi,
} from '@/api';
import { useWorkspaceStore } from '@/stores/workspace';

const workspaceStore = useWorkspaceStore();
const currentWsUuid = computed(() => workspaceStore.currentUuid);

const loading = ref(false);
const wsCount = ref(0);
const topicCount = ref(0);
const activeAlerts = ref(0);
const todayBytes = ref(0);
const todayCount = ref(0);

const { el: usageEl, render: renderUsage } = useEchart();

function formatBytes(bytes: number): string {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
  return `${(bytes / 1024 ** i).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

async function fetchOverview() {
  if (!currentWsUuid.value) return;
  loading.value = true;
  try {
    // 工作空间 / 主题数（当前租户）
    const [wss, topicsRes] = await Promise.all([
      workspaceApi.list({ page: 1, page_size: 1 }),
      topicApi.list(currentWsUuid.value, { page: 1, page_size: 1 }),
    ]);
    wsCount.value = wss.meta.total;
    topicCount.value = topicsRes.meta.total;

    // 未处理告警事件（逐规则聚合，规则数一般不多）
    const rulesRes = await alertApi.rules.list(currentWsUuid.value, { page: 1, page_size: 100 });
    let unresolved = 0;
    await Promise.all(
      rulesRes.data.map(async (rule) => {
        try {
          const evs = await alertApi.events.list(currentWsUuid.value!, rule.uuid, { page: 1, page_size: 1 });
          // 事件列表无 resolved 过滤参数，本地粗算：总数即可展示活跃度
          unresolved += evs.meta.total;
        } catch {
          // 忽略单规则失败
        }
      })
    );
    activeAlerts.value = unresolved;

    // 近 7 天用量
    const to = dayjs();
    const from = to.subtract(6, 'day');
    const usageRes = await usageApi.usage(
      currentWsUuid.value,
      from.format('YYYY-MM-DD'),
      to.format('YYYY-MM-DD')
    );
    const byDate = new Map(usageRes.data.map((d) => [d.date, d]));
    const today = to.format('YYYY-MM-DD');
    todayBytes.value = byDate.get(today)?.bytes ?? 0;
    todayCount.value = byDate.get(today)?.count ?? 0;

    const days = Array.from({ length: 7 }, (_, i) => from.add(i, 'day').format('YYYY-MM-DD'));
    renderUsage({
      grid: { left: 70, right: 20, top: 30, bottom: 30 },
      tooltip: { trigger: 'axis' },
      xAxis: { type: 'category', data: days.map((d) => d.slice(5)) },
      yAxis: { type: 'value', name: 'MB' },
      series: [{
        type: 'line',
        smooth: true,
        areaStyle: { opacity: 0.15 },
        itemStyle: { color: '#4f6df5' },
        data: days.map((d) => Math.round(((byDate.get(d)?.bytes ?? 0) / 1024 / 1024) * 10) / 10),
      }],
    });
  } finally {
    loading.value = false;
  }
}

onMounted(fetchOverview);
</script>

<template>
  <div class="page">
    <PageHeader title="仪表盘" subtitle="当前租户的写入量、主题规模与告警概况">
      <template #actions>
        <el-button size="small" :loading="loading" @click="fetchOverview">
          <el-icon><Refresh /></el-icon>刷新
        </el-button>
      </template>
    </PageHeader>

    <el-row :gutter="16">
      <el-col :span="6">
        <el-card shadow="never" v-loading="loading">
          <div class="stat-label">今日写入</div>
          <div class="stat-value">{{ formatBytes(todayBytes) }}</div>
          <div class="stat-sub">{{ todayCount.toLocaleString() }} 条</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never">
          <div class="stat-label">工作空间</div>
          <div class="stat-value">{{ wsCount }}</div>
          <div class="stat-sub">当前租户</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never">
          <div class="stat-label">日志主题</div>
          <div class="stat-value">{{ topicCount }}</div>
          <div class="stat-sub">当前工作空间</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never">
          <div class="stat-label">告警事件</div>
          <div class="stat-value">{{ activeAlerts }}</div>
          <div class="stat-sub">累计触发</div>
        </el-card>
      </el-col>
    </el-row>

    <el-card shadow="never" style="margin-top: 16px">
      <template #header>近 7 天写入量（当前工作空间）</template>
      <div v-loading="loading" ref="usageEl" style="height: 280px" />
    </el-card>
  </div>
</template>

<style scoped lang="scss">
.stat-label {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
.stat-value {
  font-size: 24px;
  font-weight: 600;
  margin-top: 6px;
}
.stat-sub {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  margin-top: 4px;
}
</style>
