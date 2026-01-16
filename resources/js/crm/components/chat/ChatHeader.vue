<template>
  <div class="chat-thread-header">
    <div class="header-info">
      <div class="chat-thread-title">
        <span class="name">{{ title || 'Оберіть клієнта' }}</span>
        <div v-if="activeChat" class="platform-indicator" :class="activeChat.platform">
          <i :class="activeChat.platform === 'instagram' ? 'bi bi-instagram' : 'bi bi-messenger'"></i>
          <span>{{ activeChat.platform }}</span>
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
        title="Синхронізувати історію з Meta"
        @click="$emit('sync')"
      >
        <i class="bi bi-arrow-clockwise"></i>
      </button>

      <button v-if="activeChat" class="action-btn info-btn" title="Деталі">
        <i class="bi bi-info-circle"></i>
      </button>
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
  activeChat: { type: Object, default: null },
  isSyncing: { type: Boolean, default: false }
});

defineEmits(['sync']);
</script>

<style scoped>
.chat-thread-header {
  padding: 16px 24px;
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 72px;
}

.header-info {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.chat-thread-title {
  display: flex;
  align-items: center;
  gap: 10px;
}

.chat-thread-title .name {
  font-size: 1.1rem;
  font-weight: 700;
  color: #1e293b;
}

.platform-indicator {
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
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
  gap: 8px;
}

.action-btn {
  width: 38px;
  height: 38px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #64748b;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
  cursor: pointer;
}

.action-btn:hover:not(:disabled) {
  background: #f8fafc;
  color: #3b82f6;
  border-color: #3b82f6;
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