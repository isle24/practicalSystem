import { reactive } from 'vue';
import { emptyPagedList } from '../utils/pagination';

const practicePanelKeys = [
  'plans',
  'schedules',
  'projects',
  'signIns',
  'journals',
  'reports',
  'syllabus',
  'lessonPlans',
  'gradeRules',
  'scores',
  'reflections',
  'archives',
];

export function emptyPracticeFilters() {
  return {
    module_type: 'all',
    grade_id: '',
    dep_id: '',
    profession_id: '',
    class_id: '',
    plan_id: '',
    status: '',
    keyword: '',
  };
}

export function createPracticeState() {
  return {
    loading: false,
    message: '',
    panel: 'plans',
    overview: {
      plans_waiting: 0,
      schedules: 0,
      projects: 0,
      syllabus_waiting: 0,
      lesson_plans_waiting: 0,
      scores_submitted: 0,
      reflections_waiting: 0,
      today_schedules: 0,
    },
    options: {
      grades: [],
      departments: [],
      professions: [],
      classes: [],
      teachers: [],
      students: [],
      rooms: [],
      plans: [],
      schedules: [],
      projects: [],
      review_rules: {},
      current_teacher_id: null,
      current_student_id: null,
    },
    filters: Object.fromEntries(practicePanelKeys.map(key => [key, emptyPracticeFilters()])),
    lists: Object.fromEntries(practicePanelKeys.map(key => [key, emptyPagedList()])),
    schedule: {
      week_start: '',
      week_end: '',
      periods: [],
      items: [],
    },
    archive: {
      plan_id: null,
      check: null,
      detail: null,
      detailVisible: false,
    },
    scoreView: 'scores',
  };
}

function createReviewDialog() {
  return {
    visible: false,
    mode: 'review',
    module: 'all',
    panel: 'plans',
    entity: 'plan',
    status: 'accept',
    row: null,
    reason: '',
  };
}

function createExecutionDialog() {
  return {
    visible: false,
    module: 'all',
    panel: 'journals',
    execution: 'journal',
    row: null,
    form: {
      id: null,
      project_id: null,
      title: '',
      date: '',
      content: '',
      reflection_summary: '',
      location: '',
      longitude: '',
      latitude: '',
      accuracy: null,
      located_at: '',
      locating: false,
      gps_error: '',
      remark: '',
      student_id: null,
      attendance_score: '',
      material_score: '',
      report_score: '',
      score_value: '',
    },
    students: [],
  };
}

function createTimelineDialog() {
  return {
    visible: false,
    loading: false,
    title: '',
    subtitle: '',
    items: [],
    cycles: [],
    message: '',
  };
}

function createMaterialDialog() {
  return {
    visible: false,
    module: 'all',
    panel: 'lessonPlans',
    entity: 'lessonPlan',
    row: null,
    form: {
      id: null,
      module_type: '',
      plan_id: null,
      title: '',
      content: '',
      attendance_weight: 20,
      operation_weight: 70,
      report_weight: 10,
      projects: [],
    },
  };
}

export function usePracticeModule() {
  const practice = reactive(createPracticeState());
  const reviewDialog = reactive(createReviewDialog());
  const executionDialog = reactive(createExecutionDialog());
  const timelineDialog = reactive(createTimelineDialog());
  const materialDialog = reactive(createMaterialDialog());

  function reset() {
    Object.assign(practice, createPracticeState());
    Object.assign(reviewDialog, createReviewDialog());
    Object.assign(executionDialog, createExecutionDialog());
    Object.assign(timelineDialog, createTimelineDialog());
    Object.assign(materialDialog, createMaterialDialog());
  }

  return {
    practice,
    reviewDialog,
    executionDialog,
    timelineDialog,
    materialDialog,
    emptyFilters: emptyPracticeFilters,
    reset,
  };
}
