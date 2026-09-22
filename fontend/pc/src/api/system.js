import { request } from './client';

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

export function checkWechatConfig(payload) {
  return request('/wechat/check-config', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function syncWechatMenu() {
  return request('/wechat/sync-menu', {
    method: 'POST',
    timeoutMs: 45000,
  });
}

export function fetchEduBatches(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/edu-data/batches${query ? `?${query}` : ''}`);
}

export function fetchEduBatchDetail(id) {
  return request(`/edu-data/batch-detail?id=${encodeURIComponent(id)}`);
}

export function uploadEduData(type, file, params = {}) {
  const body = new FormData();
  body.append('type', type);
  body.append('file', file);
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      body.append(key, value);
    }
  });
  return request('/edu-data/upload', { method: 'POST', body, timeoutMs: 0 });
}

export function fetchEduSource(type, params = {}) {
  const query = new URLSearchParams(params).toString();
  const paths = { student: 'students', teaching_plan: 'teaching-plans', course_offering: 'course-offerings' };
  return request(`/edu-data/${paths[type] || paths.student}${query ? `?${query}` : ''}`);
}

export function fetchEduChanges(batchId, params = {}) {
  const query = new URLSearchParams({ batch_id: batchId, ...params }).toString();
  return request(`/edu-data/changes?${query}`);
}

export function fetchEduIssues(batchId, params = {}) {
  const query = new URLSearchParams({ batch_id: batchId, ...params }).toString();
  return request(`/edu-data/issues?${query}`);
}

export function resolveEduIssue(id) {
  return request('/edu-data/resolve-issue', {
    method: 'POST',
    body: JSON.stringify({ id }),
  });
}

export function fetchEduCandidates(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/edu-data/candidates${query ? `?${query}` : ''}`);
}

export function publishEduBatch(id) {
  return request('/edu-data/publish', { method: 'POST', body: JSON.stringify({ id }) });
}

export function cancelEduBatch(id) {
  return request('/edu-data/cancel', { method: 'POST', body: JSON.stringify({ id }) });
}

export function classifyEduCandidate(id, businessType) {
  return request('/edu-data/classify-candidate', { method: 'POST', body: JSON.stringify({ id, business_type: businessType }) });
}

export function confirmEduCandidates(ids) {
  return request('/edu-data/confirm-candidates', { method: 'POST', body: JSON.stringify({ ids }) });
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

export function fetchMessageTemplates(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/message/templates${query ? `?${query}` : ''}`);
}

export function saveMessageTemplate(payload) {
  return request('/message/save-template', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function syncMessageTemplates() {
  return request('/message/sync-templates', {
    method: 'POST',
  });
}

export function deleteMessageTemplate(id) {
  return request('/message/delete-template', {
    method: 'POST',
    body: JSON.stringify({ id }),
  });
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

export function clearTestData(payload) {
  return request('/admin/clear-test-data', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function fetchDataCleanupOptions() {
  return request('/admin/data-cleanup-options');
}

export function createDataCleanupTask(payload) {
  return request('/admin/data-cleanup-task', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function fetchDataCleanupTask(id) {
  return request(`/admin/data-cleanup-task?id=${encodeURIComponent(id)}`);
}

export function fetchDataEnvironment() {
  return request('/admin/data-environment');
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

export function uploadMenuIcon(file) {
  const body = new FormData();
  body.append('file', file);
  return request('/admin/upload-menu-icon', {
    method: 'POST',
    body,
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

export function changeOwnPassword(payload) {
  return request('/profile/change-password', {
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

export function fetchDesktopShortcuts() {
  return request('/desktop/shortcuts');
}

export function saveDesktopShortcuts(payload) {
  return request('/desktop/save-shortcuts', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function fetchFavorites(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/favorite/list${query ? `?${query}` : ''}`);
}

export function saveFavorite(payload) {
  return request('/favorite/save', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function deleteFavorite(id) {
  return request('/favorite/delete', {
    method: 'POST',
    body: JSON.stringify({ id }),
  });
}

export function uploadFavoriteIcon(file) {
  const body = new FormData();
  body.append('file', file);
  return request('/favorite/upload-icon', {
    method: 'POST',
    body,
  });
}

export function uploadFile(file, options = {}) {
  const body = new FormData();
  Object.entries({ require_md5: 'false', ...options }).forEach(([key, value]) => {
    if (value !== undefined && value !== null) {
      body.append(key, value);
    }
  });
  body.append('file', file);
  return request('/file/upload', {
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

export function fetchEducationPlanSyncConfig() {
  return request('/education-plan-sync/config');
}

export function saveEducationPlanSyncConfig(payload) {
  return request('/education-plan-sync/save-config', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function pullEducationPlans(payload = {}) {
  return request('/education-plan-sync/pull', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function fetchEducationPlanSyncInbox(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/education-plan-sync/inbox${query ? `?${query}` : ''}`);
}

export function fetchEducationPlanSyncDetail(id) {
  const query = new URLSearchParams({ id }).toString();
  return request(`/education-plan-sync/detail?${query}`);
}

export function confirmEducationPlanSync(payload) {
  return request('/education-plan-sync/confirm', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function ignoreEducationPlanSync(payload) {
  return request('/education-plan-sync/ignore', {
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

export function fetchTeacherSyncTeachers(params = {}) {
  const query = new URLSearchParams(params).toString();
  return request(`/teacher-sync/teachers${query ? `?${query}` : ''}`);
}

export function pullTeacherSync(payload = {}) {
  return request('/teacher-sync/pull', {
    method: 'POST',
    body: JSON.stringify(payload),
    timeoutMs: 1500000,
  });
}

export function fetchTeacherSyncConfig() {
  return request('/teacher-sync/config');
}

export function saveTeacherSyncConfig(payload) {
  return request('/teacher-sync/save-config', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function saveTeacherSyncApplication(payload) {
  return request('/teacher-sync/save-application', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function saveInternshipBase(payload) {
  return internshipPost('save-base', payload);
}

export function previewInternshipBaseImport(file) {
  const body = new FormData();
  body.append('file', file);
  return request('/internship/preview-base-import', {
    method: 'POST',
    body,
    timeoutMs: 150000,
  });
}

export function confirmInternshipBaseImport(payload) {
  return internshipPost('confirm-base-import', payload);
}

export function fetchInternshipBaseDetail(params = {}) {
  return internshipList('base-detail', params);
}

export function exportInternshipBaseWord(payload) {
  return internshipPost('export-base-word', payload);
}

export function fetchInternshipBaseFlows(params = {}) {
  return internshipList('base-flows', params);
}

export function saveInternshipBaseFlow(payload) {
  return internshipPost('save-base-flow', payload);
}

export function reviewInternshipBaseFlow(payload) {
  return internshipPost('review-base-flow', payload);
}

export function fetchInternshipMentors(params = {}) {
  return internshipList('mentors', params);
}

export function saveInternshipMentor(payload) {
  return internshipPost('save-mentor', payload);
}

export function fetchInternshipPlanImportTemplate() {
  return request('/internship/plan-import-template');
}

/** 获取基地汇总表导入模板。 */
export function fetchInternshipBaseImportTemplate() {
  return request('/internship/base-import-template');
}

export function previewInternshipPlanImport(file) {
  const body = new FormData();
  body.append('file', file);
  return request('/internship/preview-plan-import', {
    method: 'POST',
    body,
  });
}

export function confirmInternshipPlanImport(payload) {
  return internshipPost('confirm-plan-import', payload);
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

export function reviewInternshipArrangement(payload) {
  return internshipPost('review-arrangement', payload);
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

export function fetchInternshipReviewDraft(params = {}) {
  return internshipList('review-draft', params);
}

export function saveInternshipReviewDraft(payload) {
  return internshipPost('save-review-draft', payload);
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

export function saveInternshipCourseScore(payload) {
  return internshipPost('save-course-score', payload);
}

export function fetchInternshipStats(params = {}) {
  return internshipList('stats', params);
}

export function exportPracticeScoreSheet(payload = {}) {
  return internshipPost('export-practice-score-sheet', payload);
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

export function fetchInternshipGraduationAppraisal(params = {}) {
  return internshipList('graduation-appraisal', params);
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

function practiceList(moduleType, params = {}) {
  const query = new URLSearchParams({ ...params, module_type: moduleType }).toString();
  return request(`/practice/list${query ? `?${query}` : ''}`);
}

function practicePost(moduleType, path, payload = {}) {
  return request(`/practice/${path}`, {
    method: 'POST',
    body: JSON.stringify({ ...payload, module_type: moduleType }),
  });
}

export function fetchPracticeOverview(moduleType = 'all') {
  return request(`/practice/overview?${new URLSearchParams({ module_type: moduleType })}`);
}

export function fetchPracticeOptions(moduleType = 'all') {
  return request(`/practice/options?${new URLSearchParams({ module_type: moduleType })}`);
}

export function fetchPracticePlanTeachers(moduleType, planId) {
  const query = new URLSearchParams({ module_type: moduleType, plan_id: planId }).toString();
  return request(`/practice/plan-teachers?${query}`);
}

export function fetchPracticeProjectStudents(moduleType, projectId) {
  const query = new URLSearchParams({ module_type: moduleType, project_id: projectId }).toString();
  return request(`/practice/project-students?${query}`);
}

export function savePracticePlanTeachers(moduleType, payload) {
  return practicePost(moduleType, 'save-plan-teachers', payload);
}

export function fetchPracticeList(moduleType, params = {}) {
  return practiceList(moduleType, params);
}

export function savePracticeItem(moduleType, payload) {
  return practicePost(moduleType, 'save', payload);
}

export function reviewPracticeItem(module, payload) {
  return practicePost(module, 'review', payload);
}

export function fetchPracticeReviewDraft(module, params = {}) {
  const query = new URLSearchParams({ ...params, module_type: module }).toString();
  return request(`/practice/review-draft?${query}`);
}

export function savePracticeReviewDraft(module, payload) {
  return practicePost(module, 'save-review-draft', payload);
}

export function requestPracticeModification(module, payload) {
  return practicePost(module, 'request-modification', payload);
}

export function fetchPracticeTimeline(module, params = {}) {
  const query = new URLSearchParams({ ...params, module_type: module }).toString();
  return request(`/practice/timeline?${query}`);
}

export function fetchPracticeExecutionList(module, execution, params = {}) {
  const path = {
    sign_in: 'sign-ins',
    journal: 'journals',
    report: 'reports',
  }[execution] || 'journals';
  const query = new URLSearchParams({ ...params, module_type: module }).toString();
  return request(`/practice/${path}?${query}`);
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
  const query = new URLSearchParams({ ...params, module_type: module }).toString();
  return request(`/practice/execution-timeline?${query}`);
}

export function fetchPracticeArchiveCheck(moduleType, planId) {
  const query = new URLSearchParams({ module_type: moduleType, plan_id: planId }).toString();
  return request(`/practice/archive-check?${query}`);
}

export function createPracticeArchive(moduleType, planId) {
  return practicePost(moduleType, 'archive', { plan_id: planId });
}

export function fetchPracticeArchives(moduleType, params = {}) {
  const query = new URLSearchParams({ ...params, module_type: moduleType }).toString();
  return request(`/practice/archive-list?${query}`);
}

export function fetchPracticeArchiveDetail(moduleType, id) {
  const query = new URLSearchParams({ module_type: moduleType, id }).toString();
  return request(`/practice/archive-detail?${query}`);
}

export function fetchPracticeArchiveDownload(moduleType, id) {
  const query = new URLSearchParams({ module_type: moduleType, id }).toString();
  return request(`/practice/archive-download?${query}`);
}

export function fetchPracticeCourseScores(moduleType, params = {}) {
  const query = new URLSearchParams({ ...params, module_type: moduleType }).toString();
  return request(`/practice/course-scores?${query}`);
}

export function fetchPracticePeriods(params = {}) {
  const query = new URLSearchParams({ ...params, module_type: 'all' }).toString();
  return request(`/practice/periods${query ? `?${query}` : ''}`);
}

export function savePracticePeriod(payload) {
  return practicePost('all', 'save-period', payload);
}

export function fetchPracticeScheduleWeek(moduleType, params = {}) {
  const query = new URLSearchParams({ ...params, module_type: moduleType }).toString();
  return request(`/practice/schedule-week?${query}`);
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

export function exportSocialPracticeStatistics(payload = {}) {
  return socialPracticePost('export-statistics', payload);
}

export function fetchSocialPracticePlanImportTemplate() {
  return socialPracticeQuery('plan-import-template');
}

export function previewSocialPracticePlanImport(file) {
  const form = new FormData();
  form.append('file', file);
  return request('/social-practice/preview-plan-import', { method: 'POST', body: form });
}

export function confirmSocialPracticePlanImport(payload) {
  return socialPracticePost('confirm-plan-import', payload);
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

export function publishSocialPractice(payload) {
  return socialPracticePost('publish', payload);
}

export function requestSocialPracticeModification(payload) {
  return socialPracticePost('request-modification', payload);
}

export function assignSocialPracticeTeachers(payload) {
  return socialPracticePost('assign-teachers', payload);
}

export function assignSocialPracticeStudents(payload) {
  return socialPracticePost('assign-students', payload);
}

export function assignSocialPracticeDeclarationTeacher(payload) {
  return socialPracticePost('assign-declaration-teacher', payload);
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

export function archiveSocialPractice(payload) {
  return socialPracticePost('archive', payload);
}

export function fetchSocialPracticeReviewDraft(resource, id) {
  return socialPracticeQuery('review-draft', { resource, id });
}

export function saveSocialPracticeReviewDraft(payload) {
  return socialPracticePost('save-review-draft', payload);
}
