<template>
  <div class="chat-layout">
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
</template>
