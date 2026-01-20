<template>
  <div class="h-100 d-flex flex-column bg-white rounded-4 shadow-sm border border-light-subtle overflow-hidden wrapper-container">
    
    <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center bg-white sticky-header">
      <div class="d-flex align-items-center gap-3">
        <div class="icon-box bg-primary bg-opacity-10 text-primary rounded-circle">
          <i class="bi bi-bag-check-fill fs-5"></i>
        </div>
        <div>
          <h6 class="mb-0 fw-bold text-dark font-heading">Кошик замовлення</h6>
          <div class="text-secondary x-small fw-medium text-uppercase ls-1">Позицій: {{ model.length }}</div>
        </div>
      </div>
      <button 
        class="btn btn-primary rounded-pill shadow-sm d-flex align-items-center gap-2 px-4 fw-medium btn-hover-effect" 
        type="button" 
        @click="openPicker"
      >
        <i class="bi bi-plus-lg text-white-50"></i>
        <span>Додати</span>
      </button>
    </div>

    <div class="flex-grow-1 overflow-auto custom-scroll bg-body-tertiary bg-opacity-10 position-relative">
      <table class="table table-borderless align-middle mb-0 w-100 position-relative" style="border-collapse: separate; border-spacing: 0;">
        <thead class="sticky-top bg-white shadow-sm z-index-10">
          <tr>
            <th class="ps-4 py-3 text-secondary x-small text-uppercase fw-bold bg-white border-bottom">Товар</th>
            <th class="py-3 text-secondary x-small text-uppercase fw-bold text-center bg-white border-bottom" style="width: 140px;">Кількість</th>
            <th class="py-3 text-secondary x-small text-uppercase fw-bold text-end bg-white border-bottom" style="width: 150px;">Ціна</th>
            <th class="py-3 text-secondary x-small text-uppercase fw-bold text-end bg-white border-bottom pe-4" style="width: 60px;"></th>
          </tr>
        </thead>
        
        <tbody v-if="model.length" class="bg-white">
          <tr v-for="(item, idx) in model" :key="item.sku + '-' + idx" class="item-row">
            <td class="ps-4 py-3 border-bottom-dashed">
              <div class="d-flex align-items-center gap-3">
                <div class="item-thumb rounded-3 shadow-sm flex-shrink-0 position-relative overflow-hidden">
                  <img v-if="item.imageUrl" :src="item.imageUrl" class="w-100 h-100 object-fit-cover zoom-effect" alt="img" />
                  <div v-else class="w-100 h-100 d-flex align-items-center justify-content-center bg-light text-secondary">
                    <i class="bi bi-image opacity-50"></i>
                  </div>
                </div>
                <div class="flex-grow-1 min-w-0">
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <span v-if="item.sku" class="badge bg-light text-secondary border fw-normal font-monospace px-2" style="font-size: 0.7rem;">{{ item.sku }}</span>
                  </div>
                  <input 
                    class="form-control form-control-flush fw-bold text-dark p-0 text-truncate" 
                    v-model="item.title" 
                    placeholder="Назва товару" 
                  />
                </div>
              </div>
            </td>

            <td class="text-center py-3 border-bottom-dashed">
              <div class="d-inline-flex align-items-center bg-light rounded-pill border p-1 stepper-shadow">
                <button 
                  class="btn btn-icon-xs rounded-circle btn-white text-secondary hover-dark"
                  @click="item.qty > 1 ? item.qty-- : remove(idx)"
                  type="button"
                >
                  <i :class="item.qty > 1 ? 'bi bi-dash' : 'bi bi-trash3 text-danger'" style="font-size: 0.8rem;"></i>
                </button>
                <input 
                  class="form-control border-0 text-center fw-bold bg-transparent p-0 mx-1" 
                  style="width: 40px; font-size: 0.95rem;"
                  v-model.number="item.qty" 
                  type="number" 
                  min="1" 
                />
                <button 
                  class="btn btn-icon-xs rounded-circle btn-white text-primary hover-primary"
                  @click="item.qty++"
                  type="button"
                >
                  <i class="bi bi-plus" style="font-size: 1rem;"></i>
                </button>
              </div>
            </td>

            <td class="text-end py-3 border-bottom-dashed">
              <div class="d-flex justify-content-end align-items-center gap-1 group-focus-within">
                <input 
                  class="form-control form-control-flush text-end fw-bold text-dark fs-6 p-0" 
                  style="width: 80px;"
                  v-model.number="item.price" 
                  type="number" 
                  step="0.01" 
                />
                <span class="text-muted small fw-normal">{{ currency }}</span>
              </div>
              <div class="text-muted x-small text-end mt-1 fw-medium" v-if="item.qty > 1">
                {{ (item.price * item.qty).toFixed(2) }} {{ currency }}
              </div>
            </td>

            <td class="text-end py-3 pe-4 border-bottom-dashed">
              <button 
                class="btn btn-icon-sm text-secondary opacity-25 hover-opacity-100 hover-danger transition-all" 
                type="button" 
                @click="remove(idx)"
              >
                <i class="bi bi-x-lg"></i>
              </button>
            </td>
          </tr>
        </tbody>
        
        <tbody v-else>
          <tr>
            <td colspan="4" class="py-5">
              <div class="text-center py-5">
                <div class="mb-3 position-relative d-inline-block">
                  <div class="icon-circle-lg bg-light text-secondary opacity-50 rounded-circle d-flex align-items-center justify-content-center mx-auto">
                    <i class="bi bi-basket2 fs-1"></i>
                  </div>
                  <span class="position-absolute top-0 end-0 p-2 bg-primary border border-white rounded-circle"></span>
                </div>
                <h6 class="fw-bold text-dark">Список порожній</h6>
                <p class="text-muted small mb-4">Почніть додавати товари до замовлення</p>
                <button class="btn btn-sm btn-outline-primary rounded-pill px-4 fw-medium" @click="openPicker">
                  Відкрити каталог
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="bg-white border-top p-4">
      <div class="d-flex justify-content-end">
        <div class="w-100" style="max-width: 320px;">
          <div v-if="prepayEnabled && prepayAmount > 0" class="d-flex justify-content-between align-items-center mb-2 px-2">
            <span class="text-muted small">Передоплата</span>
            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2">
              - {{ prepayAmount }} {{ currency }}
            </span>
          </div>
          
          <div class="p-3 bg-light bg-opacity-50 rounded-3 border d-flex justify-content-between align-items-end">
            <div>
              <div class="text-secondary x-small text-uppercase fw-bold mb-1">До сплати</div>
              <div class="text-muted small">Всього: {{ total }} {{ currency }}</div>
            </div>
            <div class="text-end lh-1">
              <span class="fs-3 fw-bolder text-primary tracking-tight">{{ netTotal }}</span>
              <span class="fs-6 text-muted fw-medium ms-1">{{ currency }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <div v-if="pickerOpen">
        <div class="offcanvas-backdrop fade show glass-backdrop" @click="closePicker"></div>
        
        <div class="offcanvas offcanvas-end show shadow-2xl border-0 d-flex flex-column" tabindex="-1" style="width: 600px; z-index: 1055;">
          
          <div class="px-4 py-3 bg-white border-bottom d-flex align-items-center justify-content-between">
            <div>
              <h5 class="fw-bold mb-1">Додати товар</h5>
              <div class="d-flex align-items-center gap-2 text-muted small">
                <i class="bi bi-search"></i>
                <span>Пошук по каталогу</span>
              </div>
            </div>
            <button type="button" class="btn btn-icon btn-light rounded-circle text-secondary" @click="closePicker">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>

          <div class="p-3 bg-light border-bottom">
            <div class="d-flex gap-2">
              <div class="position-relative flex-grow-1">
                <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-secondary opacity-50"></i>
                <input
                  class="form-control ps-5 rounded-3 border-0 shadow-sm"
                  style="height: 42px;"
                  v-model="searchTerm"
                  placeholder="Введіть назву або артикул..."
                  autofocus
                />
              </div>
              <select
                v-model="selectedCategory"
                class="form-select rounded-3 border-0 shadow-sm"
                style="width: 180px; height: 42px;"
              >
                <option value="">Всі</option>
                <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
              </select>
            </div>
          </div>

          <div ref="pickerBody" class="offcanvas-body p-0 custom-scroll bg-white" @scroll="handleScroll">
            <div v-if="loadingProducts && !products.length" class="h-100 d-flex align-items-center justify-content-center text-muted">
               <div class="spinner-border text-primary spinner-border-sm me-2"></div> Завантаження...
            </div>

            <div v-else-if="!filteredProducts.length" class="h-100 d-flex flex-column align-items-center justify-content-center text-muted opacity-50">
               <i class="bi bi-inbox fs-1 mb-2"></i>
               <span>Нічого не знайдено</span>
            </div>

            <div v-else class="list-group list-group-flush">
              <button
                v-for="p in filteredProducts"
                :key="p.id"
                type="button"
                class="list-group-item list-group-item-action p-3 border-bottom-dashed d-flex align-items-start gap-3 product-item"
                @click="addProductFromModal(p)"
              >
                <div class="position-relative flex-shrink-0 mt-1">
                   <div class="product-thumb-md border rounded-3 overflow-hidden bg-light d-flex align-items-center justify-content-center">
                     <img v-if="p.imageUrl" :src="p.imageUrl" class="w-100 h-100 object-fit-cover" />
                     <i v-else class="bi bi-image text-muted fs-5"></i>
                   </div>
                   <span class="position-absolute bottom-0 end-0 p-1 rounded-circle border border-white" 
                         :class="p.stock > 0 ? 'bg-success' : 'bg-danger'"></span>
                </div>

                <div class="flex-grow-1 overflow-hidden text-start">
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-light text-secondary border font-monospace fw-normal" style="font-size: 10px;">{{ p.sku || 'SKU?' }}</span>
                    <span v-if="p.stock !== undefined" class="small" :class="p.stock > 0 ? 'text-success' : 'text-danger'">
                      {{ p.stock > 0 ? `${p.stock} шт.` : 'Немає' }}
                    </span>
                  </div>
                  <div class="fw-bold text-dark lh-sm product-title">{{ p.title }}</div>
                </div>

                <div class="text-end ps-2 flex-shrink-0 mt-1">
                  <div class="fw-bold text-primary fs-5">{{ p.price }} <small class="text-muted fs-6 font-weight-normal">{{ currency }}</small></div>
                  <div class="btn btn-xs btn-light text-primary rounded-pill px-3 fw-bold mt-1 shadow-sm add-btn">
                    + Додати
                  </div>
                </div>
              </button>
            </div>
            
             <div v-if="loadingMore" class="py-3 text-center text-muted small border-top">
              <div class="spinner-border spinner-border-sm text-primary me-2"></div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

  </div>
</template>

<script setup>
// Script section same as before
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
    qty: 1,
    price: p.price || 0,
    imageUrl: p.imageUrl || '',
    main_photo_path: p.main_photo_path || '',
  });
}

