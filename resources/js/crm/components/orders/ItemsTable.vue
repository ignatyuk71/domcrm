<template>
  <div class="h-100 d-flex flex-column bg-white rounded-4 overflow-hidden shadow-sm border border-light-subtle">
    
    <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center bg-white">
      <div class="d-flex align-items-center gap-2">
        <div class="icon-square bg-primary bg-opacity-10 text-primary rounded-3">
          <i class="bi bi-cart3"></i>
        </div>
        <div>
          <h6 class="mb-0 fw-bold text-dark">Кошик замовлення</h6>
          <div class="text-muted small" style="font-size: 0.75rem;">Позицій: {{ model.length }}</div>
        </div>
      </div>
      <button 
        class="btn btn-primary rounded-3 shadow-sm d-flex align-items-center gap-2 px-3" 
        type="button" 
        @click="openPicker"
      >
        <i class="bi bi-plus-lg"></i>
        <span class="d-none d-sm-inline">Додати товар</span>
      </button>
    </div>

    <div class="flex-grow-1 overflow-auto custom-scroll bg-body-tertiary bg-opacity-10" style="min-height: 150px; position: relative;">
      <table class="table table-hover align-middle mb-0 modern-table w-100">
        <thead class="sticky-top bg-white shadow-sm" style="z-index: 5;">
          <tr>
            <th class="ps-4 py-3 text-secondary text-uppercase x-small fw-bold" style="width: 80px;">Фото</th>
            <th class="py-3 text-secondary text-uppercase x-small fw-bold">Товар / SKU</th>
            <th class="py-3 text-secondary text-uppercase x-small fw-bold text-center" style="width: 100px;">Розмір</th>
            <th class="py-3 text-secondary text-uppercase x-small fw-bold text-center" style="width: 110px;">Кількість</th>
            <th class="py-3 text-secondary text-uppercase x-small fw-bold text-end" style="width: 140px;">Ціна</th>
            <th class="py-3 text-secondary text-uppercase x-small fw-bold" style="width: 60px;"></th>
          </tr>
        </thead>
        <tbody v-if="model.length" class="bg-white">
          <tr v-for="(item, idx) in model" :key="item.sku + '-' + idx" class="item-row position-relative">
            <td class="ps-4 py-3">
              <div class="item-thumb shadow-sm rounded-3 overflow-hidden position-relative group-hover-scale">
                <img v-if="item.imageUrl" :src="item.imageUrl" alt="Product" class="w-100 h-100 object-fit-cover transition-transform" />
                <div v-else class="w-100 h-100 d-flex align-items-center justify-content-center bg-light text-secondary">
                  <i class="bi bi-image fs-5 opacity-50"></i>
                </div>
              </div>
            </td>
            <td class="py-3">
              <div class="d-flex flex-column gap-1 pe-3">
                <div class="d-flex align-items-center gap-2">
                   <span class="badge bg-light text-secondary border fw-normal font-monospace px-2 py-1">{{ item.sku || '---' }}</span>
                </div>
                <input 
                  class="form-control form-control-flush fw-semibold text-dark p-0" 
                  v-model="item.title" 
                  placeholder="Назва товару" 
                />
              </div>
            </td>
            <td class="text-center py-3">
               <input class="form-control form-control-sm text-center bg-light border-0 rounded-pill mx-auto" style="width: 60px;" v-model="item.size" placeholder="-" />
            </td>
            <td class="text-center py-3">
              <div class="qty-control d-inline-flex align-items-center border rounded-pill p-1 bg-white">
                <input 
                  class="form-control border-0 text-center fw-bold p-0 shadow-none bg-transparent" 
                  style="width: 50px; height: 24px;"
                  v-model.number="item.qty" 
                  type="number" 
                  min="1" 
                />
              </div>
            </td>
            <td class="text-end py-3">
              <div class="d-flex align-items-center justify-content-end gap-1">
                <input 
                  class="form-control form-control-flush text-end fw-bold text-primary p-0 fs-6" 
                  style="width: 80px;"
                  v-model.number="item.price" 
                  type="number" 
                  step="0.01" 
                />
                <span class="text-muted small">{{ currency }}</span>
              </div>
            </td>
            <td class="text-center py-3 pe-3">
              <button 
                class="btn btn-icon btn-sm text-secondary hover-danger rounded-circle" 
                type="button" 
                @click="remove(idx)"
                title="Видалити"
              >
                <i class="bi bi-trash3"></i>
              </button>
            </td>
          </tr>
        </tbody>
        <tbody v-else>
          <tr>
            <td colspan="6">
              <div class="d-flex flex-column align-items-center justify-content-center py-5 text-center">
                <div class="bg-light rounded-circle p-4 mb-3">
                  <i class="bi bi-basket2 text-secondary opacity-25" style="font-size: 2.5rem;"></i>
                </div>
                <h6 class="text-dark fw-bold mb-1">Кошик порожній</h6>
                <p class="text-muted small mb-3">Додайте товари зі складу або створіть власні позиції</p>
                <button class="btn btn-sm btn-outline-primary rounded-pill px-4" @click="openPicker">
                  Почати додавання
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="bg-white border-top p-4 d-flex justify-content-end">
      <div class="summary-card" style="min-width: 280px;">
        <div v-if="prepayEnabled && prepayAmount > 0" class="d-flex justify-content-between align-items-center mb-2 text-muted">
          <span class="small">Передоплата</span>
          <span class="fw-medium text-success">- {{ prepayAmount }} {{ currency }}</span>
        </div>
        
        <div class="d-flex justify-content-between align-items-end pt-3 border-top border-light-subtle">
          <div class="text-secondary small me-4">До сплати</div>
          <div class="d-flex flex-column align-items-end lh-1">
            <span class="fs-4 fw-bolder text-dark tracking-tight">{{ netTotal }} <small class="fs-6 text-muted fw-normal">{{ currency }}</small></span>
          </div>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <div v-if="pickerOpen">
        <div class="offcanvas-backdrop fade show glass-backdrop" @click="closePicker"></div>
        
        <div class="offcanvas offcanvas-end show shadow-lg d-flex flex-column border-0" tabindex="-1" style="width: 650px; z-index: 1055;">
          
          <div class="offcanvas-header border-bottom px-4 py-3 bg-white">
            <div>
              <h5 class="offcanvas-title fw-bold text-dark">Додати товар</h5>
              <p class="mb-0 text-muted small">Оберіть товари зі списку</p>
            </div>
            <button type="button" class="btn-close" @click="closePicker"></button>
          </div>

          <div class="p-3 bg-light border-bottom sticky-top">
            <div class="d-flex gap-2">
              <div class="position-relative flex-grow-1">
                <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-secondary"></i>
                <input
                  class="form-control ps-5 rounded-3 border-0 shadow-sm py-2"
                  v-model="searchTerm"
                  placeholder="Пошук..."
                  autofocus
                />
              </div>
              <div class="category-select-wrapper" style="min-width: 180px;">
                <select
                  v-model="selectedCategory"
                  class="form-select rounded-3 border-0 shadow-sm py-2 text-truncate"
                >
                  <option value="">Всі категорії</option>
                  <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                </select>
              </div>
            </div>
          </div>

          <div ref="pickerBody" class="offcanvas-body p-0 custom-scroll bg-white" @scroll="handleScroll">
            
            <div v-if="loadingProducts && !filteredProducts.length" class="d-flex flex-column align-items-center justify-content-center h-50 pt-5">
              <div class="spinner-border text-primary mb-3" role="status"></div>
              <span class="text-muted small">Завантаження каталогу...</span>
            </div>

            <div v-else-if="!filteredProducts.length" class="d-flex flex-column align-items-center justify-content-center h-50 pt-5 text-muted">
              <i class="bi bi-search fs-1 opacity-25 mb-3"></i>
              <p>Товарів не знайдено</p>
            </div>

            <div v-else class="d-flex flex-column">
              <div 
                v-for="p in filteredProducts"
                :key="p.id"
                class="product-item-card px-4 py-3 border-bottom d-flex align-items-center gap-3 cursor-pointer position-relative overflow-hidden"
                @click="addProductFromModal(p)"
              >
                <div class="hover-bg position-absolute top-0 start-0 w-100 h-100 bg-primary opacity-0 transition-opacity"></div>
                
                <div class="position-relative z-1 d-flex align-items-center gap-3 w-100">
                  <div class="product-thumb-lg bg-light rounded-3 border flex-shrink-0 d-flex align-items-center justify-content-center overflow-hidden" style="width: 56px; height: 56px;">
                    <img v-if="p.imageUrl" :src="p.imageUrl" class="w-100 h-100 object-fit-cover" />
                    <i v-else class="bi bi-image text-muted fs-5"></i>
                  </div>

                  <div class="flex-grow-1 overflow-hidden">
                    <div class="d-flex align-items-center gap-2 mb-1">
                      <span class="badge bg-white border text-secondary font-monospace rounded-pill px-2" style="font-size: 10px;">{{ p.sku || 'N/A' }}</span>
                      <span v-if="p.stock !== undefined" class="badge rounded-pill px-2" :class="p.stock > 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'">
                        {{ p.stock > 0 ? `${p.stock} в наявності` : 'Немає' }}
                      </span>
                    </div>
                    <div class="fw-bold text-dark text-truncate">{{ p.title }}</div>
                    <div v-if="p.size" class="small text-muted">Розмір: {{ p.size }}</div>
                  </div>

                  <div class="text-end ps-3">
                    <div class="fw-bold text-primary fs-5 mb-1">{{ p.price }} <small class="fs-6 text-muted">{{ currency }}</small></div>
                    <button class="btn btn-sm btn-light text-primary rounded-pill fw-bold border-0 px-3 add-btn-hover shadow-sm">
                      <i class="bi bi-plus-lg"></i>
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="loadingMore" class="py-3 text-center text-muted small border-top">
              <div class="spinner-border spinner-border-sm text-primary me-2"></div>
              Завантажуємо ще...
            </div>
          </div>
        </div>
      </div>
    </Teleport>

  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import http from '@/crm/api/http';
