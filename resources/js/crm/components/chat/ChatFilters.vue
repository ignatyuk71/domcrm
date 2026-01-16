<template>
  <div class="chat-filters-container">
    <div class="search-wrapper">
      <div class="search-input-group">
        <i class="bi bi-search search-icon"></i>
        <input
          :value="search"
          type="text"
          class="search-field"
          placeholder="Пошук клієнта..."
          @input="$emit('update:search', $event.target.value)"
        />
        <button 
          v-if="search" 
          class="clear-search" 
          @click="$emit('update:search', '')"
        >
          <i class="bi bi-x-circle-fill"></i>
        </button>
      </div>
    </div>

    <div class="tabs-wrapper custom-scrollbar-hidden">
      <div class="tabs-list">
        <button
          v-for="tab in tabs"
          :key="tab.value"
          type="button"
          class="tab-item"
          :class="{ active: tab.value === activeTab }"
          @click="$emit('change-tab', tab.value)"
        >
          <i v-if="tab.value === 'instagram'" class="bi bi-instagram me-1"></i>
          <i v-if="tab.value === 'messenger'" class="bi bi-messenger me-1"></i>
          {{ tab.label }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  search: { type: String, default: '' },
  tabs: { type: Array, default: () => [] },
  activeTab: { type: String, default: 'all' },
});

defineEmits(['update:search', 'change-tab']);
</script>

<style scoped>
.chat-filters-container {
  padding: 16px;
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
}

/* Стилі пошуку */
.search-wrapper {
  margin-bottom: 12px;
}

.search-input-group {
  position: relative;
  display: flex;
  align-items: center;
  background: #f1f5f9;
  border-radius: 10px;
  padding: 0 12px;
  border: 1px solid transparent;
  transition: all 0.2s;
}

.search-input-group:focus-within {
  background: #fff;
  border-color: #3b82f6;
  box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
}

.search-icon {
  color: #94a3b8;
  font-size: 0.9rem;
}

.search-field {
  flex: 1;
  border: none;
  background: transparent;
  padding: 10px 8px;
  font-size: 0.9rem;
  color: #1e293b;
  outline: none;
}

.clear-search {
  border: none;
  background: transparent;
  color: #cbd5e1;
  cursor: pointer;
  padding: 0;
  display: flex;
  align-items: center;
}

.clear-search:hover {
  color: #94a3b8;
}

/* Стилі табів */
.tabs-wrapper {
  overflow-x: auto;
  white-space: nowrap;
  -webkit-overflow-scrolling: touch;
}

/* Приховуємо скролбар для табів */
.custom-scrollbar-hidden::-webkit-scrollbar {
  display: none;
}
.custom-scrollbar-hidden {
  -ms-overflow-style: none;
  scrollbar-width: none;
}

.tabs-list {
  display: flex;
  gap: 6px;
}

.tab-item {
  border: 1px solid #e2e8f0;
  background: #fff;
  border-radius: 8px;
  padding: 6px 14px;
  font-size: 0.8rem;
  font-weight: 600;
  color: #64748b;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  align-items: center;
}

.tab-item:hover {
  background: #f8fafc;
  color: #1e293b;
}

.tab-item.active {
  background: #3b82f6;
  border-color: #3b82f6;
  color: #fff;
  box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.2);
}

.me-1 {
  margin-right: 4px;
}
</style>