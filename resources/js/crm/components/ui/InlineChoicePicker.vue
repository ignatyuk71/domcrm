<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
  options: { type: Array, default: () => [] },
  groupLabel: { type: String, required: true },
  extraLabel: { type: String, required: true },
  collapseLabel: { type: String, required: true },
  itemNoun: { type: String, required: true },
  emptyLabel: { type: String, required: true },
  fallbackIcon: { type: String, default: 'bi-plus' },
  selected: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  loadError: { type: String, default: '' },
  error: { type: String, default: '' },
});
const emit = defineEmits(['toggle', 'retry']);
const container = ref(null);
const measurement = ref(null);
const expanded = ref(false);
const visibleCount = ref(0);
let observer;
let disposed = false;
const allOptions = computed(() => {
  const known = new Set(props.options.map((tag) => String(tag.id)));
  return [...props.options, ...props.selected.filter((tag) => !known.has(String(tag.id)))];
});
const selectedIds = computed(() => new Set(props.selected.map((tag) => String(tag.id))));
const firstRow = computed(() => allOptions.value.slice(0, visibleCount.value));
const extraOptions = computed(() => allOptions.value.slice(visibleCount.value));
const disabled = computed(() => props.loading || props.saving || !!props.loadError);
const colors = { red: '#b91c1c', blue: '#1d4ed8', green: '#15803d', amber: '#92400e', gray: '#475569' };

function optionStyle(tag) {
  return { '--choice-color': /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(tag.color || '') ? tag.color : (colors[tag.color] || colors.gray) };
}

async function measure() {
  await nextTick();
  if (disposed || !container.value || !measurement.value) return;
  const width = container.value.clientWidth;
  const widths = Array.from(measurement.value.children, (node) => node.getBoundingClientRect().width);
  const total = widths.reduce((sum, value) => sum + value, 0) + Math.max(0, widths.length - 1) * 6;
  if (total <= width) {
    visibleCount.value = widths.length;
    return;
  }
  // Резервуємо місце для «Ще», орієнтуючись на фактичну ширину підписів.
  let used = 88;
  let count = 0;
  for (const optionWidth of widths) {
    if (used + 6 + optionWidth > width) break;
    used += 6 + optionWidth;
    count++;
  }
  visibleCount.value = count;
}

watch(allOptions, measure, { deep: true });
onMounted(() => {
  observer = new ResizeObserver(measure);
  observer.observe(container.value);
  measure();
  document.fonts?.ready.then(measure);
});
onBeforeUnmount(() => {
  disposed = true;
  observer?.disconnect();
});
</script>

<template>
  <div ref="container" class="choice-picker" :aria-busy="loading || saving" @click.stop>
    <div ref="measurement" class="choice-measurement" aria-hidden="true" inert>
      <span v-for="tag in allOptions" :key="tag.id" class="choice-option">
        <i class="bi bi-check-lg choice-icon"></i><span class="choice-name">{{ tag.name }}</span>
      </span>
    </div>
    <div class="choice-first-row" role="group" :aria-label="groupLabel">
      <button v-for="tag in firstRow" :key="tag.id" type="button" class="choice-option" :class="{ 'is-selected': selectedIds.has(String(tag.id)) }" :style="optionStyle(tag)" :aria-pressed="selectedIds.has(String(tag.id))" :aria-label="tag.name" :title="tag.name" :disabled="disabled" @click="emit('toggle', tag)">
        <i class="bi choice-icon" :class="selectedIds.has(String(tag.id)) ? 'bi-check-lg' : (tag.icon || fallbackIcon)" aria-hidden="true"></i><span class="choice-name">{{ tag.name }}</span>
      </button>
      <button v-if="extraOptions.length" type="button" class="choice-more" :aria-expanded="expanded" :aria-label="expanded ? collapseLabel : `Показати ще ${extraOptions.length} ${itemNoun}`" @click="expanded = !expanded">
        {{ expanded ? 'Згорнути' : `Ще +${extraOptions.length}` }}<i class="bi" :class="expanded ? 'bi-chevron-up' : 'bi-chevron-down'" aria-hidden="true"></i>
      </button>
      <span v-if="!allOptions.length && !loading && !loadError" class="small text-muted">{{ emptyLabel }}</span>
    </div>
    <div v-if="expanded && extraOptions.length" class="choice-extra-row" role="group" :aria-label="extraLabel">
      <button v-for="tag in extraOptions" :key="tag.id" type="button" class="choice-option" :class="{ 'is-selected': selectedIds.has(String(tag.id)) }" :style="optionStyle(tag)" :aria-pressed="selectedIds.has(String(tag.id))" :aria-label="tag.name" :title="tag.name" :disabled="disabled" @click="emit('toggle', tag)">
        <i class="bi choice-icon" :class="selectedIds.has(String(tag.id)) ? 'bi-check-lg' : (tag.icon || fallbackIcon)" aria-hidden="true"></i><span class="choice-name">{{ tag.name }}</span>
      </button>
    </div>
    <span v-if="loading || saving" class="choice-feedback text-muted" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span>{{ loading ? 'Завантаження…' : 'Збереження…' }}</span>
    <div v-if="loadError || error" class="choice-feedback text-danger" role="alert">
      {{ loadError || error }}
      <button v-if="loadError" type="button" class="btn btn-link btn-sm p-0" @click="emit('retry')">Повторити</button>
    </div>
  </div>
</template>

<style scoped>
.choice-picker { position: relative; min-width: 0; }
.choice-first-row, .choice-extra-row { display: flex; align-items: center; gap: 6px; min-width: 0; }
.choice-extra-row { flex-wrap: wrap; margin-top: 6px; }
.choice-option { display: inline-flex; align-items: center; flex: 0 0 auto; gap: 5px; max-width: 156px; height: 28px; padding: 3px 8px; border: 1px solid #cbd5e1; border-radius: 999px; background: #fff; color: #64748b; font-size: 12px; font-weight: 600; line-height: 20px; white-space: nowrap; }
.choice-name { overflow: hidden; text-overflow: ellipsis; }
.choice-icon { flex: 0 0 12px; width: 12px; font-size: 12px; }
.choice-option:hover:not(:disabled) { border-color: var(--choice-color); color: var(--choice-color); }
.choice-option.is-selected { border-color: var(--choice-color); color: var(--choice-color); background: color-mix(in srgb, var(--choice-color) 10%, white); }
.choice-option:disabled { cursor: wait; }
.choice-option:focus-visible, .choice-more:focus-visible { outline: 2px solid #3b82f6; outline-offset: 2px; }
.choice-more { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 88px; gap: 5px; height: 28px; padding: 3px 6px; border: 1px dashed #cbd5e1; border-radius: 999px; background: #f8fafc; color: #475569; font-size: 12px; font-weight: 600; white-space: nowrap; }
.choice-more:hover { background: #eef2ff; border-color: #6366f1; color: #4338ca; }
.choice-feedback { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-top: 5px; font-size: 12px; }
.choice-feedback .spinner-border { width: 12px; height: 12px; }
.choice-measurement { position: absolute; display: flex; gap: 6px; width: 100%; height: 0; overflow: hidden; visibility: hidden; pointer-events: none; }
</style>
