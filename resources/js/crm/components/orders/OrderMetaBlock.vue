<template>
  <div class="d-flex flex-column gap-3 position-relative">
    
    <div class="info-card">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <label class="info-label">№ замовлення</label>
          <div class="info-value">{{ local.id || 'Автоматичний' }}</div>
        </div>
        <div class="icon-circle bg-light text-muted">
          <i class="bi bi-hash"></i>
        </div>
      </div>
    </div>

    <div>
      <label class="info-label mb-1">Джерело</label>
      <div class="custom-select-trigger" @click="openPicker('source')">
        <div class="d-flex align-items-center gap-2">
          <div class="icon-mini" :style="{ backgroundColor: '#6366f120', color: '#6366f1' }">
            <i :class="getSourceIcon(local.source)"></i>
          </div>
          <span class="fw-medium text-dark">{{ getSourceLabel(local.source) }}</span>
        </div>
        <i class="bi bi-chevron-down text-muted small"></i>
      </div>
    </div>

    <div class="row g-2">
      <div class="col-6">
        <label class="info-label mb-1">Статус</label>
        <div class="status-card" :style="getStatusStyle(local.status)" @click="openPicker('status')">
          <i :class="getStatusIcon(local.status)" class="fs-5"></i>
          <span class="text-truncate">{{ getStatusName(local.status) }}</span>
        </div>
      </div>

      <div class="col-6">
        <label class="info-label mb-1">Оплата</label>
        <div class="status-card" :class="getPaymentClass(local.payment_status)" @click="openPicker('payment_status')">
          <i class="bi bi-credit-card-fill fs-5"></i>
          <span class="text-truncate">{{ getPaymentLabel(local.payment_status) }}</span>
        </div>
      </div>
    </div>

    <hr class="border-light my-1 opacity-50">

    <div>
      <label class="info-label mb-2">Теги</label>
      
      <div class="d-flex flex-wrap gap-2 align-items-center position-relative">
        
        <div 
          v-for="tag in selectedTags" 
          :key="tag.id" 
          class="tag-badge animate-fade"
          :style="styleTagBadge(tag)"
        >
          <div class="d-flex align-items-center gap-1">
            <i :class="['bi', tag.icon]" v-if="tag.icon" :style="{ color: tag.color }"></i>
            <span>{{ tag.name }}</span>
          </div>
          <button type="button" class="remove-tag-btn" @click.stop="toggleTag(tag.id)">
            <i class="bi bi-x"></i>
          </button>
        </div>

        <div class="position-relative">
          <button 
            type="button" 
            class="btn-add-tag"
            @click.stop="toggleTagsMenu"
            :class="{ 'active': tagsMenuOpen }"
          >
            <i class="bi bi-plus-lg"></i>
            <span>Додати</span>
          </button>

          <div v-if="tagsMenuOpen" class="tags-popover shadow-lg">
            <div class="popover-header">
              <span class="small fw-bold text-muted text-uppercase">Оберіть зі списку</span>
              <button type="button" class="btn-close-mini" @click="tagsMenuOpen = false">
                <i class="bi bi-x-lg"></i>
              </button>
            </div>
            
            <div class="popover-body custom-scrollbar">
              <div 
                v-for="tag in tags" 
                :key="tag.id"
                class="popover-item"
                :class="{ 'selected': tagIdsSet.has(tag.id) }"
                @click="toggleTag(tag.id)"
              >
                <div class="custom-checkbox me-2" :class="{ 'checked': tagIdsSet.has(tag.id) }">
                  <i class="bi bi-check text-white" v-if="tagIdsSet.has(tag.id)"></i>
                </div>
                
                <div class="tag-preview" :style="styleTagBadge(tag)">
                  <i :class="['bi', tag.icon]" v-if="tag.icon"></i>
                  {{ tag.name }}
                </div>
              </div>
              
              <div v-if="tags.length === 0" class="text-center text-muted small py-3">
                Список порожній
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>

    <div v-if="tagsMenuOpen" class="fixed-overlay" @click="tagsMenuOpen = false"></div>

    <Teleport to="body">
      <div v-if="picker.isOpen">
        <div class="modal-backdrop fade show bg-dark bg-opacity-25" style="backdrop-filter: blur(2px);"></div>
        <div class="modal fade show d-block" tabindex="-1" @click.self="closePicker">
          <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
              <div class="modal-header border-bottom-0 pb-0 px-4 pt-4">
                <h6 class="modal-title fw-bold text-uppercase text-muted small">{{ picker.title }}</h6>
                <button type="button" class="btn-close shadow-none" @click="closePicker"></button>
              </div>
              <div class="modal-body p-2">
                <div class="d-flex flex-column gap-1">
                  <button 
                    v-for="opt in picker.options" 
                    :key="opt.value" 
                    type="button"
                    class="picker-option"
                    :class="{ 'selected': isOptionSelected(opt.value) }"
                    @click="selectOption(opt.value)"
                  >
                    <div class="d-flex align-items-center gap-3">
                      <div class="option-icon" :style="opt.color ? { backgroundColor: opt.color + '20', color: opt.color } : {}">
                        <i :class="opt.icon || 'bi-circle-fill'"></i>
                      </div>
                      <span class="fw-medium">{{ opt.label }}</span>
                    </div>
                    <i v-if="isOptionSelected(opt.value)" class="bi bi-check-circle-fill text-primary"></i>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

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
const tags = ref([]);
const statuses = ref([]);
const sources = ref([]);
const picker = reactive({ isOpen: false, type: '', title: '', options: [] });

