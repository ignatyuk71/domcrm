<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
  src: { type: String, required: true },
  alt: { type: String, default: 'Фото товару' },
});
const emit = defineEmits(['close']);
const dialog = ref(null);
const copied = ref(false);
const copying = ref(false);
const imageFailed = ref(false);
const error = ref('');
let previousOverflow;

onMounted(() => {
  previousOverflow = document.body.style.overflow;
  document.body.style.overflow = 'hidden';
  dialog.value.showModal();
});

onBeforeUnmount(() => {
  dialog.value?.close();
  document.body.style.overflow = previousOverflow;
});

function imageAsPng() {
  return new Promise((resolve, reject) => {
    const image = new Image();
    image.crossOrigin = 'anonymous';
    image.onload = () => {
      try {
        // Буфер обміну приймає PNG; зберігаємо оригінальний розмір фото.
        const canvas = document.createElement('canvas');
        canvas.width = image.naturalWidth;
        canvas.height = image.naturalHeight;
        const context = canvas.getContext('2d');
        if (!context) throw new Error('Canvas недоступний');
        context.drawImage(image, 0, 0);
        canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('Не вдалося створити PNG')), 'image/png');
      } catch (cause) {
        reject(cause);
      }
    };
    image.onerror = () => reject(new Error('Не вдалося завантажити фото для копіювання'));
    image.src = props.src;
  });
}

async function copyImage() {
  if (copying.value || imageFailed.value) return;
  error.value = '';
  copied.value = false;
  if (!navigator.clipboard?.write || typeof ClipboardItem === 'undefined') {
    error.value = 'Копіювання зображень недоступне. Відкрийте CRM через HTTPS у браузері з підтримкою цієї функції.';
    return;
  }
  copying.value = true;
  try {
    // Викликаємо запис одразу після кліку, щоб зберегти дозвіл користувацького жесту.
    const png = imageAsPng();
    void png.catch(() => {});
    await navigator.clipboard.write([new ClipboardItem({ 'image/png': png })]);
    copied.value = true;
  } catch {
    error.value = 'Не вдалося скопіювати фото. Перевірте дозвіл браузера на буфер обміну та доступність зображення.';
  } finally {
    copying.value = false;
  }
}
</script>

<template>
  <Teleport to="body">
    <dialog ref="dialog" class="product-photo-modal" aria-label="Перегляд фото товару" @cancel.prevent="emit('close')" @click.self="emit('close')" @click.stop>
      <div class="photo-content">
        <div class="photo-toolbar">
          <button type="button" class="btn btn-sm d-inline-flex align-items-center gap-2" :class="copied ? 'btn-success' : 'btn-outline-secondary'" :disabled="copying || imageFailed" :aria-busy="copying" @click="copyImage">
            <span v-if="copying" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
            <i v-else class="bi" :class="copied ? 'bi-check-lg' : 'bi-clipboard'" aria-hidden="true"></i>
            <span aria-live="polite">{{ copied ? 'Скопійовано' : 'Копіювати' }}</span>
          </button>
          <button type="button" class="btn-close" aria-label="Закрити фото" @click="emit('close')"></button>
        </div>
        <div class="photo-preview">
          <p v-if="imageFailed" class="text-muted p-4 text-center" role="alert">Не вдалося завантажити фото.</p>
          <img v-else :src="src" :alt="alt" @error="imageFailed = true" />
        </div>
        <p v-if="error" class="photo-error text-danger small" role="alert">{{ error }}</p>
      </div>
    </dialog>
  </Teleport>
</template>

<style scoped>
.product-photo-modal { width: min(500px, calc(100vw - 32px), calc(100dvh - 140px)); max-width: none; max-height: calc(100dvh - 32px); margin: auto; padding: 0; border: 0; border-radius: 12px; color: #0f172a; background: #fff; box-shadow: 0 24px 80px #0f172a40; }
.product-photo-modal::backdrop { background: rgb(15 23 42 / 65%); }
.photo-toolbar { display: flex; align-items: center; justify-content: flex-end; gap: 16px; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; }
.photo-toolbar .btn-close { margin: 0; flex-shrink: 0; }
.photo-preview { width: 100%; aspect-ratio: 1; display: flex; align-items: center; justify-content: center; }
.photo-preview img { width: 100%; height: 100%; object-fit: contain; }
.photo-error { padding: 12px 16px; margin: 0; border-top: 1px solid #e2e8f0; }
</style>
