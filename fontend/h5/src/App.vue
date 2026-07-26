<template>
  <MobileAppShell
    v-model:active-tab="activeTab"
    v-model:refreshing="mobileRefreshing"
    :logged-in="isLoggedIn"
    :title="mobileHeaderTitle"
    :school-name="schoolText"
    :show-back="showMobileHeaderBack"
    :unread-count="messageUnreadCount"
    :error="state.error"
    :page-key="navigationPageKey"
    :transition-direction="navigationTransitionDirection"
    :internship-visible="isMobileModuleVisible('internship')"
    :training-visible="isMobileModuleVisible('training')"
    :lab-visible="isMobileModuleVisible('lab')"
    @back="goMobileBack"
    @message="openMobileMessages"
    @refresh="handleMobilePullRefresh"
  >
    <template #default>
    <section class="page-content">
      <LoginPage
        v-if="!isLoggedIn"
        :school-name="schoolText"
        :login-form="loginForm"
        :login-state="loginState"
        :register-form="registerForm"
        :register-state="registerState"
        :register-role-options="registerRoleOptions"
        :role-labels="roleNameMap"
        @login="submitLogin"
        @register="submitRegister"
        @toggle-register="toggleRegisterForm"
      />

      <HomePage
        v-else-if="activeTab === 'home'"
        :school-name="schoolText"
        :user-name="userText"
        :role-name="roleDisplayText"
        :scope-text="scopeText"
        :section-title="homeFocusTitle"
        :section-description="homeFocusDescription"
        :items="homeFocusItems"
        :support-modules="supportHomeModules"
        :student="isStudentRole"
        :flow-steps="studentFlowSteps"
        @open-focus="openHomeFocus"
        @open-tab="activeTab = $event"
      />

      <ProfilePage
        v-else-if="activeTab === 'mine'"
        :user-name="userText"
        :account-name="accountText"
        :role-name="roleDisplayText"
        :school-name="schoolText"
        :scope-text="scopeText"
        :scope-detail="scopeDetailText"
        :document-visible="hasPermission('doc:view')"
        :template-visible="hasPermission('template:view')"
        :switch-state="switchAccountState"
        :role-labels="roleNameMap"
        @open-tab="activeTab = $event"
        @switch-account="switchMobileAccount"
        @logout="confirmMobileLogout"
      />

      <MessagePage
        v-else-if="activeTab === 'message'"
        :state="messageState"
        :groups="messageGroups"
        :unread-count="messageUnreadCount"
        :type-options="mobileMessageTypeOptions"
        :is-own="isOwnMobileMessage"
        :level-text="messageLevelText"
        :time-text="messageTimeText"
        :type-text="messageTypeText"
        :type-unread="mobileMessageTypeUnread"
        @filter="setMobileMessageFilter"
        @type="setMobileMessageType"
        @open="handleMobileMessageClick"
        @linked="openMobileMessageLink"
        @read-all="markAllMobileMessagesRead"
        @load-more="loadMoreMessages"
        @reload="loadMessages(1)"
      />

      <DocumentPage
        v-else-if="activeTab === 'doc'"
        :model="support.doc"
        :can-load-more="canLoadMoreSupport('doc')"
        @load="loadMobileDocs"
        @open="openMobileDoc"
      />

      <TemplatePage
        v-else-if="activeTab === 'templateLib'"
        :model="support.template"
        :can-load-more="canLoadMoreSupport('template')"
        @load="loadMobileTemplates"
        @download="downloadMobileTemplate"
      />

      <InternshipPage v-else-if="activeTab === 'internship'" />

      <PracticePage v-else-if="isPracticeTab(activeTab)" :module-type="activeTab" />

      <template v-else>
        <section class="mobile-empty-card">
          <strong>暂无可用内容</strong>
          <span>请从底部导航进入可用模块。</span>
        </section>
      </template>
    </section>
    </template>

    <template #overlays>
    <DocumentDetail
      :visible="support.doc.detail.visible"
      :detail="support.doc.detail"
      @close="closeMobileDoc"
    />

        </template>
  </MobileAppShell>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { showToast } from 'vant';
import {
  BriefcaseBusiness,
  BookOpen,
  CalendarCheck,
  CheckCircle2,
  ClipboardList,
  FileClock,
  FileText,
  FlaskConical,
  GraduationCap,
  Home,
  MapPin,
  MessageCircle,
  Search,
  Send,
  UsersRound,
  UserRound,
  Workflow,
} from '@lucide/vue';
import DocumentDetail from './features/support/DocumentDetail.vue';
import InternshipPage from './features/internship/InternshipPage.vue';
import { provideInternshipContext } from './features/internship/internshipContext';
import PracticePage from './features/practice/PracticePage.vue';
import { providePracticeContext } from './features/practice/practiceContext';
import MobileAppShell from './layouts/MobileAppShell.vue';
import DocumentPage from './pages/DocumentPage.vue';
import HomePage from './pages/HomePage.vue';
import LoginPage from './pages/LoginPage.vue';
import MessagePage from './pages/MessagePage.vue';
import ProfilePage from './pages/ProfilePage.vue';
import TemplatePage from './pages/TemplatePage.vue';
import { useAuthSession } from './composables/useAuthSession';
import { useInternship } from './composables/useInternship';
import { useMessageCenter } from './composables/useMessageCenter';
import { useMobileNavigation } from './composables/useMobileNavigation';
import { useMobilePermissions } from './composables/useMobilePermissions';
import { usePracticeModule } from './composables/usePracticeModule';
import { useSupportCenter } from './composables/useSupportCenter';
import { statusText as resolveStatusText } from './constants/status';
import { backendUrl } from './api/client';
import { formatDateKey } from './utils/date';
import {
  fetchInternshipArchiveMaterials,
  fetchInternshipApplications,
  fetchInternshipArrangementChanges,
  fetchInternshipArrangements,
  fetchInternshipDelays,
  fetchInternshipInsurances,
  fetchInternshipJournals,
  fetchInternshipOptions,
  fetchInternshipOverview,
  fetchInternshipPairs,
  fetchInternshipPlans,
  fetchInternshipReports,
  fetchInternshipReviewDraft,
  fetchInternshipSafetyLetters,
  fetchInternshipCourseScores,
  fetchInternshipScores,
  fetchInternshipSignIns,
  fetchInternshipSyllabusGuides,
  fetchInternshipImplementationSheets,
  fetchInternshipTeacherWorkReports,
  fetchInternshipInspections,
  fetchInternshipTimeline,
  fetchTemplateList,
  fetchPracticeList,
  fetchPracticeOptions,
  fetchPracticeOverview,
  fetchPracticeExecutionList,
  fetchPracticeExecutionTimeline,
  fetchPracticeReviewDraft,
  fetchPracticeTimeline,
  requestInternshipModification,
  requestPracticeExecutionModification,
  requestPracticeModification,
  reviewInternshipApplication,
  reviewInternshipArrangementChange,
  reviewInternshipDelay,
  reviewInternshipDocument,
  reviewInternshipJournal,
  reviewInternshipPlan,
  reviewInternshipReport,
  reviewPracticeExecution,
  reviewPracticeItem,
  saveInternshipApplication,
  saveInternshipDelay,
  saveInternshipJournal,
  saveInternshipReport,
  saveInternshipReviewDraft,
  saveInternshipScore,
  saveInternshipSignIn,
  saveInternshipSafetyLetter,
  savePracticeExecution,
  savePracticeProjectScore,
  savePracticeReviewDraft,
  uploadFile,
} from './api/system';

const { state, hasPermission, load } = useMobilePermissions();
const mobileRefreshing = ref(false);

const {
  support,
  canLoadMore: canLoadMoreSupport,
  closeDoc: closeMobileDoc,
  downloadTemplate: downloadMobileTemplate,
  loadCategories: loadMobileSupportCategories,
  loadDocs: loadMobileDocs,
  loadTemplates: loadMobileTemplates,
  openDoc: openMobileDoc,
  reset: resetSupportState,
} = useSupportCenter({
  isLoggedIn: () => Boolean(state.context.account_id),
  hasPermission,
});

const {
  internship,
  reviewStatusLabels,
  emptyFilters: emptyInternshipFilters,
  emptyOptions: emptyInternshipOptions,
  emptyOverview: emptyInternshipOverview,
  reset: resetInternshipState,
} = useInternship();

const {
  practice,
  reviewDialog: practiceReviewDialog,
  executionDialog: practiceExecutionDialog,
  timelineDialog: practiceTimelineDialog,
  emptyFilters: emptyPracticeFilters,
  reset: resetPracticeState,
} = usePracticeModule();
const {
  activeTab,
  canGoBack: canGoMobileBack,
  pageKey: navigationPageKey,
  transitionDirection: navigationTransitionDirection,
  goBack: goMobileBack,
  resetToHome: resetMobileNavigationToHome,
} = useMobileNavigation({
  captureExtras: () => ({
    internshipPanel: internship.panel,
    internshipSubmitSection: internship.submitSection,
    internshipReviewList: internship.reviewList,
    internshipManageList: internship.manageList,
    trainingPanel: practice.training.panel,
    labPanel: practice.lab.panel,
  }),
  restoreExtras: async (snapshot) => {
    internship.panel = snapshot.internshipPanel || 'workbench';
    internship.submitSection = snapshot.internshipSubmitSection || '';
    internship.reviewList = snapshot.internshipReviewList || 'applications';
    internship.manageList = snapshot.internshipManageList || 'arrangements';
    practice.training.panel = snapshot.trainingPanel || practice.training.panel;
    practice.lab.panel = snapshot.labPanel || practice.lab.panel;
  },
});

const {
  state: messageState,
  groups: messageGroups,
  typeOptions: mobileMessageTypeOptions,
  unreadCount: messageUnreadCount,
  isOwn: isOwnMobileMessage,
  levelText: messageLevelText,
  load: loadMessages,
  loadMore: loadMoreMessages,
  loadSummary: loadMessageSummary,
  markAllRead: markAllMobileMessagesRead,
  markRead: handleMobileMessageClick,
  openLinked: openMobileMessageLink,
  reset: resetMessageState,
  setFilter: setMobileMessageFilter,
  setType: setMobileMessageType,
  timeText: messageTimeText,
  typeText: messageTypeText,
  typeUnread: mobileMessageTypeUnread,
} = useMessageCenter({
  isLoggedIn: () => Boolean(state.context.account_id),
  currentAccountId: () => state.context.account_id,
  navigate: tab => { activeTab.value = tab; },
  canNavigate: isMobileModuleVisible,
});

const {
  loginForm,
  loginState,
  registerForm,
  registerRoleOptions,
  registerState,
  switchAccountState,
  confirmMobileLogout,
  loadSwitchableAccounts,
  resetAccountChoices,
  submitLogin,
  submitRegister,
  switchMobileAccount,
  toggleRegisterForm,
} = useAuthSession({
  isLoggedIn: () => Boolean(state.context.account_id),
  roleLabel: type => roleNameMap[type] || '',
  onAuthenticated: refreshMobileSession,
  onLoggedOut: async () => {
    await resetMobileNavigationToHome();
    resetMobileLocalState();
    await load();
  },
  onExpired: expireMobileSession,
  onInitialLoad: refreshMobileSession,
});

const modules = [
  {
    key: 'internship',
    title: '实习管理',
    desc: '任务、签到、日志和任务老师入口',
    icon: BriefcaseBusiness,
    theme: 'blue',
    permission: 'internship:view',
    flow: '按实习文档开发',
  },
  {
    key: 'training',
    title: '实训管理',
    desc: '实训模块入口',
    icon: Workflow,
    theme: 'teal',
    permission: 'training:view',
    flow: '-',
  },
  {
    key: 'lab',
    title: '实验管理',
    desc: '实验模块入口',
    icon: FlaskConical,
    theme: 'green',
    permission: 'lab:view',
    flow: '-',
  },
  {
    key: 'doc',
    title: '文档中心',
    desc: '制度流程和常见问题',
    icon: BookOpen,
    theme: 'green',
    permission: 'doc:view',
    flow: '-',
  },
  {
    key: 'templateLib',
    title: '模板库',
    desc: '材料模板查看和下载',
    icon: FileText,
    theme: 'teal',
    permission: 'template:view',
    flow: '-',
  },
];

const currentPage = computed(() => {
  if (activeTab.value === 'home') {
    return { title: '首页', desc: '移动端工作台', theme: 'blue', icon: Home, permission: '', flow: '-' };
  }
  if (activeTab.value === 'mine') {
    return { title: '我的', desc: '个人信息', theme: 'gray', icon: UserRound, permission: '', flow: '-' };
  }
  if (activeTab.value === 'message') {
    return { title: '消息中心', desc: '待办、审核结果和系统通知', theme: 'teal', icon: MessageCircle, permission: '', flow: '-' };
  }
  return modules.find(item => item.key === activeTab.value) || modules[0];
});

const isLoggedIn = computed(() => Boolean(state.context.account_id));
const mobileHeaderTitle = computed(() => (isLoggedIn.value ? currentPage.value.title : '实践管理系统'));
const showMobileHeaderBack = computed(() => (
  isLoggedIn.value
  && ['message', 'doc', 'templateLib'].includes(activeTab.value)
  && canGoMobileBack.value
));
const roleType = computed(() => state.context.role_type || '');
const isStudentRole = computed(() => roleType.value === 'student');
const isTeacherRole = computed(() => roleType.value === 'teacher');
const isAdminRole = computed(() => ['super_admin', 'school_admin', 'college_admin', 'profession_admin'].includes(roleType.value));
const visibleMobileModules = computed(() => modules.filter(canShowMobileModule));
const supportHomeModules = computed(() => visibleMobileModules.value.filter(module => ['doc', 'templateLib'].includes(module.key)));
const canReviewInternship = computed(() => hasPermission('internship:approve'));
const canReviewInternshipPlan = computed(() => hasPermission('internship:plan') && isAdminRole.value);
const signGpsReady = computed(() => hasCoordinateValue(internship.forms.sign.longitude) && hasCoordinateValue(internship.forms.sign.latitude));
const signGpsTitle = computed(() => (signGpsReady.value ? '已获取 GPS 定位' : '等待 GPS 定位'));
const signGpsHint = computed(() => (signGpsReady.value ? '坐标来自当前设备定位' : '签到前请先授权并获取当前位置'));
const signMapUrl = computed(() => coordinateMapUrl(internship.forms.sign.longitude, internship.forms.sign.latitude, signGpsReady.value));
const practiceGpsReady = computed(() => hasCoordinateValue(practiceExecutionDialog.form.longitude) && hasCoordinateValue(practiceExecutionDialog.form.latitude));
const practiceGpsTitle = computed(() => (practiceGpsReady.value ? '已获取 GPS 定位' : '等待 GPS 定位'));
const practiceGpsHint = computed(() => (practiceGpsReady.value ? '坐标来自当前设备定位' : '签到前请先授权并获取当前位置'));
const practiceMapUrl = computed(() => coordinateMapUrl(practiceExecutionDialog.form.longitude, practiceExecutionDialog.form.latitude, practiceGpsReady.value));
const signAccuracyText = computed(() => accuracyDisplayText(internship.forms.sign.accuracy));
const currentReportIsGraduation = computed(() => currentArrangement()?.type === 'graduation');
const safetyTemplate = computed(() => internship.options.archive_templates.find(item => item.material_type === 'safety_commitment') || null);
const practiceAccuracyText = computed(() => accuracyDisplayText(practiceExecutionDialog.form.accuracy));

function coordinateMapUrl(longitudeValue, latitudeValue, ready) {
  if (!ready) {
    return '';
  }
  const longitude = Number(longitudeValue).toFixed(6);
  const latitude = Number(latitudeValue).toFixed(6);
  return `https://staticmap.openstreetmap.de/staticmap.php?center=${latitude},${longitude}&zoom=16&size=640x300&markers=${latitude},${longitude},red-pushpin`;
}

function accuracyDisplayText(value) {
  const accuracy = Number(value || 0);
  return accuracy > 0 ? `${Math.round(accuracy)} 米` : '-';
}
const roleNameMap = {
  super_admin: '系统管理员',
  school_admin: '学校管理员',
  college_admin: '学院管理员',
  profession_admin: '专业管理员',
  teacher: '任务老师',
  student: '学生',
  enterprise: '企业导师',
};

function canShowMobileModule(module) {
  if (!hasPermission(module.permission)) {
    return false;
  }
  if (['training', 'lab'].includes(module.key)) {
    return ['student', 'teacher', 'super_admin', 'school_admin', 'college_admin', 'profession_admin'].includes(roleType.value);
  }
  if (module.key === 'internship') {
    return ['student', 'teacher', 'super_admin', 'school_admin', 'college_admin', 'profession_admin'].includes(roleType.value);
  }
  return true;
}

