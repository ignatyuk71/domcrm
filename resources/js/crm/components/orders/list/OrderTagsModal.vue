<template>
  <div v-if="open">
    <div class="modal-backdrop fade show"></div>
    <div class="modal fade show d-block" tabindex="-1" @click.self="$emit('close')">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-header">
            <h5 class="modal-title fw-bold">Обрати теги</h5>
            <button type="button" class="btn-close" @click="$emit('close')"></button>
          </div>
          <div class="modal-body">
            <div v-if="loading" class="text-center py-4 text-muted">
              <div class="spinner-border text-primary mb-2" role="status"></div>
              <div>Завантаження тегів…</div>
            </div>
            <div v-else class="d-flex flex-column gap-2">
              <label
                v-for="tag in availableTags"
                :key="tag.id"
                class="d-flex align-items-center justify-content-between border rounded-3 px-3 py-2 tag-check"
              >
                <div class="d-flex align-items-center gap-2">
                  <span class="tag-icon shadow-sm">
                    <i :class="'bi ' + tag.icon"></i>
                    <span class="tag-text">{{ tag.name }}</span>
                  </span>
                </div>
                <input class="form-check-input" type="checkbox" v-model="selected" :value="tag.id" />
              </label>
              <div v-if="!availableTags.length" class="text-muted small">Теги відсутні</div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-light border" type="button" @click="$emit('close')">Скасувати</button>
            <button class="btn btn-primary" type="button" @click="$emit('save')" :disabled="loading">
              Зберегти
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  availableTags: { type: Array, default: () => [] },
  modelValue: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue', 'close', 'save']);

const selected = computed({
  get: () => props.modelValue,
  set: (val) => emit('update:modelValue', val),
});

</script>

<style scoped>
.tag-icon {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 5px 8px;
  border-radius: 8px;
  border: 2px solid rgba(17, 24, 39, 0.14);
  background: rgba(255, 255, 255, 0.92);
  box-shadow: 0 10px 24px rgba(17, 24, 39, 0.08);
  font-weight: 500;
  font-size: 0.7rem;
  color: rgba(17, 24, 39, 0.9);
}
.tag-text {
  white-space: nowrap;
  font-size: 0.7rem;
}
.tag-check {
  cursor: pointer;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(245, 249, 255, 0.9));
}
.tag-check:hover {
  border-color: rgba(109, 94, 252, 0.35);
  box-shadow: 0 8px 20px rgba(17, 24, 39, 0.08);
}
</style>
