<template>
  <ChatLayout :view-mode="viewMode">
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
        :disable-scroll="searchQuery.length > 0"
        @select="handleSelectChat"
        @load-more="handleLoadMore"
      />
    </template>

    <template #thread>
      <ChatThread
        v-if="activeChat"
        :active-chat="activeChat"
        :conversation-tags="conversationTags"
        :tags-loading="isTagsLoading"
        :messages="messages"
        :is-sending="isSending"
        :is-syncing="isSyncing"
        :loading="isLoading"
        @send="handleSendMessage"
        @force-sync="handleForceSync"
        @open-list="openMobileList"
        @open-profile="openProfile"
        @update-stage="handleUpdateStage"
        @update-tags="handleUpdateTags"
      />
      <ChatEmpty v-else @open-list="openMobileList" />
    </template>

    <template #profile>
      <ChatProfile :customer="activeChat" @close="closeProfile" />
    </template>
  </ChatLayout>
</template>

<script setup>
import { onMounted, onUnmounted, ref, computed } from 'vue';
import { useChat } from '@/crm/composables/useChat'; 

// Імпорт компонентів UI
import ChatLayout from '@/crm/components/chat/ChatLayout.vue';
import ChatSidebar from '@/crm/components/chat/ChatSidebar.vue';
import ChatThread from '@/crm/components/chat/ChatThread.vue';
import ChatEmpty from '@/crm/components/chat/ChatEmptyState.vue';
// Імпортуємо наш новий профіль (який ми щойно зробили)
import ChatProfile from '@/crm/components/chat/ChatCustomerProfile.vue';

const {
  conversations,
  activeChat,
  activeChatId,
  messages,
  isLoading, 
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
  stopPolling,
  getConversationTags,
  updateStage,
  updateTags,
  ensureConversation
} = useChat();

const searchQuery = ref('');
const viewMode = ref('list');
const conversationTags = ref([]);
const isTagsLoading = ref(false);

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
  viewMode.value = 'thread';
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
  viewMode.value = 'list';
};

const openProfile = () => {
  viewMode.value = 'profile';
};

const closeProfile = () => {
  viewMode.value = 'thread';
};

const handleUpdateStage = ({ conversationId, stage }) => {
  updateStage(conversationId, stage);
};

const handleUpdateTags = ({ conversationId, tagIds }) => {
  const optimisticTags = conversationTags.value.filter((tag) => tagIds.includes(tag.id));
  updateTags(conversationId, tagIds, optimisticTags);
};

const loadConversationTags = async () => {
  isTagsLoading.value = true;
  try {
    const { data } = await getConversationTags();
    conversationTags.value = data?.data || data || [];
  } catch (e) {
    console.error('Не вдалося завантажити теги чатів', e);
  } finally {
    isTagsLoading.value = false;
  }
};

// Lifecycle
onMounted(() => {
  initChat();
});

onUnmounted(() => {
  stopPolling();
});

const initChat = async () => {
  await fetchConversations(1);
  loadConversationTags();

  const params = new URLSearchParams(window.location.search);
  const customerId = Number(params.get('customer_id'));
  if (!customerId) return;

  const platform = params.get('platform') || null;
  const conversation = await ensureConversation(customerId, platform);
  if (conversation) {
    selectChat(customerId);
    viewMode.value = 'thread';
  }
};
</script>

<style scoped>
</style>
