<template>
  <div class="ai-base-page">
    <transition name="toast">
      <div v-if="toast.show" class="toast-notification" :class="toast.type">
        <i class="bi" :class="toast.type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'"></i>
        <div class="toast-copy">
          <strong>{{ toast.type === 'success' ? 'Готово' : 'Помилка' }}</strong>
          <span>{{ toast.message }}</span>
        </div>
      </div>
    </transition>

    <section class="hero-card">
      <div>
        <div class="eyebrow">AI база керування</div>
        <h1>База знань та шаблони AI</h1>
        <p class="subtitle">
          Тут ви керуєте текстами, шаблонами етапів і мапінгом "модель клієнта -> товар у CRM".
        </p>
        <div class="hero-actions">
          <a href="/settings/ai" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>
            Назад до параметрів
          </a>
          <button type="button" class="btn btn-sm btn-outline-dark" :disabled="loading" @click="loadData">
            Оновити
          </button>
        </div>
      </div>

      <div class="hero-stats">
        <div class="stat-pill">
          <span>Елементів бази знань</span>
          <strong>{{ knowledgeItems.length }}</strong>
        </div>
        <div class="stat-pill">
          <span>Мапінгів модель -> товар</span>
          <strong>{{ modelMaps.length }}</strong>
        </div>
      </div>
    </section>

    <div class="card-block">
      <div class="section-head">
        <h2>Шаблони етапів діалогу</h2>
        <div class="head-right">
          <label class="inline-select">
            <span>Агент</span>
            <select v-model="selectedAgentCode" :disabled="loading" @change="loadData">
              <option v-for="agent in agents" :key="agent.code" :value="agent.code">
                {{ agent.name }} ({{ agent.code }})
              </option>
            </select>
          </label>
        </div>
      </div>
      <div class="hint-box">
        Редагуйте промпт і policy_json по кожному етапу. При збереженні створюється нова версія шаблону.
      </div>

      <div class="prompt-grid">
        <article v-for="stage in stageDefs" :key="stage.code" class="prompt-card">
          <header class="prompt-head">
            <div>
              <h3>{{ stage.title }}</h3>
              <p>{{ stage.description }}</p>
            </div>
            <span class="version-badge">v{{ promptVersion(stage.code) }}</span>
          </header>

          <label class="field">
            <span>System prompt</span>
            <textarea
              v-model="promptDrafts[stage.code].system_prompt"
              rows="6"
              placeholder="Основна інструкція для етапу"
            ></textarea>
          </label>

          <label class="field">
            <span>policy_json</span>
            <textarea
              v-model="promptDrafts[stage.code].policy_json_text"
              rows="6"
              class="mono"
              placeholder="{ }"
            ></textarea>
          </label>

          <div class="card-actions">
            <button
              type="button"
              class="btn btn-sm btn-dark"
              :disabled="loading || promptSaving[stage.code]"
              @click="savePrompt(stage.code)"
            >
              <span v-if="promptSaving[stage.code]" class="spinner-border spinner-border-sm me-2"></span>
              Зберегти етап
            </button>
          </div>
        </article>
      </div>
    </div>

    <div class="card-block">
      <div class="section-head">
        <h2>База знань (ручні тексти)</h2>
        <button type="button" class="btn btn-sm btn-outline-success" @click="addKnowledgeItem">
          <i class="bi bi-plus-lg me-1"></i>
          Додати текст
        </button>
      </div>
      <div class="hint-box">
        Ці тексти додаються в системний контекст AI. Використовуйте їх для правил тону, обмежень, скриптів та FAQ.
      </div>

      <div class="stack-list">
        <div v-for="item in knowledgeItems" :key="item.local_id" class="stack-card">
          <div class="stack-grid">
            <label class="field">
              <span>Ключ</span>
              <input v-model="item.key" type="text" placeholder="delivery_rules_v1">
            </label>
            <label class="field">
              <span>Тип</span>
              <select v-model="item.item_type">
                <option value="instruction">instruction</option>
                <option value="template">template</option>
                <option value="faq">faq</option>
              </select>
            </label>
            <label class="field">
              <span>Порядок</span>
              <input v-model.number="item.sort_order" type="number" min="1" max="9999">
            </label>
            <label class="switch-label">
              <span>Активний</span>
              <input v-model="item.is_active" type="checkbox">
            </label>
          </div>

          <label class="field">
            <span>Назва</span>
            <input v-model="item.title" type="text" placeholder="Правила оформлення">
          </label>

          <label class="field">
            <span>Текст</span>
            <textarea v-model="item.content" rows="5" placeholder="Що саме агент має враховувати..."></textarea>
          </label>

          <div class="card-actions">
            <button type="button" class="btn btn-sm btn-dark" :disabled="item.saving" @click="saveKnowledgeItem(item)">
              <span v-if="item.saving" class="spinner-border spinner-border-sm me-2"></span>
              Зберегти
            </button>
            <button
              type="button"
              class="btn btn-sm btn-outline-danger"
              :disabled="item.saving"
              @click="deleteKnowledgeItem(item)"
            >
              Видалити
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="card-block">
      <div class="section-head">
        <h2>Мапінг "модель -> товар"</h2>
        <button type="button" class="btn btn-sm btn-outline-success" @click="addModelMap">
          <i class="bi bi-plus-lg me-1"></i>
          Додати мапінг
        </button>
      </div>
      <div class="hint-box">
        Якщо клієнт називає модель словом/фразою, AI використає цей мапінг для вибору товару і варіанта.
      </div>

      <div class="stack-list">
        <div v-for="map in modelMaps" :key="map.local_id" class="stack-card">
          <div class="stack-grid">
            <label class="field wide">
              <span>Фраза моделі</span>
              <input v-model="map.model_phrase" type="text" placeholder="тапочки класик чорні">
            </label>
            <label class="field">
              <span>Товар</span>
              <select v-model="map.product_id" @change="onMapProductChange(map)">
                <option :value="null">—</option>
                <option v-for="product in products" :key="product.id" :value="product.id">
                  #{{ product.id }} — {{ product.title }}
                </option>
              </select>
            </label>
            <label class="field">
              <span>Варіант</span>
              <select v-model="map.variant_id" :disabled="!map.product_id">
                <option :value="null">—</option>
                <option v-for="variant in variantsByProduct(map.product_id)" :key="variant.id" :value="variant.id">
                  #{{ variant.id }} — {{ variant.size || 'без розміру' }} ({{ variant.stock_qty }} шт.)
                </option>
              </select>
            </label>
            <label class="field">
              <span>Колір</span>
              <select v-model="map.color_id">
                <option :value="null">—</option>
                <option v-for="color in colors" :key="color.id" :value="color.id">
                  {{ color.name }}
                </option>
              </select>
            </label>
            <label class="field">
              <span>Підказка розміру</span>
              <input v-model="map.size_hint" type="text" placeholder="37">
            </label>
            <label class="field">
              <span>Пріоритет</span>
              <input v-model.number="map.priority" type="number" min="1" max="9999">
            </label>
            <label class="switch-label">
              <span>Активний</span>
              <input v-model="map.is_active" type="checkbox">
            </label>
          </div>

          <label class="field">
            <span>Нотатки</span>
            <textarea v-model="map.notes" rows="3" placeholder="Службова примітка для команди"></textarea>
          </label>

          <div class="card-actions">
            <button type="button" class="btn btn-sm btn-dark" :disabled="map.saving" @click="saveModelMap(map)">
              <span v-if="map.saving" class="spinner-border spinner-border-sm me-2"></span>
              Зберегти
            </button>
            <button
              type="button"
              class="btn btn-sm btn-outline-danger"
              :disabled="map.saving"
              @click="deleteModelMap(map)"
            >
              Видалити
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import http from '@/crm/api/http';

