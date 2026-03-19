<template>
  <div class="chat-wrapper" :data-view="viewMode">
    <div class="chat-container">
      <aside class="chat-sidebar">
        <slot name="sidebar" />
      </aside>

      <main class="chat-thread">
        <slot name="thread" />
      </main>

      <aside class="chat-profile">
        <slot name="profile" />
      </aside>
    </div>
  </div>
</template>

<script setup>
defineProps({
  viewMode: { type: String, default: 'list' },
});
</script>

<style scoped>
.chat-wrapper {
  --chat-shell: #f5f7fb;
  min-height: calc(100vh - 96px);
  padding: 18px;
  background:
    radial-gradient(circle at top left, rgba(14, 165, 233, 0.1), transparent 22%),
    radial-gradient(circle at right bottom, rgba(15, 23, 42, 0.08), transparent 28%),
    var(--chat-shell);
  font-family: "Manrope", "Segoe UI", sans-serif;
}

.chat-container {
  height: calc(100vh - 132px);
  display: grid;
  grid-template-columns: minmax(300px, 360px) minmax(0, 1fr) minmax(320px, 400px);
  gap: 16px;
}

.chat-sidebar,
.chat-thread,
.chat-profile {
  min-height: 0;
  overflow: hidden;
  border-radius: 28px;
  border: 1px solid rgba(148, 163, 184, 0.16);
  background: rgba(255, 255, 255, 0.92);
  box-shadow:
    0 18px 40px -30px rgba(15, 23, 42, 0.45),
    inset 0 1px 0 rgba(255, 255, 255, 0.4);
  backdrop-filter: blur(12px);
}

@media (max-width: 1320px) {
  .chat-container {
    grid-template-columns: minmax(280px, 330px) minmax(0, 1fr);
  }

  .chat-profile {
    display: none;
  }
}

@media (max-width: 768px) {
  .chat-wrapper {
    min-height: 100vh;
    height: 100vh;
    padding: 0;
    background: #fff;
  }

  .chat-container {
    height: 100vh;
    display: block;
  }

  .chat-sidebar,
  .chat-thread,
  .chat-profile {
    width: 100%;
    height: 100vh;
    border-radius: 0;
    border: none;
    box-shadow: none;
    backdrop-filter: none;
  }

  .chat-wrapper[data-view="list"] .chat-thread,
  .chat-wrapper[data-view="list"] .chat-profile {
    display: none;
  }

  .chat-wrapper[data-view="thread"] .chat-sidebar,
  .chat-wrapper[data-view="thread"] .chat-profile {
    display: none;
  }

  .chat-wrapper[data-view="profile"] .chat-sidebar,
  .chat-wrapper[data-view="profile"] .chat-thread {
    display: none;
  }
}
</style>
