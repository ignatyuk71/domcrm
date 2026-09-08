<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { formatDate, getDeliveryStatusStyle } from '@/crm/utils/orderDisplay';

defineOptions({ inheritAttrs: false });
const props = defineProps({
  order: { type: Object, required: true },
  compact: { type: Boolean, default: false },
});

const tooltipId = `delivery-status-${useId()}`;
const trigger = ref(null);
const panel = ref(null);
const visible = ref(false);
const positioned = ref(false);
const position = ref({ left: '8px', top: '8px' });
const textStyle = computed(() => ({ color: getDeliveryStatusStyle(props.order).color }));
const cleanText = (value) => {
  const text = String(value ?? '').trim();
  return text === '—' ? '' : text;
};
const label = computed(() => cleanText(props.order.delivery_status) || 'Статус невідомий');
const description = computed(() => {
  const text = cleanText(props.order.delivery_status_description);
  return text.toLocaleLowerCase() === label.value.toLocaleLowerCase() ? '' : text;
});
const details = computed(() => {
  const order = props.order;
  const courier = order.delivery_type === 'courier';
  const address = [
    cleanText(order.address),
    courier && cleanText(order.building) ? `буд. ${cleanText(order.building)}` : '',
    courier && cleanText(order.apartment) ? `кв. ${cleanText(order.apartment)}` : '',
  ].filter(Boolean).join(', ');
  const rows = [
    ['ТТН', cleanText(order.ttn)],
    ['Перевізник', cleanText(order.delivery_carrier)],
    ['Місто', cleanText(order.city_name)],
    [courier ? 'Адреса' : 'Відділення', address],
    ['Оновлено', formatDate(order.delivery_status_updated_at)],
    ['Перевірено', formatDate(order.last_tracked_at)],
  ];
  if (order.delivery_status_code === 'at_warehouse') {
    rows.push(['У відділенні з', formatDate(order.delivery_status_entered_at)]);
    const days = order.delivery_hold_days;
    if (cleanText(order.delivery_status_entered_at) && days !== null && days !== undefined && days !== '' && Number.isFinite(Number(days)) && Number(days) >= 0) {
      rows.push(['Зберігання', `${Number(days)} дн.`]);
    }
  }
  return rows.filter(([, value]) => cleanText(value));
});

let closeTimer;
let triggerHovered = false;
let panelHovered = false;
let triggerFocused = false;
let disposed = false;

function clearCloseTimer() {
  window.clearTimeout(closeTimer);
  closeTimer = undefined;
}

function updatePosition() {
  if (!visible.value || !trigger.value || !panel.value) return;
  const anchor = trigger.value.getBoundingClientRect();
  const popup = panel.value.getBoundingClientRect();
  const margin = 8;
  const gap = 8;
  const width = Math.min(320, window.innerWidth - margin * 2);
  const height = Math.min(popup.height, window.innerHeight - margin * 2);
  const below = window.innerHeight - anchor.bottom - gap - margin;
  const above = anchor.top - gap - margin;
  const preferredTop = height <= below || below >= above
    ? anchor.bottom + gap
    : anchor.top - height - gap;
  position.value = {
    left: `${Math.max(margin, Math.min(anchor.left, window.innerWidth - width - margin))}px`,
    top: `${Math.max(margin, Math.min(preferredTop, window.innerHeight - height - margin))}px`,
  };
  positioned.value = true;
}

function detachListeners() {
  window.removeEventListener('resize', updatePosition);
  window.removeEventListener('scroll', updatePosition, true);
  document.removeEventListener('keydown', onKeydown);
  document.removeEventListener('pointerdown', onOutsidePointer);
}

function closeTooltip() {
  clearCloseTimer();
  visible.value = false;
  positioned.value = false;
  panelHovered = false;
  detachListeners();
}

function onKeydown(event) {
  if (event.key === 'Escape') closeTooltip();
}

function onOutsidePointer(event) {
  if (!trigger.value?.contains(event.target) && !panel.value?.contains(event.target)) closeTooltip();
}

async function openTooltip() {
  clearCloseTimer();
  if (visible.value || disposed) return;
  visible.value = true;
  window.addEventListener('resize', updatePosition, { passive: true });
  window.addEventListener('scroll', updatePosition, { capture: true, passive: true });
  document.addEventListener('keydown', onKeydown);
  document.addEventListener('pointerdown', onOutsidePointer);
  await nextTick();
  if (!disposed) updatePosition();
}

