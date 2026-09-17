<template>
  <section class="cost-catalog" :aria-busy="loading || opening !== null">
    <header class="catalog-heading">
      <div>
        <button v-if="activeModel" type="button" class="back-button" :disabled="workspace?.isSaving" @click="back"><i class="bi bi-arrow-left" aria-hidden="true"></i> До категорій</button>
        <span v-else class="eyebrow">АНАЛІТИКА · ВИРОБНИЦТВО</span>
        <h1>{{ activeModel ? activeModel.name : 'Собівартість капців' }}</h1>
        <p>{{ activeModel ? 'Окремі розрахунки матеріалів для цієї категорії.' : 'Оберіть категорію — рахуйте матеріали, розкрій і витрати на одну пару.' }}</p>
      </div>
      <button v-if="!activeModel" type="button" class="btn catalog-refresh" :disabled="loading || opening !== null" @click="load"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Оновити</button>
    </header>

    <ProductionCostWorkspace v-if="activeModel" :key="activeModel.id" ref="workspace" :model-id="activeModel.id" />
    <template v-else>
      <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
      <p v-if="loading && !ready" class="catalog-empty" role="status">Завантажуємо категорії…</p>
      <div v-if="ready" class="category-grid">
        <button v-for="card in cards" :key="card.key" type="button" class="category-card" :disabled="opening !== null || loading" :data-testid="card.key" @click="select(card)">
          <span class="category-photo">
            <img v-if="safePhoto(card.photo_url) && !failedPhotos[card.key]" :src="safePhoto(card.photo_url)" alt="" loading="lazy" decoding="async" @error="failedPhotos[card.key] = true" />
            <i v-else class="bi bi-box-seam" aria-hidden="true"></i>
            <span v-if="card.records_count" class="category-status">Є розрахунки</span>
          </span>
          <span class="category-body"><span class="category-name">{{ card.name }}</span>
            <span class="category-details">{{ card.records_count ? `Матеріалів із даними: ${card.materials_count} · записів: ${card.records_count}` : 'Ще немає розрахунків' }}</span>
            <span class="category-action">{{ opening === card.key ? 'Відкриваємо…' : card.records_count ? 'Відкрити розрахунки' : 'Почати розрахунок' }}<i class="bi bi-arrow-up-right" aria-hidden="true"></i></span>
          </span>
        </button>
      </div>
      <div v-if="ready && otherCategories.length" class="other-categories">
        <button v-if="!adding" type="button" class="back-button" :disabled="opening !== null || loading" @click="adding = true"><i class="bi bi-plus-lg" aria-hidden="true"></i> Інша категорія з CRM</button>
        <form v-else @submit.prevent="selectOther">
          <label for="cost-other-category">Категорія з каталогу CRM</label>
          <div><select id="cost-other-category" v-model="otherId" class="form-select" required :disabled="opening !== null"><option value="" disabled>Оберіть категорію</option><option v-for="category in otherCategories" :key="category.id" :value="category.id">{{ category.name }}</option></select><button type="submit" class="btn btn-primary" :disabled="!otherId || opening !== null">Відкрити</button><button type="button" class="btn catalog-refresh" :disabled="opening !== null" @click="adding = false">Скасувати</button></div>
        </form>
      </div>
      <p v-if="ready" class="catalog-note"><i class="bi bi-info-circle" aria-hidden="true"></i> Ціни, розміри заготовок та історія зберігаються окремо для кожної категорії. Відсутні розрахунки — не нульова собівартість.</p>
    </template>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import ProductionCostWorkspace from './ProductionCostWorkspace.vue';
import { fetchCostModels, openCostModel, costError } from '@/crm/services/productionCostsApi';

