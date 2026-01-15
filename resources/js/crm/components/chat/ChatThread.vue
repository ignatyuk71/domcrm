<template>
  <div class="chat-thread-inner">
    <ChatHeader :title="title" :subtitle="subtitle" />

    <div class="chat-thread-body">
      <ChatEmptyState v-if="!activeChat" />
      <div v-else class="chat-messages">
        <ChatMessage
          v-for="message in messages"
          :key="message.id"
          :message="message"
          :is-mine="message.direction === 'outbound'"
        />
        <div v-if="!messages.length" class="chat-empty-inline">
          Повідомлень немає.
        </div>
      </div>
    </div>

    <ChatComposer
      v-if="activeChat"
      :sending="sending"
      @send="$emit('send', $event)"
    />
  </div>
</template>

<script setup>
import ChatComposer from './ChatComposer.vue';
import ChatEmptyState from './ChatEmptyState.vue';
import ChatHeader from './ChatHeader.vue';
import ChatMessage from './ChatMessage.vue';

defineProps({
  activeChat: { type: Object, default: null },
  messages: { type: Array, default: () => [] },
  sending: { type: Boolean, default: false },
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
});

defineEmits(['send']);
</script>
