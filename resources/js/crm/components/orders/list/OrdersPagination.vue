<template>
  <div class="card-footer bg-white border-top py-3">
    <nav class="d-flex justify-content-center">
      <ul class="pagination pagination-sm mb-0">
        <li class="page-item" :class="{ disabled: meta.current_page === 1 }">
          <button class="page-link border-0 bg-transparent text-secondary" @click="$emit('change-page', meta.current_page - 1)">
            <i class="bi bi-chevron-left"></i>
          </button>
        </li>
        <li class="page-item disabled">
          <span class="page-link border-0 bg-transparent text-dark fw-medium">
            {{ meta.current_page }} / {{ meta.last_page }}
          </span>
        </li>
        <li class="page-item" :class="{ disabled: meta.current_page === meta.last_page }">
          <button class="page-link border-0 bg-transparent text-secondary" @click="$emit('change-page', meta.current_page + 1)">
            <i class="bi bi-chevron-right"></i>
          </button>
        </li>
      </ul>
    </nav>
  </div>
</template>

<script setup>
defineProps({
  meta: {
    type: Object,
    required: true,
  },
});

defineEmits(['change-page']);
</script>