const models = ref([]), categories = ref([]), loading = ref(false), ready = ref(false), opening = ref(null), error = ref('');
const activeModel = ref(null), workspace = ref(null), failedPhotos = ref({}), adding = ref(false), otherId = ref('');
const cards = computed(() => [
  ...[...models.value].sort((a, b) => Number(b.is_default) - Number(a.is_default)).map(model => ({ ...model, key: `model-${model.id}` })),
  ...categories.value.filter(category => category.footwear).map(category => ({ ...category, id: null, category_id: category.id, key: `category-${category.id}`, records_count: 0 })),
]);
const otherCategories = computed(() => categories.value.filter(category => !category.footwear));
function safePhoto(url) { return typeof url === 'string' && (/^https?:\/\//i.test(url) || /^\/(?!\/)/.test(url)) ? url : null; }
async function load() {
  if (loading.value || opening.value !== null) return;
  loading.value = true; error.value = '';
  try {
    const { data } = await fetchCostModels(); models.value = data.models; categories.value = data.categories; ready.value = true;
  } catch (err) { error.value = costError(err); }
  finally { loading.value = false; }
}
async function select(card) {
  if (opening.value !== null || loading.value) return;
  if (card.id) { activeModel.value = card; return; }
  opening.value = card.key; error.value = '';
  try { const { data } = await openCostModel(card.category_id); activeModel.value = data; }
  catch (err) { error.value = costError(err); }
  finally { opening.value = null; }
}
function selectOther() {
  const category = otherCategories.value.find(item => item.id === Number(otherId.value));
  if (category) select({ category_id: category.id, key: `category-${category.id}` });
}
function back() {
  if (workspace.value?.isSaving) return;
  if (workspace.value?.hasUnsavedChanges && !window.confirm('Є незбережені зміни матеріалів. Повернутися до категорій без збереження?')) return;
  activeModel.value = null; load();
}
onMounted(load);
</script>

<style scoped>
.cost-catalog{max-width:1720px;margin:auto;color:#182239}.catalog-heading{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:26px}.catalog-heading h1{font-size:28px;line-height:1.3;font-weight:800;letter-spacing:-.035em;margin:9px 0}.catalog-heading p{font-size:13px;color:#6b7c93;margin:0}.eyebrow{font-size:10px;font-weight:750;letter-spacing:.12em;color:#7466cb}.catalog-refresh{background:#fff;border:1px solid #e3e9f2;border-radius:10px;font-size:13px;color:#52647c;padding:10px 15px;white-space:nowrap}.catalog-refresh i{margin-right:5px}.category-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:22px}.category-card{display:flex;flex-direction:column;padding:0;overflow:hidden;border:1px solid #e3e9f2;border-radius:16px;background:#fff;text-align:left;color:inherit;min-width:0;transition:border-color .15s,box-shadow .15s}.category-card:hover{border-color:#b4aaf0;box-shadow:0 6px 22px #4738820b}.category-photo{position:relative;display:grid;place-items:center;aspect-ratio:1.8;background:#f4f5f9;width:100%;overflow:hidden}.category-photo img{width:100%;height:100%;object-fit:contain;padding:18px}.category-photo>i{font-size:40px;color:#aaa6c6}.category-status{position:absolute;top:12px;left:12px;border-radius:7px;background:#ecf8f2;color:#27816b;padding:6px 9px;font-size:11px;font-weight:650}.category-body{display:flex;flex-direction:column;flex:1;padding:21px;gap:11px;width:100%}.category-name{font-size:17px;line-height:1.45;font-weight:750;overflow-wrap:anywhere}.category-details{font-size:12px;color:#758299;margin-bottom:9px}.category-action{display:flex;justify-content:space-between;align-items:center;gap:12px;padding-top:14px;border-top:1px solid #edf0f5;margin-top:auto;font-size:13px;font-weight:650;color:#6150d4}.back-button{display:inline-flex;align-items:center;gap:8px;background:none;border:0;padding:5px 0;color:#6150d4;font-size:13px;font-weight:650}.catalog-note{font-size:12px;line-height:1.8;color:#758299;margin:25px 0 0}.catalog-note i{margin-right:5px}.catalog-empty{text-align:center;padding:40px;color:#758299}.other-categories{margin-top:20px}.other-categories label{display:block;font-size:12px;color:#52647c;margin-bottom:8px}.other-categories form>div{display:flex;gap:10px;max-width:680px}.other-categories .form-select{flex:1;min-width:0;font-size:14px}.other-categories .btn-primary{background:#574ce8;border-color:#574ce8;font-size:13px;border-radius:9px}button:focus-visible{outline:3px solid #a5b4fc;outline-offset:3px}button:disabled{opacity:.55;cursor:not-allowed}@media(max-width:1100px){.category-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.catalog-heading{align-items:flex-start;gap:10px}.catalog-heading h1{font-size:23px}.category-grid{grid-template-columns:1fr;gap:16px}.category-photo{aspect-ratio:2}.category-body{padding:18px}.other-categories form>div{flex-wrap:wrap}.other-categories .form-select{flex-basis:100%}.catalog-refresh{padding:9px 11px}}
@media(prefers-reduced-motion:reduce){.category-card{transition:none}}
</style>
