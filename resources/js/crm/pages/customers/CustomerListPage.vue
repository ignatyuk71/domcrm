<template>
  <section class="customers-page">
    <div class="customers-shell">
      <header class="customers-header">
        <div class="customers-heading">
          <span class="customers-eyebrow">CRM</span>
          <h1>Клієнти</h1>
          <p>Єдина база контактів із замовлень та чату, без зайвого шуму в інтерфейсі.</p>
        </div>

        <div class="customers-stats">
          <article class="customers-stat">
            <span class="customers-stat-label">Знайдено</span>
            <strong>{{ meta.total || 0 }}</strong>
            <span class="customers-stat-note">Уся вибірка</span>
          </article>

          <article class="customers-stat">
            <span class="customers-stat-label">На сторінці</span>
            <strong>{{ customers.length }}</strong>
            <span class="customers-stat-note">Поточний список</span>
          </article>

          <article class="customers-stat">
            <span class="customers-stat-label">Без контакту</span>
            <strong>{{ missingContacts }}</strong>
            <span class="customers-stat-note">Без телефону й email</span>
          </article>
        </div>
      </header>

      <div class="customers-toolbar">
        <div class="search-wrapper">
          <i class="bi bi-search search-icon"></i>
          <input
            :value="filters.search"
            @input="handleSearch"
            type="text"
            class="search-input"
            placeholder="Пошук за ПІБ, телефоном або email..."
          />
        </div>

        <div class="customers-toolbar-note">
          <span v-if="loading">Оновлюємо список…</span>
          <span v-else-if="filters.search">Результати для: “{{ filters.search }}”</span>
          <span v-else>Останні додані клієнти зверху</span>
        </div>
      </div>

      <div class="customers-table-shell">
        <div class="table-responsive">
          <table class="table customers-table align-middle mb-0">
            <thead>
              <tr>
                <th class="ps-4">Клієнт</th>
                <th>Телефон</th>
                <th>Email</th>
                <th class="text-end pe-4">ID</th>
              </tr>
            </thead>

            <tbody>
              <tr v-for="customer in customers" :key="customer.id" class="customer-row">
                <td class="ps-4" data-label="Клієнт">
                  <div class="customer-main">
                    <div class="customer-avatar">
                      {{ customerInitials(customer) }}
                    </div>

                    <div class="customer-copy">
                      <div class="customer-title-row">
                        <div class="customer-name">
                          {{ formatName(customer) }}
                        </div>
                        <span class="customer-chip" :class="customerContactClass(customer)">
                          {{ customerContactLabel(customer) }}
                        </span>
                      </div>

                      <div class="customer-meta">
                        <span v-if="customer.phone" class="customer-meta-item">
                          <i class="bi bi-telephone"></i>
                          {{ formatPhone(customer.phone) }}
                        </span>
                        <span v-if="customer.email" class="customer-meta-item">
                          <i class="bi bi-envelope"></i>
                          {{ customer.email }}
                        </span>
                        <span v-if="!customer.phone && !customer.email" class="customer-meta-item is-muted">
                          Контакти не заповнені
                        </span>
                      </div>
                    </div>
                  </div>
                </td>

                <td data-label="Телефон" class="customer-phone-cell">
                  <span v-if="customer.phone" class="customer-data">{{ formatPhone(customer.phone) }}</span>
                  <span v-else class="customer-empty">Не вказано</span>
                </td>

                <td data-label="Email">
                  <span v-if="customer.email" class="customer-data customer-email">{{ customer.email }}</span>
                  <span v-else class="customer-empty">Не вказано</span>
                </td>

                <td class="text-end pe-4" data-label="ID">
                  <span class="customer-id">#{{ customer.id }}</span>
                </td>
              </tr>

              <tr v-if="!loading && customers.length === 0">
                <td colspan="4" class="customers-empty-state">
                  <div class="customers-empty-copy">
                    <i class="bi bi-people"></i>
                    <strong>Клієнтів не знайдено</strong>
                    <span>Спробуй змінити запит або очистити пошук.</span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
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
import { computed, reactive, ref } from 'vue';
import OrdersPagination from '@/crm/components/orders/list/OrdersPagination.vue';
import { listCustomers } from '@/crm/api/customers';

const customers = ref([]);
const loading = ref(false);
const meta = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const filters = reactive({ search: '', page: 1, per_page: 30 });
const perPageOptions = [15, 30, 60];
let searchTimer;