const stageDefs = [
  { code: 'interest', title: '1. Зацікавлення', description: 'Питання про ціну, фото, наявність, кольори.' },
  { code: 'selection', title: '2. Підбір', description: 'Уточнення розміру/моделі/кольору без тиску.' },
  { code: 'checkout_ready', title: '3. Готовність до оформлення', description: 'Збір полів доставки після явного наміру купити.' },
  { code: 'checkout', title: '4. Оформлення', description: 'Фінальне підтвердження замовлення та передача в обробку.' },
];

const loading = ref(false);
const agents = ref([]);
const products = ref([]);
const colors = ref([]);
const knowledgeItems = ref([]);
const modelMaps = ref([]);
const selectedAgentCode = ref('');
const promptSaving = reactive({});
const prompts = reactive({});
const promptDrafts = reactive({});
const variantOptionsByProduct = reactive({});

const toast = reactive({
  show: false,
  message: '',
  type: 'success',
});

let toastTimer = null;

function showToast(message, type = 'success') {
  const text = String(message || '').trim();
  if (!text) {
    return;
  }

  toast.message = text;
  toast.type = type === 'error' ? 'error' : 'success';
  toast.show = true;

  if (toastTimer) {
    clearTimeout(toastTimer);
  }

  toastTimer = setTimeout(() => {
    toast.show = false;
  }, 3200);
}

