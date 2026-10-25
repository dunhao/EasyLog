<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import type { FormInstance, FormRules } from 'element-plus';
import PageHeader from '@/components/PageHeader.vue';
import { workspaceApi, type Workspace, type WorkspacePayload } from '@/api';
import { useWorkspaceStore } from '@/stores/workspace';

const workspaceStore = useWorkspaceStore();

const loading = ref(false);
const list = ref<Workspace[]>([]);
const total = ref(0);
const page = ref(1);
const pageSize = ref(20);

async function fetchList() {
  loading.value = true;
  try {
    const res = await workspaceApi.list({ page: page.value, page_size: pageSize.value });
    list.value = res.data;
    total.value = res.meta.total;
  } finally {
    loading.value = false;
  }
}

// ---- 新建 / 编辑 ----
const dialogVisible = ref(false);
const submitting = ref(false);
const editing = ref<Workspace | null>(null);
const formRef = ref<FormInstance>();
const form = reactive<WorkspacePayload>({ name: '', description: '', retention_days: 30, shard_count: 6, daily_quota_gb: 0 });

const rules: FormRules = {
  name: [
    { required: true, message: '请输入工作空间名称', trigger: 'blur' },
    { max: 64, message: '名称不超过 64 字符', trigger: 'blur' },
  ],
};

function openCreate() {
  editing.value = null;
  Object.assign(form, { name: '', description: '', retention_days: 30, shard_count: 6, daily_quota_gb: 0 });
  dialogVisible.value = true;
}

function openEdit(row: Workspace) {
  editing.value = row;
  Object.assign(form, {
    name: row.name,
    description: row.description ?? '',
    retention_days: row.retention_days,
    shard_count: row.shard_count,
    daily_quota_gb: row.daily_quota_gb ?? 0,
  });
  dialogVisible.value = true;
}

async function submit() {
  if (!formRef.value) return;
  const valid = await formRef.value.validate().catch(() => false);
  if (!valid) return;
  submitting.value = true;
  try {
    if (editing.value) {
      await workspaceApi.update(editing.value.uuid, { ...form });
      ElMessage.success('已更新');
    } else {
      await workspaceApi.create({ ...form });
      ElMessage.success('已创建');
    }
    dialogVisible.value = false;
    await fetchList();
    await workspaceStore.fetchList();
  } finally {
    submitting.value = false;
  }
}

async function handleDelete(row: Workspace) {
  await ElMessageBox.confirm(
    `删除工作空间「${row.name}」不影响已写入的日志数据，但会停止其接入与查询。确认删除？`,
    '删除确认',
    { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' }
  );
  await workspaceApi.delete(row.uuid);
  ElMessage.success('已删除');
  await fetchList();
  await workspaceStore.fetchList();
}

onMounted(fetchList);
</script>

<template>
  <div class="page">
    <PageHeader title="工作空间" subtitle="多项目隔离单元，按需创建">
      <template #actions>
        <el-button type="primary" @click="openCreate">
          <el-icon><Plus /></el-icon>新建工作空间
        </el-button>
      </template>
    </PageHeader>

    <el-card shadow="never">
      <el-table :data="list" v-loading="loading" stripe>
        <el-table-column prop="name" label="名称" min-width="160" show-overflow-tooltip />
        <el-table-column prop="description" label="描述" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">{{ row.description || '—' }}</template>
        </el-table-column>
        <el-table-column label="保留期" width="90">
          <template #default="{ row }">{{ row.retention_days }} 天</template>
        </el-table-column>
        <el-table-column prop="shard_count" label="分片数" width="80" />
        <el-table-column label="日配额" width="90">
          <template #default="{ row }">{{ row.daily_quota_gb ? row.daily_quota_gb + ' GB' : '不限' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">
              {{ row.status === 'active' ? '活跃' : '冻结' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="170">
          <template #default="{ row }">{{ (row.created_at || '').replace('T', ' ').slice(0, 19) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="140" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row as Workspace)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row as Workspace)">删除</el-button>
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

    <el-dialog
      v-model="dialogVisible"
      :title="editing ? '编辑工作空间' : '新建工作空间'"
      width="520px"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-form-item label="名称" prop="name">
          <el-input v-model="form.name" placeholder="如：订单中心" />
        </el-form-item>
        <el-form-item label="描述">
          <el-input v-model="form.description" type="textarea" :rows="2" placeholder="可选" />
        </el-form-item>
        <el-form-item label="保留期">
          <el-input-number v-model="form.retention_days" :min="1" :max="3650" />
          <span class="form-unit">天</span>
        </el-form-item>
        <el-form-item label="分片数">
          <el-input-number v-model="form.shard_count" :min="1" :max="100" />
        </el-form-item>
        <el-form-item label="日配额">
          <el-input-number v-model="form.daily_quota_gb" :min="0" :max="102400" />
          <span class="form-unit">GB（0 = 不限）</span>
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
.form-unit {
  margin-left: 8px;
  color: var(--el-text-color-secondary);
}
</style>
