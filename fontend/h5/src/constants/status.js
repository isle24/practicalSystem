const statusDefinitions = {
  draft: { label: '草稿', tone: 'neutral' },
  wait: { label: '待审核', tone: 'info' },
  accept: { label: '已通过', tone: 'success' },
  modify: { label: '需修改', tone: 'warning' },
  refuse: { label: '已退回', tone: 'danger' },
  skipped: { label: '跳过', tone: 'neutral' },
  enabled: { label: '启用', tone: 'success' },
  changing: { label: '变更中', tone: 'info' },
  disabled: { label: '停用', tone: 'neutral' },
  changed: { label: '已变更', tone: 'success' },
  completed: { label: '已完成', tone: 'success' },
  pending: { label: '待处理', tone: 'info' },
  active: { label: '有效', tone: 'success' },
  removed: { label: '已移除', tone: 'neutral' },
  signed: { label: '已签署', tone: 'success' },
  published: { label: '已发布', tone: 'success' },
  confirmed: { label: '已确认', tone: 'success' },
  complete: { label: '完整', tone: 'success' },
  incomplete: { label: '待补齐', tone: 'warning' },
  archived: { label: '已归档', tone: 'success' },
  missing: { label: '待补齐', tone: 'warning' },
  not_required: { label: '不适用', tone: 'neutral' },
  pass: { label: '通过', tone: 'success' },
  fail: { label: '不通过', tone: 'danger' },
};

export const STATUS_DEFINITIONS = Object.freeze(statusDefinitions);

export function statusMeta(status, label = '') {
  const value = String(status || '').trim();
  const definition = STATUS_DEFINITIONS[value];
  if (definition) {
    return { value, ...definition, label: label || definition.label };
  }
  return {
    value,
    label: label || value || '-',
    tone: 'neutral',
  };
}

export function statusText(status) {
  return statusMeta(status).label;
}
