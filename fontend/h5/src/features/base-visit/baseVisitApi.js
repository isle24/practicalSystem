import { request } from '../../api/client';

function query(path, params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) search.set(key, value);
  });
  const suffix = search.toString();
  return request(`/base-visit/${path}${suffix ? `?${suffix}` : ''}`);
}

function post(path, payload) {
  return request(`/base-visit/${path}`, {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export const fetchBaseVisitOptions = params => query('options', params);
export const fetchBaseVisitList = params => query('list', params);
export const fetchBaseVisitDetail = id => query('detail', { id });
export const assignBaseVisit = payload => post('assign', payload);
export const scheduleBaseVisit = payload => post('schedule', payload);
export const saveBaseVisitRecord = payload => post('record', payload);
export const cancelBaseVisit = payload => post('cancel', payload);
