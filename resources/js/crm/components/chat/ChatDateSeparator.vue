<template>
  <div class="chat-date-container">
    <div class="chat-date-line"></div>
    <div class="chat-date-label">{{ label }}</div>
    <div class="chat-date-line"></div>
  </div>
</template>

<script setup>
defineProps({
  label: { type: String, default: '' },
});
</script>

<style scoped>
.chat-date-container {
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 24px 0;
  padding: 0 16px;
  gap: 16px;
  width: 100%;
}

.chat-date-line {
  flex: 1;
  height: 1px;
  background: linear-gradient(to right, transparent, #e2e8f0, transparent);
}

.chat-date-label {
  font-size: 0.75rem;
  font-weight: 600;
  color: #94a3b8;
  /* Змінено на #fff, щоб відповідати фону ChatThread */
  background: #fff; 
  padding: 4px 14px;
  border-radius: 999px;
  border: 1px solid #f1f5f9;
  text-transform: lowercase;
  white-space: nowrap;
  user-select: none;
}
</style>