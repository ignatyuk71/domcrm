<template>
  <div class="chat-date-separator">{{ label }}</div>
</template>

<script setup>
defineProps({
  label: { type: String, default: '' },
});
</script>