const missingContacts = computed(() => (
  customers.value.filter((customer) => !customer.phone && !customer.email).length
));

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
  const name = [first, last].filter(Boolean).join(' ').trim();

  if (name !== '') {
    return name;
  }

  if (customer.email) {
    return customer.email;
  }

  if (customer.phone) {
    return formatPhone(customer.phone);
  }

  return `Клієнт #${customer.id}`;
}

function customerInitials(customer) {
  const name = formatName(customer);
  const parts = name.split(/\s+/).filter(Boolean).slice(0, 2);
  return parts.map((part) => part.charAt(0).toUpperCase()).join('') || 'К';
}

function formatPhone(phone) {
  const digits = String(phone || '').replace(/\D+/g, '');

  if (digits.length === 12 && digits.startsWith('380')) {
    return `+${digits.slice(0, 3)} ${digits.slice(3, 5)} ${digits.slice(5, 8)} ${digits.slice(8, 10)} ${digits.slice(10, 12)}`;
  }

  return phone || '—';
}

function customerContactLabel(customer) {
  if (customer.phone && customer.email) {
    return 'Повний';
  }

  if (customer.phone || customer.email) {
    return 'Частковий';
  }

  return 'Порожній';
}

function customerContactClass(customer) {
  if (customer.phone && customer.email) {
    return 'is-complete';
  }

  if (customer.phone || customer.email) {
    return 'is-partial';
  }

  return 'is-empty';
}

fetchData();
</script>

<style scoped>
.customers-page {
  padding: 20px;
}

.customers-shell {
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  background: #ffffff;
  box-shadow: 0 18px 42px rgba(15, 23, 42, 0.05);
  overflow: hidden;
}

.customers-header {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 18px;
  padding: 24px 24px 18px;
  border-bottom: 1px solid #eef2f7;
  background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
}

.customers-heading h1 {
  margin: 6px 0 8px;
  font-size: 32px;
  line-height: 1;
  font-weight: 800;
  letter-spacing: -0.04em;
  color: #0f172a;
}

.customers-heading p {
  margin: 0;
  max-width: 560px;
  color: #64748b;
  font-size: 14px;
  line-height: 1.5;
}

.customers-eyebrow {
  display: inline-flex;
  align-items: center;
  min-height: 24px;
  padding: 0 10px;
  border-radius: 999px;
  background: #eff6ff;
  color: #2563eb;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.customers-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(120px, 1fr));
  gap: 10px;
}

.customers-stat {
  min-width: 0;
  padding: 14px 16px;
  border: 1px solid #e5edf7;
  border-radius: 14px;
  background: #f8fbff;
}

.customers-stat-label {
  display: block;
  margin-bottom: 6px;
  color: #64748b;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.customers-stat strong {
  display: block;
  color: #0f172a;
  font-size: 26px;
  line-height: 1;
  font-weight: 800;
}

.customers-stat-note {
  display: block;
  margin-top: 6px;
  color: #94a3b8;
  font-size: 12px;
  line-height: 1.35;
}

.customers-toolbar {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 14px;
  align-items: center;
  padding: 18px 24px;
  border-bottom: 1px solid #eef2f7;
  background: #ffffff;
}

.search-wrapper {
  position: relative;
}

.search-icon {
  position: absolute;
  left: 15px;
  top: 50%;
  transform: translateY(-50%);
  color: #94a3b8;
  font-size: 0.95rem;
  pointer-events: none;
}

.search-input {
  width: 100%;
  height: 46px;
  padding: 0 16px 0 42px;
  border-radius: 12px;
  border: 1px solid #dbe4ef;
  background: #f8fafc;
  color: #0f172a;
  font-size: 14px;
  font-weight: 500;
  transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
}

.search-input:focus {
  outline: none;
  background: #ffffff;
  border-color: #3b82f6;
  box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.08);
}

.customers-toolbar-note {
  color: #64748b;
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
}

.customers-table-shell {
  background: #ffffff;
}

.customers-table {
  margin: 0;
}

