<template>
  <div class="container-fluid max-width-1400 py-3 py-md-4 ai-settings-page">
    <div class="ai-page-stack">
      <section class="hero-card">
        <div class="hero-copy">
          <div class="eyebrow">Система AI</div>
          <h1>AI first line</h1>
          <p class="subtitle">
            Керує першою відповіддю, кваліфікацією ліда і передачею менеджеру. Тут задаються тільки робочі параметри без зайвого шуму.
          </p>
        </div>

        <div class="hero-side">
          <div class="hero-statuses">
            <div class="status-card">
              <span class="status-label">Статус</span>
              <strong class="status-value" :class="form.enabled ? 'is-good' : 'is-muted'">
                {{ form.enabled ? 'Увімкнено' : 'Вимкнено' }}
              </strong>
            </div>
            <div class="status-card">
              <span class="status-label">OpenAI ключ</span>
              <strong class="status-value" :class="meta.has_api_key ? 'is-good' : 'is-danger'">
                {{ meta.has_api_key ? 'Додано' : 'Не додано' }}
              </strong>
            </div>
          </div>

          <div class="hero-actions">
            <a class="btn btn-outline-secondary" href="/settings/ai/knowledge">
              База AI
            </a>
            <button class="btn btn-outline-secondary" :disabled="loading" @click="loadData">
              Оновити
            </button>
            <button class="btn btn-dark" :disabled="saving" @click="saveSettings">
              <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
              Зберегти
            </button>
          </div>
        </div>
      </section>

      <div v-if="flashMessage" class="alert mb-0" :class="flashType === 'error' ? 'alert-danger' : 'alert-success'">
        {{ flashMessage }}
      </div>

      <section class="master-card">
        <div class="master-copy">
          <span class="section-kicker">Глобальний режим</span>
          <h2>{{ form.enabled ? 'AI активний у чатах' : 'AI вимкнений у чатах' }}</h2>
          <p>Коли вимкнено, нові діалоги не отримують автоматичну відповідь від AI.</p>
        </div>

        <button
          type="button"
          class="ai-master-toggle"
          :class="{ 'is-active': form.enabled }"
          :aria-pressed="form.enabled ? 'true' : 'false'"
          @click="form.enabled = !form.enabled"
        >
          <span class="ai-master-track">
            <span class="ai-master-thumb"></span>
          </span>
          <span class="ai-master-text">
            {{ form.enabled ? 'Увімкнено' : 'Вимкнено' }}
          </span>
        </button>
      </section>

      <div class="settings-grid">
        <section class="settings-card">
          <div class="card-head">
            <div>
              <h2>Основні параметри</h2>
              <p>Імʼя асистента, модель, контекст і стиль відповіді.</p>
            </div>
            <span class="card-badge">База</span>
          </div>

          <div class="form-grid">
            <div class="field">
              <label>Назва асистента</label>
              <input v-model.trim="form.assistant_name" type="text" placeholder="DomCRM AI" />
              <p class="field-help">Назва, яку бачить команда в системі.</p>
            </div>

            <div class="field">
              <label>Модель</label>
              <select v-model="form.model" class="model-select">
                <option
                  v-for="model in modelOptions"
                  :key="model.value"
                  :value="model.value"
                >
                  {{ model.label }}
                </option>
              </select>
              <div v-if="selectedModelMeta?.description" class="model-note">
                {{ selectedModelMeta.description }}
              </div>
            </div>

            <div class="field">
              <label>Повідомлень у контексті</label>
              <input v-model.number="form.max_messages" type="number" min="4" max="30" />
              <p class="field-help">Скільки останніх повідомлень AI бере в аналіз.</p>
            </div>

            <div class="field full">
              <label>Стиль відповіді</label>
              <textarea
                v-model.trim="form.reply_style"
                rows="4"
                placeholder="Коротко, спокійно, українською, без зайвих обіцянок."
              ></textarea>
              <p class="field-help">Задає тон відповіді: стриманий, продажний, дружній або формальний.</p>
            </div>
          </div>
        </section>

        <section class="settings-card">
          <div class="card-head">
            <div>
              <h2>Кваліфікація</h2>
              <p>Що AI збирає на старті і в яких випадках віддає чат менеджеру.</p>
            </div>
            <span class="card-badge">{{ form.qualification_fields.length }} полів</span>
          </div>

          <div class="field-block">
            <label>Поля для збору</label>
            <p class="field-help">Натисни на тег, щоб видалити його зі списку.</p>

            <div class="chip-panel">
              <div v-if="!form.qualification_fields.length" class="empty-state-inline">
                Поля ще не додані.
              </div>

              <div v-else class="chips-editor">
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
            </div>

            <div class="inline-adder">
              <input
                v-model.trim="newQualificationField"
                type="text"
                placeholder="Додати нове поле"
                @keydown.enter.prevent="addField"
              />
              <button type="button" class="btn btn-outline-secondary" @click="addField">
                Додати
              </button>
            </div>
          </div>

          <div class="field-block">
            <label>Коли передавати менеджеру</label>
            <p class="field-help">Кожне правило з нового рядка. Це тригери для handoff.</p>
            <textarea
              v-model.trim="form.handoff_rules"
              rows="8"
              placeholder="Точна ціна&#10;Знижка&#10;Оплата&#10;Живий менеджер&#10;Нестандартний запит&#10;Скарга або конфлікт"
            ></textarea>
          </div>
        </section>

        <section class="settings-card full-span">
          <div class="card-head">
            <div>
              <h2>Контекст бізнесу</h2>
              <p>Це підтягується в prompt, щоб відповіді були точнішими і без помилкових обіцянок.</p>
            </div>
            <span class="card-badge">Prompt</span>
          </div>

          <div class="business-grid">
            <div class="field-block">
              <label>Що продаємо і які є обмеження</label>
              <p class="field-help">Товар, географія, доставка, оплата, що можна і не можна обіцяти.</p>
              <textarea
                v-model.trim="form.company_context"
                rows="7"
                placeholder="Коротко опиши товар, географію, доставку, важливі правила продажу."
              ></textarea>
            </div>

            <div class="field-block">
              <label>База знань / FAQ</label>
              <p class="field-help">Типові питання, відповіді, скрипти, обмеження, заборонені формулювання.</p>
              <textarea
                v-model.trim="form.knowledge_base"
                rows="11"
                placeholder="Типові питання, відповіді, заборонені обіцянки, рамки по ціні чи доставці."
              ></textarea>
            </div>
          </div>
        </section>
      </div>

      <section class="save-bar">
        <div class="save-bar-note">
          <strong>OpenAI API key</strong>
          <span>Ключ задається на сервері через `.env`. Ця форма керує лише поведінкою AI.</span>
        </div>

        <div class="footer-actions">
          <button class="btn btn-outline-secondary" :disabled="loading" @click="loadData">
            Оновити
          </button>
          <button class="btn btn-dark" :disabled="saving" @click="saveSettings">
            <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
            Зберегти зміни
          </button>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
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
  default_model: 'gpt-5.4-mini',
  default_max_messages: 12,
  available_models: [
    {
      value: 'gpt-5.4-mini',
      label: 'GPT-5.4 Mini',
      description: 'Рекомендована середина для першої лінії: дешевше за повну 5.4 і помітно адекватніше за nano.',
    },
  ],
});

