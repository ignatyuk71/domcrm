<template>
  <div class="meta-settings-page">
    <div class="meta-shell">
      <section class="hero-card">
        <div>
          <div class="eyebrow">Meta інтеграція</div>
          <h1>Facebook та Instagram</h1>
          <p class="subtitle">
            Підключення сторінки, токени вебхука та базова діагностика для чату.
          </p>
        </div>

        <div class="hero-actions">
          <button
            class="btn btn-primary btn-lg"
            :disabled="loading || !meta.configured"
            @click="startConnect"
          >
            <i class="bi bi-facebook me-2"></i>
            {{ connection ? 'Перепідключити через Facebook' : 'Авторизація через Facebook' }}
          </button>

          <button
            v-if="connection"
            class="btn btn-outline-danger"
            :disabled="disconnecting"
            @click="disconnect"
          >
            <span v-if="disconnecting" class="spinner-border spinner-border-sm me-2"></span>
            Відключити
          </button>
        </div>
      </section>

      <div v-if="flashMessage" class="alert" :class="flashType === 'error' ? 'alert-danger' : 'alert-success'">
        {{ flashMessage }}
      </div>

      <div v-if="!meta.configured" class="alert alert-warning">
        У `.env` не налаштовані `META_APP_ID` або `META_APP_SECRET`. Авторизація через Facebook не запуститься.
      </div>

      <div class="grid">
        <section class="card-block">
          <div class="section-head">
            <h2>Стан підключення</h2>
            <span class="status-pill" :class="connection ? 'connected' : 'empty'">
              {{ connection ? 'Підключено' : 'Не підключено' }}
            </span>
          </div>

          <div v-if="connection" class="info-list">
            <div class="info-row">
              <span class="label">Facebook сторінка</span>
              <strong>{{ connection.facebook_page_name || '—' }}</strong>
            </div>
            <div class="info-row">
              <span class="label">Page ID</span>
              <strong>{{ connection.facebook_page_id || '—' }}</strong>
            </div>
            <div class="info-row">
              <span class="label">Instagram</span>
              <strong>{{ connection.instagram_username || '—' }}</strong>
            </div>
            <div class="info-row">
              <span class="label">Meta користувач</span>
              <strong>{{ connection.meta_user_name || '—' }}</strong>
            </div>
            <div class="info-row">
              <span class="label">Page token</span>
              <strong>{{ connection.has_page_token ? 'Збережено' : 'Відсутній' }}</strong>
            </div>
            <div class="info-row">
              <span class="label">Підключено</span>
              <strong>{{ formatDate(connection.connected_at) }}</strong>
            </div>
            <div class="info-row">
              <span class="label">Видані дозволи</span>
              <strong>{{ formatScopes(connection.granted_scopes) }}</strong>
            </div>
          </div>

          <div v-else class="empty-state">
            Після авторизації тут з’явиться сторінка Facebook, Instagram акаунт та статус токена.
          </div>

          <div v-if="connection?.last_error" class="alert alert-danger mt-3 mb-0">
            {{ connection.last_error }}
          </div>
        </section>

        <section class="card-block">
          <div class="section-head">
            <h2>Webhook параметри</h2>
            <span class="hint">Використовуй їх у Meta App Dashboard</span>
          </div>

          <div class="form-grid">
            <div class="field full">
              <label>URL вебхука</label>
              <input :value="meta.webhook_url" type="text" readonly @focus="$event.target.select()" />
            </div>

            <div class="field">
              <label>Verify Token</label>
              <input v-model.trim="form.verify_token" type="text" placeholder="Згенерується автоматично" />
            </div>

            <div class="field">
              <label>App Secret Proof / Webhook Secret</label>
              <input v-model.trim="form.webhook_secret" type="text" placeholder="Секрет для підпису" />
            </div>

            <div class="field full">
              <label>Назва підключення</label>
              <input v-model.trim="form.name" type="text" placeholder="Наприклад, Основний Instagram-магазин" />
            </div>
          </div>

          <div class="d-flex flex-wrap gap-2 mt-3">
            <button class="btn btn-dark" :disabled="saving" @click="saveSettings">
              <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
              Зберегти параметри
            </button>
            <button class="btn btn-outline-secondary" :disabled="loading" @click="loadData">
              Оновити стан
            </button>
          </div>

          <div class="tips">
            <div>Callback URL: <strong>{{ meta.callback_url || '—' }}</strong></div>
            <div>Для чату потрібні дозволи `pages_messaging` та `instagram_manage_messages`.</div>
            <div>Кнопка підключення перевидає доступ Meta та повторно запитує раніше пропущені дозволи.</div>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import http from '@/crm/api/http';

const props = defineProps({
  initialSuccess: {
    type: String,
    default: '',
  },
  initialError: {
    type: String,
    default: '',
  },
});

const connection = ref(null);
const meta = reactive({
  configured: false,
  connect_url: '',
  disconnect_url: '',
  save_url: '',
  callback_url: '',
  webhook_url: '',
});

const form = reactive({
  name: '',
  verify_token: '',
  webhook_secret: '',
});

const loading = ref(false);
const saving = ref(false);
const disconnecting = ref(false);
const flashMessage = ref('');
const flashType = ref('success');

function setFlash(message, type = 'success') {
  flashMessage.value = message || '';
  flashType.value = type;
}

