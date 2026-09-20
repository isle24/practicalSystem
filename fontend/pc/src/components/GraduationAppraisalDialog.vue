<template>
  <el-dialog
    :model-value="modelValue"
    append-to-body
    destroy-on-close
    class="graduation-appraisal-dialog"
    width="min(980px, 92vw)"
    @close="close"
  >
    <template #header>
      <div class="appraisal-dialog-title">
        <div>
          <strong>毕业实习成绩鉴定表</strong>
          <small>{{ target.student_name || '学生' }} · {{ target.student_num || '未设置学号' }}</small>
        </div>
        <el-tag :type="statusType">{{ statusText }}</el-tag>
      </div>
    </template>

    <div v-loading="loading" class="appraisal-dialog-body">
      <el-alert v-if="message" :title="message" type="error" :closable="false" show-icon />

      <section class="appraisal-context">
        <div><span>实习任务</span><strong>{{ target.arrangement_title || target.task_title || '-' }}</strong></div>
        <div><span>班级</span><strong>{{ target.class_name || '-' }}</strong></div>
        <div><span>综合成绩</span><strong>{{ totalScore }} 分</strong></div>
        <div><span>五级制</span><strong>{{ gradeLevel }}</strong></div>
      </section>

      <el-form label-position="top" class="appraisal-form">
        <section class="appraisal-section">
          <header><strong>学生与实习信息</strong><span>对应模板基本信息和自我小结</span></header>
          <div class="appraisal-grid two-columns">
            <el-form-item label="实习单位">
              <el-input v-model="form.form_data.internship_unit" :disabled="!editable" maxlength="180" />
            </el-form-item>
            <el-form-item label="实习岗位">
              <el-input v-model="form.form_data.internship_position" :disabled="!editable" maxlength="120" />
            </el-form-item>
            <el-form-item label="校外指导老师">
              <el-input v-model="form.form_data.external_mentor" :disabled="!editable" maxlength="80" />
            </el-form-item>
            <el-form-item label="单位电话">
              <el-input v-model="form.form_data.enterprise_phone" :disabled="!editable" maxlength="40" />
            </el-form-item>
            <el-form-item label="自我小结" class="span-two">
              <el-input v-model="form.form_data.self_summary" :disabled="!editable" type="textarea" :rows="4" maxlength="5000" show-word-limit />
            </el-form-item>
          </div>
        </section>

        <section class="appraisal-section">
          <header><strong>评分与评语</strong><span>过程管理 50 分、实习单位 30 分、校内指导教师 20 分</span></header>
          <div class="appraisal-score-grid">
            <el-form-item label="过程管理得分（50分）">
              <el-input-number v-model="form.process_score" :disabled="!editable" :min="0" :max="50" :precision="1" controls-position="right" />
            </el-form-item>
            <el-form-item label="实习单位评分（30分）">
              <el-input-number v-model="form.enterprise_score" disabled :min="0" :max="30" :precision="1" controls-position="right" />
              <small class="enterprise-evaluation-source">来自企业导师独立评价</small>
            </el-form-item>
            <el-form-item label="校内指导教师评分（20分）">
              <el-input-number v-model="form.school_score" :disabled="!editable" :min="0" :max="20" :precision="1" controls-position="right" />
            </el-form-item>
          </div>
          <div class="appraisal-grid two-columns">
            <el-form-item label="实习单位评语">
              <el-input v-model="form.enterprise_comment" disabled type="textarea" :rows="4" maxlength="5000" show-word-limit placeholder="等待企业导师提交独立评价" />
              <small class="enterprise-evaluation-source">来自企业导师独立评价，不可在鉴定表中修改</small>
            </el-form-item>
            <el-form-item label="校内指导教师评语">
              <el-input v-model="form.school_comment" :disabled="!editable" type="textarea" :rows="4" maxlength="5000" show-word-limit />
            </el-form-item>
          </div>
        </section>

        <section class="appraisal-section">
          <header><strong>签章定稿件</strong><span>审核后可下载生成件打印签章，再上传定稿用于归档</span></header>
          <div class="appraisal-file-row">
            <button v-if="form.attachment" type="button" class="appraisal-file" @click="openAttachment">
              <FileText :size="17" />
              <span>{{ form.attachment.name }}</span>
            </button>
            <span v-else>尚未上传签章定稿件</span>
            <el-button v-if="editable" :icon="Upload" :loading="uploading" @click="chooseAttachment">
              {{ form.attachment ? '更换文件' : '上传文件' }}
            </el-button>
            <input ref="attachmentInput" type="file" accept=".pdf,.doc,.docx" hidden @change="uploadAttachment">
          </div>
        </section>

        <section v-if="reviewable" class="appraisal-section review-section">
          <header><strong>审核处理</strong><span>保存草稿不会通知提交人，提交审核后才发送结果</span></header>
          <div class="review-form-row">
            <el-radio-group v-model="review.status">
              <el-radio-button value="accept">通过</el-radio-button>
              <el-radio-button value="modify">退回修改</el-radio-button>
            </el-radio-group>
            <el-input v-model="review.opinion" type="textarea" :rows="3" maxlength="500" show-word-limit placeholder="填写审核意见" />
          </div>
          <div class="review-actions">
            <el-button :loading="saving" @click="saveReviewDraft">保存审核草稿</el-button>
            <el-button type="primary" :loading="saving" @click="submitReview">提交审核</el-button>
          </div>
        </section>

        <section v-if="timeline.length" class="appraisal-section">
          <header><strong>流程记录</strong><span>按提交和审核时间留痕</span></header>
          <el-timeline class="appraisal-timeline">
            <el-timeline-item v-for="item in timeline" :key="item.key" :timestamp="item.created_at" placement="top">
              <strong>{{ item.title }}</strong>
              <p>{{ item.content }}</p>
            </el-timeline-item>
          </el-timeline>
        </section>
      </el-form>
    </div>

    <template #footer>
      <div class="appraisal-footer">
        <el-button v-if="reopenable" type="warning" plain :loading="saving" @click="requestReopen">通过后修改</el-button>
        <span />
        <el-button @click="close">关闭</el-button>
        <el-button v-if="editable" :loading="saving" @click="save('draft')">保存草稿</el-button>
        <el-button v-if="editable" type="primary" :loading="saving" @click="save('wait')">提交审核</el-button>
      </div>
    </template>
  </el-dialog>
