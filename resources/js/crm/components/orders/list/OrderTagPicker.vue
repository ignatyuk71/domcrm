<script setup>
import InlineChoicePicker from '@/crm/components/ui/InlineChoicePicker.vue';

defineProps({
  tags: { type: Array, default: () => [] },
  selected: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  loadError: { type: String, default: '' },
  error: { type: String, default: '' },
});
const emit = defineEmits(['toggle', 'retry']);
</script>

<template>
  <InlineChoicePicker
    class="tag-picker"
    :options="tags"
    :selected="selected"
    :loading="loading"
    :saving="saving"
    :load-error="loadError"
    :error="error"
    group-label="Теги замовлення"
    extra-label="Інші теги замовлення"
    collapse-label="Згорнути теги"
    item-noun="тегів"
    empty-label="Тегів поки немає"
    @toggle="emit('toggle', $event)"
    @retry="emit('retry')"
  />
</template>