function promptVersion(stageCode) {
  const version = Number(prompts[stageCode]?.version || 0);
  return version > 0 ? version : 1;
}

function ensurePromptDraft(stageCode, prompt = {}) {
  if (!promptDrafts[stageCode]) {
    promptDrafts[stageCode] = {
      system_prompt: '',
      policy_json_text: '{}',
    };
  }

  promptDrafts[stageCode].system_prompt = String(prompt.system_prompt || '');
  promptDrafts[stageCode].policy_json_text = JSON.stringify(prompt.policy_json || {}, null, 2);
}

function normalizeKnowledgeRow(item = {}) {
  return {
    id: item.id ?? null,
    local_id: item.id ? `k_${item.id}` : `k_new_${Date.now()}_${Math.random()}`,
    key: String(item.key || ''),
    title: String(item.title || ''),
    item_type: String(item.item_type || 'instruction'),
    content: String(item.content || ''),
    sort_order: Number(item.sort_order || 100),
    is_active: item.is_active !== false,
    saving: false,
  };
}

function normalizeModelMapRow(map = {}) {
  return {
    id: map.id ?? null,
    local_id: map.id ? `m_${map.id}` : `m_new_${Date.now()}_${Math.random()}`,
    model_phrase: String(map.model_phrase || ''),
    product_id: map.product_id ? Number(map.product_id) : null,
    variant_id: map.variant_id ? Number(map.variant_id) : null,
    color_id: map.color_id ? Number(map.color_id) : null,
    size_hint: String(map.size_hint || ''),
    priority: Number(map.priority || 100),
    notes: String(map.notes || ''),
    is_active: map.is_active !== false,
    saving: false,
  };
}

async function ensureVariantsLoaded(productId) {
  const normalizedId = Number(productId || 0);
  if (!normalizedId) {
    return;
  }

  if (Array.isArray(variantOptionsByProduct[normalizedId])) {
    return;
  }

  try {
    const { data } = await http.get(`/settings/ai/base/products/${normalizedId}/variants`);
    variantOptionsByProduct[normalizedId] = Array.isArray(data?.data) ? data.data : [];
  } catch (error) {
    variantOptionsByProduct[normalizedId] = [];
    showToast(error.response?.data?.message || 'Не вдалося завантажити варіанти товару.', 'error');
  }
}

