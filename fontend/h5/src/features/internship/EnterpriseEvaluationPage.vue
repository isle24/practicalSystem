<template>
  <main class="enterprise-evaluation-page">
    <header class="enterprise-evaluation-header">
      <div class="enterprise-evaluation-mark"><Building2 :size="22" /></div>
      <div>
        <strong>毕业实习企业评价</strong>
        <span>成都锦城学院</span>
      </div>
    </header>

    <section v-if="!verified" class="enterprise-verification-card">
      <div class="enterprise-verification-icon"><ShieldCheck :size="30" /></div>
      <div class="enterprise-verification-title">
        <strong>验证企业导师身份</strong>
        <span>请输入邀请绑定的手机号，验证后评价所指导的学生。</span>
      </div>
      <label class="app-field">
        <span>企业导师手机号</span>
        <input v-model.trim="mobile" inputmode="numeric" maxlength="11" autocomplete="tel" placeholder="请输入绑定手机号">
      </label>
      <label class="app-field enterprise-code-field">
        <span>短信验证码</span>
        <span class="enterprise-code-control">
          <input v-model.trim="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="请输入 6 位验证码">
          <AppButton variant="secondary" size="small" :disabled="countdown > 0" :loading="sending" @click="sendCode">
            {{ countdown > 0 ? `${countdown} 秒` : '获取验证码' }}
          </AppButton>
        </span>
      </label>
      <p v-if="message" class="enterprise-evaluation-message">{{ message }}</p>
      <AppButton block size="large" :loading="loading" @click="verifyCode">验证并进入</AppButton>
    </section>

    <template v-else>
      <section class="enterprise-context-card">
        <div>
          <span>企业导师</span>
          <strong>{{ context.session.mentor_name || '-' }}</strong>
        </div>
        <div>
          <span>实习任务</span>
          <strong>{{ context.session.arrangement_title || '-' }}</strong>
        </div>
        <small>评价提交后不可再次修改，请核对学生和评分内容。</small>
      </section>

      <section class="enterprise-student-section">
        <header>
          <div>
            <strong>学生评价</strong>
            <span>{{ submittedCount }} / {{ context.students.length }} 已完成</span>
          </div>
          <button type="button" :disabled="loading" aria-label="刷新" @click="loadContext">
            <RefreshCw :size="18" :class="{ rotating: loading }" />
          </button>
        </header>

        <div v-if="context.students.length" class="enterprise-student-list">
          <AppListCard
            v-for="student in context.students"
            :key="student.student_id"
            :title="student.student_name || '未命名学生'"
            :subtitle="student.student_num || '-'"
            :status="student.evaluation_status || 'pending'"
            :status-label="student.evaluation_status === 'submitted' ? '已评价' : '待评价'"
            :meta="student.evaluation_status === 'submitted' ? [`企业评分：${student.total_score ?? '-'} 分`, `提交时间：${student.submitted_at || '-'}`] : ['等待企业导师评价']"
          >
            <template #actions>
              <AppButton
                v-if="student.evaluation_status !== 'submitted'"
                size="small"
                @click="openEvaluation(student)"
              >
                开始评价
              </AppButton>
              <AppButton v-else variant="quiet" size="small" @click="openSubmitted(student)">查看评价</AppButton>
            </template>
          </AppListCard>
        </div>
        <div v-else class="enterprise-empty">当前邀请没有可评价学生</div>
      </section>
      <p v-if="message" class="enterprise-evaluation-message is-page">{{ message }}</p>
    </template>

    <AppSheet
      v-model="dialog.visible"
      :title="dialog.readonly ? '企业评价详情' : '填写企业评价'"
      :subtitle="[dialog.student.student_name, dialog.student.student_num].filter(Boolean).join(' / ')"
      :close-on-overlay="!submitting"
    >
      <div class="enterprise-evaluation-form">
        <div class="enterprise-rule-summary">
          <span>评价总分</span>
          <strong>{{ totalScore }} / {{ maxTotalScore }}</strong>
        </div>
        <label v-for="item in displayCriteria" :key="item.code" class="app-field">
          <span>{{ item.name }}（最高 {{ item.max_score }} 分）</span>
          <input
            v-model="scores[item.code]"
            type="number"
            inputmode="decimal"
            min="0"
            :max="item.max_score"
            step="0.5"
            :readonly="dialog.readonly"
            :placeholder="`请输入 0-${item.max_score}`"
          >
        </label>
        <label class="app-field">
          <span>企业评价意见</span>
          <textarea v-model="comment" rows="5" maxlength="5000" :readonly="dialog.readonly" placeholder="请填写学生实习表现和评价意见" />
          <small>{{ comment.length }} / 5000</small>
        </label>
      </div>
      <template #footer>
        <AppButton variant="secondary" :disabled="submitting" @click="dialog.visible = false">关闭</AppButton>
        <AppButton v-if="!dialog.readonly" :loading="submitting" @click="submitEvaluation">确认提交</AppButton>
      </template>
    </AppSheet>
  </main>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Building2, RefreshCw, ShieldCheck } from '@lucide/vue';
