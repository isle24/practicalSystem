const planDates = [
  ['register_start_at', '报名开始', '不得晚于报名截止'],
  ['register_end_at', '报名截止', '不得早于报名开始或晚于实践开始'],
  ['practice_start_at', '实践开始', '不得早于报名截止或晚于实践结束'],
  ['practice_end_at', '实践结束', '不得早于实践开始或晚于成果截止'],
  ['result_deadline_at', '成果截止', '不得早于实践结束或晚于成绩截止'],
  ['score_deadline_at', '成绩截止', '不得早于成果截止'],
];

export const socialPracticeDateKeys = [...planDates.map(([key]) => key), 'start_at', 'end_at'];

export function socialPracticePlanDateFields(form) {
  let previous;
  return planDates.map(([key, label, help], index) => {
    const value = form[key];
    const next = planDates.slice(index + 1).find(([nextKey]) => form[nextKey]);
    const min = previous?.value || '';
    const nextValue = next ? form[next[0]] : '';
    const max = nextValue >= min ? nextValue : '';
    const error = previous && value && value < min ? `${label}不能早于${previous.label}（${min.replace('T', ' ')}）` : '';
    if (value) previous = { value, label };
    return { key, label, help: `草稿可留空，${help}`, min, max, error };
  });
}
