<template>
  <div class="product-page container-fluid px-0 px-md-3">
    <transition name="toast-pop">
      <div v-if="toast.show" class="products-toast" :class="toast.type">
        <div class="toast-icon">
          <i class="bi" :class="toast.type === 'success' ? 'bi-check2-circle' : 'bi-exclamation-triangle'"></i>
        </div>
        <div class="toast-copy">
          <strong>{{ toast.type === 'success' ? 'Збережено' : 'Помилка' }}</strong>
          <span>{{ toast.message }}</span>
        </div>
        <button type="button" class="btn-close btn-close-white btn-close-sm" @click="hideToast"></button>
      </div>
    </transition>

    <section class="toolbar-panel">
      <div class="toolbar-panel__row">
        <div class="toolbar-panel__filters">
          <label class="filter-field">
            <i class="bi bi-search"></i>
            <input
              v-model.trim="query"
              type="text"
              class="form-control border-0"
              placeholder="Пошук за назвою або SKU"
              @input="debouncedLoad"
            />
          </label>

          <label class="filter-field filter-field--select">
            <i class="bi bi-grid"></i>
            <select v-model="selectedCategory" class="form-select border-0" @change="loadProducts(1)">
              <option value="">Всі категорії</option>
              <option v-for="category in categories" :key="category.id" :value="String(category.id)">
                {{ category.name }}
              </option>
            </select>
          </label>

          <button v-if="hasFilters" type="button" class="btn btn-reset" @click="resetFilters">
            Скинути
          </button>
        </div>

        <a href="/products/create" class="btn btn-add-product">
          <i class="bi bi-plus-lg"></i>
          <span>Додати товар</span>
        </a>
      </div>

      <div class="toolbar-panel__meta">
        <span>{{ pageSummary }}</span>
        <span v-if="selectedCategoryLabel">Категорія: {{ selectedCategoryLabel }}</span>
      </div>
    </section>

    <section class="list-panel" :class="{ 'is-loading': isLoading }">
      <div class="list-head d-none d-lg-grid">
        <span></span>
        <span>Фото</span>
        <span>Назва товару</span>
        <span>SKU</span>
        <span>Категорія</span>
        <span>Залишок</span>
        <span>Ціна</span>
        <span class="text-end">Дії</span>
      </div>

      <div v-if="products.length" class="list-body">
        <template v-for="product in products" :key="product.id">
          <article class="product-row" :class="{ 'product-row--expanded': isProductExpanded(product.id) }">
            <div class="product-row__toggle">
              <button
                type="button"
                class="toggle-btn"
                :disabled="!product.variants.length"
                @click="toggleProduct(product.id)"
              >
                <i
                  class="bi"
                  :class="product.variants.length
                    ? (isProductExpanded(product.id) ? 'bi-chevron-down' : 'bi-chevron-right')
                    : 'bi-dash'"
                ></i>
              </button>
            </div>

            <div class="product-row__photo">
              <div class="product-thumb">
                <img v-if="product.imageUrl" :src="product.imageUrl" :alt="product.title" />
                <div v-else class="product-thumb__placeholder">
                  <i class="bi bi-image"></i>
                </div>
              </div>
            </div>

            <div class="product-row__name">
              <div class="product-title">{{ product.title }}</div>
              <div class="product-description">
                {{ product.description || 'Без опису' }}
              </div>
            </div>

            <div class="product-row__sku">
              <span class="row-label">SKU</span>
              <span class="mono-pill">{{ product.sku || '—' }}</span>
            </div>

            <div class="product-row__category">
              <span class="row-label">Категорія</span>
              <div class="meta-stack">
                <strong>{{ product.category_name }}</strong>
                <small>{{ product.color_name }}</small>
              </div>
            </div>

            <div class="product-row__stock">
              <span class="row-label">Залишок</span>
              <template v-if="product.variants.length">
                <span class="stock-badge" :class="getProductState(product).className">
                  {{ formatStock(product.stock_qty) }}
                </span>
              </template>
              <template v-else>
                <div class="inline-input-shell inline-input-shell--stock">
                  <input
                    v-model.number="product.stock_qty"
                    type="number"
                    min="0"
                    step="1"
                    class="form-control border-0"
                    :disabled="product.isSavingMeta"
                    @blur="saveProduct(product.id)"
                    @keydown.enter.prevent="saveProduct(product.id)"
                  />
                  <span>шт</span>
                </div>
              </template>
            </div>

            <div class="product-row__price">
              <span class="row-label">Ціна</span>
              <div class="inline-input-shell">
                <input
                  v-model.number="product.sale_price"
                  type="number"
                  min="0"
                  step="0.01"
                  class="form-control border-0"
                  :disabled="product.isSavingMeta"
                  @blur="saveProduct(product.id)"
                  @keydown.enter.prevent="saveProduct(product.id)"
                />
                <span>{{ product.currency }}</span>
                <span v-if="product.isSavingMeta" class="inline-saving">
                  <span class="spinner-border spinner-border-sm"></span>
                </span>
              </div>
            </div>

            <div class="product-row__actions">
              <a :href="`/products/${product.id}/edit`" class="btn btn-icon-action" title="Редагувати">
                <i class="bi bi-pencil"></i>
              </a>
              <button
                type="button"
                class="btn btn-icon-action btn-icon-danger"
                title="Видалити"
                @click="confirmDelete(product)"
              >
                <i class="bi bi-trash"></i>
              </button>
            </div>
          </article>

          <transition name="expand-row">
            <div v-if="isProductExpanded(product.id)" class="variants-panel">
              <div class="variants-panel__head">
                <strong>Варіанти товару</strong>
                <small>Тут можна швидко змінювати кількість і статус наявності.</small>
              </div>

              <div class="variants-table-head d-none d-md-grid">
                <span>Розмір</span>
                <span>SKU</span>
                <span>Наявність</span>
                <span>Кількість</span>
              </div>

              <div class="variants-list">
                <article
                  v-for="variant in product.variants"
                  :key="variant.id"
                  class="variant-row"
                  :class="{ 'variant-row--saving': variant.isSaving }"
                >
                  <div class="variant-row__size">
                    <span class="row-label">Розмір</span>
                    <strong>{{ variant.size || 'Без розміру' }}</strong>
                  </div>

                  <div class="variant-row__sku">
                    <span class="row-label">SKU</span>
                    <span class="mono-pill mono-pill--soft">{{ variant.sku || '—' }}</span>
                  </div>

                  <div class="variant-row__status">
                    <span class="row-label">Наявність</span>
                    <select
                      class="form-select compact-select"
                      :value="getVariantAvailabilityValue(variant)"
                      :disabled="variant.isSaving"
                      @change="updateVariantAvailability(product.id, variant.id, $event.target.value)"
                    >
                      <option v-for="option in availabilityOptions" :key="option.value" :value="option.value">
                        {{ option.label }}
                      </option>
                    </select>
                  </div>

                  <div class="variant-row__stock">
                    <span class="row-label">Кількість</span>
                    <div class="inline-input-shell inline-input-shell--stock">
                      <input
                        v-model.number="variant.stock_qty"
                        type="number"
                        min="0"
                        step="1"
                        class="form-control border-0"
                        :disabled="variant.isSaving"
                        @blur="saveVariant(product.id, variant.id)"
                        @keydown.enter.prevent="saveVariant(product.id, variant.id)"
                      />
                      <span>шт</span>
                    </div>
                  </div>
                </article>
              </div>
            </div>
          </transition>
        </template>
      </div>

      <div v-else-if="!isLoading" class="empty-state">
        <i class="bi bi-box-seam"></i>
        <strong>Товарів не знайдено</strong>
        <span>Спробуйте змінити пошук або скинути фільтри.</span>
      </div>

      <div v-if="isLoading" class="list-loader">
        <span class="spinner-border"></span>
      </div>
    </section>

    <div v-if="pagination.last_page > 1" class="list-pagination">
      <span>{{ pageSummary }}</span>

      <nav>
        <ul class="pagination mb-0">
          <li class="page-item" :class="{ disabled: pagination.current_page <= 1 }">
            <button type="button" class="page-link" @click="changePage(pagination.current_page - 1)">
              <i class="bi bi-chevron-left"></i>
            </button>
          </li>
          <li class="page-item disabled">
            <span class="page-link page-link--current">{{ pagination.current_page }} / {{ pagination.last_page }}</span>
          </li>
          <li class="page-item" :class="{ disabled: pagination.current_page >= pagination.last_page }">
            <button type="button" class="page-link" @click="changePage(pagination.current_page + 1)">
              <i class="bi bi-chevron-right"></i>
            </button>
          </li>
        </ul>
      </nav>
    </div>

    <div v-if="deleteModalOpen" class="modal-backdrop-custom" @click.self="closeDeleteModal">
      <div class="modal-card">
        <h5 class="fw-bold mb-2">Видалити товар?</h5>
        <p class="text-muted small mb-3">
          Ви точно хочете видалити цей товар? Цю дію не можна буде скасувати.
        </p>
        <div class="delete-product-preview">
          <strong>{{ deleteTarget?.title || '—' }}</strong>
          <span>{{ deleteTarget?.sku || 'Без SKU' }}</span>
        </div>
        <div class="d-flex justify-content-end gap-2 mt-4">
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
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import {
  destroyProduct,
  fetchProductCategories,
  fetchProducts,
  updateProductInline,
  updateProductVariant,
} from '@/crm/api/products'

