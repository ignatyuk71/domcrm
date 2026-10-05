export function money(value, currency = 'UAH') {
    if (value === null || value === undefined) return '—';
    const minor = BigInt(value);
    const absolute = minor < 0n ? -minor : minor;
    const whole = new Intl.NumberFormat('uk-UA').format(absolute / 100n);
    return `${minor < 0n ? '−' : ''}${whole},${String(absolute % 100n).padStart(2, '0')} ${currency === 'UAH' ? 'грн' : currency}`;
}

export function businessDate(date = new Date(), timezone = 'Europe/Kyiv') {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', {
        timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit',
    }).formatToParts(date).map(part => [part.type, part.value]));
    return `${parts.year}-${parts.month}-${parts.day}`;
}

export function period(key, timezone = 'Europe/Kyiv') {
    const today = businessDate(new Date(), timezone);
    // Працюємо з календарними датами, щоб перехід на літній час не зміщував день.
    const day = new Date(`${today}T12:00:00Z`);
    const offset = key === 'yesterday' ? 1 : key === 'week' ? 6 : 0;
    day.setUTCDate(day.getUTCDate() - offset);
    const from = key === 'month' ? `${today.slice(0, 7)}-01` : day.toISOString().slice(0, 10);
    return { from, to: key === 'yesterday' ? from : today };
}
