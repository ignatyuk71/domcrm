<template>
  <div class="chat-message" :class="{ mine: isMine }">
    <div class="chat-message-bubble">
      {{ message.text || '[без тексту]' }}
    </div>
    <div class="chat-message-time">{{ message.created_at || '' }}</div>
  </div>
</template>

<script setup>
defineProps({
  message: { type: Object, required: true },
  isMine: { type: Boolean, default: false },
});
</script>
