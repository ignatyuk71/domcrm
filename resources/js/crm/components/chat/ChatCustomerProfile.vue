<template>
  <div class="chat-profile-inner">
    <div v-if="chat" class="chat-profile-content">
      <div class="profile-avatar">
        {{ chat.customer_name?.charAt(0).toUpperCase() || '?' }}
      </div>
      <h5 class="profile-name">{{ chat.customer_name || 'Невідомий клієнт' }}</h5>

      <div class="profile-row">
        <span class="label">Телефон</span>
        <span class="value">{{ chat.customer_phone || '—' }}</span>
      </div>
      <div class="profile-row">
        <span class="label">Email</span>
        <span class="value">{{ chat.customer_email || '—' }}</span>
      </div>
      <div class="profile-row">
        <span class="label">Платформа</span>
        <span class="value">{{ platformLabel }}</span>
      </div>

      <div class="profile-actions">
        <a :href="`/customers/${chat.customer_id}`" class="btn-action secondary">
          Перейти до клієнта
        </a>
        <a :href="`/orders/create?customer_id=${chat.customer_id}`" class="btn-action primary">
          Додати замовлення
        </a>
      </div>
    </div>

    <div v-else class="chat-profile-empty">
      Оберіть чат, щоб побачити дані клієнта
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  chat: { type: Object, default: null },
});

const platformLabel = computed(() => {
  if (!props.chat?.platform) return '—';
  return props.chat.platform === 'instagram' ? 'Instagram' : 'Facebook';
});
</script>

<style scoped>
.chat-profile-inner {
  padding: 20px;
  height: 100%;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.profile-avatar {
  width: 72px;
  height: 72px;
  border-radius: 20px;
  background: #eff6ff;
  color: #3b82f6;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 1.8rem;
}

.profile-name {
  margin: 0;
  font-size: 1rem;
  font-weight: 700;
  color: #0f172a;
}

.profile-row {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.label {
  font-size: 0.75rem;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.value {
  font-size: 0.9rem;
  color: #1e293b;
}

.profile-actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 8px;
}

.btn-action {
  text-align: center;
  border-radius: 10px;
  padding: 8px 12px;
  text-decoration: none;
  font-size: 0.85rem;
  font-weight: 600;
}

.btn-action.primary {
  background: #3b82f6;
  color: #fff;
}

.btn-action.secondary {
  background: #f1f5f9;
  color: #475569;
}

.chat-profile-empty {
  color: #94a3b8;
  text-align: center;
  margin-top: 40px;
}
</style>
