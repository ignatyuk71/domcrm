<template>
  <div class="ai-settings-page">
    <transition name="toast">
      <div v-if="toast.show" class="toast-notification" :class="toast.type">
        <i class="bi" :class="toast.type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'"></i>
        <div class="toast-copy">
          <strong>{{ toast.type === 'success' ? 'Готово' : 'Помилка' }}</strong>
          <span>{{ toast.message }}</span>
        </div>
      </div>
    </transition>

    <div class="ai-shell">
      <section class="hero-card">
        <div>
          <div class="eyebrow">AI автоматизація чату</div>
          <h1>Налаштування AI-агента</h1>
          <p class="subtitle">
            Керуйте глобальною поведінкою агента, затримкою відповіді та активними агентами.
          </p>
          <div class="hero-actions">
            <a class="btn btn-sm btn-outline-dark" href="/settings/ai/base">
              <i class="bi bi-database me-1"></i>
              База AI
            </a>
          </div>
        </div>
        <div class="hero-stats">
          <div class="stat-pill">
            <span>Активних агентів</span>
            <strong>{{ activeAgentsCount }}/{{ agents.length }}</strong>
          </div>
          <div class="stat-pill">
            <span>Затримка відповіді</span>
            <strong>{{ settings.reply_delay_seconds }} c</strong>
          </div>
        </div>
      </section>

      <div class="grid">
        <section class="card-block">
          <div class="section-head">
            <h2>Глобальні параметри</h2>
          </div>

          <div class="field-grid">
            <label class="switch-row">
              <div>
                <strong>AI увімкнено глобально</strong>
                <div class="hint">Якщо вимкнути — авто-відповіді не надсилаються.</div>
              </div>
              <input v-model="settings.enabled" type="checkbox">
            </label>

            <label class="switch-row">
              <div>
                <strong>AI у закріплених чатах</strong>
                <div class="hint">Дозволити AI відповідати в діалогах із призначеним менеджером.</div>
              </div>
              <input v-model="settings.allow_assigned_conversations" type="checkbox">
            </label>

            <label class="field">
              <span>Затримка перед відповіддю (сек)</span>
              <input
                v-model.number="settings.reply_delay_seconds"
                type="number"
                min="5"
                max="60"
              >
              <small>
                Скільки чекати після останнього повідомлення клієнта перед відповіддю AI.
                Рекомендовано: 10–15 сек, щоб обʼєднати кілька коротких повідомлень в одну відповідь.
              </small>
            </label>

            <label class="field">
              <span>Скільки останніх повідомлень давати в контекст</span>
              <input
                v-model.number="settings.max_messages"
                type="number"
                min="4"
                max="30"
              >
              <small>
                Це глибина памʼяті діалогу для AI: скільки останніх реплік (клієнта + бота) передається в модель.
                Більше значення дає кращий контекст, але робить відповідь важчою і дорожчою.
              </small>
            </label>

            <label class="field full">
              <span>Агент за замовчуванням</span>
              <select v-model="settings.default_agent_code">
                <option
                  v-for="agent in selectableAgents"
                  :key="agent.code"
                  :value="agent.code"
                >
                  {{ agent.name }} ({{ agent.code }})
                </option>
              </select>
              <small>
                Який саме AI-агент відповідатиме у чатах за замовчуванням.
                Значення береться зі списку агентів праворуч.
              </small>
            </label>
          </div>

          <div class="actions">
            <button class="btn btn-dark" :disabled="saving || loading" @click="saveSettings">
              <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
              Зберегти налаштування
            </button>
            <button class="btn btn-outline-secondary" :disabled="loading" @click="loadData">
              Оновити
            </button>
          </div>
        </section>

        <section class="card-block">
          <div class="section-head">
            <h2>Список агентів</h2>
            <div class="actions-compact">
              <button class="btn btn-sm btn-outline-success" :disabled="agentsBusy" @click="setAllAgents(true)">
                Увімкнути всі
              </button>
              <button class="btn btn-sm btn-outline-danger" :disabled="agentsBusy" @click="setAllAgents(false)">
                Вимкнути всі
              </button>
            </div>
          </div>

          <div class="agents-list">
            <div v-for="agent in agents" :key="agent.id" class="agent-row">
              <div class="agent-main">
                <div class="agent-name">{{ agent.name }}</div>
                <div class="agent-meta">
                  <span>{{ agent.code }}</span>
                  <span>{{ agent.model || '—' }}</span>
                  <span>T={{ Number(agent.temperature || 0).toFixed(2) }}</span>
                </div>
              </div>

              <button
                type="button"
                class="agent-toggle"
                :class="{ 'is-on': !!agent.is_active }"
                :disabled="busyAgentIds.has(agent.id)"
                @click="toggleAgent(agent)"
              >
                <span v-if="busyAgentIds.has(agent.id)" class="spinner-border spinner-border-sm"></span>
                <span v-else>{{ agent.is_active ? 'ON' : 'OFF' }}</span>
              </button>
            </div>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import http from '@/crm/api/http';

