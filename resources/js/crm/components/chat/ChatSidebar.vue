<template>
  <div class="chat-sidebar-inner">
    <ChatFilters
      :search="search"
      :tabs="tabs"
      :active-tab="activeTab"
      @update:search="$emit('update:search', $event)"
      @change-tab="$emit('change-tab', $event)"
    />

    <div class="chat-sidebar-list custom-scrollbar" ref="sidebarList">
      <ChatSidebarItem
        v-for="chat in conversations"
        :key="chat.customer_id"
        :item="chat"
        :is-active="chat.customer_id === activeChatId"
        @select="$emit('select', chat)"
      />

      <div v-if="!conversations.length" class="chat-sidebar-empty">
        <div class="empty-icon-wrapper">
          <i class="bi bi-chat-left-text"></i>
        </div>
        <p>Чатів не знайдено</p>
      </div>

      <button
        v-if="hasMore"
        type="button"
        class="chat-load-more"
        @click="$emit('load-more')"
      >
        <i class="bi bi-plus-circle"></i>
        <span>Показати більше</span>
      </button>
      
      <div class="pb-3"></div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
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

const sidebarList = ref(null);
</script>

<style scoped>
.chat-sidebar-inner {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
  background: #fff;
}

/* Область списку з полоскою прокрутки */
.chat-sidebar-list {
  flex: 1;
  overflow-y: scroll; /* Примусово резервуємо місце під скролбар */
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  background: #fff;
  
  /* Для Firefox */
  scrollbar-width: thin;
  scrollbar-color: #cbd5e1 transparent;
}

/* --- СТИЛІЗАЦІЯ ПОЛОСКИ СКРОЛУ (Webkit) --- */
.custom-scrollbar::-webkit-scrollbar {
  width: 6px; /* Тонка, але помітна полоска */
}

.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: #e2e8f0; /* Світлий колір, щоб не відволікати */
  border-radius: 20px;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background-color: #cbd5e1; /* Темніша при наведенні */
}

/* Порожній стан */
.chat-sidebar-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px 20px;
  color: #94a3b8;
}

.empty-icon-wrapper {
  font-size: 2.5rem;
  margin-bottom: 12px;
  opacity: 0.3;
}

.chat-sidebar-empty p {
  font-weight: 500;
  font-size: 0.9rem;
}

/* Кнопка "Завантажити ще" */
.chat-load-more {
  width: 100%;
  padding: 10px;
  margin-top: 12px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  color: #64748b;
  border-radius: 10px;
  font-size: 0.8rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: all 0.2s;
}

.chat-load-more:hover {
  background: #fff;
  border-color: #3b82f6;
  color: #3b82f6;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
}

.pb-3 { padding-bottom: 1rem; }
</style>