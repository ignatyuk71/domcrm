import { reactive } from 'vue';
import { fetchStatuses } from '@/crm/api/statuses';
import { updateOrderStatus } from '@/crm/api/orders';

export function applyOrderStatus(order, status) {
  Object.assign(order, {
    status_id: status.id,
    status_key: status.code,
    status: status.name,
    status_icon: status.icon || '',
    status_color: status.color || '',
    status_changed_at: status.status_changed_at ?? null,
  });
}

export function useOrderStatuses(findOrder, onSaved = () => {}) {
  let loaded = false;
  let loadingRequest = null;
  const editor = reactive({
    statuses: [],
    loading: false,
    loadError: '',
    saving: {},
    errors: {},
    loadStatuses,
    selectStatus,
  });

  function loadStatuses() {
    if (loaded) return Promise.resolve();
    if (loadingRequest) return loadingRequest;
    editor.loading = true;
    editor.loadError = '';
    loadingRequest = (async () => {
      try {
        const { data } = await fetchStatuses({ type: 'order' });
        const statuses = data?.data ?? data;
        if (!Array.isArray(statuses)) throw new Error('Некоректний список статусів');
        editor.statuses = statuses;
        loaded = true;
      } catch {
        editor.loadError = 'Не вдалося завантажити статуси.';
      } finally {
        editor.loading = false;
        loadingRequest = null;
      }
    })();
    return loadingRequest;
  }

  async function selectStatus(order, status) {
    if (!order?.id || !status?.id || editor.saving[order.id] || editor.loading || editor.loadError) return;
    // Повторний клік по поточному статусу не скидає його й не створює нового запису в історії.
    if (String(order.status_id) === String(status.id) || (!order.status_id && order.status_key === status.code)) return;
    const id = order.id;
    const previousId = order.status_id ?? editor.statuses.find((item) => item.code === order.status_key)?.id;
    editor.saving[id] = status.id;
    editor.errors[id] = '';
    let saved;
    try {
      const { data } = await updateOrderStatus(id, status.id);
      saved = data?.data ?? data;
      if (!saved?.id || !saved.code || !saved.name) throw new Error('Некоректна відповідь збереження');
      // Підтверджений статус і час його зміни беремо з сервера.
      applyOrderStatus(order, saved);
      const current = findOrder(id);
      if (current && current !== order) applyOrderStatus(current, saved);
    } catch {
      editor.errors[id] = 'Не вдалося змінити статус. Спробуйте натиснути ще раз.';
      return;
    } finally {
      delete editor.saving[id];
    }
    onSaved(previousId, saved);
  }

  return editor;
}
