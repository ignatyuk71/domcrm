<template>
  <section class="cut-card" :class="shape" :data-testid="`laminate-${part}-layout`">
    <header><div><h3>{{ title }}</h3><p>{{ dimensions }} см · одна деталь</p></div><i :class="shape === 'rectangle' ? 'bi bi-square' : 'bi bi-scissors'" aria-hidden="true"></i></header>
    <div class="part-price"><strong :data-testid="`laminate-${part}-cost`" role="status">{{ money(cost) }} <small>грн</small></strong><span>{{ shape === 'rectangle' ? 'за 2 устілки' : 'за 2 внутрішні деталі верху' }}</span></div>
    <div v-if="layout?.pairs" class="cut-content">
      <figure><figcaption>100 × {{ number(width) }} см</figcaption><svg :viewBox="`-1 -1 102 ${Number(width) + 2}`" role="img" :aria-label="`${title}: ${layout.pieces_per_row} у ряду, ${layout.rows_per_cut} рядів. Сіре — обрізки.${truncated ? ' Показано фрагмент.' : ''}`">
        <rect x="0" y="0" width="100" :height="width" fill="#edf0f5" stroke="#cbd3df" stroke-width="1" vector-effect="non-scaling-stroke" />
        <polygon v-for="(polygon, index) in polygons" :key="index" :points="polygon.points" :fill="shape === 'rectangle' ? '#bce5dc' : polygon.flipped ? '#bcb3f6' : '#ded9fb'" :stroke="shape === 'rectangle' ? '#329a86' : '#7361ed'" stroke-width="0.7" vector-effect="non-scaling-stroke" />
      </svg><small>{{ truncated ? 'Фрагмент розкладки' : 'Сіре — обрізки' }}</small></figure>
      <dl>
        <div><dt>У ряду</dt><dd>{{ number(layout.pieces_per_row) }}</dd></div>
        <div><dt>Рядів</dt><dd>{{ number(layout.rows_per_cut) }}</dd></div>
        <div><dt>Цілих деталей</dt><dd :data-testid="`laminate-${part}-pieces`">{{ number(layout.total_pieces) }}</dd></div>
        <div><dt>Повних пар</dt><dd>{{ number(layout.pairs) }}</dd></div>
        <div><dt>Обрізки</dt><dd>{{ number(layout.offcut_percent) }}%</dd></div>
      </dl>
    </div>
    <p v-if="layout?.pairs" class="cut-formula">{{ money(metreCost) }} грн ÷ {{ number(layout.pairs) }} пар = <b>{{ money(cost) }} грн</b></p>
    <p v-else class="cut-hint">{{ width ? 'З цього відрізу не виходить двох цілих деталей. Перевірте розміри та напрям рядів.' : 'Укажіть ширину вже склеєного полотна для розрахунку.' }}</p>
    <p v-if="layout?.unpaired_pieces" class="cut-hint">1 деталь залишається без пари. Ціну відрізу ділимо на повні пари.</p>
    <p class="cut-hint">{{ shape === 'rectangle' ? 'Прямокутники йдуть рядами: довжина деталі вздовж 100 см. Обрізки після шиття вже всередині заготовки.' : 'Сусідні трапеції перевертаємо, щоб їхні похилі боки прилягали.' }}</p>
  </section>
</template>

<script setup>
import { computed } from 'vue';
const props = defineProps({ part: String, title: String, shape: String, dimensions: String, width: Number, top: Number, bottom: Number, height: Number, layout: Object, cost: Number, metreCost: Number });
const money = value => value == null ? '—' : Number(value).toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const number = value => Number(value).toLocaleString('uk-UA', { maximumFractionDigits: 4 });
const truncated = computed(() => props.layout && (props.layout.pieces_per_row > 24 || props.layout.rows_per_cut > 24));
const polygons = computed(() => {
  if (!props.layout?.pairs) return [];
  const wide = Math.max(props.top, props.bottom), narrow = Math.min(props.top, props.bottom), inset = (wide - narrow) / 2, step = (wide + narrow) / 2;
  const result = [];
  // Складність зображення обмежена; математичний розрахунок завжди охоплює всі ряди.
  for (let row = 0; row < Math.min(props.layout.rows_per_cut, 24); row++) {
    for (let column = 0; column < Math.min(props.layout.pieces_per_row, 24); column++) {
      const x = column * step, y = row * props.height, flipped = props.shape === 'trapezoid' && column % 2 === 1;
      result.push({ flipped, points: flipped
        ? `${x + inset},${y} ${x + inset + narrow},${y} ${x + wide},${y + props.height} ${x},${y + props.height}`
        : `${x},${y} ${x + wide},${y} ${x + inset + narrow},${y + props.height} ${x + inset},${y + props.height}` });
    }
  }
  return result;
});
</script>

<style scoped>
.cut-card{background:#fff;border:1px solid #e3e9f2;border-radius:16px;padding:22px;min-width:0}.cut-card header{display:flex;justify-content:space-between;gap:14px}.cut-card h3{font-size:16px;font-weight:750;margin:0 0 6px;color:#26314a}.cut-card header p{font-size:12px;color:#6b7c93;margin:0}.cut-card header i{display:grid;place-items:center;width:36px;height:36px;flex-shrink:0;border-radius:10px;background:#e6f5f0;color:#329a86}.trapezoid header i{background:#f0edff;color:#7361ed}.part-price{display:flex;align-items:baseline;flex-wrap:wrap;column-gap:12px;margin:18px 0}.part-price strong{font-size:34px;line-height:1.2;letter-spacing:-.03em;color:#247865}.trapezoid .part-price strong{color:#6252d9}.part-price small{font-size:15px;font-weight:650;letter-spacing:0}.part-price span{font-size:12px;color:#6b7c93}.cut-content{display:grid;grid-template-columns:minmax(90px,1fr) minmax(130px,1.1fr);gap:18px;align-items:center}figure{margin:0;text-align:center}figcaption,figure small{font-size:11px;color:#6b7c93}svg{display:block;width:100%;height:200px;margin:8px 0}dl{margin:0}dl>div{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid #edf0f5;font-size:12px}dt{font-weight:400;color:#6b7c93}dd{margin:0;font-weight:700;color:#26314a}.cut-formula{background:#f7f8fb;border-radius:10px;padding:12px;margin:16px 0 0;font-size:13px;color:#475569;line-height:1.8}.cut-hint{font-size:11px;color:#6b7c93;line-height:1.7;margin:10px 0 0}@media(max-width:600px){.cut-card{padding:18px}.cut-content{gap:12px}svg{height:180px}.part-price strong{font-size:30px}}
</style>
