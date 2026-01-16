<template>
  <section class="chat-page h-full">
    <ChatLayout>
      <!-- 1. Слот для сайдбару -->
      <template #sidebar>
        <ChatSidebar
          :conversations="filteredConversations"
          :active-chat-id="activeChatId"
          :search="search"
          :tabs="tabs"
          :active-tab="activeTab"
          :has-more="hasMore"
          :is-loading="isLoading"
          @select="handleSelect"
          @update:search="search = $event"
          @change-tab="activeTab = $event"
          @load-more="loadMore"
        />
      </template>

      <!-- 2. Слот для основного вікна чату -->
      <template #thread>
        <ChatThread
          v-if="activeChatId"
          :active-chat="activeChat"
          :messages="messages"
          :title="activeChat?.customer_name"
          :subtitle="activeChat?.last_message_time"
        />
        <ChatEmptyState v-else />
      </template>

      <!-- 3. Слот для профілю клієнта -->
      <template #profile v-if="activeChatId">
        <ChatCustomerProfile :chat="activeChat" />
      </template>
    </ChatLayout>

    <!-- Повідомлення про помилки -->
    <Transition name="toast">
      <div v-if="error" class="fixed bottom-4 right-4 bg-red-600 text-white px-5 py-3 rounded-xl shadow-2xl z-50 flex items-center gap-3">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        <span class="text-sm font-medium">{{ error }}</span>
      </div>
    </Transition>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// Використовуємо аліас @ (зазвичай resources/js)
import ChatLayout from '@/crm/components/chat/ChatLayout.vue';
import ChatSidebar from '@/crm/components/chat/ChatSidebar.vue';
import ChatThread from '@/crm/components/chat/ChatThread.vue';
import ChatEmptyState from '@/crm/components/chat/ChatEmptyState.vue';
import ChatCustomerProfile from '@/crm/components/chat/ChatCustomerProfile.vue';
import { useChat } from '@/crm/composables/useChat';

const search = ref('');
const activeTab = ref('all');

const tabs = [
  { value: 'all', label: 'Всі' },
  { value: 'facebook', label: 'Messenger' },
  { value: 'instagram', label: 'Instagram' },
];

const {
  conversations,
  activeChatId,
  activeChat,
  messages,
  isLoading,
  hasMore,
  error,
  fetchConversations,
  loadMore,
  selectChat,
  stopPolling,
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

onMounted(() => {
  fetchConversations();
});

onBeforeUnmount(() => {
  stopPolling();
});
</script>

<style scoped>
.toast-enter-active, .toast-leave-active {
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.toast-enter-from, .toast-leave-to {
  opacity: 0;
  transform: translateY(20px) scale(0.95);
}
</style>