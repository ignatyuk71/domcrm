<template>
  <div class="chat-profile-inner custom-scrollbar">
    <div v-if="chat" class="chat-profile-content">
      <div class="profile-header">
        <div class="profile-avatar">
          {{ chat.customer_name?.charAt(0).toUpperCase() || '?' }}
        </div>
        <h5 class="profile-name">{{ chat.customer_name }}</h5>
        <div class="profile-badge" :class="chat.platform">
          <i :class="chat.platform === 'instagram' ? 'bi bi-instagram' : 'bi bi-messenger'"></i>
          {{ chat.platform === 'instagram' ? 'Instagram' : 'Messenger' }}
        </div>
      </div>

      <hr class="my-4" />

      <div class="profile-section">
        <h6 class="section-title">Дії</h6>
        <div class="profile-actions">
          <button class="btn-action primary" @click="createOrder">
            <i class="bi bi-cart-plus"></i>
            Створити замовлення
          </button>
          <a :href="`/customers/${chat.customer_id}`" class="btn-action secondary">
            <i class="bi bi-person-badge"></i>
            Картка клієнта
          </a>
        </div>
      </div>

      <hr class="my-4" />

      <div class="profile-section">
        <h6 class="section-title">Інформація</h6>
        <div class="info-list">
          <div class="info-item">
            <span class="label">ID Клієнта:</span>
            <span class="value">#{{ chat.customer_id }}</span>
          </div>
          <div class="info-item">
            <span class="label">Остання активність:</span>
            <span class="value">{{ chat.last_message_time }}</span>
          </div>
        </div>
      </div>
    </div>

    <div v-else class="chat-profile-empty">
      <div class="empty-icon">
        <i class="bi bi-person-vcard"></i>
      </div>
      <p>Оберіть чат для перегляду деталей клієнта</p>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  chat: { type: Object, default: null },
});

function createOrder() {
  // Логіка переходу на створення замовлення з передачею ID клієнта
  window.location.href = `/orders/create?customer_id=${props.chat.customer_id}`;
}
</script>

<style scoped>
.chat-profile-inner {
  height: 100%;
  overflow-y: auto;
  padding: 24px;
  background: #fff;
}

.profile-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}

.profile-avatar {
  width: 80px;
  height: 80px;
  background: linear-gradient(135deg, #3b82f6, #2563eb);
  color: #fff;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  font-weight: 700;
  margin-bottom: 16px;
  box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
}

.profile-name {
  font-weight: 700;
  color: #1e293b;
  margin-bottom: 8px;
}

.profile-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 12px;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
}

.profile-badge.instagram {
  background: #fdf2f8;
  color: #db2777;
}

.profile-badge.messenger {
  background: #eff6ff;
  color: #2563eb;
}

.section-title {
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #94a3b8;
  margin-bottom: 12px;
  font-weight: 700;
}

.profile-actions {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.btn-action {
  width: 100%;
  padding: 10px;
  border-radius: 10px;
  border: none;
  font-size: 0.9rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: all 0.2s;
  text-decoration: none;
}

.btn-action.primary {
  background: #3b82f6;
  color: #fff;
}

.btn-action.primary:hover {
  background: #2563eb;
}

.btn-action.secondary {
  background: #f1f5f9;
  color: #475569;
}

.btn-action.secondary:hover {
  background: #e2e8f0;
}

.info-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.info-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.info-item .label {
  font-size: 0.75rem;
  color: #94a3b8;
}

.info-item .value {
  font-size: 0.9rem;
  font-weight: 600;
  color: #1e293b;
}

.chat-profile-empty {
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  color: #94a3b8;
  text-align: center;
  gap: 16px;
}

.empty-icon {
  font-size: 3rem;
  opacity: 0.3;
}
</style>