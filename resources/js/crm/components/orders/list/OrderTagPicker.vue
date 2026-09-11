<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
  tags: { type: Array, default: () => [] },
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
const allTags = computed(() => {
  const known = new Set(props.tags.map((tag) => String(tag.id)));
  return [...props.tags, ...props.selected.filter((tag) => !known.has(String(tag.id)))];
});
const selectedIds = computed(() => new Set(props.selected.map((tag) => String(tag.id))));
const firstRow = computed(() => allTags.value.slice(0, visibleCount.value));
const extraTags = computed(() => allTags.value.slice(visibleCount.value));
const disabled = computed(() => props.loading || props.saving || !!props.loadError);
const colors = { red: '#b91c1c', blue: '#1d4ed8', green: '#15803d', amber: '#92400e', gray: '#475569' };

function tagStyle(tag) {
  return { '--tag-color': /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(tag.color || '') ? tag.color : (colors[tag.color] || colors.gray) };
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
  for (const tagWidth of widths) {
    if (used + 6 + tagWidth > width) break;
    used += 6 + tagWidth;
    count++;
  }
  visibleCount.value = count;
}

watch(allTags, measure, { deep: true });
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
  <div ref="container" class="tag-picker" :aria-busy="loading || saving" @click.stop>
    <div ref="measurement" class="tag-measurement" aria-hidden="true" inert>
      <span v-for="tag in allTags" :key="tag.id" class="tag-option">
        <i class="bi bi-check-lg tag-icon"></i><span class="tag-name">{{ tag.name }}</span>
      </span>
    </div>
    <div class="tag-first-row" role="group" aria-label="Теги замовлення">
      <button v-for="tag in firstRow" :key="tag.id" type="button" class="tag-option" :class="{ 'is-selected': selectedIds.has(String(tag.id)) }" :style="tagStyle(tag)" :aria-pressed="selectedIds.has(String(tag.id))" :aria-label="tag.name" :title="tag.name" :disabled="disabled" @click="emit('toggle', tag)">
        <i class="bi tag-icon" :class="selectedIds.has(String(tag.id)) ? 'bi-check-lg' : (tag.icon || 'bi-plus')" aria-hidden="true"></i><span class="tag-name">{{ tag.name }}</span>
      </button>
      <button v-if="extraTags.length" type="button" class="tag-more" :aria-expanded="expanded" :aria-label="expanded ? 'Згорнути теги' : `Показати ще ${extraTags.length} тегів`" @click="expanded = !expanded">
        {{ expanded ? 'Згорнути' : `Ще +${extraTags.length}` }}<i class="bi" :class="expanded ? 'bi-chevron-up' : 'bi-chevron-down'" aria-hidden="true"></i>
      </button>
      <span v-if="!allTags.length && !loading && !loadError" class="small text-muted">Тегів поки немає</span>
    </div>
    <div v-if="expanded && extraTags.length" class="tag-extra-row" role="group" aria-label="Інші теги замовлення">
      <button v-for="tag in extraTags" :key="tag.id" type="button" class="tag-option" :class="{ 'is-selected': selectedIds.has(String(tag.id)) }" :style="tagStyle(tag)" :aria-pressed="selectedIds.has(String(tag.id))" :aria-label="tag.name" :title="tag.name" :disabled="disabled" @click="emit('toggle', tag)">
        <i class="bi tag-icon" :class="selectedIds.has(String(tag.id)) ? 'bi-check-lg' : (tag.icon || 'bi-plus')" aria-hidden="true"></i><span class="tag-name">{{ tag.name }}</span>
      </button>
    </div>
    <span v-if="loading || saving" class="tag-feedback text-muted" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span>{{ loading ? 'Завантаження…' : 'Збереження…' }}</span>
    <div v-if="loadError || error" class="tag-feedback text-danger" role="alert">
      {{ loadError || error }}
      <button v-if="loadError" type="button" class="btn btn-link btn-sm p-0" @click="emit('retry')">Повторити</button>
    </div>
  </div>
</template>

<style scoped>
.tag-picker { position: relative; min-width: 0; }
.tag-first-row, .tag-extra-row { display: flex; align-items: center; gap: 6px; min-width: 0; }
.tag-extra-row { flex-wrap: wrap; margin-top: 6px; }
.tag-option { display: inline-flex; align-items: center; flex: 0 0 auto; gap: 5px; max-width: 156px; height: 28px; padding: 3px 8px; border: 1px solid #cbd5e1; border-radius: 999px; background: #fff; color: #64748b; font-size: 12px; font-weight: 600; line-height: 20px; white-space: nowrap; }
.tag-name { overflow: hidden; text-overflow: ellipsis; }
.tag-icon { flex: 0 0 12px; width: 12px; font-size: 12px; }
.tag-option:hover:not(:disabled) { border-color: var(--tag-color); color: var(--tag-color); }
.tag-option.is-selected { border-color: var(--tag-color); color: var(--tag-color); background: color-mix(in srgb, var(--tag-color) 10%, white); }
.tag-option:disabled { cursor: wait; }
.tag-option:focus-visible, .tag-more:focus-visible { outline: 2px solid #3b82f6; outline-offset: 2px; }
.tag-more { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 88px; gap: 5px; height: 28px; padding: 3px 6px; border: 1px dashed #cbd5e1; border-radius: 999px; background: #f8fafc; color: #475569; font-size: 12px; font-weight: 600; white-space: nowrap; }
.tag-more:hover { background: #eef2ff; border-color: #6366f1; color: #4338ca; }
.tag-feedback { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-top: 5px; font-size: 12px; }
.tag-feedback .spinner-border { width: 12px; height: 12px; }
.tag-measurement { position: absolute; display: flex; gap: 6px; width: 100%; height: 0; overflow: hidden; visibility: hidden; pointer-events: none; }
</style>
