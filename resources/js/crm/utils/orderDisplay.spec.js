import { describe, expect, it } from 'vitest';
import { getDeliveryStatusStyle, getStatusStyle } from './orderDisplay';

describe('кольори довільних статусів', () => {
  it('приймає власний колір і скорочений HEX з налаштувань', () => {
    const custom = getStatusStyle({ status_color: '#AbC', status_key: 'delivered' });
    expect(custom).toEqual(getStatusStyle({ status_color: '#aabbcc' }));
    expect(custom).not.toEqual(getStatusStyle({ status_key: 'delivered' }));
  });

  it('показує нейтральний бейдж для невідомого коду без валідного кольору', () => {
    const neutral = getStatusStyle({ status_color: '#6b7280' });
    for (const code of ['custom', 'constructor', 'toString', '__proto__']) {
      expect(getStatusStyle({ status_key: code, status_color: 'invalid' })).toEqual(neutral);
      expect(getDeliveryStatusStyle({ delivery_status_code: code })).toEqual(neutral);
    }
  });
});
