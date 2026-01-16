import { computed, ref } from 'vue';
import {
  fetchNewMessages,
  forceSync as apiForceSync,
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
  const isSyncing = ref(false);
  const error = ref('');
  
  // Змінили назву змінної, бо це тепер таймер, а не інтервал
  let pollingTimer = null;

  const activeChat = computed(() =>
    conversations.value.find((chat) => chat.customer_id === activeChatId.value) || null
  );

  async function fetchConversations() {
    isLoading.value = true;
    error.value = '';
    try {
      const { data } = await getConversations();
      conversations.value = data?.data || data || [];
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
    
    // Вмикаємо лоадер, щоб користувач бачив процес
    isLoading.value = true;

    try {
      const { data } = await getMessages(customerId);
      messages.value = data?.data || data || [];
      
      apiMarkRead(customerId).catch(() => {});
      conversations.value = conversations.value.map((chat) =>
        chat.customer_id === customerId ? { ...chat, unread_count: 0 } : chat
      );
      
      startPolling(customerId);
    } catch (e) {
      console.error('Не вдалося завантажити повідомлення', e);
      error.value = 'Не вдалося завантажити повідомлення';
    } finally {
      isLoading.value = false;
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

  async function forceSync(customerId) {
    if (!customerId) return;
    isSyncing.value = true;
    try {
      await apiForceSync(customerId);
      // Після синхронізації оновлюємо список повідомлень
      const { data } = await getMessages(customerId);
      messages.value = data?.data || data || [];
      // Також оновлюємо список чатів (щоб змінився останній меседж)
      await fetchConversations();
    } catch (e) {
      console.error('Не вдалося синхронізувати чат', e);
      error.value = 'Не вдалося синхронізувати чат';
    } finally {
      isSyncing.value = false;
    }
  }

  // --- ОНОВЛЕНА ЛОГІКА POLLING (3 секунди + захист від дублів) ---
  function startPolling(threadId) {
    stopPolling();

    const poll = async () => {
      // Якщо користувач пішов з чату поки йшов таймер - виходимо
      if (activeChatId.value !== threadId) return;

      const lastMessage = messages.value[messages.value.length - 1];
      
      // Робимо запит тільки якщо є повідомлення і останнє не є "тимчасовим"
      if (lastMessage?.id && !String(lastMessage.id).startsWith('temp-')) {
        try {
          const data = await fetchNewMessages(threadId, lastMessage.id);
          const incoming = data?.messages || [];

          if (incoming.length) {
            incoming.forEach((msg) => {
              // Уникаємо дублікатів
              if (!messages.value.find((existing) => existing.id === msg.id)) {
                messages.value.push(msg);
              }
            });
          }

          if (data?.thread) {
            updateThreadInSidebar(data.thread);
          }
        } catch (e) {
          // Тиха помилка (наприклад, немає інтернету), не блокуємо роботу
          console.warn('Polling skip:', e.message);
        }
      }

      // Плануємо наступний запит тільки ПІСЛЯ завершення поточного
      // Інтервал: 3000 мс (3 секунди)
      if (activeChatId.value === threadId) {
        pollingTimer = setTimeout(poll, 5000);
      }
    };

    // Запускаємо
    poll();
  }

  function stopPolling() {
    if (pollingTimer) {
      clearTimeout(pollingTimer); // Використовуємо clearTimeout
      pollingTimer = null;
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
    isSyncing,
    error,
    fetchConversations,
    selectChat,
    sendMessage,
    forceSync,
    startPolling,
    stopPolling,
  };
}
