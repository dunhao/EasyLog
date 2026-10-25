<script setup lang="ts">
defineProps<{
  description?: string;
  icon?: string;
}>();
</script>

<template>
  <el-empty
    :description="description || '功能建设中…'"
    :image-size="120"
  >
    <template #image>
      <el-icon :size="80" color="var(--el-color-info)">
        <component :is="icon || 'DataAnalysis'" />
      </el-icon>
    </template>
  </el-empty>
</template>
