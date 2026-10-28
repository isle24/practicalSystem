import entries from '../../../shared/operation-guides.json';

const guides = new Map(entries.map(entry => [entry.key, entry]));

export function findOperationGuide(module, panel = '', parentPanel = '') {
  return (panel && guides.get(`${module}.${panel}`))
    || (parentPanel && guides.get(`${module}.${parentPanel}`))
    || guides.get(module)
    || null;
}

function escapeText(value) {
  return String(value ?? '').replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[character]);
}

export function renderOperationGuide(guide) {
  if (!guide) return '';
  const list = (title, values) => values?.length
    ? `<section><h3>${escapeText(title)}</h3><ul>${values.map(value => `<li>${escapeText(value)}</li>`).join('')}</ul></section>`
    : '';
  return [
    `<section><h3>用途</h3><p>${escapeText(guide.purpose)}</p></section>`,
    list('开始前', guide.prerequisites),
    `<section><h3>当前怎么操作</h3><ol>${guide.steps.map(step => `<li><strong>${escapeText(step.action)}</strong><p>完成标志：${escapeText(step.expected)}</p><p>下一步：${escapeText(step.next)}</p></li>`).join('')}</ol></section>`,
    list('完成后做什么', guide.next),
    list('遇到问题', guide.issues),
  ].join('');
}
