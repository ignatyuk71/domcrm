<script setup>
const props = defineProps({
  show: { type: Boolean, default: false },
  title: { type: String, default: 'Перевірте форму' },
  messages: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);
</script>

<template>
  <Teleport to="body">
    <transition name="toast-slide">
      <div v-if="show && messages.length" class="app-toast" role="alert">
        <div class="app-toast-head">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span class="app-toast-title">{{ title }}</span>
          <button type="button" class="app-toast-close" aria-label="Закрити" @click="emit('close')">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>
        <ul class="app-toast-list">
          <li v-for="(m, i) in messages" :key="i">{{ m }}</li>
        </ul>
      </div>
    </transition>
  </Teleport>
</template>

<style scoped>
.app-toast {
  position: fixed;
  top: 16px;
  right: 16px;
  z-index: 2000;
  width: 340px;
  max-width: calc(100vw - 32px);
  background: #fff;
  border: 1px solid #fecaca;
  border-left: 4px solid #ef4444;
  border-radius: 10px;
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.15);
  overflow: hidden;
  font-family: 'Inter', sans-serif;
}
.app-toast-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  background: #fef2f2;
  color: #b91c1c;
  font-size: 0.85rem;
}
.app-toast-title { font-weight: 700; }
.app-toast-close {
  margin-left: auto;
  border: none;
  background: transparent;
  color: #b91c1c;
  cursor: pointer;
  font-size: 0.8rem;
  padding: 2px 4px;
  line-height: 1;
}
.app-toast-close:hover { opacity: 0.7; }
.app-toast-list {
  margin: 0;
  padding: 10px 14px 12px 28px;
  font-size: 0.82rem;
  color: #334155;
}
.app-toast-list li { margin-bottom: 4px; }
.app-toast-list li:last-child { margin-bottom: 0; }

.toast-slide-enter-active, .toast-slide-leave-active { transition: all 0.25s ease; }
.toast-slide-enter-from, .toast-slide-leave-to { opacity: 0; transform: translateX(20px); }
</style>
