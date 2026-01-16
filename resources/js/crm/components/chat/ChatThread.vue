<template>
  <div class="chat-thread-inner">
    <div class="chat-thread-header">
      <div class="chat-thread-title">
        <span>{{ title || 'Оберіть клієнта' }}</span>
        <span v-if="subtitle" class="chat-thread-subtitle">{{ subtitle }}</span>
      </div>

      <button
        v-if="title"
        class="btn-sync"
        @click="$emit('sync')"
        :disabled="sending || isSyncing"
      >
        <i class="bi" :class="isSyncing ? 'bi-arrow-repeat spin' : 'bi-cloud-download'"></i>
      </button>
    </div>

    <div class="chat-thread-body custom-scrollbar" ref="messagesContainer">
      <div class="chat-messages">
        <TransitionGroup name="message-list">
          <ChatMessage
            v-for="msg in messages"
            :key="msg.id || msg.temp_id"
            :message="msg"
            :is-mine="msg.direction === 'outbound' || !msg.is_from_customer"
          />
        </TransitionGroup>
      </div>
      <div ref="scrollAnchor"></div>
    </div>

    <ChatComposer :sending="sending" @send="$emit('send', $event)" />
  </div>
</template>

<script setup>
import { ref, watch, nextTick, onMounted } from 'vue';
import ChatMessage from './ChatMessage.vue';
import ChatComposer from './ChatComposer.vue';

const props = defineProps({
  activeChat: { type: Object, default: null },
  messages: { type: Array, default: () => [] },
  sending: { type: Boolean, default: false },
  isSyncing: { type: Boolean, default: false },
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
});

defineEmits(['send', 'sync']);

const messagesContainer = ref(null);

function scrollToBottom(smooth = true) {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
  });
}

watch(() => props.messages, () => scrollToBottom(true), { deep: true });
watch(() => props.activeChat, () => scrollToBottom(false));
onMounted(() => scrollToBottom(false));
</script>

<style scoped>
/* Головний контейнер має фіксовану висоту, щоб внутрішній блок скролився */
.chat-thread-inner {
  display: flex;
  flex-direction: column;
  height: 100%; 
  min-height: 0;
  background: #fff;
  overflow: hidden;
}

.chat-thread-body {
  flex: 1;
  min-height: 0;
  padding: 20px;
  /* overflow-y: scroll - ПОЛОСКА БУДЕ ЗАВЖДИ */
  overflow-y: scroll; 
  background-color: #f8fafc;
  display: flex;
  flex-direction: column;
}

.chat-messages {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-height: 100%;
}

/* СТИЛІЗАЦІЯ ПОЛОСКИ (SCRОLLBAR) */
.custom-scrollbar::-webkit-scrollbar {
  width: 10px; /* Ширина полоски */
  display: block !important;
}

.custom-scrollbar::-webkit-scrollbar-track {
  background: #f1f5f9; /* Колір доріжки */
}

.custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: #cbd5e1; /* Колір повзунка */
  border-radius: 5px;
  border: 2px solid #f1f5f9;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background-color: #94a3b8;
}

/* Допоміжні стилі */
.chat-thread-header { padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
.chat-thread-title { display: flex; flex-direction: column; font-weight: 700; }
.chat-thread-subtitle { font-size: 0.8rem; color: #94a3b8; }
.btn-sync { width: 36px; height: 36px; border: 1px solid #e2e8f0; background: #fff; cursor: pointer; border-radius: 8px; }
.spin { animation: spin 1s linear infinite; }
@keyframes spin { 100% { transform: rotate(360deg); } }
</style>