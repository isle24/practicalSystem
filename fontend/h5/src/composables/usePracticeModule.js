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
    },
    filters: Object.fromEntries(practicePanelKeys.map(key => [key, emptyPracticeFilters()])),
    lists: Object.fromEntries(practicePanelKeys.map(key => [key, emptyPagedList()])),
    schedule: {
      week_start: '',
      week_end: '',
      periods: [],
      items: [],
    },
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
      location: '',
      longitude: '',
      latitude: '',
      accuracy: null,
      located_at: '',
      locating: false,
      gps_error: '',
      remark: '',
    },
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

export function usePracticeModule() {
  const practice = reactive(createPracticeState());
  const reviewDialog = reactive(createReviewDialog());
  const executionDialog = reactive(createExecutionDialog());
  const timelineDialog = reactive(createTimelineDialog());

  function reset() {
    Object.assign(practice, createPracticeState());
    Object.assign(reviewDialog, createReviewDialog());
    Object.assign(executionDialog, createExecutionDialog());
    Object.assign(timelineDialog, createTimelineDialog());
  }

  return {
    practice,
    reviewDialog,
    executionDialog,
    timelineDialog,
    emptyFilters: emptyPracticeFilters,
    reset,
  };
}
