import { computed, onBeforeUnmount, onMounted, reactive, readonly } from 'vue';
import { fetchFiscalAvailability } from '@/crm/api/fiscal';

const initialState = () => ({ available: false, shift_status: 'UNKNOWN', code: null, message: 'Перевіряємо стан каси…' });
const state = reactive(initialState());
const consumers = new Set();
const pollInterval = 60_000;
let timer = null;
let inFlight = null;
let generation = 0;
let lastError = '';

function notifyError(message, retry = false) {
  if (!retry && message === lastError) return;
  lastError = message;
  const consumer = [...consumers].find((item) => item.onError);
  consumer?.onError(message, () => refresh({ retry: true }));
}

function applyAvailability(payload = {}) {
  const opened = payload.available === true && payload.shift_status === 'OPENED' && !payload.code;
  Object.assign(state, {
    available: opened,
    shift_status: payload.shift_status || 'UNKNOWN',
    code: opened ? null : payload.code || 'fiscal_shift_unavailable',
    message: opened ? '' : payload.message || 'Не вдалося перевірити стан каси. Повторіть перевірку.',
  });
  if (state.code !== 'fiscal_shift_unavailable') lastError = '';
}

function refresh({ retry = false } = {}) {
  // Один запит обслуговує всю таблицю та відкриту картку замовлення.
  if (inFlight) return inFlight;
  const currentGeneration = generation;
  const request = Promise.resolve().then(async () => {
    try {
      const { data } = await fetchFiscalAvailability();
      if (currentGeneration !== generation) return;
      applyAvailability(data);
      if (state.code === 'fiscal_shift_unavailable') notifyError(state.message, retry);
    } catch (error) {
      if (currentGeneration !== generation) return;
      applyAvailability({
        code: 'fiscal_shift_unavailable',
        message: error.response?.data?.message || 'Не вдалося перевірити стан каси. Повторіть перевірку.',
      });
      notifyError(state.message, retry);
    } finally {
      if (inFlight === request) inFlight = null;
    }
  });
  inFlight = request;
  return request;
}

function refreshVisible() {
  if (document.visibilityState !== 'hidden') return refresh();
}

function start() {
  timer = window.setInterval(refreshVisible, pollInterval);
  window.addEventListener('focus', refreshVisible);
  document.addEventListener('visibilitychange', refreshVisible);
  refresh();
}

function stop() {
  window.clearInterval(timer);
  timer = null;
  window.removeEventListener('focus', refreshVisible);
  document.removeEventListener('visibilitychange', refreshVisible);
  // Пізня відповідь зі сторінки, яку закрили, не повинна дозволити нову дію.
  generation += 1;
  inFlight = null;
  lastError = '';
  Object.assign(state, initialState());
}

export function useFiscalAvailability({ onError = null } = {}) {
  const consumer = { onError };
  onMounted(() => {
    consumers.add(consumer);
    if (consumers.size === 1) start();
  });
  onBeforeUnmount(() => {
    consumers.delete(consumer);
    if (!consumers.size) stop();
  });

  function blockFromResponse(payload) {
    if (!['fiscal_shift_closed', 'fiscal_shift_unavailable'].includes(payload?.code)) return;
    applyAvailability({ ...payload, available: false });
  }

  return {
    availability: readonly(state),
    available: computed(() => state.available),
    disabledReason: computed(() => state.available ? '' : state.message),
    refresh,
    blockFromResponse,
  };
}