import { searchProducts } from '@/crm/api/products';

const model = defineModel({ type: Array, default: () => [] });
const props = defineProps({
  currency: { type: String, default: 'UAH' },
  prepayAmount: { type: Number, default: 0 },
  prepayEnabled: { type: Boolean, default: false },
});

// -- Логіка залишається майже без змін, лише додамо перевірки --
const pickerOpen = ref(false);
const searchTerm = ref('');
const loadingProducts = ref(false);
const loadingMore = ref(false);
const products = ref([]);
const categories = ref([]);
const selectedCategory = ref('');
const isLoadingCategories = ref(false);
const pickerBody = ref(null);
const page = ref(0);
const perPage = 50;
const hasMore = ref(true);
let searchTimer = null;

const total = computed(() =>
  Math.round(model.value.reduce((sum, i) => sum + (Number(i.qty) || 0) * (Number(i.price) || 0), 0) * 100) / 100
);
const netTotal = computed(() =>
  Math.max(0, Math.round((total.value - props.prepayAmount) * 100) / 100)
);

const filteredProducts = computed(() => {
  // Фільтрація вже йде з бекенду, але для плавності UI при очищенні
  const q = searchTerm.value.trim().toLowerCase();
  return products.value; 
});

function openPicker() {
  searchTerm.value = '';
  selectedCategory.value = '';
  page.value = 0;
  hasMore.value = true;
  products.value = [];
  pickerOpen.value = true;
  fetchProducts(true);
  loadCategories();
}
function closePicker() {
  pickerOpen.value = false;
}

