import { computed, ref } from 'vue';
import {
  fetchNewMessages,
  getConversations,
  getMessages,
  markRead as apiMarkRead,
  sendMessage as apiSendMessage,
} from '@/crm/services/chatApi';

export function useChat() {
  const conversations = ref([]);
  const activeChatId = ref(null);
  const messages = ref([]);
  const isLoading = ref(false);
  const isSending = ref(false);
  const error = ref('');
  let pollingIntervalId = null;

  const activeChat = computed(() =>
    conversations.value.find((chat) => chat.customer_id === activeChatId.value) || null
  );

  async function fetchConversations() {
    isLoading.value = true;
    error.value = '';
    try {
      const { data } = await getConversations();
      if (window.__CHAT_DEBUG) {
        console.info('[chat] list response', data);
      }
      conversations.value = data?.data || data || [];
      if (window.__CHAT_DEBUG) {
        console.info('[chat] list items', conversations.value.length);
      }
    } catch (e) {
      console.error('Не вдалося завантажити список чатів', e);
      error.value = 'Не вдалося завантажити список чатів';
    } finally {
      isLoading.value = false;
    }
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
      if (window.__CHAT_DEBUG) {
        console.info('[chat] messages response', customerId, data);
      }
      messages.value = data?.data || data || [];
      if (window.__CHAT_DEBUG) {
        console.info('[chat] messages items', messages.value.length);
      }
      apiMarkRead(customerId).catch(() => {});
      conversations.value = conversations.value.map((chat) =>
        chat.customer_id === customerId ? { ...chat, unread_count: 0 } : chat
      );
      startPolling(customerId);
    } catch (e) {
      console.error('Не вдалося завантажити повідомлення', e);
      error.value = 'Не вдалося завантажити повідомлення';
    }
  }

  async function sendMessage(payload) {
    if (!payload?.customer_id) return;
    if (!payload?.text && (!payload?.attachments || !payload.attachments.length)) return;
    isSending.value = true;
    error.value = '';

    const tempId = `temp-${Date.now()}`;
    const optimisticMessage = {
      id: tempId,
      text: payload.text || null,
      direction: 'outbound',
      created_at: new Date().toISOString(),
      attachments: payload.attachments || [],
      status: 'sending',
      is_read: true,
    };

    messages.value = [...messages.value, optimisticMessage];

    try {
      const { data } = await apiSendMessage(payload);
      if (window.__CHAT_DEBUG) {
        console.info('[chat] send response', data);
      }
      const newMessage = data?.data || data;
      messages.value = messages.value.map((msg) => (msg.id === tempId ? newMessage : msg));

      conversations.value = conversations.value.map((chat) =>
        chat.customer_id === payload.customer_id
          ? {
              ...chat,
              last_message: newMessage.text || payload.text || 'Вкладення',
              last_message_time: newMessage.created_at || new Date().toISOString(),
            }
          : chat
      );
    } catch (e) {
      console.error('Не вдалося відправити повідомлення', e);
      error.value = 'Не вдалося відправити повідомлення';
    } finally {
      isSending.value = false;
    }
  }

  function startPolling(threadId) {
    stopPolling();

    pollingIntervalId = setInterval(async () => {
      if (!activeChatId.value || activeChatId.value !== threadId) return;
      if (!messages.value.length) return;

      const lastMessage = messages.value[messages.value.length - 1];
      if (!lastMessage?.id || String(lastMessage.id).startsWith('temp-')) return;

      try {
        const data = await fetchNewMessages(threadId, lastMessage.id);
        const incoming = data?.messages || [];

        if (incoming.length) {
          incoming.forEach((msg) => {
            if (!messages.value.find((existing) => existing.id === msg.id)) {
              messages.value.push(msg);
            }
          });
        }

        if (data?.thread) {
          updateThreadInSidebar(data.thread);
        }
      } catch (e) {
        console.error('Chat polling error', e);
      }
    }, 5000);
  }

  function stopPolling() {
    if (pollingIntervalId) {
      clearInterval(pollingIntervalId);
      pollingIntervalId = null;
    }
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
    fetchConversations,
    selectChat,
    sendMessage,
    startPolling,
    stopPolling,
  };
}