// Стан меню тегів
const tagsMenuOpen = ref(false);

// Static data
const payments = [
  { value: 'unpaid', label: 'Не оплачено', color: '#dc3545', icon: 'bi-x-circle' },
  { value: 'prepayment', label: 'Передоплата', color: '#f59e0b', icon: 'bi-wallet2' },
  { value: 'paid', label: 'Оплачено', color: '#198754', icon: 'bi-check-circle' },
  { value: 'refund', label: 'Повернення', color: '#6c757d', icon: 'bi-arrow-counterclockwise' }
];

// --- Helpers ---
const getSourceLabel = (val) => sources.value.find(s => s.value === val)?.label || val;
const getSourceIcon = (val) => sources.value.find(s => s.value === val)?.icon || 'bi-share';

const getStatusName = (val) => statuses.value.find(s => s.code === val)?.name || '...';
const getStatusIcon = (val) => statuses.value.find(s => s.code === val)?.icon || 'bi-circle';
const getStatusStyle = (val) => {
    const s = statuses.value.find(s => s.code === val);
    return s?.color ? { backgroundColor: s.color, borderColor: s.color, color: '#fff' } : { backgroundColor: '#6c757d', color: '#fff' };
}

const getPaymentLabel = (val) => payments.find(p => p.value === val)?.label || val;
const getPaymentClass = (val) => {
    const map = {
        'unpaid': 'bg-danger text-white border-danger',
        'prepayment': 'bg-warning text-dark border-warning',
        'paid': 'bg-success text-white border-success',
        'refund': 'bg-secondary text-white border-secondary'
    };
    return map[val] || 'bg-light text-dark';
}

