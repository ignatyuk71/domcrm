<script setup>
import { computed, ref, watch } from 'vue';
import Toast from '@/crm/components/ui/Toast.vue';
import { useToast } from '@/crm/composables/useToast';
import { getStatusIcon, getStatusStyle } from '@/crm/utils/orderDisplay';

const props = defineProps({
  order: { type: Object, required: true },
  editor: { type: Object, required: true },
});

// Скорочуємо лише підписи кнопок; назви в довіднику та підказках залишаються повними.
const shortNames = {
  'Підтверджено': 'Підтв.',
  'Упакування': 'Пакування',
  'Комплектується': 'Пакування',
  'Запаковано': 'Запак.',
  'Відправлено': 'Відправ.',
  'У відділенні': 'Відділення',
  'Завершено': 'Заверш.',
  'Повернення': 'Поверн.',
  'Очікує оплату': 'Очік. оплати',
};

function option(status) {
  return {
    ...status,
    shortName: shortNames[status.name] || status.name,
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
const availableOptions = computed(() => {
  const ids = new Set(options.value.map((status) => String(status.id)));
  return [...options.value, ...selected.value.filter((status) => !ids.has(String(status.id)))];
});
const saving = computed(() => !!props.editor.saving[props.order.id]);
const disabled = computed(() => props.editor.loading || saving.value || !!props.editor.loadError);
const error = computed(() => props.editor.loadError || props.editor.errors[props.order.id] || '');
const requestedStatus = ref(null);
const { toast, showToast, closeToast, runAction } = useToast();
const isSelected = (status) => selected.value.some((current) => String(current.id) === String(status.id));

function selectStatus(status) {
  if (disabled.value || isSelected(status)) return;
  requestedStatus.value = status;
  props.editor.selectStatus(props.order, status);
}

watch([error, () => props.order.id], ([message]) => {
  if (!message) return;
  const retry = props.editor.loadError
    ? () => props.editor.loadStatuses()
    : requestedStatus.value ? () => selectStatus(requestedStatus.value) : null;
  showToast({
    type: 'error', title: props.editor.loadError ? 'Статуси не завантажено' : 'Статус замовлення не збережено',
    messages: [`Замовлення №${props.order.order_number || props.order.id}.`, message],
    actionLabel: retry ? 'Повторити' : '', onAction: retry,
  });
}, { immediate: true });
</script>

<template>
  <div class="status-picker" :aria-busy="editor.loading || saving" @click.stop>
    <div class="status-tabs" role="group" aria-label="Статус замовлення" :aria-invalid="!!error || undefined">
      <button
        v-for="status in availableOptions"
        :key="status.id"
        type="button"
        class="status-tab"
        :class="{ 'is-selected': isSelected(status), 'has-error': error && requestedStatus?.id === status.id }"
        :style="{ '--status-color': status.color }"
        :aria-pressed="isSelected(status)"
        :aria-label="status.name"
        :title="status.name"
        :disabled="disabled"
        @click="selectStatus(status)"
      >
        <span v-if="saving && isSelected(status)" class="spinner-border spinner-border-sm" role="status" aria-label="Збереження статусу"></span>
        <i v-else class="bi" :class="status.icon" aria-hidden="true"></i>
        <span>{{ status.shortName }}</span>
      </button>
      <span v-if="editor.loading" class="status-placeholder" role="status">Завантаження…</span>
      <span v-else-if="!availableOptions.length" class="status-placeholder">{{ editor.loadError ? 'Статуси недоступні' : 'Статусів поки немає' }}</span>
    </div>
    <Toast v-bind="toast" @close="closeToast" @action="runAction" />
  </div>
</template>

<style scoped>
.status-picker { min-width: 0; }
/* Один ряд із прокруткою зберігає тонку шапку на вузьких екранах. */
.status-tabs { display: flex; align-items: stretch; min-width: 0; overflow-x: auto; scrollbar-width: thin; }
.status-tab { position: relative; display: inline-flex; align-items: center; justify-content: center; flex: 1 0 auto; gap: 7px; min-height: 36px; padding: 6px 12px; border: 0; border-bottom: 2px solid transparent; background: transparent; color: #64748b; font: inherit; font-size: 0.75rem; font-weight: 600; white-space: nowrap; }
.status-tab:not(:last-of-type)::after { position: absolute; right: 0; top: 10px; bottom: 10px; width: 1px; background: #e2e8f0; content: ''; }
.status-tab .bi { font-size: 0.95rem; }
.status-tab.is-selected { border-bottom-color: var(--status-color); color: var(--status-color); }
.status-tab:hover:not(:disabled) { background: #f8fafc; color: var(--status-color); }
.status-tab:focus-visible { outline: 2px solid #3b82f6; outline-offset: -3px; }
.status-tab:disabled { cursor: wait; }
.status-tab.has-error { box-shadow: inset 0 0 0 1px #dc3545; }
.status-tab .spinner-border { width: 14px; height: 14px; border-width: 2px; }
.status-placeholder { align-self: center; padding: 8px 12px; color: #64748b; font-size: 0.75rem; white-space: nowrap; }
</style>
