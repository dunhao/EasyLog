import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { authApi, type LoginResult } from '@/api';

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string>(localStorage.getItem('easylog_token') || '');
  const user = ref<LoginResult['user'] | null>(null);
  const loading = ref(false);

  const isAuthed = computed(() => !!token.value);

  async function login(email: string, password: string) {
    loading.value = true;
    try {
      const res = await authApi.login({ email, password });
      token.value = res.token;
      user.value = res.user;
      localStorage.setItem('easylog_token', res.token);
      return res;
    } finally {
      loading.value = false;
    }
  }

  async function fetchMe() {
    if (!token.value) return null;
    try {
      user.value = await authApi.me();
      return user.value;
    } catch {
      // token 失效
      logout();
      return null;
    }
  }

  async function logout() {
    try {
      if (token.value) await authApi.logout();
    } catch {
      // ignore
    }
    token.value = '';
    user.value = null;
    localStorage.removeItem('easylog_token');
  }

  return { token, user, loading, isAuthed, login, fetchMe, logout };
});
