import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { workspaceApi, type Workspace } from '@/api';

export const useWorkspaceStore = defineStore('workspace', () => {
  const list = ref<Workspace[]>([]);
  const current = ref<Workspace | null>(null);
  const loading = ref(false);

  const currentUuid = computed(() => {
    const saved = localStorage.getItem('easylog_workspace_uuid');
    if (saved) return saved;
    return current.value?.uuid || list.value[0]?.uuid || '';
  });

  async function fetchList() {
    loading.value = true;
    try {
      const res = await workspaceApi.list({ page: 1, page_size: 100 });
      list.value = res.data;
      if (!current.value && list.value.length) {
        setCurrent(list.value[0]);
      }
      return list.value;
    } finally {
      loading.value = false;
    }
  }

  function setCurrent(ws: Workspace | null) {
    current.value = ws;
    if (ws) localStorage.setItem('easylog_workspace_uuid', ws.uuid);
  }

  function switchByUuid(uuid: string) {
    const found = list.value.find((w) => w.uuid === uuid);
    if (found) setCurrent(found);
  }

  return { list, current, loading, currentUuid, fetchList, setCurrent, switchByUuid };
});
