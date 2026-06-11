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

export function fetchSchool() {
  return request('/school/current');
}

export function fetchMenus(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/permission/menus${query ? `?${query}` : ''}`);
}

export function fetchDataScope(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/permission/filter${query ? `?${query}` : ''}`);
}

export function fetchWechatProxy() {
  return request('/wechat/proxy');
}

export function saveWechatProxy(payload) {
  return request('/wechat/save-proxy', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function fetchWechatConfig() {
  return request('/wechat/config');
}

export function saveWechatConfig(payload) {
  return request('/wechat/save-config', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function fetchOperationLogs(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/log/list${query ? `?${query}` : ''}`);
}

export function fetchMessageSummary() {
  return request('/message/summary');
}

export function fetchMessages(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/message/list${query ? `?${query}` : ''}`);
}

export function fetchMessageTargets(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/message/targets${query ? `?${query}` : ''}`);
}

export function sendMessage(payload) {
  return request('/message/send', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function markMessagesRead(payload) {
  return request('/message/read', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function fetchOperationGuide(module) {
  const query = new URLSearchParams({ module }).toString();
  return request(`/guide/current?${query}`);
}

export function fetchOperationGuides() {
  return request('/guide/list');
}

export function saveOperationGuide(payload) {
  return request('/guide/save', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function deleteOperationGuide(id) {
  return request('/guide/delete', {
    method: 'POST',
    body: JSON.stringify({ id }),
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

export function fetchDocHistory(articleId) {
  const query = new URLSearchParams({ article_id: articleId }).toString();
  return request(`/doc/history?${query}`);
}

export function saveDocCategory(payload) {
  return request('/doc/save-category', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function saveDocArticle(payload) {
  return request('/doc/save', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function deleteDocArticle(id) {
  return request('/doc/delete', {
    method: 'POST',
    body: JSON.stringify({ id }),
  });
}

export function fetchTemplateCategories() {
  return request('/template/categories');
}

export function fetchTemplateList(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/template/list${query ? `?${query}` : ''}`);
}

export function saveTemplateCategory(payload) {
  return request('/template/save-category', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function uploadTemplateFile(file) {
  const body = new FormData();
  body.append('file', file);
  return request('/template/upload', {
    method: 'POST',
    body,
  });
}

export function saveTemplateItem(payload) {
  return request('/template/save', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function deleteTemplateItem(id) {
  return request('/template/delete', {
    method: 'POST',
    body: JSON.stringify({ id }),
  });
}

export function downloadTemplateItem(id) {
  return request('/template/download', {
    method: 'POST',
    body: JSON.stringify({ id }),
  });
}

export function fetchExportTasks(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/export/list${query ? `?${query}` : ''}`);
}

export function createExportTask(payload) {
  return request('/export/create', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function retryExportTask(id) {
  return request('/export/retry', {
    method: 'POST',
    body: JSON.stringify({ id }),
  });
}

export function fetchAdminRoles() {
  return request('/admin/roles');
}

export function fetchAdminAccounts(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/admin/accounts${query ? `?${query}` : ''}`);
}

export function fetchAdminAccountDetail(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/admin/account-detail${query ? `?${query}` : ''}`);
}

export function saveAdminAccount(payload) {
  return request('/admin/save-account', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function changeAdminAccountStatus(payload) {
  return request('/admin/change-account-status', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function resetAdminAccountPassword(payload) {
  return request('/admin/reset-account-password', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function generateAdminLoginPasskey(payload) {
  return request('/admin/login-passkey', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function fetchAdminMenus() {
  return request('/admin/menus');
}

export function saveMenu(payload) {
  return request('/admin/save-menu', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function deleteMenu(id) {
  return request('/admin/delete-menu', {
    method: 'POST',
    body: JSON.stringify({ id }),
  });
}

export function fetchProfileSettings() {
  return request('/profile/settings');
}

export function fetchLoginPageSettings() {
  return request('/config/login-page');
}

export function uploadLoginBackground(file) {
  const body = new FormData();
  body.append('file', file);
  return request('/config/upload-login-background', {
    method: 'POST',
    body,
  });
}

export function saveProfileSettings(payload) {
  return request('/profile/save-settings', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function uploadProfileAsset(type, file) {
  const body = new FormData();
  body.append('type', type);
  body.append('file', file);
  return request('/profile/upload-asset', {
    method: 'POST',
    body,
  });
}

export function fetchFileList(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/file/list${query ? `?${query}` : ''}`);
}

export function fetchArchiveList(type, params = {}) {
  const query = new URLSearchParams({ type, ...params }).toString();
  return request(`/archive/list?${query}`);
}

export function saveArchiveItem(payload) {
  return request('/archive/save', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function deleteArchiveItem(payload) {
  return request('/archive/delete', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function importArchiveExcel(type, file, params = {}) {
  const body = new FormData();
  body.append('type', type);
  body.append('file', file);
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      body.append(key, value);
    }
  });
  return request('/archive/import-excel', {
    method: 'POST',
    body,
  });
}

export function fetchRolePermissions(roleId) {
  const query = new URLSearchParams({ role_id: roleId }).toString();
  return request(`/admin/role-permissions?${query}`);
}

export function saveRoleMenus(payload) {
  return request('/admin/save-role-menus', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function fetchAdminOptions() {
  return request('/admin/options');
}

export function fetchOrganizationScopes(params) {
  const query = new URLSearchParams(params).toString();
  return request(`/admin/organization-scopes?${query}`);
}

export function saveOrganizationScopes(payload) {
  return request('/admin/save-organization-scopes', {
    method: 'POST',
    body: JSON.stringify(payload),
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

export function fetchInternshipArrangementDetail(params = {}) {
  return internshipList('arrangement-detail', params);
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

export function importInternshipArrangementAssignments(file) {
  const body = new FormData();
  body.append('file', file);
  return request('/internship/import-arrangement-assignments', {
    method: 'POST',
    body,
  });
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

export function fetchInternshipTimeline(params = {}) {
  return internshipList('timeline', params);
}

export function requestInternshipModification(payload) {
  return internshipPost('request-modification', payload);
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

export function saveInternshipReport(payload) {
  return internshipPost('save-report', payload);
}

export function reviewInternshipReport(payload) {
  return internshipPost('review-report', payload);
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

export function saveInternshipCourseScore(payload) {
  return internshipPost('save-course-score', payload);
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

export function requestPracticeModification(module, payload) {
  return practicePost(module, 'request-modification', payload);
}

export function fetchPracticeTimeline(module, params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/${module}/timeline${query ? `?${query}` : ''}`);
}