const availabilityOptions = [
  { value: 'in_stock', label: 'В наявності' },
  { value: 'out_of_stock', label: 'Немає в наявності' },
  { value: 'inactive', label: 'Приховано' },
]

const products = ref([])
const query = ref('')
const categories = ref([])
const selectedCategory = ref('')
const isLoading = ref(false)
const isLoadingCategories = ref(false)
const expandedProductIds = ref([])
const deleteModalOpen = ref(false)
const deleteTarget = ref(null)
const deleteLoading = ref(false)
const toast = reactive({
  show: false,
  message: '',
  type: 'success',
})
const pagination = reactive({
  current_page: 1,
  last_page: 1,
  total: 0,
  per_page: 150,
})

let searchTimer = null
let toastTimer = null

const normalizeStock = (value) => {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? Math.max(0, Math.trunc(parsed)) : 0
}

const normalizePrice = (value) => {
  if (value === '' || value === null || value === undefined) {
    return null
  }

  const parsed = Number(value)
  if (!Number.isFinite(parsed)) {
    return null
  }

  return Math.max(0, Number(parsed.toFixed(2)))
}

const calculateProductStock = (variants = []) => (
  variants.reduce((sum, variant) => sum + (variant.is_active ? normalizeStock(variant.stock_qty) : 0), 0)
)

