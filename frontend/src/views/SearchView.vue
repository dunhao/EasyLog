<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import dayjs from 'dayjs';
import PageHeader from '@/components/PageHeader.vue';
import { useEchart } from '@/composables/useEchart';
import {
  searchApi,
  topicApi,
  type SearchHit,
  type SearchResult,
  type HistogramBucketUnit,
  type AggType,
} from '@/api';
import { useWorkspaceStore } from '@/stores/workspace';

const workspaceStore = useWorkspaceStore();
const currentWsUuid = computed(() => workspaceStore.currentUuid);

// ---- 查询状态 ----
const queryInput = ref('');
const range = ref('6h');
const customRange = ref<[Date, Date] | null>(null);
const levels = ref<string[]>([]);
const size = ref(200);
const topics = ref<{ uuid: string; name: string }[]>([]);
const topicUuid = ref(''); // 为空 = 跨主题联邦检索

const rangePresets: Record<string, [number, string]> = {
  '5min': [5, 'minute'],
  '1h': [1, 'hour'],
  '6h': [6, 'hour'],
  '24h': [24, 'hour'],
  '7d': [7, 'day'],
};

const fromIso = computed(() => {
  if (range.value === 'custom' && customRange.value) {
    return dayjs(customRange.value[0]).toISOString();
  }
  const [amount, unit] = rangePresets[range.value] ?? [6, 'hour'];
  return dayjs().subtract(amount, unit as any).toISOString();
});

const toIso = computed(() => {
  if (range.value === 'custom' && customRange.value) {
    return dayjs(customRange.value[1]).toISOString();
  }
  return dayjs().toISOString();
});

// 检索语句 + 级别过滤折叠进 query
const effectiveQuery = computed(() => {
  const base = queryInput.value.trim();
  if (!levels.value.length) return base || '*';
  const levelExpr = `level:(${levels.value.join(' OR ')})`;
  return base && base !== '*' ? `(${base}) AND ${levelExpr}` : levelExpr;
});

async function fetchTopics() {
  if (!currentWsUuid.value) return;
  const res = await topicApi.list(currentWsUuid.value, { page: 1, page_size: 100 });
  topics.value = res.data.map((t) => ({ uuid: t.uuid, name: t.name }));
}

// ---- 检索 ----
const searching = ref(false);
const result = ref<SearchResult | null>(null);
const page = ref(1);
const pageSize = 50;

const pagedHits = computed(() => {
  const hits = result.value?.hits ?? [];
  const start = (page.value - 1) * pageSize;
  return hits.slice(start, start + pageSize);
});

async function runSearch() {
  if (!currentWsUuid.value) return;
  searching.value = true;
  page.value = 1;
  try {
    result.value = topicUuid.value
      ? await searchApi.searchTopic(topicUuid.value, {
          query: effectiveQuery.value,
          from: fromIso.value,
          to: toIso.value,
          size: Math.min(size.value, 1000),
        })
      : await searchApi.search(currentWsUuid.value, {
          query: effectiveQuery.value,
          from: fromIso.value,
          to: toIso.value,
          size: Math.min(size.value, 1000),
        });
    if (result.value.error) {
      ElMessage.error(`检索失败：${result.value.error}`);
    } else {
      runHistogram();
    }
  } finally {
    searching.value = false;
  }
}

// ---- 直方图 ----
const histLoading = ref(false);
const histBuckets = ref<{ label: string; count: number }[]>([]);
const { el: histEl, render: renderHist } = useEchart();

async function runHistogram() {
  if (!topicUuid.value || !result.value) {
    histBuckets.value = [];
    return;
  }
  histLoading.value = true;
  try {
    const unit: HistogramBucketUnit = range.value === '5min' ? 'minute'
      : range.value === '7d' ? 'day' : 'hour';
    const res = await searchApi.histogram(topicUuid.value, {
      query: effectiveQuery.value,
      from: fromIso.value,
      to: toIso.value,
      bucket: unit,
    });
    histBuckets.value = res.buckets.map((b) => ({
      label: String(b.key_as_string ?? b.key).replace('T', ' ').slice(0, 16),
      count: b.doc_count,
    }));
    renderHist({
      grid: { left: 50, right: 16, top: 20, bottom: 46 },
      tooltip: { trigger: 'axis' },
      xAxis: { type: 'category', data: histBuckets.value.map((b) => b.label), axisLabel: { rotate: 30, fontSize: 10 } },
      yAxis: { type: 'value', name: '条数' },
      series: [{ type: 'bar', data: histBuckets.value.map((b) => b.count), itemStyle: { color: '#4f6df5' }, barMaxWidth: 28 }],
    });
  } catch {
    histBuckets.value = [];
  } finally {
    histLoading.value = false;
  }
}