import { showConfirmDialog, showToast } from 'vant';
import {
  fetchEnterpriseEvaluationContext,
  sendEnterpriseEvaluationCode,
  submitEnterpriseEvaluation,
  verifyEnterpriseEvaluationCode,
} from '../../api/system';
import AppButton from '../../components/ui/AppButton.vue';
import AppListCard from '../../components/ui/AppListCard.vue';
import AppSheet from '../../components/ui/AppSheet.vue';

const props = defineProps({
  token: { type: String, required: true },
});

const storageKey = `enterprise-evaluation-session:${props.token}`;
const mobile = ref('');
const code = ref('');
const sessionToken = ref('');
const loading = ref(false);
const sending = ref(false);
const submitting = ref(false);
const countdown = ref(0);
const message = ref('');
const context = reactive({
  session: {},
  rule: { total_score: 0, items: [] },
  students: [],
});
const dialog = reactive({ visible: false, readonly: false, student: {} });
const scores = reactive({});
const comment = ref('');
let countdownTimer = null;

const verified = computed(() => Boolean(sessionToken.value && context.session.session_token));
const submittedCount = computed(() => context.students.filter(item => item.evaluation_status === 'submitted').length);
const totalScore = computed(() => Number(Object.values(scores).reduce((sum, value) => sum + (Number(value) || 0), 0).toFixed(2)));
const displayCriteria = computed(() => {
  if (dialog.readonly && Array.isArray(dialog.student.criteria_json) && dialog.student.criteria_json.length) {
    return dialog.student.criteria_json;
  }
  return context.rule.items || [];
});
const maxTotalScore = computed(() => {
  if (!dialog.readonly) return Number(context.rule.total_score || 0);
  return Number(displayCriteria.value.reduce((total, item) => total + Number(item.max_score || 0), 0).toFixed(2));
});

onMounted(async () => {
  sessionToken.value = sessionStorage.getItem(storageKey) || '';
  if (sessionToken.value) {
    await loadContext(true);
  }
});

onBeforeUnmount(() => {
  if (countdownTimer) window.clearInterval(countdownTimer);
});

async function sendCode() {
  if (sending.value || countdown.value > 0) return;
  if (!/^1\d{10}$/.test(mobile.value)) {
    message.value = '请输入正确的 11 位手机号';
    return;
  }
  sending.value = true;
  message.value = '';
  try {
    const data = await sendEnterpriseEvaluationCode({ token: props.token, mobile: mobile.value });
    startCountdown(60);
    showToast(`验证码已发送至 ${data.mobile_masked || '绑定手机'}`);
  } catch (error) {
    message.value = error.message;
  } finally {
    sending.value = false;
  }
}

async function verifyCode() {
  if (loading.value) return;
  if (!/^1\d{10}$/.test(mobile.value) || !/^\d{6}$/.test(code.value)) {
    message.value = '请输入正确的手机号和 6 位验证码';
    return;
  }
  loading.value = true;
  message.value = '';
  try {
    const data = await verifyEnterpriseEvaluationCode({ token: props.token, mobile: mobile.value, sms_code: code.value });
    sessionToken.value = data.session_token || '';
    sessionStorage.setItem(storageKey, sessionToken.value);
    await loadContext(true);
  } catch (error) {
    message.value = error.message;
  } finally {
    loading.value = false;
  }
}

async function loadContext(force = false) {
  if (!sessionToken.value || (loading.value && !force)) return;
  loading.value = true;
  message.value = '';
  try {
    const data = await fetchEnterpriseEvaluationContext({ session_token: sessionToken.value });
    context.session = data.session || {};
    context.rule = data.rule || { total_score: 0, items: [] };
    context.students = data.students || [];
  } catch (error) {
    sessionToken.value = '';
    sessionStorage.removeItem(storageKey);
    Object.assign(context, { session: {}, rule: { total_score: 0, items: [] }, students: [] });
    message.value = error.message;
  } finally {
    loading.value = false;
  }
}

function openEvaluation(student) {
  Object.keys(scores).forEach(key => delete scores[key]);
  context.rule.items.forEach(item => { scores[item.code] = ''; });
  comment.value = '';
  Object.assign(dialog, { visible: true, readonly: false, student });
}

function openSubmitted(student) {
  Object.keys(scores).forEach(key => delete scores[key]);
  (student.criteria_json || []).forEach(item => { scores[item.code] = item.score ?? ''; });
  comment.value = student.evaluation_comment || '';
  Object.assign(dialog, { visible: true, readonly: true, student });
}

