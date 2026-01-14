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
  
  <template>
    <div v-if="open">
      <!-- Фон з розмиттям -->
      <div class="modal-backdrop fade show"></div>
      
      <!-- Модальне вікно -->
      <div class="modal fade show d-block" tabindex="-1" @click.self="$emit('close')">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-sm">
          <div class="modal-content clean-modal">
            
            <!-- Header -->
            <div class="modal-header border-0 pb-0">
              <div class="d-flex flex-column">
                <h5 class="modal-title fw-bold text-dark">Теги замовлення</h5>
                <span class="text-muted small">Виберіть мітки зі списку</span>
              </div>
              <button type="button" class="btn-close-custom" @click="$emit('close')">
                <i class="bi bi-x-lg"></i>
              </button>
            </div>
  
            <!-- Body (Список тегів) -->
            <div class="modal-body py-4">
              <div v-if="loading" class="d-flex flex-column align-items-center justify-content-center py-4 text-muted">
                <div class="spinner-border text-primary mb-2" role="status"></div>
                <div class="small fw-medium">Завантаження...</div>
              </div>
  
              <div v-else class="d-flex flex-column gap-2">
                <div
                  v-for="tag in availableTags"
                  :key="tag.id"
                  class="tag-option"
                  :class="{ 'is-selected': isSelected(tag.id) }"
                  @click="toggleTag(tag.id)"
                >
                  <!-- Візуалізація тегу -->
                  <div 
                    class="tag-pill"
                    :class="'tag-' + (tag.color || 'gray')"
                    :style="tag.color && tag.color.startsWith('#') ? { backgroundColor: tag.color + '15', color: tag.color } : {}"
                  >
                    <i v-if="tag.icon" :class="'bi ' + tag.icon"></i>
                    <span>{{ tag.name }}</span>
                  </div>
  
                  <!-- Чекбокс -->
                  <div class="checkbox-indicator">
                    <i class="bi bi-check-lg"></i>
                  </div>
                </div>
  
                <!-- Якщо немає тегів -->
                <div v-if="!availableTags.length" class="empty-state">
                  <i class="bi bi-tags text-muted mb-2"></i>
                  <span>Список тегів порожній</span>
                </div>
              </div>
            </div>
  
            <!-- Footer -->
            <div class="modal-footer border-0 pt-0 pb-4 px-4">
              <div class="d-flex gap-2 w-100">
                <button class="btn-cancel w-50" type="button" @click="$emit('close')">
                  Скасувати
                </button>
                <button 
                  class="btn-save w-50" 
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
  
  <style scoped>
  /* Backdrop Blur */
  .modal-backdrop {
    background-color: rgba(15, 23, 42, 0.4);
    backdrop-filter: blur(4px);
  }
  
  /* Modal Styling */
  .clean-modal {
    border: none;
    border-radius: 16px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    overflow: hidden;
    background: #fff;
  }
  
  /* Close Button */
  .btn-close-custom {
    background: transparent;
    border: none;
    color: #94a3b8;
    font-size: 1.1rem;
    padding: 4px;
    border-radius: 50%;
    transition: all 0.2s;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
  }
  .btn-close-custom:hover {
    background: #f1f5f9;
    color: #64748b;
  }
  
  /* Tag Option Card */
  .tag-option {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    border: 1px solid #f1f5f9;
    border-radius: 12px;
    cursor: pointer;
    background: #fff;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    user-select: none;
  }
  
  .tag-option:hover {
    background: #f8fafc;
    border-color: #e2e8f0;
    transform: translateY(-1px);
  }
  
  /* Selected State */
  .tag-option.is-selected {
    background: #f5f3ff; /* Light indigo bg */
    border-color: #818cf8;
    box-shadow: 0 2px 4px rgba(99, 102, 241, 0.1);
  }
  
  /* Tag Chip */
  .tag-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    background: #f1f5f9;
    color: #64748b;
    line-height: 1.2;
  }
  
  /* Fallback colors */
  .tag-red { background: #fee2e2; color: #b91c1c; }
  .tag-blue { background: #dbeafe; color: #1d4ed8; }
  .tag-green { background: #dcfce7; color: #15803d; }
  .tag-amber { background: #fef3c7; color: #b45309; }
  .tag-gray { background: #f3f4f6; color: #4b5563; }
  
  /* Checkbox Animation */
  .checkbox-indicator {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 2px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    transition: all 0.2s ease;
    font-size: 0.9rem;
  }
  
  .tag-option.is-selected .checkbox-indicator {
    background: #6366f1;
    border-color: #6366f1;
    transform: scale(1.1);
  }
  
  /* Empty State */
  .empty-state {
    text-align: center;
    padding: 24px;
    color: #94a3b8;
    display: flex;
    flex-direction: column;
    align-items: center;
  }
  .empty-state i { font-size: 1.5rem; }
  
  /* Buttons */
  .btn-cancel {
    background: #fff;
    border: 1px solid #e2e8f0;
    color: #64748b;
    font-weight: 600;
    padding: 10px;
    border-radius: 10px;
    transition: all 0.2s;
  }
  .btn-cancel:hover {
    background: #f8fafc;
    color: #334155;
  }
  
  .btn-save {
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    border: none;
    color: white;
    font-weight: 600;
    padding: 10px;
    border-radius: 10px;
    box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2);
    transition: all 0.2s;
  }
  .btn-save:hover {
    filter: brightness(1.1);
    transform: translateY(-1px);
    box-shadow: 0 6px 8px -1px rgba(79, 70, 229, 0.3);
  }
  .btn-save:disabled {
    opacity: 0.7;
    cursor: not-allowed;
    transform: none;
  }
  </style>