const modelOptions = computed(() => {
  const items = Array.isArray(meta.available_models) ? [...meta.available_models] : [];
  const currentValue = String(form.model || '').trim();

  if (currentValue !== '' && !items.some((item) => item?.value === currentValue)) {
    items.unshift({
      value: currentValue,
      label: `${currentValue} (поточна)`,
      description: 'Поточне значення не входить у стандартний список, але буде збережене.',
    });
  }

  return items;
});

const selectedModelMeta = computed(() => (
  modelOptions.value.find((item) => item.value === form.model) || null
));

function setFlash(message, type = 'success') {
  flashMessage.value = message || '';
  flashType.value = type;
}

function fillForm(settings = {}, metaPayload = {}) {
  Object.assign(meta, metaPayload || {});

  form.enabled = Boolean(settings.enabled);
  form.assistant_name = settings.assistant_name || '';
  form.model = settings.model || meta.default_model || 'gpt-5.4-mini';
  form.max_messages = Number(settings.max_messages || 12);
  form.reply_style = settings.reply_style || '';
  form.company_context = settings.company_context || '';
  form.qualification_fields = Array.isArray(settings.qualification_fields) ? [...settings.qualification_fields] : [];
  form.handoff_rules = settings.handoff_rules || '';
  form.knowledge_base = settings.knowledge_base || '';
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
.max-width-1400 {
  max-width: 1400px;
  margin: 0 auto;
}

.ai-settings-page {
  --page-bg: #f4f7fb;
  --card-bg: #ffffff;
  --card-border: #e2e8f0;
  --card-border-strong: #d6deea;
  --text-main: #0f172a;
  --text-muted: #64748b;
  --soft-surface: #f8fafc;
  min-height: calc(100vh - 120px);
  color: var(--text-main);
}

.ai-page-stack {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.hero-card,
.master-card,
.settings-card,
.save-bar {
  background: var(--card-bg);
  border: 1px solid var(--card-border);
  border-radius: 24px;
  box-shadow: 0 24px 60px -44px rgba(15, 23, 42, 0.32);
}

.hero-card {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(320px, 0.9fr);
  gap: 28px;
  padding: 30px 32px;
  background:
    radial-gradient(circle at top right, rgba(59, 130, 246, 0.12), transparent 34%),
    linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
}

.hero-copy h1 {
  margin: 0;
  font-size: 48px;
  line-height: 0.98;
  letter-spacing: -0.04em;
  font-weight: 800;
}

.eyebrow {
  display: inline-block;
  margin-bottom: 12px;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: #2563eb;
}

.subtitle {
  max-width: 760px;
  margin: 16px 0 0;
  font-size: 16px;
  line-height: 1.6;
  color: #475569;
}

.hero-side {
  display: flex;
  flex-direction: column;
  gap: 14px;
  align-items: stretch;
}

.hero-statuses {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.status-card {
  padding: 16px 18px;
  border: 1px solid var(--card-border);
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.92);
  min-height: 96px;
}

.status-label {
  display: block;
  margin-bottom: 10px;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text-muted);
}

.status-value {
  display: block;
  font-size: 24px;
  line-height: 1.1;
  font-weight: 800;
}

.status-value.is-good {
  color: #15803d;
}

.status-value.is-danger {
  color: #b91c1c;
}

.status-value.is-muted {
  color: #475569;
}

.hero-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  flex-wrap: wrap;
}

