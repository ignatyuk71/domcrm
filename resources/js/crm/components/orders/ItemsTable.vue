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
          
          <div class="px-4 py-3 bg-white border-bottom d-flex align-items-center justify-content-between sticky-top z-index-10">
            <div>
              <h5 class="fw-bold mb-1 font-heading">Каталог товарів</h5>
              <div class="d-flex align-items-center gap-2 text-muted x-small text-uppercase ls-1">
                <span>Оберіть позиції</span>
              </div>
            </div>
            <button type="button" class="btn btn-icon btn-light rounded-circle text-secondary hover-dark" @click="closePicker">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>

          <div class="p-3 bg-light border-bottom">
            <select
              v-model="selectedCategory"
              class="form-select rounded-3 border-0 shadow-sm"
              style="width: 200px; height: 42px;"
            >
              <option value="">Всі категорії</option>
              <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>

          <div ref="pickerBody" class="offcanvas-body p-0 bg-secondary bg-opacity-10 custom-scroll" @scroll="handleScroll">
            
            <div v-if="loadingProducts && !products.length" class="h-100 d-flex align-items-center justify-content-center text-muted">
               <div class="spinner-border text-primary spinner-border-sm me-2"></div> Завантаження...
            </div>

            <div v-else-if="!filteredProducts.length" class="h-100 d-flex flex-column align-items-center justify-content-center text-muted opacity-50">
               <i class="bi bi-inbox fs-1 mb-2"></i>
               <span>Нічого не знайдено</span>
            </div>

            <div v-else class="d-flex flex-column gap-2 p-3">
              <div v-for="group in groupedProducts" :key="group.product_id" class="product-group-card">
                <div class="product-group-head" @click="toggleGroup(group.product_id)">
                  <div class="product-thumb-fixed border rounded-3 overflow-hidden bg-light flex-shrink-0 d-flex align-items-center justify-content-center position-relative">
                    <img v-if="group.imageUrl" :src="group.imageUrl" class="w-100 h-100 object-fit-cover" />
                    <i v-else class="bi bi-image text-muted small"></i>
                    <span class="stock-dot border border-white" :class="group.total_stock > 0 ? 'bg-success' : 'bg-danger'"></span>
                  </div>

                  <div class="flex-grow-1 min-w-0 d-flex justify-content-between gap-3">
                    <div class="d-flex flex-column justify-content-center pt-1 pb-1">
                      <div class="fw-bold text-dark lh-sm product-title mb-1">{{ group.title }}</div>
                      <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge bg-light text-secondary border fw-normal font-monospace px-1 py-0 rounded-1" style="font-size: 0.65rem;">
                          {{ group.sku || '---' }}
                        </span>
                        <span class="x-small" :class="group.total_stock > 0 ? 'text-success' : 'text-danger'">
                          {{ group.total_stock > 0 ? `Всього ${group.total_stock} шт.` : 'Немає' }}
                        </span>
                      </div>
                    </div>

                    <div class="d-flex flex-column align-items-end justify-content-between flex-shrink-0 gap-2 pt-1">
                      <div class="fw-bold text-primary" style="font-size: 0.95rem;">
                        {{ group.price }} <span class="text-muted fw-normal" style="font-size: 0.75rem;">{{ currency }}</span>
                      </div>
                      <button type="button" class="btn btn-sm btn-light text-primary fw-bold rounded-pill px-3 py-0 shadow-sm d-flex align-items-center gap-1 add-btn-compact">
                        <i class="bi" :class="isGroupOpen(group.product_id) ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                        <span>Розміри</span>
                      </button>
                    </div>
                  </div>
                </div>

                <div v-if="isGroupOpen(group.product_id)" class="product-group-variants">
                  <div
                    v-for="p in group.variants"
                    :key="p.id"
                    class="product-card bg-white p-2 rounded-3 shadow-sm d-flex align-items-start gap-3 cursor-pointer"
                    @click="addProductFromModal(p)"
                  >
                    <div class="flex-grow-1 min-w-0 d-flex justify-content-between gap-3">
                      <div class="d-flex flex-column justify-content-center pt-1 pb-1">
                        <div class="fw-bold text-dark lh-sm product-title mb-1">{{ p.size || 'Без розміру' }}</div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                          <span class="badge bg-light text-secondary border fw-normal font-monospace px-1 py-0 rounded-1" style="font-size: 0.65rem;">
                            {{ p.sku || '---' }}
                          </span>
                          <span class="x-small" :class="p.stock > 0 ? 'text-success' : 'text-danger'">
                            {{ p.stock > 0 ? `${p.stock} шт.` : 'Немає' }}
                          </span>
                        </div>
                      </div>

                      <div class="d-flex flex-column align-items-end justify-content-between flex-shrink-0 gap-2 pt-1">
                        <div class="fw-bold text-primary" style="font-size: 0.95rem;">
                          {{ p.price }} <span class="text-muted fw-normal" style="font-size: 0.75rem;">{{ currency }}</span>
                        </div>
                        <button type="button" class="btn btn-sm btn-light text-primary fw-bold rounded-pill px-3 py-0 shadow-sm d-flex align-items-center gap-1 add-btn-compact">
                          <i class="bi bi-plus-lg"></i>
                          <span>Дод.</span>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            
             <div v-if="loadingMore" class="py-3 text-center text-muted small">
              <div class="spinner-border spinner-border-sm text-primary me-2"></div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