function syncForm() {
  form.name = connection.value?.name || '';
  form.verify_token = connection.value?.verify_token || '';
  form.webhook_secret = connection.value?.webhook_secret || '';
}

async function loadData() {
  loading.value = true;

  try {
    const { data } = await http.get('/settings/meta');
    connection.value = data.connection;
    Object.assign(meta, data.meta || {});
    syncForm();

    if (data?.meta?.flash?.success) {
      setFlash(data.meta.flash.success, 'success');
    } else if (data?.meta?.flash?.error) {
      setFlash(data.meta.flash.error, 'error');
    }
  } catch (error) {
    setFlash(error.response?.data?.message || 'Не вдалося завантажити налаштування Meta.', 'error');
  } finally {
    loading.value = false;
  }
}

function startConnect() {
  if (!meta.connect_url) {
    setFlash('URL авторизації Meta недоступний.', 'error');
    return;
  }

  window.location.href = meta.connect_url;
}

function formatScopes(scopes) {
  if (!Array.isArray(scopes) || scopes.length === 0) {
    return '—';
  }

  return scopes.join(', ');
}

async function saveSettings() {
  saving.value = true;

  try {
    const { data } = await http.post(meta.save_url || '/settings/meta', {
      name: form.name,
      verify_token: form.verify_token,
      webhook_secret: form.webhook_secret,
    });

    connection.value = data.connection;
    syncForm();
    setFlash(data.message || 'Налаштування Meta збережено.', 'success');
  } catch (error) {
    setFlash(error.response?.data?.message || 'Не вдалося зберегти налаштування Meta.', 'error');
  } finally {
    saving.value = false;
  }
}

async function disconnect() {
  if (!confirm('Відключити Meta інтеграцію?')) {
    return;
  }

  disconnecting.value = true;

  try {
    const { data } = await http.post(meta.disconnect_url || '/settings/meta/disconnect');
    connection.value = null;
    syncForm();
    setFlash(data.message || 'Meta інтеграцію відключено.', 'success');
  } catch (error) {
    setFlash(error.response?.data?.message || 'Не вдалося відключити Meta інтеграцію.', 'error');
  } finally {
    disconnecting.value = false;
  }
}

function formatDate(value) {
  if (!value) {
    return '—';
  }

  return new Intl.DateTimeFormat('uk-UA', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value));
}

onMounted(() => {
  if (props.initialSuccess) {
    setFlash(props.initialSuccess, 'success');
  } else if (props.initialError) {
    setFlash(props.initialError, 'error');
  }

  loadData();
});
</script>

<style scoped>
.meta-settings-page {
  padding: 24px 0 48px;
}

.meta-shell {
  max-width: 1180px;
  margin: 0 auto;
}

.hero-card,
.card-block {
  background: #ffffff;
  border: 1px solid rgba(15, 23, 42, 0.08);
  border-radius: 28px;
  box-shadow: 0 18px 45px rgba(15, 23, 42, 0.06);
}

.hero-card {
  display: flex;
  justify-content: space-between;
  gap: 24px;
  align-items: center;
  padding: 32px;
  margin-bottom: 24px;
  background: linear-gradient(135deg, #eef4ff 0%, #ffffff 65%);
}

.eyebrow {
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: #4f46e5;
  font-weight: 700;
  margin-bottom: 10px;
}

h1 {
  font-size: 2.15rem;
  font-weight: 800;
  color: #111827;
  margin-bottom: 8px;
}

.subtitle {
  margin: 0;
  color: #64748b;
  max-width: 640px;
}

.hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}

.grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 24px;
}

.card-block {
  padding: 28px;
}

.section-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  margin-bottom: 20px;
}

.section-head h2 {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 800;
  color: #0f172a;
}

.status-pill {
  padding: 8px 14px;
  border-radius: 999px;
  font-size: 0.82rem;
  font-weight: 700;
}

.status-pill.connected {
  background: #dcfce7;
  color: #166534;
}

.status-pill.empty {
  background: #e2e8f0;
  color: #475569;
}

.hint {
  color: #64748b;
  font-size: 0.88rem;
}

.info-list {
  display: grid;
  gap: 14px;
}

.info-row {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid #e2e8f0;
}

.label {
  color: #64748b;
}

.empty-state {
  padding: 18px;
  border-radius: 20px;
  background: #f8fafc;
  color: #64748b;
}

.form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.field.full {
  grid-column: 1 / -1;
}

.field label {
  font-size: 0.88rem;
  font-weight: 700;
  color: #334155;
}

.field input {
  width: 100%;
  min-height: 48px;
  padding: 12px 14px;
  border-radius: 16px;
  border: 1px solid #cbd5e1;
  background: #ffffff;
  color: #0f172a;
}

.field input:focus {
  outline: none;
  border-color: #4f46e5;
  box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.12);
}

.tips {
  margin-top: 16px;
  color: #64748b;
  font-size: 0.9rem;
  display: grid;
  gap: 6px;
}

@media (max-width: 991px) {
  .hero-card,
  .grid {
    grid-template-columns: 1fr;
  }

  .hero-card {
    flex-direction: column;
    align-items: flex-start;
  }

  .form-grid {
    grid-template-columns: 1fr;
  }
}
</style>