function addProductFromModal(p) {
  model.value.push({
    product_id: p.id || null,
    sku: p.sku || '',
    title: p.title || '',
    size: p.size,
    qty: 1,
    price: p.price || 0,
    imageUrl: p.imageUrl || '',
    main_photo_path: p.main_photo_path || '',
  });
  // Опціонально: візуальний фідбек про додавання (toast)
}

async function fetchProducts(reset = false) {
  if (loadingProducts.value || loadingMore.value) return;
  if (reset) {
    loadingProducts.value = true;
  } else {
    loadingMore.value = true;
  }
  try {
    const targetPage = reset ? 1 : page.value + 1;
    const { data } = await searchProducts({
      q: searchTerm.value || undefined,
      category: selectedCategory.value || undefined,
      page: targetPage,
      per_page: perPage,
    });
    const payload = data || {};
    const list = Array.isArray(payload?.data)
      ? payload.data
      : Array.isArray(payload)
        ? payload
        : [];
    
    const mapped = list.map((p) => ({
      id: p.id,
      sku: p.sku || '',
      title: p.title || '',
      size: p.length_cm ? `${p.length_cm}` : p.size || '',
      price: p.sale_price || p.price || 0,
      stock: p.stock_qty,
      imageUrl: buildImageUrl(p),
      main_photo_path: p.main_photo_path || '',
    }));

    if (reset) {
      products.value = mapped;
    } else {
      const existing = new Set(products.value.map((p) => p.id));
      products.value = products.value.concat(mapped.filter((p) => !existing.has(p.id)));
    }

    if (payload?.current_page) {
      page.value = payload.current_page;
      hasMore.value = payload.current_page < payload.last_page;
    } else {
      page.value = targetPage;
      hasMore.value = false;
    }
  } catch (e) {
    console.error(e);
    products.value = [];
  } finally {
    loadingProducts.value = false;
    loadingMore.value = false;
  }
}

