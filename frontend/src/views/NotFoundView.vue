<script setup lang="ts">
import { useRouter } from 'vue-router';
const router = useRouter();
</script>

<template>
  <div class="not-found">
    <div class="nf-code">404</div>
    <p class="nf-text">您访问的页面不存在</p>
    <el-button type="primary" @click="router.push('/')">返回首页</el-button>
  </div>
</template>

<style scoped lang="scss">
.not-found {
  height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
}
.nf-code {
  font-size: 96px;
  font-weight: 800;
  color: #4f6df5;
  line-height: 1;
}
.nf-text {
  color: var(--el-text-color-secondary);
  margin-bottom: 8px;
}
</style>
