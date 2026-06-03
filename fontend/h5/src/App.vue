<template>
  <main class="mobile-shell">
    <header class="mobile-top">
      <div>
        <span>实践管理系统</span>
        <strong>{{ currentPage.title }}</strong>
      </div>
      <div class="mobile-actions">
        <button :disabled="state.loading" @click="load">
          <RefreshCw :size="18" />
        </button>
        <button v-if="isLoggedIn" :disabled="loginState.loading" @click="submitLogout">
          <LogOut :size="18" />
        </button>
      </div>
    </header>

    <section class="context-strip">
      <div>
        <small>当前角色</small>
        <strong>{{ roleText }}</strong>
      </div>
      <div>
        <small>数据范围</small>
        <strong>{{ scopeText }}</strong>
      </div>
    </section>

    <van-notice-bar
      v-if="state.error"
      color="#8a5a00"
      background="#fff3d8"
      left-icon="warning-o"
      :text="state.error"
    />

    <section class="page-content">
      <section v-if="!isLoggedIn" class="mobile-login">
        <header>
          <UserRound :size="30" />
          <div>
            <strong>账号登录</strong>
            <span>{{ schoolText }}</span>
          </div>
        </header>
        <label>
          <span>账号</span>
          <input v-model="loginForm.login_name" autocomplete="username" placeholder="admin">
        </label>
        <label>
          <span>密码</span>
          <input v-model="loginForm.password" autocomplete="current-password" placeholder="admin123456" type="password">
        </label>
        <button :disabled="loginState.loading" @click="submitLogin">
          <LogIn :size="17" />
          登录
        </button>
        <small v-if="loginState.message">{{ loginState.message }}</small>
      </section>

      <template v-else-if="activeTab === 'home'">
        <section class="summary-band">
          <div v-for="item in summaries" :key="item.name">
            <strong>{{ item.value }}</strong>
            <span>{{ item.name }}</span>
          </div>
        </section>

        <section class="module-list">
          <button
            v-for="module in modules"
            :key="module.key"
            :disabled="!hasPermission(module.permission)"
            @click="activeTab = module.key"
          >
            <span :class="module.theme">
              <component :is="module.icon" :size="21" />
            </span>
            <div>
              <strong>{{ module.title }}</strong>
              <small>{{ module.desc }}</small>
            </div>
            <ChevronRight :size="18" />
          </button>
        </section>
      </template>

      <template v-else-if="activeTab === 'mine'">
        <section class="profile-panel">
          <UserRound :size="34" />
          <div>
            <strong>{{ userText }}</strong>
            <span>{{ schoolText }}</span>
          </div>
        </section>

        <van-cell-group inset>
          <van-cell title="账号 ID" :value="state.context.account_id || '-'" />
          <van-cell title="角色 ID" :value="state.context.role_id || '-'" />
          <van-cell title="权限码数量" :value="state.permissions.length || '-'" />
          <van-cell title="菜单数量" :value="state.menus.length || '-'" />
        </van-cell-group>
      </template>

      <template v-else-if="activeTab === 'internship'">
        <section class="module-head">
          <span class="blue">
            <BriefcaseBusiness :size="25" />
          </span>
          <div>
            <h1>{{ internshipRoleTitle }}</h1>
            <p>{{ internshipRoleDesc }}</p>
          </div>
        </section>

        <van-notice-bar
          v-if="internship.message"
          color="#8a5a00"
          background="#fff3d8"
          left-icon="warning-o"
          :text="internship.message"
        />

        <section class="internship-action-tabs">
          <button
            v-for="item in internshipPanels"
            :key="item.key"
            :class="{ active: internship.panel === item.key }"
            @click="switchInternshipPanel(item.key)"
          >
            <component :is="item.icon" :size="18" />
            <span>{{ item.name }}</span>
          </button>
        </section>

        <section v-if="internship.panel === 'workbench'" class="summary-band internship-summary">
          <div v-for="item in internshipSummaries" :key="item.name">
            <strong>{{ item.value }}</strong>
            <span>{{ item.name }}</span>
          </div>
        </section>

        <template v-if="isStudentRole && internship.panel === 'apply'">
          <section class="mobile-card form-card">
            <header>
              <ClipboardList :size="20" />
              <strong>实习申请</strong>
            </header>
            <label>
              <span>实习安排</span>
              <select v-model.number="internship.forms.application.arrangement_id">
                <option v-for="item in internship.options.arrangements" :key="item.id" :value="item.id">
                  {{ item.title }}
                </option>
              </select>
            </label>
            <label>
              <span>指导教师</span>
              <select v-model.number="internship.forms.application.teacher_id">
                <option v-for="teacher in internship.options.teachers" :key="teacher.teacher_id" :value="teacher.teacher_id">
                  {{ teacher.teacher_name }}
                </option>
              </select>
            </label>
            <label>
              <span>备注</span>
              <textarea v-model="internship.forms.application.remark" rows="3" />
            </label>
            <van-button block type="primary" :loading="internship.loading" @click="submitApplication">
              提交申请
            </van-button>
          </section>
          <section class="mobile-card">
            <header>
              <FileClock :size="20" />
              <strong>申请记录</strong>
            </header>
            <van-cell
              v-for="row in internship.lists.applications.items"
              :key="row.id"
              :title="row.arrangement_title"
              :label="`${row.student_name || '-'} / ${statusText(row.teacher_status)} / ${statusText(row.admin_status)}`"
              :value="statusText(row.status)"
            />
          </section>
        </template>

        <template v-if="isStudentRole && internship.panel === 'submit'">
          <section class="mobile-card form-card">
            <header>
              <MapPin :size="20" />
              <strong>签到</strong>
            </header>
            <label>
              <span>实习安排</span>
              <select v-model.number="internship.forms.sign.arrangement_id">
                <option v-for="item in internship.options.arrangements" :key="item.id" :value="item.id">
                  {{ item.title }}
                </option>
              </select>
            </label>
            <label>
              <span>位置</span>
              <input v-model="internship.forms.sign.location" placeholder="当前位置或实习单位">
            </label>
            <van-button block type="primary" :loading="internship.loading" @click="submitSignIn">
              提交签到
            </van-button>
          </section>

          <section class="mobile-card form-card">
            <header>
              <FileClock :size="20" />
              <strong>实习日志</strong>
            </header>
            <label>
              <span>标题</span>
              <input v-model="internship.forms.journal.title">
            </label>
            <label>
              <span>内容</span>
              <textarea v-model="internship.forms.journal.content" rows="4" />
            </label>
            <van-button block type="primary" :loading="internship.loading" @click="submitJournal">
              提交日志
            </van-button>
          </section>

          <section class="mobile-card form-card">
            <header>
              <FileText :size="20" />
              <strong>实习报告</strong>
            </header>
            <label>
              <span>标题</span>
              <input v-model="internship.forms.report.title">
            </label>
            <label>
              <span>内容</span>
              <textarea v-model="internship.forms.report.content" rows="4" />
            </label>
            <van-button block type="primary" :loading="internship.loading" @click="submitReport">
              提交报告
            </van-button>
          </section>
        </template>

        <template v-if="canReviewInternship && internship.panel === 'review'">
          <section class="mobile-card form-card">
            <header>
              <CheckCircle2 :size="20" />
              <strong>审核意见</strong>
            </header>
            <label>
              <span>意见</span>
              <input v-model="internship.forms.review.opinion">
            </label>
          </section>

          <section class="mobile-card">
            <header>
              <ClipboardList :size="20" />
              <strong>实习申请</strong>
            </header>
            <van-cell
              v-for="row in internship.lists.applications.items"
              :key="row.id"
              :title="row.student_name || row.student_num"
              :label="row.arrangement_title"
              :value="statusText(row.status)"
            >
              <template #right-icon>
                <div class="cell-actions">
                  <button @click.stop="reviewApplication(row, 'accept')">通过</button>
                  <button @click.stop="reviewApplication(row, 'modify')">退回</button>
                </div>
              </template>
            </van-cell>
          </section>

          <section v-if="isTeacherRole" class="mobile-card">
            <header>
              <FileClock :size="20" />
              <strong>日志评阅</strong>
            </header>
            <van-cell
              v-for="row in internship.lists.journals.items"
              :key="row.id"
              :title="row.title"
              :label="`${row.student_name || '-'} / ${row.date || '-'}`"
              :value="statusText(row.status)"
            >
              <template #right-icon>
                <div class="cell-actions">
                  <button @click.stop="reviewWork('journal', row, 'accept')">通过</button>
                  <button @click.stop="reviewWork('journal', row, 'modify')">退回</button>
                </div>
              </template>
            </van-cell>
          </section>

          <section v-if="isTeacherRole" class="mobile-card">
            <header>
              <FileText :size="20" />
              <strong>报告评阅</strong>
            </header>
            <van-cell
              v-for="row in internship.lists.reports.items"
              :key="row.id"
              :title="row.title"
              :label="row.student_name || '-'"
              :value="statusText(row.status)"
            >
              <template #right-icon>
                <div class="cell-actions">
                  <button @click.stop="reviewWork('report', row, 'accept')">通过</button>
                  <button @click.stop="reviewWork('report', row, 'modify')">退回</button>
                </div>
              </template>
            </van-cell>
          </section>
        </template>

        <template v-if="isTeacherRole && internship.panel === 'score'">
          <section class="mobile-card form-card">
            <header>
              <GraduationCap :size="20" />
              <strong>成绩录入</strong>
            </header>
            <label>
              <span>学生</span>
              <select v-model.number="internship.forms.score.pair_id" @change="selectScorePair">
                <option v-for="pair in internship.lists.pairs.items" :key="pair.id" :value="pair.id">
                  {{ pair.student_name }} / {{ pair.arrangement_title }}
                </option>
              </select>
            </label>
            <label><span>签到成绩</span><input v-model="internship.forms.score.sign_in_score" type="number"></label>
            <label><span>日志成绩</span><input v-model="internship.forms.score.journal_score" type="number"></label>
            <label><span>报告成绩</span><input v-model="internship.forms.score.report_score" type="number"></label>
            <label><span>企业成绩</span><input v-model="internship.forms.score.enterprise_score" type="number"></label>
            <van-button block type="primary" :loading="internship.loading" @click="submitScore">
              保存成绩
            </van-button>
          </section>
        </template>

        <template v-if="isAdminRole && internship.panel === 'manage'">
          <section class="mobile-card">
            <header>
              <CalendarCheck :size="20" />
              <strong>实习安排</strong>
            </header>
            <van-cell
              v-for="row in internship.lists.arrangements.items"
              :key="row.id"
              :title="row.title"
              :label="`${row.semester || '-'} / ${row.dep_name || '全校'}`"
              :value="statusText(row.status)"
            />
          </section>
          <section class="mobile-card">
            <header>
              <UsersRound :size="20" />
              <strong>指导关系</strong>
            </header>
            <van-cell
              v-for="row in internship.lists.pairs.items"
              :key="row.id"
              :title="row.student_name"
              :label="`${row.teacher_name || '-'} / ${row.arrangement_title || '-'}`"
              :value="statusText(row.status)"
            />
          </section>
        </template>

        <section v-if="internship.panel === 'workbench'" class="mobile-card">
          <header>
            <BriefcaseBusiness :size="20" />
            <strong>当前任务</strong>
          </header>
          <van-cell
            v-for="item in internshipWorkbenchCells"
            :key="item.title"
            :title="item.title"
            :label="item.label"
            :value="item.value"
          />
        </section>
      </template>

      <template v-else>
        <section class="module-head">
          <span :class="currentPage.theme">
            <component :is="currentPage.icon" :size="25" />
          </span>
          <div>
            <h1>{{ currentPage.title }}</h1>
            <p>{{ currentPage.desc }}</p>
          </div>
        </section>

        <section class="action-grid">
          <van-button
            v-for="action in moduleActions"
            :key="action.code"
            block
            :disabled="!hasPermission(action.code)"
          >
            <component :is="action.icon" :size="18" />
            <span>{{ action.name }}</span>
          </van-button>
        </section>

        <van-cell-group inset>
          <van-cell title="模块权限" :value="hasPermission(currentPage.permission) ? '允许访问' : '无权限'" />
          <van-cell title="业务流程" :value="currentPage.flow" />
          <van-cell title="数据来源" value="学校业务库" />
          <van-cell title="范围策略" :value="scopeText" />
        </van-cell-group>
      </template>
    </section>

    <van-tabbar v-model="activeTab" safe-area-inset-bottom>
      <van-tabbar-item name="home">
        <template #icon><Home :size="20" /></template>
        首页
      </van-tabbar-item>
      <van-tabbar-item name="internship">
        <template #icon><BriefcaseBusiness :size="20" /></template>
        实习
      </van-tabbar-item>
      <van-tabbar-item name="training">
        <template #icon><Workflow :size="20" /></template>
        实训
      </van-tabbar-item>
      <van-tabbar-item name="lab">
        <template #icon><FlaskConical :size="20" /></template>
        实验
      </van-tabbar-item>
      <van-tabbar-item name="mine">
        <template #icon><UserRound :size="20" /></template>
        我的
      </van-tabbar-item>
    </van-tabbar>
  </main>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import {
  BriefcaseBusiness,
  CalendarCheck,
  CheckCircle2,
  ChevronRight,
  ClipboardList,
  FileClock,
  FileText,
  FlaskConical,
  GraduationCap,
  Home,
  LogIn,
  LogOut,
  MapPin,
  RefreshCw,
  Send,
  UsersRound,
  UserRound,
  Workflow,
} from '@lucide/vue';
import { useMobilePermissions } from './composables/useMobilePermissions';
import {
  fetchInternshipApplications,
  fetchInternshipArrangements,
  fetchInternshipJournals,
  fetchInternshipOptions,
  fetchInternshipOverview,
  fetchInternshipPairs,
  fetchInternshipReports,
  fetchInternshipScores,
  reviewInternshipApplication,
  reviewInternshipJournal,
  reviewInternshipReport,
  saveInternshipApplication,
  saveInternshipJournal,
  saveInternshipReport,
  saveInternshipScore,
  saveInternshipSignIn,
  login as loginApi,
  logout as logoutApi,
} from './api/system';

