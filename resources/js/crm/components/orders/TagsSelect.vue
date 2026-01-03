<template>
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-bold">Теги</div>
    <div class="card-body d-flex flex-wrap gap-2">
      <label v-for="tag in tags" :key="tag.id" class="btn btn-outline-secondary btn-sm rounded-pill d-flex align-items-center gap-2">
        <input type="checkbox" class="form-check-input m-0" :value="tag.id" v-model="modelValue" />
        <span class="small">{{ tag.name }}</span>
      </label>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { fetchTags } from '@/crm/api/tags';

const modelValue = defineModel({ type: Array, default: () => [] });
const tags = ref([]);

onMounted(async () => {
  try {
    const { data } = await fetchTags();
    tags.value = data?.data || data || [];
  } catch (e) {
    console.error(e);
  }
});
</script>