</template>

<script setup>
import { previewFile } from '../../../shared/filePreview';
import { computed, reactive, ref, watch } from 'vue';
import { FileText, Upload } from '@lucide/vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { backendUrl } from '../api/client';
import {
  fetchInternshipGraduationAppraisal,
  fetchInternshipReviewDraft,
  fetchInternshipTimeline,
  requestInternshipModification,
  reviewInternshipDocument,
  saveInternshipGraduationAppraisal,
  saveInternshipReviewDraft,
  uploadFile,
} from '../api/system';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  target: { type: Object, default: () => ({}) },
  canEdit: { type: Boolean, default: false },
  canApprove: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'changed']);

const loading = ref(false);
const saving = ref(false);
const uploading = ref(false);
const message = ref('');
const attachmentInput = ref(null);
const timeline = ref([]);
const form = reactive(emptyForm());
const review = reactive({ status: 'accept', opinion: '' });

const editable = computed(() => props.canEdit && (!form.id || ['draft', 'modify'].includes(form.status)));
const reviewable = computed(() => props.canApprove && form.id && form.status === 'wait');
const reopenable = computed(() => props.canApprove && form.id && form.status === 'accept');
const totalScore = computed(() => roundScore(form.process_score) + roundScore(form.enterprise_score) + roundScore(form.school_score));
const gradeLevel = computed(() => scoreLevel(totalScore.value));
const statusText = computed(() => ({ draft: '草稿', wait: '待审核', accept: '已通过', modify: '需修改' }[form.status] || '未填写'));
const statusType = computed(() => ({ wait: 'warning', accept: 'success', modify: 'danger' }[form.status] || 'info'));

watch(() => [props.modelValue, props.target.student_id, props.target.arrangement_id], ([visible]) => {
  if (visible) load();
}, { immediate: true });

async function load() {
  if (!props.target.student_id || !props.target.arrangement_id) return;
  loading.value = true;
  message.value = '';
  try {
    const data = await fetchInternshipGraduationAppraisal({
      student_id: props.target.student_id,
      arrangement_id: props.target.arrangement_id,
    });
    Object.assign(form, emptyForm(), normalizeItem(data.item));
    await Promise.all([loadTimeline(), loadReviewDraft()]);
  } catch (error) {
    message.value = error.message;
  } finally {
    loading.value = false;
  }
}

async function loadTimeline() {
  timeline.value = [];
  if (!form.id) return;
  const data = await fetchInternshipTimeline({ entity: 'graduation_appraisal', id: form.id });
  timeline.value = [
    ...(data.records || []).map(item => ({
      key: `record-${item.id}`,
      created_at: item.created_at,
      title: `${statusLabel(item.from_status)} → ${statusLabel(item.to_status)}`,
      content: item.content || item.opinion || actionLabel(item.action),
    })),
    ...(data.reviews || []).map(item => ({
      key: `review-${item.id}`,
      created_at: item.created_at,
      title: `审核：${statusLabel(item.status || item.review_status)}`,
      content: item.opinion || '无审核意见',
    })),
  ].sort((left, right) => String(left.created_at || '').localeCompare(String(right.created_at || '')));
}