.customers-table thead th {
  position: sticky;
  top: 0;
  z-index: 1;
  padding-top: 14px;
  padding-bottom: 14px;
  border-bottom: 1px solid #e2e8f0;
  background: #f8fafc;
  color: #64748b;
  font-size: 12px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.customers-table tbody td {
  padding-top: 16px;
  padding-bottom: 16px;
  border-color: #eef2f7;
  vertical-align: middle;
}

.customer-row {
  transition: background 0.18s ease;
}

.customer-row:hover {
  background: #fbfdff;
}

.customer-main {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;
}

.customer-avatar {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #2563eb, #1d4ed8);
  color: #ffffff;
  font-size: 14px;
  font-weight: 800;
  flex-shrink: 0;
}

.customer-copy {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.customer-title-row {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
  flex-wrap: wrap;
}

.customer-name {
  min-width: 0;
  color: #0f172a;
  font-size: 16px;
  font-weight: 700;
  line-height: 1.25;
}

.customer-chip {
  display: inline-flex;
  align-items: center;
  min-height: 22px;
  padding: 0 9px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  white-space: nowrap;
}

.customer-chip.is-complete {
  background: #dcfce7;
  color: #15803d;
}

.customer-chip.is-partial {
  background: #fff7ed;
  color: #c2410c;
}

.customer-chip.is-empty {
  background: #f1f5f9;
  color: #64748b;
}

.customer-meta {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  color: #64748b;
  font-size: 12px;
  font-weight: 600;
}

.customer-meta-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.customer-meta-item i {
  font-size: 11px;
}

.customer-meta-item.is-muted {
  color: #94a3b8;
}

.customer-data {
  color: #0f172a;
  font-size: 14px;
  font-weight: 600;
}

.customer-phone-cell .customer-data {
  font-feature-settings: "tnum";
  font-variant-numeric: tabular-nums;
}

.customer-email {
  word-break: break-word;
}

.customer-empty {
  color: #94a3b8;
  font-size: 14px;
  font-weight: 500;
}

.customer-id {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 60px;
  min-height: 30px;
  padding: 0 10px;
  border-radius: 999px;
  background: #f8fafc;
  color: #334155;
  font-size: 13px;
  font-weight: 700;
}

.customers-empty-state {
  padding: 48px 24px !important;
  background: #ffffff;
}

.customers-empty-copy {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  color: #64748b;
}

.customers-empty-copy i {
  font-size: 32px;
  color: #94a3b8;
}

.customers-empty-copy strong {
  color: #0f172a;
  font-size: 18px;
  font-weight: 800;
}

.customers-empty-copy span {
  font-size: 14px;
}

@media (max-width: 1100px) {
  .customers-header {
    grid-template-columns: 1fr;
  }

  .customers-stats {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 768px) {
  .customers-page {
    padding: 12px;
  }

  .customers-shell {
    border-radius: 12px;
  }

  .customers-header,
  .customers-toolbar {
    padding-left: 14px;
    padding-right: 14px;
  }

  .customers-header {
    gap: 14px;
    padding-top: 18px;
    padding-bottom: 14px;
  }

  .customers-heading h1 {
    font-size: 24px;
  }

  .customers-heading p {
    font-size: 13px;
  }

  .customers-stats {
    grid-template-columns: 1fr;
  }

  .customers-toolbar {
    grid-template-columns: 1fr;
    gap: 10px;
  }

  .customers-toolbar-note {
    white-space: normal;
  }

  .customers-table thead {
    display: none;
  }

  .customers-table,
  .customers-table tbody,
  .customers-table tr,
  .customers-table td {
    display: block;
    width: 100%;
  }

  .customers-table tbody {
    padding: 8px;
  }

  .customer-row {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #ffffff;
    padding: 10px 12px;
    margin-bottom: 10px;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.04);
  }

  .customers-table tbody td {
    padding: 0;
    border: 0;
  }

  .customers-table tbody td + td {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #eef2f7;
  }

  .customers-table tbody td::before {
    content: attr(data-label);
    display: block;
    margin-bottom: 6px;
    color: #64748b;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
  }

  .customers-table tbody td.ps-4,
  .customers-table tbody td.pe-4,
  .customers-table tbody td.text-end {
    padding-left: 0 !important;
    padding-right: 0 !important;
    text-align: left !important;
  }

  .customer-main {
    align-items: flex-start;
  }

  .customer-avatar {
    width: 40px;
    height: 40px;
    border-radius: 10px;
  }

  .customer-name {
    font-size: 15px;
  }

  .customer-id {
    min-width: 0;
  }
}
</style>
