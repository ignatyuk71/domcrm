<template>
  <div class="chat-filters">
    <div class="chat-search">
      <i class="bi bi-search"></i>
      <input
        :value="search"
        type="text"
        placeholder="Пошук клієнта..."
        @input="$emit('update:search', $event.target.value)"
      />
    </div>
    <div class="chat-tabs">
      <button
        v-for="tab in tabs"
        :key="tab.value"
        type="button"
        class="chat-tab"
        :class="{ active: tab.value === activeTab }"
        @click="$emit('change-tab', tab.value)"
      >
        {{ tab.label }}
      </button>
    </div>
  </div>
</template>

<script setup>
defineProps({
  search: { type: String, default: '' },
  tabs: { type: Array, default: () => [] },
  activeTab: { type: String, default: 'all' },
});

defineEmits(['update:search', 'change-tab']);
</script>