function isMobileModuleVisible(key) {
  return visibleMobileModules.value.some(module => module.key === key);
}
const scopeNameMap = {
  dep_id: '学院',
  profession_id: '专业',
  company_id: '企业',
  teacher_user_id: '本人任务学生',
  student_user_id: '本人实习数据',
};
const roleDisplayText = computed(() => state.context.role_name || roleNameMap[roleType.value] || state.context.role_id || '未登录');
const userText = computed(() => state.context.user_name || state.context.name || state.context.login_name || '未登录');
const accountText = computed(() => state.context.login_name || '-');
const schoolText = computed(() => state.context.school_name || '成都锦城学院');
const scopeText = computed(() => {
  if (!isLoggedIn.value) {
    return '未登录';
  }
  const filter = scopeFilter.value;
  if (!filter) {
    return '全校';
  }
  if (filter.deny_all) {
    return '无权限';
  }
  const names = activeScopeEntries.value.map(([key]) => scopeNameMap[key] || key);
  return names.length ? names.join(' / ') : '全校';
});
const scopeDetailText = computed(() => {
  const entries = activeScopeEntries.value;
  if (!isLoggedIn.value) {
    return '登录后显示当前账号可查看的数据范围';
  }
  if (!entries.length) {
    return '可查看全校数据';
  }
  return entries.map(([key, value]) => {
    const name = scopeNameMap[key] || key;
    return Array.isArray(value) ? `${name} ${value.length} 项` : name;
  }).join('，');
});
const scopeFilter = computed(() => state.dataScope?.filter || state.context.data_scope?.filter || null);
const activeScopeEntries = computed(() => {
  const filter = scopeFilter.value;
  if (!filter || filter.deny_all) {
    return [];
  }
  return Object.entries(filter).filter(([, value]) => {
    if (Array.isArray(value)) {
      return value.length > 0;
    }
    return value !== null && value !== undefined && value !== '' && value !== false;
  });
});
const internshipPanels = computed(() => {
  if (isStudentRole.value) {
    return [
      { key: 'workbench', name: '概况', icon: Home },
      { key: 'apply', name: '任务', icon: ClipboardList },
      { key: 'submit', name: '提交', icon: Send },
      { key: 'score', name: '成绩', icon: GraduationCap },
    ];
  }
  if (isTeacherRole.value) {
    return [
      { key: 'workbench', name: '概况', icon: Home },
      { key: 'review', name: '审核', icon: CheckCircle2 },
      { key: 'score', name: '成绩', icon: GraduationCap },
    ];
  }
  if (isAdminRole.value) {
    return [
      { key: 'workbench', name: '概况', icon: Home },
      { key: 'review', name: '审核', icon: CheckCircle2 },
      { key: 'manage', name: '数据', icon: CalendarCheck },
    ];
  }
  return [{ key: 'workbench', name: '概况', icon: Home }];
});
const reviewStatusOptions = [
  { value: 'wait', label: '待审核' },
  { value: 'accept', label: '已通过' },
  { value: 'modify', label: '需修改' },
];
const practiceEnabledStatusOptions = [
  { value: 'enabled', label: '启用' },
  { value: 'completed', label: '已完成' },
  { value: 'disabled', label: '停用' },
];
const delayStatusOptions = [
  { value: 'wait', label: '待审核' },
  { value: 'accept', label: '已通过' },
  { value: 'refuse', label: '已退回' },
];
const mobileListConfigs = computed(() => ({
  arrangements: {
    key: 'arrangements',
    entity: 'arrangement',
    title: '实习任务',
    shortTitle: '任务',
    icon: CalendarCheck,
    keywordPlaceholder: '任务、课程、届次、学院、专业',
    gradeFilter: true,
    statusOptions: [
      { value: 'enabled', label: '启用' },
      { value: 'completed', label: '已完成' },
      { value: 'changing', label: '变更中' },
      { value: 'changed', label: '已变更' },
      { value: 'disabled', label: '停用' },
    ],
    emptyText: '暂无实习任务',
  },
  arrangementChanges: {
    key: 'arrangementChanges',
    entity: 'arrangement_change',
    title: '任务变更',
    shortTitle: '变更',
    icon: Workflow,
    keywordPlaceholder: '任务、课程、教师、原因、提交人',
    gradeFilter: true,
    statusOptions: [
      { value: 'wait', label: '待审核' },
      { value: 'accept', label: '已通过' },
      { value: 'modify', label: '需修改' },
      { value: 'refuse', label: '已退回' },
    ],
    emptyText: '暂无任务变更',
  },
  plans: {
    key: 'plans',
    entity: 'plan',
    title: '实习计划',
    shortTitle: '计划',
    icon: FileText,
    keywordPlaceholder: '学院、提交人',
    statusOptions: reviewStatusOptions,
    emptyText: '暂无实习计划',
  },
  syllabusGuides: {
    key: 'syllabusGuides',
    entity: 'syllabus_guide',
    title: '大纲指导书',
    shortTitle: '大纲',
    icon: BookOpen,
    keywordPlaceholder: '标题、安排、学院、专业',
    gradeFilter: true,
    statusOptions: [
      { value: 'draft', label: '草稿' },
      { value: 'published', label: '已发布' },
    ],
    emptyText: '暂无大纲指导书',
  },
  implementationSheets: {
    key: 'implementationSheets',
    entity: 'implementation_sheet',
    title: '实施表',
    shortTitle: '实施',
    icon: ClipboardList,
    keywordPlaceholder: '安排、学院、专业、教师',
    gradeFilter: true,
    statusOptions: [
      { value: 'draft', label: '草稿' },
      { value: 'confirmed', label: '已确认' },
    ],
    emptyText: '暂无实施表',
  },
  applications: {
    key: 'applications',
    entity: 'application',
    title: '特殊申请',
    shortTitle: '特申',
    icon: ClipboardList,
    keywordPlaceholder: '学生、学号、实习任务、教师',
    gradeFilter: true,
    statusOptions: reviewStatusOptions,
    emptyText: '暂无特殊申请',
  },
  pairs: {
    key: 'pairs',
    entity: '',
    title: '任务绑定',
    shortTitle: '绑定',
    icon: UsersRound,
    keywordPlaceholder: '学生、学号、教师、安排',
    gradeFilter: true,
    statusOptions: [
      { value: 'active', label: '有效' },
      { value: 'removed', label: '已移除' },
    ],
    emptyText: '暂无任务绑定',
  },
  signIns: {
    key: 'signIns',
    entity: 'sign_in',
    title: '签到记录',
    shortTitle: '签到',
    icon: MapPin,
    keywordPlaceholder: '学生、学号、安排、地点',
    gradeFilter: true,
    statusOptions: [],
    emptyText: '暂无签到记录',
  },
  journals: {
    key: 'journals',
    entity: 'journal',
    title: '日志评阅',
    shortTitle: '日志',
    icon: FileClock,
    keywordPlaceholder: '学生、标题、内容、教师',
    gradeFilter: true,
    statusOptions: reviewStatusOptions,
    emptyText: '暂无日志记录',
  },
  reports: {
    key: 'reports',
    entity: 'report',
    title: '报告评阅',
    shortTitle: '报告',
    icon: FileText,
    keywordPlaceholder: '学生、标题、内容、教师',
    gradeFilter: true,
    statusOptions: reviewStatusOptions,
    emptyText: '暂无报告记录',
  },
  teacherWorkReports: {
    key: 'teacherWorkReports',
    entity: 'teacher_work_report',
    title: '教师工作报告',
    shortTitle: '工作报告',
    icon: FileText,
    keywordPlaceholder: '安排、教师、总结、问题',
    gradeFilter: true,
    statusOptions: reviewStatusOptions,
    emptyText: '暂无教师工作报告',
  },
  delays: {
    key: 'delays',
    entity: 'delay',
    title: '延期申请',
    shortTitle: '延期',
    icon: FileClock,
    keywordPlaceholder: '学生、学号、安排、原因',
    gradeFilter: true,
    statusOptions: delayStatusOptions,
    emptyText: '暂无延期申请',
  },
  scores: {
    key: 'scores',
    entity: 'score',
    title: '任务成绩',
    shortTitle: '任务成绩',
    icon: GraduationCap,
    keywordPlaceholder: '学生、学号、安排、教师',
    gradeFilter: true,
    statusOptions: [],
    emptyText: '暂无成绩记录',
  },
  courseScores: {
    key: 'courseScores',
    entity: '',
    title: '课程成绩汇总',
    shortTitle: '课程成绩',
    icon: GraduationCap,
    keywordPlaceholder: '学生、学号、课程、任务',
    gradeFilter: true,
    statusOptions: [],
    emptyText: '暂无课程成绩汇总',
  },
  inspections: {
    key: 'inspections',
    entity: 'inspection',
    title: '巡查记录',
    shortTitle: '巡查',
    icon: Search,
    keywordPlaceholder: '安排、学生、巡查人、说明',
    gradeFilter: true,
    statusOptions: [
      { value: 'pass', label: '通过' },
      { value: 'fail', label: '不通过' },
    ],
    statusKey: 'result',
    emptyText: '暂无巡查记录',
  },
  archiveMaterials: {
    key: 'archiveMaterials',
    entity: '',
    title: '归档材料',
    shortTitle: '归档',
    icon: FileText,
    keywordPlaceholder: '课程、任务、材料',
    gradeFilter: true,
    statusOptions: [
      { value: 'complete', label: '完整' },
      { value: 'incomplete', label: '待补齐' },
    ],
    emptyText: '暂无归档材料',
  },
  insurances: {
    key: 'insurances',
    entity: 'insurance',
    title: '保险记录',
    shortTitle: '保险',
    icon: FileText,
    keywordPlaceholder: '学生、学号、安排、保单',
    gradeFilter: true,
    statusOptions: [],
    emptyText: '暂无保险记录',
  },
  safetyLetters: {
    key: 'safetyLetters',
    entity: 'safety_letter',
    title: '安全承诺',
    shortTitle: '承诺',
    icon: CheckCircle2,
    keywordPlaceholder: '学生、学号、安排',
    gradeFilter: true,
    statusOptions: [
      { value: 'pending', label: '待签署' },
      { value: 'signed', label: '已签署' },
    ],
    emptyText: '暂无安全承诺',
  },
}));
const reviewListTabs = computed(() => {
  const keys = isTeacherRole.value
    ? ['journals', 'reports', 'delays', 'applications']
    : ['arrangementChanges', 'plans', 'delays', 'applications'];
  if (!canReviewInternshipPlan.value) {
    const planIndex = keys.indexOf('plans');
    if (planIndex >= 0) {
      keys.splice(planIndex, 1);
    }
  }
  return keys.map(getMobileListConfig).filter(Boolean);
});
const manageListTabs = computed(() => [
  'plans',
  'arrangements',
  'pairs',
  'arrangementChanges',
  'syllabusGuides',
  'implementationSheets',
  'signIns',
  'journals',
  'reports',
  'teacherWorkReports',
  'delays',
  'scores',
  'courseScores',
  'inspections',
  'archiveMaterials',
].map(getMobileListConfig).filter(Boolean));
const currentReviewListConfig = computed(() => getMobileListConfig(internship.reviewList) || reviewListTabs.value[0] || null);
const currentManageListConfig = computed(() => getMobileListConfig(internship.manageList) || manageListTabs.value[0] || null);
const internshipSummaries = computed(() => [
  { name: '任务', value: internship.overview.arrangements || 0 },
  { name: '待审', value: internship.overview.applications_waiting || 0 },
  { name: '绑定', value: internship.overview.active_pairs || 0 },
]);
const studentFlowSteps = [
  '查看管理员分配的实习任务',
  '在任务中确认负责老师和阶段时间',
  '签到、日志、报告按阶段提交',
  '退回或需修改时重新提交',
  '完成归档和成绩确认',
];
const studentSubmitCards = computed(() => [
  {
    key: 'sign',
    title: '签到',
    desc: '提交当天实习位置',
    icon: MapPin,
    meta: `${internship.lists.signIns.pagination.total || 0} 条`,
  },
  {
    key: 'journal',
    title: '实习日志',
    desc: '填写过程记录，需修改可重新提交',
    icon: FileClock,
    meta: stageDeadlineText('journal_deadline'),
    expired: isStageExpired('journal_deadline'),
  },
  {
    key: 'report',
    title: '实习报告',
    desc: '提交阶段或总结报告',
    icon: FileText,
    meta: stageDeadlineText('report_deadline'),
    expired: isStageExpired('report_deadline'),
  },
  {
    key: 'safety',
    title: '安全承诺',
    desc: '下载模板签署后上传定稿件',
    icon: CheckCircle2,
    meta: internship.forms.safety.signature_file_id ? '已选择定稿' : '待签署',
  },
  {
    key: 'delay',
    title: '延期申请',
    desc: '针对日志、报告等提交阶段申请延期',
    icon: FileClock,
    meta: delayConfigText(internship.forms.delay.config_key),
  },
]);
const internshipWorkbenchCells = computed(() => {
  if (isStudentRole.value) {
    return [
      { title: '我的任务', label: '已绑定的实习任务', value: internship.options.arrangements.length || '-' },
      { title: '已评分任务', label: '按任务记录成绩', value: internship.lists.scores.pagination.total || 0 },
      { title: '特殊申请', label: '分散、自主等场景', value: internship.lists.applications.pagination.total || 0 },
    ];
  }
  if (isTeacherRole.value) {
    return [
      { title: '待评日志', label: '学生提交的实习日志', value: internship.overview.journals_waiting || 0 },
      { title: '待评报告', label: '学生提交的实习报告', value: internship.overview.reports_waiting || 0 },
      { title: '特殊申请', label: '分散、自主等场景', value: internship.lists.applications.pagination.total || 0 },
    ];
  }
  return [
    { title: '实习任务', label: '全校实习任务', value: internship.overview.arrangements || 0 },
    { title: '任务绑定', label: '学生与任务老师绑定', value: internship.overview.active_pairs || 0 },
    { title: '特殊申请', label: '分散、自主等场景待审', value: internship.overview.applications_waiting || 0 },
  ];
});

const homeFocusTitle = computed(() => {
  if (isStudentRole.value) {
    return '我的实践进度';
  }
  if (isTeacherRole.value) {
    return '今日待处理';
  }
  return '学校实践概况';
});
const homeFocusDescription = computed(() => {
  if (isStudentRole.value) {
    return '优先处理待提交和需修改事项';
  }
  if (isTeacherRole.value) {
    return '查看待审核材料和指导任务';
  }
  return '关注任务绑定、待审和异常数据';
});
const homeFocusItems = computed(() => internshipWorkbenchCells.value.map(item => ({
  ...item,
  target: 'internship',
})));

function openHomeFocus(item) {
  activeTab.value = item?.target || 'internship';
}

const practiceFlowSteps = [
  '教学计划来源于教务拉取或教师填报',
  '课表明确老师、班级、时间和地点',
  '课表发布为项目并绑定学生范围',
  '成绩比例配置后录入成绩并统计',
];
const practicePanelDefinitions = [
  { key: 'plans', entity: 'plan', title: '教学计划', shortTitle: '计划', icon: FileText, review: true, emptyText: '暂无教学计划' },
  { key: 'schedules', entity: 'schedule', title: '课表安排', shortTitle: '课表', icon: CalendarCheck, review: false, emptyText: '暂无课表安排' },
  { key: 'projects', entity: 'project', title: '项目发布', shortTitle: '项目', icon: ClipboardList, review: false, emptyText: '暂无项目' },
  { key: 'signIns', entity: 'sign_in', execution: 'sign_in', title: '签到记录', shortTitle: '签到', icon: MapPin, review: false, emptyText: '暂无签到记录' },
  { key: 'journals', entity: 'journal', execution: 'journal', title: '过程日志', shortTitle: '日志', icon: FileClock, review: true, emptyText: '暂无日志记录' },
  { key: 'reports', entity: 'report', execution: 'report', title: '总结报告', shortTitle: '报告', icon: FileText, review: true, emptyText: '暂无报告记录' },
  { key: 'syllabus', entity: 'syllabus', title: '大纲编写', shortTitle: '大纲', icon: BookOpen, review: true, emptyText: '暂无大纲' },
  { key: 'lessonPlans', entity: 'lessonPlan', title: '教案编写', shortTitle: '教案', icon: FileText, review: true, emptyText: '暂无教案' },
  { key: 'gradeRules', entity: 'gradeRule', title: '成绩比例', shortTitle: '比例', icon: GraduationCap, review: false, emptyText: '暂无成绩比例' },
  { key: 'scores', entity: 'score', title: '成绩评定', shortTitle: '成绩', icon: GraduationCap, review: false, emptyText: '暂无成绩记录' },
  { key: 'reflections', entity: 'reflection', title: '反思报告', shortTitle: '反思', icon: FileClock, review: true, emptyText: '暂无反思报告' },
];

const practiceReviewDialogTitle = computed(() => {
  const action = practiceReviewDialog.mode === 'reopen'
    ? '通过后修改'
    : (practiceReviewDialog.status === 'accept' ? '通过' : '退回');
  return `${action}${practiceEntityName(practiceReviewDialog.entity)}`;
});
const practiceReviewReasonLabel = computed(() => (
  practiceReviewDialog.mode === 'reopen' ? '修改理由' : (practiceReviewDialog.status === 'modify' ? '退回原因' : '审核意见')
));
const practiceReviewDialogRuleText = computed(() => (
  practiceRuleText(practiceReviewDialog.module, practiceReviewDialog.entity, practiceReviewDialog.status, practiceReviewReasonLabel.value)
));
const practiceReviewTargetDetails = computed(() => {
  const row = practiceReviewDialog.row;
  if (!row) {
    return [];
  }
  return [
    detailItem('模块', practiceModuleName(practiceReviewDialog.module)),
    detailItem('业务', practiceEntityName(practiceReviewDialog.entity)),
    detailItem('标题', row.title || row.name),
    detailItem('届次', row.grade_name),
    detailItem('学院专业', joinFact([row.dep_name, row.profession_name])),
    detailItem('当前状态', statusText(row.status)),
  ].filter(Boolean);
});
const practiceExecutionDialogTitle = computed(() => {
  const names = { sign_in: '签到', journal: '提交日志', report: '提交报告' };
  return `${practiceModuleName(practiceExecutionDialog.module)}${names[practiceExecutionDialog.execution] || '提交'}`;
});
const practiceExecutionDialogSubtitle = computed(() => {
  const project = practiceModule(practiceExecutionDialog.module).options.projects.find(item => Number(item.id) === Number(practiceExecutionDialog.form.project_id || 0));
  return project?.title || project?.course_name || '请选择项目';
});

