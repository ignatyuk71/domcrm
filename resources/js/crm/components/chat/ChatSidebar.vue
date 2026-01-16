<template>
  <div class="chat-sidebar-inner">
    <div class="chat-sidebar-list custom-scrollbar">
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
    </div>
  </div>
</template>

<script setup>
import ChatSidebarItem from './ChatSidebarItem.vue';

defineProps({
  conversations: { type: Array, default: () => [] },
  activeChatId: { type: [Number, String, null], default: null },
});

defineEmits(['select']);
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

</style>
