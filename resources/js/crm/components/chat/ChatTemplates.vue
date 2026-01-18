<template>
  <div class="templates-popover">
    <div class="templates-header">
      <h4 class="title">Збережені відповіді</h4>
      <a href="/templates/create" target="_blank" class="add-link">+ Додати</a>
    </div>

    <div class="templates-list custom-scrollbar">
      <div v-if="isLoading" class="state-msg"><div class="spinner"></div></div>
      
      <div v-else-if="templates.length === 0" class="state-msg text-gray-400">
        Шаблонів немає
      </div>

      <div 
        v-else
        v-for="tpl in templates" 
        :key="tpl.id" 
        class="template-item"
        @click="$emit('select', tpl.content)"
      >
        <div class="template-icon"><i class="bi bi-card-text"></i></div>
        <div class="template-info">
          <div class="template-title">{{ tpl.title }}</div>
          <div class="template-preview">{{ tpl.content }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { getTemplates } from '@/crm/services/chatApi';

const templates = ref([]);
const isLoading = ref(false);

// Логіку filteredTemplates та searchQuery видалено

onMounted(async () => {
  isLoading.value = true;
  try {
    const { data } = await getTemplates();
    templates.value = data.data || data || [];
  } catch (e) {
    console.error(e);
  } finally {
    isLoading.value = false;
  }
});
</script>

<style scoped>
.templates-popover {
  position: absolute;
  bottom: 55px;
  left: 0;
  width: 320px;
  max-height: 400px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  box-shadow: 0 10px 25px rgba(0,0,0,0.1);
  display: flex;
  flex-direction: column;
  z-index: 50;
  overflow: hidden;
}

.templates-header {
  display: flex; 
  justify-content: space-between; 
  align-items: center;
  padding: 12px 16px; 
  border-bottom: 1px solid #f1f5f9; 
  background: #fff;
}

.title {
  font-size: 0.95rem; 
  font-weight: 700; 
  margin: 0;
}

.add-link {
  font-size: 0.85rem; 
  color: #3b82f6; 
  text-decoration: none; 
  font-weight: 600;
}

/* Стилі пошуку видалено */

.templates-list {
  flex: 1;
  overflow-y: auto;
  max-height: 300px;
}

.template-item {
  display: flex; 
  gap: 12px; 
  padding: 12px 16px; 
  cursor: pointer; 
  border-bottom: 1px solid #f8fafc;
}

.template-item:hover {
  background: #f8fafc;
}

.template-icon {
  width: 32px; 
  height: 32px; 
  background: #f1f5f9; 
  color: #64748b;
  border-radius: 8px; 
  display: flex; 
  align-items: center; 
  justify-content: center;
}

.template-info {
  flex: 1; 
  min-width: 0;
}

.template-title {
  font-size: 0.9rem; 
  font-weight: 600; 
  color: #334155; 
  margin-bottom: 2px;
}

.template-preview {
  font-size: 0.8rem; 
  color: #64748b; 
  white-space: nowrap; 
  overflow: hidden; 
  text-overflow: ellipsis;
}

.state-msg {
  padding: 20px; 
  text-align: center;
}

.spinner {
  width: 20px; 
  height: 20px; 
  border: 2px solid #e2e8f0; 
  border-top-color: #3b82f6;
  border-radius: 50%; 
  animation: spin 0.8s linear infinite; 
  margin: 0 auto;
}

@keyframes spin { 
  to { transform: rotate(360deg); } 
}
</style>