const { state, hasPermission, load } = useMobilePermissions();
const activeTab = ref('home');
const loginForm = reactive({
  login_name: 'admin',
  password: 'admin123456',
});
const loginState = reactive({
  loading: false,
  message: '',
});

const internship = reactive({
  loading: false,
  message: '',
  panel: 'workbench',
  overview: emptyInternshipOverview(),
  options: emptyInternshipOptions(),
  lists: {
    arrangements: emptyPagedList(),
    applications: emptyPagedList(),
    pairs: emptyPagedList(),
    journals: emptyPagedList(),
    reports: emptyPagedList(),
    scores: emptyPagedList(),
  },
  forms: {
    application: {
      arrangement_id: null,
      teacher_id: null,
      remark: '',
    },
    sign: {
      arrangement_id: null,
      location: '',
    },
    journal: {
      title: '',
      content: '',
    },
    report: {
      title: '',
      content: '',
    },
    review: {
      opinion: '',
    },
    score: {
      pair_id: null,
      student_id: null,
      arrangement_id: null,
      sign_in_score: '',
      journal_score: '',
      report_score: '',
      enterprise_score: '',
    },
  },
});

const modules = [
  {
    key: 'internship',
    title: '实习管理',
    desc: '申请、签到、日志和指导关系入口',
    icon: BriefcaseBusiness,
    theme: 'blue',
    permission: 'internship:view',
    flow: '按实习文档开发',
  },
  {
    key: 'training',
    title: '实训管理',
    desc: '保留模块框架，流程待确认',
    icon: Workflow,
    theme: 'teal',
    permission: 'training:view',
    flow: '待确认',
  },
  {
    key: 'lab',
    title: '实验管理',
    desc: '保留模块框架，流程待确认',
    icon: FlaskConical,
    theme: 'green',
    permission: 'lab:view',
    flow: '待确认',
  },
];