function getMobileListConfig(key) {
  return mobileListConfigs.value?.[key] || null;
}

const studentScopedListKeys = new Set([
  'applications',
  'pairs',
  'signIns',
  'journals',
  'reports',
  'delays',
  'scores',
  'courseScores',
  'archiveMaterials',
  'insurances',
  'safetyLetters',
]);

function selectFilterItems(items, valueKey, labelKey) {
  return (items || []).map(item => ({
    value: item[valueKey],
    label: item[labelKey] || item[valueKey],
  }));
}

function mobileDepartmentOptions(key) {
  return mobileDepartmentOptionsByValues(internship.filters[key] || {});
}

function mobileDepartmentOptionsByValues(filters = {}) {
  const gradeId = Number(filters.grade_id || 0);
  const grade = internship.options.grades.find(item => Number(item.grade_id) === gradeId);
  if (grade?.dep_id) {
    return internship.options.departments.filter(item => Number(item.dep_id) === Number(grade.dep_id));
  }
  return internship.options.departments;
}

function mobileProfessionOptions(key) {
  return mobileProfessionOptionsByValues(internship.filters[key] || {});
}

function mobileProfessionOptionsByValues(filters = {}) {
  const gradeId = Number(filters.grade_id || 0);
  const depId = Number(filters.dep_id || 0);
  return internship.options.professions.filter((item) => {
    const matchGrade = !gradeId || Number(item.grade_id || 0) === gradeId;
    const matchDepartment = !depId || Number(item.dep_id || 0) === depId;
    return matchGrade && matchDepartment;
  });
}

function mobileClassOptions(key) {
  return mobileClassOptionsByValues(internship.filters[key] || {});
}

function mobileClassOptionsByValues(filters = {}) {
  const gradeId = Number(filters.grade_id || 0);
  const depId = Number(filters.dep_id || 0);
  const professionId = Number(filters.profession_id || 0);
  return internship.options.classes.filter((item) => {
    const matchGrade = !gradeId || Number(item.grade_id || 0) === gradeId;
    const matchDepartment = !depId || Number(item.dep_id || 0) === depId;
    const matchProfession = !professionId || Number(item.profession_id || 0) === professionId;
    return matchGrade && matchDepartment && matchProfession;
  });
}

function mobileListSelectFilters(config) {
  if (!config) {
    return [];
  }
  const key = config.key;
  const filters = [];
  if (config.gradeFilter) {
    filters.push({
      key: 'grade_id',
      label: '届次',
      placeholder: '全部届次',
      options: selectFilterItems(internship.options.grades, 'grade_id', 'grade_name'),
    });
  }
  if (isAdminRole.value && (studentScopedListKeys.has(key) || ['arrangements', 'arrangementChanges', 'plans', 'syllabusGuides', 'implementationSheets', 'teacherWorkReports', 'inspections'].includes(key))) {
    filters.push({
      key: 'dep_id',
      label: '学院',
      placeholder: '全部学院',
      options: selectFilterItems(mobileDepartmentOptions(key), 'dep_id', 'dep_name'),
    });
  }
  if (isAdminRole.value && (studentScopedListKeys.has(key) || ['arrangements', 'arrangementChanges', 'syllabusGuides', 'implementationSheets', 'teacherWorkReports', 'inspections'].includes(key))) {
    filters.push({
      key: 'profession_id',
      label: '专业',
      placeholder: '全部专业',
      options: selectFilterItems(mobileProfessionOptions(key), 'profession_id', 'profession_name'),
    });
  }
  if (isAdminRole.value && studentScopedListKeys.has(key)) {
    filters.push({
      key: 'class_id',
      label: '班级',
      placeholder: '全部班级',
      options: selectFilterItems(mobileClassOptions(key), 'class_id', 'class_name'),
    });
  }
  return filters;
}

function normalizeMobileListFilters(key) {
  const filters = internship.filters[key] || {};
  if (filters.dep_id && !mobileDepartmentOptions(key).some(item => Number(item.dep_id) === Number(filters.dep_id))) {
    filters.dep_id = '';
  }
  if (filters.profession_id && !mobileProfessionOptions(key).some(item => Number(item.profession_id) === Number(filters.profession_id))) {
    filters.profession_id = '';
  }
  if (filters.class_id && !mobileClassOptions(key).some(item => Number(item.class_id) === Number(filters.class_id))) {
    filters.class_id = '';
  }
}

function updateInternshipListFilter(key, payload) {
  if (!payload?.key || !internship.filters[key]) {
    return;
  }
  internship.filters[key][payload.key] = payload.value;
  normalizeMobileListFilters(key);
}

function resetInternshipListFilters(key) {
  if (!internship.filters[key]) {
    return;
  }
  const filters = emptyInternshipFilters();
  applyInternshipFilterDefaults(filters);
  internship.filters[key] = filters;
  reloadInternshipList(key);
}

function normalizeInternshipListViews() {
  const reviewKeys = reviewListTabs.value.map(item => item.key);
  if (reviewKeys.length && !reviewKeys.includes(internship.reviewList)) {
    internship.reviewList = reviewKeys[0];
  }

  const manageKeys = manageListTabs.value.map(item => item.key);
  if (manageKeys.length && !manageKeys.includes(internship.manageList)) {
    internship.manageList = manageKeys[0];
  }
}

function mobileListRows(key) {
  return internship.lists[key]?.items || [];
}

function listTotal(key) {
  return internship.lists[key]?.pagination?.total || 0;
}

function mobileListTitle(key, row) {
  const student = row.student_name || row.student_num || (row.student_id ? `学生ID ${row.student_id}` : '');
  const arrangement = row.arrangement_title || (row.arrangement_id ? `任务ID ${row.arrangement_id}` : '');
  const changePayload = arrangementChangePayload(row);
  const titles = {
    arrangements: row.title || row.name || `任务ID ${row.id}`,
    arrangementChanges: changePayload.title || row.arrangement_title || `变更ID ${row.id}`,
    // 暂时隐藏学期展示，后续需要时恢复 row.semester。
    plans: row.dep_name || `计划ID ${row.id}`,
    syllabusGuides: row.title || row.arrangement_title || `大纲ID ${row.id}`,
    implementationSheets: row.arrangement_title || `实施表ID ${row.id}`,
    applications: student || arrangement || `申请ID ${row.id}`,
    pairs: student || `关系ID ${row.id}`,
    signIns: student || arrangement || `签到ID ${row.id}`,
    journals: row.title || student || `日志ID ${row.id}`,
    reports: row.title || student || `报告ID ${row.id}`,
    teacherWorkReports: joinFact([row.teacher_name, row.arrangement_title]) || `工作报告ID ${row.id}`,
    delays: joinFact([student, delayConfigText(row.config_key)]) || `延期ID ${row.id}`,
    scores: student || `成绩ID ${row.id}`,
    courseScores: joinFact([row.student_name, row.student_num]) || `课程成绩 ${row.plan_id}-${row.student_id}`,
    inspections: joinFact([row.arrangement_title, row.student_name]) || `巡查ID ${row.id}`,
    archiveMaterials: row.course_name || student || arrangement || `归档ID ${row.id}`,
    insurances: student || row.insurance_company || `保险ID ${row.id}`,
    safetyLetters: student || `承诺ID ${row.id}`,
  };
  return titles[key] || row.title || row.name || `记录ID ${row.id}`;
}

function mobileListValue(key, row) {
  if (key === 'arrangementChanges') {
    return statusText(row.status);
  }
  if (key === 'signIns') {
    return signTypeText(row.sign_type);
  }
  if (key === 'scores') {
    return row.final_score !== null && row.final_score !== undefined ? `总评 ${row.final_score}` : '-';
  }
  if (key === 'courseScores') {
    return row.course_final_score !== null && row.course_final_score !== undefined ? `${row.course_final_score} 分` : '-';
  }
  if (key === 'insurances') {
    return row.policy_number || statusText(row.status);
  }
  if (key === 'archiveMaterials') {
    return row.archive_status_text || row.material_progress || statusText(row.archive_status);
  }
  if (key === 'inspections') {
    return inspectionResultText(row.result);
  }
  return statusText(row.status);
}

function mobileListFacts(key, row) {
  const student = joinFact([row.student_name, row.student_num]);
  const arrangement = row.arrangement_title || (row.arrangement_id ? `任务ID ${row.arrangement_id}` : '');
  const changePayload = arrangementChangePayload(row);
  const facts = {
    arrangements: [
      // 暂时隐藏学期字段，后续需要时恢复。
      // namedFact('学期', row.semester),
      namedFact('任务编号', row.task_no),
      namedFact('批次', row.batch_no),
      namedFact('类型', arrangementTypeText(row.type)),
      namedFact('方式', organizeModeText(row.organize_mode)),
      namedFact('时间', dateRangeText(row.start_date, row.end_date)),
      namedFact('绑定班级', row.class_names),
      namedFact('学生数', row.student_count),
      namedFact('绑定人数', row.task_binding_count),
      namedFact('任务评分', row.task_score_progress_text),
      namedFact('范围', joinFact([row.dep_name || '全校', row.profession_name || '全部专业', row.grade_name])),
    ],
    arrangementChanges: [
      namedFact('原任务', arrangement),
      namedFact('课程', row.course_name),
      namedFact('原老师', row.teacher_name),
      namedFact('拟变更时间', dateRangeText(changePayload.start_date, changePayload.end_date)),
      namedFact('生效任务', row.new_arrangement_title),
      namedFact('新任务编号', row.new_task_no),
      namedFact('新负责老师', row.new_teacher_name),
      namedFact('原因', previewText(row.reason, 42)),
      namedFact('提交人', row.submitter_name),
    ],
    plans: [
      // 暂时隐藏学期字段，后续需要时恢复。
      // namedFact('学期', row.semester),
      namedFact('学院', row.dep_name),
      namedFact('专业', row.profession_name),
      namedFact('任务数', row.task_count),
      namedFact('任务覆盖', row.task_coverage_text),
      namedFact('任务评分', row.task_score_progress_text),
      namedFact('提交人', row.submitter_name),
      namedFact('审核进度', row.approval_progress_text),
      namedFact('当前节点', row.current_approval_name),
      namedFact('内容', planContentText(row.plan_content, 48)),
    ],
    syllabusGuides: [
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('学院专业', joinFact([row.dep_name, row.profession_name])),
      namedFact('录入人', row.creator_name),
      namedFact('内容', previewText(row.content, 42)),
    ],
    implementationSheets: [
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('学院专业', joinFact([row.dep_name, row.profession_name])),
      namedFact('教师', row.teacher_name),
      namedFact('承诺签署', `${row.signed_count || 0}/${Number(row.signed_count || 0) + Number(row.unsigned_count || 0)}`),
      namedFact('保险', row.insurance_verified === 'true' ? '已核验' : '未核验'),
    ],
    applications: [
      namedFact('学号', row.student_num),
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('学院专业', joinFact([row.dep_name, row.profession_name])),
      namedFact('教师审核', statusText(row.teacher_status)),
      namedFact('管理审核', statusText(row.admin_status)),
      namedFact('提交', row.created_at),
    ],
    pairs: [
      namedFact('学号', row.student_num),
      namedFact('届次', row.grade_name),
      namedFact('学院专业', joinFact([row.dep_name, row.profession_name])),
      namedFact('班级', row.class_name),
      namedFact('任务', arrangement),
      namedFact('任务编号', row.task_no),
      namedFact('批次', row.batch_no),
      namedFact('教师', row.teacher_name || row.teacher_num),
      namedFact('创建', row.created_at),
    ],
    signIns: [
      namedFact('学生', student),
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('时间', joinFact([row.date, row.sign_time])),
      namedFact('地点', row.location),
    ],
    journals: [
      namedFact('学生', student),
      namedFact('届次', row.grade_name),
      namedFact('日期', row.date || row.created_at),
      namedFact('任务', arrangement),
      namedFact('内容', previewText(row.content, 42)),
    ],
    reports: [
      namedFact('学生', student),
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('提交', row.submitted_at || row.created_at),
      namedFact('内容', previewText(row.content, 42)),
    ],
    teacherWorkReports: [
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('教师', row.teacher_name),
      namedFact('指导人数', row.guidance_count),
      namedFact('总结', previewText(row.summary, 42)),
    ],
    delays: [
      namedFact('学生', student),
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('延期类型', delayConfigText(row.config_key)),
      namedFact('延期至', row.requested_date),
      namedFact('原因', previewText(row.reason, 42)),
    ],
    scores: [
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('评分状态', row.final_score !== null && row.final_score !== undefined ? '已评分' : '待评分'),
      namedFact('评分人', row.teacher_name || row.teacher_num),
      namedFact('分项', scoreBreakdownText(row)),
    ],
    courseScores: [
      namedFact('届次', row.grade_name),
      namedFact('学院专业', joinFact([row.dep_name, row.profession_name])),
      namedFact('班级', row.class_name),
      namedFact('课程', joinFact([row.course_code, row.course_name])),
      namedFact('成绩规则', scoreRuleText(row.score_rule)),
      namedFact('汇总状态', courseScoreStatusText(row.course_score_status)),
      namedFact('课程成绩', row.course_final_score ?? ''),
      namedFact('核定说明', previewText(row.manual_score_remark, 42)),
      namedFact('核定人', row.manual_score_operator_name || row.manual_score_operator_login),
      namedFact('任务成绩', row.task_score_text),
    ],
    inspections: [
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('学生', student),
      namedFact('巡查人', row.inspector_name),
      namedFact('说明', previewText(row.remark, 42)),
    ],
    archiveMaterials: [
      namedFact('届次', row.grade_name),
      namedFact(row.student_id ? '学生' : '学院专业', row.student_id ? student : joinFact([row.dep_name, row.profession_name])),
      namedFact(row.student_id ? '任务' : '课程', row.student_id ? arrangement : joinFact([row.course_code, row.course_name])),
      namedFact('任务数', row.task_count),
      namedFact('学生任务数', row.student_task_count),
      namedFact('进度', row.material_progress),
      namedFact('缺失', row.missing_materials),
    ],
    insurances: [
      namedFact('学生', student || (row.student_id ? `学生ID ${row.student_id}` : '')),
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('保险公司', row.insurance_company),
      namedFact('时间', dateRangeText(row.start_date, row.end_date)),
    ],
    safetyLetters: [
      namedFact('学生', student || (row.student_id ? `学生ID ${row.student_id}` : '')),
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('签署', row.signed_at),
    ],
  };
  return (facts[key] || []).filter(Boolean);
}

function mobileListActions(key, row, context) {
  const config = getMobileListConfig(key);
  if (!config?.entity) {
    return [];
  }

  const actions = [{ key: 'timeline', label: '记录', type: 'timeline', entity: config.entity }];
  const canActInContext = context === 'review' || (context === 'manage' && config.entity === 'arrangement_change');
  if (canActInContext && canReviewEntity(config.entity)) {
    if (canReviewRow(row, config.entity)) {
      const rejectStatus = config.entity === 'delay' ? 'refuse' : 'modify';
      actions.push(
        { key: 'accept', label: '通过', type: 'review', entity: config.entity, status: 'accept' },
        { key: rejectStatus, label: '退回', type: 'review', entity: config.entity, status: rejectStatus },
      );
    }
    if (canRequestModification(row, config.entity)) {
      actions.push({ key: 'reopen', label: '通过后修改', type: 'reopen', entity: config.entity });
    }
  }
  return actions;
}

function canReviewEntity(entity) {
  if (entity === 'plan') {
    return canReviewInternshipPlan.value;
  }
  if (entity === 'arrangement_change') {
    return isAdminRole.value && (hasPermission('internship:manage') || hasPermission('internship:approve'));
  }
  return canReviewInternship.value;
}

function handleMobileListAction(action, row) {
  if (action.type === 'timeline') {
    openTimelineDialog(action.entity, row);
    return;
  }
  if (action.type === 'reopen') {
    openReopenDialog(action.entity, row);
    return;
  }
  if (action.type === 'review') {
    openReviewDialog(action.entity, row, action.status);
  }
}

function namedFact(label, value) {
  const text = String(value ?? '').trim();
  return text && text !== '-' ? `${label}：${text}` : '';
}

function joinFact(values) {
  return values
    .map(value => String(value ?? '').trim())
    .filter(Boolean)
    .join(' / ');
}

function dateRangeText(start, end) {
  return joinFact([start, end]);
}

function previewText(value, length = 40) {
  const text = String(value || '').replace(/\s+/g, ' ').trim();
  if (text.length <= length) {
    return text;
  }
  return `${text.slice(0, length)}...`;
}

