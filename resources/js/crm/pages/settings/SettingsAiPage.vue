<template>
  <div class="ai-settings-page">
    <div class="ai-settings-shell">
      <section class="hero-card">
        <div>
          <div class="eyebrow">Система AI</div>
          <h1>AI first line</h1>
          <p class="subtitle">
            Керує першою відповіддю, кваліфікацією ліда і передачею менеджеру.
          </p>
        </div>

        <div class="hero-statuses">
          <div class="status-card">
            <span>Статус</span>
            <strong :class="form.enabled ? 'is-good' : 'is-muted'">
              {{ form.enabled ? 'Увімкнено' : 'Вимкнено' }}
            </strong>
          </div>
          <div class="status-card">
            <span>OpenAI ключ</span>
            <strong :class="meta.has_api_key ? 'is-good' : 'is-danger'">
              {{ meta.has_api_key ? 'Додано' : 'Не додано' }}
            </strong>
          </div>
        </div>
      </section>

      <div v-if="flashMessage" class="alert" :class="flashType === 'error' ? 'alert-danger' : 'alert-success'">
        {{ flashMessage }}
      </div>

      <div class="grid">
        <section class="card-block">
          <div class="section-head">
            <h2>Основне</h2>
            <span class="hint">Базові параметри запуску</span>
          </div>

          <div class="toggle-row">
            <div class="toggle-copy">
              <strong>Увімкнути AI у чаті</strong>
              <span>Глобальний перемикач для першої лінії.</span>
            </div>
            <button
              type="button"
              class="ai-toggle"
              :class="{ 'is-active': form.enabled }"
              :aria-pressed="form.enabled ? 'true' : 'false'"
              @click="form.enabled = !form.enabled"
            >
              <span class="ai-toggle-track">
                <span class="ai-toggle-thumb"></span>
              </span>
              <span class="ai-toggle-text">
                {{ form.enabled ? 'Увімкнено' : 'Вимкнено' }}
              </span>
            </button>
          </div>

          <div class="form-grid">
            <div class="field">
              <label>Назва асистента</label>
              <input v-model.trim="form.assistant_name" type="text" placeholder="DomCRM AI" />
            </div>

            <div class="field">
              <label>Модель</label>
              <input v-model.trim="form.model" type="text" :placeholder="meta.default_model || 'gpt-4.1-mini'" />
            </div>

            <div class="field">
              <label>Повідомлень у контексті</label>
              <input v-model.number="form.max_messages" type="number" min="4" max="30" />
            </div>

            <div class="field full">
              <label>Стиль відповіді</label>
              <textarea
                v-model.trim="form.reply_style"
                rows="3"
                placeholder="Коротко, по суті, українською, без зайвих обіцянок."
              ></textarea>
            </div>
          </div>
        </section>

        <section class="card-block">
          <div class="section-head">
            <h2>Кваліфікація</h2>
            <span class="hint">Що AI має зібрати на старті</span>
          </div>

          <div class="chips-editor">
            <button
              v-for="(item, index) in form.qualification_fields"
              :key="`${item}-${index}`"
              type="button"
              class="chip-item"
              @click="removeField(index)"
            >
              {{ item }}
              <i class="bi bi-x"></i>
            </button>
          </div>

          <div class="inline-adder">
            <input
              v-model.trim="newQualificationField"
              type="text"
              placeholder="Додати поле"
              @keydown.enter.prevent="addField"
            />
            <button type="button" class="btn btn-outline-secondary" @click="addField">
              Додати
            </button>
          </div>

          <div class="form-grid mt-3">
            <div class="field full">
              <label>Коли передавати менеджеру</label>
              <textarea
                v-model.trim="form.handoff_rules"
                rows="5"
                placeholder="Кожне правило з нового рядка"
              ></textarea>
            </div>
          </div>
        </section>

        <section class="card-block full-width">
          <div class="section-head">
            <h2>Контекст бізнесу</h2>
            <span class="hint">Це йде в prompt для більш точних відповідей</span>
          </div>

          <div class="form-grid">
            <div class="field full">
              <label>Що продаємо і які є обмеження</label>
              <textarea
                v-model.trim="form.company_context"
                rows="5"
                placeholder="Коротко опиши товар, географію, доставку, важливі правила продажу."
              ></textarea>
            </div>

            <div class="field full">
              <label>База знань / FAQ</label>
              <textarea
                v-model.trim="form.knowledge_base"
                rows="8"
                placeholder="Типові питання, відповіді, заборонені обіцянки, рамки по ціні чи доставці."
              ></textarea>
            </div>
          </div>
        </section>
      </div>

      <div class="footer-bar">
        <div class="footer-note">
          <strong>OpenAI API key</strong>
          <span>Задається на сервері через `.env`, не через цю форму.</span>
        </div>

        <div class="footer-actions">
          <button class="btn btn-outline-secondary" :disabled="loading" @click="loadData">
            Оновити
          </button>
          <button class="btn btn-dark" :disabled="saving" @click="saveSettings">
            <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
            Зберегти
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import http from '@/crm/api/http';

