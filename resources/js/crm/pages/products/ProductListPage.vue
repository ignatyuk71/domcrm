<template>
  <div class="container-fluid p-0 p-md-4">
    <div class="clean-card overflow-hidden">
      
      <div class="p-4 border-bottom">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
          
          <div class="d-flex flex-column gap-3 w-100">
            <div class="d-flex align-items-center justify-content-between">
              <h1 class="h5 fw-bold text-dark m-0 d-flex align-items-center gap-2">
                <i class="bi bi-box-seam text-primary"></i>
                Товари
              </h1>
              
              <a href="/products/create" class="btn btn-primary d-md-none btn-sm shadow-sm">
                <i class="bi bi-plus-lg"></i>
              </a>
            </div>

            <div class="d-flex gap-3 w-100 flex-wrap">
              <div class="search-wrapper w-100" style="max-width: 400px;">
                <i class="bi bi-search search-icon"></i>
                <input
                  v-model.trim="query"
                  type="text"
                  class="form-control search-input"
                  placeholder="Пошук за назвою, SKU..."
                  @input="debouncedLoad"
                />
              </div>

              <div class="category-wrapper" style="max-width: 260px;">
                <select
                  v-model="selectedCategory"
                  class="form-select category-select"
                  @change="loadProducts(1)"
                >
                  <option value="">Всі категорії</option>
                  <option v-for="c in categories" :key="c" :value="c">
                    {{ c }}
                  </option>
                </select>
              </div>
            </div>
          </div>

          <a href="/products/create" class="btn btn-create d-none d-md-flex shadow-sm">
            <i class="bi bi-plus-lg me-2"></i>
            <span>Додати товар</span>
          </a>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table align-middle mb-0 clean-table">
          <thead>
            <tr>
              <th class="ps-4" style="width: 80px;">Фото</th>
              <th style="min-width: 280px;">Назва товару</th>
              <th style="min-width: 110px;">SKU (Артикул)</th>
              <th style="min-width: 110px;">Категорія</th>
              <th class="text-center" style="min-width: 100px;">Запас</th>
              <th class="text-end" style="min-width: 120px;">Ціна</th>
              <th class="text-end pe-4" style="width: 100px;">Дії</th>
            </tr>
          </thead>
          <tbody :class="{ 'opacity-50': isLoading }">
            <tr v-for="p in products" :key="p.id" class="product-row">
              <td class="ps-4">
                <div class="product-thumb">
                  <img v-if="p.imageUrl" :src="p.imageUrl" alt="Фото" />
                  <i v-else class="bi bi-image text-muted fs-5"></i>
                </div>
              </td>
              
              <td>
                <div class="fw-bold text-dark product-title-wrap" :title="p.title">
                  {{ p.title }}
                </div>
                <div class="small text-muted product-desc-wrap" v-if="p.description">
                  {{ p.description }}
                </div>
              </td>

              <td>
                <span class="font-monospace text-secondary small bg-light px-2 py-1 rounded border">
                  {{ p.sku || '—' }}
                </span>
              </td>

              <td>
                <span class="text-dark small fw-medium">
                  {{ p.category || '—' }}
                </span>
              </td>

              <td class="text-center">
                <span 
                  class="badge-stock" 
                  :class="p.stock_qty > 0 ? 'stock-ok' : 'stock-low'"
                >
                  <i class="bi" :class="p.stock_qty > 0 ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill'"></i>
                  {{ p.stock_qty ?? '0' }} шт.
                </span>
              </td>

              <td class="text-end">
                <span class="fw-bold text-dark">
                  {{ formatPrice(p.sale_price, p.currency) }}
                </span>
              </td>

              <td class="text-end pe-4">
                <a 
                  :href="`/products/${p.id}/edit`" 
                  class="btn-icon-action" 
                  title="Редагувати"
                >
                  <i class="bi bi-pencil"></i>
                </a>
                <button
                  class="btn-icon-action btn-icon-danger ms-1"
                  type="button"
                  title="Видалити"
                  @click="confirmDelete(p)"
                >
                  <i class="bi bi-trash"></i>
                </button>
              </td>
            </tr>

            <tr v-if="products.length === 0 && !isLoading">
              <td colspan="7" class="text-center py-5">
                <div class="empty-state">
                  <div class="empty-icon mb-3">
                    <i class="bi bi-box-seam"></i>
                  </div>
                  <h6 class="fw-bold text-dark">Товарів не знайдено</h6>
                  <p class="text-muted small">Спробуйте змінити пошуковий запит або створіть новий товар.</p>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="pagination.last_page > 1" class="d-flex align-items-center justify-content-between p-4 border-top bg-light bg-opacity-10">
        <div class="text-muted small d-none d-sm-block">
          Показано <span class="fw-bold text-dark">{{ (pagination.current_page - 1) * pagination.per_page + 1 }}</span> - <span class="fw-bold text-dark">{{ Math.min(pagination.current_page * pagination.per_page, pagination.total) }}</span> з <span class="fw-bold text-dark">{{ pagination.total }}</span>
        </div>
        
        <nav>
          <ul class="pagination mb-0 gap-1">
            <li class="page-item" :class="{ disabled: pagination.current_page <= 1 }">
              <button class="page-link page-btn" @click="changePage(pagination.current_page - 1)">
                <i class="bi bi-chevron-left"></i>
              </button>
            </li>
            
            <li class="page-item disabled">
              <span class="page-link page-info border-0 bg-transparent text-dark fw-bold">
                {{ pagination.current_page }}
              </span>
            </li>

            <li class="page-item" :class="{ disabled: pagination.current_page >= pagination.last_page }">
              <button class="page-link page-btn" @click="changePage(pagination.current_page + 1)">
                <i class="bi bi-chevron-right"></i>
              </button>
            </li>
          </ul>
        </nav>
      </div>
    </div>

    <div v-if="deleteModalOpen" class="modal-backdrop-custom" @click.self="closeDeleteModal">
      <div class="modal-card">
        <h5 class="fw-bold mb-2">Видалити товар?</h5>
        <p class="text-muted small mb-3">
          Ви точно хочете видалити цей товар? Цю дію неможливо буде скасувати.
        </p>
        <div class="small fw-semibold mb-3">
          Товар: "{{ deleteTarget?.title || '—' }}"
        </div>
        <div class="d-flex justify-content-end gap-2">
          <button class="btn btn-light" type="button" @click="closeDeleteModal">Скасувати</button>
          <button class="btn btn-danger" type="button" :disabled="deleteLoading" @click="handleDelete">
            <span v-if="!deleteLoading">Так, видалити</span>
            <span v-else class="spinner-border spinner-border-sm"></span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref, reactive } from 'vue'
