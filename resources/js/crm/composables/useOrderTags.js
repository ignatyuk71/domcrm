import { reactive } from 'vue';
import { fetchTags } from '@/crm/api/tags';
import { updateOrderTags } from '@/crm/api/orders';

export function useOrderTags(findOrder) {
  let loaded = false;
  let loadingRequest = null;
  const editor = reactive({
    tags: [],
    loading: false,
    loadError: '',
    saving: {},
    errors: {},
    loadTags,
    toggleTag,
  });

  function loadTags() {
    if (loaded) return Promise.resolve();
    if (loadingRequest) return loadingRequest;
    editor.loading = true;
    editor.loadError = '';
    loadingRequest = (async () => {
      try {
        const { data } = await fetchTags();
        const tags = data?.data ?? data;
        if (!Array.isArray(tags)) throw new Error('Некоректний список тегів');
        editor.tags = tags;
        loaded = true;
      } catch {
        editor.loadError = 'Не вдалося завантажити теги.';
      } finally {
        editor.loading = false;
        loadingRequest = null;
      }
    })();
    return loadingRequest;
  }

  async function toggleTag(order, tag) {
    if (!order?.id || editor.saving[order.id] || editor.loading || editor.loadError) return;
    const id = order.id;
    const previous = [...(order.tags || [])];
    const selected = previous.some((item) => String(item.id) === String(tag.id));
    const next = selected ? previous.filter((item) => String(item.id) !== String(tag.id)) : [...previous, tag];
    editor.saving[id] = true;
    editor.errors[id] = '';
    order.tags = next;

    try {
      // Серіалізуємо зміни одного замовлення, щоб повний список тегів не перезаписав новіший вибір.
      const { data } = await updateOrderTags(id, next.map((item) => item.id));
      const saved = data?.data ?? data;
      if (!Array.isArray(saved)) throw new Error('Некоректна відповідь збереження');
      order.tags = saved;
      const current = findOrder(id);
      if (current && current !== order) current.tags = saved;
    } catch {
      order.tags = previous;
      const current = findOrder(id);
      if (current && current !== order) current.tags = previous;
      editor.errors[id] = 'Не вдалося зберегти тег. Спробуйте натиснути ще раз.';
    } finally {
      delete editor.saving[id];
    }
  }

  return editor;
}
