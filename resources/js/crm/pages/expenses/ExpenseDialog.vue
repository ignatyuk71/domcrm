<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';
const props = defineProps({ title: { type: String, required: true }, wide: Boolean, editor: Boolean, subtitle: String, busy: Boolean });
const emit = defineEmits(['close']);
const panel = ref(null), titleId = useId();
let previousFocus, previousOverflow;
const focusable = () => [...(panel.value?.querySelectorAll('button, input, select, textarea, a[href], [tabindex="0"]') || [])].filter(el => el.tabIndex >= 0 && !el.matches(':disabled') && !el.hidden);
function keydown(event) {
  if (event.key === 'Escape') { event.preventDefault(); if (!props.busy) emit('close'); }
  if (event.key !== 'Tab') return;
  const items = focusable(), first = items[0], last = items.at(-1);
  if (!items.length) { event.preventDefault(); panel.value?.focus(); }
  else if (event.shiftKey && (document.activeElement === first || document.activeElement === panel.value)) { event.preventDefault(); last.focus(); }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
}
onMounted(async () => {
  previousFocus = document.activeElement; previousOverflow = document.body.style.overflow;
  document.body.style.overflow = 'hidden';
  await nextTick(); panel.value?.focus();
});
onBeforeUnmount(() => { document.body.style.overflow = previousOverflow; previousFocus?.focus?.(); });
</script>
<template>
  <Teleport to="body">
    <div class="expense-overlay" @mousedown.self="!busy && emit('close')">
      <section ref="panel" class="expense-dialog" :class="{ 'expense-dialog-wide': wide, 'expense-dialog-editor': editor }" role="dialog" aria-modal="true" :aria-labelledby="titleId" tabindex="-1" @keydown="keydown">
        <header class="expense-dialog-head"><div><h2 :id="titleId">{{ title }}</h2><p v-if="subtitle" class="expense-dialog-subtitle">{{ subtitle }}</p></div><button type="button" class="expense-icon-button" aria-label="Закрити діалог" :disabled="busy" @click="emit('close')"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header>
        <slot />
      </section>
    </div>
  </Teleport>
</template>
