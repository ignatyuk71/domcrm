<template>
  <div class="d-flex flex-column gap-3">
    
    <div class="p-2 bg-light rounded-2 border d-flex justify-content-between align-items-center shadow-sm">
      <div>
        <label class="form-label small text-muted fw-bold text-uppercase mb-0" style="font-size: 0.65rem;">№ замовлення</label>
        <div class="fw-bold">{{ local.id || 'Автоматичний' }}</div>
      </div>
      <i class="bi bi-hash text-muted fs-5"></i>
    </div>

    <div class="row g-2">
      <div class="col-12">
        <label class="form-label small text-muted fw-bold text-uppercase mb-1" style="font-size: 0.65rem;">Джерело</label>
        <div 
          class="picker-btn border rounded-2 d-flex justify-content-between align-items-center bg-white shadow-sm"
          @click="openPicker('source')"
        >
          <div class="d-flex align-items-center gap-2">
            <div class="icon-box bg-primary-subtle text-primary">
              <i :class="getSourceIcon(local.source)"></i>
            </div>
            <span class="small fw-bold text-dark">{{ getSourceLabel(local.source) }}</span>
          </div>
          <i class="bi bi-chevron-expand text-muted"></i>
        </div>
      </div>

      <div class="col-6">
        <label class="form-label small text-muted fw-bold text-uppercase mb-1" style="font-size: 0.65rem;">Статус</label>
        <div 
          class="picker-btn border rounded-2 d-flex justify-content-between align-items-center shadow-sm text-white"
          :style="statusCardStyle"
          @click="openPicker('status')"
        >
          <div class="d-flex align-items-center gap-2 text-truncate">
            <i :class="getCurrentStatusIcon" class="fs-6 text-white"></i>
            <span class="small fw-bold text-truncate text-uppercase">{{ getStatusName(local.status) }}</span>
          </div>
        </div>
      </div>

      <div class="col-6">
        <label class="form-label small text-muted fw-bold text-uppercase mb-1" style="font-size: 0.65rem;">Оплата</label>
        <div 
          class="picker-btn border rounded-2 d-flex justify-content-between align-items-center shadow-sm text-white"
          :class="paymentCardClass"
          @click="openPicker('payment_status')"
        >
          <div class="d-flex align-items-center gap-2 text-truncate">
            <i class="bi bi-credit-card-fill text-white"></i>
            <span class="small fw-bold text-truncate text-uppercase">{{ getPaymentLabel(local.payment_status) }}</span>
          </div>
        </div>
      </div>
    </div>

    <div class="pt-2 border-top">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <label class="form-label small text-muted fw-bold text-uppercase mb-0">Теги</label>
        <button class="btn btn-sm btn-light border text-primary py-0 px-2 fw-bold" type="button" @click="tagsModal = true">
          <i class="bi bi-gear-fill me-1"></i> Керувати
        </button>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <template v-if="selectedTags.length">
          <div v-for="tag in selectedTags" :key="tag.id" class="badge d-flex align-items-center gap-2 p-2 border fw-normal text-dark shadow-sm" :style="styleTagBadge(tag)">
            <i :class="`bi ${tag.icon}`"></i>
            <span>{{ tag.name }}</span>
          </div>
        </template>
        <div v-else class="text-muted small fst-italic py-2 bg-light w-100 text-center rounded border-dashed">Теги відсутні</div>
      </div>
    </div>

    <div v-if="picker.isOpen">
      <div class="modal-backdrop fade show"></div>
      <div class="modal fade show d-block" tabindex="-1" @click.self="closePicker">
        <div class="modal-dialog modal-dialog-centered modal-sm">
          <div class="modal-content border-0 shadow-lg overflow-hidden">
            <div class="modal-header py-2 px-3 bg-dark text-white">
              <h6 class="modal-title fw-bold m-0 small text-uppercase tracking-wider">{{ picker.title }}</h6>
              <button type="button" class="btn-close btn-close-white" style="font-size: 0.6rem;" @click="closePicker"></button>
            </div>
            <div class="modal-body p-2 bg-light" style="max-height: 400px; overflow-y: auto;">
              <div class="list-group list-group-flush gap-1">
                <button 
                  v-for="option in picker.options" 
                  :key="option.value" 
                  class="list-group-item list-group-item-action border rounded-2 d-flex justify-content-between align-items-center py-2 px-3 shadow-sm mb-1"
                  :class="{ 'bg-primary text-white border-primary': isOptionSelected(option.value), 'bg-white': !isOptionSelected(option.value) }"
                  @click="selectOption(option.value)"
                >
                  <div class="d-flex align-items-center gap-3">
                    <i v-if="option.icon" :class="option.icon" :style="{ color: isOptionSelected(option.value) ? '#fff' : (option.color || 'inherit') }"></i>
                    <div v-else-if="option.color" class="rounded-circle" :style="{ width: '12px', height: '12px', backgroundColor: option.color, border: '1px solid rgba(0,0,0,0.1)' }"></div>
                    <span class="small fw-medium">{{ option.label }}</span>
                  </div>
                  <i v-if="isOptionSelected(option.value)" class="bi bi-check-circle-fill text-white"></i>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="tagsModal">
        <div class="modal-backdrop fade show"></div>
        <div class="modal fade show d-block" tabindex="-1" @click.self="closeModal">
          <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
              <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">Керування тегами</h5>
                <button type="button" class="btn-close" @click="closeModal"></button>
              </div>
              <div class="modal-body p-0">
                <div class="list-group list-group-flush">
                  <button 
                    v-for="tag in tags" 
                    :key="tag.id" 
                    class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3 px-4 border-bottom"
                    :class="{ 'bg-primary-subtle text-primary fw-bold': tagIdsSet.has(tag.id) }"
                    @click="toggleTag(tag.id)"
                  >
                    <div class="d-flex align-items-center justify-content-center rounded-circle border shadow-sm" :style="styleTagIconInModal(tag)">
                      <i :class="`bi ${tag.icon}`"></i>
                    </div>
                    <span class="flex-grow-1">{{ tag.name }}</span>
                    <i v-if="tagIdsSet.has(tag.id)" class="bi bi-check-circle-fill fs-5"></i>
                    <i v-else class="bi bi-circle text-muted fs-5"></i>
                  </button>
                </div>
              </div>
              <div class="modal-footer bg-light p-2 border-0">
                <button class="btn btn-primary w-100 fw-bold py-2" @click="closeModal">ЗБЕРЕГТИ ЗМІНИ</button>
              </div>
            </div>
          </div>
        </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { fetchTags } from '@/crm/api/tags';