const loading = ref(false);
const saving = ref(false);
const agentsBusy = ref(false);
const busyAgentIds = ref(new Set());
const agents = ref([]);
const toast = reactive({
  show: false,
  message: '',
  type: 'success',
});
let toastTimer = null;

const settings = reactive({
  enabled: true,
  default_agent_code: 'sales_assistant_v1',
  reply_delay_seconds: 12,
  allow_assigned_conversations: true,
  max_messages: 12,
});

const selectableAgents = computed(() => (
  agents.value.length ? agents.value : []
));

const activeAgentsCount = computed(() => (
  agents.value.filter((agent) => !!agent.is_active).length
));

function setFlash(message, type = 'success') {
  const normalizedMessage = String(message || '').trim();
  if (!normalizedMessage) {
    return;
  }

  toast.message = normalizedMessage;
  toast.type = type === 'error' ? 'error' : 'success';
  toast.show = true;

  if (toastTimer) {
    clearTimeout(toastTimer);
  }

  toastTimer = setTimeout(() => {
    toast.show = false;
  }, 3000);
}

async function loadData() {
  loading.value = true;
  try {
    const { data } = await http.get('/settings/ai');
    Object.assign(settings, data?.settings || {});
    agents.value = Array.isArray(data?.agents) ? data.agents : [];

    if (
      agents.value.length > 0
      && !agents.value.some((agent) => agent.code === settings.default_agent_code)
    ) {
      settings.default_agent_code = agents.value[0].code;
    }
  } catch (error) {
    setFlash(error.response?.data?.message || 'Не вдалося завантажити AI налаштування.', 'error');
  } finally {
    loading.value = false;
  }
}

async function saveSettings() {
  saving.value = true;
  try {
    const payload = {
      enabled: !!settings.enabled,
      default_agent_code: settings.default_agent_code,
      reply_delay_seconds: Number(settings.reply_delay_seconds || 12),
      allow_assigned_conversations: !!settings.allow_assigned_conversations,
      max_messages: Number(settings.max_messages || 12),
    };

    const { data } = await http.post('/settings/ai', payload);
    Object.assign(settings, data?.settings || payload);
    setFlash(data?.message || 'Налаштування AI збережено.');
  } catch (error) {
    setFlash(error.response?.data?.message || 'Не вдалося зберегти AI налаштування.', 'error');
  } finally {
    saving.value = false;
  }
}

async function toggleAgent(agent) {
  const nextStatus = !agent.is_active;
  busyAgentIds.value = new Set([...busyAgentIds.value, agent.id]);

  try {
    const { data } = await http.patch(`/settings/ai/agents/${agent.id}`, {
      is_active: nextStatus,
    });

    agent.is_active = !!data?.agent?.is_active;
    setFlash(data?.message || `Агент ${agent.name} оновлено.`);

    if (agent.is_active && !settings.default_agent_code) {
      settings.default_agent_code = agent.code;
    }
  } catch (error) {
    setFlash(error.response?.data?.message || 'Не вдалося оновити статус агента.', 'error');
  } finally {
    const nextSet = new Set(busyAgentIds.value);
    nextSet.delete(agent.id);
    busyAgentIds.value = nextSet;
  }
}

async function setAllAgents(status) {
  if (!agents.value.length) {
    return;
  }

  agentsBusy.value = true;

  try {
    for (const agent of agents.value) {
      if (!!agent.is_active === !!status) {
        continue;
      }

      await http.patch(`/settings/ai/agents/${agent.id}`, {
        is_active: !!status,
      });
      agent.is_active = !!status;
    }

    if (!status) {
      setFlash('Усі агенти вимкнені.', 'success');
    } else {
      setFlash('Усі агенти увімкнені.', 'success');
      if (!agents.value.some((agent) => agent.code === settings.default_agent_code && agent.is_active)) {
        const firstActive = agents.value.find((agent) => agent.is_active);
        if (firstActive) {
          settings.default_agent_code = firstActive.code;
        }
      }
    }
  } catch (error) {
    setFlash(error.response?.data?.message || 'Не вдалося оновити список агентів.', 'error');
  } finally {
    agentsBusy.value = false;
  }
}

