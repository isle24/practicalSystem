import { apiBase, request } from './client';

export function fetchAccountImportTasks(type, options = {}) {
  return request(`/account-import/tasks?${new URLSearchParams({ type })}`, options);
}

export function fetchAccountImportDetail(id, options = {}) {
  return request(`/account-import/detail?${new URLSearchParams({ id })}`, options);
}

export function previewTeacherAccounts(file, options = {}) {
  const body = new FormData();
  body.append('file', file);
  return request('/account-import/teacher-preview', { ...options, method: 'POST', body });
}

export function startTeacherAccounts(payload) {
  return request('/account-import/teacher-start', { method: 'POST', body: JSON.stringify(payload) });
}

export function previewStudentAccounts(options = {}) {
  return request('/account-import/student-preview', { ...options, method: 'POST', body: JSON.stringify({ filters: {} }) });
}

export function startStudentAccounts(payload) {
  return request('/account-import/student-start', { method: 'POST', body: JSON.stringify({ ...payload, filters: {} }) });
}

export function retryAccountImport(id) {
  return request('/account-import/retry', { method: 'POST', body: JSON.stringify({ id }) });
}

export function teacherAccountTemplateUrl() {
  return `${apiBase}/account-import/teacher-template`;
}
