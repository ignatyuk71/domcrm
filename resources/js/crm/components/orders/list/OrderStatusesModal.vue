<script setup>
  const props = defineProps({
    open: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    statuses: { type: Array, default: () => [] },
    modelValue: { type: [Number, String, null], default: null },
  });
  
  const emit = defineEmits(['update:modelValue', 'close', 'save']);
  
  // Вибір статусу (одразу зберігає)
  const selectStatus = (id) => {
    if (props.loading) return; // Блокуємо, якщо вже йде завантаження
    
    emit('update:modelValue', id);
    emit('save'); // Ініціюємо збереження в батьківському компоненті
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
            <div class="modal-header border-0 pb-0 pt-4 px-4">
              <div class="d-flex flex-column">
                <h5 class="modal-title fw-bold text-dark">Змінити статус</h5>
                <span class="text-muted small">Оберіть новий статус зі списку</span>
              </div>
              <button type="button" class="btn-close-custom" @click="$emit('close')">
                <i class="bi bi-x-lg"></i>
              </button>
            </div>
  
            <!-- Body (Список статусів) -->
            <div class="modal-body p-4">
              <!-- Стан завантаження -->
              <div v-if="loading" class="d-flex flex-column align-items-center justify-content-center py-5 text-muted">
                <div class="spinner-border text-primary mb-2" role="status"></div>
                <div class="small fw-medium">Оновлення статусу...</div>
              </div>
  
              <!-- Список -->
              <div v-else class="d-flex flex-column gap-2">
                <div
                  v-for="st in statuses"
                  :key="st.id"
                  class="status-option"
                  :class="{ 'is-selected': modelValue === st.id }"
                  @click="selectStatus(st.id)"
                  :style="modelValue === st.id && st.color ? { borderColor: st.color, backgroundColor: st.color + '08' } : {}"
                >
                  <!-- Стилізований бейдж статусу -->
                  <div 
                    class="status-pill"
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
  
                  <!-- Індикатор вибору -->
                  <div class="selection-indicator">
                    <i v-if="modelValue === st.id" class="bi bi-check-circle-fill" :style="{ color: st.color || '#6366f1' }"></i>
                    <i v-else class="bi bi-circle text-muted opacity-25"></i>
                  </div>
                </div>
  
                <!-- Якщо пусто -->
                <div v-if="!statuses.length" class="empty-state">
                  <i class="bi bi-list-check text-muted mb-2"></i>
                  <span>Статуси не знайдено</span>
                </div>
              </div>
            </div>
            
            <!-- Footer відсутній, бо дія миттєва -->
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
  
  /* Status Option Card */
  .status-option {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border: 1px solid #f1f5f9;
    border-radius: 12px;
    cursor: pointer;
    background: #fff;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    user-select: none;
  }
  
  .status-option:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
  }
  
  /* Selected State */
  .status-option.is-selected {
    border-width: 1px;
    background: #f9fafb;
  }
  
  /* Status Pill Design */
  .status-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    line-height: 1;
  }
  
  /* Check Icon */
  .selection-indicator {
    font-size: 1.2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s;
  }
  
  .status-option.is-selected .selection-indicator {
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
  </style>