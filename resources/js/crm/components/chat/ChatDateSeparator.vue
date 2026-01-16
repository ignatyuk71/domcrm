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
  margin: 20px 0;
  padding: 0 10px;
  gap: 16px;
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
  background: #f8fafc; /* Колір фону чату, щоб перекривати лінію */
  padding: 4px 12px;
  border-radius: 999px;
  border: 1px solid #e2e8f0;
  text-transform: lowercase;
}

/* Якщо фон чату білий, змініть background у .chat-date-label на #fff */
</style>