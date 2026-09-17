import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import TapeInsoleIllustration from './TapeInsoleIllustration.vue';
import insoleImage from '../../../../images/costs/halluci-insole-black-edging.webp';

describe('Схема окантовки устілки', () => {
  it('показує затверджене зображення з чорною окантовкою замість старого SVG', () => {
    const wrapper = mount(TapeInsoleIllustration);
    const image = wrapper.get('[data-testid="tape-insole-image"]');
    expect(image.attributes('src')).toBe(insoleImage);
    expect(image.attributes('alt')).toContain('чорною оксамитовою окантовкою');
    expect(wrapper.find('svg').exists()).toBe(false);
    expect(wrapper.text()).toContain('не вимірюємо за зображенням');
    wrapper.unmount();
  });
  it('резервує пропорції зображення та залишає доступний опис', () => {
    const wrapper = mount(TapeInsoleIllustration);
    const image = wrapper.get('img');
    expect(image.attributes('width')).toBe('752');
    expect(image.attributes('height')).toBe('752');
    expect(image.attributes('decoding')).toBe('async');
    expect(image.attributes('alt')).toContain('вужчою п’яткою');
    expect(wrapper.get('figcaption').text()).toContain('1 капець');
    wrapper.unmount();
  });
});
