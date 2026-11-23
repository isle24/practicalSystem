import {
  Archive, BookOpen, BriefcaseBusiness, Building2, CalendarCheck, ChartColumn, ClipboardList,
  Download, FileClock, FileText, FlaskConical, FolderOpen, Globe2, GraduationCap, HardDrive,
  LayoutGrid, MapPin, MessageCircle, RefreshCw, Settings, ShieldCheck, Star, Table2, UserRound,
  UsersRound, Wallet, Workflow,
} from '@lucide/vue';

const images = import.meta.glob('./assets/menu-icons/**/*.webp', {
  eager: true,
  import: 'default',
  query: '?url&no-inline',
});

const artworkByKey = new Map();
for (const [name, emoji, glyph, color, tint, aliases] of [
  ['internship', '💼', BriefcaseBusiness, 'blue', '201 139 90', 'BriefcaseBusiness'],
  ['laboratory', '🧪', FlaskConical, 'teal', '77 178 177', 'practice training lab FlaskConical'],
  ['fieldwork', '🗺️', Workflow, 'green', '150 166 112', 'socialPractice centralized Route'],
  ['database', '🗄️', HardDrive, 'red', '170 128 89', 'dataCenter dataManage databaseSchema storage manage HardDrive Database'],
  ['resources', '📂', FolderOpen, 'blue', '116 166 186', 'resourceCenter file fileManage Folder FolderOpen'],
  ['plugins', '🧩', Globe2, 'teal', '113 170 160', 'pluginCenter wechatProxy Puzzle'],
  ['favorites', '⭐', Star, 'blue', '195 113 110', 'favorite Star Bookmark'],
  ['notebook', '📓', FileText, 'amber', '117 136 174', 'notes Notebook'],
  ['bulletin', '📢', FileText, 'green', '201 164 94', 'releaseNotes releases Newspaper Megaphone'],
  ['campus', '🏫', Building2, 'blue', '190 142 113', 'home companyManage departmentManage department rooms schoolAppearance baseFlows enterpriseEvaluations Building Building2 School'],
  ['settings', '⚙️', Settings, 'gray', '153 151 164', 'config appearance preferences Settings SlidersHorizontal'],
  ['profile', '👤', UserRound, 'teal', '135 177 183', 'mine general signature student User UserRound UserRoundCheck'],
  ['workflow', '🔀', ClipboardList, 'blue', '119 156 162', 'workflowSettings changes studentChanges distributed declarations projects organizationScope Workflow Network ListTree'],
  ['planner', '📝', ClipboardList, 'blue', '122 151 174', 'teachingPlanCenter plans lessonPlans implementationSheets CalendarDays'],
  ['messages', '✉️', MessageCircle, 'teal', '157 163 184', 'message messageChannels notifications inbox MessageCircle Bell'],
  ['website', '🌐', Globe2, 'blue', '114 165 190', 'ssoClients Globe Globe2 ExternalLink'],
  ['calendar', '📅', CalendarCheck, 'blue', '202 140 116', 'arrangements schedules practicePeriods graduationCohortManage delays patchSigns Calendar CalendarCheck CalendarRange'],
  ['history', '🕘', FileClock, 'amber', '103 160 160', 'log auditCenter journal journals reflections FileClock Clock Clock3 History'],
  ['books', '📚', BookOpen, 'green', '135 151 172', 'doc templateLib syllabus syllabuses guides operationGuides Book BookOpen'],
  ['archive', '🗃️', Archive, 'amber', '159 167 125', 'archives documents archiveMaterials Archive'],
  ['statistics', '📊', ChartColumn, 'amber', '115 168 173', 'stat overview workbench statistics metrics ChartColumn LayoutDashboard BarChart3'],
  ['people', '👥', UsersRound, 'blue', '133 152 176', 'userManage classManage participants teachers teacher pairs Users UsersRound'],
  ['education', '🎓', GraduationCap, 'amber', '135 143 169', 'score scores courseScores gradeRules gradeManage professionManage profession GraduationCap'],
  ['shield', '🛡️', ShieldCheck, 'blue', '94 154 151', 'iamConfig accounts cliAuthorization roleMenus safety safetyLetters insurances Shield ShieldCheck LockKeyhole'],
  ['approval', '📋', ClipboardList, 'blue', '181 131 107', 'apply application applications requests review implementations baseApplications baseUsage approvals ClipboardList CheckCircle2'],
  ['location', '📍', MapPin, 'red', '195 144 106', 'baseVisit baseVisits baseVisitRecords inspections attendance sign signIns MapPin Search'],
  ['sync', '🔄', RefreshCw, 'teal', '105 170 181', 'educationPlanSync educationSync RefreshCw RefreshCcw'],
  ['expense', '💰', Wallet, 'amber', '195 166 115', 'expenseManage expenses Wallet Receipt'],
  ['spreadsheet', '📑', Table2, 'green', '145 165 123', 'eduData practice_score_sheet Table Table2 List Upload'],
  ['download', '📥', Download, 'blue', '117 157 192', 'exportTask downloads Download'],
  ['report', '📄', FileText, 'teal', '146 160 178', 'submit reports materials teacherWorkReports FileText Files'],
  ['apps', '🗂️', LayoutGrid, 'blue', '144 161 167', 'menuManage internshipCategoryManage LayoutGrid Tags'],
]) {
  const artwork = {
    emoji, glyph, color, tint,
    glass: images[`./assets/menu-icons/${name}.webp`],
    dimensional: images[`./assets/menu-icons/dimensional/${name}.webp`],
    handdrawn: images[`./assets/menu-icons/handdrawn/${name}.webp`],
  };
  for (const key of [name, ...aliases.split(' ')]) artworkByKey.set(key, artwork);
}

export function menuArtwork(key, iconName) {
  return artworkByKey.get(key) || artworkByKey.get(iconName)
    || artworkByKey.get(String(key).startsWith('favorite-link-') ? 'website' : 'apps');
}
