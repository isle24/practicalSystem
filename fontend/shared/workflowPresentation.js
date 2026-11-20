export const workflowEntityLabels = {
  internship_plan: '实习计划',
  lab_plan: '实验教学计划',
  training_plan: '实训教学计划',
  social_practice_plan: '社会实践计划',
  base_application: '基地申报',
  base_expense: '基地建设费用申请',
};

export function workflowSnapshot(item) {
  const snapshot = item?.snapshot_json || {};
  return snapshot.plan || snapshot.application || snapshot.expense || snapshot;
}

export function trustedImportHistory(record) {
  return `${record.opinion || '教学计划中心首次可信导入免审'}。原始来源已保留，后续修改须重新审核。`;
}

function asObject(value) {
  if (typeof value === 'string') {
    try { return JSON.parse(value) || {}; } catch { return {}; }
  }
  return value || {};
}

export function workflowPlanFields(plan) {
  const contentData = asObject(plan.content_json);
  const planData = asObject(plan.plan_content);
  const source = contentData.teaching_plan_source?.raw_payload || {};
  const course = [plan.course_code || source['课程代码'], plan.course_name || source['课程名称']].filter(Boolean).join(' / ');
  const semester = plan.semester || contentData.semester || [source['学年'], source['学期']].filter(Boolean).join(' ');
  const content = plan.content || plan.description || planData.content || planData.summary || contentData.content || contentData.summary
    || (typeof plan.plan_content === 'string' && !Object.keys(planData).length ? plan.plan_content : '');
  return [
    ['计划名称', plan.title || plan.name || plan.course_name],
    ['计划编号', [plan.code, plan.id].filter(Boolean).join(' / ')],
    ['课程', course],
    ['学年学期', semester],
    ['学院', plan.dep_name || source['学院'] || plan.dep_id || plan.organizer_dep_id],
    ['专业', plan.profession_name || source['专业名称'] || plan.profession_id],
    ['年级或届次', plan.cohort_name || plan.grade_name || source['年级'] || plan.graduation_cohort_id || plan.grade_id],
    ['班级', plan.class_name || plan.class_id],
    ['课程负责人', plan.course_leader_name || plan.course_leader_id],
    ['任课教师', plan.source_teacher || (plan.teachers || []).map(item => item.teacher_name || item.teacher_id).filter(Boolean).join('、')],
    ['学分', plan.internship_credit ?? plan.credit],
    ['学时', plan.internship_hours || plan.total_hours],
    ['学生人数', plan.student_count],
    ['安排时间', plan.source_time],
    ['地点', plan.source_location],
    ['提交内容', typeof content === 'string' ? content.replace(/<[^>]*>/g, '') : JSON.stringify(content)],
    ['备注', plan.remark],
  ].map(([label, value]) => [label, String(value ?? '').trim()]);
}

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