import http from '@/crm/api/http'

const products = ref([])
const query = ref('')
const isLoading = ref(false)
const categories = ref([])
const selectedCategory = ref('')
const isLoadingCategories = ref(false)
const pagination = reactive({
  current_page: 1,
  last_page: 1,
  total: 0,
  per_page: 150
})
const deleteModalOpen = ref(false)
const deleteTarget = ref(null)
const deleteLoading = ref(false)
let timer = null

const loadProducts = async (page = 1) => {
  isLoading.value = true
  try {
    const { data } = await http.get('/products', {
      headers: { Accept: 'application/json' },
      params: { 
        q: query.value || undefined,
        category: selectedCategory.value || undefined,
        page: page,
        per_page: pagination.per_page
      },
    })
    const payload = data || {}
    const arr = Array.isArray(payload?.data)
      ? payload.data
      : Array.isArray(payload)
        ? payload
        : []
    products.value = arr.map((p) => ({
      ...p,
      imageUrl: p?.main_photo_path ? `/storage/${p.main_photo_path}` : '',
    }))

    if (payload?.current_page) {
      pagination.current_page = payload.current_page
      pagination.last_page = payload.last_page
      pagination.total = payload.total
      pagination.per_page = payload.per_page
    }
  } catch (e) {
    console.error('Не вдалося завантажити товари', e)
  } finally {
    isLoading.value = false
  }
}

const loadCategories = async () => {
  if (isLoadingCategories.value) return
  isLoadingCategories.value = true
  try {
    const { data } = await http.get('/products/categories', {
      headers: { Accept: 'application/json' },
    })
    categories.value = Array.isArray(data) ? data : []
  } catch (e) {
    console.error('Не вдалося завантажити категорії', e)
    categories.value = []
  } finally {
    isLoadingCategories.value = false
  }
}

const changePage = (page) => {
  if (page < 1 || page > pagination.last_page) return
  loadProducts(page)
}

const debouncedLoad = () => {
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => loadProducts(1), 300)
}

const formatPrice = (price, currency = 'UAH') => {
  if (price === null || price === undefined) return '—'
  return new Intl.NumberFormat('uk-UA', { style: 'currency', currency }).format(price)
}

