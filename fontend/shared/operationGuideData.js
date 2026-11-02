import entries from './operation-guides.json';

const guides = new Map(entries.map(entry => [entry.key, entry]));

export function findOperationGuide(module, panel = '', parentPanel = '') {
  return (panel && guides.get(`${module}.${panel}`))
    || (parentPanel && guides.get(`${module}.${parentPanel}`))
    || guides.get(module)
    || null;
}

const mobileModules = {
  doc: 'doc',
  templateLib: 'templateLib',
  notebook: 'notebook',
  releaseNotes: 'releaseNotes',
  message: 'message',
  mine: 'profile',
  signature: 'profile.signature',
  expense: 'expenseManage',
  baseVisit: 'baseVisits',
  socialPractice: 'socialPractice',
  teachingPlanCenter: 'teachingPlanCenter',
};
const internshipPanels = { workbench: 'overview', apply: 'applications', changes: 'studentChanges', score: 'scores' };
const internshipSections = { sign: 'signIns', journal: 'journals', report: 'reports', safety: 'safetyLetters', delay: 'delays' };
const internshipLists = { archiveMaterials: 'documents' };

export function mobileOperationGuideContext({ tab, internshipPanel = '', internshipList = '', internshipSection = '', practicePanel = '' } = {}) {
  let key = mobileModules[tab] || '';
  if (tab === 'internship') {
    const panel = internshipPanel === 'submit'
      ? internshipSections[internshipSection]
      : ['review', 'manage'].includes(internshipPanel) ? internshipLists[internshipList] || internshipList : internshipPanels[internshipPanel];
    key = panel ? `internship.${panel}` : (internshipPanel === 'submit' && !internshipSection ? 'internship' : '');
  } else if (tab === 'practice') {
    key = practicePanel ? `practice.${practicePanel}` : '';
  }
  const guide = guides.get(key);
  return guide ? { module: guide.module, panel: guide.panel } : { module: '', panel: '' };
}