import { fetchOrderSources } from '@/crm/api/orderSources';
import { fetchStatuses } from '@/crm/api/statuses';

const props = defineProps({ errors: { type: Object, default: () => ({}) } });
const model = defineModel({ type: Object, default: () => ({ id: '', source: 'site', status: 'new', payment_status: 'unpaid' }) });
const tagModel = defineModel('tagIds', { type: Array, default: () => [] });

const local = reactive({ ...model.value });
const tagsModal = ref(false);
const tags = ref([]);
const statuses = ref([]);
const picker = reactive({ isOpen: false, type: '', title: '', options: [] });

const sources = ref([]);

const payments = [
  { value: 'unpaid', label: 'Не оплачено', color: '#dc3545', class: 'bg-danger' },
  { value: 'prepayment', label: 'Передоплата', color: '#f59e0b', class: 'bg-warning' },
  { value: 'paid', label: 'Оплачено', color: '#198754', class: 'bg-success' },
  { value: 'refund', label: 'Повернення', color: '#6c757d', class: 'bg-secondary' }
];

function openPicker(type) {
  picker.type = type;
  picker.isOpen = true;
  if (type === 'source') { 
    picker.title = 'Оберіть джерело'; 
    picker.options = sources.value; 
  }
  else if (type === 'status') { 
    picker.title = 'Статус замовлення'; 
    picker.options = statuses.value.map(s => ({ 
        value: s.code, label: s.name, icon: s.icon, color: s.color 
    })); 
  }
  else if (type === 'payment_status') { 
    picker.title = 'Статус оплати'; 
    picker.options = payments; 
  }
}

