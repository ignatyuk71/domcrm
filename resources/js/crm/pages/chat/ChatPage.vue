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
          <span v-if="getPlatformUnread(tab.value) > 0" class="tab-count is-unread">
            {{ formatUnreadCount(getPlatformUnread(tab.value)) }}
          </span>
        </button>
      </div>
    </template>

    <template #sidebar>
      <div class="chat-sidebar-shell">
        <div class="chat-sidebar-head">
          <div>
            <h1>Чати</h1>
            <p>Всі звернення з Messenger, Instagram Direct і коментарів в одному просторі.</p>
          </div>
        </div>

        <div class="chat-sidebar-search">
          <i class="bi bi-search"></i>
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Клієнт, @username, фраза..."
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
        :is-clearing-history="isClearingHistory"
        :sync-notice="syncNotice"
        :loading="isLoading"
        @send="handleSendMessage"
        @delete-conversation="handleDeleteConversation"
        @clear-history="handleClearHistory"
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
import { matchConversationByTab } from '@/crm/utils/chatOrigin';

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
  isClearingHistory,
  syncNotice,
  currentPage,
  lastPage,
  fetchConversations,
  loadMoreConversations,
  selectChat,
  sendMessage,
  archiveConversation,
  clearConversationHistory,
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

const tabStats = computed(() => {
  const stats = Object.fromEntries(
    platformTabs.map((tab) => [tab.value, { unread: 0 }])
  );

  conversations.value.forEach((chat) => {
    const unreadCount = Math.max(0, Number(chat.unread_count || 0));

    platformTabs.forEach((tab) => {
      if (!matchConversationByTab(chat, tab.value)) {
        return;
      }

      stats[tab.value].unread += unreadCount;
    });
  });

  return stats;
});

function getPlatformUnread(platform) {
  return tabStats.value[platform]?.unread || 0;
}

function formatUnreadCount(count) {
  return count > 99 ? '99+' : count;
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

async function handleClearHistory() {
  if (!activeChat.value?.conversation_id) {
    return;
  }

  const confirmed = window.confirm(
    'Очистити всю історію цього чату в CRM і скинути AI-контекст (етап, зібрані поля, підсумок)? Дію неможливо скасувати.'
  );
  if (!confirmed) {
    return;
  }

  await clearConversationHistory(activeChat.value.conversation_id);
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
  background: transparent;
}

.chat-sidebar-head {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px 4px;
}

.chat-sidebar-head h1 {
  margin: 3px 0 4px;
  font-size: 18px;
  line-height: 1;
  font-weight: 800;
  letter-spacing: -0.04em;
  color: #0f172a;
}

.chat-sidebar-head p {
  margin: 0;
  max-width: 220px;
  font-size: 11px;
  line-height: 1.35;
  color: #64748b;
}

.chat-sidebar-search {
  position: relative;
  padding: 8px 14px 8px;
}

.chat-sidebar-search i {
  position: absolute;
  top: 50%;
  left: 26px;
  transform: translateY(-50%);
  color: #94a3b8;
  font-size: 14px;
}

.chat-sidebar-search input {
  width: 100%;
  height: 38px;
  padding: 0 12px 0 34px;
  border: 1px solid rgba(148, 163, 184, 0.22);
  border-radius: 4px;
  background: #f8fafc;
  color: #0f172a;
  font-size: 13px;
  outline: none;
  transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
}

.chat-sidebar-search input:focus {
  border-color: rgba(37, 99, 235, 0.26);
  background: #ffffff;
}

.inbox-tabs {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px;
  overflow-x: auto;
  background: transparent;
  scrollbar-width: none;
}

.inbox-tabs::-webkit-scrollbar {
  display: none;
}

.inbox-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 36px;
  padding: 0 12px;
  border: 1px solid rgba(148, 163, 184, 0.18);
  background: #ffffff;
  color: #475569;
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
  border-radius: 4px;
  box-shadow: none;
  transition: border-color 0.18s ease, background 0.18s ease, color 0.18s ease;
}

.inbox-tab:hover {
  color: #0f172a;
  border-color: rgba(37, 99, 235, 0.16);
  background: #f8fbff;
}

.inbox-tab.is-active {
  color: #ffffff;
  background: #2563eb;
  border-color: transparent;
  box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.12);
}

.tab-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 999px;
  background: rgba(148, 163, 184, 0.18);
  color: #0f172a;
  font-size: 10px;
  font-weight: 700;
}

.tab-count.is-unread {
  background: rgba(254, 226, 226, 0.92);
  color: #b42318;
}

.inbox-tab.is-active .tab-count {
  background: rgba(255, 255, 255, 0.18);
  color: #ffffff;
}

@media (max-width: 768px) {
  .inbox-tabs {
    padding: 6px 6px 4px;
    gap: 6px;
  }

  .chat-sidebar-head {
    padding: 10px 10px 4px;
  }

  .chat-sidebar-head h1 {
    font-size: 18px;
  }

  .chat-sidebar-head p {
    font-size: 12px;
  }

  .chat-sidebar-search {
    padding: 6px 10px 8px;
  }

  .chat-sidebar-search i {
    left: 20px;
  }
}
</style>
