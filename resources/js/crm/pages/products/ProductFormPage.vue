<template>
  <div class="container-fluid p-0 p-md-4">
    <div class="mx-auto" style="max-width: 1200px;">

      <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-3 mb-4">
        <div>
          <div class="d-flex align-items-center gap-2 mb-1">
            <a href="/products" class="btn btn-icon-back text-muted p-0 me-2">
              <i class="bi bi-arrow-left fs-5"></i>
            </a>
            <h1 class="h4 fw-bold text-dark m-0">
              {{ isEdit ? 'Редагування товару' : 'Новий товар' }}
            </h1>
          </div>
          <p class="text-muted small mb-0 ms-4 ps-2">Заповніть інформацію, щоб додати товар до каталогу.</p>
        </div>
        
        <div class="d-flex gap-2">
          <button type="button" @click="openPreview" class="btn btn-white border shadow-sm">
            <i class="bi bi-eye me-2 text-secondary"></i>
            <span class="d-none d-md-inline">Перегляд</span>
          </button>
          
          <button @click="handleSubmit" :disabled="loading" class="btn btn-primary-gradient shadow-sm d-flex align-items-center gap-2 px-4">
            <span v-if="loading" class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            <i v-else class="bi bi-check-lg"></i>
            <span>{{ loading ? 'Збереження...' : 'Зберегти' }}</span>
          </button>
        </div>
      </div>

      <form @submit.prevent="handleSubmit">
        <div class="row g-4">

          <div class="col-lg-8">
            
            <div class="clean-card mb-4">
              <div class="card-title-section">
                <i class="bi bi-info-circle text-primary me-2"></i>Основна інформація
              </div>
              
              <div class="mb-4">
                <label class="form-label-custom">Назва товару</label>
                <input
                  v-model.trim="form.title"
                  type="text"
                  class="form-control form-control-modern"
                  :class="{ 'is-invalid': errors.title }"
                  placeholder="Наприклад: Кросівки Nike Air Max"
                >
                <div v-if="errors.title" class="invalid-feedback">{{ errors.title }}</div>
              </div>

              <div class="row g-3 mb-4">
                 <div class="col-md-4">
                    <label class="form-label-custom">Категорія</label>
                    <div class="position-relative">
                      <select v-model="form.category_id" class="form-select form-select-modern">
                        <option disabled value="">Оберіть категорію...</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                      </select>
                    </div>
                 </div>
                 <div class="col-md-4">
                    <label class="form-label-custom">Колір</label>
                    <div class="position-relative">
                      <select v-model="form.color_id" class="form-select form-select-modern">
                        <option disabled value="">Оберіть колір...</option>
                        <option v-for="c in colors" :key="c.id" :value="c.id">{{ c.name }}</option>
                      </select>
                    </div>
                 </div>
                 <div class="col-md-4">
                    <label class="form-label-custom">SKU (Артикул)</label>
                    <input
                      v-model.trim="form.sku"
                      type="text"
                      class="form-control form-control-modern"
                      placeholder="AUT-001"
                    >
                 </div>
              </div>

              <div>
                <label class="form-label-custom">Опис</label>
                <textarea
                  v-model.trim="form.description"
                  rows="4"
                  class="form-control form-control-modern"
                  placeholder="Детальний опис товару, характеристики..."
                ></textarea>
                <div class="form-text text-end">{{ form.description.length }} символів</div>
              </div>
            </div>

            <div class="clean-card mb-4">
              <div class="card-title-section">
                <i class="bi bi-currency-dollar text-success me-2"></i>Ціна та Склад
              </div>

              <div class="row g-4">
                <div class="col-md-4">
                  <label class="form-label-custom">Валюта</label>
                  <select v-model="form.currency" class="form-select form-select-modern">
                    <option value="UAH">UAH (₴)</option>
                    <option value="USD">USD ($)</option>
                    <option value="EUR">EUR (€)</option>
                  </select>
                </div>

                <div class="col-md-4">
                  <label class="form-label-custom">Закупівельна ціна</label>
                  <div class="input-group input-group-modern">
                    <input
                      v-model.number="form.cost_price"
                      type="number" min="0" step="0.01"
                      class="form-control border-0"
                      placeholder="0.00"
                    >
                    <span class="input-group-text bg-transparent border-0 text-muted">{{ form.currency }}</span>
                  </div>
                </div>

                <div class="col-md-4">
                  <label class="form-label-custom">Ціна продажу</label>
                  <div class="input-group input-group-modern focused-group">
                    <input
                      v-model.number="form.sale_price"
                      type="number" min="0" step="0.01"
                      class="form-control border-0 fw-bold"
                      placeholder="0.00"
                    >
                    <span class="input-group-text bg-transparent border-0 text-muted">{{ form.currency }}</span>
                  </div>
                </div>
              </div>
              
              <hr class="border-light my-4">

              <div class="row g-4">
                <div class="col-md-6">
                  <label class="form-label-custom">Кількість на складі</label>
                  <input
                    v-model.number="form.stock_qty"
                    type="number" min="0" step="1"
                    class="form-control form-control-modern"
                    :readonly="form.variants.length > 0"
                    placeholder="0"
                  >
                </div>
                <div class="col-md-6">
                  <label class="form-label-custom">Мін. залишок (для сповіщень)</label>
                  <input
                    v-model.number="form.min_stock"
                    type="number" min="0" step="1"
                    class="form-control form-control-modern"
                    placeholder="0"
                  >
                </div>
              </div>
            </div>

            <div class="clean-card mb-4">
              <div class="card-title-section">
                <i class="bi bi-ui-checks-grid text-primary me-2"></i>Варіанти (Розміри)
              </div>

              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr class="text-muted text-uppercase small">
                      <th style="width: 25%;">Розмір</th>
                      <th style="width: 35%;">SKU</th>
                      <th style="width: 20%;">Залишок</th>
                      <th style="width: 10%;">Активний</th>
                      <th style="width: 10%;"></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(v, idx) in form.variants" :key="v.local_id">
                      <td>
                        <input v-model.trim="v.size" type="text" class="form-control form-control-modern form-control-sm" placeholder="38-39">
                      </td>
                      <td>
                        <input v-model.trim="v.sku" type="text" class="form-control form-control-modern form-control-sm" placeholder="SKU-38-39">
                      </td>
                      <td>
                        <input v-model.number="v.stock_qty" type="number" min="0" step="1" class="form-control form-control-modern form-control-sm" placeholder="0">
                      </td>
                      <td class="text-center">
                        <input v-model="v.is_active" type="checkbox" class="form-check-input" />
                      </td>
                      <td class="text-end">
                        <button type="button" class="btn btn-light btn-sm" @click="removeVariant(idx)">
                          <i class="bi bi-trash"></i>
                        </button>
                      </td>
                    </tr>
                    <tr v-if="!form.variants.length">
                      <td colspan="5" class="text-center text-muted small py-3">Додайте розміри для моделі</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div class="mt-3">
                <button type="button" class="btn btn-outline-primary btn-sm" @click="addVariant">
                  <i class="bi bi-plus-lg me-1"></i>Додати розмір
                </button>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            
            <div class="clean-card mb-4">
              <div class="card-title-section">
                <i class="bi bi-image text-purple me-2"></i>Фото товару
              </div>
              
              <div 
                class="upload-area"
                :class="{'has-image': previewUrl}"
                @click="pickFile"
                @dragover.prevent
                @drop.prevent="onFileDrop"
              >
                <div v-if="previewUrl" class="image-preview-wrapper">
                  <img :src="previewUrl" class="uploaded-img">
                  <div class="overlay">
                     <button @click.stop="removeImage" type="button" class="btn btn-danger btn-sm rounded-circle shadow">
                       <i class="bi bi-trash"></i>
                     </button>
                     <button type="button" class="btn btn-light btn-sm rounded-pill shadow px-3 mt-2 fw-medium">
                       Змінити
                     </button>
                  </div>
                </div>

                <div v-else class="placeholder-content">
                  <div class="icon-circle mb-3">
                    <i class="bi bi-cloud-arrow-up text-primary fs-4"></i>
                  </div>
                  <div class="fw-bold text-dark mb-1">Завантажити фото</div>
                  <div class="small text-muted mb-3">Drag & drop або клікніть</div>
                  <div class="badge bg-light text-secondary border fw-normal">JPG, PNG, WEBP</div>
                </div>

                <input ref="fileInput" type="file" class="d-none" accept="image/*" @change="onFileChange">
              </div>
            </div>

            <div class="clean-card">
              <div class="card-title-section">
                <i class="bi bi-box-seam text-warning me-2"></i>Габарити
              </div>
              
              <div class="row g-3">
                <div class="col-12">
                   <label class="form-label-custom">Вага</label>
                   <div class="input-group input-group-modern input-group-sm">
                      <input v-model.number="form.weight_g" type="number" class="form-control border-0" placeholder="0">
                      <span class="input-group-text border-0 bg-transparent text-muted">г</span>
                   </div>
                </div>
                <div class="col-4" v-for="dim in dimensions.slice(1)" :key="dim.key">
                   <label class="form-label-custom text-truncate">{{ dim.label }}</label>
                   <div class="input-group input-group-modern input-group-sm">
                      <input v-model.number="form[dim.key]" type="number" :step="dim.step" class="form-control border-0 px-1 text-center" placeholder="0">
                   </div>
                </div>
              </div>
              <div class="mt-3 small text-muted fst-italic">
                <i class="bi bi-info-circle me-1"></i>
                Використовується для розрахунку ТТН
              </div>
            </div>

          </div>
        </div>
      </form>
    </div>

    <div v-if="showPreview" class="modal-backdrop fade show bg-dark bg-opacity-50"></div>
    <div v-if="showPreview" class="modal fade show d-block" tabindex="-1" @click.self="closePreview">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
          <div class="modal-header border-bottom-0">
            <h5 class="modal-title fw-bold">Попередній перегляд</h5>
            <button type="button" class="btn-close" @click="closePreview"></button>
          </div>
          <div class="modal-body p-4">
            <div class="row align-items-center">
              <div class="col-md-5 text-center mb-3 mb-md-0">
                <div class="bg-light rounded-4 d-flex align-items-center justify-content-center overflow-hidden" style="height: 300px;">
                  <img v-if="previewUrl" :src="previewUrl" class="img-fluid" style="max-height: 100%; object-fit: contain;">
                  <i v-else class="bi bi-image text-muted" style="font-size: 4rem;"></i>
                </div>
              </div>
              <div class="col-md-7">
                <span class="badge bg-primary bg-opacity-10 text-primary mb-2">{{ pv.category }}</span>
                <h3 class="fw-bold text-dark mb-2">{{ pv.title }}</h3>
                <div class="fs-4 fw-bold text-success mb-3">{{ pv.price }}</div>
                
                <div class="d-flex gap-3 mb-4">
                   <div class="border rounded px-3 py-1 bg-light">
                     <small class="text-muted d-block">SKU</small>
                     <span class="fw-bold text-dark">{{ pv.sku }}</span>
                   </div>
                   <div class="border rounded px-3 py-1 bg-light">
                     <small class="text-muted d-block">Склад</small>
                     <span class="fw-bold text-dark">{{ pv.stock }}</span>
                   </div>
                </div>

                <p class="text-muted small">{{ pv.description }}</p>
              </div>
            </div>
          </div>
          <div class="modal-footer border-top-0">
            <button type="button" class="btn btn-light border rounded-3" @click="closePreview">Закрити</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="showConfirmModal" class="modal-backdrop fade show bg-dark bg-opacity-50"></div>
    <div v-if="showConfirmModal" class="modal fade show d-block" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-4">
          <div class="modal-body text-center p-4">
            <div class="mb-3 d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 text-success" style="width: 60px; height: 60px;">
              <i class="bi bi-check-lg fs-2"></i>
            </div>
            <h5 class="mb-2 fw-bold">Зберегти товар?</h5>
            <p class="text-muted small mb-4">Перевірте правильність введених даних перед збереженням.</p>
            <div class="d-grid gap-2">
              <button type="button" class="btn btn-primary-gradient shadow-sm" @click="performSave">Зберегти</button>
              <button type="button" class="btn btn-light text-muted" @click="showConfirmModal = false">Скасувати</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import http from '@/crm/api/http'