const currentPage = computed(() => {
  if (activeTab.value === 'home') {
    return { title: '首页', desc: '移动端工作台', theme: 'blue', icon: Home, permission: '', flow: '-' };
  }
  if (activeTab.value === 'mine') {
    return { title: '我的', desc: '账号与权限上下文', theme: 'gray', icon: UserRound, permission: '', flow: '-' };
  }
  return modules.find(item => item.key === activeTab.value) || modules[0];
});

const summaries = computed(() => [
  { name: '菜单', value: state.menus.length || '-' },
  { name: '权限码', value: state.permissions.length || '-' },
  { name: '角色', value: state.context.role_type ? '已识别' : '-' },
]);

const moduleActions = computed(() => [
  { name: '查看', code: currentPage.value.permission, icon: ClipboardList },
  { name: '提交', code: `${currentPage.value.key}:submit`, icon: Send },
  { name: '确认', code: `${currentPage.value.key}:confirm`, icon: CheckCircle2 },
]);

const isLoggedIn = computed(() => Boolean(state.context.account_id));
const roleType = computed(() => state.context.role_type || '');
const isStudentRole = computed(() => roleType.value === 'student');
const isTeacherRole = computed(() => roleType.value === 'teacher');
const isAdminRole = computed(() => ['super_admin', 'school_admin', 'college_admin', 'profession_admin'].includes(roleType.value));
const canReviewInternship = computed(() => hasPermission('internship:approve'));
const roleText = computed(() => state.context.role_type || state.context.role_id || '未登录');
const userText = computed(() => state.context.user_name || (state.context.user_id ? `用户 ${state.context.user_id}` : '未登录'));
const schoolText = computed(() => (isLoggedIn.value ? '学校业务库' : '学校业务系统'));
const scopeText = computed(() => {
  if (!state.dataScope?.filter) {
    return '未注入';
  }
  const keys = Object.keys(state.dataScope.filter);
  return keys.length ? keys.join(' / ') : '全校';
});
const internshipRoleTitle = computed(() => {
  if (isStudentRole.value) {
    return '学生实习';
  }
  if (isTeacherRole.value) {
    return '教师指导';
  }
  if (isAdminRole.value) {
    return '实习管理';
  }
  return '实习';
});
const internshipRoleDesc = computed(() => {
  if (isStudentRole.value) {
    return '申请、签到、日志和报告提交';
  }
  if (isTeacherRole.value) {
    return '审核申请、评阅材料和录入成绩';
  }
  if (isAdminRole.value) {
    return '查看安排、申请、配对和数据状态';
  }
  return '按当前角色展示可用实习功能';
});
const internshipPanels = computed(() => {
  if (isStudentRole.value) {
    return [
      { key: 'workbench', name: '概况', icon: Home },
      { key: 'apply', name: '申请', icon: ClipboardList },
      { key: 'submit', name: '提交', icon: Send },
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
const internshipSummaries = computed(() => [
  { name: '安排', value: internship.overview.arrangements || 0 },
  { name: '待审', value: internship.overview.applications_waiting || 0 },
  { name: '关系', value: internship.overview.active_pairs || 0 },
]);
const internshipWorkbenchCells = computed(() => {
  if (isStudentRole.value) {
    return [
      { title: '可申请安排', label: '当前角色可见的实习安排', value: internship.options.arrangements.length || '-' },
      { title: '我的申请', label: '申请记录', value: internship.lists.applications.pagination.total || 0 },
      { title: '指导关系', label: '通过后生成', value: internship.lists.pairs.pagination.total || 0 },
    ];
  }
  if (isTeacherRole.value) {
    return [
      { title: '待审申请', label: '学生选择当前教师后的申请', value: internship.lists.applications.pagination.total || 0 },
      { title: '待评日志', label: '学生提交的实习日志', value: internship.overview.journals_waiting || 0 },
      { title: '待评报告', label: '学生提交的实习报告', value: internship.overview.reports_waiting || 0 },
    ];
  }
  return [
    { title: '实习安排', label: '全校实习安排', value: internship.overview.arrangements || 0 },
    { title: '待审申请', label: '需要管理员审核', value: internship.overview.applications_waiting || 0 },
    { title: '有效配对', label: '学生与指导教师关系', value: internship.overview.active_pairs || 0 },
  ];
});

function emptyPagedList() {
  return {
    items: [],
    pagination: {
      page: 1,
      page_size: 20,
      total: 0,
    },
  };
}

function emptyInternshipOverview() {
  return {
    arrangements: 0,
    applications_waiting: 0,
    active_pairs: 0,
    journals_waiting: 0,
    reports_waiting: 0,
    today_sign_ins: 0,
  };
}

function emptyInternshipOptions() {
  return {
    arrangements: [],
    teachers: [],
    report_templates: [],
  };
}

function setPagedList(key, data) {
  internship.lists[key].items = data.items || [];
  internship.lists[key].pagination = {
    ...internship.lists[key].pagination,
    ...(data.pagination || {}),
  };
}

function applyDefaultInternshipSelection() {
  const firstArrangement = internship.options.arrangements[0];
  const firstTeacher = internship.options.teachers[0];
  if (firstArrangement) {
    internship.forms.application.arrangement_id ||= firstArrangement.id;
    internship.forms.sign.arrangement_id ||= firstArrangement.id;
  }
  if (firstTeacher) {
    internship.forms.application.teacher_id ||= firstTeacher.teacher_id;
  }
}

async function loadInternship() {
  if (!isLoggedIn.value || !hasPermission('internship:view')) {
    return;
  }

  internship.loading = true;
  internship.message = '';
  try {
    const [overview, options] = await Promise.all([
      fetchInternshipOverview(),
      fetchInternshipOptions(),
    ]);
    internship.overview = {
      ...emptyInternshipOverview(),
      ...(overview || {}),
    };
    internship.options = {
      ...emptyInternshipOptions(),
      ...(options || {}),
    };
    applyDefaultInternshipSelection();
    await loadInternshipPanelData();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function loadInternshipPanelData() {
  const params = { page: 1, page_size: 20 };
  if (internship.panel === 'workbench') {
    const [applications, pairs] = await Promise.all([
      fetchInternshipApplications(params),
      fetchInternshipPairs(params),
    ]);
    setPagedList('applications', applications);
    setPagedList('pairs', pairs);
    return;
  }
  if (internship.panel === 'apply') {
    setPagedList('applications', await fetchInternshipApplications(params));
    return;
  }
  if (internship.panel === 'review') {
    const [applications, journals, reports] = await Promise.all([
      fetchInternshipApplications(params),
      fetchInternshipJournals(params),
      fetchInternshipReports(params),
    ]);
    setPagedList('applications', applications);
    setPagedList('journals', journals);
    setPagedList('reports', reports);
    return;
  }
  if (internship.panel === 'score') {
    const [pairs, scores] = await Promise.all([
      fetchInternshipPairs({ page: 1, page_size: 100 }),
      fetchInternshipScores(params),
    ]);
    setPagedList('pairs', pairs);
    setPagedList('scores', scores);
    const firstPair = internship.lists.pairs.items[0];
    if (firstPair && !internship.forms.score.pair_id) {
      internship.forms.score.pair_id = firstPair.id;
      selectScorePair();
    }
    return;
  }
  if (internship.panel === 'manage') {
    const [arrangements, applications, pairs] = await Promise.all([
      fetchInternshipArrangements(params),
      fetchInternshipApplications(params),
      fetchInternshipPairs(params),
    ]);
    setPagedList('arrangements', arrangements);
    setPagedList('applications', applications);
    setPagedList('pairs', pairs);
  }
}

function switchInternshipPanel(panel) {
  internship.panel = panel;
  loadInternship();
}

async function submitApplication() {
  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipApplication({
      arrangement_id: internship.forms.application.arrangement_id,
      type: 'centralized',
      status: 'wait',
      teacher_ids: internship.forms.application.teacher_id ? [internship.forms.application.teacher_id] : [],
      remark: internship.forms.application.remark,
    });
    internship.forms.application.remark = '';
    internship.message = '申请已提交';
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function submitSignIn() {
  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipSignIn({
      arrangement_id: internship.forms.sign.arrangement_id,
      location: internship.forms.sign.location || '移动端签到',
    });
    internship.forms.sign.location = '';
    internship.message = '签到已提交';
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function submitJournal() {
  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipJournal({
      arrangement_id: internship.forms.sign.arrangement_id,
      title: internship.forms.journal.title,
      content: internship.forms.journal.content,
      status: 'wait',
    });
    internship.forms.journal.title = '';
    internship.forms.journal.content = '';
    internship.message = '日志已提交';
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function submitReport() {
  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipReport({
      arrangement_id: internship.forms.sign.arrangement_id,
      template_id: internship.options.report_templates[0]?.id || null,
      title: internship.forms.report.title,
      content: internship.forms.report.content,
      status: 'wait',
    });
    internship.forms.report.title = '';
    internship.forms.report.content = '';
    internship.message = '报告已提交';
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function reviewApplication(row, status) {
  internship.loading = true;
  internship.message = '';
  try {
    await reviewInternshipApplication({
      id: row.id,
      status,
      opinion: internship.forms.review.opinion || defaultReviewOpinion('application', status),
    });
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function reviewWork(type, row, status) {
  internship.loading = true;
  internship.message = '';
  try {
    const payload = {
      id: row.id,
      status,
      opinion: internship.forms.review.opinion || defaultReviewOpinion(type, status),
    };
    if (type === 'journal') {
      await reviewInternshipJournal(payload);
    } else {
      await reviewInternshipReport(payload);
    }
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

function defaultReviewOpinion(type, status) {
  if (status === 'accept') {
    return '同意';
  }
  if (type === 'report') {
    return '请补充完善报告内容';
  }
  return '请补充修改后再提交';
}

function selectScorePair() {
  const pair = internship.lists.pairs.items.find(item => item.id === internship.forms.score.pair_id);
  if (!pair) {
    return;
  }
  internship.forms.score.student_id = pair.student_id;
  internship.forms.score.arrangement_id = pair.arrangement_id;
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

function statusText(value) {
  const names = {
    draft: '草稿',
    wait: '待审核',
    accept: '已通过',
    modify: '需修改',
    enabled: '启用',
    disabled: '停用',
    pending: '待处理',
    active: '有效',
    signed: '已签署',
  };
  return names[value] || value || '-';
}

async function submitLogin() {
  loginState.loading = true;
  loginState.message = '';
  try {
    await loginApi({
      login_name: loginForm.login_name,
      password: loginForm.password,
      client: 'H5',
    });
    await load();
    await loadInternship();
  } catch (error) {
    loginState.message = error.message;
  } finally {
    loginState.loading = false;
  }
}

async function submitLogout() {
  loginState.loading = true;
  loginState.message = '';
  try {
    await logoutApi();
    activeTab.value = 'home';
    internship.panel = 'workbench';
    internship.message = '';
    await load();
  } catch (error) {
    loginState.message = error.message;
  } finally {
    loginState.loading = false;
  }
}

watch(activeTab, (tab) => {
  if (tab === 'internship') {
    loadInternship();
  }
});

watch(roleType, () => {
  const panels = internshipPanels.value.map(item => item.key);
  if (!panels.includes(internship.panel)) {
    internship.panel = panels[0] || 'workbench';
  }
});

onMounted(async () => {
  await load();
  await loadInternship();
});
</script>
