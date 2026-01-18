<template>
  <div class="right-sidebar">
    <div v-if="customerId" class="profile-content">
      
      <div class="header-section">
        <div class="avatar-wrap">
          <img v-if="avatarUrl" :src="avatarUrl" class="avatar-img">
          <div v-else class="avatar-placeholder">
            {{ displayInitial }}
          </div>
          <div class="platform-icon" :class="isInstagram ? 'ig-bg' : 'fb-bg'">
            <i class="bi" :class="isInstagram ? 'bi-instagram' : 'bi-messenger'"></i>
          </div>
        </div>
        
        <div class="info">
          <div class="name">{{ displayName }}</div>
          <div class="id-text">ID: {{customerId }}</div>
        </div>

        <button class="btn-unlink" title="Від'єднати">
          <i class="bi bi-person-x"></i>
        </button>
      </div>

      <hr class="divider" />

      <div class="fields-section">
        <div class="field-row">
          <div class="icon-col"><i class="bi bi-telephone"></i></div>
          <div class="input-col">
            <label>Телефон</label>
            <div v-if="form.phone || showPhoneInput" class="input-group" :class="{ 'is-focused': phoneFocused }">
              <input 
                v-model="form.phone" 
                class="simple-input" 
                placeholder="+380..." 
                ref="phoneRef"
                @focus="phoneFocused = true"
                @blur="phoneFocused = false"
              >
              <button class="btn-clear" @click="clearPhone">
                <i class="bi bi-x-circle-fill"></i>
              </button>
            </div>
            <div v-else class="add-btn" @click="enablePhone">
              <i class="bi bi-plus-circle"></i> Додати телефон
            </div>
          </div>
        </div>

        <div class="field-row">
          <div class="icon-col"><i class="bi bi-envelope"></i></div>
          <div class="input-col">
            <label>E-mail</label>
            <div v-if="form.email || showEmailInput" class="input-group" :class="{ 'is-focused': emailFocused }">
              <input 
                v-model="form.email" 
                class="simple-input" 
                placeholder="email@example.com"
                ref="emailRef"
                @focus="emailFocused = true"
                @blur="emailFocused = false"
              >
              <button class="btn-clear" @click="clearEmail">
                <i class="bi bi-x-circle-fill"></i>
              </button>
            </div>
            <div v-else class="add-btn" @click="enableEmail">
              <i class="bi bi-plus-circle"></i> Додати email
            </div>
          </div>
        </div>
      </div>

      <div class="footer-section">
        <button class="btn-save" @click="saveData" :disabled="isLoading">
          <span v-if="isLoading" class="spinner"></span>
          {{ isLoading ? 'Збереження...' : 'Зберегти зміни' }}
        </button>
      </div>

    </div>

    <div v-else class="empty-state">
      <i class="bi bi-chat-dots"></i>
      <p>Виберіть чат для перегляду профілю</p>
    </div>
  </div>
</template>

<script setup>
// ... твій існуючий JS код ...
// Додай лише ці два ref для візуальних ефектів:
const phoneFocused = ref(false);
const emailFocused = ref(false);
</script>

<style scoped>
/* Основний контейнер */
.right-sidebar {
  width: 100%;
  height: 100%;
  background: #f8fafc;
  border-left: 1px solid #e2e8f0;
  display: flex;
  flex-direction: column;
}

.profile-content {
  padding: 16px 16px 18px;
  display: flex;
  flex-direction: column;
  height: 100%;
}

.divider {
  border: 0;
  border-top: 1px solid #f1f5f9;
  margin: 20px 0;
}

/* HEADER */
.header-section {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 12px;
  border-radius: 14px;
  background: #ffffff;
  border: 1px solid #eef2f6;
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
  margin-bottom: 12px;
}

.avatar-wrap {
  position: relative;
  width: 52px;
  height: 52px;
  flex-shrink: 0;
}

.avatar-img, .avatar-placeholder {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  object-fit: cover;
}

.avatar-placeholder {
  background: #e2e8f0;
  color: #64748b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 18px;
}

.platform-icon {
  position: absolute;
  bottom: -3px;
  right: -3px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  box-shadow: 0 2px 4px rgba(15, 23, 42, 0.12);
}
.platform-icon .bi-instagram { color: #e1306c; font-size: 12px; }
.platform-icon .bi-messenger { color: #0084ff; font-size: 12px; }

.info { flex: 1; min-width: 0; }
.name {
  font-size: 15px;
  color: #1f2937;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.id-text {
  font-size: 12px;
  color: #64748b;
  margin-top: 2px;
}

.btn-unlink {
  background: #fff7ed;
  border: none;
  color: #f59e0b;
  width: 30px;
  height: 30px;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  justify-content: center;
}
.btn-unlink:hover { background: #ffedd5; }

/* FIELDS */
.fields-section {
  flex: 1;
  margin-top: 8px;
  padding: 12px;
  background: #ffffff;
  border: 1px solid #eef2f6;
  border-radius: 14px;
}

.field-row {
  display: flex;
  margin-bottom: 16px;
}
.field-row:last-child { margin-bottom: 0; }

.icon-col {
  width: 28px;
  color: #cbd5e1;
  font-size: 18px;
  padding-top: 18px;
}

.input-col {
  flex: 1;
  display: flex;
  flex-direction: column;
}

.input-col label {
  font-size: 12px;
  font-weight: 700;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 6px;
}

.add-btn {
  color: #2563eb;
  font-size: 13px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
}
.add-btn:hover { color: #1d4ed8; }

.input-group {
  display: flex;
  align-items: center;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 6px 8px;
  background: #f9fafb;
  transition: border-color 0.2s, box-shadow 0.2s;
}
.input-group.is-focused {
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}

.simple-input {
  flex: 1;
  border: none;
  background: transparent;
  font-size: 14px;
  color: #111827;
  outline: none;
  padding: 0;
  font-weight: 500;
}

.btn-clear {
  background: none;
  border: none;
  color: #94a3b8;
  cursor: pointer;
  padding: 0 4px;
  transition: color 0.2s;
}
.btn-clear:hover { color: #9ca3af; }

/* FOOTER */
.btn-save {
  width: 100%;
  background: #0ea5e9;
  color: #fff;
  border: none;
  border-radius: 10px;
  padding: 12px 14px;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 8px;
}
.btn-save:hover:not(:disabled) { background: #0284c7; }
.btn-save:disabled { background: #93c5fd; cursor: not-allowed; }

/* EMPTY STATE */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: #94a3b8;
  gap: 12px;
}
.empty-state i { font-size: 48px; opacity: 0.5; }
.empty-state p { font-size: 14px; }

/* Простий спіннер */
.spinner {
  width: 18px;
  height: 18px;
  border: 2px solid rgba(255,255,255,0.3);
  border-radius: 50%;
  border-top-color: #fff;
  animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>
