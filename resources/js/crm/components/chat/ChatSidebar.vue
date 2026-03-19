<template>
  <div class="chat-sidebar-list" @scroll="handleScroll">
    <ChatSidebarItem
      v-for="chat in conversations"
      :key="chat.conversation_id"
      :item="chat"
      :is-active="chat.conversation_id === activeConversationId"
      @select="$emit('select', chat)"
    />

    <div v-if="!conversations.length" class="chat-sidebar-empty">
      <div class="empty-icon-wrapper">
        <i class="bi bi-chat-square-dots"></i>
      </div>
      <strong>Порожньо</strong>
      <p>Нові переписки з Messenger та Instagram з’являться тут.</p>
    </div>

    <div v-if="isLoadingMore" class="chat-sidebar-loading">
      <div class="spinner"></div>
    </div>
  </div>
</template>

<script setup>
import ChatSidebarItem from './ChatSidebarItem.vue';

const props = defineProps({
  conversations: { type: Array, default: () => [] },
  activeConversationId: { type: [Number, String, null], default: null },
  isLoadingMore: { type: Boolean, default: false },
  hasMore: { type: Boolean, default: false },
});

const emit = defineEmits(['select', 'load-more']);

function handleScroll(event) {
  if (!props.hasMore || props.isLoadingMore) {
    return;
  }

  const { scrollTop, clientHeight, scrollHeight } = event.target;
  if (scrollTop + clientHeight >= scrollHeight - 120) {
    emit('load-more');
  }
}
</script>

<style scoped>
.chat-sidebar-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 0 14px 18px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  scrollbar-width: thin;
  scrollbar-color: rgba(148, 163, 184, 0.32) transparent;
}

.chat-sidebar-list::-webkit-scrollbar {
  width: 6px;
}

.chat-sidebar-list::-webkit-scrollbar-track {
  background: transparent;
}

.chat-sidebar-list::-webkit-scrollbar-thumb {
  background: rgba(148, 163, 184, 0.32);
  border-radius: 999px;
}

.chat-sidebar-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 56px 20px;
  text-align: center;
  color: #64748b;
}

.empty-icon-wrapper {
  width: 64px;
  height: 64px;
  border-radius: 22px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, rgba(14, 165, 233, 0.12), rgba(15, 23, 42, 0.08));
  color: #0f172a;
  font-size: 28px;
}

.chat-sidebar-empty strong {
  font-size: 15px;
  font-weight: 800;
  color: #0f172a;
}

.chat-sidebar-empty p {
  margin: 0;
  max-width: 240px;
  font-size: 13px;
  line-height: 1.5;
}

.chat-sidebar-loading {
  display: flex;
  justify-content: center;
  padding: 10px 0 2px;
}

.spinner {
  width: 18px;
  height: 18px;
  border: 2px solid rgba(148, 163, 184, 0.24);
  border-top-color: #0ea5e9;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
