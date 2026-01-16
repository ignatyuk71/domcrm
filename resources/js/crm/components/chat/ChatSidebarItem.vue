<template>
  <button
    type="button"
    class="chat-sidebar-item"
    :class="{ active: isActive }"
    @click="$emit('select', item)"
  >
    <div class="chat-item-header">
      <div class="chat-item-title">
        <span class="platform-icon" :class="item.platform" :title="item.platform">
          <i :class="item.platform === 'instagram' ? 'bi bi-instagram' : 'bi bi-messenger'"></i>
        </span>
        <span class="chat-item-name">{{ item.customer_name || 'Невідомий клієнт' }}</span>
      </div>
      <span class="chat-item-time">{{ item.last_message_time || '' }}</span>
    </div>

    <div class="chat-item-body">
      <div class="chat-item-preview">
        {{ item.last_message || 'Немає повідомлень' }}
      </div>
      <span v-if="item.unread_count" class="chat-item-badge">
        {{ item.unread_count }}
      </span>
    </div>
  </button>
</template>

<script setup>
defineProps({
  item: { type: Object, required: true },
  isActive: { type: Boolean, default: false },
});

defineEmits(['select']);
</script>

<style scoped>
.chat-sidebar-item {
  width: 100%;
  padding: 12px 16px;
  border: 1px solid transparent;
  background: transparent;
  border-radius: 12px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  cursor: pointer;
  transition: all 0.2s ease;
  text-align: left;
}

.chat-sidebar-item:hover {
  background: #f8fafc;
}

.chat-sidebar-item.active {
  background: #eff6ff;
  border-color: #3b82f6;
}

/* Шапка картки */
.chat-item-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  width: 100%;
}

.chat-item-title {
  display: flex;
  align-items: center;
  gap: 8px;
  overflow: hidden;
}

.platform-icon {
  font-size: 0.9rem;
  display: flex;
  align-items: center;
}

.platform-icon.instagram { color: #db2777; }
.platform-icon.messenger { color: #2563eb; }

.chat-item-name {
  font-weight: 700;
  font-size: 0.9rem;
  color: #1e293b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.chat-item-time {
  font-size: 0.75rem;
  color: #94a3b8;
  white-space: nowrap;
}

/* Тіло картки */
.chat-item-body {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
}

.chat-item-preview {
  font-size: 0.85rem;
  color: #64748b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex: 1;
}

.chat-item-badge {
  background: #3b82f6;
  color: #fff;
  font-size: 0.7rem;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 10px;
  min-width: 20px;
  text-align: center;
}

.chat-sidebar-item.active .chat-item-name {
  color: #2563eb;
}
</style>
