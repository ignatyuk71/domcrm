<template>
  <div class="items-wrapper h-100 d-flex flex-column">
    
    <div class="px-3 py-3 border-bottom d-flex justify-content-between align-items-center bg-light bg-opacity-50">
      <div class="fw-bold text-uppercase text-secondary small">
        <i class="bi bi-cart3 me-1"></i> Товари ({{ model.length }})
      </div>
      <button 
        class="btn btn-sm btn-white border shadow-sm fw-medium text-primary" 
        type="button" 
        @click="openPicker"
      >
        <i class="bi bi-plus-lg me-1"></i>Додати товар
      </button>
    </div>

    <div class="table-responsive flex-grow-1" style="min-height: 100px;">
      <table class="table align-middle mb-0 clean-table">
        <thead>
          <tr>
            <th class="ps-3" style="width: 70px;">Фото</th>
            <th style="min-width: 140px;">Товар</th>
            <th class="text-center" style="width: 90px;">Розмір</th>
            <th class="text-center" style="width: 100px;">К-сть</th>
            <th class="text-end" style="width: 120px;">Ціна</th>
            <th style="width: 50px;"></th>
          </tr>
        </thead>
        <tbody v-if="model.length">
          <tr v-for="(item, idx) in model" :key="item.sku + '-' + idx" class="item-row">
            <td class="ps-3">
              <div class="item-thumb">
                <img v-if="item.imageUrl" :src="item.imageUrl" alt="img" />
                <i v-else class="bi bi-image text-muted"></i>
              </div>
            </td>
            <td>
              <div class="d-flex flex-column gap-1">
                <input class="form-control form-control-sm border-0 bg-transparent p-0 fw-bold text-dark text-truncate" v-model="item.sku" placeholder="SKU" readonly style="cursor: default;" />
                <input class="form-control form-control-sm border-0 bg-transparent p-0 text-muted small" v-model="item.title" placeholder="Назва товару" />
              </div>
            </td>
            <td><input class="form-control form-control-sm text-center bg-light" v-model="item.size" placeholder="-" /></td>
            <td><input class="form-control form-control-sm text-center fw-bold" v-model.number="item.qty" type="number" min="1" step="1" /></td>
            <td>
              <div class="input-group input-group-sm">
                <input class="form-control text-end border-end-0" v-model.number="item.price" type="number" step="0.01" />
                <span class="input-group-text bg-white border-start-0 text-muted small px-1">{{ currency }}</span>
              </div>
            </td>
            <td class="text-center">
              <button class="btn btn-sm btn-icon-remove text-danger" type="button" @click="remove(idx)">
                <i class="bi bi-x-lg"></i>
              </button>
            </td>
          </tr>
        </tbody>
        <tbody v-else>
          <tr>
            <td colspan="6" class="text-center py-5 text-muted small fst-italic">
              <i class="bi bi-basket3 fs-3 d-block mb-2 text-secondary opacity-50"></i>
              Список товарів порожній
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="bg-light bg-opacity-50 border-top p-3 d-flex flex-column gap-2 align-items-end">
      <div v-if="prepayEnabled && prepayAmount > 0" class="d-flex justify-content-between w-100" style="max-width: 260px;">
        <span class="text-muted small">Передоплата:</span>
        <span class="fw-medium text-success">- {{ prepayAmount }} <small>{{ currency }}</small></span>
      </div>
      <div class="d-flex justify-content-between w-100 pt-2 border-top" style="max-width: 260px;">
        <span class="fw-bold text-dark">До сплати:</span>
        <span class="fs-5 fw-bold text-primary">{{ netTotal }} <small class="fs-6 text-muted fw-normal">{{ currency }}</small></span>
      </div>
    </div>

    <Teleport to="body">
      <div v-if="pickerOpen">
        <div class="offcanvas-backdrop fade show" @click="closePicker"></div>
        
        <div class="offcanvas offcanvas-end show border-0 shadow-lg d-flex flex-column" tabindex="-1" style="width: 600px;">
          
          <div class="offcanvas-header border-bottom bg-white">
            <h5 class="offcanvas-title fw-bold d-flex align-items-center gap-2">
              <i class="bi bi-box-seam text-primary"></i> Додати товар
            </h5>
            <button type="button" class="btn-close text-reset" @click="closePicker"></button>
          </div>

          <div class="p-3 bg-light border-bottom">
            <div class="d-flex gap-2 flex-wrap">
              <div class="position-relative flex-grow-1" style="min-width: 220px;">
                <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                <input
                  class="form-control form-control-sm ps-5 rounded-3 border-0 shadow-sm"
                  v-model="searchTerm"
                  placeholder="Пошук за назвою, артикулом..."
                  autofocus
                />
              </div>
              <div class="category-filter" style="min-width: 200px;">
                <select
                  v-model="selectedCategory"
                  class="form-select form-select-sm rounded-3 border-0 shadow-sm"
                >
                  <option value="">Всі категорії</option>
                  <option v-for="c in categories" :key="c" :value="c">
                    {{ c }}
                  </option>
                </select>
              </div>
            </div>
          </div>

          <div ref="pickerBody" class="offcanvas-body p-0 bg-white custom-scroll" @scroll="handleScroll">
            <div v-if="loadingProducts" class="p-5 text-center text-muted">
              <div class="spinner-border text-primary mb-2"></div>
              <div>Шукаємо товари...</div>
            </div>

            <div v-else-if="!filteredProducts.length" class="p-5 text-center text-muted">
              <i class="bi bi-search fs-1 text-light mb-3 d-block"></i>
              Нічого не знайдено
            </div>

            <div v-else class="list-group list-group-flush">
              <button
                v-for="p in filteredProducts"
                :key="p.id"
                type="button"
                class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-2 px-3 border-bottom product-item"
                @click="addProductFromModal(p)"
              >
                <div class="product-thumb-lg border bg-light rounded-3 flex-shrink-0">
                  <img v-if="p.imageUrl" :src="p.imageUrl" class="w-100 h-100 object-fit-cover rounded-3" alt="img" />
                  <i v-else class="bi bi-image text-muted fs-4"></i>
                </div>

                <div class="flex-grow-1 overflow-hidden">
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-light text-dark border font-monospace">{{ p.sku || 'NO-SKU' }}</span>
                    <span v-if="p.size" class="badge bg-light text-secondary border">Розмір: {{ p.size }}</span>
                  </div>
                  <div class="fw-bold text-dark text-truncate">{{ p.title }}</div>
                  <div class="small text-muted text-truncate" v-if="p.stock !== undefined">
                    В наявності: {{ p.stock }} шт.
                  </div>
                </div>

                <div class="text-end">
                  <div class="fw-bold text-primary mb-1">
                    {{ p.price }} <small class="fs-6 text-muted">{{ currency }}</small>
                  </div>
                  <div class="btn btn-sm btn-light text-primary rounded-pill px-3 fw-bold">
                    <i class="bi bi-plus-lg me-1"></i> Додати
                  </div>
                </div>
              </button>
            </div>

            <div v-if="loadingMore" class="p-3 text-center text-muted small">
              <div class="spinner-border spinner-border-sm text-primary me-2"></div>
              Завантажуємо ще товари...
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

