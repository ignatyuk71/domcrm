<template>
  <div v-if="open">
    <div class="modal-backdrop fade show bg-dark bg-opacity-25"></div>
    
    <div class="modal fade show d-block" tabindex="-1" @click.self="$emit('close')">
      <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-xl rounded-4 overflow-hidden">
          
          <div class="modal-header border-bottom-0 pb-0 px-4 pt-4">
            <h5 class="modal-title fw-bold text-dark fs-5">Змінити статус</h5>
            <button type="button" class="btn-close shadow-none" @click="$emit('close')"></button>
          </div>

          <div class="modal-body px-4 py-3">
            <div v-if="loading" class="text-center py-5 text-muted">
              <div class="spinner-border text-primary mb-3" role="status"></div>
              <div class="small fw-medium">Завантаження...</div>
            </div>

            <div v-else class="d-flex flex-column gap-2 status-list">
              <div
                v-for="st in statuses"
                :key="st.id"
                class="status-option"
                :class="{ 'selected': selected === st.id }"
                @click="selected = st.id"
              >
                <div 
                  class="status-chip"
                  :style="st.color ? { 
                    backgroundColor: st.color + '15', 
                    color: st.color 
                  } : { 
                    backgroundColor: '#f1f5f9', 
                    color: '#64748b' 
                  }"
                >
                  <i v-if="st.icon" :class="['bi', st.icon]"></i>
                  <span>{{ st.name }}</span>
                </div>

                <div class="check-icon">
                  <i class="bi bi-check-circle-fill text-primary"></i>
                </div>
              </div>

              <div v-if="!statuses.length" class="text-center text-muted py-4 small">
                Статуси не знайдено
              </div>
            </div>
          </div>

          <div class="modal-footer border-top-0 px-4 pb-4 pt-0">
            <div class="d-grid gap-2 w-100 d-flex">
              <button class="btn btn-light border w-50 fw-medium rounded-3" type="button" @click="$emit('close')">
                Скасувати
              </button>
              <button 
                class="btn btn-primary w-50 fw-bold rounded-3 shadow-sm" 
                type="button" 
                @click="$emit('save')" 
                :disabled="loading || !selected"
              >
                Зберегти
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  statuses: { type: Array, default: () => [] },
  modelValue: { type: [Number, String, null], default: null },
});

const emit = defineEmits(['update:modelValue', 'close', 'save']);

const selected = computed({
  get: () => props.modelValue,
  set: (val) => emit('update:modelValue', val),
});
</script>

<style scoped>
/* Backdrop Blur */
.modal-backdrop {
  backdrop-filter: blur(4px);
}

/* Status Option Card */
.status-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  cursor: pointer;
  background: #fff;
  transition: all 0.2s ease;
  position: relative;
}

.status-option:hover {
  background: #f8fafc;
  border-color: #cbd5e1;
}

/* Selected State */
.status-option.selected {
  border-color: #6366f1; /* Primary color */
  background: #eff6ff;
  box-shadow: 0 0 0 1px #6366f1; /* Double border effect */
}

/* Status Chip Design */
.status-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px;
  border-radius: 8px;
  font-size: 0.85rem;
  font-weight: 600;
  line-height: 1;
}

/* Check Icon Animation */
.check-icon {
  opacity: 0;
  transform: scale(0.8);
  transition: all 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
  font-size: 1.1rem;
}

.status-option.selected .check-icon {
  opacity: 1;
  transform: scale(1);
}

/* Scrollable area if many statuses */
.status-list {
  max-height: 400px;
  overflow-y: auto;
  padding-right: 4px; /* Space for scrollbar */
}

/* Custom Scrollbar */
.status-list::-webkit-scrollbar {
  width: 4px;
}
.status-list::-webkit-scrollbar-track {
  background: #f1f5f9;
}
.status-list::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 4px;
}
</style>