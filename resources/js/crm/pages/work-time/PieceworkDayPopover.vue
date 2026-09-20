<template>
  <Teleport to="body">
    <form ref="panel" class="piece-day-popover" role="dialog" aria-labelledby="piece-day-title"
      :style="position" :aria-busy="saving" @submit.prevent="submit" @keydown.esc.stop.prevent="requestClose" @keydown.tab="keepFocus">
      <header>
        <div><h3 id="piece-day-title">Нарахування за роботу</h3><p>{{ employee.name }} · {{ formattedDate }}</p></div>
        <button type="button" class="piece-close" aria-label="Закрити нарахування" :disabled="saving" @click="requestClose"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
      </header>
      <label for="piece-day-amount">Сума, грн</label>
      <input id="piece-day-amount" ref="amountInput" v-model="amount" class="form-control" type="text" inputmode="decimal"
        maxlength="13" autocomplete="off" :disabled="saving" :aria-invalid="invalidAmount || !!error" />
      <label for="piece-day-note">За що нараховано <span>необов’язково</span></label>
      <textarea id="piece-day-note" v-model="note" class="form-control" rows="2" maxlength="500" :disabled="saving"
        :aria-invalid="invalidNote || !!error" placeholder="Наприклад, допомога в цеху в неділю" />
      <p class="piece-hint">Нарахування за день, не факт виплати.</p>
      <footer><button type="button" class="btn piece-cancel" :disabled="saving" @click="cancel">Скасувати</button><button type="submit" class="btn piece-save" :disabled="saving">{{ saving ? 'Зберігаємо…' : 'Зберегти' }}</button></footer>
    </form>
  </Teleport>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { parseWorkAmount } from '../../utils/workTime';

const props = defineProps({ employee: { type: Object, required: true }, date: { type: String, required: true },
  initialAmount: { type: String, default: '' }, initialNote: { type: String, default: '' },
  anchor: { type: Object, required: true }, saving: Boolean, error: { type: String, default: '' } });
const emit = defineEmits(['save', 'close', 'notice']);
const amount = ref(props.initialAmount), note = ref(props.initialNote), panel = ref(null), amountInput = ref(null);
const invalidAmount = ref(false), invalidNote = ref(false), position = ref({});
const dirty = computed(() => amount.value !== props.initialAmount || note.value !== props.initialNote);
const formattedDate = computed(() => new Intl.DateTimeFormat('uk-UA', { day: 'numeric', month: 'long' }).format(new Date(`${props.date}T12:00:00`)));
let alive = true;

function reposition() {
  if (!panel.value) return;
  const anchor = props.anchor.getBoundingClientRect(), box = panel.value.getBoundingClientRect();
  const viewport = window.visualViewport;
  const width = viewport?.width || window.innerWidth, height = viewport?.height || window.innerHeight;
  const left = viewport?.offsetLeft || 0, top = viewport?.offsetTop || 0;
  const below = anchor.bottom + 8;
  position.value = {
    left: `${Math.max(left + 12, Math.min(anchor.left - 16, left + width - box.width - 12))}px`,
    top: `${Math.max(top + 12, Math.min(below + box.height <= top + height - 12 ? below : anchor.top - box.height - 8, top + height - box.height - 12))}px`,
    maxHeight: `${Math.max(100, height - 24)}px`,
  };
}
function cancel() { if (!props.saving) emit('close'); }
function requestClose() {
  if (props.saving) return;
  if (!dirty.value) { cancel(); return; }
  emit('notice', { type: 'warning', title: 'Є незбережені зміни',
    messages: ['Збережіть нарахування або натисніть «Скасувати», щоб закрити без цих змін.'] });
}
function outside(event) {
  if (panel.value?.contains(event.target) || props.anchor.contains(event.target) || event.target.closest?.('.app-toast')) return;
  requestClose();
}
function keepFocus(event) {
  const nodes = panel.value.querySelectorAll('button:not(:disabled), input:not(:disabled), textarea:not(:disabled)');
  const first = nodes[0], last = nodes[nodes.length - 1];
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
}
function submit() {
  if (props.saving) return;
  invalidAmount.value = false; invalidNote.value = false;
  try { parseWorkAmount(amount.value); }
  catch (error) {
    invalidAmount.value = true;
    emit('notice', { type: 'error', title: 'Перевірте суму', messages: [error.message] });
    amountInput.value?.focus(); return;
  }
  if (Array.from(note.value.trim()).length > 500) {
    invalidNote.value = true;
    emit('notice', { type: 'error', title: 'Скоротіть пояснення', messages: ['Пояснення може містити до 500 символів.'] }); return;
  }
  emit('save', { amount: amount.value, note: note.value.trim() });
}
function unload(event) { if (dirty.value || props.saving) { event.preventDefault(); event.returnValue = ''; } }
onMounted(async () => {
  await nextTick(); if (!alive) return;
  reposition(); amountInput.value?.focus(); amountInput.value?.select();
  document.addEventListener('pointerdown', outside);
  window.addEventListener('resize', reposition);
  window.addEventListener('scroll', reposition, true);
  window.visualViewport?.addEventListener('resize', reposition);
  window.visualViewport?.addEventListener('scroll', reposition);
  window.addEventListener('beforeunload', unload);
});
onBeforeUnmount(() => {
  alive = false;
  document.removeEventListener('pointerdown', outside);
  window.removeEventListener('resize', reposition);
  window.removeEventListener('scroll', reposition, true);
  window.visualViewport?.removeEventListener('resize', reposition);
  window.visualViewport?.removeEventListener('scroll', reposition);
  window.removeEventListener('beforeunload', unload);
});
</script>

<style scoped>
.piece-day-popover{position:fixed;z-index:1055;box-sizing:border-box;width:min(320px,calc(100vw - 24px));padding:16px;background:#fff;border:1px solid #dce9e4;border-radius:14px;box-shadow:0 14px 44px #203b3430;color:#28344c;overflow:auto}
header{display:flex;gap:10px;align-items:flex-start;justify-content:space-between;margin-bottom:16px}h3{font-size:15px;font-weight:750;margin:0 0 5px}header p{font-size:11px;color:#79869a;margin:0;overflow-wrap:anywhere}
.piece-close{flex-shrink:0;border:0;border-radius:6px;background:transparent;color:#79869a;width:26px;height:26px}.piece-close:hover,.piece-cancel:hover{background:#f2f5f8}
label{display:block;font-size:12px;font-weight:650;margin:12px 0 6px}label span{font-size:10px;font-weight:400;color:#8490a1;float:right}
.form-control{border:1px solid #dfe6ed;border-radius:8px;font-size:14px;color:#28344c;padding:8px 10px;min-height:38px}.form-control:focus{border-color:#55ac92;box-shadow:0 0 0 3px #55ac921a}.form-control[aria-invalid=true]{border-color:#c44758}.form-control:disabled{background:#f6f8fa}textarea{resize:vertical;max-height:150px}
.piece-hint{font-size:10px;color:#7d899b;margin:9px 0 14px}footer{display:flex;justify-content:flex-end;gap:8px}.btn{font-size:12px;padding:8px 12px;border-radius:8px}.piece-cancel{border:1px solid #e2e8ef;color:#66768b}.piece-save{background:#268671;color:white;border:1px solid #268671}.piece-save:hover{background:#1b705e;color:white}button:focus-visible{outline:2px solid #55ac92;outline-offset:2px}button:disabled{opacity:.6}
@media(pointer:coarse){.form-control{font-size:16px}.btn,.piece-close{min-height:44px}.piece-close{min-width:32px}}
</style>
