<template>
  <section v-if="aiState" class="ai-widget">
    <div class="ai-widget-head">
      <div class="ai-widget-title">
        <span class="ai-widget-eyebrow">AI</span>
        <strong>{{ aiModeTitle }}</strong>
      </div>

      <span class="ai-status-pill" :class="aiStatusClass">
        {{ aiStatusLabel }}
      </span>
    </div>

    <div v-if="aiAlertText" class="ai-alert" :class="aiAlertClass">
      {{ aiAlertText }}
    </div>

    <div v-else class="ai-summary-row">
      <span class="ai-row-label">Суть</span>
      <p>{{ aiSummaryText }}</p>
    </div>

    <div class="ai-controls">
      <button
        type="button"
        class="ai-switch"
        :class="{ 'is-active': aiEnabled }"
        :aria-pressed="aiEnabled ? 'true' : 'false'"
        @click="toggleAi"
      >
        <span class="ai-switch-track">
          <span class="ai-switch-thumb"></span>
        </span>
        <span class="ai-switch-copy">
          <strong>{{ aiEnabled ? 'AI увімкнено' : 'AI вимкнено' }}</strong>
          <small>{{ aiEnabled ? 'Відповідає першим' : 'AI не відповідає' }}</small>
        </span>
      </button>
    </div>

    <div v-if="aiLeadRows.length" class="ai-dropdown-wrap">
      <button
        type="button"
        class="ai-dropdown-trigger"
        :class="{ 'is-open': showLeadFields }"
        @click="showLeadFields = !showLeadFields"
      >
        <span>Кваліфікація</span>
        <div class="ai-dropdown-meta">
          <strong>{{ aiLeadRows.length }}</strong>
          <i class="bi bi-chevron-down"></i>
        </div>
      </button>

      <transition name="ai-expand">
        <div v-if="showLeadFields" class="ai-dropdown-panel">
          <div
            v-for="item in aiLeadRows"
            :key="item.key"
            class="ai-field-row"
          >
            <span>{{ item.label }}</span>
            <strong>{{ item.value }}</strong>
          </div>
        </div>
      </transition>
    </div>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  conversation: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['toggle-ai', 'takeover-ai']);
const showLeadFields = ref(false);

const aiState = computed(() => props.conversation?.ai || null);
const aiEnabled = computed(() => Boolean(aiState.value?.enabled));
const aiAvailable = computed(() => Boolean(aiState.value?.available));
const aiSystemEnabled = computed(() => aiState.value?.system_enabled !== false);
const aiSummary = computed(() => compactText(aiState.value?.summary || ''));
const aiHandoffReason = computed(() => compactText(aiState.value?.handoff_reason || ''));
const aiLastError = computed(() => compactText(aiState.value?.last_error || ''));

const aiModeTitle = computed(() => {
  const status = aiState.value?.status;

  if (status === 'manual') {
    return 'Чат у менеджера';
  }

  if (status === 'paused' || status === 'disabled') {
    return 'AI зупинений';
  }

  if (status === 'handoff') {
    return 'Потрібен менеджер';
  }

  return 'AI веде перший контакт';
});

const aiSummaryText = computed(() => {
  if (aiSummary.value) {
    return aiSummary.value;
  }

  const status = aiState.value?.status;

  if (status === 'manual') {
    return 'Далі відповідає менеджер.';
  }

  if (status === 'paused' || status === 'disabled') {
    return 'AI не відповідає на нові повідомлення.';
  }

  if (status === 'handoff') {
    return 'AI зібрав запит і очікує менеджера.';
  }

  return 'AI відповідає першим і передає чат менеджеру, коли це потрібно.';
});

const aiStatusLabel = computed(() => {
  const map = {
    idle: 'Активний',
    queued: 'Нове',
    processing: 'Обробка',
    replied: 'Відповів',
    handoff: 'Передати',
    manual: 'Менеджер',
    paused: 'Пауза',
    disabled: 'Вимкнено',
    error: 'Помилка',
    not_configured: 'Без ключа',
  };

  return map[aiState.value?.status] || 'Активний';
});

const aiStatusClass = computed(() => `is-${aiState.value?.status || 'idle'}`);

const aiAlertText = computed(() => {
  if (!aiSystemEnabled.value) {
    return 'AI вимкнений у системі.';
  }

  if (!aiAvailable.value) {
    return 'Не додано ключ OpenAI.';
  }

  if (aiHandoffReason.value) {
    return aiHandoffReason.value;
  }

  if (aiLastError.value) {
    if (aiLastError.value.includes('429') || aiLastError.value.toLowerCase().includes('quota')) {
      return 'OpenAI тимчасово недоступний: немає квоти.';
    }

    return aiLastError.value;
  }

  return '';
});

const aiAlertClass = computed(() => {
  if (!aiSystemEnabled.value || aiHandoffReason.value) {
    return 'is-warning';
  }

  return 'is-error';
});

const aiLeadRows = computed(() => {
  const lead = aiState.value?.lead || {};

  return [
    { key: 'customer_name', label: 'Імʼя', value: lead.customer_name || '' },
    { key: 'phone', label: 'Телефон', value: lead.phone || '' },
    { key: 'product_interest', label: 'Інтерес', value: lead.product_interest || '' },
    { key: 'budget', label: 'Бюджет', value: lead.budget || '' },
    { key: 'timeline', label: 'Термін', value: lead.timeline || '' },
    { key: 'city', label: 'Місто', value: lead.city || '' },
    { key: 'notes', label: 'Нотатка', value: lead.notes || '' },
  ]
    .map((item) => ({ ...item, value: compactText(item.value) }))
    .filter((item) => item.value);
});

function compactText(value) {
  return String(value || '').replace(/\s+/g, ' ').trim();
}