function remove(idx) {
  model.value.splice(idx, 1);
}

function buildImageUrl(p) {
  const raw = p.main_photo_url || p.main_photo || p.main_photo_path || (p.imageUrl ? p.imageUrl : '');
  if (!raw) return '';
  if (raw.startsWith('http')) return raw;
  let clean = raw.replace(/^\//, '');
  if (clean.startsWith('public/')) clean = clean.replace(/^public\//, '');
  return clean.startsWith('storage/') ? `/${clean}` : `/storage/${clean}`;
}

watch(
  () => searchTerm.value,
  () => {
    if (!pickerOpen.value) return;
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      page.value = 0;
      hasMore.value = true;
      fetchProducts(true);
    }, 400); // Трохи збільшив затримку для кращої продуктивності
  }
);

watch(
  () => selectedCategory.value,
  () => {
    if (!pickerOpen.value) return;
    page.value = 0;
    hasMore.value = true;
    fetchProducts(true);
  }
);

const loadCategories = async () => {
  if (categories.value.length > 0) return; // Кешуємо категорії
  if (isLoadingCategories.value) return;
  isLoadingCategories.value = true;
  try {
    const { data } = await http.get('/products/categories', {
      headers: { Accept: 'application/json' },
    });
    categories.value = Array.isArray(data) ? data : [];
  } catch (e) {
    console.error('Не вдалося завантажити категорії', e);
  } finally {
    isLoadingCategories.value = false;
  }
};

const handleScroll = () => {
  const el = pickerBody.value;
  if (!el || loadingProducts.value || loadingMore.value || !hasMore.value) return;
  const threshold = 200;
  if (el.scrollTop + el.clientHeight >= el.scrollHeight - threshold) {
    fetchProducts(false);
  }
};
</script>

<style scoped>
/* Utility Tweaks */
.x-small { font-size: 0.7rem; letter-spacing: 0.05em; }
.tracking-tight { letter-spacing: -0.02em; }
.cursor-pointer { cursor: pointer; }

/* Icon Square */
.icon-square {
  width: 38px; height: 38px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem;
}

/* Modern Table Styles */
.modern-table th {
  background-color: #ffffff;
  border-bottom: 1px solid #edf2f7;
}
.modern-table td {
  border-bottom: 1px solid #f8f9fa;
  padding-top: 0.75rem;
  padding-bottom: 0.75rem;
}
.item-row { transition: background-color 0.2s; }
.item-row:hover { background-color: #fcfcfc; }

/* Item Thumb */
.item-thumb {
  width: 42px; height: 42px;
  border: 1px solid #e9ecef;
}
.item-thumb .transition-transform { transition: transform 0.2s; }
.item-row:hover .item-thumb .transition-transform { transform: scale(1.1); }

/* Form Controls "Flush" (Seamless editing) */
.form-control-flush {
  border: 1px solid transparent;
  background: transparent;
  padding: 4px 6px;
  border-radius: 4px;
  transition: all 0.2s;
}
.form-control-flush:hover {
  background: #f8f9fa;
}
.form-control-flush:focus {
  background: #fff;
  border-color: #86b7fe;
  box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15);
}

/* Buttons */
.btn-icon {
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  border: none;
}
.hover-danger:hover {
  background-color: #fee2e2;
  color: #dc3545 !important;
}

/* Offcanvas & Picker */
.glass-backdrop {
  backdrop-filter: blur(2px);
  background-color: rgba(0, 0, 0, 0.3);
}

.product-item-card { transition: all 0.2s; }
.product-item-card:hover .hover-bg { opacity: 0.04; }
.product-item-card:hover .add-btn-hover {
  background-color: var(--bs-primary);
  color: white !important;
}

/* Scrollbar */
.custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
.custom-scroll::-webkit-scrollbar-track { background: transparent; }
.custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.custom-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

/* Removing number arrows */
input[type=number]::-webkit-inner-spin-button, 
input[type=number]::-webkit-outer-spin-button { 
  -webkit-appearance: none; margin: 0; 
}
</style>