const normalizeVariant = (variant) => {
  const stockQty = normalizeStock(variant?.stock_qty)
  const isActive = Boolean(variant?.is_active)

  return {
    ...variant,
    stock_qty: stockQty,
    is_active: isActive,
    saved_stock_qty: stockQty,
    saved_is_active: isActive,
    isSaving: false,
    pendingSave: false,
  }
}

const normalizeProduct = (product) => {
  const variants = Array.isArray(product?.variants) ? product.variants.map(normalizeVariant) : []
  const stockQty = variants.length ? calculateProductStock(variants) : normalizeStock(product?.stock_qty)
  const salePrice = normalizePrice(product?.sale_price)

  return {
    ...product,
    imageUrl: product?.main_photo_url || (product?.main_photo_path ? `/storage/${product.main_photo_path}` : ''),
    category_name: product?.category?.name || product?.category || 'Без категорії',
    color_name: product?.color?.name || 'Без кольору',
    color_hex: product?.color?.hex_code || '',
    currency: product?.currency || 'UAH',
    stock_qty: stockQty,
    saved_stock_qty: stockQty,
    sale_price: salePrice,
    saved_sale_price: salePrice,
    min_stock: normalizeStock(product?.min_stock),
    variants,
    isSavingMeta: false,
    pendingMetaSave: false,
  }
}

const selectedCategoryLabel = computed(() => {
  if (!selectedCategory.value) return ''

  const current = categories.value.find((item) => String(item.id) === String(selectedCategory.value))
  return current?.name || ''
})

const hasFilters = computed(() => Boolean(query.value || selectedCategory.value))