// ---- 聚合 ----
const aggType = ref<AggType>('terms');
const aggField = ref('');
const aggLoading = ref(false);
const aggResult = ref<{ label: string; count: number }[] | null>(null);
const aggValue = ref<number | null>(null);
const { el: aggEl, render: renderAgg } = useEchart();

async function runAgg() {
  if (!topicUuid.value || !aggField.value) {
    ElMessage.warning('选择主题并填写聚合字段后再执行');
    return;
  }
  aggLoading.value = true;
  try {
    const res = await searchApi.aggregate(topicUuid.value, {
      query: effectiveQuery.value,
      from: fromIso.value,
      to: toIso.value,
      agg_type: aggType.value,
      field: aggField.value,
      size: 20,
    });
    const data = res.aggregations;
    if (data?.buckets) {
      const buckets: { label: string; count: number }[] = data.buckets.map((b: any) => ({
        label: String(b.key_as_string ?? b.key ?? '').slice(0, 24),
        count: b.doc_count ?? 0,
      }));
      aggResult.value = buckets;
      aggValue.value = null;
      renderAgg({
        tooltip: { trigger: 'axis' },
        grid: { left: 70, right: 16, top: 10, bottom: 30 },
        xAxis: { type: 'value' },
        yAxis: { type: 'category', data: buckets.map((b) => b.label).reverse(), axisLabel: { fontSize: 10 } },
        series: [{ type: 'bar', data: buckets.map((b) => b.count).reverse(), itemStyle: { color: '#6e8bff' } }],
      });
    } else if (typeof data?.value === 'number') {
      aggValue.value = data.value;
      aggResult.value = null;
    } else {
      aggResult.value = null;
      aggValue.value = null;
      ElMessage.info('聚合无结果');
    }
  } finally {
    aggLoading.value = false;
  }
}

// ---- 命中详情 ----
const detailVisible = ref(false);
const detailHit = ref<SearchHit | null>(null);
const detailJson = computed(() =>
  detailHit.value ? JSON.stringify(detailHit.value, null, 2) : ''
);

const levelTagType = (level: string) =>
  ({ DEBUG: 'info', INFO: 'primary', WARN: 'warning', ERROR: 'danger', FATAL: 'danger' } as any)[String(level).toUpperCase()] || 'info';

watch(currentWsUuid, () => {
  topicUuid.value = '';
  result.value = null;
  fetchTopics();
});

onMounted(fetchTopics);
</script>

