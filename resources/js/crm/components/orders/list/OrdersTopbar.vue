<template>
  <header class="topbar sticky-top">
    <!-- ВЕРХНІЙ РЯДОК: ПОШУК ТА СТВОРЕННЯ -->
    <div class="toolbar-header px-4 py-3">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        
        <!-- ЛІВА ЧАСТИНА (Можна додати заголовок або хлібні крихти, якщо треба) -->
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

    <!-- НИЖНІЙ РЯДОК: ФІЛЬТРИ -->
    <div class="toolbar-filters px-4 pb-3">
      <div class="d-flex align-items-center justify-content-between gap-3 overflow-auto no-scrollbar">
        
        <!-- СТАТУСИ (ЛІВА ЧАСТИНА) -->
        <div class="d-flex gap-2">
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

        <!-- СПЕЦІАЛЬНИЙ ФІЛЬТР "НЕЗАБОРИ" (ПРАВА ЧАСТИНА) -->
        <div v-if="holdFilterEnabled" class="special-filter-zone">
          <div class="divider-vertical"></div>
          
          <button 
            class="alert-toggle-btn"
            :class="{ 'is-active': holdFilterActive }"
            @click="$emit('toggle-hold')"
            title="Показати замовлення, що довго лежать на пошті"
          >
            <div class="toggle-icon-box">
              <transition name="icon-swap" mode="out-in">
                <i v-if="holdFilterActive" class="bi bi-fire"></i>
                <i v-else class="bi bi-hourglass-split"></i>
              </transition>
            </div>
            <div class="toggle-content">
              <span class="toggle-label">Контроль незаборів</span>
              <span class="toggle-sub">Лежать > {{ holdFilterDays }} дн.</span>
            </div>
            <div class="toggle-switch-ui"></div>
          </button>
        </div>

      </div>
    </div>
  </header>
</template>

<script setup>
const props = defineProps({
  search: { type: String, default: '' },
  statusChips: { type: Array, default: () => [] },
  isStatusActive: { type: Function, required: true },
  holdFilterEnabled: { type: Boolean, default: false },
  holdFilterActive: { type: Boolean, default: false },
  holdFilterDays: { type: Number, default: 4 },
});

const emit = defineEmits(['update:search', 'search', 'toggle-status', 'toggle-hold']);

function onSearch(event) {
  const value = event.target.value;
  emit('update:search', value);
  emit('search', value);
}
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

/* STATUS CHIPS */
.filter-chip { height: 36px; padding: 0 16px; border-radius: 10px; border: 1px solid #e2e8f0; background: #fff; color: #64748b; font-size: 0.85rem; font-weight: 600; white-space: nowrap; transition: all 0.2s; display: flex; align-items: center; gap: 8px; cursor: pointer; }
.filter-chip:hover { background: #f8fafc; border-color: #cbd5e1; color: #334155; }
.filter-chip.active { border-color: transparent; transform: translateY(-1px); }

/* --- ALERT TOGGLE BUTTON (НОВИЙ ДИЗАЙН) --- */
.special-filter-zone {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-left: auto; /* Притискає вправо */
}

.divider-vertical {
  width: 1px;
  height: 24px;
  background: #e2e8f0;
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
  min-width: 200px;
  position: relative;
  overflow: hidden;
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
  line-height: 1.1;
  flex: 1;
}

.toggle-label {
  font-size: 12px;
  font-weight: 700;
  color: #334155;
}

.toggle-sub {
  font-size: 10px;
  color: #94a3b8;
  font-weight: 600;
}

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

.alert-toggle-btn.is-active .toggle-sub {
  color: #c2410c;
}

.alert-toggle-btn.is-active .toggle-switch-ui {
  background: #f97316; /* Помаранчевий */
}

.alert-toggle-btn.is-active .toggle-switch-ui::after {
  left: 18px; /* Зсув кружечка вправо */
}

/* Анімація іконки */
.icon-swap-enter-active,
.icon-swap-leave-active {
  transition: all 0.2s;
}
.icon-swap-enter-from,
.icon-swap-leave-to {
  opacity: 0;
  transform: scale(0.5);
}

/* --- SCROLLBAR --- */
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

/* MOBILE */
@media (max-width: 768px) {
  .special-filter-zone {
    margin-left: 0; /* На мобільному не притискаємо вправо */
    border-left: 1px solid #e2e8f0;
    padding-left: 12px;
  }
  .divider-vertical { display: none; }
}
</style>