function scheduleClose() {
  clearCloseTimer();
  // Даємо час перейти курсором із напису на вікно з інформацією.
  closeTimer = window.setTimeout(() => {
    closeTimer = undefined;
    if (!triggerHovered && !panelHovered && !triggerFocused) closeTooltip();
  }, 180);
}

function enterTrigger() {
  triggerHovered = true;
  openTooltip();
}

function leaveTrigger() {
  triggerHovered = false;
  scheduleClose();
}

function focusTrigger() {
  triggerFocused = true;
  openTooltip();
}

function blurTrigger() {
  triggerFocused = false;
  scheduleClose();
}

function enterPanel() {
  panelHovered = true;
  clearCloseTimer();
}

function leavePanel() {
  panelHovered = false;
  scheduleClose();
}

watch([label, description, details], updatePosition, { flush: 'post' });
onBeforeUnmount(() => {
  disposed = true;
  clearCloseTimer();
  detachListeners();
});
</script>

<template>
  <button
    ref="trigger"
    v-bind="$attrs"
    type="button"
    class="delivery-status-label"
    :class="{ 'delivery-status-label--compact': compact }"
    :style="textStyle"
    :aria-describedby="visible ? tooltipId : undefined"
    @mouseenter="enterTrigger"
    @mouseleave="leaveTrigger"
    @focus="focusTrigger"
    @blur="blurTrigger"
    @click.stop="openTooltip"
  >
    <i v-if="order.delivery_status_icon" :class="['bi', order.delivery_status_icon]" aria-hidden="true"></i>
    <span class="delivery-status-label__text">{{ label }}</span>
  </button>

  <Teleport to="body">
    <div
      v-if="visible"
      :id="tooltipId"
      ref="panel"
      role="tooltip"
      class="delivery-status-tooltip"
      :style="{ ...position, visibility: positioned ? 'visible' : 'hidden' }"
      @mouseenter="enterPanel"
      @mouseleave="leavePanel"
      @click.stop
    >
      <div class="delivery-status-tooltip__title" :style="textStyle">
        <i v-if="order.delivery_status_icon" :class="['bi', order.delivery_status_icon]" aria-hidden="true"></i>
        <span>{{ label }}</span>
      </div>
      <p v-if="description" class="delivery-status-tooltip__description">{{ description }}</p>
      <dl v-if="details.length" class="delivery-status-tooltip__details">
        <div v-for="[name, value] in details" :key="name">
          <dt>{{ name }}</dt>
          <dd>{{ value }}</dd>
        </div>
      </dl>
    </div>
  </Teleport>
</template>

<style scoped>
.delivery-status-label {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  min-width: 0;
  max-width: 100%;
  padding: 0;
  border: 0;
  margin: 0;
  background: none;
  border-radius: 0;
  font: inherit;
  font-weight: 700;
  text-align: left;
  cursor: default;
}
.delivery-status-label:focus-visible {
  outline: 2px solid currentColor;
  outline-offset: 3px;
}
.delivery-status-label > i,
.delivery-status-tooltip__title > i {
  flex-shrink: 0;
}
.delivery-status-label__text {
  min-width: 0;
  overflow-wrap: anywhere;
}
.delivery-status-label--compact {
  font-size: 0.75rem;
  letter-spacing: 0.03em;
  text-transform: uppercase;
}
.delivery-status-label--compact .delivery-status-label__text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.delivery-status-tooltip {
  position: fixed;
  z-index: 1080;
  box-sizing: border-box;
  width: min(320px, calc(100vw - 16px));
  max-height: calc(100vh - 16px);
  overflow-y: auto;
  padding: 14px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #fff;
  box-shadow: 0 6px 24px #0f172a24;
  color: #334155;
  font-size: 0.8125rem;
  font-weight: 400;
  line-height: 1.45;
  text-align: left;
  text-transform: none;
  letter-spacing: normal;
  overflow-wrap: anywhere;
  white-space: normal;
}
.delivery-status-tooltip__title {
  display: flex;
  align-items: flex-start;
  gap: 7px;
  font-weight: 700;
}
.delivery-status-tooltip__description {
  margin: 8px 0 0;
}
.delivery-status-tooltip__details {
  display: grid;
  gap: 7px;
  padding-top: 10px;
  margin: 10px 0 0;
  border-top: 1px solid #f1f5f9;
}
.delivery-status-tooltip__details > div {
  display: grid;
  grid-template-columns: 90px minmax(0, 1fr);
  gap: 8px;
}
.delivery-status-tooltip__details dt {
  color: #64748b;
  font-weight: 400;
}
.delivery-status-tooltip__details dd {
  margin: 0;
}
</style>
