import { reactive } from 'vue';
import { emptyPagedList } from '../utils/pagination';

export const defaultInternshipReviewRules = {
  arrangement_change: {
    accept: { min: 0, max: 300 },
    modify: { min: 5, max: 500 },
    refuse: { min: 5, max: 500 },
  },
  application: {
    accept: { min: 0, max: 200 },
    modify: { min: 5, max: 500 },
    skipped: { min: 0, max: 200 },
  },
  journal: {
    accept: { min: 0, max: 200 },
    modify: { min: 5, max: 500 },
  },
  report: {
    accept: { min: 0, max: 300 },
    modify: { min: 8, max: 800 },
  },
  plan: {
    accept: { min: 0, max: 300 },
    modify: { min: 8, max: 800 },
  },
  delay: {
    accept: { min: 0, max: 300 },
    refuse: { min: 5, max: 500 },
    modify: { min: 5, max: 500 },
  },
  syllabus_guide: {
    accept: { min: 0, max: 300 },
    modify: { min: 5, max: 500 },
  },
  implementation_sheet: {
    accept: { min: 0, max: 300 },
    modify: { min: 5, max: 500 },
  },
  teacher_work_report: {
    accept: { min: 0, max: 300 },
    modify: { min: 5, max: 500 },
  },
  inspection: {
    accept: { min: 0, max: 300 },
    modify: { min: 5, max: 500 },
  },
};

export const internshipReviewStatusLabels = {
  accept: '通过',
  modify: '退回修改',
  refuse: '退回',
  skipped: '跳过',
};

export function emptyInternshipFilters() {
  return {
    grade_id: '',
    dep_id: '',
    profession_id: '',
    class_id: '',
    arrangement_id: '',
    status: '',
    result: '',
    keyword: '',
  };
}

export function emptyInternshipOverview() {
  return {
    arrangements: 0,
    applications_waiting: 0,
    active_pairs: 0,
    journals_waiting: 0,
    reports_waiting: 0,
    today_sign_ins: 0,
  };
}

export function emptyInternshipOptions() {
  return {
    plans: [],
    arrangements: [],
    grades: [],
    departments: [],
    professions: [],
    classes: [],
    teachers: [],
    report_templates: [],
    review_rules: defaultInternshipReviewRules,
    deadline_configs: {},
  };
}

export function createInternshipState() {
  const listKeys = [
    'arrangements',
    'arrangementChanges',
    'plans',
    'syllabusGuides',
    'implementationSheets',
    'applications',
    'pairs',
    'signIns',
    'journals',
    'reports',
    'teacherWorkReports',
    'delays',
    'scores',
    'courseScores',
    'inspections',
    'archiveMaterials',
    'insurances',
    'safetyLetters',
  ];

  return {
    loading: false,
    message: '',
    panel: 'workbench',
    submitSection: '',
    reviewList: 'applications',
    manageList: 'arrangements',
    overview: emptyInternshipOverview(),
    options: emptyInternshipOptions(),
    lists: Object.fromEntries(listKeys.map(key => [key, emptyPagedList()])),
    filters: Object.fromEntries(listKeys.map(key => [key, emptyInternshipFilters()])),
    forms: {
      application: { arrangement_id: null, type: 'distributed', remark: '' },
      sign: { arrangement_id: null, location: '', longitude: null, latitude: null, accuracy: null, located_at: '', locating: false, gps_error: '' },
      journal: { id: null, arrangement_id: null, title: '', content: '' },
      report: { id: null, arrangement_id: null, title: '', content: '' },
      delay: { id: null, arrangement_id: null, config_key: 'report_deadline', requested_date: '', reason: '' },
      score: {
        pair_id: null,
        student_id: null,
        arrangement_id: null,
        sign_in_score: '',
        journal_score: '',
        report_score: '',
        enterprise_score: '',
      },
    },
    reviewDialog: {
      visible: false,
      mode: 'review',
      entity: 'application',
      status: 'accept',
      row: null,
      reason: '',
    },
    timelineDialog: {
      visible: false,
      loading: false,
      entity: 'application',
      row: null,
      title: '',
      subtitle: '',
      items: [],
      cycles: [],
      message: '',
    },
  };
}

export function useInternship() {
  const internship = reactive(createInternshipState());

  function reset() {
    Object.assign(internship, createInternshipState());
  }

  return {
    internship,
    reviewStatusLabels: internshipReviewStatusLabels,
    emptyFilters: emptyInternshipFilters,
    emptyOptions: emptyInternshipOptions,
    emptyOverview: emptyInternshipOverview,
    reset,
  };
}
