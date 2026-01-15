<template>
  <div class="chat-thread-header">
    <div class="chat-thread-title">
      <span>{{ title || 'Оберіть клієнта' }}</span>
      <span v-if="subtitle" class="chat-thread-subtitle">{{ subtitle }}</span>
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
});
</script>