function selectOption(value) {
  local[picker.type] = value;
  closePicker();
}

const closePicker = () => picker.isOpen = false;
const isOptionSelected = (val) => local[picker.type] === val;
const getSourceLabel = (val) => sources.value.find(s => s.value === val)?.label || val;
const getSourceIcon = (val) => sources.value.find(s => s.value === val)?.icon || 'bi-share';
const getStatusName = (val) => statuses.value.find(s => s.code === val)?.name || '...';
const getCurrentStatusIcon = computed(() => statuses.value.find(s => s.code === local.status)?.icon || 'bi-info-circle');
const getPaymentLabel = (val) => payments.find(p => p.value === val)?.label || val;

// --- ЯСКРАВІ СТИЛІ ---
const statusCardStyle = computed(() => {
  const st = statuses.value.find((s) => s.code === local.status);
  return st?.color ? { 
    backgroundColor: st.color, 
    borderColor: st.color,
    textShadow: '0 1px 2px rgba(0,0,0,0.2)'
  } : { backgroundColor: '#6c757d' };
});

const paymentCardClass = computed(() => {
  const p = payments.find(p => p.value === local.payment_status);
  return p ? `${p.class} border-${p.value}` : 'bg-secondary';
});

// Логіка Тегів
const tagIdsSet = computed(() => new Set(tagModel.value || []));
const selectedTags = computed(() => tags.value.filter((t) => tagIdsSet.value.has(t.id)));
function toggleTag(id) {
  const next = new Set(tagIdsSet.value);
  if (next.has(id)) next.delete(id); else next.add(id);
  tagModel.value = Array.from(next);
}
function closeModal() { tagsModal.value = false; }
function hexToRgba(color) {
  const map = { red: '#ff4d6d', blue: '#6d5efc', amber: '#ffb020', teal: '#27c2a0', pink: '#ff85a1' };
  return map[color] || color || '#6c757d';
}
function styleTagBadge(tag) {
  const c = hexToRgba(tag.color);
  return { backgroundColor: `${c}15`, color: c, borderColor: `${c}40` };
}
function styleTagIconInModal(tag) {
  const c = hexToRgba(tag.color);
  return { width: '32px', height: '32px', backgroundColor: `${c}15`, color: c, borderColor: `${c}30` };
}

watch(local, (val) => { Object.assign(model.value, val); }, { deep: true });
onMounted(async () => {
  try {
    const [tagsRes, statusesRes, sourcesRes] = await Promise.all([
      fetchTags(),
      fetchStatuses({ type: 'order' }),
      fetchOrderSources({ type: 'order' }),
    ]);
    tags.value = Array.isArray(tagsRes.data?.data) ? tagsRes.data.data : (tagsRes.data ?? []);
    statuses.value = Array.isArray(statusesRes.data?.data) ? statusesRes.data.data : (statusesRes.data ?? []);
    const src = Array.isArray(sourcesRes.data?.data) ? sourcesRes.data.data : (sourcesRes.data ?? []);
    // normalize to value/label/icon/color
    sources.value = src.map((s) => ({
      value: s.code,
      label: s.name,
      icon: s.icon ? `bi ${s.icon}` : 'bi-share',
      color: s.color,
    }));
  } catch (e) { console.error(e); }
});
</script>

<style scoped>
.picker-btn {
  padding: 8px 12px;
  cursor: pointer;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  min-height: 42px;
}
.picker-btn:hover { filter: contrast(1.1) brightness(1.05); transform: translateY(-1px); }
.picker-btn:active { transform: translateY(0); }

.icon-box {
  width: 26px; height: 26px;
  display: flex; align-items: center; justify-content: center;
  border-radius: 6px; font-size: 0.9rem;
}

.border-dashed { border: 1px dashed #ced4da; }
.tracking-wider { letter-spacing: 0.05em; }
.modal-backdrop { opacity: 0.4; backdrop-filter: blur(2px); }

/* Плавний перехід для списків */
.list-group-item {
  transition: all 0.15s ease;
  border: 1px solid transparent !important;
}
</style>
