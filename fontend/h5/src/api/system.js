import { request } from './client';

export function fetchContext() {
  return request('/auth/context');
}

export function login(payload) {
  return request('/auth/login', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function fetchRegisterOptions() {
  return request('/auth/register-options');
}

export function registerAccount(payload) {
  return request('/auth/register', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function logout() {
  return request('/auth/logout', {
    method: 'POST',
  });
}

export function fetchSwitchableAccounts() {
  return request('/auth/switchable-accounts');
}

export function switchAccount(payload) {
  return request('/auth/switch-account', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function passkeyLogin(payload) {
  return request('/auth/passkey-login', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function fetchMenus(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/permission/menus${query ? `?${query}` : ''}`);
}

export function fetchDataScope(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/permission/filter${query ? `?${query}` : ''}`);
}

export function fetchMessageSummary() {
  return request('/message/summary');
}

export function fetchMessages(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/message/list${query ? `?${query}` : ''}`);
}

export function markMessagesRead(payload) {
  return request('/message/read', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function fetchDocCategories() {
  return request('/doc/categories');
}

export function fetchDocList(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/doc/list${query ? `?${query}` : ''}`);
}

export function fetchDocDetail(id) {
  const query = new URLSearchParams({ id }).toString();
  return request(`/doc/detail?${query}`);
}

export function fetchTemplateCategories() {
  return request('/template/categories');
}

export function fetchTemplateList(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/template/list${query ? `?${query}` : ''}`);
}

export function downloadTemplateItem(id) {
  return request('/template/download', {
    method: 'POST',
    body: JSON.stringify({ id }),
  });
}

function internshipList(path, params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/internship/${path}${query ? `?${query}` : ''}`);
}

function internshipPost(path, payload) {
  return request(`/internship/${path}`, {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function fetchInternshipOverview() {
  return request('/internship/overview');
}

export function fetchInternshipOptions() {
  return request('/internship/options');
}

export function fetchInternshipBases(params = {}) {
  return internshipList('bases', params);
}

export function saveInternshipBase(payload) {
  return internshipPost('save-base', payload);
}

export function fetchInternshipBaseFlows(params = {}) {
  return internshipList('base-flows', params);
}

export function saveInternshipBaseFlow(payload) {
  return internshipPost('save-base-flow', payload);
}

export function fetchInternshipMentors(params = {}) {
  return internshipList('mentors', params);
}

export function saveInternshipMentor(payload) {
  return internshipPost('save-mentor', payload);
}

export function fetchInternshipArrangements(params = {}) {
  return internshipList('arrangements', params);
}

export function fetchInternshipArrangementChanges(params = {}) {
  return internshipList('arrangement-changes', params);
}

export function saveInternshipArrangement(payload) {
  return internshipPost('save-arrangement', payload);
}

export function saveInternshipArrangementChange(payload) {
  return internshipPost('save-arrangement-change', payload);
}

export function reviewInternshipArrangementChange(payload) {
  return internshipPost('review-arrangement-change', payload);
}

export function fetchInternshipApplications(params = {}) {
  return internshipList('applications', params);
}

export function saveInternshipApplication(payload) {
  return internshipPost('save-application', payload);
}

export function submitInternshipApplication(payload) {
  return internshipPost('submit-application', payload);
}

export function reviewInternshipApplication(payload) {
  return internshipPost('review-application', payload);
}

export function fetchInternshipReviewDraft(params = {}) {
  return internshipList('review-draft', params);
}

export function saveInternshipReviewDraft(payload) {
  return internshipPost('save-review-draft', payload);
}

export function fetchInternshipPairs(params = {}) {
  return internshipList('pairs', params);
}

export function saveInternshipPair(payload) {
  return internshipPost('save-pair', payload);
}

export function removeInternshipPair(payload) {
  return internshipPost('remove-pair', payload);
}

export function fetchInternshipSignIns(params = {}) {
  return internshipList('sign-ins', params);
}

export function saveInternshipSignIn(payload) {
  return internshipPost('save-sign-in', payload);
}

export function fetchInternshipJournals(params = {}) {
  return internshipList('journals', params);
}

export function saveInternshipJournal(payload) {
  return internshipPost('save-journal', payload);
}

export function reviewInternshipJournal(payload) {
  return internshipPost('review-journal', payload);
}

export function fetchInternshipReports(params = {}) {
  return internshipList('reports', params);
}

export function fetchInternshipTimeline(params = {}) {
  return internshipList('timeline', params);
}

export function requestInternshipModification(payload) {
  return internshipPost('request-modification', payload);
}

export function saveInternshipReport(payload) {
  return internshipPost('save-report', payload);
}

export function reviewInternshipReport(payload) {
  return internshipPost('review-report', payload);
}

export function reviewInternshipDocument(payload) {
  return internshipPost('review-document', payload);
}

export function fetchInternshipDelays(params = {}) {
  return internshipList('delays', params);
}

export function saveInternshipDelay(payload) {
  return internshipPost('save-delay', payload);
}

export function reviewInternshipDelay(payload) {
  return internshipPost('review-delay', payload);
}

export function fetchInternshipPlans(params = {}) {
  return internshipList('plans', params);
}

export function saveInternshipPlan(payload) {
  return internshipPost('save-plan', payload);
}

export function reviewInternshipPlan(payload) {
  return internshipPost('review-plan', payload);
}

export function fetchInternshipScores(params = {}) {
  return internshipList('scores', params);
}

export function fetchInternshipCourseScores(params = {}) {
  return internshipList('course-scores', params);
}

export function fetchInternshipStats(params = {}) {
  return internshipList('stats', params);
}

export function fetchInternshipArchiveMaterials(params = {}) {
  return internshipList('archive-materials', params);
}

export function saveInternshipScore(payload) {
  return internshipPost('save-score', payload);
}

export function fetchInternshipInsurances(params = {}) {
  return internshipList('insurances', params);
}

export function fetchInternshipSafetyLetters(params = {}) {
  return internshipList('safety-letters', params);
}

export function fetchInternshipSyllabusGuides(params = {}) {
  return internshipList('syllabus-guides', params);
}

export function saveInternshipSyllabusGuide(payload) {
  return internshipPost('save-syllabus-guide', payload);
}

export function fetchInternshipImplementationSheets(params = {}) {
  return internshipList('implementation-sheets', params);
}

export function saveInternshipImplementationSheet(payload) {
  return internshipPost('save-implementation-sheet', payload);
}

export function fetchInternshipTeacherWorkReports(params = {}) {
  return internshipList('teacher-work-reports', params);
}

export function saveInternshipTeacherWorkReport(payload) {
  return internshipPost('save-teacher-work-report', payload);
}

export function fetchInternshipInspections(params = {}) {
  return internshipList('inspections', params);
}

export function saveInternshipInspection(payload) {
  return internshipPost('save-inspection', payload);
}

function practiceList(module, params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/${module}/list${query ? `?${query}` : ''}`);
}

function practicePost(module, path, payload) {
  return request(`/${module}/${path}`, {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function fetchPracticeOverview(module) {
  return request(`/${module}/overview`);
}

export function fetchPracticeOptions(module) {
  return request(`/${module}/options`);
}

export function fetchPracticeList(module, params = {}) {
  return practiceList(module, params);
}

export function savePracticeItem(module, payload) {
  return practicePost(module, 'save', payload);
}

export function reviewPracticeItem(module, payload) {
  return practicePost(module, 'review', payload);
}

export function fetchPracticeReviewDraft(module, params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/${module}/review-draft${query ? `?${query}` : ''}`);
}

export function savePracticeReviewDraft(module, payload) {
  return practicePost(module, 'save-review-draft', payload);
}

export function requestPracticeModification(module, payload) {
  return practicePost(module, 'request-modification', payload);
}

export function fetchPracticeTimeline(module, params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/${module}/timeline${query ? `?${query}` : ''}`);
}

export function fetchPracticeExecutionList(module, execution, params = {}) {
  const path = {
    sign_in: 'sign-ins',
    journal: 'journals',
    report: 'reports',
  }[execution] || 'journals';
  const query = new URLSearchParams(params).toString();
  return request(`/${module}/${path}${query ? `?${query}` : ''}`);
}

export function savePracticeExecution(module, execution, payload) {
  const path = {
    sign_in: 'save-sign-in',
    journal: 'save-journal',
    report: 'save-report',
  }[execution] || 'save-journal';
  return practicePost(module, path, payload);
}

export function reviewPracticeExecution(module, execution, payload) {
  const path = execution === 'report' ? 'review-report' : 'review-journal';
  return practicePost(module, path, payload);
}

export function requestPracticeExecutionModification(module, payload) {
  return practicePost(module, 'request-execution-modification', payload);
}

export function savePracticeProjectScore(module, payload) {
  return practicePost(module, 'save-score', payload);
}

export function fetchPracticeExecutionTimeline(module, params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/${module}/execution-timeline${query ? `?${query}` : ''}`);
}
