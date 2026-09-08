import { onBeforeUnmount, ref } from 'vue';

export function useTtnCopy() {
  const copiedTtn = ref('');
  let timer;
  let requestId = 0;
  let disposed = false;

  function reset() {
    clearTimeout(timer);
    timer = undefined;
    copiedTtn.value = '';
  }

  async function copyTtn(ttn) {
    const currentRequest = ++requestId;
    reset();
    const value = ttn == null ? '' : String(ttn);
    if (disposed || !value.trim()) return false;

    try {
      if (typeof navigator?.clipboard?.writeText !== 'function') return false;
      await navigator.clipboard.writeText(value);
      // Запізніла відповідь не змінює результат останнього натискання.
      if (disposed || currentRequest !== requestId) return false;
      copiedTtn.value = value;
      timer = setTimeout(reset, 2000);
      return true;
    } catch {
      return false;
    }
  }

  onBeforeUnmount(() => {
    disposed = true;
    requestId++;
    reset();
  });

  return { copiedTtn, copyTtn };
}
