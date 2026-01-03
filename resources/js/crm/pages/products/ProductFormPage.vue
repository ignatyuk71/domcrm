<template>
  <div class="container py-5">
    <div class="mx-auto" style="max-width: 1140px;">

      <!-- Header -->
      <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-3 mb-4">
        <div>
          <h1 class="h3 mb-1 fw-bold text-dark">
            {{ isEdit ? 'Редагування товару' : 'Додавання товару' }}
          </h1>
          <p class="text-muted mb-0">Заповніть інформацію про товар для каталогу.</p>
        </div>
        <div class="d-flex gap-2">
          <a href="/products" class="btn btn-light border">
            Скасувати
          </a>
          <button type="button" @click="openPreview" class="btn btn-outline-primary">
            👁️ Попередній перегляд
          </button>
          <button @click="handleSubmit" :disabled="loading" class="btn btn-primary d-flex align-items-center gap-2">
            <span v-if="loading" class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            <span>{{ loading ? 'Збереження...' : 'Зберегти товар' }}</span>
          </button>
        </div>
      </div>

      <form @submit.prevent="handleSubmit" class="row g-4">

        <!-- Left Column -->
        <div class="col-lg-8">

          <!-- Basic Info Card -->
          <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
              <h5 class="card-title fw-bold mb-4">Основна інформація</h5>
              <div class="mb-3">
                <label class="form-label">Назва товару</label>
                <input
                  v-model.trim="form.title"
                  type="text"
                  class="form-control"
                  :class="{ 'is-invalid': errors.title }"
                  placeholder="Наприклад: Кросівки Nike Air"
                >
                <div v-if="errors.title" class="invalid-feedback">{{ errors.title }}</div>
              </div>

              <div class="mb-3">
                <label class="form-label">Опис</label>
                <textarea
                  v-model.trim="form.description"
                  rows="4"
                  class="form-control"
                  placeholder="Детальний опис товару..."
                ></textarea>
              </div>

              <div class="mb-3">
                <label class="form-label">Категорія</label>
                <select
                  v-model="form.category"
                  class="form-select"
                >
                  <option disabled value="">Виберіть категорію</option>
                  <option>Пухнасті тапки</option>
                  <option>Тапки дитячі</option>
                  <option>Капці (непухнасті)</option>
                  <option>Аксесуари</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Pricing Card -->
          <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
              <h5 class="card-title fw-bold mb-4">Ціноутворення</h5>
              <div class="row g-3">
                <div class="col-sm-4">
                  <label class="form-label">Валюта</label>
                  <select
                    v-model="form.currency"
                    class="form-select"
                  >
                    <option value="UAH">UAH (₴)</option>
                    <option value="USD">USD ($)</option>
                    <option value="EUR">EUR (€)</option>
                    <option value="PLN">PLN (zł)</option>
                  </select>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Закупівельна ціна</label>
                  <div class="input-group">
                    <input
                      v-model.number="form.cost_price"
                      type="number" min="0" step="0.01"
                      class="form-control"
                      placeholder="0.00"
                    >
                    <span class="input-group-text">{{ form.currency }}</span>
                  </div>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Ціна продажу</label>
                  <div class="input-group">
                    <input
                      v-model.number="form.sale_price"
                      type="number" min="0" step="0.01"
                      class="form-control"
                      placeholder="0.00"
                    >
                    <span class="input-group-text">{{ form.currency }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Inventory Card -->
          <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
              <h5 class="card-title fw-bold mb-4">Склад та артикул</h5>
              <div class="row g-3">
                <div class="col-sm-4">
                  <label class="form-label">SKU (Артикул)</label>
                  <input
                    v-model.trim="form.sku"
                    type="text"
                    class="form-control"
                  >
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Кількість</label>
                  <input
                    v-model.number="form.stock_qty"
                    type="number" min="0" step="1"
                    class="form-control"
                  >
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Мін. залишок</label>
                  <input
                    v-model.number="form.min_stock"
                    type="number" min="0" step="1"
                    class="form-control"
                  >
                </div>
              </div>
            </div>
          </div>

        </div>

        <!-- Right Column -->
        <div class="col-lg-4">

          <!-- Media Card -->
          <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
              <h5 class="card-title fw-bold mb-4">Медіа</h5>
              <div
                class="border rounded-3 p-4 text-center cursor-pointer bg-light"
                :class="{'border-primary bg-primary-subtle': previewUrl}"
                style="border-style: dashed !important;"
                @click="pickFile"
                @dragover.prevent
                @drop.prevent="onFileDrop"
              >
                <div v-if="previewUrl" class="position-relative mb-3">
                  <img :src="previewUrl" class="img-fluid rounded" style="max-height: 200px; object-fit: contain;">
                  <button @click.stop="removeImage" type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1 rounded-circle p-1 lh-1">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                  </button>
                </div>
                <div v-else>
                  <div class="mb-3 text-muted">
                    <svg class="mx-auto" width="48" height="48" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                      <path fill-rule="evenodd" d="M1.5 6a2.25 2.25 0 012.25-2.25h16.5A2.25 2.25 0 0122.5 6v12a2.25 2.25 0 01-2.25 2.25H3.75A2.25 2.25 0 011.5 18V6zM3 16.06V18c0 .414.336.75.75.75h16.5A.75.75 0 0021 18v-1.94l-2.69-2.689a1.5 1.5 0 00-2.12 0l-.88.879.97.97a.75.75 0 11-1.06 1.06l-5.16-5.159a1.5 1.5 0 00-2.12 0L3 16.061zm10.125-7.81a1.125 1.125 0 112.25 0 1.125 1.125 0 01-2.25 0z" clip-rule="evenodd" />
                    </svg>
                  </div>
                  <div class="text-primary fw-semibold">Завантажити фото</div>
                  <div class="small text-muted">PNG, JPG, GIF до 10MB</div>
                </div>
                <input ref="fileInput" type="file" class="d-none" accept="image/*" @change="onFileChange">
              </div>
            </div>
          </div>

          <!-- Dimensions Card -->
          <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
              <h5 class="card-title fw-bold mb-4">Габарити</h5>
              <div class="vstack gap-3">
                <div v-for="dim in dimensions" :key="dim.key">
                  <label class="form-label small text-muted mb-1">{{ dim.label }}</label>
                  <div class="input-group input-group-sm">
                    <input
                      v-model.number="form[dim.key]"
                      type="number" min="0" :step="dim.step"
                      class="form-control"
                    >
                    <span class="input-group-text">{{ dim.unit }}</span>
                  </div>
                </div>
              </div>
              <div class="form-text mt-3">Використовується для розрахунку доставки.</div>
            </div>
          </div>

        </div>
      </form>
    </div>

    <!-- Preview Modal -->
    <div v-if="showPreview" class="modal fade show" style="display: block; background-color: rgba(0,0,0,0.5);" tabindex="-1" role="dialog">
      <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Попередній перегляд</h5>
            <button type="button" class="btn-close" @click="closePreview"></button>
          </div>
          <div class="modal-body">
            <div class="row">
              <div class="col-md-6 text-center mb-3 mb-md-0">
                <div class="bg-light rounded d-flex align-items-center justify-content-center" style="height: 300px;">
                  <img v-if="previewUrl" :src="previewUrl" class="img-fluid" style="max-height: 100%; object-fit: contain;">
                  <span v-else class="text-muted">Немає фото</span>
                </div>
              </div>
              <div class="col-md-6">
                <h4>{{ pv.title }}</h4>
                <p class="text-muted">{{ pv.category }}</p>
                <h3 class="text-primary">{{ pv.price }}</h3>
                <p class="mt-3">{{ pv.description }}</p>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="closePreview">Закрити</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Confirm Modal -->
    <div v-if="showConfirmModal" class="modal fade show" style="display: block; background-color: rgba(0,0,0,0.5);" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
          <div class="modal-body text-center p-4">
            <div class="mb-3 text-primary">
              <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" class="bi bi-check-circle" viewBox="0 0 16 16">
                <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                <path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/>
              </svg>
            </div>
            <h5 class="mb-2">Зберегти зміни?</h5>
            <p class="text-muted small mb-4">Ви впевнені, що хочете зберегти цей товар?</p>
            <div class="d-grid gap-2">
              <button type="button" class="btn btn-primary" @click="performSave">Так, зберегти</button>
              <button type="button" class="btn btn-light" @click="showConfirmModal = false">Скасувати</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import http from '@/crm/api/http'

const props = defineProps({
  initialProduct: { type: Object, default: null },
})

const loading = ref(false)
const fileInput = ref(null)
const imageFile = ref(null)
const previewUrl = ref(
  props.initialProduct?.main_photo_path ? `/storage/${props.initialProduct.main_photo_path}` : ''
)
const showConfirmModal = ref(false)

const form = reactive({
  id: props.initialProduct?.id ?? null,
  title: props.initialProduct?.title ?? '',
  category: props.initialProduct?.category ?? '',
  weight_g: props.initialProduct?.weight_g ?? null,
  length_cm: props.initialProduct?.length_cm ?? null,
  width_cm: props.initialProduct?.width_cm ?? null,
  height_cm: props.initialProduct?.height_cm ?? null,
  cost_price: props.initialProduct?.cost_price ?? null,
  sale_price: props.initialProduct?.sale_price ?? null,
  currency: props.initialProduct?.currency ?? 'UAH',
  sku: props.initialProduct?.sku ?? '',
  stock_qty: props.initialProduct?.stock_qty ?? null,
  min_stock: props.initialProduct?.min_stock ?? null,
  description: props.initialProduct?.description ?? '',
  main_photo_path: props.initialProduct?.main_photo_path ?? '',
})
const errors = reactive({
  title: '',
})

const dimensions = [
  { key: 'weight_g', label: 'Вага', unit: 'г', step: 1 },
  { key: 'length_cm', label: 'Довжина', unit: 'см', step: 0.1 },
  { key: 'width_cm', label: 'Ширина', unit: 'см', step: 0.1 },
  { key: 'height_cm', label: 'Висота', unit: 'см', step: 0.1 },
]

const isEdit = computed(() => !!form.id)
const currencyShort = computed(() => form.currency || 'UAH')
const pv = computed(() => {
  const title = form.title?.trim() || '—'
  const category = form.category?.trim() || '—'
  const description = form.description?.trim() || '—'
  const price =
    form.sale_price !== null && form.sale_price !== undefined && form.sale_price !== ''
      ? `${form.sale_price} ${currencyShort.value}`
      : '—'
  const sku = form.sku?.trim() || '—'
  const stock =
    form.stock_qty !== null && form.stock_qty !== undefined && form.stock_qty !== ''
      ? `${form.stock_qty} шт`
      : '—'
  return { title, category, description, price, sku, stock }
})
const showPreview = ref(false)

function pickFile() {
  fileInput.value?.click()
}

function onFileChange(e) {
  const file = e.target.files?.[0]
  if (!file) return
  imageFile.value = file
  previewUrl.value = URL.createObjectURL(file)
}

function onFileDrop(e) {
  const file = e.dataTransfer.files?.[0]
  if (!file) return
  imageFile.value = file
  previewUrl.value = URL.createObjectURL(file)
}

function removeImage() {
  imageFile.value = null
  previewUrl.value = ''
}

function openPreview() {
  showPreview.value = true
}

function closePreview() {
  showPreview.value = false
}

function validateForm() {
  errors.title = ''

  if (!form.title || !form.title.trim()) {
    errors.title = 'Укажіть назву товару'
  }

  return !errors.title
}

function handleSubmit() {
  if (!validateForm()) return
  showConfirmModal.value = true
}

async function performSave() {
  showConfirmModal.value = false
  loading.value = true
  try {
    const fd = new FormData()
    Object.entries(form).forEach(([key, val]) => {
      if (key === 'main_photo_path') return
      if (val === null || val === undefined) return
      if (typeof val === 'string' && val.trim() === '' && key !== 'title') return
      fd.append(key, val)
    })
    if (imageFile.value) fd.append('main_photo', imageFile.value)

    if (isEdit.value) {
      fd.append('_method', 'PUT')
      await http.post(`/products/${form.id}`, fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    } else {
      await http.post('/products', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    }

    window.location.href = '/products'
  } catch (e) {
    console.error('Не вдалося зберегти товар', e)
    alert('Не вдалося зберегти товар. Перевір консоль/бекенд-відповідь.')
  } finally {
    loading.value = false
  }
}
</script>
