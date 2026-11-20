const images = import.meta.glob('./assets/menu-icons/*.webp', {
  eager: true,
  import: 'default',
  query: '?url&no-inline',
});

const artworkByKey = new Map(Object.entries({
  internship: 'BriefcaseBusiness',
  laboratory: 'practice training lab FlaskConical',
  fieldwork: 'socialPractice centralized Route',
  database: 'dataCenter dataManage databaseSchema storage manage HardDrive Database',
  resources: 'resourceCenter file fileManage Folder FolderOpen',
  plugins: 'pluginCenter wechatProxy Puzzle',
  favorites: 'favorite Star Bookmark',
  notebook: 'notes Notebook',
  bulletin: 'releaseNotes releases Newspaper Megaphone',
  campus: 'home companyManage departmentManage department rooms schoolAppearance baseFlows enterpriseEvaluations Building Building2 School',
  settings: 'config appearance preferences Settings SlidersHorizontal',
  profile: 'mine general signature student User UserRound UserRoundCheck',
  workflow: 'workflowSettings changes studentChanges distributed declarations projects organizationScope Workflow Network ListTree',
  planner: 'teachingPlanCenter plans lessonPlans implementationSheets CalendarDays',
  messages: 'message messageChannels notifications inbox MessageCircle Bell',
  website: 'ssoClients Globe Globe2 ExternalLink',
  calendar: 'arrangements schedules practicePeriods graduationCohortManage delays patchSigns Calendar CalendarCheck CalendarRange',
  history: 'log auditCenter journal journals reflections FileClock Clock Clock3 History',
  books: 'doc templateLib syllabus syllabuses guides operationGuides Book BookOpen',
  archive: 'archives documents archiveMaterials Archive',
  statistics: 'stat overview workbench statistics metrics ChartColumn LayoutDashboard BarChart3',
  people: 'userManage classManage participants teachers teacher pairs Users UsersRound',
  education: 'score scores courseScores gradeRules gradeManage professionManage profession GraduationCap',
  shield: 'iamConfig accounts cliAuthorization roleMenus safety safetyLetters insurances Shield ShieldCheck LockKeyhole',
  approval: 'apply application applications requests review implementations baseApplications baseUsage approvals ClipboardList CheckCircle2',
  location: 'baseVisit baseVisits baseVisitRecords inspections attendance sign signIns MapPin Search',
  sync: 'educationPlanSync educationSync RefreshCw RefreshCcw',
  expense: 'expenseManage expenses Wallet Receipt',
  spreadsheet: 'eduData practice_score_sheet Table Table2 List Upload',
  download: 'exportTask downloads Download',
  report: 'submit reports materials teacherWorkReports FileText Files',
  apps: 'menuManage internshipCategoryManage LayoutGrid Tags',
}).flatMap(([artwork, keys]) => [artwork, ...keys.split(' ')].map(key => [key, artwork])));

export function menuArtwork(key, iconName) {
  const artwork = artworkByKey.get(key) || artworkByKey.get(iconName)
    || (String(key).startsWith('favorite-link-') ? 'website' : 'apps');
  return images[`./assets/menu-icons/${artwork}.webp`];
}
