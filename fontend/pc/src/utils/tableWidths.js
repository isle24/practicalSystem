export const TABLE_WIDTH_SCOPE = Symbol('table-width-scope');

export function columnWidthIdentity(column = {}) {
  const columnKey = column.columnKey ?? column['column-key'];
  if (columnKey !== null && columnKey !== undefined && columnKey !== '') return `key:${columnKey}`;
  if (column.property || column.prop) return `prop:${column.property || column.prop}`;
  if (column.rawColumnKey !== null && column.rawColumnKey !== undefined) return `vnode:${String(column.rawColumnKey)}`;
  return `column:${column.type || 'default'}:${column.label || ''}`;
}

export function validColumnWidth(value) {
  return typeof value === 'number' && Number.isFinite(value) && value >= 30 && value <= 5000;
}

export function readTableWidths(key) {
  if (!key) return {};
  try {
    const value = JSON.parse(localStorage.getItem(key) || '{}');
    if (!value || typeof value !== 'object' || Array.isArray(value)) return {};
    return Object.fromEntries(Object.entries(value).filter(([name, width]) => name.length <= 240 && validColumnWidth(width)).slice(0, 256));
  } catch { return {}; }
}

export function writeTableWidths(key, value) {
  if (!key) return;
  try { localStorage.setItem(key, JSON.stringify(value)); } catch {}
}
