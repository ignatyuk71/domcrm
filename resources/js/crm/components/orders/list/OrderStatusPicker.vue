<script setup>
import { computed } from 'vue';
import InlineChoicePicker from '@/crm/components/ui/InlineChoicePicker.vue';
import { getStatusIcon, getStatusStyle } from '@/crm/utils/orderDisplay';

const props = defineProps({
  order: { type: Object, required: true },
  editor: { type: Object, required: true },
});

function option(status) {
  return {
    ...status,
    color: getStatusStyle({ status_color: status.color, status_key: status.code }).color,
    icon: status.icon || getStatusIcon(status.code),
  };
}

const options = computed(() => props.editor.statuses.map(option));
const selected = computed(() => {
  const pendingId = props.editor.saving[props.order.id];
  const id = pendingId ?? props.order.status_id;
  const current = options.value.find((status) => id != null
    ? String(status.id) === String(id)
    : status.code === props.order.status_key);
  if (current) return [current];
  if (!props.order.status_id) return [];
  return [option({ id: props.order.status_id, code: props.order.status_key, name: props.order.status, color: props.order.status_color, icon: props.order.status_icon })];
});
</script>

<template>
  <InlineChoicePicker
    class="status-picker"
    :options="options"
    :selected="selected"
    :loading="editor.loading"
    :saving="!!editor.saving[order.id]"
    :load-error="editor.loadError"
    :error="editor.errors[order.id] || ''"
    group-label="Статус замовлення"
    extra-label="Інші статуси замовлення"
    collapse-label="Згорнути статуси"
    item-noun="статусів"
    empty-label="Статусів поки немає"
    fallback-icon="bi-circle"
    @toggle="editor.selectStatus(order, $event)"
    @retry="editor.loadStatuses()"
  />
</template>
