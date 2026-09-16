<template>
  <div class="period-picker">
    <button ref="trigger" type="button" class="period-trigger" :class="{ 'is-open': open }" :disabled="disabled"
      aria-haspopup="dialog" :aria-expanded="open" :aria-controls="dialogId" :aria-label="'Період аналітики: ' + selectedLabel" @click="openPicker">
      <span class="period-icon"><i class="bi bi-calendar3" aria-hidden="true"></i></span>
      <span class="period-value"><span>Період аналітики</span><strong>{{ selectedLabel }}</strong></span>
      <span v-if="selectedDays" class="period-duration">{{ daysLabel(selectedDays) }}</span>
      <i class="bi bi-chevron-down period-chevron" aria-hidden="true"></i>
    </button>

    <Teleport to="body">
      <div v-if="open" class="period-backdrop" @click.self="closePicker">
        <div :id="dialogId" ref="panel" class="period-popover" :style="position" role="dialog" aria-modal="true"
          :aria-labelledby="dialogId + '-title'" tabindex="-1" @keydown="onDialogKeydown">
          <header class="period-heading">
            <h2 :id="dialogId + '-title'">Оберіть період</h2>
            <button type="button" class="calendar-icon-btn" aria-label="Закрити календар" @click="closePicker"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
          </header>
          <div class="period-presets" role="group" aria-label="Швидкий вибір періоду">
            <button v-for="preset in presets" :key="preset.key" type="button" class="preset-btn" :class="{ active: draftFrom === preset.from && draftTo === preset.to }"
              :aria-pressed="draftFrom === preset.from && draftTo === preset.to" @click="choosePreset(preset)">{{ preset.label }}</button>
          </div>
          <div class="period-inputs">
            <label><span>Початок</span><input v-model="draftFrom" name="date_from" type="date" required :aria-invalid="!!rangeError" :aria-describedby="dialogId + '-hint'" @change="manualDate('from')" @keydown.enter.prevent="confirm"></label>
            <span class="period-input-arrow" aria-hidden="true"><i class="bi bi-arrow-right"></i></span>
            <label><span>Кінець</span><input v-model="draftTo" name="date_to" type="date" required :min="draftFrom" :aria-invalid="!!rangeError" :aria-describedby="dialogId + '-hint'" @change="manualDate('to')" @keydown.enter.prevent="confirm"></label>
          </div>
          <div class="calendar-navigation">
            <button type="button" class="calendar-icon-btn previous-month" :disabled="view.getFullYear() === 1 && view.getMonth() === 0" aria-label="Попередній місяць" @click="moveMonth(-1)"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
            <div class="calendar-month-selects">
              <select :value="view.getMonth()" aria-label="Місяць" @change="changeMonth(Number($event.target.value))"><option v-for="(month, index) in monthNames" :key="month" :value="index">{{ month }}</option></select>
              <select :value="view.getFullYear()" aria-label="Рік" @change="changeYear(Number($event.target.value))"><option v-for="year in years" :key="year" :value="year">{{ year }}</option></select>
            </div>
            <button type="button" class="calendar-icon-btn next-month" :disabled="view.getFullYear() === 9999 && view.getMonth() === 11" aria-label="Наступний місяць" @click="moveMonth(1)"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
          </div>
          <div class="calendar-grid" role="grid" aria-multiselectable="true" :aria-label="monthLabel" :aria-describedby="dialogId + '-hint'" @mouseleave="hoverDate = ''">
            <div class="calendar-weekdays" role="row"><span v-for="day in weekdays" :key="day" role="columnheader">{{ day }}</span></div>
            <div v-for="(week, index) in weeks" :key="index" class="calendar-week" role="row">
              <div v-for="day in week" :key="day.iso" role="gridcell" :aria-selected="isSelected(day.iso)"
                class="calendar-cell" :class="{ 'in-range': inRange(day.iso), 'range-start': day.iso === previewFrom, 'range-end': day.iso === previewTo }">
                <button type="button" class="calendar-day" :class="{ selected: isEndpoint(day.iso), outside: !day.currentMonth, today: day.iso === today }"
                  :data-date="day.iso" :disabled="!day.valid" :tabindex="focusedDate === day.iso && day.valid ? 0 : -1" :aria-label="day.label" :aria-current="day.iso === today ? 'date' : undefined"
                  @click="selectDate(day.iso)" @mouseenter="hoverDate = day.iso" @focus="focusedDate = day.iso" @keydown="onDayKeydown($event, day.iso)">{{ day.number }}</button>
              </div>
            </div>
          </div>
          <p :id="dialogId + '-hint'" class="period-hint" :class="{ invalid: !!rangeError }" aria-live="polite">{{ rangeError || (awaitingEnd ? 'Тепер оберіть кінцеву дату' : 'Натисніть початкову та кінцеву дату') }}</p>
          <footer class="period-footer">
            <span><strong>{{ daysLabel(draftDays) || 'Оберіть дати' }}</strong><small>включно з обома датами</small></span>
            <button type="button" class="period-confirm" :disabled="!!rangeError || !draftDays" @click="confirm">Показати період <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
          </footer>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId } from 'vue';
