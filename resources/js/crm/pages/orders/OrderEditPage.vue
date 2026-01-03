<template>
  <div class="container-fluid p-4">
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center mb-4 gap-3">
      <h1 class="h3 mb-0 fw-bold text-dark">Редагувати замовлення #{{ initialOrderId }}</h1>

      <div class="d-flex align-items-center gap-3">
        <div v-if="saved" class="d-flex align-items-center text-success">
          <i class="bi bi-check-circle-fill me-2"></i>
          <span class="fw-bold small">Збережено</span>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-light border" type="button" @click="confirmOrder" :disabled="loading">
            Підтвердити
          </button>
          <button class="btn btn-primary d-flex align-items-center gap-2" type="button" @click="openConfirm" :disabled="loading">
            <span v-if="loading" class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            <span>{{ loading ? 'Збереження…' : 'Зберегти' }}</span>
          </button>
        </div>
      </div>
    </div>

    <div v-if="fetching" class="d-flex justify-content-center align-items-center py-5">
      <div class="spinner-border text-primary" role="status">
        <span class="visually-hidden">Завантаження...</span>
      </div>
    </div>

    <form v-else @submit.prevent="submit">
      <div class="row g-4">
        <div class="col-12 col-lg-3">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-bold py-3">Мета дані</div>
            <div class="card-body">
              <OrderMetaBlock v-model="form.meta" v-model:tagIds="form.tag_ids" :errors="errors" />
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-4">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-bold py-3">Клієнт</div>
            <div class="card-body">
              <CustomerBlock v-model="form.customer" :errors="errors" />
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-5">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-bold py-3">Доставка</div>
            <div class="card-body">
              <DeliveryBlock v-model="form.delivery" :errors="errors" />
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-8">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-bold py-3">Товари</div>
            <div class="card-body p-0">
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
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-bold py-3">Оплата</div>
            <div class="card-body">
              <PaymentBlock v-model="form.payment" :currency="form.meta.currency" />
            </div>
          </div>
        </div>
      </div>
    </form>

    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header bg-light fw-bold small text-uppercase text-muted">Payload Preview</div>
      <div class="card-body bg-light-subtle">
        <pre class="mb-0 small text-muted" style="max-height: 200px; overflow-y: auto;">{{ prettyPayload }}</pre>
      </div>
    </div>

    <div v-if="confirmOpen">
      <div class="modal-backdrop fade show"></div>
      <div class="modal fade show d-block" tabindex="-1" @click.self="closeConfirm">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content border-0 shadow">
            <div class="modal-header">
              <h5 class="modal-title fw-bold">Підтвердити зміни?</h5>
              <button type="button" class="btn-close" @click="closeConfirm"></button>
            </div>
            <div class="modal-body">
              <p class="fw-bold mb-1">Зберегти зміни до замовлення #{{ initialOrderId }}?</p>
              <p class="text-muted small mb-0">Після збереження дані буде оновлено.</p>
            </div>
            <div class="modal-footer bg-light">
              <button type="button" class="btn btn-light border" @click="closeConfirm">Скасувати</button>
              <button type="button" class="btn btn-primary" :disabled="loading" @click="confirmOrder">
                {{ loading ? 'Збереження…' : 'Зберегти' }}
              </button>
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

const props = defineProps({
  initialOrderId: { type: [String, Number], default: null },
});

const loading = ref(false);
const fetching = ref(false);
const saved = ref(false);
const confirmOpen = ref(false);
const errors = reactive({});
const form = reactive({
  customer: { first_name: '', last_name: '', phone: '', email: '' },
  meta: { currency: 'UAH', source: 'site', status: 'new', payment_status: 'unpaid' },
  items: [],
  payment: { method: 'cod', currency: 'UAH' },
  delivery: { carrier: 'nova_poshta', delivery_type: 'warehouse', payer: 'recipient', ttn: '' },
  tag_ids: [],
  comment_internal: '',
});