const pageSummary = computed(() => {
  if (!pagination.total) {
    return 'Порожній список'
  }

  const from = (pagination.current_page - 1) * pagination.per_page + 1
  const to = Math.min(pagination.current_page * pagination.per_page, pagination.total)

  return `Показано ${from}-${to} з ${pagination.total}`
})

const showToast = (message, type = 'success') => {
  toast.message = message
  toast.type = type === 'error' ? 'error' : 'success'
  toast.show = true

  if (toastTimer) {
    clearTimeout(toastTimer)
  }

  toastTimer = setTimeout(() => {
    toast.show = false
  }, 2600)
}

const hideToast = () => {
  toast.show = false

  if (toastTimer) {
    clearTimeout(toastTimer)
    toastTimer = null
  }
}

const loadProducts = async (page = 1) => {
  isLoading.value = true

  try {
    const { data } = await fetchProducts({
      q: query.value || undefined,
      category: selectedCategory.value || undefined,
      page,
      per_page: pagination.per_page,
    })

    const payload = data || {}
    const rows = Array.isArray(payload?.data)
      ? payload.data
      : Array.isArray(payload)
        ? payload
        : []

    products.value = rows.map(normalizeProduct)
    expandedProductIds.value = expandedProductIds.value.filter((id) => products.value.some((product) => product.id === id))

    if (payload?.current_page) {
      pagination.current_page = payload.current_page
      pagination.last_page = payload.last_page
      pagination.total = payload.total
      pagination.per_page = payload.per_page
    }
  } catch (error) {
    console.error('Не вдалося завантажити товари', error)
    showToast(error.response?.data?.message || 'Не вдалося завантажити товари.', 'error')
  } finally {
    isLoading.value = false
  }
}

