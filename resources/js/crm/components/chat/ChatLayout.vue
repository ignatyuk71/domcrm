<template>
  <div class="chat-wrapper">
    <div class="chat-container">
      <aside class="chat-sidebar">
        <div class="sidebar-inner">
          <slot name="sidebar" />
        </div>
      </aside>

      <main class="chat-thread">
        <div class="thread-inner">
          <slot name="thread" />
        </div>
      </main>
    </div>
  </div>
</template>

<style scoped>
/* Обгортка для всього чату, щоб він займав доступну висоту */
.chat-wrapper {
  height: calc(100vh - 100px); /* Висота мінус хедер CRM */
  padding: 16px;
  background-color: #f1f5f9; /* Світло-сірий фон сторінки */
}

.chat-container {
  display: grid;
  /* Дві колонки: Список (320px), Чат (гнучкий) */
  grid-template-columns: 320px 1fr;
  height: 100%;
  gap: 16px;
  max-width: 1600px;
  margin: 0 auto;
}

/* Спільні стилі для всіх колонок */
.chat-sidebar,
.chat-thread {
  background: #fff;
  border-radius: 16px;
  border: 1px solid #e2e8f0;
  overflow: hidden; /* Щоб внутрішні скроли працювали правильно */
  display: flex;
  flex-direction: column;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
}

.sidebar-inner,
.thread-inner {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0; /* Важливо для роботи скролу всередині */
}

/* Адаптивність: на мобільних залишаємо тільки чат або список (логіка перемикання зазвичай на рівні сторінки) */
@media (max-width: 768px) {
  .chat-container {
    grid-template-columns: 1fr;
  }
  .chat-sidebar {
    display: none;
  }
}
</style>
