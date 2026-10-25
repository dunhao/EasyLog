<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import type { FormInstance, FormRules } from 'element-plus';
import PageHeader from '@/components/PageHeader.vue';
import {
  dashboardApi,
  searchApi,
  topicApi,
  type Dashboard,
  type DashboardPanel,
} from '@/api';
import { useWorkspaceStore } from '@/stores/workspace';

const workspaceStore = useWorkspaceStore();
const currentWsUuid = computed(() => workspaceStore.currentUuid);

const loading = ref(false);
const list = ref<Dashboard[]>([]);
const topics = ref<{ uuid: string; name: string }[]>([]);

async function fetchList() {
  if (!currentWsUuid.value) return;
  loading.value = true;
  try {
    const res = await dashboardApi.list(currentWsUuid.value, { page: 1, page_size: 50 });
    list.value = res.data;
  } finally {
    loading.value = false;
  }
}

async function fetchTopics() {
  if (!currentWsUuid.value) return;
  const res = await topicApi.list(currentWsUuid.value, { page: 1, page_size: 100 });
  topics.value = res.data.map((t) => ({ uuid: t.uuid, name: t.name }));
}

watch(currentWsUuid, () => {
  fetchList();
  fetchTopics();
});

// ---- 新建 / 编辑（含面板编辑器） ----
const dialogVisible = ref(false);
const submitting = ref(false);
const editing = ref<Dashboard | null>(null);
const formRef = ref<FormInstance>();
const form = reactive({ name: '', panels: [] as DashboardPanel[] });

const rules: FormRules = {
  name: [{ required: true, message: '请输入看板名称', trigger: 'blur' }],
};

function blankPanel(): DashboardPanel {
  return {
    type: 'line',
    title: '新图表',
    query: '*',
    topic_uuid: topics.value[0]?.uuid ?? '',
    agg_type: 'date_histogram',
    bucket: 'hour',
    field: 'level',
    span: 12,
  };
}

function openCreate() {
  editing.value = null;
  Object.assign(form, { name: '', panels: [blankPanel()] });
  dialogVisible.value = true;
}

function openEdit(row: Dashboard) {
  editing.value = row;
  Object.assign(form, { name: row.name, panels: (row.layout ?? []).map((p) => ({ ...p })) });
  dialogVisible.value = true;
}

function addPanel() {
  form.panels.push(blankPanel());
}

function removePanel(idx: number) {
  form.panels.splice(idx, 1);
}

async function submit() {
  if (!formRef.value || !currentWsUuid.value) return;
  const valid = await formRef.value.validate().catch(() => false);
  if (!valid) return;
  submitting.value = true;
  try {
    if (editing.value) {
      await dashboardApi.update(currentWsUuid.value, editing.value.id, {
        name: form.name,
        layout: form.panels,
      });
      ElMessage.success('已更新');
    } else {
      await dashboardApi.create(currentWsUuid.value, { name: form.name, layout: form.panels });
      ElMessage.success('已创建');
    }
    dialogVisible.value = false;
    await fetchList();
  } finally {
    submitting.value = false;
  }
}

async function handleDelete(row: Dashboard) {
  await ElMessageBox.confirm(`删除看板「${row.name}」？`, '删除确认', {
    type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消',
  });
  await dashboardApi.delete(currentWsUuid.value!, row.id);
  ElMessage.success('已删除');
  await fetchList();
}

// ---- 查看看板（对话框内逐面板渲染 ECharts） ----
const viewerVisible = ref(false);
const viewing = ref<Dashboard | null>(null);
const viewerLoading = ref(false);
const viewerEls = ref<Record<number, HTMLElement>>({});
let viewerCharts: any[] = [];

function disposeViewerCharts() {
  viewerCharts.forEach((c) => c?.dispose?.());
  viewerCharts = [];
  viewerEls.value = {};
}

watch(viewerVisible, (v) => {
  if (!v) disposeViewerCharts();
});

function setViewerEl(idx: number, el: any) {
  if (el) viewerEls.value[idx] = el as HTMLElement;
}

async function openViewer(row: Dashboard) {
  viewing.value = row;
  viewerVisible.value = true;
  viewerLoading.value = true;
  // 等 DOM 渲染
  await new Promise((r) => setTimeout(r, 60));
  viewerLoading.value = false;
  await renderPanels(row);
}

