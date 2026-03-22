import { computed, ref } from 'vue';
import {
  archiveConversation as apiArchiveConversation,
  clearConversationHistory as apiClearConversationHistory,
  fetchNewMessages,
  forceSync as apiForceSync,
  getConversations,
  getConversationByCustomer,
  getMessages,
  markRead as apiMarkRead,
  refreshCustomerProfile as apiRefreshCustomerProfile,
  sendMessage as apiSendMessage,
  updateConversationStage as apiUpdateConversationStage,
} from '@/crm/services/chatApi';

function buildConversationKey(chat) {
  if (!chat) {
    return '';
  }

  return `${chat.conversation_id ?? 'x'}:${chat.platform ?? 'unknown'}`;
}

function normalizeCollection(payload) {
  return Array.isArray(payload?.data) ? payload.data : (Array.isArray(payload) ? payload : []);
}

export function useChat() {
  const conversations = ref([]);
  const activeConversationId = ref(null);
  const messages = ref([]);
  const isLoading = ref(false);
  const isLoadingMore = ref(false);
  const isSending = ref(false);
  const isSyncing = ref(false);
  const isArchiving = ref(false);
  const isClearingHistory = ref(false);
  const syncNotice = ref(null);
  const error = ref('');
  const currentPage = ref(1);
  const lastPage = ref(1);

  let pollingTimer = null;
  let syncNoticeTimer = null;

  const activeChat = computed(() =>
    conversations.value.find((chat) => chat.conversation_id === activeConversationId.value) || null
  );

  function mergeConversationList(items, append = false) {
    const nextMap = new Map();

    if (append) {
      conversations.value.forEach((chat) => {
        nextMap.set(buildConversationKey(chat), chat);
      });
    }

    items.forEach((chat) => {
      nextMap.set(buildConversationKey(chat), chat);
    });

    conversations.value = Array.from(nextMap.values());
  }

  function patchConversation(conversationId, updater) {
    conversations.value = conversations.value.map((chat) => (
      chat.conversation_id === conversationId ? updater(chat) : chat
    ));
  }

  function patchConversationSnapshot(snapshot) {
    if (!snapshot?.conversation_id) {
      return;
    }

    patchConversation(snapshot.conversation_id, (chat) => ({
      ...chat,
      ...snapshot,
    }));
  }

  function moveConversationToTop(conversationId) {
    const index = conversations.value.findIndex((chat) => chat.conversation_id === conversationId);
    if (index <= 0) {
      return;
    }

    const [chat] = conversations.value.splice(index, 1);
    conversations.value.unshift(chat);
  }

  function removeConversation(conversationId) {
    conversations.value = conversations.value.filter((chat) => chat.conversation_id !== conversationId);

    if (activeConversationId.value === conversationId) {
      activeConversationId.value = null;
      messages.value = [];
      stopPolling();
    }
  }

  async function fetchConversations(page = 1) {
    if (page === 1) {
      isLoading.value = true;
    } else {
      if (isLoadingMore.value) {
        return;
      }
      isLoadingMore.value = true;
    }

    error.value = '';

    try {
      const { data } = await getConversations(page);
      const items = normalizeCollection(data);

      mergeConversationList(items, page !== 1);

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
    if (currentPage.value >= lastPage.value) {
      return;
    }

    fetchConversations(currentPage.value + 1);
  }

  async function selectChat(chat) {
    if (!chat?.conversation_id || !chat?.customer_id) {
      return;
    }

    if (activeConversationId.value === chat.conversation_id) {
      return;
    }

    stopPolling();
    activeConversationId.value = chat.conversation_id;
    messages.value = [];
    error.value = '';
    isLoading.value = true;

    try {
      const { data } = await getMessages(chat.customer_id, chat.platform);
      messages.value = normalizeCollection(data);

      apiRefreshCustomerProfile(chat.customer_id, chat.platform)
        .then(({ data: responseData }) => {
          const snapshot = responseData?.data || null;
          if (!snapshot) {
            return;
          }

          patchConversationSnapshot(snapshot);
        })
        .catch((e) => {
          console.warn('Не вдалося оновити профіль чату', e);
        });

      apiMarkRead(chat.customer_id, chat.platform).catch(() => {});

      patchConversation(chat.conversation_id, (item) => ({
        ...item,
        unread_count: 0,
      }));

      startPolling(chat);
    } catch (e) {
      console.error('Не вдалося завантажити повідомлення', e);
      error.value = 'Не вдалося завантажити повідомлення';
    } finally {
      isLoading.value = false;
    }
  }

  async function sendMessage(payload) {
    if (!payload?.customer_id || !payload?.platform) {
      return;
    }

    if (
      !payload?.text &&
      (!payload?.files || !payload.files.length) &&
      (!payload?.remote_urls || !payload.remote_urls.length)
    ) {
      return;
    }

    isSending.value = true;
    error.value = '';

    const tempId = `temp-${Date.now()}`;
    const tempIds = [];
    const tempMessages = [];
    const files = payload.files || [];
    const remoteUrls = payload.remote_urls || [];
    const now = new Date().toISOString();

    const pushTempMessage = (message) => {
      tempMessages.push(message);
      tempIds.push(message.id);
    };

    if (files.length) {
      files.forEach((file, index) => {
        pushTempMessage({
          id: `${tempId}-file-${index}`,
          text: index === 0 ? payload.text || null : null,
          direction: 'outbound',
          created_at: now,
          attachments: [{
            type: file.type?.startsWith('image/') ? 'image' : 'file',
            url: URL.createObjectURL(file),
          }],
          status: 'sending',
          is_read: false,
        });
      });
    }

    if (remoteUrls.length) {
      remoteUrls.forEach((url, index) => {
        pushTempMessage({
          id: `${tempId}-remote-${index}`,
          text: !files.length && index === 0 ? payload.text || null : null,
          direction: 'outbound',
          created_at: now,
          attachments: [{ type: 'image', url }],
          status: 'sending',
          is_read: false,
        });
      });
    }

    if (!files.length && !remoteUrls.length) {
      pushTempMessage({
        id: tempId,
        text: payload.text || null,
        direction: 'outbound',
        created_at: now,
        attachments: [],
        status: 'sending',
        is_read: false,
      });
    }

    messages.value = [...messages.value, ...tempMessages];

    try {
      const hasFiles = files.length > 0;
      let requestPayload = payload;

      if (hasFiles) {
        const formData = new FormData();
        formData.append('customer_id', payload.customer_id);
        formData.append('platform', payload.platform);
        if (payload.text) {
          formData.append('text', payload.text);
        }
        files.forEach((file) => formData.append('files[]', file));
        remoteUrls.forEach((url) => formData.append('remote_urls[]', url));
        requestPayload = formData;
      }

      const { data } = await apiSendMessage(requestPayload);
      const newMessages = normalizeCollection(data);
      patchConversationSnapshot(data?.conversation || null);

      messages.value = messages.value.map((message) => {
        const replaceIndex = tempIds.indexOf(message.id);
        if (replaceIndex === -1) {
          return message;
        }

        return newMessages[replaceIndex] || message;
      });

      if (newMessages.length < tempIds.length) {
        const staleIds = tempIds.slice(newMessages.length);
        messages.value = messages.value.filter((message) => !staleIds.includes(message.id));
      }

      patchConversation(payload.conversation_id, (chat) => ({
        ...chat,
        last_message: newMessages[0]?.text || payload.text || 'Вкладення',
        last_message_time: newMessages[0]?.created_at || now,
        unread_count: 0,
      }));

      moveConversationToTop(payload.conversation_id);
    } catch (e) {
      console.error('Не вдалося відправити повідомлення', e);
      error.value = 'Не вдалося відправити повідомлення';
      messages.value = messages.value.filter((message) => !tempIds.includes(message.id));
    } finally {
      isSending.value = false;
    }
  }

  async function archiveConversation(conversationId) {
    if (!conversationId || isArchiving.value) {
      return;
    }

    isArchiving.value = true;
    error.value = '';

    try {
      await apiArchiveConversation(conversationId);
      removeConversation(conversationId);
    } catch (e) {
      console.error('Не вдалося прибрати чат з інбоксу', e);
      error.value = 'Не вдалося прибрати чат з інбоксу';
      throw e;
    } finally {
      isArchiving.value = false;
    }
  }

  async function clearConversationHistory(conversationId) {
    if (!conversationId || isClearingHistory.value) {
      return;
    }

    isClearingHistory.value = true;
    error.value = '';

    try {
      const { data } = await apiClearConversationHistory(conversationId);
      messages.value = [];
      patchConversationSnapshot(data?.conversation || null);
      patchConversation(conversationId, (chat) => ({
        ...chat,
        last_message: null,
        last_message_time: null,
        unread_count: 0,
      }));
    } catch (e) {
      console.error('Не вдалося очистити історію чату', e);
      error.value = 'Не вдалося очистити історію чату';
      throw e;
    } finally {
      isClearingHistory.value = false;
    }
  }

  async function forceSync(chat = activeChat.value) {
    if (!chat?.customer_id) {
      return;
    }

    isSyncing.value = true;
    syncNotice.value = null;

    try {
      const { data: syncData } = await apiForceSync(chat.customer_id, chat.platform);
      const { data } = await getMessages(chat.customer_id, chat.platform);
      messages.value = normalizeCollection(data);
      await fetchConversations(1);
      syncNotice.value = {
        type: 'success',
        text: Number(syncData?.count || 0) > 0
          ? `Історію оновлено: додано ${syncData.count} повідомлень.`
          : 'Історію оновлено, нових повідомлень не знайдено.',
      };
    } catch (e) {
      console.error('Не вдалося синхронізувати чат', e);
      error.value = 'Не вдалося синхронізувати чат';
      syncNotice.value = {
        type: 'error',
        text: 'Не вдалося оновити історію чату.',
      };
    } finally {
      isSyncing.value = false;
      if (syncNoticeTimer) {
        clearTimeout(syncNoticeTimer);
      }
      syncNoticeTimer = setTimeout(() => {
        syncNotice.value = null;
      }, 3500);
    }
  }

  function startPolling(chat) {
    stopPolling();

    const poll = async () => {
      if (!chat?.customer_id || activeConversationId.value !== chat.conversation_id) {
        return;
      }

      const lastRealMessage = [...messages.value]
        .reverse()
        .find((message) => !String(message.id).startsWith('temp-'));

      const sinceId = lastRealMessage ? lastRealMessage.id : 0;

      try {
        const data = await fetchNewMessages(chat.customer_id, sinceId, chat.platform);
        const incoming = data?.messages || [];

        incoming.forEach((message) => {
          const existsById = messages.value.some((item) => item.id === message.id);
          if (existsById) {
            return;
          }

          const tempMatch = messages.value.find((item) =>
            String(item.id).startsWith('temp-') &&
            item.direction === 'outbound' &&
            message.direction === 'outbound' &&
            item.text === message.text
          );

          if (tempMatch) {
            tempMatch.id = message.id;
            tempMatch.created_at = message.created_at;
            tempMatch.status = message.status || 'sent';
            tempMatch.is_read = message.is_read;

            if (message.attachments?.length) {
              tempMatch.attachments = message.attachments;
            }

            return;
          }

          messages.value.push(message);
        });

        if (data?.thread) {
          patchConversation(chat.conversation_id, (item) => ({
            ...item,
            last_message: data.thread.last_message_text,
            last_message_time: data.thread.last_message_at,
          }));
        }

        if (data?.conversation) {
          patchConversationSnapshot(data.conversation);
        }
      } catch (e) {
        console.warn('Polling skip:', e.message);
      }

      if (activeConversationId.value === chat.conversation_id) {
        pollingTimer = setTimeout(poll, 3000);
      }
    };

    poll();
  }

  function stopPolling() {
    if (!pollingTimer) {
      return;
    }

    clearTimeout(pollingTimer);
    pollingTimer = null;
  }

  async function updateStage(conversationId, stage) {
    if (!conversationId) {
      return;
    }

    try {
      const { data } = await apiUpdateConversationStage(conversationId, stage);
      patchConversation(conversationId, (chat) => ({
        ...chat,
        stage: data?.stage ?? stage ?? null,
      }));
    } catch (e) {
      console.error('Не вдалося оновити етап чату', e);
      error.value = 'Не вдалося оновити етап чату';
    }
  }

  async function ensureConversation(customerId, platform = null) {
    if (!customerId) {
      return null;
    }

    const existing = conversations.value.find((chat) => (
      chat.customer_id === customerId && (platform ? chat.platform === platform : true)
    ));

    if (existing) {
      return existing;
    }

    try {
      const { data } = await getConversationByCustomer(customerId, platform);
      const conversation = data?.data || null;

      if (conversation) {
        mergeConversationList([conversation], true);
        moveConversationToTop(conversation.conversation_id);
      }

      return conversation;
    } catch (e) {
      console.error('Не вдалося отримати чат клієнта', e);
      return null;
    }
  }

  return {
    conversations,
    activeConversationId,
    activeChat,
    messages,
    isLoading,
    isLoadingMore,
    isSending,
    isSyncing,
    isArchiving,
    isClearingHistory,
    syncNotice,
    error,
    currentPage,
    lastPage,
    fetchConversations,
    loadMoreConversations,
    selectChat,
    sendMessage,
    archiveConversation,
    clearConversationHistory,
    forceSync,
    startPolling,
    stopPolling,
    updateStage,
    ensureConversation,
  };
}
