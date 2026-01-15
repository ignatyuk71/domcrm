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
        title="Оновити історію з Facebook/Instagram"
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
            :is-mine="msg.direction === 'outbound'"
            @image-click="openLightbox" 
          />
        </TransitionGroup>
      </div>
      
      <div ref="scrollAnchor"></div>
    </div>

    <ChatComposer :sending="sending" @send="$emit('send', $event)" />

    <Transition name="fade">
      <div v-if="lightboxImage" class="lightbox-overlay" @click="closeLightbox">
        <button class="lightbox-close"><i class="bi bi-x-lg"></i></button>
        <img :src="lightboxImage" alt="Zoom" class="lightbox-img" @click.stop />
      </div>
    </Transition>
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
const scrollAnchor = ref(null);

function scrollToBottom(smooth = true) {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTo({
        top: messagesContainer.value.scrollHeight,
        behavior: smooth ? 'smooth' : 'auto',
      });
    }
  });
}

watch(() => props.messages, (newVal, oldVal) => {
  const isFirstLoad = !oldVal || oldVal.length === 0;
  scrollToBottom(!isFirstLoad);
}, { deep: true });

watch(() => props.activeChat, () => {
  scrollToBottom(false);
});

onMounted(() => {
  scrollToBottom(false);
});

const lightboxImage = ref(null);
function openLightbox(url) { lightboxImage.value = url; }
function closeLightbox() { lightboxImage.value = null; }
</script>

<style scoped>
.chat-thread-inner {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  position: relative;
  overflow: hidden;
  background: #fff;
  border-radius: 16px;
}

.chat-thread-header {
  padding: 16px 20px;
  border-bottom: 1px solid #e2e8f0;
  background: #fff;
  z-index: 10;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.chat-thread-title {
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-weight: 700;
  font-size: 1.1rem;
  color: #1e293b;
}

.chat-thread-subtitle {
  font-size: 0.8rem;
  color: #94a3b8;
  font-weight: 400;
}

/* --- ТІЛО ЧАТУ ТА СКРОЛ --- */
.chat-thread-body {
  flex: 1;
  min-height: 0;
  padding: 20px;
  overflow-y: scroll; /* Примусово показуємо зону скролу */
  background-color: #f8fafc;
  display: flex;
  flex-direction: column;
  
  /* Для Firefox */
  scrollbar-width: thin;
  scrollbar-color: #cbd5e1 #f1f5f9;
}

.chat-messages {
  display: flex;
  flex-direction: column;
  gap: 12px;
  justify-content: flex-end;
  min-height: 100%;
}

/* --- СТИЛІЗАЦІЯ ПОЛОСКИ (Webkit) --- */
.custom-scrollbar::-webkit-scrollbar {
  width: 8px; /* Ширина полоски */
  display: block;
}

.custom-scrollbar::-webkit-scrollbar-track {
  background: #f1f5f9; /* Колір доріжки */
  border-radius: 4px;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: #cbd5e1; /* Колір самого повзунка */
  border-radius: 20px;
  border: 2px solid #f1f5f9; /* Відступ */
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background-color: #94a3b8; /* Колір при наведенні */
}

/* Решта стилів залишається без змін */
.btn-sync {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #64748b;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-sync:hover { background: #f1f5f9; color: #3b82f6; border-color: #cbd5e1; }
.btn-sync:disabled { opacity: 0.7; cursor: not-allowed; }
.spin { animation: spin 1s linear infinite; }
@keyframes spin { 100% { transform: rotate(360deg); } }

.message-list-enter-active, .message-list-leave-active { transition: all 0.4s ease; }
.message-list-enter-from { opacity: 0; transform: translateY(20px); }
.message-list-leave-to { opacity: 0; transform: scale(0.9); }

.lightbox-overlay {
  position: absolute; top: 0; left: 0; width: 100%; height: 100%;
  background: rgba(0, 0, 0, 0.85); display: flex; align-items: center;
  justify-content: center; z-index: 100; backdrop-filter: blur(5px); cursor: zoom-out;
}
.lightbox-img { max-width: 90%; max-height: 90%; border-radius: 8px; }
.lightbox-close {
  position: absolute; top: 20px; right: 20px; background: rgba(255, 255, 255, 0.2);
  border: none; color: #fff; width: 40px; height: 40px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center; cursor: pointer;
}
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>