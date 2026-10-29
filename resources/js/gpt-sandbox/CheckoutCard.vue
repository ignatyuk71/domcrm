<script setup>
import { computed } from 'vue';

const props = defineProps({ order: { type: Object, required: true } });
const payment = computed(() => ({ cod: 'Уся сума при отриманні', full: 'Повна оплата на картку / ФОП', deposit: '100 грн передоплати, решта при отриманні' })[props.order.payment] || props.order.payment || 'Ще не обрано');
const total = computed(() => props.order.total ?? null);
</script>

<template>
    <section class="border rounded-3 p-3 mt-4" aria-labelledby="gpt-checkout-title" data-test="checkout-card">
        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
            <h3 id="gpt-checkout-title" class="h6 fw-semibold mb-0">Тестова заявка</h3>
            <span class="badge text-bg-light border">{{ order.needs_confirmation ? 'Очікуємо погодження суми' : order.status === 'needs_human' ? 'Потрібна увага' : order.status === 'ready_for_manager' ? 'Готова до опрацювання' : 'Збираємо дані' }}</span>
        </div>
        <div v-for="(item, index) in order.items" :key="index" class="small border-bottom pb-2 mb-2">
            <div class="fw-semibold">{{ item.title }}</div>
            <div class="text-secondary">{{ [item.color, item.size].filter(Boolean).join(' · ') }} · {{ item.qty }} шт. × {{ item.price }} грн</div>
            <div>{{ item.line_total ?? '—' }} грн</div>
        </div>
        <p v-if="total !== null" class="fw-semibold small mb-3">Разом: {{ total }} грн</p>
        <dl class="small mb-0">
            <dt>Отримувач</dt><dd>{{ order.customer_name || 'Ще не вказано' }}</dd>
            <dt>Телефон</dt><dd>{{ order.phone || 'Ще не вказано' }}</dd>
            <dt>Нова пошта</dt><dd>{{ order.address || 'Ще не вказано' }}</dd>
            <dt>Оплата</dt><dd>{{ payment }}</dd>
            <template v-if="order.pay_now != null && order.pay_on_delivery != null">
                <dt>До переказу</dt><dd data-test="pay-now">{{ order.pay_now }} грн</dd>
                <dt>При отриманні</dt><dd data-test="pay-on-delivery">{{ order.pay_on_delivery }} грн</dd>
            </template>
            <template v-if="order.receipt">
                <dt>Квитанція</dt><dd>Отримана для перевірки. Зарахування коштів не підтверджено.</dd>
            </template>
        </dl>
        <p class="small text-secondary mb-0 mt-3">Лише тестова заявка. Замовлення в CRM та резерв товару не створюються.</p>
    </section>
</template>