async function loadReviewDraft() {
  Object.assign(review, { status: 'accept', opinion: '' });
  if (!reviewable.value) return;
  const data = await fetchInternshipReviewDraft({ entity: 'graduation_appraisal', id: form.id });
  if (data.draft) {
    review.status = data.draft.review_status || 'accept';
    review.opinion = data.draft.opinion || '';
  }
}

async function save(status) {
  if (saving.value || !editable.value) return;
  if (status === 'wait' && [form.process_score, form.enterprise_score, form.school_score].some(value => value === null || value === '')) {
    ElMessage.warning('提交审核前请填写全部评分');
    return;
  }
  saving.value = true;
  message.value = '';
  try {
    await saveInternshipGraduationAppraisal({
      id: form.id || undefined,
      student_id: props.target.student_id,
      arrangement_id: props.target.arrangement_id,
      process_score: form.process_score,
      enterprise_score: form.enterprise_score,
      school_score: form.school_score,
      enterprise_comment: form.enterprise_comment,
      school_comment: form.school_comment,
      form_data: form.form_data,
      attachment_id: form.attachment_id || undefined,
      status,
    });
    ElMessage.success(status === 'wait' ? '鉴定表已提交审核' : '鉴定表草稿已保存');
    await changed();
  } catch (error) {
    message.value = error.message;
  } finally {
    saving.value = false;
  }
}

async function saveReviewDraft() {
  if (saving.value || !reviewable.value) return;
  saving.value = true;
  try {
    await saveInternshipReviewDraft({ entity: 'graduation_appraisal', id: form.id, status: review.status, opinion: review.opinion });
    ElMessage.success('审核草稿已保存');
  } catch (error) {
    message.value = error.message;
  } finally {
    saving.value = false;
  }
}

async function submitReview() {
  if (saving.value || !reviewable.value) return;
  if (review.status === 'modify' && review.opinion.trim().length < 5) {
    ElMessage.warning('退回修改意见至少填写 5 个字');
    return;
  }
  saving.value = true;
  try {
    await reviewInternshipDocument({ entity: 'graduation_appraisal', id: form.id, status: review.status, opinion: review.opinion });
    ElMessage.success(review.status === 'accept' ? '鉴定表已通过' : '鉴定表已退回修改');
    await changed();
  } catch (error) {
    message.value = error.message;
  } finally {
    saving.value = false;
  }
}

async function requestReopen() {
  try {
    const { value } = await ElMessageBox.prompt('请填写通过后修改理由，提交后原填写人可重新修改。', '通过后修改', {
      confirmButtonText: '确认发起',
      cancelButtonText: '取消',
      inputType: 'textarea',
      inputValidator: value => String(value || '').trim().length >= 5 || '修改理由至少填写 5 个字',
    });
    saving.value = true;
    await requestInternshipModification({ entity: 'graduation_appraisal', id: form.id, opinion: value });
    ElMessage.success('已发起通过后修改');
    await changed();
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') message.value = error.message || String(error);
  } finally {
    saving.value = false;
  }
}

function chooseAttachment() {
  if (!attachmentInput.value) return;
  attachmentInput.value.value = '';
  attachmentInput.value.click();
}

async function uploadAttachment(event) {
  const file = event.target.files?.[0];
  if (!file) return;
  uploading.value = true;
  try {
    const uploaded = await uploadFile(file, { category: 'internship_appraisal', is_temporary: 'false' });
    form.attachment_id = uploaded.file_id;
    form.attachment = { id: uploaded.file_id, name: uploaded.name || file.name, url: uploaded.url || '' };
    ElMessage.success('鉴定表附件已上传，请保存表单');
  } catch (error) {
    message.value = error.message;
  } finally {
    uploading.value = false;
  }
}

function openAttachment() {
  if (form.attachment) previewFile(form.attachment);
}

async function changed() {
  await load();
  emit('changed');
}

function close() {
  emit('update:modelValue', false);
}

function emptyForm() {
  return {
    id: 0,
    status: '',
    process_score: null,
    enterprise_score: null,
    school_score: null,
    enterprise_comment: '',
    school_comment: '',
    attachment_id: 0,
    attachment: null,
    form_data: {
      internship_unit: '',
      internship_position: '',
      external_mentor: '',
      enterprise_phone: '',
      self_summary: '',
    },
  };
}

