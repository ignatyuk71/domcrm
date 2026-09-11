<template>
  <header class="topbar sticky-top">
    <div class="toolbar-header px-4 py-3">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        
        <div class="d-none d-md-block"></div>

        <div class="d-flex align-items-center gap-2 w-100 w-md-auto">
          <div class="search-wrapper w-100 w-md-auto">
            <i class="bi bi-search search-icon"></i>
            <input
              :value="search"
              @input="onSearch"
              type="text"
              class="form-control search-input"
              placeholder="Пошук (ПІБ, телефон, ТТН)..."
            />
          </div>

          <a href="/orders/create" class="btn btn-create shadow-sm">
            <i class="bi bi-plus-lg"></i>
            <span class="d-none d-sm-inline ms-1">Створити</span>
          </a>
        </div>
      </div>
    </div>

    <div class="toolbar-filters px-4 pb-3">
      <div class="filters-container">
        
        <div class="status-scroll-area">
          <button
            v-for="opt in mainStatusChips"
            :key="opt.value"
            class="filter-chip"
            :class="{ active: isStatusActive(opt.value) }"
            :style="isStatusActive(opt.value) && opt.color ? {
              backgroundColor: opt.color,
              borderColor: opt.color,
              color: '#fff',
              boxShadow: `0 4px 12px ${opt.color}40`
            } : {}"
            :aria-pressed="isStatusActive(opt.value)"
            :aria-label="opt.label"
            :title="opt.label"
            @click="$emit('toggle-status', opt.value)"
          >
            <i 
              v-if="opt.icon" 
              :class="`bi ${opt.icon}`"
              :style="!isStatusActive(opt.value) && opt.color ? { color: opt.color } : {}"
            ></i>
            <span class="filter-label-full" aria-hidden="true">{{ opt.label }}</span>
            <span class="filter-label-compact" aria-hidden="true">{{ compactLabel(opt.label) }}</span>
          </button>


          <button
            v-if="returnChip"
            type="button"
            class="filter-chip return-filter"
            :class="{ active: isStatusActive(returnChip.value) }"
            :aria-pressed="isStatusActive(returnChip.value)"
            :aria-label="returnChip.label"
            :title="returnChip.label"
            @click="$emit('toggle-status', returnChip.value)"
          >
            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
            <span class="filter-label-full" aria-hidden="true">{{ returnChip.label }}</span>
            <span class="filter-label-compact" aria-hidden="true">{{ compactLabel(returnChip.label) }}</span>
          </button>
        </div>
        <div v-if="reservationChip || holdFilterEnabled" class="special-filter-zone">
          <div v-if="holdFilterEnabled" class="divider-vertical" aria-hidden="true"></div>

          <button
            v-if="reservationChip"
            type="button"
            class="reservation-filter"
            :class="{ 'is-active': isStatusActive(reservationChip.value) }"
            :aria-pressed="isStatusActive(reservationChip.value)"
            title="Бронювання: замовлення, які очікують на товар"
            @click="$emit('toggle-reservation', reservationChip.value)"
          >
            <span class="reservation-icon"><i class="bi bi-calendar2-check" aria-hidden="true"></i></span>
            <span class="reservation-content">
              <span class="reservation-label">{{ reservationChip.label }}</span>
              <span class="reservation-description">Очікують на товар</span>
            </span>
          </button>

          <div v-if="holdFilterEnabled" class="alert-toggle-wrapper" v-click-outside="closeDaysDropdown">
            <button
              class="alert-toggle-btn"
              :class="{ 'is-active': holdFilterActive }"
              @click="$emit('toggle-hold')"
              title="Фільтр замовлень, що довго лежать на пошті"
            >
              <div class="toggle-icon-box">
                <transition name="icon-swap" mode="out-in">
                  <i v-if="holdFilterActive" class="bi bi-fire"></i>
                  <i v-else class="bi bi-hourglass-split"></i>
                </transition>
              </div>

              <div class="toggle-content">
                <span class="toggle-label">Контроль зберігання</span>

                <div
                  class="toggle-sub-interactive"
                  @click.stop="toggleDaysDropdown"
                >
                  <span>Понад {{ holdFilterDays }} дн.</span>
                  <i class="bi bi-caret-down-fill ms-1" :class="{ 'rotate-180': showDaysDropdown }"></i>
                </div>
              </div>

              <div class="toggle-switch-ui"></div>
            </button>

            <transition name="dropdown-fade">
              <div v-if="showDaysDropdown" class="days-dropdown-menu">
                <div class="dropdown-header">Термін зберігання</div>
                <div class="days-grid">
                  <button
                    v-for="day in [3, 4, 5, 6, 7, 10]"
                    :key="day"
                    class="day-option"
                    :class="{ selected: holdFilterDays === day }"
                    @click.stop="selectDay(day)"
                  >
                    {{ day }} дні
                  </button>
                </div>
              </div>
            </transition>
          </div>
        </div>

      </div>
    </div>
  </header>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  search: { type: String, default: '' },
  statusChips: { type: Array, default: () => [] },
  isStatusActive: { type: Function, required: true },
  holdFilterEnabled: { type: Boolean, default: false },
  holdFilterActive: { type: Boolean, default: false },
  holdFilterDays: { type: Number, default: 4 },
});

