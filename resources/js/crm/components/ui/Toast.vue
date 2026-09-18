<script setup>
const props = defineProps({
  show: { type: Boolean, default: false },
  title: { type: String, default: 'Перевірте форму' },
  messages: { type: Array, default: () => [] },
  type: { type: String, default: 'error' },
  actionLabel: { type: String, default: '' },
  secondaryLabel: { type: String, default: '' },
  teleportTo: { type: [String, Object], default: 'body' },
});
const emit = defineEmits(['close', 'action', 'secondary']);
</script>

<template>
  <Teleport :to="teleportTo">
    <transition name="toast-slide">
      <div v-if="show && messages.length" class="app-toast" :class="`app-toast--${type}`" :role="type === 'error' || type === 'warning' ? 'alert' : 'status'" aria-atomic="true">
        <div class="app-toast-head">
          <i class="bi" :class="type === 'success' ? 'bi-check-circle-fill' : type === 'info' ? 'bi-info-circle-fill' : 'bi-exclamation-triangle-fill'" aria-hidden="true"></i>
          <span class="app-toast-title">{{ title }}</span>
          <button type="button" class="app-toast-close" aria-label="Закрити" @click="emit('close')">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>
        <ul class="app-toast-list">
          <li v-for="(m, i) in messages" :key="i">{{ m }}</li>
        </ul>
        <div v-if="actionLabel || secondaryLabel" class="app-toast-actions">
          <button v-if="secondaryLabel" type="button" class="btn btn-sm btn-outline-secondary" @click="emit('secondary')">{{ secondaryLabel }}</button>
          <button v-if="actionLabel" type="button" class="btn btn-sm btn-outline-primary" @click="emit('action')">{{ actionLabel }}</button>
        </div>
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
.app-toast-close { color: inherit; min-width: 28px; min-height: 28px; }
.app-toast-actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; padding: 0 12px 12px; }
.app-toast--success { border-color: #bfe5d8; border-left-color: #24816f; }
.app-toast--success .app-toast-head { background: #edf9f4; color: #216d5b; }
.app-toast--info { border-color: #ddd7fc; border-left-color: #6250df; }
.app-toast--info .app-toast-head { background: #f3f0ff; color: #5945b6; }
.app-toast--warning { border-color: #f0d8aa; border-left-color: #b57a18; }
.app-toast--warning .app-toast-head { background: #fff8e9; color: #8a5b12; }
.app-toast-list {
  margin: 0;
  padding: 10px 14px 12px 28px;
  max-height: 45vh;
  overflow: auto;
  overflow-wrap: anywhere;
  font-size: 0.82rem;
  color: #334155;
}
.app-toast-list li { margin-bottom: 4px; }
.app-toast-list li:last-child { margin-bottom: 0; }

.toast-slide-enter-active, .toast-slide-leave-active { transition: all 0.25s ease; }
.toast-slide-enter-from, .toast-slide-leave-to { opacity: 0; transform: translateX(20px); }
@media(prefers-reduced-motion:reduce){.toast-slide-enter-active,.toast-slide-leave-active{transition:none}}
</style>