onMounted(loadData);

onBeforeUnmount(() => {
  if (toastTimer) {
    clearTimeout(toastTimer);
  }
});
</script>

<style scoped>
.ai-settings-page { color: #1f2937; position: relative; }
.ai-shell { display: flex; flex-direction: column; gap: 16px; }

.toast-notification {
  position: fixed;
  top: 16px;
  right: 16px;
  z-index: 1100;
  min-width: 240px;
  max-width: min(360px, calc(100vw - 24px));
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 10px;
  border: 1px solid #d1fae5;
  background: rgba(240, 253, 244, 0.98);
  box-shadow: 0 14px 30px -20px rgba(15, 23, 42, 0.45);
  backdrop-filter: blur(6px);
}

.toast-notification.success {
  border-color: #bbf7d0;
  background: rgba(240, 253, 244, 0.98);
}

.toast-notification.error {
  border-color: #fecaca;
  background: rgba(254, 242, 242, 0.98);
}

.toast-notification > i {
  margin-top: 1px;
  font-size: 18px;
  flex-shrink: 0;
}

.toast-notification.success > i {
  color: #16a34a;
}

.toast-notification.error > i {
  color: #dc2626;
}

.toast-copy {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.toast-copy strong {
  font-size: 12px;
  line-height: 1.2;
  font-weight: 700;
  color: #0f172a;
}

.toast-copy span {
  font-size: 13px;
  line-height: 1.35;
  color: #334155;
}

.toast-enter-active,
.toast-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
.hero-card {
  background: linear-gradient(135deg, #ecfeff 0%, #f8fafc 100%);
  border: 1px solid #c7d2fe;
  border-radius: 18px;
  padding: 20px 24px;
  display: flex;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}
.eyebrow { font-size: 12px; font-weight: 700; text-transform: uppercase; color: #0369a1; letter-spacing: .04em; }
h1 { margin: 6px 0 8px; font-size: 28px; font-weight: 700; color: #0f172a; }
.subtitle { margin: 0; color: #334155; max-width: 720px; }
.hero-actions { margin-top: 12px; display: flex; gap: 8px; }
.hero-stats { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.stat-pill { background: #ffffff; border: 1px solid #dbeafe; border-radius: 12px; padding: 10px 12px; min-width: 170px; }
.stat-pill span { display: block; color: #64748b; font-size: 12px; }
.stat-pill strong { color: #0f172a; font-size: 16px; }

.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.card-block { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px; }
.section-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.section-head h2 { margin: 0; font-size: 18px; font-weight: 700; color: #0f172a; }
.actions-compact { display: flex; gap: 8px; }

.field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.field { display: flex; flex-direction: column; gap: 6px; }
.field.full { grid-column: 1 / -1; }
.field span { font-weight: 600; font-size: 13px; color: #334155; }
.field small { color: #64748b; }
.field input,
.field select {
  height: 40px;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  padding: 0 12px;
  background: #fff;
}

.switch-row {
  grid-column: 1 / -1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 10px 12px;
}
.switch-row .hint { color: #64748b; font-size: 12px; margin-top: 4px; }
.switch-row input[type="checkbox"] { width: 20px; height: 20px; accent-color: #16a34a; }

.actions { margin-top: 16px; display: flex; gap: 10px; }

.agents-list { display: flex; flex-direction: column; gap: 8px; max-height: 420px; overflow: auto; padding-right: 2px; }
.agent-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 10px 12px;
}
.agent-main { min-width: 0; }
.agent-name { font-weight: 700; color: #0f172a; }
.agent-meta { display: flex; gap: 8px; flex-wrap: wrap; color: #64748b; font-size: 12px; }

.agent-toggle {
  min-width: 64px;
  height: 34px;
  border-radius: 999px;
  border: 1px solid #cbd5e1;
  background: #f8fafc;
  color: #334155;
  font-weight: 700;
}
.agent-toggle.is-on { border-color: #16a34a; background: #16a34a; color: #fff; }

@media (max-width: 1024px) {
  .grid { grid-template-columns: 1fr; }
}

@media (max-width: 640px) {
  .field-grid { grid-template-columns: 1fr; }
}
</style>
