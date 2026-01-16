import { computed, ref } from 'vue';
import axios from 'axios';
import { getConversations, getMessages, sendMessage as apiSendMessage } from '@/crm/services/chatApi';

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
    activeChatId.value = customerId;
    messages.value = [];
    error.value = '';
    try {
      const { data } = await getMessages(customerId);
      messages.value = data?.data || data || [];
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
  };
}
