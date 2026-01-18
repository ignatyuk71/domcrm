<template>
  <div class="chat-sidebar-inner">
    <div class="chat-sidebar-list custom-scrollbar" @scroll="handleScroll">
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

      <div v-if="isLoadingMore" class="chat-sidebar-loading">
        <div class="spinner"></div>
      </div>
    </div>
  </div>
</template>

<script setup>
import ChatSidebarItem from './ChatSidebarItem.vue';

const props = defineProps({
  conversations: { type: Array, default: () => [] },
  activeChatId: { type: [Number, String, null], default: null },
  isLoadingMore: { type: Boolean, default: false },
  hasMore: { type: Boolean, default: false },
  disableScroll: { type: Boolean, default: false },
});

const emit = defineEmits(['select', 'load-more']);

function handleScroll(event) {
  if (!props.hasMore || props.isLoadingMore || props.disableScroll) return;
  const { scrollTop, clientHeight, scrollHeight } = event.target;
  if (scrollTop + clientHeight >= scrollHeight - 50) {
    emit('load-more');
  }
}
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

.chat-sidebar-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 12px 0;
}

.spinner {
  width: 18px;
  height: 18px;
  border: 2px solid #e2e8f0;
  border-top-color: #3b82f6;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

</style>
