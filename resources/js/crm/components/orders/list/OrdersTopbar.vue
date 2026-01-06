<template>
  <header class="topbar sticky-top">
    <div class="toolbar-header px-4 py-3">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        
       

        <div class="d-flex align-items-center gap-2 w-100 w-md-auto">
          <div class="search-wrapper w-100 w-md-auto">
            <i class="bi bi-search search-icon"></i>
            <input
              :value="search"
              @input="onSearch"
              type="text"
              class="form-control search-input"
              placeholder="Пошук клієнта, телефону..."
            />
          </div>

          <a href="/orders/create" class="btn btn-create shadow-sm">
            <i class="bi bi-plus-lg"></i>
            <span class="d-none d-sm-inline ms-1">Створити</span>
          </a>
        </div>
      </div>
    </div>

    <div class="toolbar-filters px-4 pb-0">
      <div class="d-flex gap-2 overflow-auto no-scrollbar pb-3">
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
    </div>
  </header>
</template>

<script setup>
const props = defineProps({
  search: { type: String, default: '' },
  statusChips: { type: Array, default: () => [] },
  isStatusActive: { type: Function, required: true },
});

const emit = defineEmits(['update:search', 'search', 'toggle-status']);

function onSearch(event) {
  const value = event.target.value;
  emit('update:search', value);
  emit('search', value);
}
</script>

<style scoped>
/* --- Main Container --- */
.topbar {
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(16px);
  border-bottom: 1px solid rgba(0, 0, 0, 0.06);
  z-index: 50;
}

/* --- Search Input --- */
.search-wrapper {
  position: relative;
  min-width: 280px;
}
.search-icon {
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  color: #94a3b8;
  font-size: 0.9rem;
  pointer-events: none;
}
.search-input {
  padding-left: 38px;
  padding-right: 16px;
  height: 40px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  font-size: 0.9rem;
  font-weight: 500;
  transition: all 0.2s ease;
}
.search-input:focus {
  background: #fff;
  border-color: #6366f1;
  box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
  color: #1e293b;
}

/* --- Create Button --- */
.btn-create {
  height: 40px;
  display: flex;
  align-items: center;
  padding: 0 20px;
  border-radius: 10px;
  background: linear-gradient(135deg, #6366f1, #4f46e5);
  border: none;
  color: #fff;
  font-weight: 600;
  font-size: 0.9rem;
  transition: all 0.2s;
  white-space: nowrap;
}
.btn-create:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35);
  color: #fff;
}

/* --- Filter Chips --- */
.filter-chip {
  height: 34px;
  padding: 0 14px;
  border-radius: 20px; /* Fully rounded */
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #64748b;
  font-size: 0.85rem;
  font-weight: 600;
  white-space: nowrap;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
}

.filter-chip:hover {
  background: #f8fafc;
  border-color: #cbd5e1;
  color: #334155;
}

/* Active State Styles */
.filter-chip.active {
  /* Colors handled by inline style in template for dynamic coloring */
  border-color: transparent;
  transform: translateY(-1px);
}

.filter-chip i {
  font-size: 0.9rem;
}

/* --- Utilities --- */
.no-scrollbar::-webkit-scrollbar {
  display: none;
}
.no-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>