const confirmDelete = (product) => {
  deleteTarget.value = product
  deleteModalOpen.value = true
}

const closeDeleteModal = () => {
  if (deleteLoading.value) return
  deleteModalOpen.value = false
  deleteTarget.value = null
}

const handleDelete = async () => {
  if (!deleteTarget.value || deleteLoading.value) return
  deleteLoading.value = true
  try {
    await http.delete(`/products/${deleteTarget.value.id}`, {
      headers: { Accept: 'application/json' },
    })
    products.value = products.value.filter((p) => p.id !== deleteTarget.value.id)
    pagination.total = Math.max(0, (pagination.total || 1) - 1)
    closeDeleteModal()
  } catch (e) {
    console.error('Не вдалося видалити товар', e)
    alert('Не вдалося видалити товар')
  } finally {
    deleteLoading.value = false
  }
}

onMounted(loadProducts)
onMounted(loadCategories)

onUnmounted(() => {
  if (timer) clearTimeout(timer)
})
</script>

<style scoped>
/* --- Card Container --- */
.clean-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 16px; /* Rounded-4 equivalent */
  box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

/* --- Search Input --- */
.search-wrapper {
  position: relative;
}
.search-icon {
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  color: #94a3b8;
  font-size: 0.9rem;
  pointer-events: none;
}
.search-input {
  padding-left: 38px;
  padding-right: 16px;
  height: 42px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  font-size: 0.9rem;
  font-weight: 500;
  transition: all 0.2s;
}
.search-input:focus {
  background: #fff;
  border-color: #6366f1;
  box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
  color: #1e293b;
}
.category-select {
  height: 42px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  font-size: 0.9rem;
  font-weight: 500;
  transition: all 0.2s;
}
.category-select:focus {
  background: #fff;
  border-color: #6366f1;
  box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
}

/* --- Create Button --- */
.btn-create {
  height: 42px;
  display: flex;
  align-items: center;
  padding: 0 20px;
  border-radius: 10px;
  background: linear-gradient(135deg, #6366f1, #4f46e5);
  border: none;
  color: #fff;
  font-weight: 600;
  font-size: 0.9rem;
  transition: all 0.2s;
}
.btn-create:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35);
  color: #fff;
}

/* --- Table Styles --- */
.clean-table th {
  background: #f8fafc;
  color: #64748b;
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 12px;
  border-bottom: 1px solid #e2e8f0;
}
.clean-table td {
  padding: 12px;
  border-bottom: 1px solid #f1f5f9;
}
.product-row:hover {
  background: #fdfdfd;
}
.product-row:last-child td {
  border-bottom: none;
}

/* --- Product Thumb --- */
.product-thumb {
  width: 48px;
  height: 48px;
  border-radius: 8px;
  background: #fff;
  border: 1px solid #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
.product-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

/* --- Stock Badges --- */
.badge-stock {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 0.75rem;
  font-weight: 700;
}
.stock-ok {
  background: #dcfce7;
  color: #166534;
}
.stock-low {
  background: #fee2e2;
  color: #991b1b;
}

/* --- Action Buttons --- */
.btn-icon-action {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #64748b;
  border: 1px solid transparent;
  transition: all 0.2s;
  background: #fff;
}
.btn-icon-action:hover {
  background: #f1f5f9;
  color: #3b82f6;
  border-color: #e2e8f0;
}
.btn-icon-danger:hover {
  color: #b91c1c;
  border-color: #fecaca;
  background: #fee2e2;
}
.product-title-wrap,
.product-desc-wrap {
  white-space: normal;
  word-break: break-word;
}
.product-title-wrap {
  line-height: 1.25;
}
.product-desc-wrap {
  line-height: 1.3;
}

.modal-backdrop-custom {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1050;
  padding: 16px;
}
.modal-card {
  width: 100%;
  max-width: 420px;
  background: #fff;
  border-radius: 14px;
  padding: 20px;
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.2);
}

/* --- Empty State --- */
.empty-icon {
  width: 64px;
  height: 64px;
  background: #f1f5f9;
  color: #94a3b8;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
}

/* --- Pagination Buttons --- */
.page-btn {
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #64748b;
  font-size: 0.85rem;
  transition: all 0.2s;
}
.page-btn:hover:not(:disabled) {
  background: #f1f5f9;
  color: #334155;
  border-color: #cbd5e1;
}
</style>