.master-card {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 24px;
  align-items: center;
  padding: 24px 28px;
}

.section-kicker {
  display: inline-block;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #2563eb;
}

.master-copy h2 {
  margin: 10px 0 10px;
  font-size: 26px;
  line-height: 1.1;
  font-weight: 800;
}

.master-copy p {
  margin: 0;
  font-size: 15px;
  line-height: 1.55;
  color: var(--text-muted);
}

.ai-master-toggle {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  gap: 14px;
  min-width: 220px;
  justify-content: center;
  padding: 12px 18px;
  border: 1px solid #cbd5e1;
  border-radius: 999px;
  background: #ffffff;
  color: #334155;
  font-size: 15px;
  font-weight: 800;
  transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease, color 0.2s ease;
}

.ai-master-toggle:hover {
  border-color: #93c5fd;
  box-shadow: 0 14px 28px -22px rgba(37, 99, 235, 0.48);
}

.ai-master-toggle:focus-visible {
  outline: none;
  border-color: #60a5fa;
  box-shadow: 0 0 0 4px rgba(96, 165, 250, 0.16);
}

.ai-master-toggle.is-active {
  border-color: #86efac;
  background: #f0fdf4;
  color: #166534;
}

.ai-master-track {
  position: relative;
  width: 48px;
  height: 28px;
  border-radius: 999px;
  background: #cbd5e1;
  transition: background-color 0.2s ease;
}

.ai-master-thumb {
  position: absolute;
  top: 3px;
  left: 3px;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: #ffffff;
  box-shadow: 0 1px 4px rgba(15, 23, 42, 0.22);
  transition: transform 0.22s ease;
}

.ai-master-toggle.is-active .ai-master-track {
  background: #22c55e;
}

.ai-master-toggle.is-active .ai-master-thumb {
  transform: translateX(20px);
}

.settings-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.05fr) minmax(0, 0.95fr);
  gap: 20px;
  align-items: start;
}

.settings-card {
  padding: 26px 28px;
}

.settings-card.full-span {
  grid-column: 1 / -1;
}

.card-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 24px;
}

.card-head h2 {
  margin: 0;
  font-size: 28px;
  line-height: 1.08;
  font-weight: 800;
}

.card-head p {
  margin: 10px 0 0;
  font-size: 14px;
  line-height: 1.55;
  color: var(--text-muted);
}

