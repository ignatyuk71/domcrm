<template>
  <header class="topbar sticky-top">
    <!-- ВЕРХНІЙ РЯДОК: ПОШУК ТА СТВОРЕННЯ -->
    <div class="toolbar-header px-4 py-3">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        
        <!-- ЛІВА ЧАСТИНА -->
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

          <div class="per-page-select">
            <span class="per-page-label d-none d-lg-inline">На сторінці</span>
            <select class="form-select per-page-input" :value="perPage" @change="onPerPageChange">
              <option v-for="opt in perPageOptions" :key="opt" :value="opt">{{ opt }}</option>
            </select>
          </div>

          <a href="/orders/create" class="btn btn-create shadow-sm">
            <i class="bi bi-plus-lg"></i>
            <span class="d-none d-sm-inline ms-1">Створити</span>
          </a>
        </div>
      </div>
    </div>

    <!-- НИЖНІЙ РЯДОК: ФІЛЬТРИ -->
    <div class="toolbar-filters px-4 pb-3">
      <!-- ВИПРАВЛЕНО: Прибрано overflow-auto з батька -->
      <div class="filters-container d-flex align-items-center justify-content-between gap-3">
        
        <!-- СТАТУСИ (ЛІВА ЧАСТИНА - скролиться окремо) -->
        <div class="d-flex gap-2 overflow-auto no-scrollbar status-scroll-area">
          <button
            v-for="opt in statusChips"
            :key="opt.value"
            class="filter-chip"
            :class="{ active: isStatusActive(opt.value) }"
            :style="isStatusActive(opt.value) && opt.color ? {
              backgroundColor: opt.color,
              borderColor: opt.color,
              color: '#fff',
              boxShadow: `0 4px 12px ${opt.color}40`
            } : {}"
            @click="$emit('toggle-status', opt.value)"
          >
            <i 
              v-if="opt.icon" 
              :class="`bi ${opt.icon}`"
              :style="!isStatusActive(opt.value) && opt.color ? { color: opt.color } : {}"
            ></i>
            <span>{{ opt.label }}</span>
          </button>
        </div>

        <!-- СПЕЦІАЛЬНИЙ ФІЛЬТР "КОНТРОЛЬ ЗБЕРІГАННЯ" (ПРАВА ЧАСТИНА) -->
        <div v-if="holdFilterEnabled" class="special-filter-zone">
          <div class="divider-vertical"></div>
          
          <div class="alert-toggle-wrapper" v-click-outside="closeDaysDropdown">
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
                
                <!-- Випадаючий список днів -->
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

            <!-- ВИПАДАЮЧИЙ СПИСОК ДНІВ (Збільшений z-index) -->
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
import { ref } from 'vue';

const props = defineProps({
  search: { type: String, default: '' },
  statusChips: { type: Array, default: () => [] },
  isStatusActive: { type: Function, required: true },
  holdFilterEnabled: { type: Boolean, default: false },
  holdFilterActive: { type: Boolean, default: false },
  holdFilterDays: { type: Number, default: 4 },
  perPage: { type: Number, default: 30 },
  perPageOptions: { type: Array, default: () => [15, 30, 60] },
});

const emit = defineEmits(['update:search', 'search', 'toggle-status', 'toggle-hold', 'update:hold-days', 'update:per-page']);

const showDaysDropdown = ref(false);

function onSearch(event) {
  const value = event.target.value;
  emit('update:search', value);
  emit('search', value);
}

function onPerPageChange(event) {
  const value = Number(event.target.value) || 30;
  emit('update:per-page', value);
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

/* PER PAGE */
.per-page-select { display: flex; align-items: center; gap: 8px; }
.per-page-label { font-size: 0.8rem; font-weight: 600; color: #64748b; }
.per-page-input { height: 40px; border-radius: 12px; border: 1px solid #e2e8f0; background: #f8fafc; font-size: 0.85rem; font-weight: 600; padding: 0 10px; width: 90px; }
.per-page-input:focus { background: #fff; border-color: #6366f1; box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1); color: #1e293b; }

/* CREATE BUTTON */
.btn-create { height: 40px; display: flex; align-items: center; padding: 0 20px; border-radius: 12px; background: linear-gradient(135deg, #6366f1, #4f46e5); border: none; color: #fff; font-weight: 600; font-size: 0.9rem; transition: all 0.2s; white-space: nowrap; }
.btn-create:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35); color: #fff; }

/* --- FILTERS CONTAINER (FIXED OVERFLOW) --- */
.filters-container {
  position: relative;
}

/* STATUS CHIPS SCROLL AREA */
.status-scroll-area {
  flex: 1; /* Займає вільне місце */
  min-width: 0; /* Дозволяє стискатися */
  padding-bottom: 4px; /* Місце для тіні при ховері */
}

.filter-chip { height: 36px; padding: 0 5px; border-radius: 7px; border: 1px solid #e2e8f0; background: #fff; color: #64748b; font-size: 0.85rem; font-weight: 500; white-space: nowrap; transition: all 0.2s; display: flex; align-items: center; gap: 8px; cursor: pointer; flex-shrink: 0; }
.filter-chip:hover { background: #f8fafc; border-color: #cbd5e1; color: #334155; }
.filter-chip.active { border-color: transparent; transform: translateY(-1px); }

/* --- ALERT TOGGLE BUTTON --- */
.special-filter-zone {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-shrink: 0; /* Не стискається */
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
  gap: 10px;
  padding: 6px 8px 6px 8px;
  border: 1px solid #e2e8f0;
  background: #fff;
  border-radius: 12px;
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  min-width: 210px;
  position: relative;
  overflow: visible; /* Дозволяємо випадання */
}

/* Іконка зліва */
.toggle-icon-box {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: #f1f5f9;
  color: #64748b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
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
  font-size: 12px;
  font-weight: 700;
  color: #334155;
}

/* Інтерактивний підзаголовок */
.toggle-sub-interactive {
  font-size: 11px;
  color: #6366f1;
  font-weight: 600;
  display: flex;
  align-items: center;
  padding: 1px 6px;
  margin-left: -6px;
  border-radius: 4px;
  transition: background 0.2s;
  cursor: pointer; /* Явно вказуємо курсор */
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
  width: 36px;
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
  background: #fff7ed; /* Світло-помаранчевий фон */
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
  background: #f97316; /* Помаранчевий */
}

.alert-toggle-btn.is-active .toggle-switch-ui::after {
  left: 18px; /* Зсув кружечка вправо */
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
  box-shadow: 0 10px 30px -5px rgba(0,0,0,0.15); /* Більша тінь */
  padding: 12px;
  z-index: 1050; /* Високий z-index */
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

.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

/* MOBILE */
@media (max-width: 768px) {
  .special-filter-zone {
    margin-left: 0;
    border-left: 1px solid #e2e8f0;
    padding-left: 12px;
  }
  .divider-vertical { display: none; }
  
  /* Адаптація дропдауна для мобільного */
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
