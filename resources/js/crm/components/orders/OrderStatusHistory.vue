<template>
  <section v-if="orderId" class="clean-card status-history">
    <div class="d-flex align-items-center justify-content-between gap-3">
      <button
        type="button"
        class="btn p-0 text-start d-flex align-items-center gap-2 fw-semibold"
        :aria-expanded="expanded"
        :aria-controls="`order-status-history-${orderId}`"
        @click="toggle"
      >
        <i class="bi bi-clock-history text-primary" aria-hidden="true"></i>
        Історія статусів
        <i :class="expanded ? 'bi bi-chevron-up' : 'bi bi-chevron-down'" class="small text-muted" aria-hidden="true"></i>
      </button>
      <button v-if="expanded" type="button" class="btn btn-sm btn-light" :disabled="loading" @click="load(1)">
        <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Оновити
      </button>
    </div>

    <div v-if="expanded" :id="`order-status-history-${orderId}`" class="mt-3" :aria-busy="loading">
      <p class="small text-muted mb-3">Хто, коли й чому змінив статус замовлення. Час за Києвом.</p>

      <div v-if="entries.length" class="table-responsive">
        <table class="table align-middle small mb-0">
          <thead class="text-muted">
            <tr>
              <th scope="col">Коли</th>
              <th scope="col">Зміна статусу</th>
              <th scope="col">Хто / джерело</th>
              <th scope="col">Причина</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in entries" :key="entry.id">
              <td class="text-nowrap"><time :datetime="entry.occurred_at">{{ formatOccurredAt(entry.occurred_at) }}</time></td>
              <td>
                <div class="d-flex align-items-center flex-wrap gap-2 status-change">
                  <span class="text-muted">{{ statusName(entry, 'old') }}</span>
                  <span aria-label="змінено на">→</span>
                  <span class="fw-semibold">{{ statusName(entry, 'new') }}</span>
                </div>
              </td>
              <td>
                <div>{{ entry.actor_name || (entry.actor_id ? `Користувач #${entry.actor_id}` : 'Система') }}</div>
                <div v-if="entry.source_label" class="text-muted mt-1">{{ entry.source_label }}</div>
              </td>
              <td class="history-reason">
                <div>{{ entry.reason || 'Причину не зазначено' }}</div>
                <div v-if="npSummary(entry)" class="text-muted mt-1">НП: {{ npSummary(entry) }}</div>
                <div v-if="entry.metadata?.ttn" class="text-muted mt-1">ТТН: {{ entry.metadata.ttn }}</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="error" class="alert alert-danger d-flex flex-wrap align-items-center gap-2 mt-3 mb-0 py-2 small" role="alert">
        <span>{{ error }}</span>
        <button type="button" class="btn btn-sm btn-outline-danger" :disabled="loading" @click="load(requestedPage)">
          Спробувати ще раз
        </button>
      </div>
      <div v-else-if="loaded && !entries.length && !loading" class="text-muted small bg-light rounded-3 p-3">
        Історія статусів поки порожня. Зміни фіксуються після ввімкнення журналу.
      </div>

      <div v-if="loading" class="d-flex align-items-center gap-2 py-3 text-muted small" role="status">
        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
        Завантаження історії…
      </div>
      <div v-else-if="nextPage && !error" class="text-center mt-3">
        <button type="button" class="btn btn-sm btn-outline-secondary" @click="load(nextPage)">Показати ще</button>
      </div>
    </div>
  </section>
</template>

<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import http from '@/crm/api/http';
import { DISPLAY_TIME_ZONE, statusLabels } from '@/crm/utils/orderDisplay';

const props = defineProps({
  orderId: { type: [Number, String], required: true },
});

const expanded = ref(false);
const entries = ref([]);
const loading = ref(false);
const loaded = ref(false);
const error = ref('');
const nextPage = ref(null);
const requestedPage = ref(1);
let requestId = 0;

const dateFormatter = new Intl.DateTimeFormat('uk-UA', {
  timeZone: DISPLAY_TIME_ZONE,
  day: '2-digit', month: '2-digit', year: 'numeric',
  hour: '2-digit', minute: '2-digit', second: '2-digit',
});

function formatOccurredAt(value) {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : dateFormatter.format(date);
}

function statusName(entry, side) {
  const code = entry[`${side}_status`];
  const id = entry[`${side}_status_id`];
  return entry[`${side}_status_name`] || statusLabels[code] || code || (id ? `Статус #${id}` : 'Не задано');
}

function npSummary(entry) {
  const response = entry.metadata?.np_response;
  if (!response) return '';
  return [response.StatusCode, response.Status || response.StatusDescription]
    .filter((value) => value !== null && value !== undefined && value !== '')
    .join(' — ');
}

function toggle() {
  expanded.value = !expanded.value;
  if (expanded.value && !loaded.value && !loading.value) load(1);
}

async function load(page) {
  if (!props.orderId || loading.value) return;
  const currentRequest = ++requestId;
  requestedPage.value = page;
  loading.value = true;
  error.value = '';

  try {
    const { data } = await http.get(`/orders/${encodeURIComponent(props.orderId)}/status-history`, { params: { page } });
    // Відповідь попереднього замовлення не повинна потрапити в поточний журнал.
    if (currentRequest !== requestId) return;
    if (!Array.isArray(data?.data)) throw new Error('Некоректна відповідь журналу');

    const previous = page === 1 ? [] : entries.value;
    const knownIds = new Set(previous.map((entry) => entry.id));
    entries.value = [...previous, ...data.data.filter((entry) => !knownIds.has(entry.id))];
    nextPage.value = data.next_page_url ? Number(data.current_page || page) + 1 : null;
    loaded.value = true;
  } catch {
    if (currentRequest === requestId) error.value = 'Не вдалося завантажити історію статусів.';
  } finally {
    if (currentRequest === requestId) loading.value = false;
  }
}

watch(() => props.orderId, () => {
  requestId += 1;
  expanded.value = false;
  entries.value = [];
  loading.value = false;
  loaded.value = false;
  error.value = '';
  nextPage.value = null;
  requestedPage.value = 1;
});

onBeforeUnmount(() => { requestId += 1; });
</script>

<style scoped>
.status-history {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  padding: 24px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}
.status-history th { font-weight: 600; }
.status-history th,
.status-history td { padding: 12px 10px; }
.status-change { min-width: 180px; }
.history-reason { min-width: 210px; overflow-wrap: anywhere; }
</style>
