<template>
  <section class="chat-page h-full">
    <ChatLayout>
      <!-- 1. Слот для сайдбару -->
      <template #sidebar>
        <ChatSidebar
          :conversations="conversations"
          :active-chat-id="activeChatId"
          @select="handleSelect"
        />
      </template>

      <!-- 2. Слот для основного вікна чату -->
      <template #thread>
        <ChatThread
          v-if="activeChatId"
          :active-chat="activeChat"
          :messages="messages"
        />
        <ChatEmptyState v-else />
      </template>
    </ChatLayout>

    <!-- Повідомлення про помилки -->
    <Transition name="toast">
      <div v-if="error" class="fixed bottom-4 right-4 bg-red-600 text-white px-5 py-3 rounded-xl shadow-2xl z-50 flex items-center gap-3">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        <span class="text-sm font-medium">{{ error }}</span>
      </div>
    </Transition>
  </section>
</template>

<script setup>
import { onMounted } from 'vue';

// Використовуємо аліас @ (зазвичай resources/js)
import ChatLayout from '@/crm/components/chat/ChatLayout.vue';
import ChatSidebar from '@/crm/components/chat/ChatSidebar.vue';
import ChatThread from '@/crm/components/chat/ChatThread.vue';
import ChatEmptyState from '@/crm/components/chat/ChatEmptyState.vue';
import { useChat } from '@/crm/composables/useChat';

const {
  conversations,
  activeChatId,
  activeChat,
  messages,
  error,
  fetchConversations,
  selectChat,
} = useChat();

function handleSelect(chat) {
  selectChat(chat.customer_id);
}

onMounted(() => {
  fetchConversations();
});

</script>

<style scoped>
.toast-enter-active, .toast-leave-active {
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.toast-enter-from, .toast-leave-to {
  opacity: 0;
  transform: translateY(20px) scale(0.95);
}
</style>