const props = defineProps({
  initialProduct: { type: Object, default: null },
})

const loading = ref(false)
const fileInput = ref(null)
const imageFile = ref(null)
const categories = ref([])
const colors = ref([])
const isLoadingCategories = ref(false)
const isLoadingColors = ref(false)
const previewUrl = ref(
  props.initialProduct?.main_photo_path ? `/storage/${props.initialProduct.main_photo_path}` : ''
)
const showConfirmModal = ref(false)
const showPreview = ref(false)

const form = reactive({
  id: props.initialProduct?.id ?? null,
  title: props.initialProduct?.title ?? '',
  category_id: props.initialProduct?.category_id ?? '',
  color_id: props.initialProduct?.color_id ?? '',
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
  variants: Array.isArray(props.initialProduct?.variants)
    ? props.initialProduct.variants.map((v) => ({
      local_id: `v-${v.id}`,
      id: v.id,
      size: v.size || '',
      sku: v.sku || '',
      stock_qty: Number(v.stock_qty || 0),
      is_active: v.is_active !== false,
    }))
    : [],
})

const errors = reactive({ title: '' })

const dimensions = [
  { key: 'weight_g', label: 'Вага', unit: 'г', step: 1 },
  { key: 'length_cm', label: 'Довжина', unit: 'см', step: 0.1 },
  { key: 'width_cm', label: 'Ширина', unit: 'см', step: 0.1 },
  { key: 'height_cm', label: 'Висота', unit: 'см', step: 0.1 },
]

