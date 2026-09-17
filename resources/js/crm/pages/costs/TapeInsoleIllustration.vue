<template>
  <figure class="tape-illustration" data-testid="tape-insole-illustration">
    <figcaption><div><h3>Окантовка устілки</h3><p>Стрічка проходить по всьому краю</p></div><span>1 капець</span></figcaption>
    <img class="insole-image" data-testid="tape-insole-image" :src="insoleImage" width="752" height="752" decoding="async" alt="Пласка світла устілка з широким округлим носком, вужчою п’яткою та чорною оксамитовою окантовкою по всьому краю" />
    <div class="tape-legend"><span><i class="insole-swatch"></i>Устілка</span><span><i class="tape-swatch"></i>Окантовка по краю</span></div>
    <p class="tape-illustration-note">Умовна схема. Довжину стрічки та запас беремо з полів розрахунку, не вимірюємо за зображенням.</p>
  </figure>
</template>

<script setup>
// Затверджена ілюстрація не впливає на норму витрати стрічки.
import insoleImage from '../../../../images/costs/halluci-insole-black-edging.webp';
</script>

<style scoped>
.tape-illustration{margin:0;padding:20px 22px 17px;background:#fff;border:1px solid #e3e9f2;border-radius:16px;min-width:0}.tape-illustration figcaption{display:flex;justify-content:space-between;align-items:center;gap:12px}.tape-illustration h3{font-size:14px;font-weight:750;color:#28354b;margin:0 0 5px}.tape-illustration figcaption p{font-size:11px;color:#8690a1;margin:0;line-height:1.5}.tape-illustration figcaption>span{font-size:10px;color:#8d7da4;border:1px solid #ede8f4;background:#faf8fd;border-radius:6px;padding:5px 8px;white-space:nowrap}.insole-image{display:block;width:100%;max-width:340px;height:auto;object-fit:contain;margin:12px auto}.tape-legend{display:flex;justify-content:center;flex-wrap:wrap;gap:18px;font-size:11px;color:#8590a2}.tape-legend span{display:flex;align-items:center;gap:6px}.tape-legend i{display:inline-block;width:11px;height:11px;border-radius:4px}.insole-swatch{background:#fbf6e6;border:1px solid #e6dccc}.tape-swatch{background:#090909;border:1px solid #252525}.tape-illustration-note{margin:13px 0 0;font-size:10px;color:#95a0af;line-height:1.7;text-align:center}@media(max-width:600px){.tape-illustration{padding:18px}}
</style>
