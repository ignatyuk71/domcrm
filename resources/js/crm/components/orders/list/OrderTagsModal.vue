<template>
  <div v-if="open">
    <div class="modal-backdrop fade show bg-dark bg-opacity-25"></div>
    
    <div class="modal fade show d-block" tabindex="-1" @click.self="$emit('close')">
      <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-xl rounded-4 overflow-hidden">
          
          <div class="modal-header border-bottom-0 pb-0 px-4 pt-4">
            <h5 class="modal-title fw-bold text-dark fs-5">Обрати теги</h5>
            <button type="button" class="btn-close shadow-none" @click="$emit('close')"></button>
          </div>

          <div class="modal-body px-4 py-3">
            <div v-if="loading" class="text-center py-5 text-muted">
              <div class="spinner-border text-primary mb-3" role="status"></div>
              <div class="small fw-medium">Завантаження...</div>
            </div>

            <div v-else class="d-flex flex-column gap-2 tag-list">
              <div
                v-for="tag in availableTags"
                :key="tag.id"
                class="tag-option"
                :class="{ 'selected': isSelected(tag.id) }"
                @click="toggleTag(tag.id)"
              >
                <div 
                  class="tag-chip"
                  :class="'tag-' + (tag.color || 'gray')"
                  :style="tag.color && tag.color.startsWith('#') ? { backgroundColor: tag.color + '15', color: tag.color } : {}"
                >
                  <i v-if="tag.icon" :class="'bi ' + tag.icon"></i>
                  <span>{{ tag.name }}</span>
                </div>

                <div class="check-icon">
                  <i class="bi bi-check-circle-fill text-primary"></i>
                </div>
              </div>

              <div v-if="!availableTags.length" class="text-center text-muted py-4 small">
                Теги відсутні
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
                :disabled="loading"
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
const props = defineProps({
  open: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  availableTags: { type: Array, default: () => [] },
  modelValue: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue', 'close', 'save']);

// Перевірка, чи вибраний тег
const isSelected = (id) => {
  return props.modelValue.includes(id);
};

// Логіка перемикання (додати/видалити зі списку)
const toggleTag = (id) => {
  const newSelection = [...props.modelValue];
  const index = newSelection.indexOf(id);
  
  if (index === -1) {
    newSelection.push(id); // Додати
  } else {
    newSelection.splice(index, 1); // Видалити
  }
  
  emit('update:modelValue', newSelection);
};
</script>

<style scoped>
/* Backdrop Blur */
.modal-backdrop {
  backdrop-filter: blur(4px);
}

/* Scrollable List */
.tag-list {
  max-height: 400px;
  overflow-y: auto;
  padding-right: 4px;
}
.tag-list::-webkit-scrollbar { width: 4px; }
.tag-list::-webkit-scrollbar-track { background: #f1f5f9; }
.tag-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

/* Tag Option Card (Точно як у StatusModal) */
.tag-option {
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
  user-select: none;
}

.tag-option:hover {
  background: #f8fafc;
  border-color: #cbd5e1;
}

/* Selected State */
.tag-option.selected {
  border-color: #6366f1; /* Primary color */
  background: #eff6ff;
  box-shadow: 0 0 0 1px #6366f1;
}

/* Tag Chip Design */
.tag-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px;
  border-radius: 8px;
  font-size: 0.85rem;
  font-weight: 600;
  background: #f1f5f9;
  color: #64748b;
  line-height: 1;
}

/* Fallback colors for classes */
.tag-red { background: #fee2e2; color: #b91c1c; }
.tag-blue { background: #dbeafe; color: #1d4ed8; }
.tag-green { background: #dcfce7; color: #15803d; }
.tag-amber { background: #fef3c7; color: #b45309; }
.tag-gray { background: #f3f4f6; color: #4b5563; }

/* Check Icon Animation */
.check-icon {
  opacity: 0;
  transform: scale(0.8);
  transition: all 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
  font-size: 1.1rem;
}

.tag-option.selected .check-icon {
  opacity: 1;
  transform: scale(1);
}
</style>