const isEdit = computed(() => !!form.id)
const currencyShort = computed(() => form.currency || 'UAH')

const pv = computed(() => {
  const title = form.title?.trim() || 'Без назви'
  const categoryName = categories.value.find((c) => c.id === form.category_id)?.name
  const category = categoryName || 'Без категорії'
  const description = form.description?.trim() || 'Опис відсутній'
  const price =
    form.sale_price !== null && form.sale_price !== undefined && form.sale_price !== ''
      ? `${form.sale_price} ${currencyShort.value}`
      : '0.00'
  const sku = form.sku?.trim() || '—'
  const stock =
    form.stock_qty !== null && form.stock_qty !== undefined && form.stock_qty !== ''
      ? `${form.stock_qty} шт.`
      : '0'
  return { title, category, description, price, sku, stock }
})

const syncStockFromVariants = () => {
  if (!form.variants.length) return;
  form.stock_qty = form.variants.reduce((sum, v) => sum + Number(v.stock_qty || 0), 0);
};

const addVariant = () => {
  form.variants.push({
    local_id: `v-new-${Date.now()}-${Math.random().toString(16).slice(2)}`,
    size: '',
    sku: '',
    stock_qty: 0,
    is_active: true,
  });
};

const removeVariant = (idx) => {
  form.variants.splice(idx, 1);
  syncStockFromVariants();
};

