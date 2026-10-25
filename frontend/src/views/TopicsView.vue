<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import type { FormInstance, FormRules } from 'element-plus';
import PageHeader from '@/components/PageHeader.vue';
import {
  topicApi,
  topicFieldApi,
  type Topic,
  type TopicField,
  type TopicFieldPayload,
  type TopicPayload,
  type FieldType,
} from '@/api';
import { useWorkspaceStore } from '@/stores/workspace';

const workspaceStore = useWorkspaceStore();
const currentWsUuid = computed(() => workspaceStore.currentUuid);

const loading = ref(false);
const list = ref<Topic[]>([]);
const total = ref(0);
const page = ref(1);
const pageSize = ref(20);
const keyword = ref('');

async function fetchList() {
  if (!currentWsUuid.value) return;
  loading.value = true;
  try {
    const res = await topicApi.list(currentWsUuid.value, {
      page: page.value,
      page_size: pageSize.value,
      keyword: keyword.value || undefined,
    });
    list.value = res.data;
    total.value = res.meta.total;
  } finally {
    loading.value = false;
  }
}

watch(currentWsUuid, () => {
  page.value = 1;
  fetchList();
});

// ---- 新建 / 编辑 ----
const dialogVisible = ref(false);
const submitting = ref(false);
const editing = ref<Topic | null>(null);
const formRef = ref<FormInstance>();
const form = reactive<TopicPayload>({
  name: '',
  description: '',
  retention_days: 30,
  auto_field_index: true,
  enable_full_text: true,
  json_extract: true,
});

const rules: FormRules = {
  name: [
    { required: true, message: '请输入主题名称', trigger: 'blur' },
    { max: 64, message: '名称不超过 64 字符', trigger: 'blur' },
  ],
};

function openCreate() {
  editing.value = null;
  Object.assign(form, {
    name: '', description: '', retention_days: 30,
    auto_field_index: true, enable_full_text: true, json_extract: true,
  });
  dialogVisible.value = true;
}

function openEdit(row: Topic) {
  editing.value = row;
  Object.assign(form, {
    name: row.name,
    description: row.description ?? '',
    retention_days: row.retention_days,
    auto_field_index: row.auto_field_index,
    enable_full_text: row.enable_full_text,
    json_extract: row.json_extract,
  });
  dialogVisible.value = true;
}

async function submit() {
  if (!formRef.value || !currentWsUuid.value) return;
  const valid = await formRef.value.validate().catch(() => false);
  if (!valid) return;
  submitting.value = true;
  try {
    if (editing.value) {
      await topicApi.update(editing.value.uuid, { ...form });
      ElMessage.success('已更新');
    } else {
      await topicApi.create(currentWsUuid.value, { ...form });
      ElMessage.success('已创建');
    }
    dialogVisible.value = false;
    await fetchList();
  } finally {
    submitting.value = false;
  }
}

