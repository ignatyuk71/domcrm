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

    <div v-if="aiLeadRows.length" class="ai-detail-list">
      <div
        v-for="item in aiLeadRows"
        :key="item.key"
        class="ai-detail-row"
      >
        <span>{{ item.label }}</span>
        <strong>{{ item.value }}</strong>
      </div>

      <div v-if="aiNotes" class="ai-detail-row">
        <span>Нотатка</span>
        <strong>{{ aiNotes }}</strong>
      </div>
    </div>

    <div class="ai-actions">
      <button
        type="button"
        class="ai-button ai-button-secondary"
        :class="{ 'is-paused': !aiEnabled }"
        @click="toggleAi"
      >
        {{ aiEnabled ? 'Пауза AI' : 'Увімкнути AI' }}
      </button>

      <button
        type="button"
        class="ai-button ai-button-primary"
        @click="takeoverAi"
      >
        Менеджер
      </button>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  conversation: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['toggle-ai', 'takeover-ai']);

const aiState = computed(() => props.conversation?.ai || null);
const aiEnabled = computed(() => Boolean(aiState.value?.enabled));
const aiAvailable = computed(() => Boolean(aiState.value?.available));
const aiSystemEnabled = computed(() => aiState.value?.system_enabled !== false);
const aiSummary = computed(() => compactText(aiState.value?.summary || ''));
const aiHandoffReason = computed(() => compactText(aiState.value?.handoff_reason || ''));
const aiLastError = computed(() => compactText(aiState.value?.last_error || ''));
const aiNotes = computed(() => compactText(aiState.value?.lead?.notes || ''));

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

function takeoverAi() {
  if (!props.conversation?.conversation_id) {
    return;
  }

  emit('takeover-ai', props.conversation.conversation_id);
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

.ai-detail-list {
  margin-top: 12px;
  border-top: 1px solid #eef2f7;
}

.ai-detail-row {
  display: grid;
  grid-template-columns: 78px minmax(0, 1fr);
  gap: 10px;
  padding: 10px 0;
  border-bottom: 1px solid #f1f5f9;
}

.ai-detail-row:last-child {
  border-bottom: none;
}

.ai-detail-row span {
  font-size: 12px;
  font-weight: 700;
  color: #64748b;
}

.ai-detail-row strong {
  min-width: 0;
  font-size: 14px;
  line-height: 1.35;
  font-weight: 700;
  color: #0f172a;
  word-break: break-word;
}

.ai-actions {
  margin-top: 14px;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.ai-button {
  min-height: 40px;
  padding: 0 12px;
  border-radius: 12px;
  font-size: 13px;
  font-weight: 700;
  transition: all 0.18s ease;
}

.ai-button-secondary {
  border: 1px solid #cbd5e1;
  background: #ffffff;
  color: #334155;
}

.ai-button-secondary.is-paused {
  border-color: #bfdbfe;
  color: #1d4ed8;
}

.ai-button-primary {
  border: 1px solid #0f172a;
  background: #0f172a;
  color: #ffffff;
}

@media (max-width: 768px) {
  .ai-widget-head,
  .ai-actions {
    display: grid;
    grid-template-columns: 1fr;
  }

  .ai-status-pill {
    justify-self: start;
  }
}
</style>
