<template>
  <div class="chat-profile-card">
    <div v-if="chat">
      <div class="chat-profile-name">{{ chat.customer_name }}</div>
      <div class="chat-profile-meta">
        Платформа: {{ chat.platform === 'instagram' ? 'Instagram' : 'Messenger' }}
      </div>
    </div>
    <div v-else class="chat-profile-empty">
      Оберіть чат для деталей
    </div>
  </div>
</template>

<script setup>
defineProps({
  chat: { type: Object, default: null },
});
</script>
