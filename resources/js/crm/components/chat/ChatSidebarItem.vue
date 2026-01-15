<template>
  <button
    type="button"
    class="chat-sidebar-item"
    :class="{ active: isActive }"
    @click="$emit('select', item)"
  >
    <div class="chat-item-title">
      <span class="chat-item-platform" :class="item.platform">{{ platformLabel }}</span>
      <span class="chat-item-name">{{ item.customer_name }}</span>
    </div>
    <div class="chat-item-preview">{{ item.last_message || '—' }}</div>
    <div class="chat-item-meta">
      <span>{{ item.last_message_time || '' }}</span>
      <span v-if="item.unread_count" class="chat-item-badge">{{ item.unread_count }}</span>
    </div>
  </button>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  item: { type: Object, required: true },
  isActive: { type: Boolean, default: false },
});

defineEmits(['select']);

const platformLabel = computed(() => (props.item.platform === 'instagram' ? 'IG' : 'FB'));
</script>