async function fetchProducts(reset = false) {
  if (loadingProducts.value || loadingMore.value) return;
  if (reset) loadingProducts.value = true;
  else loadingMore.value = true;
  
  try {
    const targetPage = reset ? 1 : page.value + 1;
    const { data } = await searchProducts({
      q: searchTerm.value || undefined,
      category: selectedCategory.value || undefined,
      page: targetPage,
      per_page: perPage,
    });
    
    const payload = data || {};
    const list = Array.isArray(payload?.data) ? payload.data : (Array.isArray(payload) ? payload : []);
    
    const mapped = list.map((p) => ({
      id: p.id,
      sku: p.sku || '',
      title: p.title || '',
      price: p.sale_price || p.price || 0,
      stock: p.stock_qty,
      imageUrl: buildImageUrl(p),
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

watch(() => searchTerm.value, () => {
    if (!pickerOpen.value) return;
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      page.value = 0;
      hasMore.value = true;
      fetchProducts(true);
    }, 400);
});

watch(() => selectedCategory.value, () => {
    if (!pickerOpen.value) return;
    page.value = 0;
    hasMore.value = true;
    fetchProducts(true);
});

const loadCategories = async () => {
  if (categories.value.length) return;
  if (isLoadingCategories.value) return;
  isLoadingCategories.value = true;
  try {
    const { data } = await http.get('/products/categories');
    categories.value = Array.isArray(data) ? data : [];
  } catch (e) { console.error(e); } 
  finally { isLoadingCategories.value = false; }
};

const handleScroll = () => {
  const el = pickerBody.value;
  if (!el || loadingProducts.value || loadingMore.value || !hasMore.value) return;
  if (el.scrollTop + el.clientHeight >= el.scrollHeight - 200) {
    fetchProducts(false);
  }
};
</script>

<style scoped>
/* GENERAL UTILS */
.x-small { font-size: 0.7rem; }
.ls-1 { letter-spacing: 0.5px; }
.cursor-pointer { cursor: pointer; }
.z-index-10 { z-index: 10; }

/* HEADER ICON */
.icon-box {
  width: 42px; height: 42px;
  display: flex; align-items: center; justify-content: center;
}

/* TABLE STYLING */
.item-thumb {
  width: 48px; height: 48px;
  border: 1px solid #edf2f7;
  background: #fff;
}
.zoom-effect { transition: transform 0.3s ease; }
.item-thumb:hover .zoom-effect { transform: scale(1.1); }
.border-bottom-dashed { border-bottom: 1px dashed #e2e8f0; }

/* INPUTS */
.form-control-flush {
  background: transparent; border: 1px solid transparent;
  transition: all 0.2s;
}
.form-control-flush:hover { background: #f8fafc; }
.form-control-flush:focus {
  background: #fff; border-color: #cbd5e1; box-shadow: none;
}

/* STEPPER */
.btn-icon-xs {
  width: 24px; height: 24px; padding: 0; display: flex; align-items: center; justify-content: center; border: none;
}
.btn-white { background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
.stepper-shadow { box-shadow: inset 0 1px 2px rgba(0,0,0,0.03); }
.hover-dark:hover { background: #1e293b; color: #fff !important; }
.hover-primary:hover { background: var(--bs-primary); color: #fff !important; }

/* REMOVE BUTTON */
.btn-icon-sm { width: 32px; height: 32px; padding: 0; border: none; background: transparent; }
.hover-danger:hover { color: #dc3545 !important; background: #fff0f0; opacity: 1 !important; transform: scale(1.1); }
.transition-all { transition: all 0.2s; }

/* EMPTY STATE */
.icon-circle-lg { width: 80px; height: 80px; }

/* MODAL / PICKER */
.glass-backdrop { backdrop-filter: blur(4px); background-color: rgba(30, 41, 59, 0.4); }
.product-thumb-md { width: 48px; height: 48px; }
.product-item { transition: background 0.15s; }
.product-item:hover { background: #f8fafc; }
.product-item:hover .add-btn { background: var(--bs-primary); color: #fff !important; }

/* Product Title in Picker (NEW) */
.product-title {
  font-size: 0.85rem; 
  white-space: normal;
  word-wrap: break-word;
}

/* SCROLLBAR */
.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-track { background: transparent; }
.custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

/* REMOVE SPINNERS */
input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
</style>