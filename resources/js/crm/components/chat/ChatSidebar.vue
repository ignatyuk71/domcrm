<template>
  <div class="chat-sidebar-inner">
    <ChatFilters
      :search="search"
      :tabs="tabs"
      :active-tab="activeTab"
      @update:search="$emit('update:search', $event)"
      @change-tab="$emit('change-tab', $event)"
    />

    <div class="chat-sidebar-list custom-scrollbar">
      <ChatSidebarItem
        v-for="chat in conversations"
        :key="chat.customer_id"
        :item="chat"
        :is-active="chat.customer_id === activeChatId"
        @select="$emit('select', chat)"
      />

      <div v-if="!conversations.length" class="chat-sidebar-empty">
        <i class="bi bi-chat-square-text"></i>
        <span>Чатів не знайдено</span>
      </div>

      <button
        v-if="hasMore"
        type="button"
        class="chat-load-more"
        @click="$emit('load-more')"
      >
        <i class="bi bi-arrow-clockwise"></i>
        <span>Завантажити ще...</span>
      </button>
      
      <div class="pb-2"></div>
    </div>
  </div>
</template>

<script setup>
import ChatFilters from './ChatFilters.vue';
import ChatSidebarItem from './ChatSidebarItem.vue';

defineProps({
  conversations: { type: Array, default: () => [] },
  activeChatId: { type: [Number, String, null], default: null },
  search: { type: String, default: '' },
  tabs: { type: Array, default: () => [] },
  activeTab: { type: String, default: 'all' },
  hasMore: { type: Boolean, default: false },
});

defineEmits(['select', 'update:search', 'change-tab', 'load-more']);
</script>

<style scoped>
/* Головний контейнер сайдбару */
.chat-sidebar-inner {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden; /* Ховаємо скрол контейнера */
  background: #fff;
  border-radius: 16px; /* Закруглення всього блоку */
}

/* Область списку, яка скролиться */
.chat-sidebar-list {
  flex: 1; /* Займає всю доступну висоту */
  overflow-y: auto; /* Вертикальний скрол */
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 8px; /* Відступ між картками */
}

/* --- Стилізація скролбару (Webkit) --- */
.custom-scrollbar::-webkit-scrollbar {
  width: 5px; /* Тонкий скрол */
}

.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: #cbd5e1; /* Світло-сірий */
  border-radius: 4px;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background-color: #94a3b8; /* Темніший при наведенні */
}

/* --- Порожній стан --- */
.chat-sidebar-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 40px 20px;
  color: #94a3b8;
  gap: 10px;
  text-align: center;
}

.chat-sidebar-empty i {
  font-size: 2rem;
  opacity: 0.5;
}

/* --- Кнопка "Завантажити ще" --- */
.chat-load-more {
  width: 100%;
  padding: 12px;
  margin-top: 8px;
  border: 1px dashed #cbd5e1; /* Пунктирна рамка */
  background: #f8fafc;
  color: #64748b;
  border-radius: 10px;
  font-size: 0.85rem;
  font-weight: 500;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: all 0.2s ease;
}

.chat-load-more:hover {
  background: #eff6ff;
  border-color: #3b82f6;
  color: #3b82f6;
}

.chat-load-more:active {
  transform: scale(0.98);
}
</style>