const loadCategories = async () => {
  if (isLoadingCategories.value) return

  isLoadingCategories.value = true

  try {
    const { data } = await fetchProductCategories()
    categories.value = Array.isArray(data) ? data : []
  } catch (error) {
    console.error('Не вдалося завантажити категорії', error)
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
  if (searchTimer) {
    clearTimeout(searchTimer)
  }

  searchTimer = setTimeout(() => {
    loadProducts(1)
  }, 280)
}

const resetFilters = () => {
  query.value = ''
  selectedCategory.value = ''
  loadProducts(1)
}

const formatStock = (stockQty) => `${normalizeStock(stockQty)} шт`

const isProductExpanded = (productId) => expandedProductIds.value.includes(productId)

const toggleProduct = (productId) => {
  if (isProductExpanded(productId)) {
    expandedProductIds.value = expandedProductIds.value.filter((id) => id !== productId)
    return
  }

  expandedProductIds.value = [...expandedProductIds.value, productId]
}

const getProductState = (product) => {
  const activeStock = product.variants.length ? calculateProductStock(product.variants) : normalizeStock(product.stock_qty)

  if (activeStock === 0) {
    return {
      className: 'stock-badge--out',
    }
  }

  if (product.min_stock > 0 && activeStock <= product.min_stock) {
    return {
      className: 'stock-badge--low',
    }
  }

  return {
    className: 'stock-badge--ok',
  }
}

const getVariantAvailabilityValue = (variant) => {
  if (!variant.is_active) return 'inactive'
  return normalizeStock(variant.stock_qty) > 0 ? 'in_stock' : 'out_of_stock'
}

const findProduct = (productId) => products.value.find((item) => item.id === productId) || null

const findProductVariant = (productId, variantId) => {
  const product = findProduct(productId)
  if (!product) return null

  const variant = product.variants.find((item) => item.id === variantId)
  if (!variant) return null

  return { product, variant }
}

const hasVariantChanges = (variant) => (
  normalizeStock(variant.stock_qty) !== normalizeStock(variant.saved_stock_qty)
  || Boolean(variant.is_active) !== Boolean(variant.saved_is_active)
)

const hasProductChanges = (product) => {
  const salePriceChanged = normalizePrice(product.sale_price) !== normalizePrice(product.saved_sale_price)
  const stockChanged = !product.variants.length
    && normalizeStock(product.stock_qty) !== normalizeStock(product.saved_stock_qty)

  return salePriceChanged || stockChanged
}

const requestVariantSave = async (product, variant) => {
  if (!hasVariantChanges(variant)) {
    return
  }

  if (variant.isSaving) {
    variant.pendingSave = true
    return
  }

  const previous = {
    stock_qty: variant.saved_stock_qty,
    is_active: variant.saved_is_active,
  }

  const payload = {
    stock_qty: normalizeStock(variant.stock_qty),
    is_active: Boolean(variant.is_active),
  }

  variant.stock_qty = payload.stock_qty
  variant.isSaving = true
  variant.pendingSave = false

  try {
    const { data } = await updateProductVariant(variant.id, payload)
    const nextVariant = data?.data?.variant || payload
    const nextProduct = data?.data?.product || {}

    variant.stock_qty = normalizeStock(nextVariant.stock_qty)
    variant.is_active = Boolean(nextVariant.is_active)
    variant.saved_stock_qty = variant.stock_qty
    variant.saved_is_active = variant.is_active
    product.stock_qty = normalizeStock(nextProduct.stock_qty ?? calculateProductStock(product.variants))
    product.saved_stock_qty = product.stock_qty

    showToast(data?.message || 'Варіант збережено.')
  } catch (error) {
    console.error('Не вдалося оновити варіант', error)
    variant.stock_qty = previous.stock_qty
    variant.is_active = previous.is_active

    showToast(error.response?.data?.message || 'Не вдалося зберегти варіант.', 'error')
  } finally {
    variant.isSaving = false

    if (variant.pendingSave) {
      variant.pendingSave = false
      requestVariantSave(product, variant)
    }
  }
}

const requestProductSave = async (product) => {
  if (!hasProductChanges(product)) {
    return
  }

  if (product.isSavingMeta) {
    product.pendingMetaSave = true
    return
  }

  const previous = {
    sale_price: product.saved_sale_price,
    stock_qty: product.saved_stock_qty,
  }

  const payload = {
    sale_price: normalizePrice(product.sale_price),
  }

  if (!product.variants.length) {
    payload.stock_qty = normalizeStock(product.stock_qty)
    product.stock_qty = payload.stock_qty
  }

  product.sale_price = payload.sale_price
  product.isSavingMeta = true
  product.pendingMetaSave = false

  try {
    const { data } = await updateProductInline(product.id, payload)
    const nextProduct = normalizeProduct(data?.data?.product || product)

    product.sale_price = nextProduct.sale_price
    product.saved_sale_price = nextProduct.sale_price
    product.stock_qty = nextProduct.stock_qty
    product.saved_stock_qty = nextProduct.stock_qty

    showToast(data?.message || 'Товар оновлено.')
  } catch (error) {
    console.error('Не вдалося оновити товар', error)
    product.sale_price = previous.sale_price
    product.stock_qty = previous.stock_qty

    showToast(error.response?.data?.message || 'Не вдалося оновити товар.', 'error')
  } finally {
    product.isSavingMeta = false

    if (product.pendingMetaSave) {
      product.pendingMetaSave = false
      requestProductSave(product)
    }
  }
}

const saveVariant = (productId, variantId) => {
  const found = findProductVariant(productId, variantId)
  if (!found) return

  requestVariantSave(found.product, found.variant)
}

const updateVariantAvailability = (productId, variantId, value) => {
  const found = findProductVariant(productId, variantId)
  if (!found) return

  const { product, variant } = found

  if (value === 'inactive') {
    variant.is_active = false
  } else if (value === 'out_of_stock') {
    variant.is_active = true
    variant.stock_qty = 0
  } else {
    variant.is_active = true

    if (normalizeStock(variant.stock_qty) === 0) {
      variant.stock_qty = 1
    }
  }

  requestVariantSave(product, variant)
}

const saveProduct = (productId) => {
  const product = findProduct(productId)
  if (!product) return

  requestProductSave(product)
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
    const targetId = deleteTarget.value.id

    await destroyProduct(targetId)

    products.value = products.value.filter((product) => product.id !== targetId)
    expandedProductIds.value = expandedProductIds.value.filter((id) => id !== targetId)
    pagination.total = Math.max(0, pagination.total - 1)

    if (!products.value.length && pagination.current_page > 1) {
      await loadProducts(pagination.current_page - 1)
    }

    showToast('Товар видалено.')
  } catch (error) {
    console.error('Не вдалося видалити товар', error)
    showToast(error.response?.data?.message || 'Не вдалося видалити товар.', 'error')
  } finally {
    deleteLoading.value = false
    closeDeleteModal()
  }
}