async function renderPanels(dash: Dashboard) {
  const from = new Date(Date.now() - 6 * 3600 * 1000).toISOString();
  const to = new Date().toISOString();
  await Promise.all(
    dash.layout.map(async (panel, idx) => {
      const el = viewerEls.value[idx];
      if (!el) return;
      try {
        let option: any;
        if (panel.agg_type === 'terms' && panel.field) {
          const res = await searchApi.aggregate(panel.topic_uuid, {
            query: panel.query || '*', from, to, agg_type: 'terms', field: panel.field, size: 20,
          });
          const buckets = res.aggregations?.buckets ?? [];
          option = {
            title: { text: panel.title, left: 'left', textStyle: { fontSize: 13 } },
            grid: { left: 60, right: 16, top: 36, bottom: 26 },
            xAxis: { type: 'value' },
            yAxis: { type: 'category', data: buckets.map((b: any) => String(b.key).slice(0, 20)).reverse() },
            series: [{ type: 'bar', data: buckets.map((b: any) => b.doc_count).reverse() }],
          };
        } else if (panel.agg_type && ['avg', 'sum', 'max', 'min', 'cardinality'].includes(panel.agg_type) && panel.field) {
          const res = await searchApi.aggregate(panel.topic_uuid, {
            query: panel.query || '*', from, to, agg_type: panel.agg_type, field: panel.field,
          });
          option = {
            title: { text: panel.title, left: 'left', textStyle: { fontSize: 13 } },
            series: [{
              type: 'gauge', startAngle: 200, endAngle: -20,
              data: [{ value: res.aggregations?.value ?? 0, name: panel.field }],
            }],
          };
        } else {
          // 默认时间序列：按 level 拆分
          const res = await searchApi.histogram(panel.topic_uuid, {
            query: panel.query || '*', from, to, bucket: panel.bucket ?? 'hour',
          });
          const labels = res.buckets.map((b) => String(b.key_as_string ?? b.key).replace('T', ' ').slice(0, 16));
          option = {
            title: { text: panel.title, left: 'left', textStyle: { fontSize: 13 } },
            grid: { left: 50, right: 16, top: 36, bottom: 30 },
            tooltip: { trigger: 'axis' },
            xAxis: { type: 'category', data: labels },
            yAxis: { type: 'value' },
            series: [{ type: panel.type === 'bar' ? 'bar' : 'line', data: res.buckets.map((b) => b.doc_count), smooth: true }],
          };
        }
        // 动态渲染面板图
        const echarts = await import('echarts');
        const inst = echarts.init(el);
        inst.setOption(option, true);
        viewerCharts.push(inst);
      } catch {
        // 单个面板失败不影响其他面板
      }
    })
  );
}

onMounted(() => {
  fetchList();
  fetchTopics();
});
</script>

<template>
  <div class="page">
    <PageHeader title="自定义仪表盘" subtitle="把同一组查询固化为可复用的看板">
      <template #actions>
        <el-button type="primary" :disabled="!currentWsUuid" @click="openCreate">
          <el-icon><Plus /></el-icon>新建看板
        </el-button>
      </template>
    </PageHeader>

    <el-card shadow="never">
      <el-table :data="list" v-loading="loading" stripe>
        <el-table-column prop="name" label="名称" min-width="180" show-overflow-tooltip />
        <el-table-column label="面板数" width="90">
          <template #default="{ row }">{{ (row.layout ?? []).length }}</template>
        </el-table-column>
        <el-table-column label="更新时间" width="170">
          <template #default="{ row }">{{ (row.updated_at || '').replace('T', ' ').slice(0, 19) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="160" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openViewer(row as Dashboard)">查看</el-button>
            <el-button link type="primary" @click="openEdit(row as Dashboard)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row as Dashboard)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-if="!loading && !list.length" description="还没有看板，点击右上角创建" />
    </el-card>

    <el-dialog v-model="dialogVisible" :title="editing ? '编辑看板' : '新建看板'" width="860px" top="4vh">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="80px">
        <el-form-item label="名称" prop="name">
          <el-input v-model="form.name" />
        </el-form-item>
      </el-form>

      <div v-for="(panel, idx) in form.panels" :key="idx" class="panel-editor">
        <div class="panel-editor-head">
          <span>面板 {{ idx + 1 }}</span>
          <el-button link type="danger" @click="removePanel(idx)">移除</el-button>
        </div>
        <el-form label-width="76px" size="small">
          <el-row :gutter="12">
            <el-col :span="8">
              <el-form-item label="标题">
                <el-input v-model="panel.title" />
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="主题">
                <el-select v-model="panel.topic_uuid" style="width: 100%">
                  <el-option v-for="t in topics" :key="t.uuid" :label="t.name" :value="t.uuid" />
                </el-select>
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="图表">
                <el-select v-model="panel.type" style="width: 100%">
                  <el-option label="折线" value="line" />
                  <el-option label="柱状" value="bar" />
                </el-select>
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="聚合">
                <el-select v-model="panel.agg_type" style="width: 100%">
                  <el-option label="时间序列" value="date_histogram" />
                  <el-option label="分组统计" value="terms" />
                  <el-option label="平均值" value="avg" />
                  <el-option label="求和" value="sum" />
                  <el-option label="最大值" value="max" />
                  <el-option label="基数" value="cardinality" />
                </el-select>
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="字段">
                <el-input v-model="panel.field" placeholder="terms/指标类必填" />
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="查询">
                <el-input v-model="panel.query" placeholder="* 为全部" />
              </el-form-item>
            </el-col>
          </el-row>
        </el-form>
      </div>

      <el-button style="margin-top: 6px" @click="addPanel">
        <el-icon><Plus /></el-icon>添加面板
      </el-button>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">保存</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="viewerVisible" :title="`看板 · ${viewing?.name || ''}`" width="1100px" top="4vh">
      <div v-loading="viewerLoading" class="viewer-grid">
        <div
          v-for="(panel, idx) in viewing?.layout ?? []"
          :key="idx"
          class="viewer-cell"
          :style="{ gridColumn: `span ${panel.span ?? 12}` }"
        >
          <div :ref="(el: any) => setViewerEl(idx, el)" class="viewer-chart" />
        </div>
        <el-empty v-if="!viewing?.layout?.length" description="该看板没有面板" style="grid-column: span 24" />
      </div>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.panel-editor {
  border: 1px solid var(--el-border-color-light);
  border-radius: 6px;
  padding: 10px 12px 0;
  margin-bottom: 12px;

  .panel-editor-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
    margin-bottom: 8px;
  }
}

.viewer-grid {
  display: grid;
  grid-template-columns: repeat(24, 1fr);
  gap: 12px;
  min-height: 240px;

  .viewer-chart {
    height: 260px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
  }
}
</style>
