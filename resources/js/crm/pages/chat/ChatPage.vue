<template>
  <ChatLayout>
    <template #sidebar>
      <div class="p-3 border-b border-gray-200 bg-white">
        <input 
          v-model="searchQuery" 
          type="text" 
          placeholder="Пошук чату..." 
          class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-blue-400"
        >
      </div>
      
      <div v-if="isLoading && !conversations.length" class="p-4 text-center text-gray-400">
        <i class="bi bi-arrow-clockwise animate-spin text-2xl"></i>
      </div>

      <ChatSidebar
        v-else
        :conversations="filteredConversations"
        :active-chat-id="activeChatId"
        @select="handleSelectChat"
      />
    </template>

    <template #thread>
      <ChatThread
        v-if="activeChat"
        :active-chat="activeChat"
        :messages="messages"
        :is-sending="isSending"
        @send="handleSendMessage"
      />
      <ChatEmpty v-else />
    </template>

    <template #profile>
      <ChatProfile :chat="activeChat" />
    </template>
  </ChatLayout>
</template>

<script setup>
import { onMounted, onUnmounted, ref, computed } from 'vue';
import { useChat } from '@/crm/composables/useChat'; // Ваш composable

// Імпорт компонентів UI
import ChatLayout from '@/crm/components/chat/ChatLayout.vue';
import ChatSidebar from '@/crm/components/chat/ChatSidebar.vue';
import ChatThread from '@/crm/components/chat/ChatThread.vue';
import ChatEmpty from '@/crm/components/chat/ChatEmptyState.vue';
import ChatProfile from '@/crm/components/chat/ChatCustomerProfile.vue';

const {
  conversations,
  activeChat,
  activeChatId,
  messages,
  isLoading,
  isSending,
  fetchConversations,
  selectChat,
  sendMessage,
  stopPolling
} = useChat();

const searchQuery = ref('');

// Фільтрація списку чатів
const filteredConversations = computed(() => {
  if (!searchQuery.value) return conversations.value;
  const q = searchQuery.value.toLowerCase();
  return conversations.value.filter(c => 
    (c.customer_name || '').toLowerCase().includes(q) ||
    (c.last_message || '').toLowerCase().includes(q)
  );
});

// Обробники подій
const handleSelectChat = (chat) => {
  selectChat(chat.customer_id);
};

const handleSendMessage = (payload) => {
  // Додаємо customer_id, який очікує useChat / бекенд
  sendMessage({
    ...payload,
    customer_id: activeChatId.value
  });
};

// Lifecycle
onMounted(() => {
  fetchConversations();
});

onUnmounted(() => {
  stopPolling();
});
</script>