function planContentText(value, length = 48) {
  let text = '';
  if (typeof value === 'string') {
    try {
      const parsed = JSON.parse(value);
      text = parsed?.content || parsed?.summary || value;
    } catch {
      text = value;
    }
  } else {
    text = value?.content || value?.summary || JSON.stringify(value || {});
  }
  return previewText(text, length);
}

function scoreBreakdownText(row) {
  return [
    `签到 ${row.sign_in_score ?? '-'}`,
    `日志 ${row.journal_score ?? '-'}`,
    `报告 ${row.report_score ?? '-'}`,
    `企业 ${row.enterprise_score ?? '-'}`,
  ].join(' / ');
}

function setPagedList(key, data, append = false) {
  const items = data.items || [];
  internship.lists[key].items = append ? [...internship.lists[key].items, ...items] : items;
  internship.lists[key].pagination = {
    ...internship.lists[key].pagination,
    ...(data.pagination || {}),
  };
}

function hasFilterValue(value) {
  return value !== '' && value !== null && value !== undefined;
}

function sameFilterValue(left, right) {
  return String(left ?? '') === String(right ?? '');
}

function currentInternshipGradeId() {
  const currentGrade = (internship.options.grades || [])
    .find(item => sameFilterValue(item.is_current, 'true') || sameFilterValue(item.is_current, 1));
  return currentGrade?.grade_id || internship.options.grades?.[0]?.grade_id || '';
}

function scopeIds(field) {
  const ids = [];
  (state.context.organization_scopes || []).forEach((scope) => {
    if (hasFilterValue(scope?.[field])) {
      ids.push(scope[field]);
    }
  });

  const filter = scopeFilter.value || {};
  const value = filter[field];
  (Array.isArray(value) ? value : [value]).forEach((item) => {
    if (hasFilterValue(item)) {
      ids.push(item);
    }
  });

  return Array.from(new Set(ids.map(item => String(item))));
}

function firstScopedOption(items = [], key, ids = []) {
  const values = (ids || []).filter(hasFilterValue).map(item => String(item));
  if (!values.length) {
    return null;
  }
  return (items || []).find(item => values.includes(String(item?.[key] ?? ''))) || null;
}

function internshipScopeDefaults() {
  const defaults = {
    grade_id: currentInternshipGradeId(),
    dep_id: '',
    profession_id: '',
    class_id: '',
  };
  const depIds = scopeIds('dep_id');
  const professionIds = scopeIds('profession_id');
  const classIds = scopeIds('class_id');
  const scopedClass = firstScopedOption(internship.options.classes, 'class_id', classIds);
  const scopedProfession = firstScopedOption(internship.options.professions, 'profession_id', professionIds);
  const scopedDepartment = firstScopedOption(internship.options.departments, 'dep_id', depIds);

  if (scopedClass) {
    defaults.class_id = scopedClass.class_id || '';
    defaults.profession_id = scopedClass.profession_id || defaults.profession_id;
    defaults.dep_id = scopedClass.dep_id || defaults.dep_id;
    defaults.grade_id = defaults.grade_id || scopedClass.grade_id || '';
  }
  if (scopedProfession) {
    defaults.profession_id = scopedProfession.profession_id || defaults.profession_id;
    defaults.dep_id = scopedProfession.dep_id || defaults.dep_id;
    defaults.grade_id = defaults.grade_id || scopedProfession.grade_id || '';
  }
  if (scopedDepartment) {
    defaults.dep_id = scopedDepartment.dep_id || defaults.dep_id;
  }

  if (roleType.value === 'college_admin' && !defaults.dep_id) {
    defaults.dep_id = depIds[0] || (internship.options.departments?.length === 1 ? internship.options.departments[0]?.dep_id : '') || '';
  }
  if (roleType.value === 'profession_admin' && !defaults.profession_id) {
    const fallback = firstScopedOption(internship.options.professions, 'profession_id', professionIds)
      || (internship.options.professions?.length === 1 ? internship.options.professions[0] : null);
    if (fallback) {
      defaults.profession_id = fallback.profession_id || '';
      defaults.dep_id = fallback.dep_id || defaults.dep_id;
      defaults.grade_id = defaults.grade_id || fallback.grade_id || '';
    }
  }

  return defaults;
}

function applyInternshipFilterDefaults(target) {
  const defaults = internshipScopeDefaults();
  Object.entries(defaults).forEach(([key, value]) => {
    if (hasFilterValue(value) && !hasFilterValue(target[key])) {
      target[key] = value;
    }
  });
  normalizeMobileListFiltersByValues(target);
}

function applyDefaultInternshipFilters() {
  Object.keys(internship.filters).forEach((key) => {
    const current = {
      ...emptyInternshipFilters(),
      ...internship.filters[key],
    };
    applyInternshipFilterDefaults(current);
    internship.filters[key] = current;
  });
}

function normalizeMobileListFiltersByValues(filters) {
  if (!filters) {
    return;
  }
  if (filters.profession_id && !mobileProfessionOptionsByValues(filters).some(item => sameFilterValue(item.profession_id, filters.profession_id))) {
    filters.profession_id = '';
  }
  if (filters.dep_id && !mobileDepartmentOptionsByValues(filters).some(item => sameFilterValue(item.dep_id, filters.dep_id))) {
    filters.dep_id = '';
    filters.profession_id = '';
  }
  if (filters.class_id && !mobileClassOptionsByValues(filters).some(item => sameFilterValue(item.class_id, filters.class_id))) {
    filters.class_id = '';
  }
}

function internshipQueryParams(key, page = 1) {
  const filters = internship.filters[key] || {};
  const params = {
    page,
    page_size: internship.lists[key]?.pagination.page_size || 10,
  };
  Object.entries(filters).forEach(([filterKey, value]) => {
    if (value !== '' && value !== null && value !== undefined) {
      params[filterKey] = value;
    }
  });
  return params;
}

function internshipFetcher(key) {
  const fetchers = {
    arrangements: fetchInternshipArrangements,
    arrangementChanges: fetchInternshipArrangementChanges,
    plans: fetchInternshipPlans,
    applications: fetchInternshipApplications,
    pairs: fetchInternshipPairs,
    signIns: fetchInternshipSignIns,
    journals: fetchInternshipJournals,
    reports: fetchInternshipReports,
    delays: fetchInternshipDelays,
    scores: fetchInternshipScores,
    courseScores: fetchInternshipCourseScores,
    syllabusGuides: fetchInternshipSyllabusGuides,
    implementationSheets: fetchInternshipImplementationSheets,
    teacherWorkReports: fetchInternshipTeacherWorkReports,
    inspections: fetchInternshipInspections,
    archiveMaterials: fetchInternshipArchiveMaterials,
    insurances: fetchInternshipInsurances,
    safetyLetters: fetchInternshipSafetyLetters,
  };
  return fetchers[key] || null;
}

async function loadInternshipList(key, page = 1, append = false) {
  const fetcher = internshipFetcher(key);
  if (!fetcher) {
    return;
  }
  applyInternshipFilterDefaults(internship.filters[key] || {});
  const data = await fetcher(internshipQueryParams(key, page));
  setPagedList(key, data, append);
}

async function reloadInternshipList(key) {
  internship.loading = true;
  internship.message = '';
  try {
    await loadInternshipList(key, 1);
  } catch (error) {
    internship.message = error.message;
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

async function loadMoreInternshipList(key) {
  const pagination = internship.lists[key]?.pagination || {};
  if (!canLoadMore(key)) {
    return;
  }
  internship.loading = true;
  internship.message = '';
  try {
    await loadInternshipList(key, (pagination.page || 1) + 1, true);
  } catch (error) {
    internship.message = error.message;
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

function canLoadMore(key) {
  const list = internship.lists[key];
  if (!list) {
    return false;
  }
  return list.items.length < (list.pagination.total || 0);
}

function isPracticeTab(tab) {
  return ['training', 'lab'].includes(tab);
}

function practiceModule(module) {
  return practice[module] || practice.training;
}

function practiceModuleName(module) {
  return module === 'lab' ? '实验管理' : '实训管理';
}

function practicePanels(module) {
  if (isStudentRole.value) {
    return practicePanelDefinitions.filter(item => ['projects', 'signIns', 'journals', 'reports', 'scores'].includes(item.key));
  }
  if (isTeacherRole.value) {
    return practicePanelDefinitions.filter(item => item.key !== 'gradeRules');
  }
  return practicePanelDefinitions;
}

function currentPracticePanel(module) {
  const state = practiceModule(module);
  const panels = practicePanels(module);
  return panels.find(item => item.key === state.panel) || panels[0] || practicePanelDefinitions[0];
}

function practiceSummaries(module) {
  const overview = practiceModule(module).overview;
  return [
    { name: '待审计划', value: overview.plans_waiting || 0 },
    { name: '课表', value: overview.schedules || 0 },
    { name: '项目', value: overview.projects || 0 },
    { name: '日志', value: practiceModule(module).lists.journals.pagination.total || 0 },
    { name: '今日课表', value: overview.today_schedules || 0 },
  ];
}

function practiceFilters(module) {
  if (isStudentRole.value) {
    return [];
  }
  const state = practiceModule(module);
  const options = state.options;
  const common = [
    { key: 'grade_id', label: '届次', placeholder: '全部届次', options: selectFilterItems(options.grades, 'grade_id', 'grade_name') },
  ];
  if (isAdminRole.value) {
    common.push(
      { key: 'dep_id', label: '学院', placeholder: '全部学院', options: selectFilterItems(practiceDepartmentFilterOptions(module), 'dep_id', 'dep_name') },
      { key: 'profession_id', label: '专业', placeholder: '全部专业', options: selectFilterItems(practiceProfessionFilterOptions(module), 'profession_id', 'profession_name') },
      { key: 'class_id', label: '班级', placeholder: '全部班级', options: selectFilterItems(practiceClassFilterOptions(module), 'class_id', 'class_name') },
    );
  }
  if (currentPracticePanel(module).review) {
    common.push({ key: 'status', label: '状态', placeholder: '全部状态', options: reviewStatusOptions });
  } else if (['projects', 'schedules'].includes(currentPracticePanel(module).key)) {
    common.push({ key: 'status', label: '状态', placeholder: '全部状态', options: practiceEnabledStatusOptions });
  }
  return common;
}

function currentPracticeGradeId(module) {
  const grades = practiceModule(module).options.grades || [];
  const currentGrade = grades.find(item => sameFilterValue(item.is_current, 'true') || sameFilterValue(item.is_current, 1));
  return currentGrade?.grade_id || grades[0]?.grade_id || '';
}

function practiceFilterValues(module) {
  const state = practiceModule(module);
  const panel = currentPracticePanel(module);
  return state.filters[panel.key] || {};
}

function practiceDepartmentFilterOptions(module) {
  return practiceModule(module).options.departments || [];
}

function practiceProfessionFilterOptions(module, filters = practiceFilterValues(module)) {
  const state = practiceModule(module);
  const gradeId = Number(filters.grade_id || 0);
  const depId = Number(filters.dep_id || 0);
  return (state.options.professions || []).filter((item) => {
    const matchGrade = !gradeId || Number(item.grade_id || 0) === gradeId;
    const matchDepartment = !depId || Number(item.dep_id || 0) === depId;
    return matchGrade && matchDepartment;
  });
}

function practiceClassFilterOptions(module, filters = practiceFilterValues(module)) {
  const state = practiceModule(module);
  const gradeId = Number(filters.grade_id || 0);
  const depId = Number(filters.dep_id || 0);
  const professionId = Number(filters.profession_id || 0);
  return (state.options.classes || []).filter((item) => {
    const matchGrade = !gradeId || Number(item.grade_id || 0) === gradeId;
    const matchDepartment = !depId || Number(item.dep_id || 0) === depId;
    const matchProfession = !professionId || Number(item.profession_id || 0) === professionId;
    return matchGrade && matchDepartment && matchProfession;
  });
}

function normalizePracticeFilters(module, filters = practiceFilterValues(module)) {
  if (!filters) {
    return;
  }
  if (filters.dep_id && !practiceDepartmentFilterOptions(module).some(item => sameFilterValue(item.dep_id, filters.dep_id))) {
    filters.dep_id = '';
    filters.profession_id = '';
    filters.class_id = '';
  }
  if (filters.profession_id && !practiceProfessionFilterOptions(module, filters).some(item => sameFilterValue(item.profession_id, filters.profession_id))) {
    filters.profession_id = '';
    filters.class_id = '';
  }
  if (filters.class_id && !practiceClassFilterOptions(module, filters).some(item => sameFilterValue(item.class_id, filters.class_id))) {
    filters.class_id = '';
  }
}

function practiceScopeDefaults(module) {
  const state = practiceModule(module);
  const defaults = {
    grade_id: currentPracticeGradeId(module),
    dep_id: '',
    profession_id: '',
    class_id: '',
  };
  const depIds = scopeIds('dep_id');
  const professionIds = scopeIds('profession_id');
  const classIds = scopeIds('class_id');
  const scopedClass = firstScopedOption(state.options.classes, 'class_id', classIds);
  const scopedProfession = firstScopedOption(state.options.professions, 'profession_id', professionIds);
  const scopedDepartment = firstScopedOption(state.options.departments, 'dep_id', depIds);

  if (scopedClass) {
    defaults.class_id = scopedClass.class_id || '';
    defaults.profession_id = scopedClass.profession_id || defaults.profession_id;
    defaults.dep_id = scopedClass.dep_id || defaults.dep_id;
    defaults.grade_id = defaults.grade_id || scopedClass.grade_id || '';
  }
  if (scopedProfession) {
    defaults.profession_id = scopedProfession.profession_id || defaults.profession_id;
    defaults.dep_id = scopedProfession.dep_id || defaults.dep_id;
    defaults.grade_id = defaults.grade_id || scopedProfession.grade_id || '';
  }
  if (scopedDepartment) {
    defaults.dep_id = scopedDepartment.dep_id || defaults.dep_id;
  }
  if (roleType.value === 'college_admin' && !defaults.dep_id) {
    defaults.dep_id = depIds[0] || (state.options.departments?.length === 1 ? state.options.departments[0]?.dep_id : '') || '';
  }
  if (roleType.value === 'profession_admin' && !defaults.profession_id) {
    const fallback = firstScopedOption(state.options.professions, 'profession_id', professionIds)
      || (state.options.professions?.length === 1 ? state.options.professions[0] : null);
    if (fallback) {
      defaults.profession_id = fallback.profession_id || '';
      defaults.dep_id = fallback.dep_id || defaults.dep_id;
      defaults.grade_id = defaults.grade_id || fallback.grade_id || '';
    }
  }

  return defaults;
}

function applyDefaultPracticeFilters(module) {
  const state = practiceModule(module);
  Object.keys(state.filters).forEach((key) => {
    const current = {
      ...emptyPracticeFilters(),
      ...state.filters[key],
    };
    Object.entries(practiceScopeDefaults(module)).forEach(([filterKey, value]) => {
      if (hasFilterValue(value) && !hasFilterValue(current[filterKey])) {
        current[filterKey] = value;
      }
    });
    state.filters[key] = current;
    normalizePracticeFilters(module, current);
  });
}

function updatePracticeListFilter(module, payload) {
  if (!payload?.key) {
    return;
  }
  const state = practiceModule(module);
  const panel = currentPracticePanel(module);
  state.filters[panel.key][payload.key] = payload.value;
  normalizePracticeFilters(module, state.filters[panel.key]);
}

function resetPracticeListFilters(module) {
  const state = practiceModule(module);
  const panel = currentPracticePanel(module);
  state.filters[panel.key] = emptyPracticeFilters();
  applyDefaultPracticeFilters(module);
  reloadPracticeList(module);
}

function practiceQueryParams(module, page = 1) {
  const state = practiceModule(module);
  const panel = currentPracticePanel(module);
  const filters = state.filters[panel.key] || {};
  const params = {
    entity: panel.entity,
    page,
    page_size: state.lists[panel.key]?.pagination.page_size || 10,
  };
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) {
      params[key] = value;
    }
  });
  return params;
}

function practiceExecutionQueryParams(module, page = 1) {
  const state = practiceModule(module);
  const panel = currentPracticePanel(module);
  const filters = state.filters[panel.key] || {};
  const params = {
    page,
    page_size: state.lists[panel.key]?.pagination.page_size || 10,
  };
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) {
      params[key] = value;
    }
  });
  return params;
}

