export function socialPracticeRecordOutcome(record, reviews, statusText) {
  if (record.action === 'auto_filing') return '旧流程自动备案';
  if (record.from_status === 'wait' && record.to_status === 'wait') {
    if (reviews.some(review => review.status === 'accept')) return '本节点已通过，流程继续等待审核';
    if (reviews.some(review => review.status === 'modify')) return '已记录退回修改意见，历史流程状态仍为待审核';
    return '已记录流程操作，仍待审核';
  }
  return `${statusText(record.from_status)} → ${statusText(record.to_status)}`;
}

export function workflowEventOutcome(instance, event) {
  const result = event.result_json || {};
  const status = result.status || event.to_status;
  if (event.action === 'modify') return '已退回修改，可修改后重新提交';
  if (event.action !== 'accept') return '';
  if (status === 'accept') return '本节点已通过，审批流程已全部通过';
  if (status !== 'wait') return '';
  const next = Number(result.active_position || 0);
  if (next && next === Number(event.position)) return '该审批人已通过，会签仍待其他审批人处理';
  const name = instance.nodes_json?.[next - 1]?.name;
  return name ? `本节点已通过，下一步：${name}` : '本节点已通过，流程继续等待审核';
}
