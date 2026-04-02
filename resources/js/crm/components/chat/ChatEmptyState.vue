<template>
  <div class="chat-empty-container">
    <div class="chat-empty-content">
      <div class="chat-empty-icon">
        <i class="bi bi-chat-left-dots"></i>
      </div>
      <h3 class="chat-empty-title">Оберіть чат</h3>
      <p class="chat-empty-subtitle">
        Виберіть діалог зі списку зліва, щоб переглянути історію повідомлень та відповісти клієнту
      </p>
      <button type="button" class="chat-empty-button" @click="$emit('open-list')">
        Список чатів
      </button>
    </div>
  </div>
</template>

<script setup>
defineEmits(['open-list']);
</script>

<style scoped>
.chat-empty-container {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  background: linear-gradient(180deg, #ffffff, #fbfdff);
  padding: 22px;
  text-align: center;
}

.chat-empty-content {
  max-width: 320px;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.chat-empty-icon {
  width: 68px;
  height: 68px;
  background: #f8fafc;
  border-radius: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2.5rem;
  color: #2563eb;
  margin-bottom: 18px;
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
  border: 1px solid rgba(148, 163, 184, 0.18);
}

.chat-empty-title {
  font-size: 1.25rem;
  font-weight: 700;
  color: #1e293b;
  margin-bottom: 8px;
}

.chat-empty-subtitle {
  font-size: 0.9rem;
  color: #64748b;
  line-height: 1.6;
}

.chat-empty-button {
  display: none;
  margin-top: 16px;
  background: #2563eb;
  color: #fff;
  border: none;
  border-radius: 10px;
  padding: 10px 16px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 10px 20px rgba(37, 99, 235, 0.16);
}

@media (max-width: 768px) {
  .chat-empty-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
}
</style>
