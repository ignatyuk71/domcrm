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
  const isLoadingMore = ref(false);
  const isSending = ref(false);
  const isSyncing = ref(false);
  const error = ref('');
  const currentPage = ref(1);
  const lastPage = ref(1);
  
  // Змінили назву змінної, бо це тепер таймер, а не інтервал
  let pollingTimer = null;

  const activeChat = computed(() =>
    conversations.value.find((chat) => chat.customer_id === activeChatId.value) || null
  );

  async function fetchConversations(page = 1) {
    if (page === 1) {
      isLoading.value = true;
    } else {
      if (isLoadingMore.value) return;
      isLoadingMore.value = true;
    }
    error.value = '';
    try {
      const { data } = await getConversations(page);
      const items = data?.data || data || [];

      if (page === 1) {
        conversations.value = items;
      } else {
        conversations.value = [...conversations.value, ...items];
      }

      currentPage.value = data?.current_page || page;
      lastPage.value = data?.last_page || page;
    } catch (e) {
      console.error('Не вдалося завантажити список чатів', e);
      error.value = 'Не вдалося завантажити список чатів';
    } finally {
      isLoading.value = false;
      isLoadingMore.value = false;
    }
  }

  function loadMoreConversations() {
    if (currentPage.value >= lastPage.value) return;
    fetchConversations(currentPage.value + 1);
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
    if (!payload?.text && (!payload?.files || !payload.files.length)) return;
    
    isSending.value = true;
    error.value = '';

    const tempId = `temp-${Date.now()}`;
    const tempMessages = [];
    const tempIds = [];
    const files = payload.files || [];

    if (files.length) {
      files.forEach((file, index) => {
        const fileUrl = URL.createObjectURL(file);
        const optimisticMessage = {
          id: `${tempId}-${index}`,
          text: index === 0 ? payload.text || null : null,
          direction: 'outbound',
          created_at: new Date().toISOString(),
          attachments: [
            {
              type: file.type?.startsWith('image/') ? 'image' : 'file',
              url: fileUrl,
            },
          ],
          status: 'sending',
          is_read: true,
        };
        tempMessages.push(optimisticMessage);
        tempIds.push(optimisticMessage.id);
      });
    } else {
      tempMessages.push({
        id: tempId,
        text: payload.text || null,
        direction: 'outbound',
        created_at: new Date().toISOString(),
        attachments: [],
        status: 'sending',
        is_read: true,
      });
      tempIds.push(tempId);
    }

    messages.value = [...messages.value, ...tempMessages];

    try {
      const formData = new FormData();
      formData.append('customer_id', payload.customer_id);
      if (payload.text) {
        formData.append('text', payload.text);
      }
      if (files.length) {
        files.forEach((file) => {
          formData.append('files[]', file);
        });
      }

      const { data } = await apiSendMessage(formData);
      const responseData = data?.data || data;
      const newMessages = Array.isArray(responseData) ? responseData : [responseData];

      messages.value = messages.value.map((msg) => {
        const replaceIndex = tempIds.indexOf(msg.id);
        if (replaceIndex === -1) return msg;
        return newMessages[replaceIndex] || msg;
      });

      conversations.value = conversations.value.map((chat) =>
        chat.customer_id === payload.customer_id
          ? {
              ...chat,
              last_message: newMessages[0]?.text || payload.text || 'Вкладення',
              last_message_time: newMessages[0]?.created_at || new Date().toISOString(),
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
    isLoadingMore,
    isSending,
    isSyncing,
    error,
    currentPage,
    lastPage,
    fetchConversations,
    loadMoreConversations,
    selectChat,
    sendMessage,
    forceSync,
    startPolling,
    stopPolling,
  };
}