const emit = defineEmits(['update:search', 'search', 'toggle-status', 'toggle-reservation', 'toggle-hold', 'update:hold-days']);

const reservationChip = computed(() => props.statusChips.find((chip) => chip.code === 'reserved'));
const returnChip = computed(() => props.statusChips.find((chip) => chip.code === 'returned'));
const mainStatusChips = computed(() => props.statusChips.filter((chip) => !['reserved', 'returned'].includes(chip.code)));

const shortLabels = {
  'Підтверджено': 'Підтв.',
  'Упакування': 'Пакув.',
  'Запаковано': 'Запак.',
  'Відправлено': 'Відпр.',
  'У відділенні': 'Відділ.',
  'Завершено': 'Заверш.',
  'Повернення': 'Поверн.',
};

function compactLabel(label) {
  // Зберігаємо лічильник і повну назву в підказці; скорочення потрібні лише для вузького рядка.
  return label.replace(/^(Підтверджено|Упакування|Запаковано|Відправлено|У відділенні|Завершено|Повернення)(?= ·|$)/, (name) => shortLabels[name]);
}

const showDaysDropdown = ref(false);

function onSearch(event) {
  const value = event.target.value;
  emit('update:search', value);
  emit('search', value);
}

function toggleDaysDropdown() {
  showDaysDropdown.value = !showDaysDropdown.value;
}

function closeDaysDropdown() {
  showDaysDropdown.value = false;
}

function selectDay(day) {
  emit('update:hold-days', day);
  showDaysDropdown.value = false;
}

// Директива кліку зовні
const vClickOutside = {
  mounted(el, binding) {
    el.clickOutsideEvent = function(event) {
      if (!(el === event.target || el.contains(event.target))) {
        binding.value(event);
      }
    };
    document.body.addEventListener('click', el.clickOutsideEvent);
  },
  unmounted(el) {
    document.body.removeEventListener('click', el.clickOutsideEvent);
  },
};
</script>

<style scoped>
/* MAIN CONTAINER */
.topbar {
  background: rgba(255, 255, 255, 0.9);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid #e2e8f0;
  z-index: 40;
}