const loadCategories = async () => {
  if (isLoadingCategories.value) return;
  isLoadingCategories.value = true;
  try {
    const { data } = await http.get('/products/categories', { headers: { Accept: 'application/json' } });
    categories.value = Array.isArray(data) ? data : [];
  } catch (e) {
    console.error('Не вдалося завантажити категорії', e);
    categories.value = [];
  } finally {
    isLoadingCategories.value = false;
  }
};

const loadColors = async () => {
  if (isLoadingColors.value) return;
  isLoadingColors.value = true;
  try {
    const { data } = await http.get('/products/colors', { headers: { Accept: 'application/json' } });
    colors.value = Array.isArray(data) ? data : [];
  } catch (e) {
    console.error('Не вдалося завантажити кольори', e);
    colors.value = [];
  } finally {
    isLoadingColors.value = false;
  }
};

watch(
  () => form.variants.map((v) => [v.size, v.sku, v.stock_qty, v.is_active]),
  () => {
    syncStockFromVariants();
  },
  { deep: true }
);

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
  // Якщо треба видалити стару фотку на сервері, тут можна додати логіку
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
    errors.title = 'Вкажіть назву товару'
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
      if (key === 'variants') return
      if (val === null || val === undefined) return
      if (typeof val === 'string' && val.trim() === '' && key !== 'title') return
      fd.append(key, val)
    })
    if (imageFile.value) fd.append('main_photo', imageFile.value)
    fd.append('variants', JSON.stringify(form.variants.map((v) => ({
      size: v.size,
      sku: v.sku,
      stock_qty: v.stock_qty,
      is_active: v.is_active,
    }))))
    if (form.variants.length) {
      syncStockFromVariants();
      fd.set('stock_qty', form.stock_qty || 0);
    }

    if (isEdit.value) {
      fd.append('_method', 'PUT')
      await http.post(`/products/${form.id}`, fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    } else {
      await http.post('/products', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    }

    window.location.href = '/products'
  } catch (e) {
    console.error('Save error', e)
    alert('Помилка при збереженні')
  } finally {
    loading.value = false
  }
}

