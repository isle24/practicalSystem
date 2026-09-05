import { request } from './client';

export function fetchProfileSettings() {
  return request('/profile/settings');
}

export function sendMobileCode(payload) {
  return request('/profile/send-mobile-code', { method: 'POST', body: JSON.stringify(payload) });
}

export function verifyMobile(payload) {
  return request('/profile/verify-mobile', { method: 'POST', body: JSON.stringify(payload) });
}

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

export function uploadFile(file, options = {}) {
  const body = new FormData();
  Object.entries({ require_md5: 'false', ...options }).forEach(([key, value]) => {
    if (value !== undefined && value !== null) {
      body.append(key, value);
    }
  });
  body.append('file', file);
  return request('/file/upload', { method: 'POST', body });
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

export function fetchInternshipBaseDetail(params = {}) {
  return internshipList('base-detail', params);
}

export function exportInternshipBaseWord(payload) {
  return internshipPost('export-base-word', payload);
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

export function reviewInternshipArrangement(payload) {
  return internshipPost('review-arrangement', payload);
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

export function fetchInternshipArchiveRequirements(params = {}) {
  return internshipList('archive-requirements', params);
}

export function saveInternshipArchiveRequirements(payload) {
  return internshipPost('save-archive-requirements', payload);
}

export function fetchInternshipArchiveMaterialDetail(params = {}) {
  return internshipList('archive-material-detail', params);
}

export function fetchInternshipArchiveMaterialHistory(params = {}) {
  return internshipList('archive-material-history', params);
}

export function fetchInternshipStudentProfiles(params = {}) {
  return internshipList('student-profiles', params);
}

export function fetchInternshipStudentChanges(params = {}) {
  return internshipList('student-changes', params);
}

export function fetchInternshipStudentChangeDetail(params = {}) {
  return internshipList('student-change-detail', params);
}

export function saveInternshipStudentChange(payload) {
  return internshipPost('save-student-change', payload);
}

export function reviewInternshipStudentChange(payload) {
  return internshipPost('review-student-change', payload);
}

export function createEnterpriseEvaluationInvitation(payload) {
  return internshipPost('create-enterprise-evaluation-invitation', payload);
}

export function fetchEnterpriseEvaluationProgress(params = {}) {
  return internshipList('enterprise-evaluation-progress', params);
}

export function fetchEnterpriseEvaluationRule(params = {}) {
  return internshipList('enterprise-evaluation-rule', params);
}

export function saveEnterpriseEvaluationRule(payload) {
  return internshipPost('save-enterprise-evaluation-rule', payload);
}

export function sendEnterpriseEvaluationCode(payload) {
  return request('/open-enterprise-evaluation/send-code', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function verifyEnterpriseEvaluationCode(payload) {
  return request('/open-enterprise-evaluation/verify-code', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function fetchEnterpriseEvaluationContext(payload) {
  return request('/open-enterprise-evaluation/context', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function submitEnterpriseEvaluation(payload) {
  return request('/open-enterprise-evaluation/submit', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function saveInternshipArchiveMaterial(payload) {
  return internshipPost('save-archive-material', payload);
}

export function generateInternshipArchiveMaterial(payload) {
  return internshipPost('generate-archive-material', payload);
}

export function archiveInternshipMaterial(payload) {
  return internshipPost('archive-material', payload);
}

export function saveInternshipGraduationAppraisal(payload) {
  return internshipPost('save-graduation-appraisal', payload);
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

export function saveInternshipSafetyLetter(payload) {
  return internshipPost('save-safety-letter', payload);
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

export function fetchInternshipImplementationDetail(params = {}) {
  return internshipList('implementation-detail', params);
}

export function exportInternshipImplementationPdf(payload) {
  return internshipPost('export-implementation-pdf', payload);
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

function practiceQuery(path, moduleType = 'all', params = {}) {
  const query = new URLSearchParams({ module_type: moduleType, ...params }).toString();
  return request(`/practice/${path}${query ? `?${query}` : ''}`);
}

function practicePost(path, moduleType, payload) {
  return request(`/practice/${path}`, {
    method: 'POST',
    body: JSON.stringify({ ...payload, module_type: payload.module_type || moduleType }),
  });
}

export function fetchPracticeOverview(moduleType = 'all') {
  return practiceQuery('overview', moduleType);
}

export function fetchPracticeOptions(moduleType = 'all') {
  return practiceQuery('options', moduleType);
}

export function fetchPracticePlanTeachers(moduleType, planId) {
  return practiceQuery('plan-teachers', moduleType, { plan_id: planId });
}

export function fetchPracticeProjectStudents(moduleType, projectId) {
  return practiceQuery('project-students', moduleType, { project_id: projectId });
}

export function savePracticePlanTeachers(moduleType, payload) {
  return practicePost('save-plan-teachers', moduleType, payload);
}

export function fetchPracticeList(moduleType = 'all', params = {}) {
  return practiceQuery('list', moduleType, params);
}

export function fetchPracticeScheduleWeek(moduleType = 'all', params = {}) {
  return practiceQuery('schedule-week', moduleType, params);
}

export function savePracticeItem(moduleType, payload) {
  return practicePost('save', moduleType, payload);
}

export function reviewPracticeItem(moduleType, payload) {
  return practicePost('review', moduleType, payload);
}

export function fetchPracticeReviewDraft(moduleType = 'all', params = {}) {
  return practiceQuery('review-draft', moduleType, params);
}

export function savePracticeReviewDraft(moduleType, payload) {
  return practicePost('save-review-draft', moduleType, payload);
}

export function requestPracticeModification(moduleType, payload) {
  return practicePost('request-modification', moduleType, payload);
}

export function fetchPracticeTimeline(moduleType = 'all', params = {}) {
  return practiceQuery('timeline', moduleType, params);
}

export function fetchPracticeExecutionList(moduleType = 'all', execution, params = {}) {
  const path = {
    sign_in: 'sign-ins',
    journal: 'journals',
    report: 'reports',
  }[execution] || 'journals';
  return practiceQuery(path, moduleType, params);
}

export function savePracticeExecution(moduleType, execution, payload) {
  const path = {
    sign_in: 'save-sign-in',
    journal: 'save-journal',
    report: 'save-report',
  }[execution] || 'save-journal';
  return practicePost(path, moduleType, payload);
}

export function reviewPracticeExecution(moduleType, execution, payload) {
  const path = execution === 'report' ? 'review-report' : 'review-journal';
  return practicePost(path, moduleType, payload);
}

export function requestPracticeExecutionModification(moduleType, payload) {
  return practicePost('request-execution-modification', moduleType, payload);
}

export function savePracticeProjectScore(moduleType, payload) {
  return practicePost('save-score', moduleType, payload);
}

export function fetchPracticeExecutionTimeline(moduleType = 'all', params = {}) {
  return practiceQuery('execution-timeline', moduleType, params);
}

export function fetchPracticeArchiveCheck(moduleType, planId) {
  return practiceQuery('archive-check', moduleType, { plan_id: planId });
}

export function createPracticeArchive(moduleType, planId) {
  return practicePost('archive', moduleType, { plan_id: planId });
}

export function fetchPracticeArchives(moduleType = 'all', params = {}) {
  return practiceQuery('archive-list', moduleType, params);
}

export function fetchPracticeArchiveDetail(moduleType, id) {
  return practiceQuery('archive-detail', moduleType, { id });
}

export function fetchPracticeArchiveDownload(moduleType, id) {
  return practiceQuery('archive-download', moduleType, { id });
}

export function fetchPracticeCourseScores(moduleType, params = {}) {
  return practiceQuery('course-scores', moduleType, { ...params, module_type: moduleType });
}

function socialPracticeQuery(path, params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/social-practice/${path}${query ? `?${query}` : ''}`);
}

function socialPracticePost(path, payload = {}) {
  return request(`/social-practice/${path}`, {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function fetchSocialPracticeOverview() {
  return socialPracticeQuery('overview');
}

export function fetchSocialPracticeStatistics(params = {}) {
  return socialPracticeQuery('statistics', params);
}

export function fetchSocialPracticeOptions() {
  return socialPracticeQuery('options');
}

export function fetchSocialPracticeEligibleStudents(planId) {
  return socialPracticeQuery('eligible-students', { plan_id: planId });
}

export function fetchSocialPracticeList(resource, params = {}) {
  return socialPracticeQuery('list', { ...params, resource });
}

export function fetchSocialPracticeDetail(resource, id) {
  return socialPracticeQuery('detail', { resource, id });
}

export function fetchSocialPracticeTimeline(resource, id) {
  return socialPracticeQuery('timeline', { resource, id });
}

export function saveSocialPractice(payload) {
  return socialPracticePost('save', payload);
}

export function submitSocialPractice(payload) {
  return socialPracticePost('submit', payload);
}

export function reviewSocialPractice(payload) {
  return socialPracticePost('review', payload);
}

export function requestSocialPracticeModification(payload) {
  return socialPracticePost('request-modification', payload);
}

export function confirmSocialPracticeMember(payload) {
  return socialPracticePost('confirm-member', payload);
}

export function confirmSocialPracticeTeacher(payload) {
  return socialPracticePost('confirm-teacher', payload);
}

export function reselectSocialPracticeTeacher(payload) {
  return socialPracticePost('reselect-teacher', payload);
}

export function assignSocialPracticeDeclarationTeacher(payload) {
  return socialPracticePost('assign-declaration-teacher', payload);
}

export function saveSocialPracticeMaterial(payload) {
  return socialPracticePost('save-material', payload);
}

export function saveSocialPracticeSignIn(payload) {
  return socialPracticePost('save-sign-in', payload);
}

export function saveSocialPracticePatchSign(payload) {
  return socialPracticePost('save-patch-sign', payload);
}

export function saveSocialPracticeScore(payload) {
  return socialPracticePost('save-score', payload);
}

export function fetchSocialPracticeReviewDraft(resource, id) {
  return socialPracticeQuery('review-draft', { resource, id });
}

export function saveSocialPracticeReviewDraft(payload) {
  return socialPracticePost('save-review-draft', payload);
}