// --- Picker Logic ---
function openPicker(type) {
  picker.type = type;
  picker.isOpen = true;
  if (type === 'source') { 
    picker.title = 'Оберіть джерело'; 
    picker.options = sources.value.map(s => ({ ...s, color: '#6366f1' })); 
  }
  else if (type === 'status') { 
    picker.title = 'Змінити статус'; 
    picker.options = statuses.value.map(s => ({ value: s.code, label: s.name, icon: s.icon, color: s.color })); 
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

// --- Tags Logic ---
const tagIdsSet = computed(() => new Set(tagModel.value || []));
const selectedTags = computed(() => tags.value.filter((t) => tagIdsSet.value.has(t.id)));

function toggleTagsMenu() {
  tagsMenuOpen.value = !tagsMenuOpen.value;
}

function toggleTag(id) {
  const next = new Set(tagIdsSet.value);
  if (next.has(id)) next.delete(id); else next.add(id);
  tagModel.value = Array.from(next);
}

// Функція стилізації для тегів (пастельні кольори)
function styleTagBadge(tag) {
  const c = tag.color || '#6c757d';
  const isHex = c.startsWith('#');
  
  if (isHex) {
    return { 
      backgroundColor: c + '26', // 15% opacity
      color: c, 
      borderColor: c + '4d'      // 30% opacity
    };
  }
  return { backgroundColor: '#f1f5f9', color: '#64748b', borderColor: '#e2e8f0' };
}

// Watchers & Init
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
/* Info Cards & General */
.info-card {
  padding: 12px;
  background: #f8fafc;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
}
.info-label {
  font-size: 0.7rem;
  text-transform: uppercase;
  font-weight: 700;
  color: #94a3b8;
  letter-spacing: 0.02em;
}
.info-value {
  font-weight: 700;
  color: #1e293b;
  font-size: 0.95rem;
}
.icon-circle {
  width: 32px; height: 32px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
}

/* Custom Select Trigger */
.custom-select-trigger {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 12px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.2s;
}
.custom-select-trigger:hover {
  border-color: #cbd5e1;
  background: #fdfdfd;
}
.icon-mini {
  width: 24px; height: 24px;
  border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  font-size: 0.8rem;
}

/* Status Cards */
.status-card {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 12px;
  border-radius: 10px;
  font-size: 0.85rem;
  font-weight: 700;
  cursor: pointer;
  transition: transform 0.1s;
  border: 1px solid transparent;
  color: white;
  text-shadow: 0 1px 2px rgba(0,0,0,0.1);
}
.status-card:active { transform: scale(0.98); }

/* --- NEW TAGS STYLES --- */
.tag-badge {
  display: inline-flex; align-items: center;
  padding: 5px 10px;
  border-radius: 8px;
  font-size: 0.75rem;
  font-weight: 700;
  border: 1px solid transparent;
  cursor: default;
  gap: 6px;
}

.remove-tag-btn {
  background: none; border: none; padding: 0;
  display: flex; align-items: center;
  color: inherit;
  opacity: 0.6;
  font-size: 1rem;
  cursor: pointer;
  transition: opacity 0.2s;
}
.remove-tag-btn:hover { opacity: 1; }

.btn-add-tag {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 5px 12px;
  border-radius: 8px;
  font-size: 0.75rem;
  font-weight: 600;
  background: #fff;
  color: #64748b;
  border: 1px dashed #cbd5e1;
  transition: all 0.2s;
}
.btn-add-tag:hover, .btn-add-tag.active {
  background: #f8fafc;
  border-color: #94a3b8;
  color: #334155;
}

/* Popover Menu */
.tags-popover {
  position: absolute;
  top: calc(100% + 8px); left: 0;
  width: 260px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  z-index: 1005; /* Вище оверлею */
  display: flex; flex-direction: column;
}

.popover-header {
  padding: 10px 12px;
  border-bottom: 1px solid #f1f5f9;
  display: flex; justify-content: space-between; align-items: center;
}
.btn-close-mini {
  background: none; border: none; padding: 2px; color: #94a3b8; cursor: pointer;
  font-size: 0.9rem; border-radius: 4px; display: flex;
}
.btn-close-mini:hover { color: #64748b; background: #f1f5f9; }

.popover-body {
  padding: 6px;
  max-height: 220px;
  overflow-y: auto;
}

.popover-item {
  padding: 6px 8px;
  border-radius: 8px;
  cursor: pointer;
  display: flex; align-items: center;
  transition: background 0.1s;
}
.popover-item:hover { background: #f8fafc; }
.popover-item.selected { background: #f0f7ff; }

.custom-checkbox {
  width: 18px; height: 18px;
  border: 2px solid #cbd5e1;
  border-radius: 5px;
  display: flex; align-items: center; justify-content: center;
  transition: all 0.2s;
  flex-shrink: 0;
}
.custom-checkbox.checked {
  background: #6366f1;
  border-color: #6366f1;
}
.custom-checkbox i { font-size: 0.9rem; }

.tag-preview {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 2px 8px; border-radius: 6px;
  font-size: 0.75rem; font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Overlay */
.fixed-overlay {
  position: fixed;
  top: 0; left: 0; width: 100vw; height: 100vh;
  z-index: 1000;
  background: transparent;
}

/* Animations & Scrollbar */
.animate-fade { animation: fadeIn 0.2s ease; }
@keyframes fadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }

.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

/* Picker Modal Options Styles */
.picker-option {
  width: 100%;
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 12px;
  border: 1px solid transparent;
  background: transparent;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.2s;
  color: #334155;
}
.picker-option:hover { background: #f8fafc; }
.picker-option.selected { background: #eff6ff; color: #4f46e5; }
.option-icon {
  width: 28px; height: 28px;
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 0.9rem;
  background: #f1f5f9;
  color: #64748b;
}
</style>