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

// --- Логіка Скролу ---
const messagesContainer = ref(null);
const scrollAnchor = ref(null);

function scrollToBottom(smooth = true) {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTo({
        top: messagesContainer.value.scrollHeight,
        behavior: smooth ? 'smooth' : 'auto',
      });
    } else if (scrollAnchor.value) {
      scrollAnchor.value.scrollIntoView({
        behavior: smooth ? 'smooth' : 'auto',
        block: 'end',
      });
    }
  });
}

// Скролимо вниз, коли змінюється список повідомлень
watch(() => props.messages, (newVal, oldVal) => {
  // Якщо це перше завантаження чату - миттєво вниз
  const isFirstLoad = !oldVal || oldVal.length === 0;
  scrollToBottom(!isFirstLoad);
}, { deep: true });

// Скролимо вниз, коли змінюється чат
watch(() => props.activeChat, () => {
  scrollToBottom(false); // Миттєво, без анімації
});

onMounted(() => {
  scrollToBottom(false);
});

// --- Логіка Лайтбоксу (Zoom) ---
const lightboxImage = ref(null);

function openLightbox(url) {
  lightboxImage.value = url;
}

function closeLightbox() {
  lightboxImage.value = null;
}
</script>

<style scoped>
.chat-thread-inner {
  display: flex;
  flex-direction: column;
  height: 100%;
  position: relative; /* Для лайтбоксу */
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

.btn-sync:hover {
  background: #f1f5f9;
  color: #3b82f6;
  border-color: #cbd5e1;
}

.btn-sync:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  100% {
    transform: rotate(360deg);
  }
}

.chat-thread-body {
  flex: 1;
  padding: 20px;
  overflow-y: auto;
  background-color: #f8fafc; /* Легкий фон для тіла чату */
  display: flex;
  flex-direction: column;
}

.chat-messages {
  display: flex;
  flex-direction: column;
  gap: 12px;
  /* flex: 1; */
  justify-content: flex-end; /* Повідомлення притискаються до низу, якщо їх мало */
  min-height: 100%;
}

/* --- Стилізація Скролбару --- */
.custom-scrollbar::-webkit-scrollbar {
  width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: #cbd5e1;
  border-radius: 10px;
}

/* --- АНІМАЦІЇ ПОВІДОМЛЕНЬ (Vue Transitions) --- */
.message-list-enter-active,
.message-list-leave-active {
  transition: all 0.4s ease;
}

.message-list-enter-from {
  opacity: 0;
  transform: translateY(20px); /* Виїжджає знизу */
}

.message-list-leave-to {
  opacity: 0;
  transform: scale(0.9);
}

/* --- ЛАЙТБОКС (ZOOM) --- */
.lightbox-overlay {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.85);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
  backdrop-filter: blur(5px); /* Блюр фону */
  cursor: zoom-out;
}

.lightbox-img {
  max-width: 90%;
  max-height: 90%;
  border-radius: 8px;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
  cursor: default;
}

.lightbox-close {
  position: absolute;
  top: 20px;
  right: 20px;
  background: rgba(255, 255, 255, 0.2);
  border: none;
  color: #fff;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background 0.2s;
}

.lightbox-close:hover {
  background: rgba(255, 255, 255, 0.4);
}

/* Анімація лайтбоксу */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
