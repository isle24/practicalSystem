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
  };
}

function createReviewDialog() {
  return {
    visible: false,
    mode: 'review',
    module: 'training',
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
    module: 'training',
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
  return {
    practice: reactive({
      training: createPracticeState(),
      lab: createPracticeState(),
    }),
    reviewDialog: reactive(createReviewDialog()),
    executionDialog: reactive(createExecutionDialog()),
    timelineDialog: reactive(createTimelineDialog()),
    emptyFilters: emptyPracticeFilters,
  };
}