onMounted(() => {
  loadProducts()
  loadCategories()
})

onUnmounted(() => {
  if (searchTimer) {
    clearTimeout(searchTimer)
  }

  if (toastTimer) {
    clearTimeout(toastTimer)
  }
})
</script>

<style scoped>
.product-page {
  --crm-ink: #1e293b;
  --crm-muted: #64748b;
  --crm-line: #e2e8f0;
  --crm-soft: #f8fafc;
  --crm-primary: #2563eb;
  --crm-success: #15803d;
  --crm-warning: #b45309;
  --crm-danger: #dc2626;
}

.toolbar-panel,
.list-panel,
.list-pagination {
  background: #fff;
  border: 1px solid var(--crm-line);
  border-radius: 18px;
  box-shadow: 0 10px 28px rgba(15, 23, 42, 0.04);
}

.toolbar-panel {
  padding: 0.9rem 1rem;
}

.toolbar-panel__row,
.toolbar-panel__filters,
.toolbar-panel__meta,
.list-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.toolbar-panel__filters {
  flex: 1;
  flex-wrap: wrap;
}

.toolbar-panel__meta {
  margin-top: 0.65rem;
  font-size: 0.84rem;
  color: var(--crm-muted);
}

.filter-field {
  display: flex;
  align-items: center;
  min-height: 42px;
  min-width: 220px;
  padding: 0 0.8rem;
  border: 1px solid var(--crm-line);
  border-radius: 12px;
  background: var(--crm-soft);
}

.filter-field i {
  margin-right: 0.55rem;
  color: var(--crm-muted);
}

.filter-field input,
.filter-field select,
.inline-input-shell input,
.compact-select {
  box-shadow: none;
  background: transparent;
}

.btn-add-product,
.btn-reset {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  min-height: 42px;
  padding: 0.72rem 1rem;
  border-radius: 12px;
  font-weight: 700;
}

.btn-add-product {
  background: var(--crm-primary);
  color: #fff;
  border: 1px solid var(--crm-primary);
}

.btn-add-product:hover {
  color: #fff;
  background: #1d4ed8;
}

.btn-reset {
  border: 1px solid var(--crm-line);
  background: #fff;
  color: var(--crm-ink);
}

.list-panel {
  position: relative;
  margin-top: 1rem;
  overflow: hidden;
}

.list-panel.is-loading .list-body {
  opacity: 0.62;
}

.list-head,
.product-row {
  display: grid;
  grid-template-columns: 42px 74px minmax(220px, 2.1fr) minmax(110px, 0.8fr) minmax(130px, 1fr) minmax(130px, 0.8fr) minmax(150px, 0.9fr) 88px;
  gap: 0.9rem;
  align-items: center;
}

.list-head {
  padding: 0.9rem 1rem;
  border-bottom: 1px solid var(--crm-line);
  background: #f8fafc;
  font-size: 0.78rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #64748b;
}

.product-row {
  padding: 0.85rem 1rem;
  border-bottom: 1px solid #edf2f7;
}

.product-row:last-of-type {
  border-bottom: 0;
}

.product-row--expanded {
  background: #fbfdff;
}

.toggle-btn,
.btn-icon-action {
  width: 34px;
  height: 34px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  border: 1px solid var(--crm-line);
  background: #fff;
  color: var(--crm-ink);
  transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease;
}

.toggle-btn:hover:not(:disabled),
.btn-icon-action:hover {
  background: #eff6ff;
  color: var(--crm-primary);
  border-color: #bfdbfe;
}

.toggle-btn:disabled {
  opacity: 0.5;
  cursor: default;
}

.product-thumb {
  width: 58px;
  height: 58px;
  border-radius: 12px;
  overflow: hidden;
  background: #f1f5f9;
  border: 1px solid var(--crm-line);
}

.product-thumb img,
.product-thumb__placeholder {
  width: 100%;
  height: 100%;
}

.product-thumb img {
  object-fit: cover;
}

.product-thumb__placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #94a3b8;
}