async function handleDelete(row: Topic) {
  await ElMessageBox.confirm(
    `删除主题「${row.name}」后其接入点与告警规则将失效。确认删除？`,
    '删除确认',
    { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' }
  );
  await topicApi.delete(row.uuid);
  ElMessage.success('已删除');
  await fetchList();
}

// ---- 字段索引抽屉 ----
const fieldsDrawer = ref(false);
const fieldsLoading = ref(false);
const fieldsTopic = ref<Topic | null>(null);
const fields = ref<TopicField[]>([]);

const fieldDialogVisible = ref(false);
const fieldEditing = ref<TopicField | null>(null);
const fieldFormRef = ref<FormInstance>();
const fieldForm = reactive<TopicFieldPayload>({ field_name: '', field_type: 'keyword', indexed: true });
const fieldTypes: FieldType[] = ['text', 'keyword', 'long', 'double', 'date', 'ip', 'geo'];

const fieldRules: FormRules = {
  field_name: [{ required: true, message: '请输入字段名', trigger: 'blur' }],
  field_type: [{ required: true, message: '请选择类型', trigger: 'change' }],
};

function openFields(row: Topic) {
  fieldsTopic.value = row;
  fieldsDrawer.value = true;
  fetchFields();
}

async function fetchFields() {
  if (!fieldsTopic.value) return;
  fieldsLoading.value = true;
  try {
    const res = await topicFieldApi.list(fieldsTopic.value.uuid);
    fields.value = res.data;
  } finally {
    fieldsLoading.value = false;
  }
}

function openFieldCreate() {
  fieldEditing.value = null;
  Object.assign(fieldForm, { field_name: '', field_type: 'keyword', indexed: true });
  fieldDialogVisible.value = true;
}

function openFieldEdit(row: TopicField) {
  fieldEditing.value = row;
  Object.assign(fieldForm, {
    field_name: row.field_name,
    field_type: row.field_type,
    indexed: row.indexed,
  });
  fieldDialogVisible.value = true;
}

async function submitField() {
  if (!fieldFormRef.value || !fieldsTopic.value) return;
  const valid = await fieldFormRef.value.validate().catch(() => false);
  if (!valid) return;
  const topicUuid = fieldsTopic.value.uuid;
  if (fieldEditing.value) {
    await topicFieldApi.update(topicUuid, fieldEditing.value.id, { ...fieldForm });
    ElMessage.success('已更新');
  } else {
    await topicFieldApi.create(topicUuid, { ...fieldForm });
    ElMessage.success('已创建');
  }
  fieldDialogVisible.value = false;
  await fetchFields();
}

async function handleFieldDelete(row: TopicField) {
  if (!fieldsTopic.value) return;
  await ElMessageBox.confirm(`删除字段索引「${row.field_name}」？`, '删除确认', {
    type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消',
  });
  await topicFieldApi.delete(fieldsTopic.value.uuid, row.id);
  ElMessage.success('已删除');
  await fetchFields();
}

onMounted(fetchList);
</script>

<template>
  <div class="page">
    <PageHeader title="日志主题" subtitle="日志接入与检索的基本单元">
      <template #actions>
        <el-button type="primary" :disabled="!currentWsUuid" @click="openCreate">
          <el-icon><Plus /></el-icon>新建主题
        </el-button>
      </template>
    </PageHeader>

    <el-card shadow="never">
      <div class="toolbar">
        <el-input
          v-model="keyword"
          placeholder="按名称搜索"
          clearable
          style="width: 240px"
          @keyup.enter="() => { page = 1; fetchList(); }"
          @clear="() => { page = 1; fetchList(); }"
        />
        <el-button @click="() => { page = 1; fetchList(); }">搜索</el-button>
      </div>

      <el-table :data="list" v-loading="loading" stripe>
        <el-table-column prop="name" label="名称" min-width="160" show-overflow-tooltip />
        <el-table-column prop="description" label="描述" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">{{ row.description || '—' }}</template>
        </el-table-column>
        <el-table-column label="保留期" width="90">
          <template #default="{ row }">{{ row.retention_days }} 天</template>
        </el-table-column>
        <el-table-column label="全文索引" width="90">
          <template #default="{ row }">
            <el-tag :type="row.enable_full_text ? 'success' : 'info'" size="small">
              {{ row.enable_full_text ? '开' : '关' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="JSON 提取" width="90">
          <template #default="{ row }">
            <el-tag :type="row.json_extract ? 'success' : 'info'" size="small">
              {{ row.json_extract ? '开' : '关' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="170">
          <template #default="{ row }">{{ (row.created_at || '').replace('T', ' ').slice(0, 19) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openFields(row as Topic)">字段索引</el-button>
            <el-button link type="primary" @click="openEdit(row as Topic)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row as Topic)">删除</el-button>
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

    <el-dialog v-model="dialogVisible" :title="editing ? '编辑主题' : '新建主题'" width="560px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
        <el-form-item label="名称" prop="name">
          <el-input v-model="form.name" placeholder="如：order-api-logs" />
        </el-form-item>
        <el-form-item label="描述">
          <el-input v-model="form.description" type="textarea" :rows="2" placeholder="可选" />
        </el-form-item>
        <el-form-item label="保留期">
          <el-input-number v-model="form.retention_days" :min="1" :max="3650" />
          <span class="form-unit">天</span>
        </el-form-item>
        <el-form-item label="自动字段索引">
          <el-switch v-model="form.auto_field_index" />
        </el-form-item>
        <el-form-item label="全文索引">
          <el-switch v-model="form.enable_full_text" />
        </el-form-item>
        <el-form-item label="JSON 提取">
          <el-switch v-model="form.json_extract" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">确定</el-button>
      </template>
    </el-dialog>

    <el-drawer v-model="fieldsDrawer" :title="`字段索引 · ${fieldsTopic?.name || ''}`" size="560px">
      <div class="drawer-toolbar">
        <el-button type="primary" size="small" @click="openFieldCreate">
          <el-icon><Plus /></el-icon>新增字段
        </el-button>
        <el-button size="small" @click="fetchFields">刷新</el-button>
      </div>
      <el-table :data="fields" v-loading="fieldsLoading" size="small">
        <el-table-column prop="field_name" label="字段名" min-width="130" show-overflow-tooltip />
        <el-table-column prop="field_type" label="类型" width="90" />
        <el-table-column label="索引" width="70">
          <template #default="{ row }">{{ row.indexed ? '是' : '否' }}</template>
        </el-table-column>
        <el-table-column prop="analyzer" label="分词器" width="100" />
        <el-table-column label="操作" width="110">
          <template #default="{ row }">
            <el-button link type="primary" @click="openFieldEdit(row as TopicField)">编辑</el-button>
            <el-button link type="danger" @click="handleFieldDelete(row as TopicField)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-drawer>

    <el-dialog
      v-model="fieldDialogVisible"
      :title="fieldEditing ? '编辑字段' : '新增字段'"
      width="460px"
      append-to-body
    >
      <el-form ref="fieldFormRef" :model="fieldForm" :rules="fieldRules" label-width="90px">
        <el-form-item label="字段名" prop="field_name">
          <el-input v-model="fieldForm.field_name" :disabled="!!fieldEditing" placeholder="如：user_id" />
        </el-form-item>
        <el-form-item label="类型" prop="field_type">
          <el-select v-model="fieldForm.field_type" style="width: 100%">
            <el-option v-for="t in fieldTypes" :key="t" :label="t" :value="t" />
          </el-select>
        </el-form-item>
        <el-form-item label="开启索引">
          <el-switch v-model="fieldForm.indexed" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="fieldDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="submitField">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.toolbar {
  display: flex;
  gap: 8px;
  margin-bottom: 14px;
}
.drawer-toolbar {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
}
.form-unit {
  margin-left: 8px;
  color: var(--el-text-color-secondary);
}
</style>
