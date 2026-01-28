<template>
  <div class="container-fluid p-0 p-md-4">
    <div class="mx-auto" style="max-width: 1600px;">
      
      <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center mb-4 gap-3">
        <div>
          <div class="d-flex align-items-center gap-2 mb-1">
            <a href="/orders" class="btn btn-icon-back text-muted p-0 me-2">
              <i class="bi bi-arrow-left fs-5"></i>
            </a>
            <h1 class="h4 fw-bold text-dark m-0">
              {{ initialOrderId ? `Замовлення #${initialOrderId}` : 'Нове замовлення' }}
            </h1>
          </div>
        </div>
        
        <div class="d-flex align-items-center gap-2">
          <a href="/orders" class="btn btn-white border shadow-sm">
            Скасувати
          </a>
          <button 
            class="btn btn-primary-gradient shadow-sm d-flex align-items-center gap-2 px-4" 
            type="button" 
            @click="openConfirm" 
            :disabled="loading"
          >
            <span v-if="loading" class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            <i v-else class="bi bi-check-lg"></i>
            <span>{{ loading ? 'Збереження…' : 'Зберегти' }}</span>
          </button>
        </div>
      </div>

      <div v-if="fetching" class="py-5 text-center text-muted">
        <div class="spinner-border text-primary mb-2"></div>
        <div>Завантаження даних...</div>
      </div>

      <form v-else @submit.prevent="openConfirm">
        <div class="row g-4">
          
          <div class="col-12 col-lg-3">
            <div class="clean-card h-100">
              <div class="card-title-section">
                <i class="bi bi-sliders text-primary me-2"></i>Мета дані
              </div>
              <OrderMetaBlock v-model="form.meta" v-model:tagIds="form.tag_ids" />
            </div>
          </div>
          
          <div class="col-12 col-lg-4">
            <div class="clean-card h-100">
              <div class="card-title-section">
                <i class="bi bi-person text-purple me-2"></i>Клієнт
              </div>
              <CustomerBlock v-model="form.customer" />
            </div>
          </div>
          
          <div class="col-12 col-lg-5">
            <div class="clean-card h-100">
              <div class="card-title-section">
                <i class="bi bi-truck text-dark me-2"></i>Доставка
              </div>
              <DeliveryBlock v-model="form.delivery" />
            </div>
          </div>

          <div class="col-12 col-lg-8">
            <div class="clean-card h-100">
              <div class="card-title-section d-flex justify-content-between align-items-center mb-0 pb-3 border-bottom">
                <span><i class="bi bi-box-seam text-warning me-2"></i>Товари</span>
                <span class="badge bg-light text-dark border" v-if="form.items.length">
                  {{ form.items.length }} шт.
                </span>
              </div>
              <div class="pt-3">
                <ItemsTable
                  v-model="form.items"
                  :currency="form.meta.currency"
                  :prepay-amount="prepayAmount"
                  :prepay-enabled="form.payment.method === 'prepay'"
                />
              </div>
            </div>
          </div>
          
          <div class="col-12 col-lg-4">
            <div class="clean-card h-100">
              <div class="card-title-section">
                <i class="bi bi-credit-card text-success me-2"></i>Оплата
              </div>
              <PaymentBlock v-model="form.payment" :currency="form.meta.currency" />
            </div>
          </div>

        </div>
      </form>

      <div v-if="confirmOpen">
        <div class="modal-backdrop fade show bg-dark bg-opacity-50" style="backdrop-filter: blur(4px);"></div>
        <div class="modal fade show d-block" tabindex="-1" @click.self="closeConfirm">
          <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow-lg rounded-4">
              <div class="modal-body text-center p-4">
                <div class="mb-3 d-inline-flex align-items-center justify-content-center rounded-circle bg-primary bg-opacity-10 text-primary" style="width: 60px; height: 60px;">
                  <i class="bi bi-save fs-2"></i>
                </div>
                <h5 class="mb-2 fw-bold">Зберегти замовлення?</h5>
                <p class="text-muted small mb-4">
                  Перевірте дані. Після збереження ви будете перенаправлені до списку.
                </p>
                <div class="d-grid gap-2">
                  <button 
                    type="button" 
                    class="btn btn-primary-gradient shadow-sm fw-bold" 
                    :disabled="loading" 
                    @click="confirmOrder"
                  >
                    <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
                    {{ loading ? 'Обробка...' : 'Так, зберегти' }}
                  </button>
                  <button type="button" class="btn btn-light text-muted fw-medium" @click="closeConfirm">
                    Скасувати
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { createOrder, getOrder, updateOrder } from '@/crm/api/orders';

import CustomerBlock from '@/crm/components/orders/CustomerBlock.vue';
import OrderMetaBlock from '@/crm/components/orders/OrderMetaBlock.vue';
import ItemsTable from '@/crm/components/orders/ItemsTable.vue';
import PaymentBlock from '@/crm/components/orders/PaymentBlock.vue';
import DeliveryBlock from '@/crm/components/orders/DeliveryBlock.vue';

const loading = ref(false);
const fetching = ref(false);
const confirmOpen = ref(false);
const props = defineProps({
  initialOrderId: { type: [String, Number], default: null },
});

