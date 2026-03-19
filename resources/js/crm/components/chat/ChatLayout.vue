<template>
  <div class="chat-wrapper" :data-view="viewMode">
    <div v-if="$slots.topbar" class="chat-topbar">
      <slot name="topbar" />
    </div>

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
  --chat-shell: #f3f4f6;
  min-height: calc(100vh - 96px);
  padding: 12px 16px 16px;
  background: var(--chat-shell);
  font-family: "Segoe UI", sans-serif;
}

.chat-topbar {
  margin-bottom: 10px;
  border-radius: 18px;
  border: 1px solid #e5e7eb;
  background: #fff;
  overflow: hidden;
}

.chat-container {
  height: calc(100vh - 132px);
  display: grid;
  grid-template-columns: 360px minmax(0, 1fr) 350px;
  gap: 0;
  border-radius: 18px;
  overflow: hidden;
  border: 1px solid #e5e7eb;
  background: #fff;
}

.chat-sidebar,
.chat-thread,
.chat-profile {
  min-height: 0;
  overflow: hidden;
  background: #fff;
  border-right: 1px solid #e5e7eb;
}

.chat-profile {
  border-right: none;
}

@media (max-width: 1320px) {
  .chat-container {
    grid-template-columns: 330px minmax(0, 1fr);
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
    border-radius: 0;
    border: none;
  }

  .chat-sidebar,
  .chat-thread,
  .chat-profile {
    width: 100%;
    height: 100vh;
    border-radius: 0;
    border: none;
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