.product-title {
  font-size: 1rem;
  font-weight: 700;
  color: var(--crm-ink);
}

.product-description {
  margin-top: 0.18rem;
  font-size: 0.87rem;
  line-height: 1.35;
  color: var(--crm-muted);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.mono-pill {
  display: inline-flex;
  align-items: center;
  min-height: 34px;
  padding: 0.3rem 0.7rem;
  border-radius: 10px;
  border: 1px solid var(--crm-line);
  background: #fff;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.88rem;
  color: #475569;
}

.mono-pill--soft {
  background: var(--crm-soft);
}

.meta-stack {
  display: grid;
  gap: 0.12rem;
}

.meta-stack strong {
  font-size: 0.93rem;
  color: var(--crm-ink);
}

.meta-stack small {
  color: var(--crm-muted);
}

.stock-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 34px;
  padding: 0.3rem 0.7rem;
  border-radius: 999px;
  font-size: 0.84rem;
  font-weight: 700;
}

.stock-badge--ok {
  color: var(--crm-success);
  background: #dcfce7;
}

.stock-badge--low {
  color: var(--crm-warning);
  background: #ffedd5;
}

.stock-badge--out {
  color: var(--crm-danger);
  background: #fee2e2;
}

.inline-input-shell {
  display: flex;
  align-items: center;
  min-height: 38px;
  padding: 0 0.7rem;
  border: 1px solid var(--crm-line);
  border-radius: 12px;
  background: #fff;
}

.inline-input-shell--stock {
  max-width: 120px;
}

.inline-input-shell input {
  padding-left: 0;
  padding-right: 0.35rem;
}

.inline-input-shell span {
  color: var(--crm-muted);
  font-size: 0.82rem;
  font-weight: 700;
}

.inline-saving {
  margin-left: 0.35rem;
}

.product-row__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.35rem;
}

.btn-icon-danger:hover {
  color: var(--crm-danger);
  border-color: #fecaca;
  background: #fef2f2;
}

.row-label {
  display: none;
  margin-bottom: 0.22rem;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--crm-muted);
}

.variants-panel {
  padding: 0 1rem 0.9rem 1rem;
  background: #fbfdff;
  border-bottom: 1px solid #edf2f7;
}

.variants-panel__head,
.variant-row,
.variants-table-head {
  display: grid;
  grid-template-columns: minmax(130px, 1fr) minmax(140px, 1fr) minmax(200px, 1.1fr) minmax(140px, 0.8fr);
  gap: 0.75rem;
  align-items: center;
}

.variants-panel__head {
  grid-template-columns: 1fr;
  gap: 0.15rem;
  padding: 0.1rem 0 0.65rem;
}

.variants-panel__head small {
  color: var(--crm-muted);
}

.variants-table-head {
  padding: 0.55rem 0.8rem;
  border-radius: 12px;
  background: #f8fafc;
  font-size: 0.74rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--crm-muted);
}

.variants-list {
  display: grid;
  gap: 0.45rem;
}

.variant-row {
  padding: 0.75rem 0.8rem;
  border: 1px solid var(--crm-line);
  border-radius: 14px;
  background: #fff;
}

.variant-row--saving {
  border-color: #bfdbfe;
  box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.08);
}

.compact-select {
  min-height: 38px;
  border: 1px solid var(--crm-line);
  border-radius: 12px;
}

.empty-state {
  display: grid;
  justify-items: center;
  gap: 0.35rem;
  padding: 2rem 1rem;
  color: var(--crm-muted);
}

.empty-state i {
  font-size: 1.9rem;
  color: #94a3b8;
}

.list-loader {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
}

.list-loader .spinner-border {
  color: var(--crm-primary);
}

.list-pagination {
  margin-top: 0.9rem;
  padding: 0.75rem 0.95rem;
  font-size: 0.9rem;
  color: var(--crm-muted);
}

.pagination {
  gap: 0.45rem;
}

.page-link {
  min-width: 40px;
  min-height: 40px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--crm-line);
  border-radius: 10px !important;
  background: #fff;
  color: var(--crm-ink);
  box-shadow: none;
}

.page-link--current {
  min-width: 82px;
  background: var(--crm-soft);
  font-weight: 700;
}