function variantsByProduct(productId) {
  const normalizedId = Number(productId || 0);
  if (!normalizedId) {
    return [];
  }

  return Array.isArray(variantOptionsByProduct[normalizedId])
    ? variantOptionsByProduct[normalizedId]
    : [];
}

async function loadData() {
  loading.value = true;
  try {
    const params = {};
    if (selectedAgentCode.value) {
      params.agent_code = selectedAgentCode.value;
    }

    const { data } = await http.get('/settings/ai/base', { params });

    agents.value = Array.isArray(data?.agents) ? data.agents : [];
    products.value = Array.isArray(data?.products) ? data.products : [];
    colors.value = Array.isArray(data?.colors) ? data.colors : [];
    selectedAgentCode.value = String(data?.selected_agent_code || agents.value[0]?.code || '');

    const serverPrompts = data?.prompts && typeof data.prompts === 'object' ? data.prompts : {};
    for (const stage of stageDefs) {
      prompts[stage.code] = serverPrompts[stage.code] || {
        stage: stage.code,
        version: 1,
        system_prompt: '',
        policy_json: {},
      };
      ensurePromptDraft(stage.code, prompts[stage.code]);
    }

    knowledgeItems.value = Array.isArray(data?.knowledge_items)
      ? data.knowledge_items.map((item) => normalizeKnowledgeRow(item))
      : [];

    modelMaps.value = Array.isArray(data?.model_maps)
      ? data.model_maps.map((map) => normalizeModelMapRow(map))
      : [];

    const productIds = [...new Set(modelMaps.value.map((item) => Number(item.product_id || 0)).filter((id) => id > 0))];
    for (const productId of productIds) {
      // eslint-disable-next-line no-await-in-loop
      await ensureVariantsLoaded(productId);
    }
  } catch (error) {
    showToast(error.response?.data?.message || 'Не вдалося завантажити AI базу.', 'error');
  } finally {
    loading.value = false;
  }
}

async function savePrompt(stageCode) {
  if (!promptDrafts[stageCode]) {
    return;
  }

  let parsedPolicy = {};
  const rawPolicy = String(promptDrafts[stageCode].policy_json_text || '').trim();
  if (rawPolicy !== '') {
    try {
      parsedPolicy = JSON.parse(rawPolicy);
    } catch {
      showToast(`policy_json для етапу "${stageCode}" має бути валідним JSON.`, 'error');
      return;
    }
  }

  promptSaving[stageCode] = true;
  try {
    const payload = {
      agent_code: selectedAgentCode.value,
      system_prompt: promptDrafts[stageCode].system_prompt,
      policy_json: parsedPolicy,
    };

    const { data } = await http.post(`/settings/ai/base/prompts/${stageCode}`, payload);
    prompts[stageCode] = data?.prompt || prompts[stageCode];
    ensurePromptDraft(stageCode, prompts[stageCode]);
    showToast(data?.message || `Етап ${stageCode} збережено.`);
  } catch (error) {
    showToast(error.response?.data?.message || `Не вдалося зберегти етап ${stageCode}.`, 'error');
  } finally {
    promptSaving[stageCode] = false;
  }
}

function addKnowledgeItem() {
  knowledgeItems.value.unshift(normalizeKnowledgeRow());
}

async function saveKnowledgeItem(item) {
  item.saving = true;
  try {
    const payload = {
      key: item.key,
      title: item.title,
      item_type: item.item_type,
      content: item.content,
      sort_order: Number(item.sort_order || 100),
      is_active: !!item.is_active,
    };

    let response;
    if (item.id) {
      response = await http.patch(`/settings/ai/base/knowledge-items/${item.id}`, payload);
    } else {
      response = await http.post('/settings/ai/base/knowledge-items', payload);
    }

    const savedItem = response?.data?.item;
    if (savedItem) {
      Object.assign(item, normalizeKnowledgeRow(savedItem));
    }

    showToast(response?.data?.message || 'Елемент бази знань збережено.');
  } catch (error) {
    showToast(error.response?.data?.message || 'Не вдалося зберегти елемент бази знань.', 'error');
  } finally {
    item.saving = false;
  }
}