const loading = ref(false);
const saving = ref(false);
const flashMessage = ref('');
const flashType = ref('success');
const newQualificationField = ref('');

const form = reactive({
  enabled: true,
  assistant_name: '',
  model: '',
  max_messages: 12,
  reply_style: '',
  company_context: '',
  qualification_fields: [],
  handoff_rules: '',
  knowledge_base: '',
});

const meta = reactive({
  has_api_key: false,
  api_key_source: '.env',
  default_model: 'gpt-4.1-mini',
  default_max_messages: 12,
});

function setFlash(message, type = 'success') {
  flashMessage.value = message || '';
  flashType.value = type;
}

function fillForm(settings = {}, metaPayload = {}) {
  form.enabled = Boolean(settings.enabled);
  form.assistant_name = settings.assistant_name || '';
  form.model = settings.model || '';
  form.max_messages = Number(settings.max_messages || 12);
  form.reply_style = settings.reply_style || '';
  form.company_context = settings.company_context || '';
  form.qualification_fields = Array.isArray(settings.qualification_fields) ? [...settings.qualification_fields] : [];
  form.handoff_rules = settings.handoff_rules || '';
  form.knowledge_base = settings.knowledge_base || '';

  Object.assign(meta, metaPayload || {});
}

async function loadData() {
  loading.value = true;

  try {
    const { data } = await http.get('/settings/ai');
    fillForm(data.settings || {}, data.meta || {});
  } catch (error) {
    setFlash(error.response?.data?.message || 'Не вдалося завантажити налаштування AI.', 'error');
  } finally {
    loading.value = false;
  }
}

function addField() {
  const value = newQualificationField.value.trim();
  if (!value || form.qualification_fields.includes(value)) {
    newQualificationField.value = '';
    return;
  }

  form.qualification_fields.push(value);
  newQualificationField.value = '';
}

function removeField(index) {
  form.qualification_fields.splice(index, 1);
}

async function saveSettings() {
  saving.value = true;

  try {
    const { data } = await http.post('/settings/ai', {
      enabled: form.enabled,
      assistant_name: form.assistant_name,
      model: form.model,
      max_messages: form.max_messages,
      reply_style: form.reply_style,
      company_context: form.company_context,
      qualification_fields: form.qualification_fields,
      handoff_rules: form.handoff_rules,
      knowledge_base: form.knowledge_base,
    });

    fillForm(data.settings || {}, data.meta || {});
    setFlash(data.message || 'Налаштування AI збережено.', 'success');
  } catch (error) {
    setFlash(error.response?.data?.message || 'Не вдалося зберегти налаштування AI.', 'error');
  } finally {
    saving.value = false;
  }
}

onMounted(loadData);
</script>

<style scoped>
.ai-settings-page {
  --shell-bg: #f4f7fb;
  --card-bg: #ffffff;
  --card-border: #e2e8f0;
  min-height: calc(100vh - 120px);
  font-family: "Segoe UI", sans-serif;
}

.ai-settings-shell {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.hero-card,
.card-block,
.footer-bar {
  border: 1px solid var(--card-border);
  border-radius: 20px;
  background: var(--card-bg);
  box-shadow: 0 20px 50px -40px rgba(15, 23, 42, 0.3);
}

.hero-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  padding: 24px 26px;
  background:
    radial-gradient(circle at top right, rgba(59, 130, 246, 0.12), transparent 32%),
    linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
}

.eyebrow {
  margin-bottom: 8px;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.08em;
  color: #2563eb;
  text-transform: uppercase;
}

.hero-card h1 {
  margin: 0;
  font-size: 34px;
  line-height: 1.05;
  font-weight: 800;
  color: #0f172a;
}

.subtitle {
  margin: 10px 0 0;
  max-width: 620px;
  font-size: 15px;
  line-height: 1.5;
  color: #475569;
}

