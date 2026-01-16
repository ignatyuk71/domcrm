<template>
  <div class="chat-filters">
    <!-- Пошук -->
    <div class="search-box">
      <div class="input-group">
        <span class="input-group-text">
          <i class="bi bi-search"></i>
        </span>
        <input
          type="text"
          class="form-control shadow-none"
          placeholder="Пошук клієнта..."
          :value="search"
          @input="$emit('update:search', $event.target.value)"
        />
        <button 
          v-if="search" 
          class="btn-clear" 
          @click="$emit('update:search', '')"
        >
          <i class="bi bi-x-circle-fill"></i>
        </button>
      </div>
    </div>

    <!-- Вкладки платформ -->
    <div class="tabs-container">
      <button
        v-for="tab in tabs"
        :key="tab.value"
        class="tab-btn"
        :class="{ active: activeTab === tab.value, [tab.value]: true }"
        @click="$emit('change-tab', tab.value)"
      >
        <span class="tab-label">{{ tab.label }}</span>
        <span v-if="tab.count !== undefined" class="tab-count">{{ tab.count }}</span>
      </button>
    </div>
  </div>
</template>

<script setup>
defineProps({
  search: { type: String, default: '' },
  tabs: { type: Array, required: true },
  activeTab: { type: String, required: true },
});

defineEmits(['update:search', 'change-tab']);
</script>

<style scoped>
.chat-filters {
  padding: 16px;
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.search-box {
  position: relative;
}

.input-group {
  background: #f1f5f9;
  border-radius: 12px;
  border: 1px solid transparent;
  transition: all 0.2s;
  overflow: hidden;
}

.input-group:focus-within {
  background: #fff;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.input-group-text {
  background: transparent;
  border: none;
  color: #94a3b8;
  padding-left: 12px;
}

.form-control {
  background: transparent;
  border: none;
  font-size: 0.9rem;
  padding: 10px 8px;
  color: #1e293b;
}

.btn-clear {
  background: transparent;
  border: none;
  color: #cbd5e1;
  padding-right: 12px;
  transition: color 0.2s;
}

.btn-clear:hover {
  color: #94a3b8;
}

/* Стилізація вкладок */
.tabs-container {
  display: flex;
  background: #f8fafc;
  padding: 4px;
  border-radius: 10px;
  gap: 4px;
}

.tab-btn {
  flex: 1;
  border: none;
  background: transparent;
  padding: 6px 4px;
  border-radius: 8px;
  font-size: 0.8rem;
  font-weight: 600;
  color: #64748b;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}

.tab-btn:hover:not(.active) {
  background: #f1f5f9;
  color: #475569;
}

.tab-btn.active {
  background: #fff;
  color: #1e293b;
  box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.tab-btn.active.facebook { color: #2563eb; }
.tab-btn.active.instagram { color: #db2777; }

.tab-count {
  font-size: 0.7rem;
  background: #e2e8f0;
  padding: 1px 6px;
  border-radius: 6px;
  color: #64748b;
}

.tab-btn.active .tab-count {
  background: #eff6ff;
  color: #3b82f6;
}
</style>