async function loadPractice(module) {
  if (!isLoggedIn.value || !hasPermission(`${module}:view`)) {
    return;
  }
  const state = practiceModule(module);
  state.loading = true;
  state.message = '';
  try {
    const [overview, options] = await Promise.all([
      fetchPracticeOverview(module),
      fetchPracticeOptions(module),
    ]);
    state.overview = { ...state.overview, ...(overview || {}) };
    state.options = { ...state.options, ...(options || {}) };
    applyDefaultPracticeFilters(module);
    normalizePracticePanel(module);
    await loadPracticeList(module, 1);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

async function loadPracticeList(module, page = 1, append = false) {
  const state = practiceModule(module);
  const panel = currentPracticePanel(module);
  const data = panel.execution
    ? await fetchPracticeExecutionList(module, panel.execution, practiceExecutionQueryParams(module, page))
    : await fetchPracticeList(module, practiceQueryParams(module, page));
  const items = data.items || [];
  state.lists[panel.key].items = append ? [...state.lists[panel.key].items, ...items] : items;
  state.lists[panel.key].pagination = {
    ...state.lists[panel.key].pagination,
    ...(data.pagination || {}),
  };
}

async function reloadPracticeList(module) {
  const state = practiceModule(module);
  state.loading = true;
  state.message = '';
  try {
    await loadPracticeList(module, 1);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

async function loadMorePracticeList(module) {
  const state = practiceModule(module);
  const panel = currentPracticePanel(module);
  const pagination = state.lists[panel.key]?.pagination || {};
  if (!canLoadMorePractice(module)) {
    return;
  }
  state.loading = true;
  state.message = '';
  try {
    await loadPracticeList(module, (pagination.page || 1) + 1, true);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

function canLoadMorePractice(module) {
  const state = practiceModule(module);
  const panel = currentPracticePanel(module);
  const list = state.lists[panel.key];
  return Boolean(list && list.items.length < (list.pagination.total || 0));
}

function currentPracticeRows(module) {
  const state = practiceModule(module);
  return state.lists[currentPracticePanel(module).key]?.items || [];
}

function practiceListTotal(module) {
  const state = practiceModule(module);
  return state.lists[currentPracticePanel(module).key]?.pagination.total || 0;
}

function normalizePracticePanel(module) {
  const state = practiceModule(module);
  const keys = practicePanels(module).map(item => item.key);
  if (!keys.includes(state.panel)) {
    state.panel = keys[0] || 'plans';
  }
}

function switchPracticePanel(module, panel) {
  practiceModule(module).panel = panel;
  reloadPracticeList(module);
}

function practiceRowTitle(module, row) {
  const panel = currentPracticePanel(module).key;
  if (panel === 'signIns') {
    return row.project_title || row.title || '签到记录';
  }
  if (['journals', 'reports'].includes(panel)) {
    return row.title || row.project_title || `记录 ${row.id}`;
  }
  if (panel === 'scores') {
    return row.student_name || row.student_num || row.title || `成绩 ${row.id}`;
  }
  if (panel === 'projects') {
    return row.title || row.course_name || `项目 ${row.id}`;
  }
  if (panel === 'schedules') {
    return row.title || row.course_name || `课表 ${row.id}`;
  }
  return row.title || row.name || row.course_name || `记录 ${row.id}`;
}

function practiceRowValue(module, row) {
  const panel = currentPracticePanel(module).key;
  if (panel === 'signIns') {
    return row.date || row.sign_time || statusText(row.status);
  }
  if (['journals', 'reports'].includes(panel)) {
    return statusText(row.status);
  }
  if (panel === 'schedules') {
    return row.schedule_date || statusText(row.status);
  }
  if (panel === 'projects') {
    return joinFact([row.start_date, row.end_date]) || statusText(row.status);
  }
  if (panel === 'scores') {
    return row.score_value !== null && row.score_value !== undefined ? `${row.score_value} 分` : statusText(row.status);
  }
  return statusText(row.status);
}

function practiceRowFacts(module, row) {
  const panel = currentPracticePanel(module).key;
  const common = [
    namedFact('届次', row.grade_name),
    namedFact('学院专业', joinFact([row.dep_name, row.profession_name])),
    namedFact('教师', row.teacher_name),
  ];
  const detail = {
    schedules: [
      namedFact('计划', row.plan_title),
      namedFact('时间', joinFact([row.schedule_date, row.start_time, row.end_time])),
      namedFact('地点', row.room_name || row.base_name || row.location),
      namedFact('学生数', row.student_count),
    ],
    projects: [
      namedFact('计划', row.plan_title),
      namedFact('课表', row.schedule_title),
      namedFact('日期', joinFact([row.start_date, row.end_date])),
      namedFact('绑定学生', row.bound_student_count ?? row.student_count),
    ],
    signIns: [
      namedFact('项目', row.project_title),
      namedFact('学生', joinFact([row.student_name, row.student_num])),
      namedFact('负责老师', row.teacher_name),
      namedFact('位置', row.location),
    ],
    journals: [
      namedFact('项目', row.project_title),
      namedFact('学生', joinFact([row.student_name, row.student_num])),
      namedFact('日期', row.date || row.created_at),
      namedFact('内容', previewText(row.content, 52)),
    ],
    reports: [
      namedFact('项目', row.project_title),
      namedFact('学生', joinFact([row.student_name, row.student_num])),
      namedFact('提交', row.submitted_at || row.created_at),
      namedFact('内容', previewText(row.content, 52)),
    ],
    scores: [
      namedFact('学号', row.student_num),
      namedFact('计划', row.plan_title),
      namedFact('评分教师', row.teacher_name),
    ],
    gradeRules: [
      namedFact('计划', row.plan_title),
      namedFact('比例', practiceRatioText(row.ratio_json)),
    ],
    plans: [
      namedFact('来源', practiceSourceText(row.source_type)),
      namedFact('内容', previewText(row.content, 52)),
    ],
  }[panel] || [
    namedFact('计划', row.plan_title),
    namedFact('内容', previewText(row.content, 52)),
  ];
  return [...common, ...detail].filter(Boolean);
}

function practiceRowActions(module, row) {
  const panel = currentPracticePanel(module);
  if (panel.key === 'projects' && isStudentRole.value) {
    return [
      { key: 'signIn', label: '签到' },
      { key: 'journal', label: '日志' },
      { key: 'report', label: '报告' },
    ];
  }
  if (panel.execution) {
    const actions = [{ key: 'timeline', label: '记录' }];
    if (isStudentRole.value && ['journals', 'reports'].includes(panel.key) && ['draft', 'modify'].includes(row.status || '')) {
      actions.unshift({ key: 'editExecution', label: '修改' });
    }
    if (!isStudentRole.value && row.status === 'wait' && hasPermission(`${module}:approve`) && panel.review) {
      actions.push(
        { key: 'accept', label: '通过' },
        { key: 'modify', label: '退回' },
      );
    }
    if (!isStudentRole.value && row.status === 'accept' && hasPermission(`${module}:approve`) && panel.review) {
      actions.push({ key: 'reopen', label: '通过后修改' });
    }
    return actions;
  }
  if (!panel.review) {
    return [];
  }
  const actions = [{ key: 'timeline', label: '记录' }];
  if (row.status === 'wait' && hasPermission(`${module}:approve`) && !isStudentRole.value) {
    actions.push(
      { key: 'accept', label: '通过' },
      { key: 'modify', label: '退回' },
    );
  }
  if (row.status === 'accept' && hasPermission(`${module}:approve`) && !isStudentRole.value) {
    actions.push({ key: 'reopen', label: '通过后修改' });
  }
  return actions;
}

function handlePracticeAction(module, action, row) {
  const panel = currentPracticePanel(module);
  if (['signIn', 'journal', 'report'].includes(action.key)) {
    openPracticeExecutionDialog(module, action.key === 'signIn' ? 'signIns' : `${action.key}s`, null, {
      projectId: row.id,
      projectTitle: row.title || row.course_name,
    });
    return;
  }
  if (action.key === 'editExecution') {
    openPracticeExecutionDialog(module, panel.key, row);
    return;
  }
  if (action.key === 'timeline') {
    openPracticeTimeline(module, panel, row);
    return;
  }
  if (action.key === 'reopen') {
    openPracticeReview(module, panel, row, 'modify', 'reopen');
    return;
  }
  openPracticeReview(module, panel, row, action.key, 'review');
}

function applyDefaultInternshipSelection() {
  const firstArrangement = internship.options.arrangements[0];
  if (firstArrangement) {
    internship.forms.application.arrangement_id ||= firstArrangement.id;
    internship.forms.sign.arrangement_id ||= firstArrangement.id;
    internship.forms.journal.arrangement_id ||= firstArrangement.id;
    internship.forms.report.arrangement_id ||= firstArrangement.id;
    internship.forms.delay.arrangement_id ||= firstArrangement.id;
    internship.forms.safety.arrangement_id ||= firstArrangement.id;
  }
  internship.forms.journal.date ||= formatDateKey(new Date());
  internship.forms.safety.template_id ||= safetyTemplate.value?.id || null;
}

async function loadInternship() {
  if (!isLoggedIn.value || !hasPermission('internship:view')) {
    return;
  }

  internship.loading = true;
  internship.message = '';
  try {
    const [overview, options, archiveTemplates] = await Promise.all([
      fetchInternshipOverview(),
      fetchInternshipOptions(),
      fetchTemplateList({ business_code: 'internship_archive', page: 1, page_size: 100 }),
    ]);
    internship.overview = {
      ...emptyInternshipOverview(),
      ...(overview || {}),
    };
    internship.options = {
      ...emptyInternshipOptions(),
      ...(options || {}),
      archive_templates: archiveTemplates.items || [],
    };
    applyDefaultInternshipFilters();
    applyDefaultInternshipSelection();
    normalizeInternshipListViews();
    await loadInternshipPanelData();
  } catch (error) {
    internship.message = error.message;
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

async function loadInternshipPanelData() {
  if (internship.panel === 'workbench') {
    await Promise.all([
      loadInternshipList('applications'),
      loadInternshipList('scores'),
    ]);
    return;
  }
  if (internship.panel === 'apply') {
    await loadInternshipList('applications');
    return;
  }
  if (internship.panel === 'submit') {
    await Promise.all([
      loadInternshipList('signIns'),
      loadInternshipList('journals'),
      loadInternshipList('reports'),
      loadInternshipList('delays'),
      loadInternshipList('safetyLetters'),
    ]);
    return;
  }
  if (internship.panel === 'review') {
    await loadInternshipList(currentReviewListConfig.value?.key || 'applications');
    return;
  }
  if (internship.panel === 'score') {
    if (isTeacherRole.value) {
      const [pairs] = await Promise.all([
        fetchInternshipPairs({ page: 1, page_size: 50, keyword: internship.filters.pairs.keyword || '' }),
        loadInternshipList('scores'),
        loadInternshipList('courseScores'),
      ]);
      setPagedList('pairs', pairs);
      const firstPair = internship.lists.pairs.items[0];
      if (firstPair && !internship.forms.score.pair_id) {
        internship.forms.score.pair_id = firstPair.id;
        selectScorePair();
      }
      return;
    }
    await Promise.all([
      loadInternshipList('scores'),
      loadInternshipList('courseScores'),
    ]);
    return;
  }
  if (internship.panel === 'manage') {
    await loadInternshipList(currentManageListConfig.value?.key || 'arrangements');
  }
}

async function reloadScorePairs() {
  internship.loading = true;
  internship.message = '';
  try {
    const pairs = await fetchInternshipPairs({
      page: 1,
      page_size: 50,
      keyword: internship.filters.pairs.keyword || '',
    });
    setPagedList('pairs', pairs);
    const firstPair = internship.lists.pairs.items[0];
    if (firstPair) {
      internship.forms.score.pair_id = firstPair.id;
      selectScorePair();
    } else {
      internship.forms.score.pair_id = null;
      internship.forms.score.student_id = null;
      internship.forms.score.arrangement_id = null;
    }
  } catch (error) {
    internship.message = error.message;
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

function switchInternshipPanel(panel) {
  internship.panel = panel;
  normalizeInternshipListViews();
  loadInternship();
}

async function switchMobileList(type, key) {
  if (type === 'review') {
    internship.reviewList = key;
  } else {
    internship.manageList = key;
  }
  await reloadInternshipList(key);
}

async function submitApplication(status = 'wait') {
  if (internship.loading) {
    return;
  }
  if (!internship.forms.application.arrangement_id) {
    internship.message = '请选择实习任务';
    showToast(internship.message);
    return;
  }
  if (status === 'wait' && !String(internship.forms.application.remark || '').trim()) {
    internship.message = '请填写申请说明';
    showToast(internship.message);
    return;
  }

  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipApplication({
      arrangement_id: internship.forms.application.arrangement_id,
      type: internship.forms.application.type || 'distributed',
      status,
      remark: internship.forms.application.remark,
    });
    if (status === 'wait') {
      internship.forms.application.remark = '';
    }
    internship.message = status === 'wait' ? '申请已提交审核' : '申请草稿已保存';
    await loadInternship();
    showToast(internship.message);
  } catch (error) {
    internship.message = error.message;
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

async function submitSignIn() {
  if (internship.loading) {
    return;
  }
  if (!signGpsReady.value) {
    internship.message = '请先获取 GPS 定位后再签到';
    showToast(internship.message);
    return;
  }

  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipSignIn({
      arrangement_id: internship.forms.sign.arrangement_id,
      sign_type: 'gps',
      location: internship.forms.sign.location || 'GPS 定位签到',
      longitude: internship.forms.sign.longitude,
      latitude: internship.forms.sign.latitude,
      remark: internship.forms.sign.accuracy ? `GPS 精度 ${Math.round(Number(internship.forms.sign.accuracy))} 米` : '',
    });
    resetSignPosition();
    internship.message = '签到已提交';
    await loadInternship();
    showToast(internship.message);
  } catch (error) {
    internship.message = error.message;
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

async function submitJournal(status = 'wait') {
  if (internship.loading) {
    return;
  }
  const arrangementId = internship.forms.journal.arrangement_id || internship.forms.sign.arrangement_id;
  if (!arrangementId) {
    internship.message = '请选择实习任务';
    showToast(internship.message);
    return;
  }
  if (status === 'wait' && isStageExpired('journal_deadline')) {
    internship.message = '实习日志已截止，请先申请延期';
    showToast(internship.message);
    openDelayForStage('journal_deadline');
    return;
  }
  if (!String(internship.forms.journal.title || '').trim()) {
    internship.message = '请填写日志标题';
    showToast(internship.message);
    return;
  }
  if (!String(internship.forms.journal.work_content || '').trim()) {
    internship.message = '请填写工作内容';
    showToast(internship.message);
    return;
  }
  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipJournal({
      id: internship.forms.journal.id || undefined,
      arrangement_id: arrangementId,
      date: internship.forms.journal.date || formatDateKey(new Date()),
      title: internship.forms.journal.title,
      content: internship.forms.journal.work_content,
      location: internship.forms.journal.location,
      work_content: internship.forms.journal.work_content,
      gains: internship.forms.journal.gains,
      problems: internship.forms.journal.problems,
      form_data: {
        location: internship.forms.journal.location,
        gains: internship.forms.journal.gains,
        problems: internship.forms.journal.problems,
      },
      attachment_ids: internship.forms.journal.attachments.map(item => item.id),
      status,
    });
    if (status === 'wait') {
      internship.forms.journal.id = null;
      internship.forms.journal.arrangement_id = arrangementId;
      internship.forms.journal.title = '';
      internship.forms.journal.location = '';
      internship.forms.journal.work_content = '';
      internship.forms.journal.gains = '';
      internship.forms.journal.problems = '';
      internship.forms.journal.attachments = [];
    }
    internship.message = status === 'wait' ? '日志已提交审核' : '日志草稿已保存';
    await loadInternship();
    showToast(internship.message);
  } catch (error) {
    internship.message = error.message;
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

async function submitReport(status = 'wait') {
  if (internship.loading) {
    return;
  }
  const arrangementId = internship.forms.report.arrangement_id || internship.forms.sign.arrangement_id;
  if (!arrangementId) {
    internship.message = '请选择实习任务';
    showToast(internship.message);
    return;
  }
  if (status === 'wait' && isStageExpired('report_deadline')) {
    internship.message = '实习报告已截止，请先申请延期';
    showToast(internship.message);
    openDelayForStage('report_deadline');
    return;
  }
  if (!String(internship.forms.report.title || '').trim() || !String(internship.forms.report.content || '').trim()) {
    internship.message = '请填写报告标题和主要内容';
    showToast(internship.message);
    return;
  }
  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipReport({
      id: internship.forms.report.id || undefined,
      arrangement_id: arrangementId,
      template_id: internship.options.report_templates[0]?.id || null,
      title: internship.forms.report.title,
      content: internship.forms.report.content,
      report_type: currentReportIsGraduation.value ? 'graduation' : 'general',
      form_data: {
        purpose: internship.forms.report.purpose,
        gains: internship.forms.report.gains,
        suggestions: internship.forms.report.suggestions,
        company_profile: internship.forms.report.company_profile,
      },
      attachment_ids: internship.forms.report.attachments.map(item => item.id),
      status,
    });
    if (status === 'wait') {
      internship.forms.report.id = null;
      internship.forms.report.arrangement_id = arrangementId;
      internship.forms.report.title = '';
      internship.forms.report.content = '';
      internship.forms.report.purpose = '';
      internship.forms.report.gains = '';
      internship.forms.report.suggestions = '';
      internship.forms.report.company_profile = '';
      internship.forms.report.attachments = [];
    }
    internship.message = status === 'wait' ? '报告已提交审核' : '报告草稿已保存';
    await loadInternship();
    showToast(internship.message);
  } catch (error) {
    internship.message = error.message;
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

async function uploadInternshipAttachments(type, event) {
  const files = Array.from(event.target.files || []);
  event.target.value = '';
  if (!files.length || internship.loading) {
    return;
  }
  internship.loading = true;
  try {
    for (const file of files.slice(0, 10)) {
      const uploaded = await uploadFile(file, { category: 'internship_material', is_temporary: 'false' });
      internship.forms[type].attachments.push({ id: uploaded.file_id, name: uploaded.name || file.name, url: uploaded.url || '' });
    }
    showToast('附件已上传');
  } catch (error) {
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

function removeInternshipAttachment(type, fileId) {
  internship.forms[type].attachments = internship.forms[type].attachments.filter(item => Number(item.id) !== Number(fileId));
}

function openSafetyTemplate() {
  const url = safetyTemplate.value?.file?.url;
  if (!url) {
    showToast('安全承诺模板暂不可用');
    return;
  }
  window.open(backendUrl(url), '_blank', 'noopener,noreferrer');
}

async function uploadSafetyFinal(event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file || internship.loading) {
    return;
  }
  internship.loading = true;
  try {
    const uploaded = await uploadFile(file, { category: 'internship_archive', is_temporary: 'false' });
    internship.forms.safety.signature_file_id = uploaded.file_id;
    internship.forms.safety.file_name = uploaded.name || file.name;
    showToast('签署定稿已上传');
  } catch (error) {
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

async function submitSafetyLetter() {
  if (internship.loading) {
    return;
  }
  if (!internship.forms.safety.arrangement_id || !internship.forms.safety.signature_file_id) {
    showToast('请选择实习任务并上传签署定稿');
    return;
  }
  internship.loading = true;
  try {
    await saveInternshipSafetyLetter({
      arrangement_id: internship.forms.safety.arrangement_id,
      template_id: internship.forms.safety.template_id || safetyTemplate.value?.id || null,
      signature_file_id: internship.forms.safety.signature_file_id,
      status: 'signed',
    });
    internship.forms.safety.signature_file_id = null;
    internship.forms.safety.file_name = '';
    await loadInternship();
    showToast('安全承诺已提交');
  } catch (error) {
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

async function submitDelay(status = 'wait') {
  if (internship.loading) {
    return;
  }
  if (!internship.forms.delay.arrangement_id) {
    internship.message = '请选择实习任务';
    showToast(internship.message);
    return;
  }
  if (!internship.forms.delay.requested_date) {
    internship.message = '请选择申请延期日期';
    showToast(internship.message);
    return;
  }
  if (!String(internship.forms.delay.reason || '').trim()) {
    internship.message = '请填写申请原因';
    showToast(internship.message);
    return;
  }

  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipDelay({
      id: internship.forms.delay.id || undefined,
      entity_type: 'internship',
      entity_id: internship.forms.delay.arrangement_id,
      config_key: internship.forms.delay.config_key,
      requested_date: internship.forms.delay.requested_date,
      reason: internship.forms.delay.reason,
      status,
    });
    if (status === 'wait') {
      internship.forms.delay.id = null;
      internship.forms.delay.requested_date = '';
      internship.forms.delay.reason = '';
    }
    internship.message = status === 'wait' ? '延期申请已提交审核' : '延期申请草稿已保存';
    await loadInternship();
    showToast(internship.message);
  } catch (error) {
    internship.message = error.message;
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

function canEditStudentWork(row) {
  return isStudentRole.value && ['draft', 'modify'].includes(row?.status || '');
}

function editStudentWork(type, row) {
  if (!canEditStudentWork(row)) {
    return;
  }
  internship.panel = 'submit';
  if (type === 'journal') {
    internship.submitSection = 'journal';
    internship.forms.journal.id = row.id || null;
    internship.forms.journal.arrangement_id = row.arrangement_id || null;
    internship.forms.journal.title = row.title || '';
    internship.forms.journal.date = row.date || formatDateKey(new Date());
    internship.forms.journal.location = row.location || '';
    internship.forms.journal.work_content = row.work_content || row.content || '';
    internship.forms.journal.gains = row.gains || '';
    internship.forms.journal.problems = row.problems || '';
    internship.forms.journal.attachments = (row.attachment_ids || []).map(id => ({ id, name: `附件 ${id}` }));
    internship.forms.sign.arrangement_id = row.arrangement_id || internship.forms.sign.arrangement_id;
    internship.message = '已载入日志内容，请修改后重新提交';
  }
  if (type === 'report') {
    internship.submitSection = 'report';
    internship.forms.report.id = row.id || null;
    internship.forms.report.arrangement_id = row.arrangement_id || null;
    internship.forms.report.title = row.title || '';
    internship.forms.report.content = row.content || '';
    internship.forms.report.purpose = row.form_data?.purpose || '';
    internship.forms.report.gains = row.form_data?.gains || '';
    internship.forms.report.suggestions = row.form_data?.suggestions || '';
    internship.forms.report.company_profile = row.form_data?.company_profile || '';
    internship.forms.report.attachments = (row.attachment_ids || []).map(id => ({ id, name: `附件 ${id}` }));
    internship.forms.sign.arrangement_id = row.arrangement_id || internship.forms.sign.arrangement_id;
    internship.message = '已载入报告内容，请修改后重新提交';
  }
  if (type === 'delay') {
    internship.submitSection = 'delay';
    internship.forms.delay.id = row.id || null;
    internship.forms.delay.arrangement_id = row.arrangement_id || row.entity_id || null;
    internship.forms.delay.config_key = row.config_key || 'report_deadline';
    internship.forms.delay.requested_date = row.requested_date || '';
    internship.forms.delay.reason = row.reason || '';
    internship.forms.sign.arrangement_id = row.arrangement_id || row.entity_id || internship.forms.sign.arrangement_id;
    internship.message = '已载入延期申请，请修改后重新提交';
  }
}

function openReviewDialog(entity, row, status) {
  if (!canReviewRow(row, entity)) {
    internship.message = '仅待审核数据可处理';
    showToast(internship.message);
    return;
  }
  internship.reviewDialog.mode = 'review';
  internship.reviewDialog.entity = entity;
  internship.reviewDialog.status = status;
  internship.reviewDialog.row = row;
  internship.reviewDialog.reason = status === 'accept' ? defaultReviewOpinion(entity, status) : '';
  trimReviewDialogMax();
  internship.reviewDialog.visible = true;
  loadInternshipDialogReviewDraft();
}

function openReopenDialog(entity, row) {
  if (!canRequestModification(row, entity)) {
    internship.message = '仅已通过数据可发起通过后修改';
    showToast(internship.message);
    return;
  }
  internship.reviewDialog.mode = 'reopen';
  internship.reviewDialog.entity = entity;
  internship.reviewDialog.status = 'modify';
  internship.reviewDialog.row = row;
  internship.reviewDialog.reason = '';
  trimReviewDialogMax();
  internship.reviewDialog.visible = true;
}

function closeReviewDialog() {
  internship.reviewDialog.visible = false;
  internship.reviewDialog.row = null;
}

function internshipReviewStatusOptions(entity) {
  if (entity === 'arrangement_change') {
    return ['accept', 'modify', 'refuse'].map(value => ({ value, label: reviewStatusLabels[value] }));
  }
  if (entity === 'delay') {
    return ['accept', 'refuse'].map(value => ({ value, label: reviewStatusLabels[value] }));
  }
  return ['accept', 'modify'].map(value => ({ value, label: reviewStatusLabels[value] }));
}

function setInternshipReviewStatus(status) {
  if (internship.reviewDialog.status === status) {
    return;
  }
  internship.reviewDialog.status = status;
  if (!internship.reviewDialog.reason || internship.reviewDialog.reason === defaultReviewOpinion(internship.reviewDialog.entity, 'accept')) {
    internship.reviewDialog.reason = status === 'accept' ? defaultReviewOpinion(internship.reviewDialog.entity, status) : '';
  }
  trimReviewDialogMax();
}

async function loadInternshipDialogReviewDraft() {
  const { entity, row, mode } = internship.reviewDialog;
  if (mode !== 'review' || !row?.id || internship.loading) {
    return;
  }
  internship.loading = true;
  try {
    const data = await fetchInternshipReviewDraft({ entity, id: row.id });
    const draft = data?.draft || null;
    if (draft?.review_status && internshipReviewStatusOptions(entity).some(item => item.value === draft.review_status)) {
      internship.reviewDialog.status = draft.review_status;
    }
    if (draft?.opinion) {
      internship.reviewDialog.reason = draft.opinion;
      trimReviewDialogMax();
    }
  } catch (error) {
    internship.message = error.message;
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

async function saveInternshipDialogReviewDraft() {
  const { entity, status, row, mode } = internship.reviewDialog;
  if (internship.loading || mode !== 'review' || !row?.id) {
    return;
  }
  trimReviewDialogMax();
  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipReviewDraft({
      entity,
      id: row.id,
      status,
      opinion: internship.reviewDialog.reason,
    });
    showToast('审核草稿已保存');
  } catch (error) {
    internship.message = error.message;
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

async function openTimelineDialog(entity, row) {
  internship.timelineDialog.visible = true;
  internship.timelineDialog.loading = true;
  internship.timelineDialog.entity = entity;
  internship.timelineDialog.row = row;
  internship.timelineDialog.title = `${reviewEntityName(entity)}流程记录`;
  internship.timelineDialog.subtitle = row.title || row.arrangement_title || row.student_name || String(row.id);
  internship.timelineDialog.items = [];
  internship.timelineDialog.cycles = [];
  internship.timelineDialog.message = '';
  try {
    const data = await fetchInternshipTimeline({ entity, id: row.id });
    internship.timelineDialog.cycles = data.cycles || [];
    internship.timelineDialog.items = data.items || [];
  } catch (error) {
    internship.timelineDialog.message = error.message;
  } finally {
    internship.timelineDialog.loading = false;
  }
}

async function openPracticeTimeline(module, panel, row) {
  practiceTimelineDialog.visible = true;
  practiceTimelineDialog.loading = true;
  practiceTimelineDialog.title = `${practiceEntityName(panel.entity)}流程记录`;
  practiceTimelineDialog.subtitle = row.title || row.name || String(row.id);
  practiceTimelineDialog.items = [];
  practiceTimelineDialog.cycles = [];
  practiceTimelineDialog.message = '';
  try {
    const data = panel.execution
      ? await fetchPracticeExecutionTimeline(module, { execution: panel.execution, id: row.id })
      : await fetchPracticeTimeline(module, { entity: panel.entity, id: row.id });
    practiceTimelineDialog.cycles = data.cycles || [];
    practiceTimelineDialog.items = data.items || data.records || [];
  } catch (error) {
    practiceTimelineDialog.message = error.message;
  } finally {
    practiceTimelineDialog.loading = false;
  }
}

function closePracticeTimeline() {
  practiceTimelineDialog.visible = false;
}

function openPracticeExecutionDialog(module, panelKey, row = null, context = {}) {
  const panel = practicePanelDefinitions.find(item => item.key === panelKey) || currentPracticePanel(module);
  const projectId = context.projectId
    || (panel.key === 'projects'
      ? row?.id
      : row?.project_id || row?.entity_id || practiceModule(module).options.projects[0]?.id || null);
  practiceExecutionDialog.visible = true;
  practiceExecutionDialog.module = module;
  practiceExecutionDialog.panel = panel.key;
  practiceExecutionDialog.execution = panel.execution || 'journal';
  practiceExecutionDialog.row = row;
  practiceExecutionDialog.form = {
    id: context.projectId ? null : (panel.execution && row?.id ? row.id : null),
    project_id: projectId,
    title: panel.execution ? (row?.title || context.projectTitle || '') : '',
    date: row?.date || '',
    content: row?.content || '',
    location: row?.location || '',
    longitude: row?.longitude || '',
    latitude: row?.latitude || '',
    accuracy: row?.accuracy || null,
    located_at: '',
    locating: false,
    gps_error: '',
    remark: row?.remark || '',
  };
  if (practiceExecutionDialog.execution === 'sign_in' && !practiceGpsReady.value) {
    locatePracticePosition();
  }
}

function closePracticeExecutionDialog() {
  practiceExecutionDialog.visible = false;
  practiceExecutionDialog.row = null;
}

async function submitPracticeExecution(status = 'wait') {
  const state = practiceModule(practiceExecutionDialog.module);
  const execution = practiceExecutionDialog.execution;
  if (state.loading) {
    return;
  }
  if (!practiceExecutionDialog.form.project_id) {
    state.message = '请选择项目';
    showToast(state.message);
    return;
  }
  if (execution !== 'sign_in' && !String(practiceExecutionDialog.form.content || '').trim()) {
    state.message = '请填写内容';
    showToast(state.message);
    return;
  }
  if (execution === 'sign_in' && !practiceGpsReady.value) {
    state.message = '请先获取 GPS 定位后再签到';
    showToast(state.message);
    return;
  }
  state.loading = true;
  state.message = '';
  try {
    await savePracticeExecution(practiceExecutionDialog.module, execution, {
      id: practiceExecutionDialog.form.id || undefined,
      project_id: practiceExecutionDialog.form.project_id,
      title: practiceExecutionDialog.form.title,
      date: practiceExecutionDialog.form.date,
      content: practiceExecutionDialog.form.content,
      location: practiceExecutionDialog.form.location,
      longitude: practiceExecutionDialog.form.longitude,
      latitude: practiceExecutionDialog.form.latitude,
      remark: practiceExecutionDialog.form.remark,
      status: execution === 'sign_in' ? 'signed' : status,
    });
    if (execution === 'sign_in' || status === 'wait') {
      closePracticeExecutionDialog();
    }
    state.panel = practiceExecutionDialog.panel;
    await loadPractice(practiceExecutionDialog.module);
    showToast(execution === 'sign_in' ? '签到已提交' : (status === 'wait' ? '已提交审核' : '草稿已保存'));
  } catch (error) {
    state.message = error.message;
    showToast(error.message);
  } finally {
    state.loading = false;
  }
}

function closeTimelineDialog() {
  internship.timelineDialog.visible = false;
}

function openPracticeReview(module, panel, row, status, mode = 'review') {
  practiceReviewDialog.visible = true;
  practiceReviewDialog.mode = mode;
  practiceReviewDialog.module = module;
  practiceReviewDialog.panel = panel.key;
  practiceReviewDialog.entity = panel.entity;
  practiceReviewDialog.status = status;
  practiceReviewDialog.row = row;
  practiceReviewDialog.reason = status === 'accept' ? '同意' : '';
  trimPracticeReviewMax();
  loadPracticeDialogReviewDraft();
}

function closePracticeReviewDialog() {
  practiceReviewDialog.visible = false;
  practiceReviewDialog.row = null;
}

function practiceReviewStatusOptions() {
  return ['accept', 'modify'].map(value => ({ value, label: reviewStatusLabels[value] }));
}

function setPracticeReviewStatus(status) {
  if (practiceReviewDialog.status === status) {
    return;
  }
  practiceReviewDialog.status = status;
  if (!practiceReviewDialog.reason || practiceReviewDialog.reason === '同意') {
    practiceReviewDialog.reason = status === 'accept' ? '同意' : '';
  }
  trimPracticeReviewMax();
}

function practiceReviewDraftPayload() {
  const panel = practicePanelDefinitions.find(item => item.key === practiceReviewDialog.panel);
  if (panel?.execution) {
    return {
      execution: panel.execution,
      id: practiceReviewDialog.row?.id,
    };
  }
  return {
    entity: practiceReviewDialog.entity,
    id: practiceReviewDialog.row?.id,
  };
}

async function loadPracticeDialogReviewDraft() {
  const state = practiceModule(practiceReviewDialog.module);
  if (practiceReviewDialog.mode !== 'review' || !practiceReviewDialog.row?.id || state.loading) {
    return;
  }
  state.loading = true;
  try {
    const data = await fetchPracticeReviewDraft(practiceReviewDialog.module, practiceReviewDraftPayload());
    const draft = data?.draft || null;
    if (draft?.review_status && practiceReviewStatusOptions().some(item => item.value === draft.review_status)) {
      practiceReviewDialog.status = draft.review_status;
    }
    if (draft?.opinion) {
      practiceReviewDialog.reason = draft.opinion;
      trimPracticeReviewMax();
    }
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

async function savePracticeReviewDraftFromDialog() {
  const state = practiceModule(practiceReviewDialog.module);
  if (state.loading || practiceReviewDialog.mode !== 'review' || !practiceReviewDialog.row?.id) {
    return;
  }
  trimPracticeReviewMax();
  state.loading = true;
  state.message = '';
  try {
    await savePracticeReviewDraft(practiceReviewDialog.module, {
      ...practiceReviewDraftPayload(),
      status: practiceReviewDialog.status,
      opinion: practiceReviewDialog.reason,
    });
    showToast('审核草稿已保存');
  } catch (error) {
    state.message = error.message;
    showToast(error.message);
  } finally {
    state.loading = false;
  }
}

async function confirmPracticeReview() {
  const state = practiceModule(practiceReviewDialog.module);
  if (state.loading) {
    return;
  }
  const error = validatePracticeReason(
    practiceReviewDialog.module,
    practiceReviewDialog.entity,
    practiceReviewDialog.status,
    practiceReviewDialog.reason,
    practiceReviewReasonLabel.value,
  );
  if (error) {
    state.message = error;
    showToast(error);
    return;
  }
  state.loading = true;
  state.message = '';
  try {
    const payload = {
      entity: practiceReviewDialog.entity,
      id: practiceReviewDialog.row.id,
      opinion: practiceReviewDialog.reason,
    };
    if (practiceReviewDialog.mode === 'reopen') {
      const panel = practicePanelDefinitions.find(item => item.key === practiceReviewDialog.panel);
      if (panel?.execution) {
        await requestPracticeExecutionModification(practiceReviewDialog.module, {
          execution: panel.execution,
          id: practiceReviewDialog.row.id,
          opinion: practiceReviewDialog.reason,
        });
      } else {
        await requestPracticeModification(practiceReviewDialog.module, payload);
      }
    } else {
      const panel = practicePanelDefinitions.find(item => item.key === practiceReviewDialog.panel);
      if (panel?.execution) {
        await reviewPracticeExecution(practiceReviewDialog.module, panel.execution, {
          id: practiceReviewDialog.row.id,
          status: practiceReviewDialog.status,
          opinion: practiceReviewDialog.reason,
        });
      } else {
        await reviewPracticeItem(practiceReviewDialog.module, {
          ...payload,
          status: practiceReviewDialog.status,
        });
      }
    }
    closePracticeReviewDialog();
    await loadPractice(practiceReviewDialog.module);
  } catch (error) {
    state.message = error.message;
    showToast(error.message);
  } finally {
    state.loading = false;
  }
}

async function confirmReviewDialog() {
  if (internship.loading) {
    return;
  }
  const { entity, status, row, mode } = internship.reviewDialog;
  if (!row?.id) {
    closeReviewDialog();
    return;
  }

  const error = validateReviewReason(entity, status, internship.reviewDialog.reason, reviewDialogReasonLabel.value);
  if (error) {
    internship.message = error;
    showToast(error);
    return;
  }

  if (mode === 'reopen') {
    if (!canRequestModification(row, entity)) {
      internship.message = '仅已通过数据可发起通过后修改';
      showToast(internship.message);
      return;
    }
    await requestModification(entity, row, internship.reviewDialog.reason);
    return;
  }
  if (!canReviewRow(row, entity)) {
    internship.message = '仅待审核数据可处理';
    showToast(internship.message);
    return;
  }

  if (entity === 'application') {
    await reviewApplication(row, status, internship.reviewDialog.reason);
    return;
  }
  if (entity === 'arrangement_change') {
    await reviewArrangementChange(row, status, internship.reviewDialog.reason);
    return;
  }
  if (entity === 'plan') {
    await reviewPlan(row, status, internship.reviewDialog.reason);
    return;
  }
  if (entity === 'delay') {
    await reviewDelay(row, status, internship.reviewDialog.reason);
    return;
  }
  await reviewWork(entity, row, status, internship.reviewDialog.reason);
}

async function reviewApplication(row, status, opinion) {
  internship.loading = true;
  internship.message = '';
  try {
    await reviewInternshipApplication({
      id: row.id,
      status,
      opinion: opinion || defaultReviewOpinion('application', status),
    });
    closeReviewDialog();
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

async function reviewArrangementChange(row, status, opinion) {
  internship.loading = true;
  internship.message = '';
  try {
    await reviewInternshipArrangementChange({
      id: row.id,
      status,
      opinion: opinion || defaultReviewOpinion('arrangement_change', status),
    });
    closeReviewDialog();
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

async function reviewWork(type, row, status, opinion) {
  internship.loading = true;
  internship.message = '';
  try {
    const payload = {
      id: row.id,
      status,
      opinion: opinion || defaultReviewOpinion(type, status),
    };
    if (type === 'journal') {
      await reviewInternshipJournal(payload);
    } else if (type === 'report') {
      await reviewInternshipReport(payload);
    } else if (isInternshipDocumentReviewEntity(type)) {
      await reviewInternshipDocument({ ...payload, entity: type });
    } else {
      throw new Error('该业务不支持当前审核入口');
    }
    closeReviewDialog();
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

async function reviewPlan(row, status, opinion) {
  internship.loading = true;
  internship.message = '';
  try {
    await reviewInternshipPlan({
      id: row.id,
      approval_level: row.next_approval_level || undefined,
      status,
      opinion: opinion || defaultReviewOpinion('plan', status),
    });
    closeReviewDialog();
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

async function reviewDelay(row, status, opinion) {
  internship.loading = true;
  internship.message = '';
  try {
    await reviewInternshipDelay({
      id: row.id,
      status,
      opinion: opinion || defaultReviewOpinion('delay', status),
    });
    closeReviewDialog();
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

async function requestModification(entity, row, opinion) {
  internship.loading = true;
  internship.message = '';
  try {
    await requestInternshipModification({
      entity,
      id: row.id,
      opinion,
    });
    closeReviewDialog();
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
    showToast(error.message);
  } finally {
    internship.loading = false;
  }
}

function defaultReviewOpinion(type, status) {
  if (status === 'accept') {
    return '同意';
  }
  if (type === 'delay' && status === 'refuse') {
    return '不同意延期';
  }
  if (type === 'report') {
    return '请补充完善报告内容';
  }
  return '请补充修改后再提交';
}

function reviewRule(entity, status) {
  return internship.options.review_rules?.[entity]?.[status]
    || defaultInternshipReviewRules[entity]?.[status]
    || { min: 0, max: null };
}

function reviewRuleText(entity, status, label = '意见') {
  const rule = reviewRule(entity, status);
  if (!rule.min && !rule.max) {
    return `${label}字数不限制`;
  }
  if (rule.min && rule.max) {
    return `${label}需 ${rule.min}-${rule.max} 字`;
  }
  if (rule.min) {
    return `${label}至少 ${rule.min} 字`;
  }
  return `${label}最多 ${rule.max} 字`;
}

function reviewRuleMax(entity, status) {
  const max = reviewRule(entity, status).max;
  return max || null;
}

function reviewRuleMaxText(entity, status) {
  return reviewRuleMax(entity, status) || '不限';
}

function practiceRule(module, entity, status) {
  return practiceModule(module).options.review_rules?.[entity]?.[status]
    || { min: 0, max: null };
}

function practiceRuleText(module, entity, status, label = '意见') {
  const rule = practiceRule(module, entity, status);
  if (!rule.min && !rule.max) {
    return `${label}字数不限制`;
  }
  if (rule.min && rule.max) {
    return `${label}需 ${rule.min}-${rule.max} 字`;
  }
  if (rule.min) {
    return `${label}至少 ${rule.min} 字`;
  }
  return `${label}最多 ${rule.max} 字`;
}

function practiceRuleMax(module, entity, status) {
  return practiceRule(module, entity, status).max || null;
}

function practiceRuleMaxText(module, entity, status) {
  return practiceRuleMax(module, entity, status) || '不限';
}

function trimPracticeReviewMax() {
  const max = practiceRuleMax(practiceReviewDialog.module, practiceReviewDialog.entity, practiceReviewDialog.status);
  if (!max) {
    return;
  }
  const chars = Array.from(String(practiceReviewDialog.reason || ''));
  if (chars.length > max) {
    practiceReviewDialog.reason = chars.slice(0, max).join('');
  }
}

function validatePracticeReason(module, entity, status, reason, label = null) {
  const rule = practiceRule(module, entity, status);
  const length = textLength(reason);
  const fieldLabel = label || (status === 'modify' ? '退回原因' : '审核意见');
  if (rule.min && length < rule.min) {
    return `${fieldLabel}至少 ${rule.min} 字`;
  }
  if (rule.max && length > rule.max) {
    return `${fieldLabel}最多 ${rule.max} 字`;
  }
  return '';
}

function reviewEntityName(entity) {
  const names = {
    arrangement: '实习任务',
    arrangement_change: '任务变更',
    application: '特殊申请',
    sign_in: '实习签到',
    journal: '实习日志',
    report: '实习报告',
    score: '实习成绩',
    plan: '实习计划',
    delay: '延期申请',
    insurance: '保险记录',
    safety_letter: '安全承诺',
    syllabus_guide: '大纲指导书',
    implementation_sheet: '实施表',
    teacher_work_report: '教师工作报告',
    inspection: '巡查记录',
  };
  return names[entity] || '审核事项';
}

function isInternshipDocumentReviewEntity(entity) {
  return ['syllabus_guide', 'implementation_sheet', 'teacher_work_report', 'inspection'].includes(entity);
}

function practiceEntityName(entity) {
  const names = {
    plan: '教学计划',
    schedule: '课表安排',
    syllabus: '大纲',
    lessonPlan: '教案',
    gradeRule: '成绩比例',
    score: '成绩',
    reflection: '反思报告',
  };
  return names[entity] || '实践事项';
}

function isRejectReviewStatus(status) {
  return ['modify', 'refuse'].includes(status);
}

function canReviewRow(row, entity) {
  if (!row || row.status !== 'wait') {
    return false;
  }
  if (entity === 'plan') {
    return canReviewInternshipPlan.value && canReviewPlanLevel(row);
  }
  if (entity === 'arrangement_change') {
    return isAdminRole.value && (hasPermission('internship:manage') || hasPermission('internship:approve'));
  }
  if (entity === 'application') {
    if (!canReviewInternship.value) {
      return false;
    }
    if (isTeacherRole.value) {
      return ['pending', 'wait'].includes(row.teacher_status);
    }
    if (isAdminRole.value) {
      return ['pending', 'wait'].includes(row.admin_status);
    }
    return false;
  }
  return canReviewInternship.value;
}

function canReviewPlanLevel(row) {
  if (roleType.value === 'super_admin') {
    return true;
  }
  const roles = Array.isArray(row.next_approval_role_types) ? row.next_approval_role_types : [];
  return roles.includes(roleType.value);
}

function canRequestModification(row, entity) {
  if (!row || row.status !== 'accept') {
    return false;
  }
  if (![
    'application',
    'journal',
    'report',
    'plan',
    'delay',
    'syllabus_guide',
    'implementation_sheet',
    'teacher_work_report',
    'inspection',
  ].includes(entity)) {
    return false;
  }
  if (entity === 'plan') {
    return canReviewInternshipPlan.value;
  }
  return canReviewInternship.value;
}

function detailItem(label, value) {
  const text = String(value ?? '').trim();
  return text && text !== '-' ? { label, value: text } : null;
}

function reviewTargetDetails(entity, row) {
  if (!row) {
    return [];
  }
  const student = joinFact([row.student_name, row.student_num]);
  const arrangement = row.arrangement_title || (row.arrangement_id ? `任务ID ${row.arrangement_id}` : '');
  const changePayload = arrangementChangePayload(row);
  const base = [
    detailItem('审核模块', reviewEntityName(entity)),
    detailItem('学生', student || (row.student_id ? `学生ID ${row.student_id}` : '')),
    detailItem('实习任务', arrangement),
  ];

  const details = {
    arrangement_change: [
      detailItem('课程计划', row.course_name),
      detailItem('原负责老师', row.teacher_name),
      detailItem('拟变更任务', changePayload.title),
      detailItem('拟变更时间', dateRangeText(changePayload.start_date, changePayload.end_date)),
      detailItem('生效任务', row.new_arrangement_title),
      detailItem('新任务编号', row.new_task_no),
      detailItem('新负责老师', row.new_teacher_name),
      detailItem('变更原因', previewText(row.reason, 100)),
      detailItem('提交人', row.submitter_name),
    ],
    application: [
      detailItem('届次', row.grade_name),
      detailItem('学院专业', joinFact([row.dep_name, row.profession_name])),
      detailItem('教师审核', statusText(row.teacher_status)),
      detailItem('管理审核', statusText(row.admin_status)),
      detailItem('申请备注', previewText(row.remark, 80)),
    ],
    journal: [
      detailItem('届次', row.grade_name),
      detailItem('日志标题', row.title),
      detailItem('日志日期', row.date || row.created_at),
      detailItem('内容摘要', previewText(row.content, 100)),
    ],
    report: [
      detailItem('届次', row.grade_name),
      detailItem('报告标题', row.title),
      detailItem('提交时间', row.submitted_at || row.created_at),
      detailItem('内容摘要', previewText(row.content, 100)),
    ],
    plan: [
      // 暂时隐藏学期字段，后续需要时恢复。
      // detailItem('学期', row.semester),
      detailItem('学院', row.dep_name),
      detailItem('提交人', row.submitter_name),
      detailItem('审核进度', row.approval_progress_text),
      detailItem('当前节点', row.current_approval_name),
      detailItem('计划摘要', planContentText(row.plan_content, 100)),
    ],
    delay: [
      detailItem('届次', row.grade_name),
      detailItem('延期类型', delayConfigText(row.config_key)),
      detailItem('申请延期至', row.requested_date),
      detailItem('申请原因', previewText(row.reason, 100)),
    ],
  };

  return [
    ...base,
    ...(details[entity] || []),
    detailItem('当前状态', statusText(row.status)),
  ].filter(Boolean);
}

const reviewDialogTargetDetails = computed(() => (
  reviewTargetDetails(internship.reviewDialog.entity, internship.reviewDialog.row)
));
const internshipTimelineCycles = computed(() => normalizeTimelineCycles(
  internship.timelineDialog.cycles || [],
  internship.timelineDialog.items || [],
));
const practiceTimelineCycles = computed(() => normalizeTimelineCycles(
  practiceTimelineDialog.cycles || [],
  practiceTimelineDialog.items || [],
));

function workflowActionText(action) {
  const names = {
    submit: '提交',
    review: '审核',
    teacher_review: '教师审核',
    admin_review: '管理员审核',
    modify_after_accept: '通过后修改',
  };
  return names[action] || action || '记录';
}

function normalizeTimelineCycles(cycles, items) {
  if (Array.isArray(cycles) && cycles.length) {
    return cycles;
  }

  if (!Array.isArray(items) || !items.length) {
    return [];
  }

  const normalized = [];
  let current = null;
  let sequence = 0;

  items.forEach((item) => {
    if (item.record && item.record.action === 'submit') {
      sequence += 1;
      current = {
        kind: 'cycle',
        sequence,
        created_at: item.created_at,
        record: item.record,
        branches: [],
      };
      normalized.push(current);
      return;
    }

    if (!current) {
      sequence += 1;
      current = {
        kind: 'cycle',
        sequence,
        created_at: item.created_at,
        record: null,
        branches: [],
      };
      normalized.push(current);
    }

    current.branches.push({
      kind: 'branch',
      type: item.kind || 'recording',
      created_at: item.created_at,
      record: item.record || null,
      review: item.review || null,
      reviews: item.reviews || (item.review ? [item.review] : []),
    });
  });

  return normalized;
}

function timelineCycleKey(cycle, index) {
  return `cycle-${cycle.record?.id || cycle.sequence || index}`;
}

function timelineCycleTitle(cycle) {
  const prefix = cycle.sequence ? `第 ${cycle.sequence} 次提交` : '提交记录';
  if (cycle.record) {
    return `${prefix}${timelineOperatorText(cycle.record)}：${statusText(cycle.record.from_status)} -> ${statusText(cycle.record.to_status)}`;
  }
  return cycle.sequence ? `第 ${cycle.sequence} 次流程记录` : '流程记录';
}

function timelineCycleTime(cycle) {
  return cycle.created_at || cycle.record?.created_at || '-';
}

function timelineCycleContent(cycle) {
  if (!cycle.record) {
    return '无提交内容';
  }
  return timelineContent({ record: cycle.record });
}

function timelineBranchKey(branch, index) {
  return `branch-${branch.record?.id || branch.review?.id || index}`;
}

function timelineBranchTitle(branch) {
  if (branch.record) {
    return `${workflowActionText(branch.record.action)}${timelineOperatorText(branch.record)}：${statusText(branch.record.from_status)} -> ${statusText(branch.record.to_status)}`;
  }
  const review = branch.review || branch.reviews?.[0];
  return `审核${timelineReviewerText(review)}：${statusText(review?.status)}`;
}

function timelineBranchTime(branch) {
  return branch.created_at || branch.record?.created_at || branch.review?.created_at || '-';
}

function timelineBranchReviews(branch) {
  if (Array.isArray(branch.reviews) && branch.reviews.length) {
    return branch.reviews;
  }
  return branch.review ? [branch.review] : [];
}

function timelineBranchContent(branch) {
  if (timelineBranchReviews(branch).length) {
    return '';
  }
  if (branch.record) {
    return branch.record.content || branch.record.opinion || '';
  }
  return branch.review?.opinion || '';
}

function timelineOperatorText(record) {
  const name = record?.operator_name || record?.operator_login_name;
  return name ? `（${name}）` : '';
}

function timelineReviewerText(review) {
  const name = review?.reviewer_name || review?.teacher_name || review?.reviewer_login_name || review?.teacher_num;
  return name ? `（${name}）` : '';
}

function isModifyAfterAcceptBranch(branch) {
  return branch.record?.action === 'modify_after_accept' || branch.review?.status === 'modify';
}

function timelineContent(item) {
  if (item.record) {
    if (item.record.action === 'submit' && isGenericSubmitContent(item.record.content)) {
      return submissionSnapshotText(internship.timelineDialog.entity, internship.timelineDialog.row);
    }
    return item.record.content || item.record.opinion || '-';
  }
  return item.review?.opinion || '-';
}

function isGenericSubmitContent(value) {
  return [
    '提交特殊申请',
    '提交补充申请',
    '提交实习日志',
    '提交实习报告',
    '提交延期申请',
    '提交实习计划',
    '提交实习签到',
  ].includes(String(value || '').trim());
}

function submissionSnapshotText(entity, row) {
  if (!row) {
    return '-';
  }
  const textMap = {
    application: row.remark || row.arrangement_title,
    sign_in: [row.date, row.sign_time, row.location].filter(Boolean).join(' / '),
    journal: [row.title, row.content].filter(Boolean).join('：'),
    report: [row.title, row.content].filter(Boolean).join('：'),
    plan: planContentText(row.plan_content, 120),
    delay: row.reason,
  };
  return previewText(textMap[entity] || '', 160) || '-';
}

const reviewDialogTitle = computed(() => {
  if (internship.reviewDialog.mode === 'reopen') {
    return `通过后修改${reviewEntityName(internship.reviewDialog.entity)}`;
  }
  const action = isRejectReviewStatus(internship.reviewDialog.status) ? '退回' : '通过';
  return `${action}${reviewEntityName(internship.reviewDialog.entity)}`;
});

const reviewDialogReasonLabel = computed(() => {
  if (internship.reviewDialog.mode === 'reopen') {
    return '修改理由';
  }
  return isRejectReviewStatus(internship.reviewDialog.status) ? '退回原因' : '审核意见';
});

const reviewDialogRuleText = computed(() => (
  reviewRuleText(internship.reviewDialog.entity, internship.reviewDialog.status, reviewDialogReasonLabel.value)
));

function textLength(value) {
  return Array.from(String(value || '').trim()).length;
}

function trimReviewDialogMax() {
  const max = reviewRuleMax(internship.reviewDialog.entity, internship.reviewDialog.status);
  if (!max) {
    return;
  }
  const chars = Array.from(String(internship.reviewDialog.reason || ''));
  if (chars.length > max) {
    internship.reviewDialog.reason = chars.slice(0, max).join('');
  }
}

function validateReviewReason(entity, status, reason, label = null) {
  const rule = reviewRule(entity, status);
  const length = textLength(reason);
  const fieldLabel = label || (isRejectReviewStatus(status) ? '退回原因' : '审核意见');
  if (rule.min && length < rule.min) {
    return `${fieldLabel}至少 ${rule.min} 字`;
  }
  if (rule.max && length > rule.max) {
    return `${fieldLabel}最多 ${rule.max} 字`;
  }
  return '';
}

function selectScorePair() {
  const pair = internship.lists.pairs.items.find(item => item.id === internship.forms.score.pair_id);
  if (!pair) {
    return;
  }
  internship.forms.score.student_id = pair.student_id;
  internship.forms.score.arrangement_id = pair.arrangement_id;
  fillScoreFormFromExisting(pair.student_id, pair.arrangement_id, pair);
}

function fillScoreFormFromExisting(studentId, arrangementId, fallback = null) {
  const score = internship.lists.scores.items.find(item =>
    Number(item.student_id) === Number(studentId) && Number(item.arrangement_id) === Number(arrangementId));
  const source = score || (fallback?.score_id ? fallback : null);
  if (!source) {
    internship.forms.score.sign_in_score = '';
    internship.forms.score.journal_score = '';
    internship.forms.score.report_score = '';
    internship.forms.score.enterprise_score = '';
    return;
  }
  internship.forms.score.sign_in_score = source.sign_in_score ?? '';
  internship.forms.score.journal_score = source.journal_score ?? '';
  internship.forms.score.report_score = source.report_score ?? '';
  internship.forms.score.enterprise_score = source.enterprise_score ?? '';
}

async function submitScore() {
  selectScorePair();
  if (!internship.forms.score.student_id || !internship.forms.score.arrangement_id) {
    internship.message = '请先选择学生';
    return;
  }

  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipScore({
      student_id: internship.forms.score.student_id,
      arrangement_id: internship.forms.score.arrangement_id,
      sign_in_score: numericOrNull(internship.forms.score.sign_in_score),
      journal_score: numericOrNull(internship.forms.score.journal_score),
      report_score: numericOrNull(internship.forms.score.report_score),
      enterprise_score: numericOrNull(internship.forms.score.enterprise_score),
    });
    internship.message = '成绩已保存';
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

function numericOrNull(value) {
  return value === '' || value === null || value === undefined ? null : Number(value);
}

function arrangementChangePayload(row) {
  const payload = row?.payload || {};
  if (typeof payload === 'string') {
    try {
      const parsed = JSON.parse(payload);
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
      return {};
    }
  }
  return payload && typeof payload === 'object' ? payload : {};
}

function arrangementTypeText(value) {
  const names = {
    cognition_internal: '认知校内',
    cognition_external: '认知校外',
    major_internal: '专业校内',
    major_external: '专业校外',
    production: '生产实习',
    graduation: '毕业实习',
  };
  return names[value] || value || '-';
}

function scoreRuleText(value) {
  const names = {
    average: '平均分',
    sum: '累计分',
    weighted: '按学分加权',
    manual: '人工核定',
  };
  return names[value] || value || '-';
}

function courseScoreStatusText(value) {
  return value === 'complete' ? '已汇总' : '待汇总';
}

function organizeModeText(value) {
  const names = {
    centralized: '集中',
    distributed: '分散',
    autonomous: '自主',
  };
  return names[value] || value || '-';
}

function signTypeText(value) {
  const names = {
    gps: '定位',
    qrcode: '扫码',
    manual: '补录',
  };
  return names[value] || value || '-';
}

function inspectionResultText(value) {
  const names = {
    pass: '通过',
    fail: '不通过',
  };
  return names[value] || value || '-';
}

function openStudentSubmitSection(section) {
  internship.submitSection = internship.submitSection === section ? '' : section;
  if (internship.submitSection === 'sign' && !signGpsReady.value) {
    locateSignPosition();
  }
}

function currentArrangement() {
  const formArrangementId = {
    journal: internship.forms.journal.arrangement_id,
    report: internship.forms.report.arrangement_id,
    delay: internship.forms.delay.arrangement_id,
    sign: internship.forms.sign.arrangement_id,
    safety: internship.forms.safety.arrangement_id,
  }[internship.submitSection];
  const arrangementId = Number(
    formArrangementId
    || internship.forms.sign.arrangement_id
    || internship.forms.journal.arrangement_id
    || internship.forms.report.arrangement_id
    || internship.forms.application.arrangement_id
    || internship.forms.delay.arrangement_id
    || internship.forms.safety.arrangement_id
    || 0,
  );
  return internship.options.arrangements.find(item => Number(item.id) === arrangementId) || internship.options.arrangements[0] || null;
}

function stageDeadlineText(key) {
  const value = stageDeadlineDate(key);
  if (!value) {
    return '截止时间未配置';
  }
  return isStageExpired(key) ? `已截止 ${value}` : `截止 ${value}`;
}

function stageDeadlineDetailText(key) {
  const label = delayConfigText(key);
  const value = stageDeadlineDate(key);
  if (!value) {
    return `${label}暂未配置截止时间`;
  }
  return isStageExpired(key) ? `${label}已于 ${value} 截止` : `${label}截止时间 ${value}`;
}

function stageDeadlineDate(key) {
  const configured = String(internship.options.deadline_configs?.[key] || '').slice(0, 10);
  const arrangementEnd = String(currentArrangement()?.end_date || '').slice(0, 10);
  const value = configured || arrangementEnd;
  return /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : '';
}

function isStageExpired(key) {
  const value = stageDeadlineDate(key);
  return Boolean(value && value < formatDateKey(new Date()));
}

function openDelayForStage(key) {
  const arrangementId = currentArrangement()?.id || internship.forms.sign.arrangement_id || internship.forms.delay.arrangement_id || null;
  internship.submitSection = 'delay';
  internship.forms.delay.config_key = key;
  internship.forms.delay.arrangement_id = arrangementId;
  internship.forms.sign.arrangement_id = arrangementId || internship.forms.sign.arrangement_id;
}

function coordinateText(value) {
  if (!hasCoordinateValue(value)) {
    return '-';
  }
  const number = Number(value);
  return number.toFixed(6);
}

function hasCoordinateValue(value) {
  return value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value));
}

function resetSignPosition() {
  resetGpsForm(internship.forms.sign);
}

function resetPracticePosition() {
  resetGpsForm(practiceExecutionDialog.form);
}

function resetGpsForm(form) {
  form.location = '';
  form.longitude = null;
  form.latitude = null;
  form.accuracy = null;
  form.located_at = '';
  form.gps_error = '';
}

function geolocationErrorText(error) {
  if (!error) {
    return '无法获取当前位置';
  }
  if (error.code === 1) {
    return '定位权限未授权，请允许浏览器访问位置';
  }
  if (error.code === 2) {
    return '当前位置不可用，请检查定位服务';
  }
  if (error.code === 3) {
    return '定位超时，请重新定位';
  }
  return error.message || '无法获取当前位置';
}

async function locateSignPosition() {
  await locateGpsPosition(internship.forms.sign, resetSignPosition);
}

async function locatePracticePosition() {
  await locateGpsPosition(practiceExecutionDialog.form, resetPracticePosition);
}

async function locateGpsPosition(form, resetPosition) {
  if (!navigator.geolocation) {
    form.gps_error = '当前浏览器不支持 GPS 定位';
    showToast(form.gps_error);
    return;
  }

  form.locating = true;
  form.gps_error = '';
  try {
    const position = await new Promise((resolve, reject) => {
      navigator.geolocation.getCurrentPosition(resolve, reject, {
        enableHighAccuracy: true,
        timeout: 12000,
        maximumAge: 0,
      });
    });
    const { latitude, longitude, accuracy } = position.coords;
    form.latitude = Number(latitude.toFixed(6));
    form.longitude = Number(longitude.toFixed(6));
    form.accuracy = accuracy ? Math.round(accuracy) : null;
    form.located_at = new Date().toISOString();
    form.location = `GPS ${coordinateText(form.latitude)}, ${coordinateText(form.longitude)}`;
  } catch (error) {
    resetPosition();
    form.gps_error = geolocationErrorText(error);
    showToast(form.gps_error);
  } finally {
    form.locating = false;
  }
}

function delayConfigOptions() {
  return [
    { value: 'journal_deadline', label: '实习日志' },
    { value: 'report_deadline', label: '实习报告' },
  ];
}

function delayConfigText(value) {
  return delayConfigOptions().find(item => item.value === value)?.label || value || '-';
}

function practiceSourceText(value) {
  const names = {
    jw: '教务拉取',
    manual: '手动填报',
  };
  return names[value] || value || '-';
}

function practiceRatioText(value) {
  if (Array.isArray(value)) {
    return value.map(item => `${item.name || item.label || '项目'}${item.weight || item.ratio || ''}%`).join('，');
  }
  if (value && typeof value === 'object') {
    return Object.entries(value).map(([key, val]) => `${key}${val}%`).join('，');
  }
  return value ? String(value) : '';
}

function statusText(value) {
  return resolveStatusText(value);
}

function resetMobileLocalState() {
  resetMessageState();
  resetSupportState();
  resetInternshipState();
  resetPracticeState();
}

function expireMobileSession(message) {
  state.context = {};
  state.permissions = [];
  state.menus = [];
  state.dataScope = null;
  state.error = message;
  resetMobileNavigationToHome();
  resetMobileLocalState();
}

async function refreshMobilePage() {
  await load();
  await loadSwitchableAccounts();
  if (activeTab.value === 'message') {
    await loadMessages(1);
    return;
  }
  if (activeTab.value === 'doc') {
    await loadMobileDocs(1);
    return;
  }
  if (activeTab.value === 'templateLib') {
    await loadMobileTemplates(1);
    return;
  }
  if (activeTab.value === 'internship') {
    await loadInternship();
    return;
  }
  if (isPracticeTab(activeTab.value)) {
    await loadPractice(activeTab.value);
    return;
  }
  await loadMobileSupportCategories();
  await loadMessageSummary();
}

async function handleMobilePullRefresh() {
  try {
    await refreshMobilePage();
  } finally {
    mobileRefreshing.value = false;
  }
}

async function refreshMobileSession(resetWorkspace = false) {
  if (resetWorkspace) {
    resetMobileLocalState();
  }

  await load();
  if (!isLoggedIn.value) {
    resetAccountChoices();
    return;
  }

  await loadSwitchableAccounts();
  await loadMessageSummary();
  await loadMobileSupportCategories();
  await loadInternship();
  if (isPracticeTab(activeTab.value)) {
    await loadPractice(activeTab.value);
  }
}

function openMobileMessages() {
  if (isLoggedIn.value && activeTab.value !== 'message') {
    activeTab.value = 'message';
  }
}

provideInternshipContext({
  canEditStudentWork,
  canLoadMore,
  canReviewInternship,
  closeReviewDialog,
  closeTimelineDialog,
  confirmReviewDialog,
  coordinateText,
  currentManageListConfig,
  currentReviewListConfig,
  dateRangeText,
  delayConfigOptions,
  delayConfigText,
  editStudentWork,
  getMobileListConfig,
  handleMobileListAction,
  internship,
  internshipPanels,
  internshipReviewStatusOptions,
  internshipSummaries,
  internshipTimelineCycles,
  internshipWorkbenchCells,
  isAdminRole,
  isModifyAfterAcceptBranch,
  isStageExpired,
  isStudentRole,
  isTeacherRole,
  joinFact,
  listTotal,
  loadMoreInternshipList,
  locateSignPosition,
  manageListTabs,
  mobileListActions,
  mobileListFacts,
  mobileListRows,
  mobileListSelectFilters,
  mobileListTitle,
  mobileListValue,
  openDelayForStage,
  openStudentSubmitSection,
  openTimelineDialog,
  reloadInternshipList,
  reloadScorePairs,
  resetInternshipListFilters,
  reviewDialogReasonLabel,
  reviewDialogRuleText,
  reviewDialogTargetDetails,
  reviewDialogTitle,
  reviewListTabs,
  reviewRuleMax,
  reviewRuleMaxText,
  saveInternshipDialogReviewDraft,
  selectScorePair,
  setInternshipReviewStatus,
  signAccuracyText,
  signGpsHint,
  signGpsReady,
  signGpsTitle,
  signMapUrl,
  signTypeText,
  stageDeadlineDetailText,
  stageDeadlineText,
  statusText,
  studentSubmitCards,
  submitApplication,
  submitDelay,
  submitJournal,
  submitReport,
  submitSafetyLetter,
  submitScore,
  submitSignIn,
  uploadInternshipAttachments,
  uploadSafetyFinal,
  removeInternshipAttachment,
  openSafetyTemplate,
  currentReportIsGraduation,
  switchInternshipPanel,
  switchMobileList,
  textLength,
  timelineBranchContent,
  timelineBranchKey,
  timelineBranchReviews,
  timelineBranchTime,
  timelineBranchTitle,
  timelineCycleContent,
  timelineCycleKey,
  timelineCycleTime,
  timelineCycleTitle,
  trimReviewDialogMax,
  updateInternshipListFilter,
});

providePracticeContext({
  canLoadMorePractice,
  closePracticeExecutionDialog,
  closePracticeReviewDialog,
  closePracticeTimeline,
  confirmPracticeReview,
  coordinateText,
  currentPracticePanel,
  currentPracticeRows,
  handlePracticeAction,
  isModifyAfterAcceptBranch,
  isStudentRole,
  loadMorePracticeList,
  locatePracticePosition,
  practiceAccuracyText,
  practiceExecutionDialog,
  practiceExecutionDialogSubtitle,
  practiceExecutionDialogTitle,
  practiceFilters,
  practiceFlowSteps,
  practiceGpsHint,
  practiceGpsReady,
  practiceGpsTitle,
  practiceListTotal,
  practiceMapUrl,
  practiceModule,
  practiceModuleName,
  practicePanels,
  practiceReviewDialog,
  practiceReviewDialogRuleText,
  practiceReviewDialogTitle,
  practiceReviewReasonLabel,
  practiceReviewStatusOptions,
  practiceReviewTargetDetails,
  practiceRowActions,
  practiceRowFacts,
  practiceRowTitle,
  practiceRowValue,
  practiceRuleMax,
  practiceRuleMaxText,
  practiceSummaries,
  practiceTimelineCycles,
  practiceTimelineDialog,
  reloadPracticeList,
  resetPracticeListFilters,
  savePracticeReviewDraftFromDialog,
  setPracticeReviewStatus,
  statusText,
  submitPracticeExecution,
  switchPracticePanel,
  textLength,
  timelineBranchContent,
  timelineBranchKey,
  timelineBranchReviews,
  timelineBranchTime,
  timelineBranchTitle,
  timelineCycleContent,
  timelineCycleKey,
  timelineCycleTime,
  timelineCycleTitle,
  trimPracticeReviewMax,
  updatePracticeListFilter,
});

watch(activeTab, (tab) => {
  if (tab === 'internship') {
    loadInternship();
  }
  if (isPracticeTab(tab)) {
    loadPractice(tab);
  }
  if (tab === 'message') {
    loadMessages(1);
  }
  if (tab === 'doc') {
    loadMobileDocs(1);
  }
  if (tab === 'templateLib') {
    loadMobileTemplates(1);
  }
});

watch(roleType, () => {
  if (!['home', 'mine', 'message'].includes(activeTab.value) && !visibleMobileModules.value.some(module => module.key === activeTab.value)) {
    activeTab.value = 'home';
  }
  const panels = internshipPanels.value.map(item => item.key);
  if (!panels.includes(internship.panel)) {
    internship.panel = panels[0] || 'workbench';
  }
  normalizeInternshipListViews();
  normalizePracticePanel('training');
  normalizePracticePanel('lab');
});

watch(visibleMobileModules, () => {
  if (!['home', 'mine', 'message'].includes(activeTab.value) && !visibleMobileModules.value.some(module => module.key === activeTab.value)) {
    activeTab.value = 'home';
  }
});

</script>
