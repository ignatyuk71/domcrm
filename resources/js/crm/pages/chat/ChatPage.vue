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
        :is-loading-more="isLoadingMore"
        :has-more="currentPage < lastPage"
        @select="handleSelectChat"
        @load-more="handleLoadMore"
      />
    </template>

    <template #thread>
      <ChatThread
        v-if="activeChat"
        :active-chat="activeChat"
        :messages="messages"
        :is-sending="isSending"
        :is-syncing="isSyncing"
        :loading="isLoading"
        @send="handleSendMessage"
        @force-sync="handleForceSync"
        @open-list="openMobileList"
      />
      <ChatEmpty v-else @open-list="openMobileList" />
    </template>

    <template #profile>
      <ChatProfile :chat="activeChat" />
    </template>
  </ChatLayout>

  <div v-if="isMobileListOpen" class="chat-mobile-overlay">
    <div class="chat-mobile-sheet">
      <div class="chat-mobile-header">
        <h3>Список чатів</h3>
        <button type="button" class="chat-mobile-close" @click="closeMobileList">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
      <div class="chat-mobile-body">
        <ChatSidebar
          :conversations="filteredConversations"
          :active-chat-id="activeChatId"
          @select="handleSelectChat"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref, computed } from 'vue';
import { useChat } from '@/crm/composables/useChat'; 

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
  isLoading, // Ця змінна відповідає за стан завантаження
  isLoadingMore,
  isSending,
  isSyncing,
  currentPage,
  lastPage,
  fetchConversations,
  loadMoreConversations,
  selectChat,
  sendMessage,
  forceSync,
  stopPolling
} = useChat();

const searchQuery = ref('');
const isMobileListOpen = ref(false);

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
  if (isMobileListOpen.value) {
    isMobileListOpen.value = false;
  }
};

const handleLoadMore = () => {
  loadMoreConversations();
};

const handleSendMessage = (payload) => {
  sendMessage({
    ...payload,
    customer_id: activeChatId.value
  });
};

const handleForceSync = () => {
  forceSync(activeChatId.value);
};

const openMobileList = () => {
  isMobileListOpen.value = true;
};

const closeMobileList = () => {
  isMobileListOpen.value = false;
};

// Lifecycle
onMounted(() => {
  fetchConversations(1);
});

onUnmounted(() => {
  stopPolling();
});
</script>

<style scoped>
.chat-mobile-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.35);
  z-index: 50;
  display: flex;
  align-items: stretch;
  justify-content: stretch;
}

.chat-mobile-sheet {
  background: #ffffff;
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
}

.chat-mobile-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid #e2e8f0;
}

.chat-mobile-header h3 {
  margin: 0;
  font-size: 1rem;
  font-weight: 700;
  color: #0f172a;
}

.chat-mobile-close {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  color: #475569;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  padding: 0;
}

.chat-mobile-body {
  flex: 1;
  overflow: hidden;
}

@media (min-width: 769px) {
  .chat-mobile-overlay {
    display: none;
  }
}
</style>