.hero-statuses {
  display: grid;
  grid-template-columns: repeat(2, minmax(150px, 1fr));
  gap: 12px;
}

.status-card {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 150px;
  padding: 14px 16px;
  border-radius: 16px;
  background: rgba(248, 250, 252, 0.92);
  border: 1px solid #e2e8f0;
}

.status-card span {
  font-size: 12px;
  font-weight: 700;
  color: #64748b;
  text-transform: uppercase;
}

.status-card strong {
  font-size: 18px;
  color: #0f172a;
}

.status-card strong.is-good {
  color: #15803d;
}

.status-card strong.is-danger {
  color: #b91c1c;
}

.status-card strong.is-muted {
  color: #475569;
}

.grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px;
}

.card-block {
  padding: 22px;
}

.card-block.full-width {
  grid-column: 1 / -1;
}

.section-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 18px;
}

.section-head h2 {
  margin: 0;
  font-size: 22px;
  font-weight: 800;
  color: #0f172a;
}

.hint {
  font-size: 13px;
  color: #64748b;
}

.toggle-row {
  margin-bottom: 18px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 16px;
  border-radius: 16px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
}

.toggle-copy {
  min-width: 0;
}

.toggle-row strong {
  display: block;
  font-size: 15px;
  color: #0f172a;
}

.toggle-row span {
  display: block;
  margin-top: 4px;
  font-size: 13px;
  color: #64748b;
}

.ai-toggle {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  gap: 12px;
  min-width: 150px;
  padding: 10px 14px;
  border: 1px solid #cbd5e1;
  border-radius: 999px;
  background: #ffffff;
  color: #334155;
  font-size: 14px;
  font-weight: 700;
  transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease, color 0.2s ease;
}

.ai-toggle:hover {
  border-color: #93c5fd;
  box-shadow: 0 10px 24px -18px rgba(37, 99, 235, 0.45);
}

.ai-toggle:focus-visible {
  outline: none;
  border-color: #60a5fa;
  box-shadow: 0 0 0 4px rgba(96, 165, 250, 0.16);
}

.ai-toggle.is-active {
  border-color: #86efac;
  background: #f0fdf4;
  color: #166534;
}

.ai-toggle-track {
  position: relative;
  width: 42px;
  height: 24px;
  border-radius: 999px;
  background: #cbd5e1;
  transition: background-color 0.2s ease;
}

.ai-toggle-thumb {
  position: absolute;
  top: 3px;
  left: 3px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #ffffff;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.22);
  transition: transform 0.22s ease;
}

.ai-toggle.is-active .ai-toggle-track {
  background: #22c55e;
}

.ai-toggle.is-active .ai-toggle-thumb {
  transform: translateX(18px);
}

.ai-toggle-text {
  white-space: nowrap;
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
  font-size: 13px;
  font-weight: 700;
  color: #334155;
}

.field input,
.field textarea {
  width: 100%;
  border: 1px solid #cbd5e1;
  border-radius: 14px;
  padding: 12px 14px;
  font-size: 14px;
  color: #0f172a;
  background: #ffffff;
  outline: none;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.field input:focus,
.field textarea:focus,
.inline-adder input:focus {
  border-color: #60a5fa;
  box-shadow: 0 0 0 4px rgba(96, 165, 250, 0.14);
}

.chips-editor {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.chip-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 12px;
  border-radius: 999px;
  border: 1px solid #bfdbfe;
  background: #eff6ff;
  color: #1d4ed8;
  font-size: 13px;
  font-weight: 700;
}

.inline-adder {
  margin-top: 14px;
  display: flex;
  gap: 10px;
}

.inline-adder input {
  flex: 1 1 auto;
  min-width: 0;
  border: 1px solid #cbd5e1;
  border-radius: 14px;
  padding: 12px 14px;
}

.footer-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 18px 22px;
}

.footer-note {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.footer-note strong {
  font-size: 14px;
  color: #0f172a;
}

.footer-note span {
  font-size: 13px;
  color: #64748b;
}

.footer-actions {
  display: flex;
  gap: 10px;
}

@media (max-width: 992px) {
  .hero-card,
  .footer-bar,
  .toggle-row {
    flex-direction: column;
    align-items: stretch;
  }

  .hero-statuses,
  .grid,
  .form-grid {
    grid-template-columns: 1fr;
  }

  .footer-actions {
    justify-content: stretch;
  }

  .footer-actions .btn {
    flex: 1 1 0;
  }
}
</style>