/* SEARCH */
.search-wrapper { position: relative; min-width: 280px; }
.search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.9rem; pointer-events: none; }
.search-input { padding-left: 38px; padding-right: 16px; height: 40px; border-radius: 12px; border: 1px solid #e2e8f0; background: #f8fafc; font-size: 0.9rem; font-weight: 500; transition: all 0.2s ease; }
.search-input:focus { background: #fff; border-color: #6366f1; box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1); color: #1e293b; }

/* CREATE BUTTON */
.btn-create { height: 40px; display: flex; align-items: center; padding: 0 20px; border-radius: 12px; background: linear-gradient(135deg, #6366f1, #4f46e5); border: none; color: #fff; font-weight: 600; font-size: 0.9rem; transition: all 0.2s; white-space: nowrap; }
.btn-create:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35); color: #fff; }

/* --- FILTERS CONTAINER --- */
.filters-container {
  position: relative;
  display: flex;
  flex-wrap: nowrap;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

/* STATUS CHIPS AREA */
.status-scroll-area {
  display: flex;
  align-items: center;
  flex: 1 1 auto;
  min-width: 0;
  flex-wrap: nowrap;
  gap: 4px;
  overflow-x: auto;
  overflow-y: hidden;
  white-space: nowrap;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none; /* прокрутка лишається, смугу не малюємо */
}
.status-scroll-area::-webkit-scrollbar { display: none; }

.filter-chip { height: 30px; padding: 0 8px; border-radius: 7px; border: 1px solid #e2e8f0; background: #fff; color: #64748b; font-size: 0.75rem; font-weight: 500; white-space: nowrap; transition: all 0.2s; display: flex; align-items: center; gap: 4px; cursor: pointer; }
.filter-chip:hover { background: #f8fafc; border-color: #cbd5e1; color: #334155; }
.filter-chip.active { border-color: transparent; transform: translateY(-1px); }
.filter-chip:focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }
.filter-label-compact { display: none; }

/* --- ALERT TOGGLE BUTTON --- */
.status-scroll-area > button { flex-shrink: 0; }
.reservation-filter { display: flex; align-items: center; flex-shrink: 0; gap: 6px; min-height: 42px; padding: 6px; border: 1px solid #ddd6fe; border-radius: 12px; background: #faf8ff; color: #5b21b6; text-align: left; white-space: nowrap; transition: background 0.15s, border-color 0.15s; }
.reservation-icon { display: grid; place-items: center; flex-shrink: 0; width: 24px; height: 28px; border-radius: 7px; background: #ede9fe; color: #7c3aed; font-size: 14px; }
.reservation-content { display: flex; flex-direction: column; gap: 1px; line-height: 1.2; }
.reservation-label { font-size: 11px; font-weight: 700; }
.reservation-description { font-size: 10px; font-weight: 400; color: #7c3aed; }
.reservation-filter:hover { border-color: #a78bfa; background: #f5f3ff; }
.reservation-filter.is-active { background: #7c3aed; border-color: #7c3aed; color: #fff; }
.reservation-filter.is-active .reservation-icon { background: #ffffff26; color: #fff; }
.reservation-filter.is-active .reservation-description { color: #ede9fe; }
.reservation-filter:focus-visible { outline: 2px solid #7c3aed; outline-offset: 3px; }
.return-filter .bi { color: #ef4444; }
.return-filter.active { background: #fef2f2; border-color: #fca5a5; color: #b91c1c; }
.special-filter-zone {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: nowrap;
  flex-shrink: 0;
}

.divider-vertical {
  width: 1px;
  height: 24px;
  background: #e2e8f0;
}

.alert-toggle-wrapper {
  position: relative;
}

.alert-toggle-btn {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 6px;
  border: 1px solid #e2e8f0;
  background: #fff;
  border-radius: 12px;
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  min-width: 0;
  min-height: 42px;
  position: relative;
  overflow: visible;
}

/* Іконка зліва */
.toggle-icon-box {
  width: 24px;
  height: 28px;
  flex-shrink: 0;
  border-radius: 7px;
  background: #f1f5f9;
  color: #64748b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  transition: all 0.3s ease;
}

/* Текст */
.toggle-content {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  text-align: left;
  line-height: 1.2;
  flex: 1;
}

.toggle-label {
  font-size: 11px;
  white-space: nowrap;
  font-weight: 700;
  color: #334155;
}

/* Інтерактивний підзаголовок */
.toggle-sub-interactive {
  font-size: 10px;
  color: #6366f1;
  font-weight: 600;
  display: flex;
  align-items: center;
  padding: 1px 6px;
  margin-left: -6px;
  border-radius: 4px;
  transition: background 0.2s;
  cursor: pointer;
}
.toggle-sub-interactive:hover {
  background: rgba(99, 102, 241, 0.1);
}
.toggle-sub-interactive i {
  font-size: 8px;
  transition: transform 0.2s;
}
.rotate-180 { transform: rotate(180deg); }

/* Слайдер справа */
.toggle-switch-ui {
  width: 28px;
  flex-shrink: 0;
  height: 20px;
  background: #e2e8f0;
  border-radius: 20px;
  position: relative;
  transition: all 0.3s ease;
}

.toggle-switch-ui::after {
  content: '';
  position: absolute;
  top: 2px;
  left: 2px;
  width: 16px;
  height: 16px;
  background: #fff;
  border-radius: 50%;
  box-shadow: 0 1px 3px rgba(0,0,0,0.2);
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* --- ACTIVE STATE (УВІМКНЕНО) --- */
.alert-toggle-btn:hover {
  border-color: #cbd5e1;
  box-shadow: 0 2px 8px rgba(0,0,0,0.03);
}

.alert-toggle-btn.is-active {
  background: #fff7ed;
  border-color: #fdba74;
}

.alert-toggle-btn.is-active .toggle-icon-box {
  background: #ffedd5;
  color: #ea580c;
}

.alert-toggle-btn.is-active .toggle-label {
  color: #9a3412;
}

.alert-toggle-btn.is-active .toggle-sub-interactive {
  color: #c2410c;
}
.alert-toggle-btn.is-active .toggle-sub-interactive:hover {
  background: rgba(234, 88, 12, 0.1);
}

.alert-toggle-btn.is-active .toggle-switch-ui {
  background: #f97316;
}

.alert-toggle-btn.is-active .toggle-switch-ui::after {
  left: 10px;
}

/* --- DAYS DROPDOWN MENU --- */
.days-dropdown-menu {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  width: 240px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  box-shadow: 0 10px 30px -5px rgba(0,0,0,0.15);
  padding: 12px;
  z-index: 1050;
  transform-origin: top right;
}

.dropdown-header {
  font-size: 11px;
  text-transform: uppercase;
  color: #94a3b8;
  font-weight: 700;
  margin-bottom: 8px;
  padding-left: 4px;
}

.days-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
}

.day-option {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 8px 4px;
  font-size: 13px;
  font-weight: 600;
  color: #475569;
  cursor: pointer;
  transition: all 0.2s;
}

.day-option:hover {
  background: #fff;
  border-color: #cbd5e1;
  box-shadow: 0 2px 4px rgba(0,0,0,0.05);
  color: #3b82f6;
}

.day-option.selected {
  background: #ea580c;
  border-color: #ea580c;
  color: white;
  box-shadow: 0 4px 8px rgba(234, 88, 12, 0.3);
}

/* Animations */
.dropdown-fade-enter-active,
.dropdown-fade-leave-active {
  transition: all 0.2s ease;
}
.dropdown-fade-enter-from,
.dropdown-fade-leave-to {
  opacity: 0;
  transform: translateY(-10px) scale(0.95);
}

.icon-swap-enter-active,
.icon-swap-leave-active {
  transition: all 0.2s;
}
.icon-swap-enter-from,
.icon-swap-leave-to {
  opacity: 0;
  transform: scale(0.5);
}

/* MOBILE */
@media (max-width: 1400px) {
  .filters-container { gap: 8px; }
  .filter-chip { font-size: 11px; padding-inline: 5px; }
  .filter-label-full { display: none; }
  .filter-label-compact { display: inline; }
  .status-scroll-area { gap: 3px; }
}
@media (max-width: 768px) {
  .search-wrapper { min-width: 0; }
  .filters-container { gap: 8px; }
  .reservation-filter { gap: 5px; padding: 4px; }
  .reservation-icon { width: 24px; height: 28px; font-size: 14px; }
  .reservation-label { font-size: 10px; }
  .reservation-description { font-size: 9px; }
  .alert-toggle-btn { min-width: 0; gap: 4px; padding: 6px; }
  .toggle-label { font-size: 9px; }
  .toggle-switch-ui { width: 28px; flex-shrink: 0; }
  .alert-toggle-btn.is-active .toggle-switch-ui::after { left: 10px; }
  .toggle-icon-box { display: none; }
  .divider-vertical { display: none; }
  
  .days-dropdown-menu {
    position: fixed;
    top: auto;
    bottom: 0;
    left: 0;
    right: 0;
    width: 100%;
    border-radius: 20px 20px 0 0;
    box-shadow: 0 -10px 40px rgba(0,0,0,0.2);
    padding: 20px;
    animation: slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  }
  
  @keyframes slideUp {
    from { transform: translateY(100%); }
    to { transform: translateY(0); }
  }
}
</style>