const payload = computed(() => ({
  customer: form.customer,
  order: { ...form.meta, comment_internal: form.comment_internal },
  items: form.items,
  payment: form.payment.method === 'prepay'
    ? form.payment
    : { ...form.payment, prepay_amount: 0 },
  delivery: form.delivery,
  tag_ids: form.tag_ids,
}));

const prepayAmount = computed(() =>
  form.payment.method === 'prepay' ? Number(form.payment.prepay_amount || 0) : 0
);

const prettyPayload = computed(() => JSON.stringify(payload.value, null, 2));

function validate() {
  Object.keys(errors).forEach((key) => delete errors[key]);
  let isValid = true;

  if (!form.customer.first_name?.trim()) {
    errors.first_name = 'Вкажіть імʼя';
    isValid = false;
  }
  if (!form.customer.phone?.trim()) {
    errors.phone = 'Вкажіть телефон';
    isValid = false;
  }

  if (!form.delivery.city_name?.trim()) {
    errors.city_name = 'Вкажіть місто';
    isValid = false;
  }
  if (form.delivery.delivery_type === 'warehouse' && !form.delivery.warehouse_name?.trim()) {
    errors.warehouse_name = 'Оберіть відділення';
    isValid = false;
  }
  if (form.delivery.delivery_type === 'courier' && !form.delivery.street_name?.trim()) {
    errors.street_name = 'Вкажіть адресу';
    isValid = false;
  }

  return isValid;
}

async function submit() {
  if (!validate()) return;
  loading.value = true;
  saved.value = false;
  try {
    if (props.initialOrderId) {
      await updateOrder(props.initialOrderId, payload.value);
    } else {
      await createOrder(payload.value);
    }
    saved.value = true;
  } catch (error) {
    console.error(error);
    saved.value = false;
  } finally {
    loading.value = false;
  }
}

function confirmOrder() {
  submit();
}

function openConfirm() {
  if (!validate()) return;
  confirmOpen.value = true;
}
function closeConfirm() {
  confirmOpen.value = false;
}

async function loadOrder() {
  if (!props.initialOrderId) return;
  fetching.value = true;
  try {
    const { data } = await getOrder(props.initialOrderId);
    const order = data?.data || data || {};
    Object.assign(form.customer, {
      first_name: order.customer?.first_name || '',
      last_name: order.customer?.last_name || '',
      phone: order.customer?.phone || '',
      email: order.customer?.email || '',
    });
    Object.assign(form.meta, {
      currency: order.currency || 'UAH',
      source: order.source || 'site',
      status: order.status || 'new',
      payment_status: order.payment_status || 'unpaid',
    });
    form.items = (order.items || []).map((i) => ({
      id: i.id,
      product_id: i.product_id,
      sku: i.sku,
      title: i.product_title || i.title,
      size: i.size,
      qty: i.qty,
      price: i.price,
      total: i.total,
      imageUrl: i.product?.main_photo_path ? `/storage/${i.product.main_photo_path}` : '',
    }));
    const pay = order.payment || {};
    Object.assign(form.payment, {
      method: pay.method || 'cod',
      prepay_amount: pay.prepay_amount || 0,
      currency: pay.currency || order.currency || 'UAH',
      note: pay.note || '',
    });
    const del = order.delivery || {};
    Object.assign(form.delivery, {
      carrier: del.carrier || 'nova_poshta',
      delivery_type: del.delivery_type || 'warehouse',
      payer: del.delivery_payer || 'recipient',
      city_ref: del.city_ref || '',
      city_name: del.city_name || '',
      warehouse_ref: del.warehouse_ref || '',
      warehouse_name: del.warehouse_name || '',
      street_name: del.street_name || '',
      building: del.building || '',
      apartment: del.apartment || '',
      address_note: del.address_note || '',
      recipient_name: del.recipient_name || '',
      recipient_phone: del.recipient_phone || '',
      ttn: del.ttn || '',
    });
    form.tag_ids = (order.tags || []).map((t) => t.id);
    form.comment_internal = order.comment_internal || '';
  } catch (e) {
    console.error('Не вдалося завантажити замовлення', e);
  } finally {
    fetching.value = false;
  }
}

loadOrder();
</script>
