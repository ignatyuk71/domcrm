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
  min-height: calc(100vh - 112px);
  padding: 2px 0 0;
  background: transparent;
}

.chat-topbar {
  margin-bottom: 8px;
  border-radius: 8px;
  border: 1px solid rgba(226, 232, 240, 0.92);
  background: #ffffff;
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
  overflow: hidden;
}

.chat-container {
  height: calc(100vh - 182px);
  display: grid;
  grid-template-columns: 340px minmax(0, 1fr) 390px;
  gap: 8px;
  border-radius: 16px;
  overflow: visible;
  background: transparent;
}

.chat-sidebar,
.chat-thread,
.chat-profile {
  min-height: 0;
  overflow: hidden;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 5px;
  box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
}

.chat-profile {
  background: #ffffff;
}

@media (max-width: 1320px) {
  .chat-container {
    height: calc(100vh - 182px);
    grid-template-columns: 320px minmax(0, 1fr);
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
    background: transparent;
  }

  .chat-container {
    height: calc(100vh - 72px);
    display: block;
    border-radius: 0;
  }

  .chat-sidebar,
  .chat-thread,
  .chat-profile {
    width: 100%;
    height: calc(100vh - 72px);
    border-radius: 8px 8px 0 0;
    border-inline: none;
    border-bottom: none;
    box-shadow: none;
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

  .chat-topbar {
    margin-bottom: 8px;
    border-radius: 8px;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.04);
  }
}
</style>
