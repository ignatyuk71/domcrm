<template>
  <section class="workspace">
    <div class="card border-0 shadow-sm overflow-hidden">
      <div class="customer-toolbar px-4 py-3 border-bottom">
        <div class="d-flex flex-column flex-md-row align-items-md-center gap-3">
          <div class="search-wrapper w-100 w-md-auto">
            <i class="bi bi-search search-icon"></i>
            <input
              :value="filters.search"
              @input="handleSearch"
              type="text"
              class="form-control search-input"
              placeholder="Пошук (ПІБ, телефон, email)..."
            />
          </div>
          <div class="text-muted small" v-if="loading">Завантаження...</div>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-4">Клієнт</th>
              <th>Телефон</th>
              <th>Email</th>
              <th class="text-end pe-4">ID</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="customer in customers" :key="customer.id">
              <td class="ps-4">
                <div class="fw-semibold text-dark">
                  {{ formatName(customer) }}
                </div>
              </td>
              <td>{{ customer.phone || '—' }}</td>
              <td>{{ customer.email || '—' }}</td>
              <td class="text-end pe-4 text-muted">#{{ customer.id }}</td>
            </tr>
            <tr v-if="!loading && customers.length === 0">
              <td colspan="4" class="text-center text-muted py-4">
                Клієнтів не знайдено
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <OrdersPagination
        :meta="meta"
        :per-page="filters.per_page"
        :per-page-options="perPageOptions"
        @change-page="changePage"
        @update:per-page="updatePerPage"
      />
    </div>
  </section>
</template>

<script setup>
import { reactive, ref } from 'vue';
import OrdersPagination from '@/crm/components/orders/list/OrdersPagination.vue';
import { listCustomers } from '@/crm/api/customers';

const customers = ref([]);
const loading = ref(false);
const meta = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const filters = reactive({ search: '', page: 1, per_page: 30 });
const perPageOptions = [15, 30, 60];
let searchTimer;

async function fetchData() {
  loading.value = true;
  try {
    const { data } = await listCustomers({
      q: filters.search || undefined,
      page: filters.page,
      per_page: filters.per_page,
    });

    const payload = data.data || data?.data?.data || [];
    customers.value = payload;

    const metaPayload = data.meta || data?.data?.meta || data || {};
    meta.value = {
      current_page: metaPayload.current_page ?? 1,
      last_page: metaPayload.last_page ?? 1,
      total: metaPayload.total ?? customers.value.length,
      from: metaPayload.from ?? 0,
      to: metaPayload.to ?? 0,
    };
  } catch (error) {
    console.error('Не вдалося завантажити клієнтів', error);
  } finally {
    loading.value = false;
  }
}

function handleSearch(event) {
  const value = event.target.value;
  filters.search = value;
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    filters.page = 1;
    fetchData();
  }, 300);
}

function changePage(page) {
  if (page < 1 || page > meta.value.last_page) return;
  filters.page = page;
  fetchData();
}

function updatePerPage(value) {
  filters.per_page = value;
  filters.page = 1;
  fetchData();
}

function formatName(customer) {
  const first = customer.first_name || '';
  const last = customer.last_name || '';
  const name = [first, last].filter(Boolean).join(' ');
  return name || '—';
}

fetchData();
</script>

<style scoped>
.customer-toolbar {
  background: #fff;
}

.search-wrapper { position: relative; min-width: 280px; }
.search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.9rem; pointer-events: none; }
.search-input { padding-left: 38px; padding-right: 16px; height: 40px; border-radius: 12px; border: 1px solid #e2e8f0; background: #f8fafc; font-size: 0.9rem; font-weight: 500; transition: all 0.2s ease; }
.search-input:focus { background: #fff; border-color: #6366f1; box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1); color: #1e293b; }
</style>
