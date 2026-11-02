export function socialPracticeProjectPlanEligibility(plan) {
  if (!plan) return { canCreate: false, reason: '计划不存在或不在当前可见范围，请重新选择' };
  if (typeof plan.can_create_project !== 'boolean' && !Object.prototype.hasOwnProperty.call(plan, 'published_at')) return { canCreate: false, reason: '服务器未提供计划发布状态，请更新服务端后重新读取' };
  const canCreate = typeof plan.can_create_project === 'boolean'
    ? plan.can_create_project
    : plan.status === 'accept' && Boolean(plan.published_at);
  if (canCreate) return { canCreate: true, reason: '' };
  const defaultReason = '计划审核通过并发布后才能创建实践项目';
  const statusReason = ({
    draft: '计划为草稿，请先提交审核并发布',
    wait: '计划仍在审核中，请等待全部审核通过并发布',
    modify: '计划需修改，请修改后重新提交审核并发布',
    accept: '计划已通过审核，请先发布',
  }[plan.status]);
  const reason = plan.project_unavailable_reason && plan.project_unavailable_reason !== defaultReason
    ? plan.project_unavailable_reason
    : statusReason || defaultReason;
  return { canCreate: false, reason };
}

export function socialPracticeProjectPlanOptions(plans) {
  return plans.map(plan => {
    const eligibility = socialPracticeProjectPlanEligibility(plan);
    return { ...plan, label: eligibility.canCreate ? plan.title : `${plan.title}（${eligibility.reason}）`, value: plan.id, disabled: !eligibility.canCreate };
  });
}