// ... (ВЕСЬ SCRIPT ЗАЛИШАЄТЬСЯ БЕЗ ЗМІН) ...
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
const expandedGroups = ref(new Set());

const total = computed(() =>
  Math.round(model.value.reduce((sum, i) => sum + (Number(i.qty) || 0) * (Number(i.price) || 0), 0) * 100) / 100
);
const netTotal = computed(() =>
  Math.max(0, Math.round((total.value - props.prepayAmount) * 100) / 100)
);

const filteredProducts = computed(() => products.value);

const groupedProducts = computed(() => {
  const map = new Map();
  filteredProducts.value.forEach((p) => {
    const group = map.get(p.product_id) || {
      product_id: p.product_id,
      title: p.base_title || p.title || '',
      sku: p.parent_sku || '',
      imageUrl: p.imageUrl || '',
      price: p.price || 0,
      total_stock: 0,
      variants: [],
    };
    group.variants.push(p);
    group.total_stock += Number(p.stock || 0);
    map.set(p.product_id, group);
  });
  return Array.from(map.values());
});

function openPicker() {
  selectedCategory.value = '';
  page.value = 0;
  hasMore.value = true;
  products.value = [];
  expandedGroups.value = new Set();
  pickerOpen.value = true;
  fetchProducts(true);
  loadCategories();
}
function closePicker() {
  pickerOpen.value = false;
}

function addProductFromModal(p) {
  model.value.push({
    product_id: p.product_id || p.id || null,
    product_variant_id: p.product_variant_id || null,
    sku: p.sku || '',
    title: p.title || '',
    size: p.size || '',
    qty: 1,
    price: p.price || 0,
    imageUrl: p.imageUrl || '',
    main_photo_path: p.main_photo_path || '',
  });
}

function toggleGroup(productId) {
  const next = new Set(expandedGroups.value);
  if (next.has(productId)) {
    next.delete(productId);
  } else {
    next.add(productId);
  }
  expandedGroups.value = next;
}

function isGroupOpen(productId) {
  return expandedGroups.value.has(productId);
}

