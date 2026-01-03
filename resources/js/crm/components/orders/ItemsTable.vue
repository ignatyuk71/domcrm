<template>
  <div>
    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
      <span class="fw-bold small text-uppercase text-muted">Товари ({{ model.length }})</span>
      <button class="btn btn-sm btn-white border shadow-sm" type="button" @click="openPicker">
        + Додати товар
      </button>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="bg-light text-secondary small">
              <tr>
                <th style="width:78px;">Зображення</th>
                <th style="width:120px;">Артикул</th>
                <th>Назва</th>
                <th style="width:110px;">Розмір</th>
                <th style="width:120px;">Кількість</th>
                <th style="width:130px;">Ціна продажу</th>
                <th style="width:46px;"></th>
              </tr>
            </thead>
            <transition-group name="item-row" tag="tbody">
              <tr v-for="(item, idx) in model" :key="item.sku + '-' + idx">
                <td>
                  <div class="d-flex align-items-center justify-content-center bg-light border rounded" style="width: 48px; height: 48px;" title="Фото">
                    <template v-if="item.imageUrl">
                      <img :src="item.imageUrl" alt="Фото" class="w-100 h-100 object-fit-cover rounded" />
                    </template>
                    <template v-else>
                      <span class="text-muted small">🖼️</span>
                    </template>
                  </div>
                </td>
                <td>
                  <input class="form-control form-control-sm font-monospace" v-model="item.sku" placeholder="SKU" />
                </td>
                <td>
                  <div class="fw-bold text-dark small">{{ item.title || '—' }}</div>
                  <div class="text-muted" style="font-size: 0.75rem;">Позиція для демо</div>
                </td>
                <td>
                  <input class="form-control form-control-sm" v-model="item.size" placeholder="Розмір" />
                </td>
                <td>
                  <input class="form-control form-control-sm" v-model.number="item.qty" type="number" min="1" step="1" />
                </td>
                <td>
                  <input class="form-control form-control-sm" v-model.number="item.price" type="number" step="0.01" />
                </td>
                <td class="text-center">
                  <button class="btn btn-sm btn-light text-danger" type="button" @click="remove(idx)">✕</button>
                </td>
              </tr>
            </transition-group>
          </table>
        </div>
        <div class="d-flex justify-content-end align-items-center gap-4 p-3 bg-light border-top">
          <div v-if="prepayEnabled && prepayAmount > 0" class="text-muted small">
            Передоплата: <span class="fw-bold text-dark">{{ prepayAmount }} {{ currency }}</span>
          </div>
          <div class="fs-5">
            <span class="text-muted me-2">Всього:</span>
            <span class="fw-bold text-primary">{{ netTotal }} {{ currency }}</span>
          </div>
        </div>

    <div v-if="pickerOpen">
      <div class="modal-backdrop fade show"></div>
      <div class="modal fade show d-block" tabindex="-1" @click.self="closePicker">
        <div class="modal-dialog modal-dialog-centered modal-lg">
          <div class="modal-content border-0 shadow">
            <div class="modal-header">
              <h5 class="modal-title fw-bold">Додати товар</h5>
              <button type="button" class="btn-close" @click="closePicker"></button>
            </div>
            <div class="modal-body">
              <input
                class="form-control"
                v-model="searchTerm"
                placeholder="Пошук за назвою або артикулом…"
              />
              <div class="text-muted small fw-bold mt-3 mb-2">Товари з бази</div>
              
              <div class="list-group" style="max-height: 400px; overflow-y: auto;">
                <div v-if="loadingProducts" class="p-3 text-center text-muted">Завантаження…</div>
                <div v-else-if="productsError" class="p-3 text-center text-danger">{{ productsError }}</div>
                <template v-else>
                  <button
                    v-for="p in filteredProducts"
                    :key="p.id || p.sku"
                    type="button"
                    class="list-group-item list-group-item-action d-flex align-items-center gap-3 p-2"
                    @click="addProductFromModal(p)"
                  >
                    <div class="d-flex align-items-center justify-content-center bg-light border rounded flex-shrink-0" style="width: 40px; height: 40px;">
                      <template v-if="p.imageUrl">
                        <img :src="p.imageUrl" alt="Фото" class="w-100 h-100 object-fit-cover rounded" />
                      </template>
                      <template v-else>
                        <span class="small text-muted">🖼️</span>
                      </template>
                    </div>
                    <div class="flex-grow-1">
                      <div class="fw-bold text-dark">{{ p.title || '—' }}</div>
                      <div class="small text-muted">
                        <span class="font-monospace">{{ p.sku || 'SKU?' }}</span>
                        <span v-if="p.size"> • Розмір: {{ p.size }}</span>
                      </div>
                    </div>
                    <span class="fw-bold text-primary">{{ p.price }} грн</span>
                  </button>
                  <div v-if="!filteredProducts.length" class="p-3 text-center text-muted">
                    Нічого не знайдено
                  </div>
                </template>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { searchProducts } from '@/crm/api/products';

// Основна модель таблиці
const model = defineModel({ type: Array, default: () => [] });
const props = defineProps({
  currency: { type: String, default: 'UAH' },
  prepayAmount: { type: Number, default: 0 },
  prepayEnabled: { type: Boolean, default: false },
});

// Стан модалки вибору товару
const pickerOpen = ref(false);
const searchTerm = ref('');
const loadingProducts = ref(false);
const products = ref([]);
const productsError = ref('');
let searchTimer = null;

const total = computed(() =>
  Math.round(
    model.value.reduce((sum, i) => sum + (Number(i.qty) || 0) * (Number(i.price) || 0), 0) * 100
  ) / 100
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
  // скидаємо пошук при кожному відкритті
  searchTerm.value = '';
  pickerOpen.value = true;
  fetchProducts();
}
function closePicker() {
  pickerOpen.value = false;
}
function addProductFromModal(p) {
  // додаємо обраний товар у таблицю з qty=1
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
  closePicker();
}

async function fetchProducts() {
  productsError.value = '';
  loadingProducts.value = true;
  try {
    const { data } = await searchProducts(searchTerm.value || '');
    const list = data?.data?.data ?? data?.data ?? data ?? [];
    products.value = Array.isArray(list)
      ? list.map((p) => ({
          id: p.id,
          sku: p.sku || '',
          title: p.title || '',
          size: p.length_cm ? `${p.length_cm}` : p.size || '',
          price: p.sale_price || p.price || 0,
          imageUrl: buildImageUrl(p),
          main_photo_path: p.main_photo_path || '',
        }))
      : [];
  } catch (e) {
    console.error('Не вдалося завантажити товари', e);
    products.value = [];
    productsError.value = 'Не вдалося завантажити товари';
  } finally {
    loadingProducts.value = false;
  }
}

function remove(idx) {
  model.value.splice(idx, 1);
}

function buildImageUrl(p) {
  const raw =
    p.main_photo_url ||
    p.main_photo ||
    p.main_photo_path ||
    (p.imageUrl ? p.imageUrl : '');
  if (!raw) return '';
  if (raw.startsWith('http')) return raw;
  let clean = raw.replace(/^\//, '');
  if (clean.startsWith('public/')) clean = clean.replace(/^public\//, '');
  return clean.startsWith('storage/') ? `/${clean}` : `/storage/${clean}`;
}

// Пошук при вводі
watch(
  () => searchTerm.value,
  () => {
    if (!pickerOpen.value) return;
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(fetchProducts, 250);
  }
);
</script>