.card-badge {
  flex-shrink: 0;
  padding: 8px 12px;
  border-radius: 999px;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  font-size: 12px;
  font-weight: 800;
  color: #1d4ed8;
}

.form-grid,
.business-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px;
}

.field,
.field-block {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.field.full {
  grid-column: 1 / -1;
}

.field label,
.field-block label {
  font-size: 13px;
  font-weight: 800;
  letter-spacing: 0.01em;
  color: #334155;
}

.field-help {
  margin: -2px 0 0;
  font-size: 13px;
  line-height: 1.45;
  color: var(--text-muted);
}

.field input,
.field select,
.field textarea,
.field-block textarea,
.inline-adder input {
  width: 100%;
  border: 1px solid #cbd5e1;
  border-radius: 16px;
  padding: 14px 16px;
  font-size: 15px;
  line-height: 1.45;
  color: var(--text-main);
  background: #ffffff;
  outline: none;
  transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
}

.field textarea,
.field-block textarea {
  resize: vertical;
}

.field select {
  appearance: none;
  padding-right: 48px;
  background-image:
    linear-gradient(45deg, transparent 50%, #64748b 50%),
    linear-gradient(135deg, #64748b 50%, transparent 50%);
  background-position:
    calc(100% - 22px) calc(50% - 2px),
    calc(100% - 16px) calc(50% - 2px);
  background-size: 6px 6px, 6px 6px;
  background-repeat: no-repeat;
  cursor: pointer;
}

.field input::placeholder,
.field textarea::placeholder,
.field-block textarea::placeholder,
.inline-adder input::placeholder {
  color: #94a3b8;
}

.field input:focus,
.field select:focus,
.field textarea:focus,
.field-block textarea:focus,
.inline-adder input:focus {
  border-color: #60a5fa;
  box-shadow: 0 0 0 4px rgba(96, 165, 250, 0.14);
}

.model-note {
  padding: 10px 12px;
  border: 1px solid #dbeafe;
  border-radius: 14px;
  background: #f8fbff;
  font-size: 13px;
  line-height: 1.45;
  color: #1e3a8a;
}

.chip-panel {
  min-height: 74px;
  padding: 14px;
  border: 1px solid var(--card-border);
  border-radius: 18px;
  background: var(--soft-surface);
}

.empty-state-inline {
  display: flex;
  align-items: center;
  min-height: 44px;
  font-size: 14px;
  color: #94a3b8;
}

.chips-editor {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.chip-item {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  border-radius: 999px;
  border: 1px solid #bfdbfe;
  background: #eff6ff;
  color: #1d4ed8;
  font-size: 14px;
  font-weight: 700;
  transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
}

.chip-item:hover {
  transform: translateY(-1px);
  box-shadow: 0 10px 20px -18px rgba(29, 78, 216, 0.45);
  border-color: #93c5fd;
}

.inline-adder {
  margin-top: 12px;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 10px;
}

.save-bar {
  position: sticky;
  bottom: 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 18px 22px;
  background: rgba(255, 255, 255, 0.96);
  backdrop-filter: blur(10px);
}

.save-bar-note {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.save-bar-note strong {
  font-size: 14px;
  color: var(--text-main);
}

.save-bar-note span {
  font-size: 13px;
  color: var(--text-muted);
}

.footer-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

@media (max-width: 1199px) {
  .hero-card,
  .settings-grid,
  .business-grid,
  .form-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 992px) {
  .hero-card,
  .master-card,
  .save-bar {
    padding: 22px 20px;
  }

  .hero-copy h1 {
    font-size: 38px;
  }

  .hero-actions,
  .footer-actions {
    width: 100%;
  }

  .hero-actions .btn,
  .footer-actions .btn {
    flex: 1 1 0;
  }

  .master-card,
  .save-bar {
    grid-template-columns: 1fr;
  }

  .card-head {
    flex-direction: column;
  }
}

@media (max-width: 767px) {
  .ai-settings-page {
    padding-left: 0.25rem;
    padding-right: 0.25rem;
  }

  .hero-card,
  .settings-card,
  .master-card,
  .save-bar {
    border-radius: 20px;
  }

  .hero-copy h1 {
    font-size: 32px;
  }

  .hero-statuses,
  .inline-adder {
    grid-template-columns: 1fr;
  }

  .ai-master-toggle {
    width: 100%;
  }
}
</style>
