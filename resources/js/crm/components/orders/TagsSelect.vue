<template>
  <div class="clean-card h-100">
    <div class="card-title-section d-flex justify-content-between align-items-center">
      <div><i class="bi bi-tags-fill text-primary me-2"></i>Теги</div>
      <button 
        type="button" 
        class="btn btn-sm btn-light text-primary fw-bold border-0 hover-lift" 
        @click="openModal"
      >
        <i class="bi bi-gear-fill me-1"></i>
        Керувати
      </button>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-3">
      <div 
        v-for="tag in selectedTagsObjects" 
        :key="tag.id" 
        class="selected-chip animate-pop"
        :style="getTagStyle(tag.color)"
      >
        <i v-if="tag.icon" :class="['bi', tag.icon, 'me-1']" :style="{ color: tag.color || 'inherit' }"></i>
        <span>{{ tag.name }}</span>
      </div>
      
      <div v-if="!modelValue.length" class="text-muted small fst-italic py-2 opacity-75">
        Теги не обрано
      </div>
    </div>
  </div>

  <Teleport to="body">
    <div v-if="isOpen">
      <div class="modal-backdrop fade show bg-dark bg-opacity-25" style="backdrop-filter: blur(4px);"></div>
      
      <div class="modal fade show d-block" tabindex="-1" @click.self="closeModal">
        <div class="modal-dialog modal-dialog-centered modal-sm">
          <div class="modal-content border-0 shadow-xl rounded-4 overflow-hidden">
            
            <div class="modal-header border-bottom-0 pb-0 px-4 pt-4">
              <h5 class="modal-title fw-bold text-dark fs-5">Обрати теги</h5>
              <button type="button" class="btn-close shadow-none" @click="closeModal"></button>
            </div>

            <div class="modal-body px-4 py-3">
              <div v-if="loading" class="text-center py-4">
                <div class="spinner-border text-primary" role="status"></div>
              </div>

              <div v-else class="d-flex flex-column gap-2 tag-list custom-scrollbar">
                <div
                  v-for="tag in allTags"
                  :key="tag.id"
                  class="tag-option"
                  :class="{ 'selected': tempSelected.includes(tag.id) }"
                  @click="toggleTag(tag.id)"
                >
                  <div 
                    class="tag-chip-ui"
                    :style="getTagStyle(tag.color)"
                  >
                    <i v-if="tag.icon" :class="['bi', tag.icon]"></i>
                    <span>{{ tag.name }}</span>
                  </div>

                  <div class="check-icon">
                    <i class="bi bi-check-circle-fill text-primary fs-5"></i>
                  </div>
                </div>
              </div>
            </div>

            <div class="modal-footer border-top-0 px-4 pb-4 pt-2">
              <div class="d-grid gap-2 w-100">
                <button 
                  type="button" 
                  class="btn btn-primary fw-bold rounded-3 shadow-sm py-2" 
                  @click="saveChanges"
                >
                  Зберегти
                </button>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { fetchTags } from '@/crm/api/tags';

const modelValue = defineModel({ type: Array, default: () => [] });

const allTags = ref([]);
const loading = ref(false);
const isOpen = ref(false);
const tempSelected = ref([]);

const selectedTagsObjects = computed(() => {
  return allTags.value.filter(t => modelValue.value.includes(t.id));
});

// Ця функція робить магію кольорів
const getTagStyle = (colorHex) => {
  if (!colorHex) return {}; // Дефолтний сірий стиль з CSS, якщо кольору немає
  
  return {
    backgroundColor: `${colorHex}26`, // 15% прозорості (Hex '26')
    color: colorHex,                  // Текст повним кольором
    borderColor: `${colorHex}4d`      // Бордер 30% прозорості (Hex '4d')
  };
};

onMounted(async () => {
  loading.value = true;
  try {
    const { data } = await fetchTags();
    allTags.value = data?.data || data || [];
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
  }
});

const openModal = () => {
  tempSelected.value = [...modelValue.value];
  isOpen.value = true;
};

const closeModal = () => {
  isOpen.value = false;
};

const toggleTag = (id) => {
  const idx = tempSelected.value.indexOf(id);
  if (idx === -1) tempSelected.value.push(id);
  else tempSelected.value.splice(idx, 1);
};

const saveChanges = () => {
  modelValue.value = [...tempSelected.value];
  isOpen.value = false;
};
</script>

<style scoped>
.clean-card {
  background: #fff;
  border: 1px solid #f1f5f9;
  border-radius: 16px;
  padding: 24px;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -1px rgba(0, 0, 0, 0.02);
  transition: box-shadow 0.3s ease;
}
.clean-card:hover {
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
}

.selected-chip {
  display: inline-flex;
  align-items: center;
  padding: 6px 12px;
  border-radius: 10px;
  font-size: 0.8rem;
  font-weight: 700;
  /* Дефолтний стиль (сірий), якщо немає кольору в базі */
  background: #f8fafc;
  color: #64748b;
  border: 1px solid #e2e8f0; 
  transition: all 0.2s ease;
}

/* Стиль тегу всередині списку */
.tag-chip-ui {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: 8px;
  font-size: 0.85rem;
  font-weight: 600;
  background: #f1f5f9;
  color: #475569;
  border: 1px solid transparent; /* Щоб не стрибало при додаванні кольорового бордера */
}

/* Список опцій */
.tag-list {
  max-height: 350px;
  overflow-y: auto;
}
.tag-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
  border: 1px solid transparent;
  border-radius: 12px;
  cursor: pointer;
  background: #fff;
  transition: all 0.2s;
  margin-bottom: 4px;
}
.tag-option:hover {
  background: #f8fafc;
}
.tag-option.selected {
  background: #f0f7ff;
  border-color: #e0e7ff;
}

/* Іконка галочки */
.check-icon {
  opacity: 0;
  transform: translateX(-10px);
  transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.tag-option.selected .check-icon {
  opacity: 1;
  transform: translateX(0);
}

/* Анімації */
.animate-pop {
  animation: popIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
@keyframes popIn {
  from { opacity: 0; transform: scale(0.9); }
  to { opacity: 1; transform: scale(1); }
}

.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>