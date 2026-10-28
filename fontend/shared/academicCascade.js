import { computed, isReactive } from 'vue';

const linkedGradeIndexes = new WeakMap();

function hasId(value) {
  return value !== '' && value !== null && value !== undefined && value !== 0 && value !== '0';
}

function sameId(left, right) {
  return String(left ?? '') === String(right ?? '');
}

function buildLinkedGradeIndex(options) {
  const grades = new Map();
  for (const key of ['classes', 'students', 'plans', 'arrangements']) {
    for (const item of options[key] || []) {
      const gradeId = item.grade_id;
      const professionId = item.profession_id;
      if (!hasId(gradeId) || !hasId(professionId)) continue;
      const gradeKey = String(gradeId);
      const depKey = hasId(item.dep_id) ? String(item.dep_id) : '';
      if (!grades.has(gradeKey)) grades.set(gradeKey, { all: new Set(), departments: new Map() });
      const grade = grades.get(gradeKey);
      if (!grade.departments.has(depKey)) grade.departments.set(depKey, new Set());
      grade.all.add(String(professionId));
      grade.departments.get(depKey).add(String(professionId));
    }
  }
  return grades;
}

function linkedGradeIndex(options) {
  if (!isReactive(options)) return buildLinkedGradeIndex(options);
  let index = linkedGradeIndexes.get(options);
  if (!index) {
    index = computed(() => buildLinkedGradeIndex(options));
    linkedGradeIndexes.set(options, index);
  }
  return index.value;
}

export function filterAcademicProfessions(options = {}, values = {}, { allowLinkedGrades = true } = {}) {
  const gradeId = hasId(values.graduation_cohort_id) ? null : values.grade_id;
  const grade = allowLinkedGrades && hasId(gradeId) ? linkedGradeIndex(options).get(String(gradeId)) : null;
  const associatedIds = hasId(values.dep_id) ? grade?.departments.get(String(values.dep_id)) : grade?.all;
  const unscopedIds = hasId(values.dep_id) ? grade?.departments.get('') : null;
  return (options.professions || []).filter((item) => {
    if (hasId(values.dep_id) && !sameId(item.dep_id, values.dep_id)) return false;
    return !hasId(gradeId) || !hasId(item.grade_id) || sameId(item.grade_id, gradeId)
      || associatedIds?.has(String(item.profession_id)) || unscopedIds?.has(String(item.profession_id));
  });
}

export function filterAcademicClasses(options = {}, values = {}) {
  const keys = hasId(values.graduation_cohort_id)
    ? ['dep_id', 'profession_id']
    : ['grade_id', 'dep_id', 'profession_id'];
  return (options.classes || []).filter(item => keys.every(key => !hasId(values[key]) || sameId(item[key], values[key])));
}
