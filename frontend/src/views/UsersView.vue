<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import type { FormInstance, FormRules } from 'element-plus';
import PageHeader from '@/components/PageHeader.vue';
import { userApi, type User, type UserPayload } from '@/api';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();

const loading = ref(false);
const list = ref<User[]>([]);
const total = ref(0);
const page = ref(1);
const pageSize = ref(20);

const roleOptions = ['owner', 'admin', 'viewer', 'billing'];

async function fetchList() {
  loading.value = true;
  try {
    const res = await userApi.list({ page: page.value, page_size: pageSize.value });
    list.value = res.data;
    total.value = res.meta.total;
  } finally {
    loading.value = false;
  }
}

// ---- 新建 / 编辑 ----
const dialogVisible = ref(false);
const submitting = ref(false);
const editing = ref<User | null>(null);
const formRef = ref<FormInstance>();
const form = reactive<UserPayload & { password: string }>({
  name: '', email: '', password: '', roles: ['viewer'],
});

const rules: FormRules = {
  name: [{ required: true, message: '请输入姓名', trigger: 'blur' }],
  email: [
    { required: true, message: '请输入邮箱', trigger: 'blur' },
    { type: 'email', message: '邮箱格式不正确', trigger: 'blur' },
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 8, message: '密码至少 8 位', trigger: 'blur' },
  ],
};

function openCreate() {
  editing.value = null;
  Object.assign(form, { name: '', email: '', password: '', roles: ['viewer'] });
  dialogVisible.value = true;
}

function openEdit(row: User) {
  editing.value = row;
  Object.assign(form, { name: row.name, email: row.email, password: '', roles: [...row.roles] });
  dialogVisible.value = true;
}

async function submit() {
  if (!formRef.value) return;
  const valid = await formRef.value.validate().catch(() => false);
  if (!valid) return;
  submitting.value = true;
  try {
    if (editing.value) {
      const payload: Partial<UserPayload> = { name: form.name, email: form.email, roles: form.roles };
      if (form.password) payload.password = form.password;
      await userApi.update(editing.value.id, payload);
      ElMessage.success('已更新');
      if (auth.user && auth.user.id === editing.value.id) await auth.fetchMe();
    } else {
      await userApi.create({ ...form });
      ElMessage.success('已创建');
    }
    dialogVisible.value = false;
    await fetchList();
  } finally {
    submitting.value = false;
  }
}

async function handleResetPassword(row: User) {
  const { value } = await ElMessageBox.prompt(
    `为「${row.name}（${row.email}）」设置新密码（至少 8 位）`,
    '重置密码',
    { inputType: 'password', inputPattern: /^.{8,}$/, inputErrorMessage: '密码至少 8 位' }
  );
  await userApi.resetPassword(row.id, value);
  ElMessage.success('密码已重置');
}

async function handleDelete(row: User) {
  await ElMessageBox.confirm(`删除用户「${row.name}」？`, '删除确认', {
    type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消',
  });
  await userApi.delete(row.id);
  ElMessage.success('已删除');
  await fetchList();
}

onMounted(fetchList);
</script>

<template>
  <div class="page">
    <PageHeader title="用户与权限" subtitle="控制台账号与角色（owner / admin / viewer / billing）">
      <template #actions>
        <el-button type="primary" @click="openCreate">
          <el-icon><Plus /></el-icon>新建用户
        </el-button>
      </template>
    </PageHeader>

    <el-card shadow="never">
      <el-table :data="list" v-loading="loading" stripe>
        <el-table-column prop="name" label="姓名" min-width="120" />
        <el-table-column prop="email" label="邮箱" min-width="200" show-overflow-tooltip />
        <el-table-column label="角色" min-width="160">
          <template #default="{ row }">
            <el-tag v-for="r in row.roles" :key="r" size="small" style="margin-right: 4px">{{ r }}</el-tag>
            <span v-if="!row.roles?.length" class="text-muted">—</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 'active' ? 'success' : 'danger'" size="small">
              {{ row.status === 'active' ? '正常' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="最近登录" width="170">
          <template #default="{ row }">
            {{ row.last_login_at ? row.last_login_at.replace('T', ' ').slice(0, 19) : '—' }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="220" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="handleResetPassword(row as User)">重置密码</el-button>
            <el-button link type="primary" @click="openEdit(row as User)">编辑</el-button>
            <el-button v-if="row.id !== auth.user?.id" link type="danger" @click="handleDelete(row as User)">删除</el-button>
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

    <el-dialog v-model="dialogVisible" :title="editing ? '编辑用户' : '新建用户'" width="520px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="80px">
        <el-form-item label="姓名" prop="name">
          <el-input v-model="form.name" />
        </el-form-item>
        <el-form-item label="邮箱" prop="email">
          <el-input v-model="form.email" :disabled="!!editing" />
        </el-form-item>
        <el-form-item label="密码" prop="password">
          <el-input v-model="form.password" type="password" show-password
            :placeholder="editing ? '留空则不修改密码' : '至少 8 位'" />
        </el-form-item>
        <el-form-item label="角色">
          <el-select v-model="form.roles" multiple style="width: 100%">
            <el-option v-for="r in roleOptions" :key="r" :label="r" :value="r" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>
