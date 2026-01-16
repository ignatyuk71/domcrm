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
          :has-more="hasMore"
          @select="handleSelect"
          @update:search="search = $event"
          @change-tab="activeTab = $event"
          @load-more="loadMore"
        />
      </template>
      <template #thread>
        <ChatThread
          :active-chat="activeChat"
          :messages="messages"
          :sending="isSending"
          :is-syncing="isSyncing"
          :title="activeChat?.customer_name"
          :subtitle="activeChat?.last_message_time"
          @send="handleSend"
          @sync="handleSync"
        />
      </template>
      <template #profile>
        <ChatCustomerProfile :chat="activeChat" />
      </template>
    </ChatLayout>

    <Transition name="toast">
      <div v-if="toastMessage" class="toast-notification" :class="toastType">
        {{ toastMessage }}
      </div>
    </Transition>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ChatCustomerProfile from '@/crm/components/chat/ChatCustomerProfile.vue';
import ChatLayout from '@/crm/components/chat/ChatLayout.vue';
import ChatSidebar from '@/crm/components/chat/ChatSidebar.vue';
import ChatThread from '@/crm/components/chat/ChatThread.vue';
import { useChat } from '@/crm/composables/useChat';

const search = ref('');
const activeTab = ref('all');
const toastMessage = ref('');
const toastType = ref('toast-success');
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
  hasMore,
  isSyncing,
  fetchConversations,
  loadMore,
  selectChat,
  sendMessage,
  syncHistory,
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

function handleSend(text) {
  if (!activeChat.value) return;
  sendMessage({
    customer_id: activeChat.value.customer_id,
    message: text,
  });
}

function handleSync() {
  if (!activeChat.value) return;
  syncHistory(activeChat.value.customer_id).then((result) => {
    if (!result) return;
    toastType.value = result.success ? 'toast-success' : 'toast-error';
    toastMessage.value = result.message;
    setTimeout(() => {
      toastMessage.value = '';
    }, 3000);
  });
}

onMounted(fetchConversations);

onBeforeUnmount(() => {
  stopPolling();
});
</script>

<style></style>
