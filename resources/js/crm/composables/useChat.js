import { computed, ref } from 'vue';
import { getConversations, getMessages } from '@/crm/services/chatApi';

export function useChat() {
  const conversations = ref([]);
  const activeChatId = ref(null);
  const messages = ref([]);
  const isLoading = ref(false);
  const error = ref('');

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

  return {
    conversations,
    activeChatId,
    activeChat,
    messages,
    isLoading,
    error,
    fetchConversations,
    selectChat,
  };
}
