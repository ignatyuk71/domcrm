<template>
  <ChatLayout :view-mode="viewMode">
    <template #topbar>
      <div class="inbox-tabs">
        <button
          v-for="tab in platformTabs"
          :key="tab.value"
          type="button"
          class="inbox-tab"
          :class="{ 'is-active': platformFilter === tab.value }"
          @click="platformFilter = tab.value"
        >
          {{ tab.label }}
          <span class="tab-count">{{ getPlatformCount(tab.value) }}</span>
        </button>
      </div>
    </template>

    <template #sidebar>
      <div class="chat-sidebar-shell">
        <div class="chat-sidebar-search">
          <i class="bi bi-search"></i>
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Пошук"
          >
        </div>

        <div class="sidebar-toolbar">
          <button type="button" class="manage-btn">
            <i class="bi bi-stack"></i>
            Управляти
          </button>
        </div>

        <div class="sidebar-filters">
          <button type="button" class="filter-chip is-active">Непрочитані</button>
          <button type="button" class="filter-chip">Контакти</button>
          <button type="button" class="filter-chip">
            <i class="bi bi-sliders"></i>
          </button>
        </div>

        <ChatSidebar
          :conversations="filteredConversations"
          :active-conversation-id="activeConversationId"
          :is-loading-more="isLoadingMore"
          :has-more="currentPage < lastPage && !searchQuery && platformFilter === 'all'"
          @select="handleSelectChat"
          @load-more="handleLoadMore"
        />
      </div>
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
        @open-profile="openProfile"
        @update-stage="handleUpdateStage"
      />
      <ChatEmpty v-else @open-list="openMobileList" />
    </template>

    <template #profile>
      <ChatProfile
        :customer="activeChat"
        @close="closeProfile"
        @update-stage="handleUpdateStage"
      />
    </template>
  </ChatLayout>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useChat } from '@/crm/composables/useChat';
import ChatLayout from '@/crm/components/chat/ChatLayout.vue';
import ChatSidebar from '@/crm/components/chat/ChatSidebar.vue';
import ChatThread from '@/crm/components/chat/ChatThread.vue';
import ChatEmpty from '@/crm/components/chat/ChatEmptyState.vue';
import ChatProfile from '@/crm/components/chat/ChatCustomerProfile.vue';

const platformTabs = [
  { value: 'all', label: 'Усі повідомлення' },
  { value: 'messenger', label: 'Messenger' },
  { value: 'instagram', label: 'Instagram' },
  { value: 'facebook_comments', label: 'Коментарі на Facebook' },
  { value: 'instagram_comments', label: 'Коментарі в Instagram' },
];

const {
  conversations,
  activeChat,
  activeConversationId,
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
  updateStage,
  ensureConversation,
} = useChat();

const searchQuery = ref('');
const platformFilter = ref('all');
const viewMode = ref('list');

const filteredConversations = computed(() => {
  const query = searchQuery.value.trim().toLowerCase();

  return conversations.value.filter((chat) => {
    const matchesPlatform = matchConversationByTab(chat, platformFilter.value);
    if (!matchesPlatform) {
      return false;
    }

    if (!query) {
      return true;
    }

    const haystack = [
      chat.customer_name,
      chat.last_message,
      chat.external_username,
      chat.platform === 'instagram' ? 'instagram' : 'messenger',
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase();

    return haystack.includes(query);
  });
});

function getPlatformCount(platform) {
  return conversations.value.filter((chat) => matchConversationByTab(chat, platform)).length;
}

function looksLikeCommentThread(chat) {
  const preview = String(chat?.last_message || '').toLowerCase();
  return preview.includes('коментар') || preview.includes('комментар') || preview.includes('comment');
}

function matchConversationByTab(chat, tab) {
  if (tab === 'all') {
    return true;
  }

  if (tab === 'messenger' || tab === 'instagram') {
    return chat.platform === tab;
  }

  if (tab === 'facebook_comments') {
    return chat.platform === 'messenger' && looksLikeCommentThread(chat);
  }

  if (tab === 'instagram_comments') {
    return chat.platform === 'instagram' && looksLikeCommentThread(chat);
  }

  return true;
}

function handleSelectChat(chat) {
  selectChat(chat);
  viewMode.value = 'thread';
}

function handleLoadMore() {
  loadMoreConversations();
}

function handleSendMessage(payload) {
  if (!activeChat.value) {
    return;
  }

  sendMessage({
    ...payload,
    customer_id: activeChat.value.customer_id,
    conversation_id: activeChat.value.conversation_id,
    platform: activeChat.value.platform,
  });
}

function handleForceSync() {
  if (!activeChat.value) {
    return;
  }

  forceSync(activeChat.value);
}

function handleUpdateStage({ conversationId, stage }) {
  updateStage(conversationId, stage);
}

function openMobileList() {
  viewMode.value = 'list';
}

function openProfile() {
  viewMode.value = 'profile';
}

function closeProfile() {
  viewMode.value = 'thread';
}

async function initChat() {
  await fetchConversations(1);

  const params = new URLSearchParams(window.location.search);
  const customerId = Number(params.get('customer_id'));
  const platform = params.get('platform') || null;

  if (!customerId) {
    return;
  }

  const conversation = await ensureConversation(customerId, platform);
  if (conversation) {
    handleSelectChat(conversation);
  }
}

onMounted(initChat);
onUnmounted(stopPolling);
</script>

<style scoped>
.chat-sidebar-shell {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  background: #fff;
}

.chat-sidebar-search {
  position: relative;
  padding: 18px 16px 12px;
}

.chat-sidebar-search i {
  position: absolute;
  top: 50%;
  left: 30px;
  transform: translateY(-50%);
  color: #6b7280;
  font-size: 14px;
}

.chat-sidebar-search input {
  width: 100%;
  height: 42px;
  padding: 0 14px 0 40px;
  border: 1px solid #d1d5db;
  border-radius: 10px;
  background: #fff;
  color: #0f172a;
  outline: none;
  transition: border-color 0.2s ease;
}

.sidebar-toolbar,
.sidebar-filters {
  display: flex;
  gap: 8px;
  padding: 0 16px 12px;
}

.manage-btn,
.filter-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  border: 1px solid #d1d5db;
  border-radius: 999px;
  background: #fff;
  color: #374151;
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
}

.filter-chip.is-active {
  background: #f3f4f6;
}

.inbox-tabs {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 18px;
  overflow-x: auto;
}

.inbox-tab {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 12px 16px;
  border: none;
  border-bottom: 2px solid transparent;
  background: transparent;
  color: #374151;
  font-size: 14px;
  font-weight: 600;
  white-space: nowrap;
}

.inbox-tab.is-active {
  color: #0ea5e9;
  border-bottom-color: #0ea5e9;
}

.tab-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: 999px;
  background: #e5e7eb;
  color: #111827;
  font-size: 11px;
  font-weight: 700;
}

@media (max-width: 768px) {
  .inbox-tabs {
    padding: 10px 12px;
  }
}
</style>
