<template>
  <header class="topbar border-bottom bg-white sticky-top">
    <div class="d-flex align-items-center justify-content-between px-4 py-2">
      <div class="d-flex align-items-center gap-3">
        <h1 class="h5 fw-bold m-0">Замовлення</h1>

        <div class="input-group input-group-sm rounded-pill border overflow-hidden bg-light" style="width: 250px;">
          <span class="input-group-text bg-transparent border-0 text-muted ps-3">
            <i class="bi bi-search"></i>
          </span>
          <input
            :value="search"
            @input="onSearch"
            type="text"
            class="form-control border-0 shadow-none ps-1 bg-transparent"
            placeholder="Пошук..."
          />
        </div>
      </div>

      <a href="/orders/create" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold shadow-sm">
        <i class="bi bi-plus-lg me-1"></i> Створити
      </a>
    </div>

    <div class="px-4 pb-2 d-flex gap-2 overflow-auto no-scrollbar border-top pt-2">
      <button
        v-for="opt in statusChips"
        :key="opt.value"
        class="status-chip"
        :class="{ active: isStatusActive(opt.value) }"
        :style="isStatusActive(opt.value) && opt.color ? {
          backgroundColor: opt.color,
          borderColor: opt.color,
          color: '#111827'
        } : {}"
        @click="$emit('toggle-status', opt.value)"
      >
        <i v-if="opt.icon" :class="`bi ${opt.icon}`"></i>
        <span>{{ opt.label }}</span>
      </button>
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
.topbar {
  position: sticky;
  top: 0;
  z-index: 50;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.92), rgba(255, 255, 255, 0.86));
  backdrop-filter: blur(10px);
  border-bottom: 1px solid rgba(17, 24, 39, 0.07);
}

.status-chip {
  background: #f8fafc;
  border: 1px solid #5481bb;
  padding: 4px 10px;
  border-radius: 6px;
  font-size: 0.9rem;
  font-weight: 500;
  color: #64748b;
  white-space: nowrap;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  gap: 5px;
}

.status-chip:hover {
  background: #f1f5f9;
}

.status-chip.active {
  background: #0f172a;
  color: #fff;
  border-color: #0f172a;
}

.no-scrollbar::-webkit-scrollbar {
  display: none;
}
.no-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>
