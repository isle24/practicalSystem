import { request } from './client';

function query(params) {
  return new URLSearchParams(Object.entries(params).filter(([, value]) => value !== '' && value !== null && value !== undefined)).toString();
}

function save(action, payload) {
  return request(`/base-visit/${action}`, { method: 'POST', body: JSON.stringify(payload) });
}

export function fetchBaseVisitOptions(params = {}, options = {}) {
  return request(`/base-visit/options?${query(params)}`, options);
}

export function fetchBaseVisits(params = {}, options = {}) {
  return request(`/base-visit/list?${query(params)}`, options);
}

export function fetchBaseVisitDetail(id, options = {}) {
  return request(`/base-visit/detail?${query({ id })}`, options);
}

export const assignBaseVisit = payload => save('assign', payload);
export const scheduleBaseVisit = payload => save('schedule', payload);
export const saveBaseVisitRecord = payload => save('record', payload);
export const cancelBaseVisit = payload => save('cancel', payload);