async function deleteKnowledgeItem(item) {
  if (!item.id) {
    knowledgeItems.value = knowledgeItems.value.filter((row) => row.local_id !== item.local_id);
    return;
  }

  item.saving = true;
  try {
    const { data } = await http.delete(`/settings/ai/base/knowledge-items/${item.id}`);
    knowledgeItems.value = knowledgeItems.value.filter((row) => row.id !== item.id);
    showToast(data?.message || 'Елемент бази знань видалено.');
  } catch (error) {
    showToast(error.response?.data?.message || 'Не вдалося видалити елемент бази знань.', 'error');
  } finally {
    item.saving = false;
  }
}

function addModelMap() {
  modelMaps.value.unshift(normalizeModelMapRow());
}

async function onMapProductChange(map) {
  map.variant_id = null;
  await ensureVariantsLoaded(map.product_id);
}

async function saveModelMap(map) {
  map.saving = true;
  try {
    if (!map.product_id) {
      showToast('Оберіть товар для мапінгу.', 'error');
      return;
    }

    const payload = {
      model_phrase: map.model_phrase,
      product_id: map.product_id,
      variant_id: map.variant_id || null,
      color_id: map.color_id || null,
      size_hint: map.size_hint || null,
      priority: Number(map.priority || 100),
      notes: map.notes || null,
      is_active: !!map.is_active,
    };

    let response;
    if (map.id) {
      response = await http.patch(`/settings/ai/base/model-maps/${map.id}`, payload);
    } else {
      response = await http.post('/settings/ai/base/model-maps', payload);
    }

    const savedMap = response?.data?.map;
    if (savedMap) {
      Object.assign(map, normalizeModelMapRow(savedMap));
      await ensureVariantsLoaded(map.product_id);
    }

    showToast(response?.data?.message || 'Мапінг збережено.');
  } catch (error) {
    showToast(error.response?.data?.message || 'Не вдалося зберегти мапінг.', 'error');
  } finally {
    map.saving = false;
  }
}

async function deleteModelMap(map) {
  if (!map.id) {
    modelMaps.value = modelMaps.value.filter((row) => row.local_id !== map.local_id);
    return;
  }

  map.saving = true;
  try {
    const { data } = await http.delete(`/settings/ai/base/model-maps/${map.id}`);
    modelMaps.value = modelMaps.value.filter((row) => row.id !== map.id);
    showToast(data?.message || 'Мапінг видалено.');
  } catch (error) {
    showToast(error.response?.data?.message || 'Не вдалося видалити мапінг.', 'error');
  } finally {
    map.saving = false;
  }
}

onMounted(loadData);

onBeforeUnmount(() => {
  if (toastTimer) {
    clearTimeout(toastTimer);
  }
});
</script>

