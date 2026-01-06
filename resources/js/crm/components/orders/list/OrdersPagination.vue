<template>
  <div class="pagination-wrapper">
    
    <div class="text-muted small fw-medium">
      Показано від <span class="fw-bold text-dark">{{ meta.from || 0 }}</span> 
      до <span class="fw-bold text-dark">{{ meta.to || 0 }}</span> 
      з <span class="fw-bold text-dark">{{ meta.total || 0 }}</span> записів
    </div>

    <nav>
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

        <li class="page-item active">
          <span class="page-btn active-page">
            {{ meta.current_page }}
          </span>
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
defineProps({
  meta: {
    type: Object,
    required: true,
  },
});

defineEmits(['change-page']);
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
}

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

.active-page {
  background: #eff6ff;
  color: #3b82f6;
  border-color: #3b82f6;
  font-weight: 600;
  pointer-events: none;
}

/* На мобільному ставимо стовпчиком */
@media (max-width: 576px) {
  .pagination-wrapper {
    flex-direction: column;
    gap: 12px;
  }
}
</style>