function toggleAi() {
  if (!props.conversation?.conversation_id) {
    return;
  }

  emit('toggle-ai', {
    conversationId: props.conversation.conversation_id,
    enabled: !aiEnabled.value,
  });
}
</script>

<style scoped>
.ai-widget {
  margin-top: 18px;
  padding: 14px;
  border: 1px solid #dbe4f0;
  border-radius: 18px;
  background: #ffffff;
  box-shadow: 0 16px 32px -28px rgba(15, 23, 42, 0.38);
}

.ai-widget-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.ai-widget-title {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.ai-widget-eyebrow {
  font-size: 11px;
  line-height: 1;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #2563eb;
}

.ai-widget-title strong {
  font-size: 18px;
  line-height: 1.2;
  font-weight: 800;
  color: #0f172a;
}

.ai-status-pill {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 28px;
  padding: 0 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 800;
}

.ai-status-pill.is-idle,
.ai-status-pill.is-replied {
  background: #dcfce7;
  color: #166534;
}

.ai-status-pill.is-queued,
.ai-status-pill.is-processing {
  background: #e0f2fe;
  color: #0c4a6e;
}

.ai-status-pill.is-handoff {
  background: #fef3c7;
  color: #92400e;
}

.ai-status-pill.is-manual,
.ai-status-pill.is-paused,
.ai-status-pill.is-disabled {
  background: #e5e7eb;
  color: #374151;
}

.ai-status-pill.is-error,
.ai-status-pill.is-not_configured {
  background: #fee2e2;
  color: #991b1b;
}

.ai-summary-row,
.ai-alert {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid #eef2f7;
}

.ai-row-label {
  display: block;
  margin-bottom: 6px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #64748b;
}

.ai-summary-row p {
  margin: 0;
  font-size: 14px;
  line-height: 1.45;
  color: #475569;
}

.ai-alert {
  font-size: 13px;
  line-height: 1.45;
  font-weight: 600;
}

.ai-alert.is-warning {
  color: #92400e;
}

.ai-alert.is-error {
  color: #991b1b;
}

.ai-controls {
  margin-top: 14px;
}

.ai-switch {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  padding: 14px 16px;
  border: 1px solid #dbe4f0;
  border-radius: 16px;
  background: #f8fafc;
  text-align: left;
  transition: border-color 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
}

.ai-switch:hover {
  border-color: #bfdbfe;
  box-shadow: 0 12px 24px -22px rgba(37, 99, 235, 0.38);
}

.ai-switch.is-active {
  border-color: #bbf7d0;
  background: #f0fdf4;
}

.ai-switch-track {
  position: relative;
  flex-shrink: 0;
  width: 46px;
  height: 28px;
  border-radius: 999px;
  background: #cbd5e1;
  transition: background-color 0.18s ease;
}

.ai-switch-thumb {
  position: absolute;
  top: 3px;
  left: 3px;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: #ffffff;
  box-shadow: 0 1px 4px rgba(15, 23, 42, 0.2);
  transition: transform 0.18s ease;
}

.ai-switch.is-active .ai-switch-track {
  background: #22c55e;
}

.ai-switch.is-active .ai-switch-thumb {
  transform: translateX(18px);
}

.ai-switch-copy {
  min-width: 0;
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  gap: 2px;
}

.ai-switch-copy strong {
  font-size: 14px;
  line-height: 1.3;
  font-weight: 800;
  color: #0f172a;
}

.ai-switch-copy small {
  font-size: 12px;
  line-height: 1.35;
  color: #64748b;
}

.ai-dropdown-wrap {
  margin-top: 12px;
}

.ai-dropdown-trigger {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 14px;
  border: 1px solid #dbe4f0;
  border-radius: 14px;
  background: #ffffff;
  font-size: 13px;
  font-weight: 700;
  color: #334155;
  transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

.ai-dropdown-trigger:hover {
  border-color: #bfdbfe;
}

.ai-dropdown-trigger.is-open {
  border-color: #bfdbfe;
  box-shadow: 0 0 0 4px rgba(191, 219, 254, 0.18);
}

.ai-dropdown-meta {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  color: #64748b;
}

.ai-dropdown-meta strong {
  min-width: 18px;
  text-align: center;
  font-size: 12px;
  font-weight: 800;
  color: #2563eb;
}

.ai-dropdown-meta i {
  font-size: 12px;
  transition: transform 0.18s ease;
}

.ai-dropdown-trigger.is-open .ai-dropdown-meta i {
  transform: rotate(180deg);
}

.ai-dropdown-panel {
  margin-top: 10px;
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  background: #f8fafc;
  overflow: hidden;
}

.ai-field-row {
  display: grid;
  grid-template-columns: 82px minmax(0, 1fr);
  gap: 10px;
  padding: 11px 14px;
  border-top: 1px solid #e5edf6;
}

.ai-field-row:first-child {
  border-top: none;
}

.ai-field-row span {
  font-size: 12px;
  font-weight: 700;
  color: #64748b;
}

.ai-field-row strong {
  min-width: 0;
  font-size: 14px;
  line-height: 1.35;
  font-weight: 700;
  color: #0f172a;
  word-break: break-word;
}

.ai-expand-enter-active,
.ai-expand-leave-active {
  transition: all 0.2s ease;
}

.ai-expand-enter-from,
.ai-expand-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}

.ai-expand-enter-to,
.ai-expand-leave-from {
  opacity: 1;
  transform: translateY(0);
}

@media (max-width: 768px) {
  .ai-widget-head {
    display: grid;
    grid-template-columns: 1fr;
  }

  .ai-status-pill {
    justify-self: start;
  }

  .ai-switch {
    align-items: flex-start;
  }

  .ai-field-row {
    grid-template-columns: 1fr;
    gap: 4px;
  }
}
</style>
