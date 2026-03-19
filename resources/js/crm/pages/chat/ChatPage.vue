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
          <span v-if="getPlatformCount(tab.value) > 0" class="tab-count">{{ getPlatformCount(tab.value) }}</span>
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
        :is-archiving="isArchiving"
        :loading="isLoading"
        @send="handleSendMessage"
        @delete-conversation="handleDeleteConversation"
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
  isArchiving,
  currentPage,
  lastPage,
  fetchConversations,
  loadMoreConversations,
  selectChat,
  sendMessage,
  archiveConversation,
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
  return conversations.value.filter((chat) => (
    matchConversationByTab(chat, platform) && Number(chat.unread_count || 0) > 0
  )).length;
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

async function handleDeleteConversation() {
  if (!activeChat.value?.conversation_id) {
    return;
  }

  const confirmed = window.confirm('Прибрати цю переписку з інбоксу?');
  if (!confirmed) {
    return;
  }

  await archiveConversation(activeChat.value.conversation_id);
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
  padding: 14px 12px 8px;
}

.chat-sidebar-search i {
  position: absolute;
  top: 50%;
  left: 24px;
  transform: translateY(-50%);
  color: #6b7280;
  font-size: 13px;
}

.chat-sidebar-search input {
  width: 100%;
  height: 40px;
  padding: 0 12px 0 34px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #fff;
  color: #0f172a;
  font-size: 14px;
  outline: none;
  transition: border-color 0.2s ease;
}

.inbox-tabs {
  display: flex;
  align-items: center;
  gap: 0;
  padding: 0 16px;
  overflow-x: auto;
  border-bottom: 1px solid #e5e7eb;
}

.inbox-tab {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 52px;
  padding: 0 18px;
  border: none;
  background: transparent;
  color: #1f2937;
  font-size: 14px;
  font-weight: 400;
  white-space: nowrap;
  border-radius: 8px 8px 0 0;
  margin-top: 6px;
  margin-right: 4px;
}

.inbox-tab.is-active {
  color: #1877f2;
  background: #e7f3ff;
}

.tab-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: 999px;
  background: #b42318;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
}

@media (max-width: 768px) {
  .inbox-tabs {
    padding: 10px 12px;
  }
}
</style>
