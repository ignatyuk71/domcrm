<template>
  <div class="chat-sidebar-inner">
    <ChatFilters
      :search="search"
      :tabs="tabs"
      :active-tab="activeTab"
      @update:search="$emit('update:search', $event)"
      @change-tab="$emit('change-tab', $event)"
    />

    <div class="chat-sidebar-list">
      <ChatSidebarItem
        v-for="chat in conversations"
        :key="chat.customer_id"
        :item="chat"
        :is-active="chat.customer_id === activeChatId"
        @select="$emit('select', chat)"
      />
      <div v-if="!conversations.length" class="chat-sidebar-empty">
        Немає чатів
      </div>
      <button
        v-if="hasMore"
        type="button"
        class="chat-load-more"
        @click="$emit('load-more')"
      >
        Завантажити ще...
      </button>
    </div>
  </div>
</template>

<script setup>
import ChatFilters from './ChatFilters.vue';
import ChatSidebarItem from './ChatSidebarItem.vue';

defineProps({
  conversations: { type: Array, default: () => [] },
  activeChatId: { type: [Number, String, null], default: null },
  search: { type: String, default: '' },
  tabs: { type: Array, default: () => [] },
  activeTab: { type: String, default: 'all' },
  hasMore: { type: Boolean, default: false },
});

defineEmits(['select', 'update:search', 'change-tab', 'load-more']);
</script>