<template>
  <div class="page">
    <PageHeader title="日志检索" subtitle="Lucene 语法 · 最大返回 1000 条" />

    <el-card shadow="never" class="search-bar">
      <el-input
        v-model="queryInput"
        placeholder="Lucene：service:order-api AND status:500 ｜ 也支持 SQL：SELECT * WHERE level = 'ERROR' LIMIT 50"
        clearable
        @keyup.enter="runSearch"
      >
        <template #append>
          <el-button type="primary" :loading="searching" @click="runSearch">
            <el-icon><Search /></el-icon>检索
          </el-button>
        </template>
      </el-input>

      <div class="search-meta">
        <div class="meta-row">
          <el-select v-model="topicUuid" placeholder="跨主题检索" clearable style="width: 180px" size="small">
            <el-option v-for="t in topics" :key="t.uuid" :label="t.name" :value="t.uuid" />
          </el-select>

          <el-select v-model="range" style="width: 110px" size="small" @change="customRange = null">
            <el-option label="5 分钟" value="5min" />
            <el-option label="1 小时" value="1h" />
            <el-option label="6 小时" value="6h" />
            <el-option label="24 小时" value="24h" />
            <el-option label="7 天" value="7d" />
            <el-option label="自定义" value="custom" />
          </el-select>

          <el-date-picker
            v-if="range === 'custom'"
            v-model="customRange"
            type="datetimerange"
            size="small"
            style="width: 340px"
          />

          <el-select v-model="size" style="width: 90px" size="small">
            <el-option :label="200" :value="200" />
            <el-option :label="500" :value="500" />
            <el-option :label="1000" :value="1000" />
          </el-select>
        </div>

        <el-checkbox-group v-model="levels" size="small">
          <el-checkbox-button label="DEBUG" />
          <el-checkbox-button label="INFO" />
          <el-checkbox-button label="WARN" />
          <el-checkbox-button label="ERROR" />
          <el-checkbox-button label="FATAL" />
        </el-checkbox-group>
      </div>
    </el-card>

    <el-card v-if="topicUuid" shadow="never" class="histogram-card">
      <template #header>时间分布</template>
      <div v-loading="histLoading" ref="histEl" style="height: 220px" />
    </el-card>

    <el-card v-if="topicUuid" shadow="never" class="histogram-card">
      <template #header>聚合分析</template>
      <div class="agg-bar">
        <el-select v-model="aggType" size="small" style="width: 140px">
          <el-option label="分组统计" value="terms" />
          <el-option label="平均值" value="avg" />
          <el-option label="求和" value="sum" />
          <el-option label="最大值" value="max" />
          <el-option label="最小值" value="min" />
          <el-option label="基数" value="cardinality" />
        </el-select>
        <el-input
          v-model="aggField"
          size="small"
          placeholder="字段名，如 level"
          style="width: 180px"
          @keyup.enter="runAgg"
        />
        <el-button size="small" :loading="aggLoading" @click="runAgg">执行聚合</el-button>
        <span v-if="aggValue !== null" class="agg-value">结果：{{ aggValue }}</span>
      </div>
      <div v-show="aggResult" ref="aggEl" style="height: 220px" />
    </el-card>

    <el-card shadow="never" class="result-card">
      <div class="result-header">
        <span>
          共 <b>{{ result?.total ?? 0 }}</b> 条
          <span v-if="result" class="took">（{{ result.took_ms }} ms）</span>
        </span>
        <el-pagination
          v-if="(result?.hits.length ?? 0) > pageSize"
          v-model:current-page="page"
          :page-size="pageSize"
          :total="result!.hits.length"
          layout="prev, pager, next"
          small
        />
      </div>

      <el-table
        :data="pagedHits"
        v-loading="searching"
        stripe
        size="small"
        @row-click="(row: SearchHit) => { detailHit = row; detailVisible = true; }"
      >
        <el-table-column label="时间" width="170">
          <template #default="{ row }">
            {{ String(row.timestamp ?? row._ts ?? row['@timestamp'] ?? '').replace('T', ' ').slice(0, 19) }}
          </template>
        </el-table-column>
        <el-table-column label="级别" width="80">
          <template #default="{ row }">
            <el-tag v-if="row.level" :type="levelTagType(row.level)" size="small">{{ row.level }}</el-tag>
            <span v-else>—</span>
          </template>
        </el-table-column>
        <el-table-column label="内容" min-width="500">
          <template #default="{ row }">
            <span class="hit-message">{{ row.message ?? JSON.stringify(row).slice(0, 200) }}</span>
          </template>
        </el-table-column>
      </el-table>

      <el-empty v-if="result && !result.hits.length && !searching" description="无命中结果" />
      <el-empty v-if="!result && !searching" description="输入条件后点击「检索」" />
    </el-card>

    <el-drawer v-model="detailVisible" title="日志详情" size="560px">
      <pre class="json-view">{{ detailJson }}</pre>
    </el-drawer>
  </div>
</template>

<style scoped lang="scss">
.search-bar {
  margin-bottom: 16px;

  .search-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 12px;
    gap: 12px;
    flex-wrap: wrap;

    .meta-row {
      display: flex;
      gap: 8px;
      align-items: center;
      flex-wrap: wrap;
    }
  }
}

.histogram-card {
  margin-bottom: 16px;

  .agg-bar {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-bottom: 12px;
    flex-wrap: wrap;

    .agg-value {
      font-size: 13px;
      color: var(--el-text-color-regular);
    }
  }
}

.result-card {
  min-height: 360px;

  .result-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    font-size: 13px;

    .took {
      color: var(--el-text-color-secondary);
    }
  }

  :deep(.el-table__row) {
    cursor: pointer;
  }
}

.hit-message {
  font-family: 'SF Mono', Menlo, Consolas, monospace;
  font-size: 12px;
  word-break: break-all;
}

.json-view {
  background: var(--el-fill-color-light);
  border-radius: 6px;
  padding: 12px;
  font-size: 12px;
  line-height: 1.6;
  overflow: auto;
  max-height: calc(100vh - 160px);
  margin: 0;
}
</style>
