import { computed, ref } from 'vue';
import axios from 'axios';
import {
  getConversations,
  getMessages,
  sendMessage as apiSendMessage,
  fetchNewMessages,
} from '@/crm/services/chatApi';

export function useChat() {
  const conversations = ref([]);
  const activeChatId = ref(null);
  const messages = ref([]);
  const isLoading = ref(false);
  const isSending = ref(false);
  const error = ref('');
  const page = ref(1);
  const hasMore = ref(false);
  const isSyncing = ref(false);
  let pollingIntervalId = null;
  let pollingErrorCount = 0;
  const pollingDelay = 3000;
  const maxPollingErrors = 5;

  const activeChat = computed(() =>
    conversations.value.find((chat) => chat.customer_id === activeChatId.value) || null
  );

  async function fetchConversations(nextPage = 1) {
    isLoading.value = true;
    error.value = '';
    try {
      const { data } = await getConversations(nextPage);
      const payload = data?.data || [];
      if (nextPage === 1) {
        conversations.value = payload;
      } else {
        conversations.value = [...conversations.value, ...payload];
      }
      page.value = data?.current_page || nextPage;
      const lastPage = data?.last_page || page.value;
      hasMore.value = page.value < lastPage;
    } catch (e) {
      console.error('Не вдалося завантажити список чатів', e);
      error.value = 'Не вдалося завантажити список чатів';
    } finally {
      isLoading.value = false;
    }
  }

  function loadMore() {
    if (!hasMore.value || isLoading.value) return;
    fetchConversations(page.value + 1);
  }

  async function selectChat(customerId) {
    if (!customerId) return;
    if (activeChatId.value === customerId) return;
    stopPolling();
    activeChatId.value = customerId;
    messages.value = [];
    error.value = '';
    try {
      const { data } = await getMessages(customerId);
      messages.value = data?.data || data || [];
      startPolling(customerId);
    } catch (e) {
      console.error('Не вдалося завантажити повідомлення', e);
      error.value = 'Не вдалося завантажити повідомлення';
    }
  }

  async function sendMessage(payload) {
    if (!payload?.customer_id || !payload?.message) return;
    isSending.value = true;
    error.value = '';
    try {
      const { data } = await apiSendMessage(payload);
      const newMessage = data?.data || data;
      if (newMessage) {
        messages.value = [...messages.value, newMessage];
        conversations.value = conversations.value.map((chat) =>
          chat.customer_id === payload.customer_id
            ? {
                ...chat,
                last_message: newMessage.text || payload.message,
                last_message_time: newMessage.created_at || new Date().toISOString(),
              }
            : chat
        );
      }
    } catch (e) {
      console.error('Не вдалося відправити повідомлення', e);
      error.value = 'Не вдалося відправити повідомлення';
    } finally {
      isSending.value = false;
    }
  }

  async function syncHistory(customerId) {
    if (!customerId) return;
    isSyncing.value = true;
    try {
      const { data } = await axios.post(`/api/chat/${customerId}/sync`);
      await selectChat(customerId);
      await fetchConversations();
      return {
        success: true,
        message: data?.message || 'Синхронізація виконана',
      };
    } catch (e) {
      console.error('Помилка синхронізації:', e);
      return {
        success: false,
        message:
          e.response?.data?.message ||
          e.response?.data?.error ||
          'Не вдалося синхронізувати. Перевірте, чи є активний діалог.',
      };
    } finally {
      isSyncing.value = false;
    }
  }

  function startPolling(threadId) {
    stopPolling();

    pollingIntervalId = setInterval(async () => {
      if (!activeChatId.value || activeChatId.value !== threadId) return;
      if (!messages.value.length) return;

      const lastMessage = messages.value[messages.value.length - 1];
      if (!lastMessage?.id) return;

      try {
        const { data } = await fetchNewMessages(threadId, lastMessage.id);
        pollingErrorCount = 0;

        const incoming = data?.messages || [];
        if (incoming.length) {
          incoming.forEach((msg) => {
            if (!messages.value.find((existing) => existing.id === msg.id)) {
              messages.value.push(msg);
            }
          });

          if (data?.thread) {
            updateThreadInSidebar(data.thread);
          }
        }
      } catch (e) {
        console.error('Chat polling error', e);
        pollingErrorCount += 1;
        if (pollingErrorCount >= maxPollingErrors) {
          stopPolling();
        }
      }
    }, pollingDelay);
  }

  function stopPolling() {
    if (pollingIntervalId) {
      clearInterval(pollingIntervalId);
      pollingIntervalId = null;
    }
    pollingErrorCount = 0;
  }

  function updateThreadInSidebar(updatedThread) {
    const index = conversations.value.findIndex(
      (thread) => thread.customer_id === updatedThread.id
    );
    if (index === -1) return;
    conversations.value[index] = {
      ...conversations.value[index],
      last_message: updatedThread.last_message_text,
      last_message_time: updatedThread.last_message_at,
    };
  }


  return {
    conversations,
    activeChatId,
    activeChat,
    messages,
    isLoading,
    isSending,
    error,
    page,
    hasMore,
    isSyncing,
    fetchConversations,
    loadMore,
    selectChat,
    sendMessage,
    syncHistory,
    startPolling,
    stopPolling,
  };
}
