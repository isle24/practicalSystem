export function desktopTopInset() {
  return Math.max(0, Number.parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--desktop-top-inset')) || 0);
}

export function activeFilterCount(values = {}) {
  const metadata = new Set(['page', 'page_size', 'per_page', 'sort', 'order', 'order_by']);
  return Object.entries(values).filter(([key, value]) => !metadata.has(key) && (Array.isArray(value) ? value.length > 0 : value !== '' && value !== null && value !== undefined && value !== 'all')).length;
}