const form = reactive({
  customer: { first_name: '', last_name: '', phone: '', email: '' },
  meta: { currency: 'UAH', source: 'site', status: 'new', payment_status: 'unpaid' },
  items: [],
  payment: { method: 'cod', currency: 'UAH', prepay_amount: 0, note: '' },
  // Поля доставки ініціалізуємо
  delivery: { 
    carrier: 'nova_poshta', 
    delivery_type: 'warehouse', 
    service_type: '',
    payer: 'recipient', 
    ttn: '',
    city_ref: '',
    settlement_ref: '',
    city_name: '',
    warehouse_ref: '',
    warehouse_name: '',
    street_name: '',
    street_ref: '',
    building: '',
    apartment: '',
    address_note: '',
    recipient_name: '',
    recipient_phone: ''
  },
  tag_ids: [],
  comment_internal: '',
});

// Формуємо payload для відправки (враховуємо модель)
const payload = computed(() => ({
  customer: form.customer,
  order: { ...form.meta, comment_internal: form.comment_internal },
  items: form.items,
  payment: form.payment.method === 'prepay' ? form.payment : { ...form.payment, prepay_amount: 0 },
  
  // Мапимо payer -> delivery_payer
  delivery: {
    ...form.delivery,
    delivery_payer: form.delivery.payer 
  },
  
  tag_ids: form.tag_ids,
}));

const prepayAmount = computed(() =>
  form.payment.method === 'prepay' ? Number(form.payment.prepay_amount || 0) : 0
);

// --- MAIN LOGIC ---

async function submit() {
  loading.value = true;
  try {
    if (props.initialOrderId) {
      await updateOrder(props.initialOrderId, payload.value);
    } else {
      await createOrder(payload.value);
    }
    window.location.href = '/orders';
  } catch (error) {
    console.error('Помилка при збереженні:', error);
    alert('Не вдалося зберегти замовлення. Перевірте консоль.');
  } finally {
    loading.value = false;
    closeConfirm();
  }
}

function confirmOrder() {
  submit();
}

function openConfirm() {
  confirmOpen.value = true;
}

function closeConfirm() {
  if (!loading.value) {
    confirmOpen.value = false;
  }
}

// Завантаження даних
async function loadOrder() {
  if (!props.initialOrderId) return;
  fetching.value = true;
  try {
    const { data } = await getOrder(props.initialOrderId);
    const order = data?.data || data || {};
    
    // Клієнт
    Object.assign(form.customer, {
      first_name: order.customer?.first_name || '',
      last_name: order.customer?.last_name || '',
      phone: order.customer?.phone || '',
      email: order.customer?.email || '',
    });
    
    // Мета
    Object.assign(form.meta, {
      currency: order.currency || 'UAH',
      source: order.source || 'site',
      status: order.status || 'new',
      payment_status: order.payment_status || 'unpaid',
    });
    
    // Товари
    form.items = (order.items || []).map((i) => ({
      id: i.id,
      product_id: i.product_id,
      product_variant_id: i.product_variant_id || null,
      sku: i.sku,
      title: i.product_title || i.title,
      size: i.size,
      qty: i.qty,
      price: i.price,
      total: i.total,
      imageUrl: i.product?.main_photo_path ? `/storage/${i.product.main_photo_path}` : '',
    }));
    
    // Оплата
    const pay = order.payment || {};
    Object.assign(form.payment, {
      method: pay.method || 'cod',
      prepay_amount: pay.prepay_amount || 0,
      currency: pay.currency || order.currency || 'UAH',
      note: pay.note || '',
    });
    
    // Доставка (Правильний мапінг)
    const del = order.delivery || {};
    
    Object.assign(form.delivery, {
      carrier: del.carrier || 'nova_poshta',
      delivery_type: del.delivery_type || 'warehouse',
      service_type: del.service_type || '',
      
      // БЕРЕМО З БАЗИ delivery_payer І КЛАДЕМО В payer
      payer: del.delivery_payer || del.payer || 'recipient', 
      
      ttn: del.ttn || '',
      
      city_ref: del.city_ref || '',
      city_name: del.city_name || del.city || '', 
      warehouse_ref: del.warehouse_ref || '',
      warehouse_name: del.warehouse_name || del.warehouse || '',
      
      street_name: del.street_name || '',
      building: del.building || '',
      apartment: del.apartment || '',
      address_note: del.address_note || '',
      
      recipient_name: del.recipient_name || '',
      recipient_phone: del.recipient_phone || ''
    });
    
    // Інше
    form.tag_ids = (order.tags || []).map((t) => t.id);
    form.comment_internal = order.comment_internal || '';

  } catch (e) {
    console.error('Не вдалося завантажити замовлення', e);
  } finally {
    fetching.value = false;
  }
}

if (props.initialOrderId) {
  loadOrder();
}
</script>

<style scoped>
/* --- Card Design --- */
.clean-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  padding: 24px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.02);
  height: 100%;
}

.card-title-section {
  font-size: 0.85rem;
  font-weight: 700;
  color: #1e293b;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

/* --- Buttons --- */
.btn-primary-gradient {
  background: linear-gradient(135deg, #6366f1, #4f46e5);
  border: none;
  color: white;
  transition: all 0.2s;
}
.btn-primary-gradient:hover:not(:disabled) {
  box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35);
  transform: translateY(-1px);
  color: white;
}
.btn-white {
  background: #fff;
  color: #475569;
  font-weight: 500;
  transition: all 0.2s;
}
.btn-white:hover {
  background: #f8fafc;
  color: #1e293b;
}

/* --- Colors --- */
.text-purple { color: #8b5cf6; }
</style>
