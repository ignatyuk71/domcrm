<template>
  <div class="container-fluid p-4">
    <div class="card border-0 shadow-sm rounded-3 overflow-hidden">
      <div class="card-header bg-white p-4 border-bottom d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-3">
        <div class="d-flex align-items-center">
          <!-- Icon: Shopping Cart -->
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-primary me-2"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
          <h1 class="h4 mb-0 fw-bold text-dark">Товари</h1>
        </div>
        <a class="btn btn-primary d-inline-flex align-items-center gap-2 px-4" href="/products/create">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          <span>Додати товар</span>
        </a>
      </div>

      <div class="p-4 bg-light border-bottom">
        <div class="input-group" style="max-width: 320px;">
          <span class="input-group-text bg-white border-end-0 text-muted ps-3">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          </span>
          <input
            v-model.trim="query"
            type="text"
            class="form-control border-start-0 ps-2 shadow-none"
            placeholder="Пошук за назвою або артикулом…"
            @input="debouncedLoad"
          />
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 text-nowrap">
          <thead class="bg-light text-uppercase text-secondary small fw-bold">
            <tr>
              <th class="px-4 py-3 border-bottom">Фото</th>
              <th class="px-4 py-3 border-bottom">Назва</th>
              <th class="px-4 py-3 border-bottom">SKU</th>
              <th class="px-4 py-3 border-bottom">Ціна</th>
              <th class="px-4 py-3 border-bottom">Запас</th>
              <th class="px-4 py-3 border-bottom">Категорія</th>
              <th class="px-4 py-3 border-bottom text-end">Дії</th>
            </tr>
          </thead>
          <tbody :class="{ 'opacity-50 pe-none': isLoading }">
            <tr v-for="p in products" :key="p.id">
              <td class="px-4 py-3">
                <div class="d-flex align-items-center justify-content-center bg-light border rounded" style="width: 48px; height: 48px;">
                  <template v-if="p.imageUrl">
                    <img :src="p.imageUrl" alt="Фото" class="w-100 h-100 object-fit-cover rounded" />
                  </template>
                  <template v-else>
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-secondary"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                  </template>
                </div>
              </td>
              <td class="px-4 py-3">
                <div class="fw-bold text-dark">{{ p.title }}</div>
                <div class="text-muted small text-truncate" style="max-width: 250px;" v-if="p.description">{{ p.description }}</div>
              </td>
              <td class="px-4 py-3 font-monospace small text-muted">{{ p.sku || '—' }}</td>
              <td class="px-4 py-3 fw-medium text-dark">{{ formatPrice(p.sale_price, p.currency) }}</td>
              <td class="px-4 py-3">
                <span class="badge rounded-pill" :class="p.stock_qty > 0 ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-danger-subtle text-danger border border-danger-subtle'">
                  {{ p.stock_qty ?? '0' }}
                </span>
              </td>
              <td class="px-4 py-3 text-secondary">{{ p.category || '—' }}</td>
              <td class="px-4 py-3 text-end">
                <a class="btn btn-sm btn-light text-primary border" :href="`/products/${p.id}/edit`" title="Редагувати">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                </a>
              </td>
            </tr>
            <tr v-if="products.length === 0 && !isLoading">
              <td colspan="7" class="text-center py-5 text-muted">
                <div class="d-flex flex-column align-items-center">
                  <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" class="mb-3 text-secondary"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                  <span>Товарів не знайдено</span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

        <!-- Pagination -->
      <div v-if="pagination.last_page > 1" class="card-footer bg-white p-4 border-top d-flex align-items-center justify-content-between">
        <div class="text-muted small d-none d-sm-block">
          Показано <span class="fw-bold text-dark">{{ (pagination.current_page - 1) * pagination.per_page + 1 }}</span> до <span class="fw-bold text-dark">{{ Math.min(pagination.current_page * pagination.per_page, pagination.total) }}</span> з <span class="fw-bold text-dark">{{ pagination.total }}</span> результатів
        </div>
        <nav>
          <ul class="pagination mb-0">
            <li class="page-item" :class="{ disabled: pagination.current_page <= 1 }">
              <button class="page-link" @click="changePage(pagination.current_page - 1)" aria-label="Попередня">
                <span aria-hidden="true">&laquo;</span>
              </button>
            </li>
            <li class="page-item" :class="{ disabled: pagination.current_page >= pagination.last_page }">
              <button class="page-link" @click="changePage(pagination.current_page + 1)" aria-label="Наступна">
                <span aria-hidden="true">&raquo;</span>
              </button>
            </li>
          </ul>
        </nav>
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
const pagination = reactive({
  current_page: 1,
  last_page: 1,
  total: 0,
  per_page: 15
})
let timer = null

const loadProducts = async (page = 1) => {
  isLoading.value = true
  try {
    const { data } = await http.get('/products', {
      headers: { Accept: 'application/json' },
      params: { 
        q: query.value || undefined,
        page: page
      },
    })
    const list = data?.data || data
    const arr = Array.isArray(list?.data) ? list.data : Array.isArray(list) ? list : []
    products.value = arr.map((p) => ({
      ...p,
      imageUrl: p?.main_photo_path ? `/storage/${p.main_photo_path}` : '',
    }))

    if (list?.current_page) {
      pagination.current_page = list.current_page
      pagination.last_page = list.last_page
      pagination.total = list.total
      pagination.per_page = list.per_page
    }
  } catch (e) {
    console.error('Не вдалося завантажити товари', e)
  } finally {
    isLoading.value = false
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

onMounted(loadProducts)

onUnmounted(() => {
  if (timer) clearTimeout(timer)
})
</script>
