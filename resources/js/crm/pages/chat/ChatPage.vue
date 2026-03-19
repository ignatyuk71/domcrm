<template>
  <ChatLayout :view-mode="viewMode">
    <template #sidebar>
      <div class="chat-sidebar-shell">
        <div class="chat-sidebar-topbar">
          <div>
            <p class="sidebar-eyebrow">Meta Inbox</p>
            <h1 class="sidebar-title">Переписки</h1>
          </div>

          <span class="sidebar-total">{{ filteredConversations.length }}</span>
        </div>

        <div class="chat-sidebar-search">
          <i class="bi bi-search"></i>
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Пошук по імені або повідомленню"
          >
        </div>

        <div class="platform-tabs">
          <button
            v-for="tab in platformTabs"
            :key="tab.value"
            type="button"
            class="platform-tab"
            :class="{ 'is-active': platformFilter === tab.value }"
            @click="platformFilter = tab.value"
          >
            {{ tab.label }}
            <span class="platform-count">{{ getPlatformCount(tab.value) }}</span>
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
  { value: 'all', label: 'Усі' },
  { value: 'messenger', label: 'Messenger' },
  { value: 'instagram', label: 'Instagram' },
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
    const matchesPlatform = platformFilter.value === 'all' || chat.platform === platformFilter.value;
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
  if (platform === 'all') {
    return conversations.value.length;
  }

  return conversations.value.filter((chat) => chat.platform === platform).length;
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
  background:
    radial-gradient(circle at top left, rgba(14, 165, 233, 0.12), transparent 34%),
    linear-gradient(180deg, rgba(248, 250, 252, 0.98), #ffffff 24%);
}

.chat-sidebar-topbar {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 22px 20px 16px;
}

.sidebar-eyebrow {
  margin: 0 0 4px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: #64748b;
}

.sidebar-title {
  margin: 0;
  font-size: 28px;
  line-height: 1;
  font-weight: 800;
  color: #0f172a;
}

.sidebar-total {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 38px;
  height: 38px;
  padding: 0 12px;
  border-radius: 999px;
  background: #0f172a;
  color: #f8fafc;
  font-size: 14px;
  font-weight: 700;
}

.chat-sidebar-search {
  position: relative;
  padding: 0 20px;
}

.chat-sidebar-search i {
  position: absolute;
  top: 50%;
  left: 34px;
  transform: translateY(-50%);
  color: #94a3b8;
  font-size: 14px;
}

.chat-sidebar-search input {
  width: 100%;
  height: 44px;
  padding: 0 14px 0 40px;
  border: 1px solid rgba(148, 163, 184, 0.25);
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.92);
  color: #0f172a;
  outline: none;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.chat-sidebar-search input:focus {
  border-color: rgba(14, 165, 233, 0.5);
  box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.08);
}

.platform-tabs {
  display: flex;
  gap: 8px;
  padding: 14px 20px 18px;
  overflow-x: auto;
}

.platform-tab {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 9px 14px;
  border: 1px solid rgba(148, 163, 184, 0.22);
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.76);
  color: #334155;
  font-size: 13px;
  font-weight: 700;
  white-space: nowrap;
  transition: all 0.2s ease;
}

.platform-tab:hover {
  border-color: rgba(14, 165, 233, 0.35);
  color: #0f172a;
}

.platform-tab.is-active {
  background: linear-gradient(135deg, #0f172a, #1e293b);
  border-color: transparent;
  color: #f8fafc;
  box-shadow: 0 12px 26px -18px rgba(15, 23, 42, 0.7);
}

.platform-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: 999px;
  background: rgba(148, 163, 184, 0.16);
  font-size: 11px;
  font-weight: 800;
}

.platform-tab.is-active .platform-count {
  background: rgba(255, 255, 255, 0.18);
}

@media (max-width: 768px) {
  .chat-sidebar-topbar {
    padding-top: 18px;
  }

  .sidebar-title {
    font-size: 24px;
  }
}
</style>
