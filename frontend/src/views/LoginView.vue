<script setup lang="ts">
import { ref, reactive } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import type { FormInstance, FormRules } from 'element-plus';

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();

const formRef = ref<FormInstance>();
const form = reactive({ email: '', password: '' });
const rules: FormRules = {
  email: [
    { required: true, message: '请输入邮箱', trigger: 'blur' },
    { type: 'email', message: '邮箱格式不正确', trigger: 'blur' },
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 6, message: '密码至少 6 位', trigger: 'blur' },
  ],
};

async function handleLogin() {
  if (!formRef.value) return;
  await formRef.value.validate(async (valid) => {
    if (!valid) return;
    try {
      await auth.login(form.email, form.password);
      const redirect = (route.query.redirect as string) || '/';
      router.push(redirect);
    } catch (e: any) {
      // 错误已由 http 拦截器统一处理
    }
  });
}
</script>

<template>
  <div class="login-page">
    <div class="login-card">
      <div class="login-brand">
        <span class="brand-mark">E</span>
        <span class="brand-text">EasyLog</span>
      </div>
      <p class="login-subtitle">登录到您的日志服务</p>

      <el-form ref="formRef" :model="form" :rules="rules" size="large" @keyup.enter="handleLogin">
        <el-form-item prop="email">
          <el-input v-model="form.email" placeholder="邮箱" :prefix-icon="'User'" />
        </el-form-item>
        <el-form-item prop="password">
          <el-input v-model="form.password" type="password" placeholder="密码" :prefix-icon="'Lock'" show-password />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="auth.loading" @click="handleLogin" style="width: 100%">
            登录
          </el-button>
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>

<style scoped lang="scss">
.login-page {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100vh;
  background: linear-gradient(135deg, #4f6df5 0%, #6e8bff 100%);
}

.login-card {
  width: 380px;
  background: #fff;
  padding: 40px;
  border-radius: 8px;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
}

.login-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  justify-content: center;
  margin-bottom: 8px;

  .brand-mark {
    display: inline-flex;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: #4f6df5;
    color: #fff;
    font-size: 20px;
    font-weight: 700;
    align-items: center;
    justify-content: center;
  }
  .brand-text {
    font-size: 22px;
    font-weight: 700;
  }
}

.login-subtitle {
  text-align: center;
  color: var(--el-text-color-secondary);
  margin: 0 0 28px 0;
  font-size: 14px;
}
</style>
