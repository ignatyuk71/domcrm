import axios from 'axios';

const storageKey = (runId) => `packing:deferred:${runId}`;

export function readDeferredRun(runId) {
  if (!runId) throw new Error('Прохід відкладених замовлень недоступний. Поверніться до списку.');
  const run = JSON.parse(sessionStorage.getItem(storageKey(runId)) || 'null');
  if (!run || !Array.isArray(run.remainingIds) || !run.results) {
    throw new Error('Прохід відкладених замовлень недоступний. Поверніться до списку.');
  }
  return run;
}

function saveRun(runId, run) {
  sessionStorage.setItem(storageKey(runId), JSON.stringify(run));
}

export function createDeferredRun(orders) {
  const runId = crypto.randomUUID();
  // Знімок черги не поповнюється: повторно відкладені не повертаються в цей прохід.
  const remainingIds = [...new Set(orders
    .filter((order) => order.packing_status === 'skipped')
    .map((order) => Number(order.id)))];
  saveRun(runId, { remainingIds, activeId: null, activeSessionId: null, results: {} });
  return runId;
}

export async function startNextDeferredOrder(runId) {
  const run = readDeferredRun(runId);
  if (run.activeId) return deferredOrderUrl(run.activeId, runId);

  while (run.remainingIds.length) {
    const orderId = run.remainingIds[0];
    let sessionId;
    try {
      // Перевірку актуального стану і захоплення виконує сервер в одній транзакції.
      const { data } = await axios.post(`/packing/${orderId}/start`, { queue: 'skipped' });
      sessionId = data?.packing_session_id || null;
    } catch (error) {
      if (![404, 409, 423].includes(error.response?.status)) throw error;
      run.remainingIds.shift();
      saveRun(runId, run);
      continue;
    }

    run.remainingIds.shift();
    run.activeId = orderId;
    run.activeSessionId = sessionId;
    saveRun(runId, run);
    return deferredOrderUrl(orderId, runId);
  }

  return null;
}

export function completeDeferredOrder(runId, orderId, result) {
  const run = readDeferredRun(runId);
  if (Number(run.activeId) !== Number(orderId)) {
    throw new Error('Це замовлення вже опрацьоване в поточному проході.');
  }
  run.results[orderId] = result;
  run.activeId = null;
  run.activeSessionId = null;
  saveRun(runId, run);
}

export function deferredRunStats(runId) {
  const results = Object.values(readDeferredRun(runId).results);
  return {
    packed: results.filter((result) => result === 'packed').length,
    deferred: results.filter((result) => result === 'skipped').length,
  };
}

export function disposeDeferredRun(runId) {
  sessionStorage.removeItem(storageKey(runId));
}

function deferredOrderUrl(orderId, runId) {
  return `/packing/${orderId}?queue=skipped&run=${encodeURIComponent(runId)}`;
}