async function submitEvaluation() {
  if (submitting.value) return;
  const criteria = context.rule.items.map(item => ({ ...item, score: scores[item.code] }));
  const invalid = criteria.find(item => scores[item.code] === '' || Number(item.score) < 0 || Number(item.score) > Number(item.max_score));
  if (invalid) {
    showToast(`请正确填写${invalid.name}评分`);
    return;
  }
  if (!comment.value.trim()) {
    showToast('请填写企业评价意见');
    return;
  }
  try {
    await showConfirmDialog({
      title: '确认提交企业评价',
      message: `学生：${dialog.student.student_name || '-'}\n总分：${totalScore.value} 分\n提交后不可修改。`,
      confirmButtonText: '确认提交',
      cancelButtonText: '再检查一下',
    });
  } catch {
    return;
  }
  submitting.value = true;
  message.value = '';
  try {
    await submitEnterpriseEvaluation({
      session_token: sessionToken.value,
      student_id: dialog.student.student_id,
      criteria: criteria.map(item => ({ code: item.code, score: Number(item.score) })),
      total_score: totalScore.value,
      comment: comment.value.trim(),
    });
    dialog.visible = false;
    showToast('企业评价已提交');
    await loadContext();
  } catch (error) {
    message.value = error.message;
    showToast(error.message);
  } finally {
    submitting.value = false;
  }
}

function startCountdown(seconds) {
  countdown.value = seconds;
  if (countdownTimer) window.clearInterval(countdownTimer);
  countdownTimer = window.setInterval(() => {
    countdown.value -= 1;
    if (countdown.value <= 0) {
      window.clearInterval(countdownTimer);
      countdownTimer = null;
    }
  }, 1000);
}
</script>

<style scoped>
.enterprise-evaluation-page {
  min-height: 100dvh;
  padding: calc(18px + env(safe-area-inset-top)) 14px calc(28px + env(safe-area-inset-bottom));
  color: var(--app-text);
  background: var(--app-bg);
}

.enterprise-evaluation-header {
  width: min(100%, 620px);
  min-height: 52px;
  display: flex;
  align-items: center;
  gap: 11px;
  margin: 0 auto 18px;
}

.enterprise-evaluation-header > div:last-child {
  min-width: 0;
  display: grid;
  gap: 2px;
}

.enterprise-evaluation-header strong { font-size: 18px; }
.enterprise-evaluation-header span { color: var(--app-text-secondary); font-size: 12px; }

.enterprise-evaluation-mark,
.enterprise-verification-icon {
  display: grid;
  place-items: center;
  color: #fff;
  background: var(--app-primary);
}

.enterprise-evaluation-mark { width: 44px; height: 44px; border-radius: 8px; }

.enterprise-verification-card,
.enterprise-context-card,
.enterprise-student-section {
  width: min(100%, 620px);
  margin: 0 auto 12px;
  border: 1px solid var(--app-line);
  border-radius: var(--app-radius);
  background: var(--app-surface);
  box-shadow: var(--app-shadow-sm);
}

.enterprise-verification-card {
  display: grid;
  gap: 16px;
  padding: 22px 18px;
}

.enterprise-verification-icon { width: 56px; height: 56px; border-radius: 8px; }

.enterprise-verification-title { display: grid; gap: 5px; }
.enterprise-verification-title strong { font-size: 20px; }
.enterprise-verification-title span { color: var(--app-text-secondary); font-size: 13px; line-height: 1.6; }

.enterprise-code-control {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px;
}

.enterprise-code-control .app-button { min-width: 112px; }

.enterprise-evaluation-message {
  margin: -4px 0 0;
  color: var(--app-danger);
  font-size: 13px;
  line-height: 1.5;
}

.enterprise-evaluation-message.is-page { width: min(100%, 620px); margin: 8px auto; }

.enterprise-context-card {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
  padding: 16px;
}

.enterprise-context-card > div { min-width: 0; display: grid; gap: 5px; }
.enterprise-context-card span { color: var(--app-text-secondary); font-size: 12px; }
.enterprise-context-card strong { overflow-wrap: anywhere; font-size: 15px; }
.enterprise-context-card small { grid-column: 1 / -1; color: var(--app-warning); line-height: 1.5; }

.enterprise-student-section { overflow: hidden; }

.enterprise-student-section > header {
  min-height: 56px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 14px;
  border-bottom: 1px solid var(--app-line);
}

.enterprise-student-section > header > div { display: grid; gap: 2px; }
.enterprise-student-section > header strong { font-size: 16px; }
.enterprise-student-section > header span { color: var(--app-text-secondary); font-size: 12px; }
.enterprise-student-section > header button { width: 38px; height: 38px; border: 0; border-radius: 8px; display: grid; place-items: center; color: var(--app-primary); background: var(--app-primary-soft); }

.enterprise-student-list { display: grid; gap: 10px; padding: 12px; }
.enterprise-empty { padding: 30px 14px; color: var(--app-text-secondary); text-align: center; }

.enterprise-evaluation-form { display: grid; gap: 14px; padding: 14px 16px; }
.enterprise-evaluation-form .app-field small { justify-self: end; color: var(--app-text-tertiary); }

.enterprise-rule-summary {
  min-height: 60px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border-radius: var(--app-radius);
  padding: 12px 14px;
  color: var(--app-primary-strong);
  background: var(--app-primary-soft);
}

.enterprise-rule-summary span { font-size: 13px; }
.enterprise-rule-summary strong { font-size: 22px; }
.rotating { animation: enterprise-spin .8s linear infinite; }

@keyframes enterprise-spin { to { transform: rotate(360deg); } }

@media (max-width: 380px) {
  .enterprise-context-card { grid-template-columns: 1fr; }
  .enterprise-context-card small { grid-column: auto; }
}
</style>
