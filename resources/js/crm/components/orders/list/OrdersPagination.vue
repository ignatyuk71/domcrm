<template>
  <div class="pagination-wrapper">
    <div class="pagination-info">
      <div class="text-muted small fw-medium">
        Показано від <span class="fw-bold text-dark">{{ meta.from || 0 }}</span> 
        до <span class="fw-bold text-dark">{{ meta.to || 0 }}</span> 
        з <span class="fw-bold text-dark">{{ meta.total || 0 }}</span> записів
      </div>
      <div class="per-page-select">
        <span class="per-page-label d-none d-lg-inline">На сторінці</span>
        <select class="form-select per-page-input" :value="perPage" @change="onPerPageChange">
          <option v-for="opt in perPageOptions" :key="opt" :value="opt">{{ opt }}</option>
        </select>
      </div>
    </div>

    <nav v-if="showNav" class="pagination-nav">
      <ul class="pagination mb-0 gap-1">
        
        <li class="page-item" :class="{ disabled: meta.current_page === 1 }">
          <button 
            class="page-btn" 
            @click="$emit('change-page', meta.current_page - 1)"
            :disabled="meta.current_page === 1"
          >
            <i class="bi bi-chevron-left"></i>
          </button>
        </li>

        <li
          v-for="(page, idx) in pages"
          :key="`${page}-${idx}`"
          class="page-item"
          :class="{ active: page === meta.current_page, disabled: page === '...' }"
        >
          <button
            v-if="page !== '...'"
            class="page-btn"
            :class="{ 'is-active': page === meta.current_page }"
            @click="changePage(page)"
          >
            {{ page }}
          </button>
          <span v-else class="page-btn page-ellipsis">…</span>
        </li>

        <li class="page-item" :class="{ disabled: meta.current_page === meta.last_page }">
          <button 
            class="page-btn" 
            @click="$emit('change-page', meta.current_page + 1)"
            :disabled="meta.current_page === meta.last_page"
          >
            <i class="bi bi-chevron-right"></i>
          </button>
        </li>

      </ul>
    </nav>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  meta: {
    type: Object,
    required: true,
  },
  perPage: {
    type: Number,
    default: 30,
  },
  perPageOptions: {
    type: Array,
    default: () => [15, 30, 60],
  },
});

const emit = defineEmits(['change-page', 'update:per-page']);

const pages = computed(() => {
  const current = Number(props.meta.current_page || 1);
  const last = Number(props.meta.last_page || 1);
  if (last <= 1) return [1];
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);

  const result = [1];
  const start = Math.max(2, current - 1);
  const end = Math.min(last - 1, current + 1);

  if (start > 2) result.push('...');
  for (let page = start; page <= end; page += 1) {
    result.push(page);
  }
  if (end < last - 1) result.push('...');
  result.push(last);

  return result;
});

const showNav = computed(() => Number(props.meta.last_page || 1) > 1);

function changePage(page) {
  if (page === props.meta.current_page) return;
  emit('change-page', page);
}

function onPerPageChange(event) {
  const value = Number(event.target.value) || 30;
  emit('update:per-page', value);
}
</script>

<style scoped>
.pagination-wrapper {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 24px;
  background: #fff;
  border-top: 1px solid #e2e8f0;
  border-bottom-left-radius: 16px;
  border-bottom-right-radius: 16px;
  gap: 16px;
}

.pagination-info {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
}

.pagination-nav {
  flex-shrink: 0;
}

.per-page-select { display: flex; align-items: center; gap: 8px; }
.per-page-label { font-size: 0.8rem; font-weight: 600; color: #64748b; }
.per-page-input { height: 36px; border-radius: 10px; border: 1px solid #e2e8f0; background: #f8fafc; font-size: 0.8rem; font-weight: 600; padding: 0 8px; width: 86px; }
.per-page-input:focus { background: #fff; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12); color: #1e293b; }

.page-btn {
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #64748b;
  font-size: 0.85rem;
  transition: all 0.2s;
}

.page-btn:hover:not(:disabled) {
  background: #f1f5f9;
  color: #334155;
  border-color: #cbd5e1;
}

.page-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  background: #f8fafc;
}

.page-btn.is-active {
  background: #eff6ff;
  color: #3b82f6;
  border-color: #3b82f6;
  font-weight: 600;
  pointer-events: none;
}

.page-ellipsis {
  border-color: transparent;
  background: transparent;
  color: #94a3b8;
  cursor: default;
}

/* На мобільному ставимо стовпчиком */
@media (max-width: 576px) {
  .pagination-wrapper {
    flex-direction: column;
    gap: 12px;
  }
}
</style>
