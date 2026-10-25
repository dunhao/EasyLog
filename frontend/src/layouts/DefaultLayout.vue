<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useWorkspaceStore } from '@/stores/workspace';
import { routes } from '@/router';
import type { RouteRecordRaw } from 'vue-router';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const workspace = useWorkspaceStore();

// 从路由元信息中提取主菜单（顶层 layout 的 children）
const menuItems = computed(() => {
  const root = routes.find((r) => r.path === '/');
  const children = (root?.children || []) as RouteRecordRaw[];
  return children
    .filter((c) => c.meta?.nav)
    .map((c) => ({
      index: c.path as string,
      title: c.meta?.title as string,
      icon: c.meta?.icon as string,
    }));
});

const pageTitle = computed(() => (route.meta.title as string) || 'EasyLog');

onMounted(async () => {
  if (auth.isAuthed && !auth.user) {
    await auth.fetchMe();
  }
  await workspace.fetchList();
});

function handleSelect(index: string) {
  router.push('/' + index);
}

async function handleLogout() {
  await auth.logout();
  router.push('/login');
}
</script>

<template>
  <el-container class="layout">
    <el-aside width="220px" class="layout-aside">
      <div class="logo">
        <span class="logo-mark">E</span>
        <span class="logo-text">EasyLog</span>
      </div>
      <el-menu
        :default-active="route.path.replace('/', '')"
        class="menu"
        @select="handleSelect"
      >
        <el-menu-item v-for="item in menuItems" :key="item.index" :index="item.index">
          <el-icon><component :is="item.icon" /></el-icon>
          <template #title>{{ item.title }}</template>
        </el-menu-item>
      </el-menu>
    </el-aside>

    <el-container>
      <el-header class="layout-header">
        <div class="header-left">
          <span class="page-title">{{ pageTitle }}</span>
        </div>
        <div class="header-right">
          <el-select
            v-if="workspace.list.length"
            :model-value="workspace.currentUuid"
            size="small"
            placeholder="选择工作空间"
            @change="workspace.switchByUuid($event)"
            style="width: 200px"
          >
            <el-option
              v-for="ws in workspace.list"
              :key="ws.uuid"
              :label="ws.name"
              :value="ws.uuid"
            />
          </el-select>
          <el-dropdown @command="handleLogout">
            <span class="user-trigger">
              <el-avatar :size="28">{{ auth.user?.name?.[0] || 'U' }}</el-avatar>
              <span class="user-name">{{ auth.user?.name || '未登录' }}</span>
            </span>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="logout">退出登录</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </el-header>

      <el-main class="layout-main">
        <router-view v-slot="{ Component }">
          <keep-alive>
            <component :is="Component" />
          </keep-alive>
        </router-view>
      </el-main>
    </el-container>
  </el-container>
</template>

<style scoped lang="scss">
.layout {
  height: 100vh;
}

.layout-aside {
  background: var(--el-bg-color);
  border-right: 1px solid var(--el-border-color-light);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.logo {
  height: 56px;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 18px;
  border-bottom: 1px solid var(--el-border-color-light);

  .logo-mark {
    display: inline-flex;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    background: #4f6df5;
    color: #fff;
    font-weight: 700;
    align-items: center;
    justify-content: center;
  }
  .logo-text {
    font-size: 16px;
    font-weight: 600;
  }
}

.menu {
  flex: 1;
  border-right: none;
}

.layout-header {
  background: var(--el-bg-color);
  border-bottom: 1px solid var(--el-border-color-light);
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 56px;
  padding: 0 20px;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.page-title {
  font-size: 16px;
  font-weight: 600;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 16px;
}

.user-trigger {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
}

.user-name {
  font-size: 13px;
  color: var(--el-text-color-regular);
}

.layout-main {
  background: var(--el-fill-color-light);
  padding: 20px;
  overflow: auto;
}
</style>