function normalizeItem(item) {
  if (!item) return {};
  return {
    ...item,
    process_score: numberOrNull(item.process_score),
    enterprise_score: numberOrNull(item.enterprise_score),
    school_score: numberOrNull(item.school_score),
    enterprise_comment: item.enterprise_comment || '',
    school_comment: item.school_comment || '',
    form_data: { ...emptyForm().form_data, ...(item.form_data || {}) },
  };
}

function numberOrNull(value) {
  return value === null || value === undefined || value === '' ? null : Number(value);
}

function roundScore(value) {
  return Number(value || 0);
}

function scoreLevel(score) {
  if (score >= 90) return '优秀';
  if (score >= 80) return '良好';
  if (score >= 70) return '中等';
  if (score >= 60) return '及格';
  return score > 0 ? '不及格' : '-';
}

function statusLabel(status) {
  return ({ draft: '草稿', wait: '待审核', accept: '已通过', modify: '需修改' }[status] || status || '-');
}

function actionLabel(action) {
  return ({ submit: '提交审核', save_draft: '保存草稿', review: '审核处理', modify_after_accept: '通过后修改' }[action] || action || '流程处理');
}
</script>

<style scoped>
.appraisal-dialog-body {
  max-height: calc(100vh - 190px);
  overflow: auto;
  padding: 0 4px 10px;
}

.appraisal-dialog-title,
.appraisal-dialog-title > div,
.appraisal-section header,
.appraisal-file-row,
.appraisal-footer {
  display: flex;
  align-items: center;
}

.appraisal-dialog-title {
  justify-content: space-between;
  gap: 16px;
  padding-right: 32px;
}

.appraisal-dialog-title > div {
  align-items: baseline;
  gap: 10px;
}

.appraisal-dialog-title strong {
  font-size: 18px;
}

.appraisal-dialog-title small,
.appraisal-section header span,
.appraisal-file-row > span {
  color: #667085;
  font-size: 12px;
}

.appraisal-context {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 1px;
  overflow: hidden;
  margin-bottom: 16px;
  border: 1px solid #e4e7ec;
  border-radius: 6px;
  background: #e4e7ec;
}

.appraisal-context div {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 5px;
  padding: 11px 13px;
  background: #fff;
}

.appraisal-context span {
  color: #667085;
  font-size: 12px;
}

.appraisal-context strong {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.appraisal-section {
  padding: 16px 0;
  border-top: 1px solid #eaecf0;
}

.appraisal-section header {
  align-items: baseline;
  gap: 10px;
  margin-bottom: 13px;
}

.appraisal-grid,
.appraisal-score-grid {
  display: grid;
  gap: 12px 16px;
}

.appraisal-grid.two-columns {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.appraisal-score-grid {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.span-two {
  grid-column: 1 / -1;
}

.appraisal-form :deep(.el-form-item) {
  margin-bottom: 2px;
}

.appraisal-form :deep(.el-input-number) {
  width: 100%;
}

.enterprise-evaluation-source {
  display: block;
  margin-top: 5px;
  color: #667085;
  font-size: 12px;
  line-height: 1.4;
}

.appraisal-file-row {
  justify-content: space-between;
  gap: 12px;
  min-height: 42px;
  padding: 8px 10px;
  border: 1px solid #e4e7ec;
  border-radius: 6px;
  background: #f9fafb;
}

.appraisal-file {
  display: inline-flex;
  min-width: 0;
  align-items: center;
  gap: 7px;
  padding: 0;
  border: 0;
  color: #2563eb;
  background: transparent;
  cursor: pointer;
}

.appraisal-file span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.review-section {
  margin-top: 4px;
  padding: 16px;
  border: 1px solid #dbeafe;
  border-radius: 6px;
  background: #f8fbff;
}

.review-form-row {
  display: grid;
  grid-template-columns: 180px minmax(0, 1fr);
  align-items: start;
  gap: 14px;
}

.review-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 10px;
}

.appraisal-timeline {
  padding: 4px 8px 0;
}

.appraisal-timeline p {
  margin: 5px 0 0;
  color: #667085;
  line-height: 1.6;
}

.appraisal-footer {
  width: 100%;
  justify-content: flex-end;
  gap: 8px;
}

.appraisal-footer > span {
  flex: 1;
}

@media (max-width: 760px) {
  .appraisal-context,
  .appraisal-grid.two-columns,
  .appraisal-score-grid,
  .review-form-row {
    grid-template-columns: 1fr;
  }
}
</style>