.products-toast {
  position: fixed;
  top: 1rem;
  right: 1rem;
  z-index: 1080;
  display: flex;
  align-items: center;
  gap: 0.8rem;
  min-width: 300px;
  max-width: min(92vw, 400px);
  padding: 0.85rem 0.95rem;
  border-radius: 16px;
  box-shadow: 0 20px 40px rgba(17, 32, 52, 0.22);
  color: #fff;
}

.products-toast.success {
  background: linear-gradient(135deg, #12956c 0%, #0f7e5d 100%);
}

.products-toast.error {
  background: linear-gradient(135deg, #d94f5c 0%, #b73845 100%);
}

.toast-icon {
  width: 38px;
  height: 38px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.16);
}

.toast-copy {
  display: grid;
  gap: 0.05rem;
  flex: 1;
}

.toast-copy span {
  font-size: 0.9rem;
}

.modal-backdrop-custom {
  position: fixed;
  inset: 0;
  z-index: 1050;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.45);
  backdrop-filter: blur(4px);
}

.modal-card {
  width: 100%;
  max-width: 420px;
  padding: 1.35rem;
  border-radius: 20px;
  background: #fff;
  box-shadow: 0 26px 54px rgba(15, 23, 42, 0.24);
}

.delete-product-preview {
  display: grid;
  gap: 0.12rem;
  padding: 0.9rem 1rem;
  border-radius: 14px;
  background: var(--crm-soft);
}

.delete-product-preview span {
  color: var(--crm-muted);
}

.toast-pop-enter-active,
.toast-pop-leave-active,
.expand-row-enter-active,
.expand-row-leave-active {
  transition: all 0.24s ease;
}

.toast-pop-enter-from,
.toast-pop-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}

.expand-row-enter-from,
.expand-row-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

@media (max-width: 1199.98px) {
  .list-head,
  .product-row {
    grid-template-columns: 42px 64px minmax(200px, 1.8fr) minmax(100px, 0.8fr) minmax(110px, 0.9fr) minmax(120px, 0.8fr) minmax(130px, 0.8fr) 82px;
  }
}

@media (max-width: 991.98px) {
  .product-row {
    grid-template-columns: 42px 64px minmax(0, 1fr) minmax(130px, 1fr);
    grid-template-areas:
      "toggle photo name actions"
      "toggle photo sku price"
      "toggle photo category stock";
    align-items: start;
  }

  .product-row__toggle { grid-area: toggle; }
  .product-row__photo { grid-area: photo; }
  .product-row__name { grid-area: name; }
  .product-row__actions { grid-area: actions; }
  .product-row__sku { grid-area: sku; }
  .product-row__price { grid-area: price; }
  .product-row__category { grid-area: category; }
  .product-row__stock { grid-area: stock; }

  .row-label {
    display: block;
  }

  .product-row__actions {
    justify-content: flex-start;
  }
}

@media (max-width: 767.98px) {
  .product-page {
    padding-left: 0.35rem !important;
    padding-right: 0.35rem !important;
  }

  .toolbar-panel,
  .list-panel,
  .list-pagination {
    border-radius: 14px;
  }

  .toolbar-panel {
    padding: 0.8rem;
  }

  .toolbar-panel__row,
  .toolbar-panel__filters,
  .toolbar-panel__meta,
  .list-pagination {
    flex-direction: column;
    align-items: stretch;
  }

  .filter-field {
    min-width: 0;
    width: 100%;
  }

  .btn-add-product,
  .btn-reset {
    width: 100%;
  }

  .product-row {
    grid-template-columns: 34px 56px minmax(0, 1fr);
    grid-template-areas:
      "toggle photo name"
      "toggle photo sku"
      "toggle photo category"
      "toggle photo stock"
      "toggle photo price"
      "toggle photo actions";
    gap: 0.65rem;
    padding: 0.8rem;
  }

  .product-thumb {
    width: 56px;
    height: 56px;
  }

  .variants-panel {
    padding: 0 0.8rem 0.8rem;
  }

  .variant-row,
  .variants-panel__head {
    grid-template-columns: 1fr;
  }

  .products-toast {
    right: 0.6rem;
    left: 0.6rem;
    top: 0.6rem;
    min-width: 0;
    max-width: none;
  }
}
</style>
