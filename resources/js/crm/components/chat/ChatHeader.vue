<template>
  <div class="chat-thread-header">
    <div class="header-info">
      <div class="chat-thread-title">
        <h2 class="name">{{ title || 'Оберіть клієнта' }}</h2>
        <div v-if="activeChat" class="platform-indicator" :class="activeChat.platform">
          <i :class="activeChat.platform === 'instagram' ? 'bi bi-instagram' : 'bi bi-messenger'"></i>
          <span>{{ displayPlatformName }}</span>
        </div>
      </div>
      <span v-if="subtitle" class="chat-thread-subtitle">
        <i class="bi bi-clock-history me-1"></i>
        Активність: {{ subtitle }}
      </span>
    </div>

    <div class="header-actions">
      <button
        v-if="activeChat"
        class="action-btn sync-btn"
        :class="{ 'is-syncing': isSyncing }"
        :disabled="isSyncing"
        aria-label="Синхронізувати з Meta"
        title="Синхронізувати історію з Meta"
        @click="$emit('sync')"
      >
        <i class="bi bi-arrow-clockwise"></i>
      </button>

      <button 
        v-if="activeChat" 
        class="action-btn info-btn" 
        aria-label="Деталі профілю"
        title="Деталі"
      >
        <i class="bi bi-info-circle"></i>
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
  activeChat: { type: Object, default: null },
  isSyncing: { type: Boolean, default: false }
});

defineEmits(['sync']);

const displayPlatformName = computed(() => {
  if (!props.activeChat?.platform) return '';
  const p = props.activeChat.platform.toLowerCase();
  return p.charAt(0).toUpperCase() + p.slice(1);
});
</script>

<style scoped>
.chat-thread-header {
  padding: 14px 24px;
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 72px;
  flex-shrink: 0;
  z-index: 10;
}

.header-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  overflow: hidden;
}

.chat-thread-title {
  display: flex;
  align-items: center;
  gap: 10px;
  overflow: hidden;
}

.chat-thread-title .name {
  font-size: 1.1rem;
  font-weight: 700;
  color: #1e293b;
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.platform-indicator {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px;
  border-radius: 8px;
  font-size: 0.75rem;
  font-weight: 600;
  flex-shrink: 0;
}

.platform-indicator.instagram {
  background: #fdf2f8;
  color: #db2777;
}

.platform-indicator.messenger {
  background: #eff6ff;
  color: #2563eb;
}

.chat-thread-subtitle {
  font-size: 0.8rem;
  color: #94a3b8;
  display: flex;
  align-items: center;
}

.header-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.action-btn {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #64748b;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  cursor: pointer;
  outline: none;
}

.action-btn:hover:not(:disabled) {
  background: #f8fafc;
  color: #3b82f6;
  border-color: #3b82f6;
  transform: translateY(-1px);
}

.action-btn:active:not(:disabled) {
  transform: translateY(0);
}

.action-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
  background: #f1f5f9;
}

.sync-btn.is-syncing i {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.me-1 { margin-right: 4px; }
</style>