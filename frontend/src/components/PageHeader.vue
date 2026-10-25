<script setup lang="ts">
defineProps<{
  title: string;
  subtitle?: string;
}>();
</script>

<template>
  <div class="page-header">
    <div class="page-header-text">
      <h2 class="page-header-title">{{ title }}</h2>
      <p v-if="subtitle" class="page-header-subtitle">{{ subtitle }}</p>
    </div>
    <div class="page-header-actions">
      <slot name="actions" />
    </div>
  </div>
</template>

<style scoped lang="scss">
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 16px;
  gap: 16px;

  .page-header-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
  }
  .page-header-subtitle {
    margin: 4px 0 0 0;
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }
  .page-header-actions {
    display: flex;
    gap: 8px;
    align-items: center;
  }
}
</style>
