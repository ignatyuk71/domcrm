<template>
  <section v-if="aiState" class="ai-side-card">
    <div class="ai-side-head">
      <div class="ai-side-copy">
        <div class="ai-side-title-row">
          <span class="ai-side-label">AI first line</span>
          <span class="ai-status-chip" :class="aiStatusClass">{{ aiStatusLabel }}</span>
        </div>
        <strong>Перший контакт</strong>
        <span class="ai-side-summary">
          {{ aiSummary || 'Чекає на новий діалог.' }}
        </span>
      </div>

      <div class="ai-side-actions">
        <button
          type="button"
          class="ai-action-btn"
          :class="{ 'is-paused': !aiEnabled }"
          @click="toggleAi"
        >
          {{ aiEnabled ? 'Пауза' : 'Увімк.' }}
        </button>

        <button
          type="button"
          class="ai-action-btn is-primary"
          @click="takeoverAi"
        >
          Менеджер
        </button>
      </div>
    </div>

    <div v-if="!aiSystemEnabled" class="ai-inline-note is-warning">
      AI вимкнений у системі.
    </div>

    <div v-else-if="!aiAvailable" class="ai-inline-note is-error">
      Ключ OpenAI не додано.
    </div>

    <div v-else-if="aiHandoffReason" class="ai-inline-note is-warning">
      {{ aiHandoffReason }}
    </div>

    <div v-else-if="aiLastError" class="ai-inline-note is-error">
      {{ aiLastError }}
    </div>

    <div v-if="aiLeadItems.length" class="ai-lead-grid">
      <div
        v-for="item in aiLeadItems"
        :key="item.key"
        class="ai-lead-item"
      >
        <span>{{ item.label }}</span>
        <strong>{{ item.value }}</strong>
      </div>
    </div>

    <div v-if="aiNotes" class="ai-notes">
      <span>Нотатки</span>
      <strong>{{ aiNotes }}</strong>
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
const aiSummary = computed(() => String(aiState.value?.summary || '').trim());
const aiHandoffReason = computed(() => String(aiState.value?.handoff_reason || '').trim());
const aiLastError = computed(() => String(aiState.value?.last_error || '').trim());
const aiNotes = computed(() => String(aiState.value?.lead?.notes || '').trim());
const aiStatusLabel = computed(() => {
  const map = {
    idle: 'Готовий',
    queued: 'У черзі',
    processing: 'Обробка',
    replied: 'Відповів',
    handoff: 'Передати',
    manual: 'У менеджера',
    paused: 'Пауза',
    disabled: 'Вимкнено',
    error: 'Помилка',
    not_configured: 'Без ключа',
  };

  return map[aiState.value?.status] || 'Готовий';
});
const aiStatusClass = computed(() => `is-${aiState.value?.status || 'idle'}`);
const aiLeadItems = computed(() => {
  const lead = aiState.value?.lead || {};

  return [
    { key: 'customer_name', label: 'Імʼя', value: lead.customer_name || '' },
    { key: 'phone', label: 'Телефон', value: lead.phone || '' },
    { key: 'product_interest', label: 'Інтерес', value: lead.product_interest || '' },
    { key: 'budget', label: 'Бюджет', value: lead.budget || '' },
    { key: 'timeline', label: 'Термін', value: lead.timeline || '' },
    { key: 'city', label: 'Місто', value: lead.city || '' },
  ].filter((item) => String(item.value || '').trim());
});

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
.ai-side-card {
  margin-top: 18px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px;
  border-radius: 18px;
  border: 1px solid #dbeafe;
  background: linear-gradient(180deg, #f8fbff 0%, #eef6ff 100%);
}

.ai-side-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.ai-side-copy {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.ai-side-title-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.ai-side-label {
  font-size: 11px;
  line-height: 1.2;
  font-weight: 800;
  color: #2563eb;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.ai-side-copy strong {
  font-size: 16px;
  line-height: 1.3;
  color: #0f172a;
}

.ai-side-summary {
  font-size: 13px;
  line-height: 1.45;
  color: #475569;
}

.ai-side-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.ai-status-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 24px;
  padding: 0 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 800;
}

.ai-status-chip.is-idle,
.ai-status-chip.is-replied {
  background: #dcfce7;
  color: #166534;
}

.ai-status-chip.is-queued,
.ai-status-chip.is-processing {
  background: #e0f2fe;
  color: #0c4a6e;
}

.ai-status-chip.is-handoff {
  background: #fef3c7;
  color: #92400e;
}

.ai-status-chip.is-disabled,
.ai-status-chip.is-manual,
.ai-status-chip.is-paused {
  background: #e5e7eb;
  color: #374151;
}

.ai-status-chip.is-error,
.ai-status-chip.is-not_configured {
  background: #fee2e2;
  color: #991b1b;
}

.ai-action-btn {
  min-height: 34px;
  padding: 0 12px;
  border-radius: 10px;
  border: 1px solid #bfdbfe;
  background: #ffffff;
  color: #1d4ed8;
  font-size: 12px;
  font-weight: 700;
}

.ai-action-btn.is-paused {
  border-color: #d1d5db;
  color: #374151;
}

.ai-action-btn.is-primary {
  border-color: #0f172a;
  background: #0f172a;
  color: #ffffff;
}

.ai-inline-note {
  padding: 9px 10px;
  border-radius: 10px;
  font-size: 12px;
  line-height: 1.45;
  font-weight: 600;
}

.ai-inline-note.is-warning {
  background: #fffbeb;
  color: #92400e;
  border: 1px solid #fde68a;
}

.ai-inline-note.is-error {
  background: #fef2f2;
  color: #991b1b;
  border: 1px solid #fecaca;
}

.ai-lead-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.ai-lead-item,
.ai-notes {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 10px 11px;
  border-radius: 12px;
  border: 1px solid rgba(191, 219, 254, 0.9);
  background: rgba(255, 255, 255, 0.82);
}

.ai-lead-item span,
.ai-notes span {
  font-size: 10px;
  line-height: 1.2;
  font-weight: 800;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.ai-lead-item strong,
.ai-notes strong {
  font-size: 13px;
  line-height: 1.35;
  color: #0f172a;
}

@media (max-width: 768px) {
  .ai-side-head {
    flex-direction: column;
  }

  .ai-side-actions {
    width: 100%;
    justify-content: stretch;
  }

  .ai-action-btn {
    flex: 1 1 0;
  }
}
</style>