<style scoped>
.ai-base-page {
  color: #1f2937;
  display: flex;
  flex-direction: column;
  gap: 16px;
  position: relative;
  width: 100%;
  max-width: 1540px;
  margin: 0 auto;
  padding-inline: clamp(10px, 1.4vw, 24px);
  box-sizing: border-box;
}
.hero-card {
  background: linear-gradient(135deg, #eff6ff 0%, #f8fafc 100%);
  border: 1px solid #bfdbfe;
  border-radius: 18px;
  padding: 20px 24px;
  display: flex;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}
.eyebrow { font-size: 12px; font-weight: 700; text-transform: uppercase; color: #1d4ed8; letter-spacing: .04em; }
h1 { margin: 6px 0 8px; font-size: 28px; font-weight: 700; color: #0f172a; }
.subtitle { margin: 0; color: #334155; max-width: 740px; }
.hero-actions { margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap; }
.hero-stats { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.stat-pill { background: #ffffff; border: 1px solid #dbeafe; border-radius: 12px; padding: 10px 12px; min-width: 200px; }
.stat-pill span { display: block; color: #64748b; font-size: 12px; }
.stat-pill strong { color: #0f172a; font-size: 16px; }

.card-block { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px; }
.section-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; }
.section-head h2 { margin: 0; font-size: 18px; font-weight: 700; color: #0f172a; }
.head-right { display: flex; align-items: center; gap: 8px; }
.inline-select { display: flex; align-items: center; gap: 8px; margin: 0; }
.inline-select span { font-size: 12px; color: #64748b; font-weight: 600; }
.inline-select select { height: 34px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 10px; }

.hint-box {
  border: 1px solid #dbeafe;
  background: #f8fbff;
  color: #334155;
  border-radius: 12px;
  padding: 10px 12px;
  margin-bottom: 12px;
  font-size: 13px;
}

.prompt-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.prompt-card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px; background: #fff; display: flex; flex-direction: column; gap: 10px; }
.prompt-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.prompt-head h3 { margin: 0; font-size: 15px; font-weight: 700; color: #0f172a; }
.prompt-head p { margin: 3px 0 0; color: #64748b; font-size: 12px; }
.version-badge { background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; font-size: 11px; border-radius: 999px; padding: 3px 8px; font-weight: 700; }

.stack-list { display: flex; flex-direction: column; gap: 10px; }
.stack-card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px; background: #fff; display: flex; flex-direction: column; gap: 10px; }
.stack-grid { display: grid; grid-template-columns: 1.4fr 1fr 0.8fr 0.8fr; gap: 10px; align-items: end; }
.stack-grid .field.wide { grid-column: span 2; }
.switch-label { display: flex; align-items: center; justify-content: space-between; border: 1px solid #e2e8f0; border-radius: 10px; padding: 9px 10px; background: #f8fafc; min-height: 40px; }
.switch-label span { font-size: 12px; color: #334155; font-weight: 600; }

.field { display: flex; flex-direction: column; gap: 6px; }
.field span { font-weight: 600; font-size: 12px; color: #334155; }
.field input,
.field select,
.field textarea {
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  padding: 9px 11px;
  background: #fff;
  color: #0f172a;
}
.field textarea { resize: vertical; min-height: 96px; }
.field .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 12px; }

.card-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.toast-notification {
  position: fixed;
  top: 16px;
  right: 16px;
  z-index: 1100;
  min-width: 240px;
  max-width: min(380px, calc(100vw - 24px));
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 10px;
  border: 1px solid #d1fae5;
  background: rgba(240, 253, 244, 0.98);
  box-shadow: 0 14px 30px -20px rgba(15, 23, 42, 0.45);
  backdrop-filter: blur(6px);
}
.toast-notification.success { border-color: #bbf7d0; background: rgba(240, 253, 244, 0.98); }
.toast-notification.error { border-color: #fecaca; background: rgba(254, 242, 242, 0.98); }
.toast-notification > i { margin-top: 1px; font-size: 18px; flex-shrink: 0; }
.toast-notification.success > i { color: #16a34a; }
.toast-notification.error > i { color: #dc2626; }
.toast-copy { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.toast-copy strong { font-size: 12px; line-height: 1.2; font-weight: 700; color: #0f172a; }
.toast-copy span { font-size: 13px; line-height: 1.35; color: #334155; }
.toast-enter-active, .toast-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateY(-8px); }

@media (max-width: 1100px) {
  .prompt-grid { grid-template-columns: 1fr; }
}

@media (max-width: 900px) {
  .stack-grid { grid-template-columns: 1fr 1fr; }
  .stack-grid .field.wide { grid-column: span 2; }
}

@media (max-width: 640px) {
  .stack-grid { grid-template-columns: 1fr; }
  .stack-grid .field.wide { grid-column: span 1; }
}
</style>