loadCategories();
loadColors();
</script>

<style scoped>
/* --- Global & Layout --- */
.clean-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  padding: 24px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.02);
}

.card-title-section {
  font-size: 1rem;
  font-weight: 700;
  color: #1e293b;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
}

/* --- Form Controls --- */
.form-label-custom {
  font-size: 0.8rem;
  font-weight: 600;
  color: #64748b;
  margin-bottom: 6px;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

.form-control-modern, .form-select-modern {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 0.95rem;
  transition: all 0.2s;
  background-color: #fcfcfc;
}

.form-control-modern:focus, .form-select-modern:focus {
  border-color: #6366f1;
  background-color: #fff;
  box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
  color: #1e293b;
}

/* Input Group Modern */
.input-group-modern {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background-color: #fcfcfc;
  overflow: hidden;
  transition: all 0.2s;
}
.input-group-modern:focus-within {
  border-color: #6366f1;
  background-color: #fff;
  box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
}
.input-group-modern .form-control {
  background: transparent;
  padding: 10px 12px;
  font-size: 0.95rem;
}

/* --- Media Upload --- */
.upload-area {
  border: 2px dashed #cbd5e1;
  border-radius: 12px;
  min-height: 240px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background-color: #f8fafc;
  cursor: pointer;
  transition: all 0.2s;
  position: relative;
  overflow: hidden;
}
.upload-area:hover {
  border-color: #6366f1;
  background-color: #f1f5f9;
}
.upload-area.has-image {
  border-style: solid;
  border-color: #e2e8f0;
  padding: 0;
  background: #fff;
}

.icon-circle {
  width: 50px;
  height: 50px;
  background: #eff6ff;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.image-preview-wrapper {
  width: 100%;
  height: 100%;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
}
.uploaded-img {
  width: 100%;
  height: auto;
  max-height: 240px;
  object-fit: contain;
}
.overlay {
  position: absolute;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.4);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  opacity: 0;
  transition: opacity 0.2s;
}
.upload-area:hover .overlay {
  opacity: 1;
}

/* --- Buttons --- */
.btn-primary-gradient {
  background: linear-gradient(135deg, #6366f1, #4f46e5);
  border: none;
  color: white;
  font-weight: 600;
  transition: all 0.2s;
}
.btn-primary-gradient:hover:not(:disabled) {
  box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35);
  transform: translateY(-1px);
  color: white;
}
.btn-white {
  background: #fff;
  color: #475569;
  font-weight: 500;
  transition: all 0.2s;
}
.btn-white:hover {
  background: #f8fafc;
  color: #1e293b;
}

.text-purple { color: #8b5cf6; }
</style>