async function fetchProducts(reset = false) {
  if (loadingProducts.value || loadingMore.value) return;
  if (reset) loadingProducts.value = true;
  else loadingMore.value = true;
  
  try {
    const targetPage = reset ? 1 : page.value + 1;
    const { data } = await searchProducts({
      category: selectedCategory.value || undefined,
      page: targetPage,
      per_page: perPage,
      with_variants: true,
    });
    
    const payload = data || {};
    const list = Array.isArray(payload?.data) ? payload.data : (Array.isArray(payload) ? payload : []);
    
    const mapped = flattenProducts(list);

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

function flattenProducts(list) {
  return list.flatMap((p) => {
    const base = {
      product_id: p.id,
      price: p.sale_price || p.price || 0,
      imageUrl: buildImageUrl(p),
      main_photo_path: p.main_photo_path || '',
      parent_sku: p.sku || '',
      base_title: p.title || '',
    };

    if (Array.isArray(p.variants) && p.variants.length) {
      return p.variants.map((v) => ({
        id: v.id,
        product_id: p.id,
        product_variant_id: v.id,
        sku: v.sku || p.sku || '',
        title: `${p.title}${v.size ? ` (${v.size})` : ''}`,
        size: v.size || '',
        stock: Number(v.stock_qty || 0),
        ...base,
      }));
    }

    return [{
      id: p.id,
      product_id: p.id,
      product_variant_id: null,
      sku: p.sku || '',
      title: p.title || '',
      size: '',
      stock: Number(p.stock_qty || 0),
      ...base,
    }];
  });
}

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
.font-heading { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }

/* HEADER ICON */
.icon-box { width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; }

/* TABLE & MAIN LIST */
.item-thumb { width: 48px; height: 48px; border: 1px solid #edf2f7; background: #fff; }
.zoom-effect { transition: transform 0.3s ease; }
.item-thumb:hover .zoom-effect { transform: scale(1.1); }
.border-bottom-dashed { border-bottom: 1px dashed #e2e8f0; }

/* INPUTS */
.form-control-flush { background: transparent; border: 1px solid transparent; transition: all 0.2s; }
.form-control-flush:hover { background: #f8fafc; }
.form-control-flush:focus { background: #fff; border-color: #cbd5e1; box-shadow: none; }

/* STEPPER */
.btn-icon-xs { width: 24px; height: 24px; padding: 0; display: flex; align-items: center; justify-content: center; border: none; }
.btn-white { background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
.stepper-shadow { box-shadow: inset 0 1px 2px rgba(0,0,0,0.03); }
.hover-dark:hover { background: #1e293b; color: #fff !important; }
.hover-primary:hover { background: var(--bs-primary); color: #fff !important; }

/* BUTTONS */
.btn-icon-sm { width: 32px; height: 32px; padding: 0; border: none; background: transparent; }
.hover-danger:hover { color: #dc3545 !important; background: #fff0f0; opacity: 1 !important; transform: scale(1.1); }
.transition-all { transition: all 0.2s; }

/* EMPTY STATE */
.icon-circle-lg { width: 80px; height: 80px; }

/* MODAL STYLES (NEW) */
.glass-backdrop { backdrop-filter: blur(4px); background-color: rgba(30, 41, 59, 0.4); }

/* Product Card in List */
.product-card {
  transition: transform 0.15s ease, box-shadow 0.15s ease;
  border: 1px solid transparent;
}
.product-card:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.08) !important;
  border-color: #e2e8f0;
  z-index: 1;
}
.product-card:active { transform: translateY(0); }

/* Fixed Icon Size 45x45 */
.product-thumb-fixed {
  width: 45px; height: 45px;
  min-width: 45px; 
}
.stock-dot {
  width: 8px; height: 8px; border-radius: 50%;
  position: absolute; bottom: 2px; right: 2px;
}

/* Titles */
.product-title {
  font-size: 0.85rem;
  line-height: 1.35;
  white-space: normal;
  word-wrap: break-word;
}

/* Optimized Add Button */
.add-btn-compact {
  height: 24px;
  font-size: 0.75rem;
  transition: all 0.2s;
}
.product-card:hover .add-btn-compact {
  background-color: var(--bs-primary);
  color: white !important;
}

.product-group-card {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 6px;
}
.product-group-head {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px;
  border-radius: 10px;
  background: #ffffff;
  cursor: pointer;
}
.product-group-head:hover {
  background: #f1f5f9;
}
.product-group-variants {
  margin-top: 8px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

/* SCROLLBAR */
.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-track { background: transparent; }
.custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
</style>
