import { computed, ref } from 'vue';
import {
  fetchNewMessages,
  forceSync as apiForceSync,
  getConversations,
  getConversationTags,
  getMessages,
  markRead as apiMarkRead,
  sendMessage as apiSendMessage,
  updateConversationStage as apiUpdateConversationStage,
  updateConversationTags as apiUpdateConversationTags,
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
    if (!payload?.text && (!payload?.files || !payload.files.length) && (!payload?.remote_urls || !payload.remote_urls.length)) {
      return;
    }
    
    isSending.value = true;
    error.value = '';

    const tempId = `temp-${Date.now()}`;
    const tempMessages = [];
    const tempIds = [];
    const files = payload.files || [];
    const remoteUrls = payload.remote_urls || [];

    // Формуємо оптимістичні повідомлення (для відображення одразу)
    if (files.length) {
      files.forEach((file, index) => {
        const fileUrl = URL.createObjectURL(file);
        const optimisticMessage = {
          id: `${tempId}-${index}`,
          text: index === 0 ? payload.text || null : null,
          direction: 'outbound',
          created_at: new Date().toISOString(),
          attachments: [{ type: file.type?.startsWith('image/') ? 'image' : 'file', url: fileUrl }],
          status: 'sending',
          is_read: true,
        };
        tempMessages.push(optimisticMessage);
        tempIds.push(optimisticMessage.id);
      });
    } else {
      // Тільки текст
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

    if (remoteUrls.length) {
      remoteUrls.forEach((url, index) => {
        const optimisticMessage = {
          id: `${tempId}-remote-${index}`,
          text: files.length === 0 && index === 0 ? payload.text || null : null,
          direction: 'outbound',
          created_at: new Date().toISOString(),
          attachments: [{ type: 'image', url }],
          status: 'sending',
          is_read: true,
        };
        tempMessages.push(optimisticMessage);
        tempIds.push(optimisticMessage.id);
      });
    }

    messages.value = [...messages.value, ...tempMessages];

    try {
      const formData = new FormData();
      formData.append('customer_id', payload.customer_id);
      if (payload.text) formData.append('text', payload.text);
      if (files.length) files.forEach((file) => formData.append('files[]', file));
      if (remoteUrls.length) remoteUrls.forEach((url) => formData.append('remote_urls[]', url));

      const { data } = await apiSendMessage(formData);
      const responseData = data?.data || data;
      const newMessages = Array.isArray(responseData) ? responseData : [responseData];

      // Оновлюємо тимчасові повідомлення реальними даними з відповіді API
      messages.value = messages.value.map((msg) => {
        const replaceIndex = tempIds.indexOf(msg.id);
        if (replaceIndex === -1) return msg;
        return newMessages[replaceIndex] || msg;
      });

      // Видаляємо зайві тимчасові, якщо їх було більше ніж повернув сервер
      if (newMessages.length < tempIds.length) {
        const staleIds = tempIds.slice(newMessages.length);
        messages.value = messages.value.filter((msg) => !staleIds.includes(msg.id));
      }

      // Оновлюємо сайдбар
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
      const { data } = await getMessages(customerId);
      messages.value = data?.data || data || [];
      await fetchConversations();
    } catch (e) {
      console.error('Не вдалося синхронізувати чат', e);
      error.value = 'Не вдалося синхронізувати чат';
    } finally {
      isSyncing.value = false;
    }
  }

  // --- 🔥 ОНОВЛЕНА ЛОГІКА POLLING (Фікс дублікатів) ---
  function startPolling(threadId) {
    stopPolling();

    const poll = async () => {
      // Якщо користувач пішов з чату - виходимо
      if (activeChatId.value !== threadId) return;

      // 1. Беремо ID останнього РЕАЛЬНОГО повідомлення (ігноруємо temp-...)
      // Це важливо, щоб запит не "застрягав" на тимчасових ID
      const lastRealMessage = [...messages.value].reverse().find(m => !String(m.id).startsWith('temp-'));
      const sinceId = lastRealMessage ? lastRealMessage.id : 0;

      try {
        const data = await fetchNewMessages(threadId, sinceId);
        const incoming = data?.messages || [];

        if (incoming.length) {
          incoming.forEach((msg) => {
            // А. Якщо повідомлення з таким ID вже є - пропускаємо
            const existsById = messages.value.find((m) => m.id === msg.id);
            if (existsById) return;

            // Б. 🔥 ШУКАЄМО ТИМЧАСОВОГО ДВІЙНИКА
            // Якщо сервер надіслав повідомлення, яке ми щойно відправили (але воно ще висить як temp-)
            const tempMatch = messages.value.find((m) => 
                String(m.id).startsWith('temp-') &&       
                m.direction === 'outbound' &&             
                msg.direction === 'outbound' &&
                (m.text === msg.text) // Звіряємо текст
            );

            if (tempMatch) {
                // Знайшли! Оновлюємо тимчасове повідомлення на реальне (MERGE)
                tempMatch.id = msg.id;
                tempMatch.created_at = msg.created_at;
                tempMatch.status = 'sent';
                
                // Якщо прийшли реальні посилання на файли
                if (msg.attachments && msg.attachments.length) {
                    tempMatch.attachments = msg.attachments;
                }
            } else {
                // Це чуже повідомлення або нове - просто додаємо
                messages.value.push(msg);
            }
          });
        }

        if (data?.thread) {
          updateThreadInSidebar(data.thread);
        }
      } catch (e) {
        console.warn('Polling skip:', e.message);
      }

      // Наступний запит через 3 секунди
      if (activeChatId.value === threadId) {
        pollingTimer = setTimeout(poll, 3000);
      }
    };

    poll();
  }

  function stopPolling() {
    if (pollingTimer) {
      clearTimeout(pollingTimer);
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

  async function updateStage(conversationId, stage) {
    if (!conversationId) return;
    try {
      const { data } = await apiUpdateConversationStage(conversationId, stage);
      conversations.value = conversations.value.map((chat) =>
        chat.conversation_id === conversationId
          ? { ...chat, stage: data?.stage ?? stage ?? null }
          : chat
      );
    } catch (e) {
      console.error('Не вдалося оновити етап чату', e);
      error.value = 'Не вдалося оновити етап чату';
    }
  }

  async function updateTags(conversationId, tagIds, optimisticTags = null) {
    if (!conversationId) return;
    if (optimisticTags) {
      conversations.value = conversations.value.map((chat) =>
        chat.conversation_id === conversationId
          ? { ...chat, tags: optimisticTags }
          : chat
      );
    }
    try {
      const { data } = await apiUpdateConversationTags(conversationId, tagIds);
      const tags = data?.data || [];
      conversations.value = conversations.value.map((chat) =>
        chat.conversation_id === conversationId
          ? { ...chat, tags }
          : chat
      );
    } catch (e) {
      console.error('Не вдалося оновити теги чату', e);
      error.value = 'Не вдалося оновити теги чату';
    }
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
    getConversationTags,
    updateStage,
    updateTags,
  };
}