const pickerOpen = ref(false);
const searchTerm = ref('');
const loadingProducts = ref(false);
const loadingMore = ref(false);
const products = ref([]);
const productsError = ref('');
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
  const q = searchTerm.value.trim().toLowerCase();
  return products.value.filter(
    (p) => !q || (p.sku || '').toLowerCase().includes(q) || (p.title || '').toLowerCase().includes(q)
  );
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
  // Не закриваємо пікер, щоб можна було додати декілька товарів підряд. 
  // Якщо хочеш закривати - розкоментуй:
  // closePicker(); 
}

async function fetchProducts(reset = false) {
  if (loadingProducts.value || loadingMore.value) return;
  productsError.value = '';
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
    }, 300);
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
  if (isLoadingCategories.value) return;
  isLoadingCategories.value = true;
  try {
    const { data } = await http.get('/products/categories', {
      headers: { Accept: 'application/json' },
    });
    categories.value = Array.isArray(data) ? data : [];
  } catch (e) {
    console.error('Не вдалося завантажити категорії', e);
    categories.value = [];
  } finally {
    isLoadingCategories.value = false;
  }
};

const handleScroll = () => {
  const el = pickerBody.value;
  if (!el || loadingProducts.value || loadingMore.value || !hasMore.value) return;
  const threshold = 180;
  if (el.scrollTop + el.clientHeight >= el.scrollHeight - threshold) {
    fetchProducts(false);
  }
};
</script>

<style scoped>
/* Table Styles */
.clean-table th {
  font-size: 0.75rem;
  text-transform: uppercase;
  color: #94a3b8;
  font-weight: 700;
  padding: 10px 8px;
  background: #fff;
  border-bottom: 1px solid #f1f5f9;
}
.clean-table td { padding: 8px; border-bottom: 1px solid #f8fafc; }
.item-thumb {
  width: 36px; height: 36px; border-radius: 8px; background: #f8fafc;
  border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.item-thumb img { width: 100%; height: 100%; object-fit: cover; }
.form-control-sm { font-size: 0.85rem; padding: 3px 6px; }
.form-control:focus { box-shadow: none; border-color: #6366f1; background: #fff; }
.btn-white { background: #fff; color: #475569; }
.btn-white:hover { background: #f8fafc; color: #1e293b; }
.btn-icon-remove { opacity: 0.5; transition: opacity 0.2s; }
.btn-icon-remove:hover { opacity: 1; background: #fee2e2; }

/* Offcanvas Styles */
.product-thumb-lg {
  width: 52px; height: 52px; display: flex; align-items: center; justify-content: center;
}
.product-item { transition: background 0.2s; font-size: 0.9rem; }
.product-item:hover { background: #f8fafc; }
.custom-scroll::-webkit-scrollbar { width: 6px; }
.custom-scroll::-webkit-scrollbar-track { background: #f1f5f9; }
.custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
</style>
