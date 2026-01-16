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
    </div>
  </div>
</template>

<style scoped>
.chat-empty-container {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  background-color: #f8fafc; /* Світлий фон як у всій CRM */
  padding: 20px;
  text-align: center;
}

.chat-empty-content {
  max-width: 320px;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.chat-empty-icon {
  width: 80px;
  height: 80px;
  background: white;
  border-radius: 24px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2.5rem;
  color: #3b82f6;
  margin-bottom: 24px;
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
  /* Легкий ефект світіння */
  border: 1px solid #e2e8f0;
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
</style>