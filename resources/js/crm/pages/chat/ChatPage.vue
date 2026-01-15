<template>
  <section class="chat-page">
    <ChatLayout>
      <template #sidebar>
        <ChatSidebar
          :conversations="filteredConversations"
          :active-chat-id="activeChatId"
          :search="search"
          :tabs="tabs"
          :active-tab="activeTab"
          @select="handleSelect"
          @update:search="search = $event"
          @change-tab="activeTab = $event"
        />
      </template>
      <template #thread>
        <ChatThread
          :active-chat="activeChat"
          :messages="messages"
          :sending="isSending"
          :title="activeChat?.customer_name"
          :subtitle="activeChat?.last_message_time"
          @send="handleSend"
        />
      </template>
      <template #profile>
        <ChatCustomerProfile :chat="activeChat" />
      </template>
    </ChatLayout>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import ChatCustomerProfile from '@/crm/components/chat/ChatCustomerProfile.vue';
import ChatLayout from '@/crm/components/chat/ChatLayout.vue';
import ChatSidebar from '@/crm/components/chat/ChatSidebar.vue';
import ChatThread from '@/crm/components/chat/ChatThread.vue';
import { useChat } from '@/crm/composables/useChat';

const search = ref('');
const activeTab = ref('all');
const tabs = [
  { value: 'all', label: 'Всі' },
  { value: 'messenger', label: 'Messenger' },
  { value: 'instagram', label: 'Instagram' },
];

const {
  conversations,
  activeChatId,
  activeChat,
  messages,
  isSending,
  fetchConversations,
  selectChat,
  sendMessage,
} = useChat();

const filteredConversations = computed(() => {
  const needle = search.value.trim().toLowerCase();
  return conversations.value.filter((chat) => {
    if (activeTab.value !== 'all' && chat.platform !== activeTab.value) return false;
    if (!needle) return true;
    return (chat.customer_name || '').toLowerCase().includes(needle);
  });
});

function handleSelect(chat) {
  selectChat(chat.customer_id);
}

function handleSend(text) {
  if (!activeChat.value) return;
  sendMessage({
    customer_id: activeChat.value.customer_id,
    message: text,
  });
}

onMounted(fetchConversations);
</script>

<style scoped>
.chat-page {
  min-height: calc(100vh - 32px);
}

.chat-layout {
  display: grid;
  grid-template-columns: 320px minmax(0, 1fr) 300px;
  gap: 16px;
}

.chat-sidebar,
.chat-thread,
.chat-profile {
  background: #fff;
  border-radius: 16px;
  border: 1px solid #e2e8f0;
  min-height: 70vh;
  display: flex;
  flex-direction: column;
}

.chat-sidebar-inner {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.chat-filters {
  padding: 16px;
  border-bottom: 1px solid #e2e8f0;
}

.chat-search {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 8px 12px;
}

.chat-search input {
  border: none;
  background: transparent;
  width: 100%;
  outline: none;
}

.chat-tabs {
  display: flex;
  gap: 8px;
  margin-top: 12px;
  overflow-x: auto;
}

.chat-tab {
  border: 1px solid #e2e8f0;
  background: #fff;
  border-radius: 999px;
  padding: 6px 12px;
  font-size: 0.8rem;
  font-weight: 600;
  color: #475569;
}

.chat-tab.active {
  background: #3b82f6;
  border-color: #3b82f6;
  color: #fff;
}

.chat-sidebar-list {
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  overflow-y: auto;
}

.chat-sidebar-item {
  text-align: left;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 12px;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.chat-sidebar-item.active {
  border-color: #3b82f6;
  background: #eff6ff;
}

.chat-item-title {
  display: flex;
  gap: 8px;
  align-items: center;
  font-weight: 700;
  color: #1e293b;
}

.chat-item-platform {
  font-size: 0.7rem;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 6px;
  background: #f1f5f9;
  color: #475569;
}

.chat-item-preview {
  font-size: 0.85rem;
  color: #64748b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.chat-item-meta {
  display: flex;
  justify-content: space-between;
  font-size: 0.75rem;
  color: #94a3b8;
}

.chat-item-badge {
  background: #ef4444;
  color: #fff;
  border-radius: 999px;
  padding: 2px 8px;
}

.chat-thread-inner {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.chat-thread-header {
  padding: 16px;
  border-bottom: 1px solid #e2e8f0;
}

.chat-thread-title {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-weight: 700;
}

.chat-thread-subtitle {
  font-size: 0.8rem;
  color: #94a3b8;
}

.chat-thread-body {
  flex: 1;
  padding: 16px;
  overflow-y: auto;
}

.chat-messages {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.chat-message {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
}

.chat-message.mine {
  align-items: flex-end;
}

.chat-message-bubble {
  background: #f1f5f9;
  border-radius: 12px;
  padding: 10px 14px;
  color: #1e293b;
  max-width: 70%;
  white-space: pre-wrap;
}

.chat-message.mine .chat-message-bubble {
  background: #3b82f6;
  color: #fff;
}

.chat-message-time {
  font-size: 0.7rem;
  color: #94a3b8;
}

.chat-composer {
  border-top: 1px solid #e2e8f0;
  padding: 12px;
  display: flex;
  gap: 12px;
}

.chat-composer textarea {
  flex: 1;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 8px 12px;
  resize: none;
}

.chat-composer button {
  border: none;
  background: #3b82f6;
  color: #fff;
  border-radius: 10px;
  padding: 0 18px;
  font-weight: 600;
}

.chat-empty {
  text-align: center;
  color: #94a3b8;
  margin-top: 80px;
}

.chat-empty-title {
  font-weight: 700;
  color: #1e293b;
}

.chat-profile-card {
  padding: 16px;
}

.chat-profile-name {
  font-weight: 700;
  margin-bottom: 6px;
}

.chat-profile-meta {
  color: #94a3b8;
  font-size: 0.85rem;
}

.chat-profile-empty {
  color: #94a3b8;
  text-align: center;
  padding-top: 40px;
}

.chat-sidebar-empty {
  text-align: center;
  color: #94a3b8;
  padding: 24px 0;
}

.chat-empty-inline {
  color: #94a3b8;
  text-align: center;
  padding: 20px 0;
}

@media (max-width: 991px) {
  .chat-layout {
    grid-template-columns: 1fr;
  }

  .chat-profile {
    display: none;
  }
}
</style>
