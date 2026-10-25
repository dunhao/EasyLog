<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import dayjs from 'dayjs';
import PageHeader from '@/components/PageHeader.vue';
import { useEchart } from '@/composables/useEchart';
import { usageApi, type UsageByEndpoint } from '@/api';
import { useWorkspaceStore } from '@/stores/workspace';

const workspaceStore = useWorkspaceStore();
const currentWsUuid = computed(() => workspaceStore.currentUuid);

const loading = ref(false);
const range = ref<[Date, Date]>([dayjs().subtract(6, 'day').toDate(), dayjs().toDate()]);

const daily = ref<{ date: string; bytes: number; count: number }[]>([]);
const byEndpoint = ref<UsageByEndpoint[]>([]);

const { el: chartEl, render: renderChart } = useEchart();

function formatBytes(bytes: number): string {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
  return `${(bytes / 1024 ** i).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

const totalBytes = computed(() => daily.value.reduce((s, d) => s + d.bytes, 0));
const totalCount = computed(() => daily.value.reduce((s, d) => s + d.count, 0));

async function fetchUsage() {
  if (!currentWsUuid.value) return;
  loading.value = true;
  try {
    const from = dayjs(range.value[0]).format('YYYY-MM-DD');
    const to = dayjs(range.value[1]).format('YYYY-MM-DD');
    const [usageRes, epRes] = await Promise.all([
      usageApi.usage(currentWsUuid.value, from, to),
      usageApi.byEndpoint(currentWsUuid.value, from, to),
    ]);
    daily.value = usageRes.data;
    byEndpoint.value = epRes.data;

    renderChart({
      grid: { left: 70, right: 20, top: 34, bottom: 30 },
      tooltip: {
        trigger: 'axis',
        valueFormatter: (v: any) => formatBytes(Number(v)),
      },
      xAxis: { type: 'category', data: daily.value.map((d) => d.date.slice(5)) },
      yAxis: { type: 'value', name: 'MB' },
      series: [{
        type: 'bar',
        barMaxWidth: 32,
        itemStyle: { color: '#4f6df5' },
        data: daily.value.map((d) => Math.round((d.bytes / 1024 / 1024) * 10) / 10),
      }],
    });
  } finally {
    loading.value = false;
  }
}

onMounted(fetchUsage);
</script>

<template>
  <div class="page">
    <PageHeader title="用量统计" subtitle="按天与按接入点的写入量（字节 / 条数）">
      <template #actions>
        <el-date-picker
          v-model="range"
          type="daterange"
          size="small"
          :clearable="false"
          style="width: 260px"
          @change="fetchUsage"
        />
      </template>
    </PageHeader>

    <el-row :gutter="16" style="margin-bottom: 16px">
      <el-col :span="12">
        <el-card shadow="never" v-loading="loading">
          <div class="stat-label">区间写入总量</div>
          <div class="stat-value">{{ formatBytes(totalBytes) }}</div>
        </el-card>
      </el-col>
      <el-col :span="12">
        <el-card shadow="never">
          <div class="stat-label">区间写入总条数</div>
          <div class="stat-value">{{ totalCount.toLocaleString() }}</div>
        </el-card>
      </el-col>
    </el-row>

    <el-card shadow="never" style="margin-bottom: 16px">
      <template #header>按天写入量</template>
      <div v-loading="loading" ref="chartEl" style="height: 280px" />
    </el-card>

    <el-card shadow="never">
      <template #header>按接入点排行</template>
      <el-table :data="byEndpoint" v-loading="loading" stripe size="small">
        <el-table-column label="接入点" min-width="180">
          <template #default="{ row }">{{ row.endpoint_name || row.endpoint_uuid || '已删除接入点' }}</template>
        </el-table-column>
        <el-table-column label="写入量" width="120">
          <template #default="{ row }">{{ formatBytes(row.bytes) }}</template>
        </el-table-column>
        <el-table-column label="条数" width="130">
          <template #default="{ row }">{{ row.count.toLocaleString() }}</template>
        </el-table-column>
      </el-table>
      <el-empty v-if="!loading && !byEndpoint.length" description="区间内无写入数据" />
    </el-card>
  </div>
</template>

<style scoped lang="scss">
.stat-label {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
.stat-value {
  font-size: 22px;
  font-weight: 600;
  margin-top: 6px;
}
</style>