import { dateISO, datePresets, dayCount, daysLabel, formatDateRange, parseDate, todayInKyiv } from '@/crm/utils/dateRange';

const props = defineProps({ from: { type: String, required: true }, to: { type: String, required: true }, disabled: Boolean });
const emit = defineEmits(['apply']);
const dialogId = useId() + '-date-range';
const trigger = ref(null);
const panel = ref(null);
const open = ref(false);
const today = ref(todayInKyiv());
const draftFrom = ref('');
const draftTo = ref('');
const awaitingEnd = ref(false);
const hoverDate = ref('');
const focusedDate = ref('');
const view = ref(parseDate(today.value));
const position = ref({});
let previousOverflow;
const weekdays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Нд'];
const dayFormatter = new Intl.DateTimeFormat('uk-UA', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
const monthNames = Array.from({ length: 12 }, (_, month) => new Intl.DateTimeFormat('uk-UA', { month: 'long' }).format(new Date(2026, month, 1)));
const selectedLabel = computed(() => formatDateRange(props.from, props.to));
const selectedDays = computed(() => dayCount(props.from, props.to));
const draftDays = computed(() => dayCount(draftFrom.value, draftTo.value));
const presets = computed(() => datePresets(today.value));
const rangeError = computed(() => {
  if (awaitingEnd.value && !draftTo.value) return '';
  if (!parseDate(draftFrom.value) || !parseDate(draftTo.value)) return 'Вкажіть обидві коректні дати.';
  if (draftFrom.value > draftTo.value) return 'Кінцева дата не може бути раніше початкової.';
  return draftDays.value > 731 ? 'Максимальний період — 731 день.' : '';
});
const monthLabel = computed(() => new Intl.DateTimeFormat('uk-UA', { month: 'long', year: 'numeric' }).format(view.value));
const years = computed(() => {
  const current = parseDate(today.value).getFullYear();
  return [...new Set([...Array.from({ length: 23 }, (_, index) => current - 20 + index), view.value.getFullYear()])].sort((a, b) => a - b);
});
const preview = computed(() => {
  const end = awaitingEnd.value ? (hoverDate.value || draftFrom.value) : draftTo.value;
  return [draftFrom.value, end].filter(Boolean).sort();
});
const previewFrom = computed(() => preview.value[0]);
const previewTo = computed(() => preview.value[1] || preview.value[0]);
const inRange = (iso) => iso >= previewFrom.value && iso <= previewTo.value;
const isEndpoint = (iso) => iso === draftFrom.value || iso === draftTo.value;
const isSelected = (iso) => !!draftDays.value && iso >= draftFrom.value && iso <= draftTo.value;
const weeks = computed(() => {
  const first = new Date(view.value);
  first.setDate(1);
  first.setDate(1 - (first.getDay() + 6) % 7);
  return Array.from({ length: 6 }, (_, row) => Array.from({ length: 7 }, (_, column) => {
    const date = new Date(first);
    date.setDate(first.getDate() + row * 7 + column);
    return { iso: dateISO(date), number: date.getDate(), currentMonth: date.getMonth() === view.value.getMonth(), valid: date.getFullYear() >= 1 && date.getFullYear() <= 9999, label: dayFormatter.format(date) };
  }));
});

function reposition() {
  if (!open.value) return;
  if (window.innerWidth <= 575) { position.value = {}; return; }
  const rect = trigger.value.getBoundingClientRect();
  const height = panel.value?.offsetHeight || 590;
  const width = Math.min(368, window.innerWidth - 24);
  position.value = {
    left: Math.max(12, Math.min(rect.left, window.innerWidth - width - 12)) + 'px',
    top: Math.max(12, Math.min(rect.bottom + 8, window.innerHeight - height - 12)) + 'px',
  };
}

async function focusDay(iso) {
  focusedDate.value = iso;
  await nextTick();
  panel.value?.querySelector(`[data-date="${iso}"]`)?.focus();
}

async function openPicker() {
  if (props.disabled || open.value) return;
  today.value = todayInKyiv();
  draftFrom.value = props.from;
  draftTo.value = props.to;
  awaitingEnd.value = false;
  hoverDate.value = '';
  const initial = parseDate(props.from) || parseDate(today.value);
  view.value = initial;
  focusedDate.value = dateISO(initial);
  previousOverflow = document.body.style.overflow;
  document.body.style.overflow = 'hidden';
  open.value = true;
  reposition();
  window.addEventListener('resize', reposition);
  await nextTick();
  reposition();
  await focusDay(focusedDate.value);
}

function cleanup() {
  window.removeEventListener('resize', reposition);
  if (previousOverflow !== undefined) document.body.style.overflow = previousOverflow;
  previousOverflow = undefined;
}

function closePicker() {
  open.value = false;
  cleanup();
  nextTick(() => trigger.value?.focus());
}

function confirm() {
  if (rangeError.value || !draftDays.value) return;
  emit('apply', { from: draftFrom.value, to: draftTo.value });
  closePicker();
}

function choosePreset(preset) {
  draftFrom.value = preset.from;
  draftTo.value = preset.to;
  awaitingEnd.value = false;
  hoverDate.value = '';
  view.value = parseDate(preset.from);
  focusedDate.value = preset.from;
}

function selectDate(iso) {
  if (!awaitingEnd.value) {
    draftFrom.value = iso;
    draftTo.value = '';
    awaitingEnd.value = true;
  } else {
    // Вибір у зворотному порядку також утворює правильний діапазон.
    [draftFrom.value, draftTo.value] = [draftFrom.value, iso].sort();
    awaitingEnd.value = false;
  }
  hoverDate.value = '';
  view.value = parseDate(iso);
  focusDay(iso);
}

function manualDate(field) {
  awaitingEnd.value = false;
  hoverDate.value = '';
  const date = parseDate(field === 'from' ? draftFrom.value : draftTo.value);
  if (date) { view.value = date; focusedDate.value = dateISO(date); }
}

function changeMonth(month) {
  const date = new Date(view.value);
  date.setDate(1);
  date.setMonth(month);
  view.value = date;
  focusedDate.value = dateISO(date);
}

function changeYear(year) {
  const date = new Date(view.value);
  date.setDate(1);
  date.setFullYear(year);
  view.value = date;
  focusedDate.value = dateISO(date);
}

const moveMonth = (offset) => changeMonth(view.value.getMonth() + offset);

function onDayKeydown(event, iso) {
  const date = parseDate(iso);
  const offsets = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
  if (event.key in offsets) date.setDate(date.getDate() + offsets[event.key]);
  else if (event.key === 'Home') date.setDate(date.getDate() - (date.getDay() + 6) % 7);
  else if (event.key === 'End') date.setDate(date.getDate() + 6 - (date.getDay() + 6) % 7);
  else if (event.key === 'PageUp' || event.key === 'PageDown') {
    const day = date.getDate();
    date.setDate(1);
    date.setMonth(date.getMonth() + (event.key === 'PageUp' ? -1 : 1) * (event.shiftKey ? 12 : 1));
    const last = new Date(date);
    last.setMonth(last.getMonth() + 1, 0);
    date.setDate(Math.min(day, last.getDate()));
  } else return;
  event.preventDefault();
  if (date.getFullYear() < 1 || date.getFullYear() > 9999) return;
  view.value = date;
  if (awaitingEnd.value) hoverDate.value = dateISO(date);
  focusDay(dateISO(date));
}

function onDialogKeydown(event) {
  if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closePicker(); }
  if (event.key !== 'Tab') return;
  const focusable = [...panel.value.querySelectorAll('button, input, select')].filter(element => !element.disabled && element.tabIndex >= 0);
  const first = focusable[0];
  const last = focusable.at(-1);
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
}

