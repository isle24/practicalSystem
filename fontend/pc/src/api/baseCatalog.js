import { request } from './client';

function query(params) {
  return new URLSearchParams(Object.entries(params).filter(([, value]) => value !== '' && value !== null && value !== undefined)).toString();
}

export const fetchBaseCourses = (params = {}, options = {}) => request(`/base-catalog/courses?${query(params)}`, options);
export const saveBaseCourse = payload => request('/base-catalog/course-save', { method: 'POST', body: JSON.stringify(payload) });
export const fetchBasePeople = (params = {}, options = {}) => request(`/base-catalog/people?${query(params)}`, options);
