const images = import.meta.glob('./assets/menu-icons/*.webp', {
  eager: true,
  import: 'default',
  query: '?url&no-inline',
});

const artworkByKey = new Map();
for (const [name, tint, aliases] of [
  ['internship', '201 139 90', 'BriefcaseBusiness'],
  ['laboratory', '77 178 177', 'practice training lab FlaskConical'],
  ['fieldwork', '150 166 112', 'socialPractice centralized Route'],
  ['database', '170 128 89', 'dataCenter dataManage databaseSchema storage manage HardDrive Database'],
  ['resources', '116 166 186', 'resourceCenter file fileManage Folder FolderOpen'],
  ['plugins', '113 170 160', 'pluginCenter wechatProxy Puzzle'],
  ['favorites', '195 113 110', 'favorite Star Bookmark'],
  ['notebook', '117 136 174', 'notes Notebook'],
  ['bulletin', '201 164 94', 'releaseNotes releases Newspaper Megaphone'],
  ['campus', '190 142 113', 'home companyManage departmentManage department rooms schoolAppearance baseFlows enterpriseEvaluations Building Building2 School'],
  ['settings', '153 151 164', 'config appearance preferences Settings SlidersHorizontal'],
  ['profile', '135 177 183', 'mine general signature student User UserRound UserRoundCheck'],
  ['workflow', '119 156 162', 'workflowSettings changes studentChanges distributed declarations projects organizationScope Workflow Network ListTree'],
  ['planner', '122 151 174', 'teachingPlanCenter plans lessonPlans implementationSheets CalendarDays'],
  ['messages', '157 163 184', 'message messageChannels notifications inbox MessageCircle Bell'],
  ['website', '114 165 190', 'ssoClients Globe Globe2 ExternalLink'],
  ['calendar', '202 140 116', 'arrangements schedules practicePeriods graduationCohortManage delays patchSigns Calendar CalendarCheck CalendarRange'],
  ['history', '103 160 160', 'log auditCenter journal journals reflections FileClock Clock Clock3 History'],
  ['books', '135 151 172', 'doc templateLib syllabus syllabuses guides operationGuides Book BookOpen'],
  ['archive', '159 167 125', 'archives documents archiveMaterials Archive'],
  ['statistics', '115 168 173', 'stat overview workbench statistics metrics ChartColumn LayoutDashboard BarChart3'],
  ['people', '133 152 176', 'userManage classManage participants teachers teacher pairs Users UsersRound'],
  ['education', '135 143 169', 'score scores courseScores gradeRules gradeManage professionManage profession GraduationCap'],
  ['shield', '94 154 151', 'iamConfig accounts cliAuthorization roleMenus safety safetyLetters insurances Shield ShieldCheck LockKeyhole'],
  ['approval', '181 131 107', 'apply application applications requests review implementations baseApplications baseUsage approvals ClipboardList CheckCircle2'],
  ['location', '195 144 106', 'baseVisit baseVisits baseVisitRecords inspections attendance sign signIns MapPin Search'],
  ['sync', '105 170 181', 'educationPlanSync educationSync RefreshCw RefreshCcw'],
  ['expense', '195 166 115', 'expenseManage expenses Wallet Receipt'],
  ['spreadsheet', '145 165 123', 'eduData practice_score_sheet Table Table2 List Upload'],
  ['download', '117 157 192', 'exportTask downloads Download'],
  ['report', '146 160 178', 'submit reports materials teacherWorkReports FileText Files'],
  ['apps', '144 161 167', 'menuManage internshipCategoryManage LayoutGrid Tags'],
]) {
  const artwork = { url: images[`./assets/menu-icons/${name}.webp`], tint };
  for (const key of [name, ...aliases.split(' ')]) artworkByKey.set(key, artwork);
}

export function menuArtwork(key, iconName) {
  return artworkByKey.get(key) || artworkByKey.get(iconName)
    || artworkByKey.get(String(key).startsWith('favorite-link-') ? 'website' : 'apps');
}