onBeforeUnmount(cleanup);
</script>

<style scoped>
.period-picker{min-width:0}.period-trigger{display:flex;align-items:center;gap:11px;min-height:54px;max-width:100%;border:1px solid #e2e6f0;border-radius:11px;background:#fff;padding:7px 12px 7px 8px;text-align:left;color:#17213a;transition:border-color .15s,box-shadow .15s}.period-trigger:hover,.period-trigger.is-open{border-color:#a5a1f2;box-shadow:0 0 0 3px #6366f10b}.period-icon{display:grid;place-items:center;flex-shrink:0;width:36px;height:36px;border-radius:9px;background:#f0efff;color:#5b50dc;font-size:1.1rem}.period-value{display:flex;flex-direction:column;gap:3px;min-width:0}.period-value>span{font-size:.65rem;font-weight:550;line-height:1.2;color:#8b93a6}.period-value strong{font-size:.85rem;font-weight:650;line-height:1.3;letter-spacing:-.01em}.period-duration{font-size:.66rem;color:#6b7284;background:#f4f6fa;border-radius:6px;padding:4px 7px;white-space:nowrap;margin-left:8px}.period-chevron{font-size:.68rem;color:#8b93a6;margin-left:auto}.period-trigger.is-open .period-chevron{transform:rotate(180deg)}
.period-backdrop{position:fixed;inset:0;z-index:1080}.period-popover{position:fixed;width:368px;max-width:calc(100vw - 24px);max-height:calc(100dvh - 24px);overflow-y:auto;overscroll-behavior:contain;background:#fff;color:#17213a;border:1px solid #e7e9f1;border-radius:18px;padding:18px;box-shadow:0 16px 60px #16234224,0 4px 16px #1623420c;font-family:inherit}.period-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:15px}.period-heading h2{margin:0;font-size:1rem;font-weight:750;letter-spacing:-.02em}.period-heading p{margin:4px 0 0;font-size:.7rem;color:#9097a7}.calendar-icon-btn{display:grid;place-items:center;flex-shrink:0;width:32px;height:32px;background:#fff;border:1px solid #eceef4;border-radius:8px;color:#687388;font-size:.75rem}.calendar-icon-btn:hover{background:#f5f6fc;color:#4f46e5}.period-presets{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;margin-bottom:16px}.preset-btn{border:1px solid transparent;background:#f6f7fb;color:#697389;border-radius:8px;min-height:34px;padding:6px;font-size:.72rem;font-weight:600}.preset-btn:hover{background:#eeedf9}.preset-btn.active{background:#f0efff;color:#5548db;border-color:#e0dcff}
.period-inputs{display:grid;grid-template-columns:minmax(0,1fr) 16px minmax(0,1fr);align-items:end;gap:8px;margin-bottom:16px}.period-inputs label{min-width:0}.period-inputs label>span{display:block;margin:0 0 6px 2px;font-size:.67rem;color:#8b93a6;font-weight:600}.period-inputs input{width:100%;min-width:0;height:36px;border:1px solid #e3e6ef;border-radius:8px;padding:6px 8px;color:#364157;font:inherit;font-size:.76rem;background:#fff}.period-inputs input:focus{outline:2px solid #d7d2ff;outline-offset:1px;border-color:#a7a0ef}.period-inputs input[aria-invalid="true"]{border-color:#e7a8af}.period-input-arrow{display:grid;place-items:center;height:36px;color:#a3aaba;font-size:.8rem}.calendar-navigation{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px}.calendar-month-selects{display:flex;justify-content:center;gap:5px;min-width:0}.calendar-month-selects select{max-width:140px;background:#fff;border:0;border-radius:5px;color:#26334d;padding:5px 3px;font:inherit;font-size:.8rem;font-weight:650;text-transform:capitalize;cursor:pointer}
.calendar-weekdays,.calendar-week{display:grid;grid-template-columns:repeat(7,minmax(0,1fr))}.calendar-weekdays span{text-align:center;color:#a1a8b7;font-size:.65rem;font-weight:600;padding:7px 0 9px}.calendar-week{margin-bottom:3px}.calendar-cell{height:36px;display:flex;align-items:center;justify-content:center}.calendar-cell.in-range{background:#f0eeff}.calendar-cell.range-start{border-radius:9px 0 0 9px}.calendar-cell.range-end{border-radius:0 9px 9px 0}.calendar-cell.range-start.range-end{border-radius:9px}.calendar-day{position:relative;display:grid;place-items:center;border:0;background:transparent;border-radius:9px;width:36px;height:36px;max-width:100%;padding:0;color:#49566e;font-size:.75rem;font-weight:550}.calendar-day.outside{color:#c2c7d2}.calendar-day:hover{background:#e4e0ff;color:#5042ce}.calendar-day.today:after{content:'';position:absolute;width:3px;height:3px;background:#7769ed;border-radius:50%;bottom:4px}.calendar-day.selected{background:#6254e8;color:#fff;font-weight:700;box-shadow:0 2px 5px #6254e823}.calendar-day.selected:after{background:#fff}.period-hint{font-size:.67rem;line-height:1.4;color:#929bad;min-height:19px;margin:12px 0 14px;text-align:center}.period-hint.invalid{color:#c44e61}.period-footer{display:flex;justify-content:space-between;align-items:center;gap:10px;border-top:1px solid #edf0f6;padding-top:14px}.period-footer>span{display:flex;flex-direction:column;gap:3px}.period-footer strong{font-size:.78rem;font-weight:650;color:#4c5570}.period-footer small{font-size:.58rem;color:#9aa1b1}.period-confirm{display:flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:9px;padding:10px 12px;background:#6254e8;color:#fff;font-size:.74rem;font-weight:650;white-space:nowrap}.period-confirm:hover{background:#5244d4}button:disabled{opacity:.5;cursor:default}button:focus-visible,select:focus-visible{outline:2px solid #8b7eec;outline-offset:2px}
@media(max-width:575.98px){.period-trigger{width:100%;gap:9px}.period-value strong{font-size:.8rem}.period-duration{display:none}.period-backdrop{background:#14213b40}.period-popover{left:12px;right:12px;bottom:12px;top:auto;width:auto;border-radius:20px;padding:18px;box-shadow:0 16px 60px #16234230}.calendar-cell,.calendar-day{height:40px}.calendar-day{width:40px}.period-presets .preset-btn{min-height:38px}.period-confirm{min-height:42px}}
@media(prefers-reduced-motion:reduce){.period-trigger{transition:none}}
</style>
