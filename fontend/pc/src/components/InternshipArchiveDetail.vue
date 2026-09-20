<template>
  <el-dialog
    :model-value="modelValue"
    append-to-body
    destroy-on-close
    class="internship-archive-dialog"
    width="min(1480px, 94vw)"
    @close="close"
  >
    <template #header>
      <div class="archive-dialog-title">
        <span>实习档案</span>
        <small v-if="detail.plan">{{ detail.plan.course_name || detail.plan.course_code }}</small>
      </div>
    </template>

    <div v-loading="loading" class="archive-detail-body">
      <el-alert v-if="message" :title="message" type="error" :closable="false" show-icon />
      <template v-if="detail.plan">
        <section class="archive-plan-summary">
          <div><span>实习类别</span><strong>{{ detail.plan.category_name || '-' }}</strong></div>
          <div><span>{{ planScopeLabel }}</span><strong>{{ planScopeName || '-' }}</strong></div>
          <div><span>学院</span><strong>{{ detail.plan.dep_name || '-' }}</strong></div>
          <div><span>专业</span><strong>{{ detail.plan.profession_name || '-' }}</strong></div>
          <div><span>课程</span><strong>{{ detail.plan.course_name || detail.plan.course_code || '-' }}</strong></div>
        </section>

        <el-tabs v-model="activeSection" class="archive-detail-tabs">
          <el-tab-pane label="计划材料" name="plan">
            <InternshipArchiveMaterialList
              :materials="detail.plan.materials || []"
              :templates="templateMap"
              :can-manage="canManage"
              :can-edit-appraisal="canEditAppraisal"
              :can-approve="canApprove"
              :loading-key="loadingKey"
              @appraisal="openAppraisal"
              @archive="archiveMaterial"
              @generate="generateMaterial"
              @history="showHistory"
              @open-file="openFile"
              @open-template="openTemplate"
              @upload="selectUpload"
            />
          </el-tab-pane>
          <el-tab-pane :label="`任务材料（${detail.arrangements.length}）`" name="tasks">
            <el-collapse v-model="activeTasks" class="archive-task-collapse">
              <el-collapse-item v-for="task in detail.arrangements" :key="task.id" :name="task.id">
                <template #title>
                  <div class="archive-task-title">
                    <strong>{{ task.title || task.name }}</strong>
                    <span>{{ task.task_no || '未设置任务编号' }}</span>
                    <span>{{ dateRange(task.start_date, task.end_date) }}</span>
                  </div>
                </template>
                <section class="archive-level-section">
                  <header><strong>任务材料</strong><span>由当前任务统一生成或维护</span></header>
                  <InternshipArchiveMaterialList
                    :materials="task.materials || []"
                    :templates="templateMap"
                    :can-manage="canManage"
                    :can-edit-appraisal="canEditAppraisal"
                    :can-approve="canApprove"
                    :loading-key="loadingKey"
                    @appraisal="openAppraisal"
                    @archive="archiveMaterial"
                    @generate="generateMaterial"
                    @history="showHistory"
                    @open-file="openFile"
                    @open-template="openTemplate"
                    @upload="selectUpload"
                  />
                </section>
                <section v-if="task.compliance" class="archive-compliance-section">
                  <header>
                    <strong>合规附加材料</strong>
                    <span>保险记录、抽查记录继续留痕，不计入 12 类档案完成度</span>
                  </header>
                  <div class="archive-compliance-grid">
                    <div class="archive-compliance-item">
                      <span>保险覆盖</span>
                      <strong :class="task.compliance.insurance.ready ? 'is-ready' : 'is-pending'">
                        {{ task.compliance.insurance.text }}
                      </strong>
                    </div>
                    <div class="archive-compliance-item">
                      <span>抽查记录</span>
                      <strong>{{ task.compliance.inspection.text }}</strong>
                      <small v-if="task.compliance.inspection.latest_result">
                        最近结果：{{ task.compliance.inspection.latest_result }}
                      </small>
                    </div>
                  </div>
                </section>

                <el-tabs class="archive-sub-tabs">
                  <el-tab-pane :label="`班级材料（${task.classes?.length || 0}）`">
                    <el-empty v-if="!task.classes?.length" description="当前任务未绑定班级" :image-size="72" />
                    <section v-for="classRow in task.classes || []" :key="classRow.class_id" class="archive-level-section compact">
                      <header><strong>{{ classRow.class_name || classRow.class_num }}</strong><span>成绩登记表按任务和班级独立生成</span></header>
                      <InternshipArchiveMaterialList
                        :materials="classRow.materials || []"
                        :templates="templateMap"
                        :can-manage="canManage"
                        :can-edit-appraisal="canEditAppraisal"
                        :can-approve="canApprove"
                        :loading-key="loadingKey"
                        @appraisal="openAppraisal"
                        @archive="archiveMaterial"
                        @generate="generateMaterial"
                        @history="showHistory"
                        @open-file="openFile"
                        @open-template="openTemplate"
                        @upload="selectUpload"
                      />
                    </section>
                  </el-tab-pane>
                  <el-tab-pane :label="`学生材料（${task.students?.length || 0}）`">
                    <el-empty v-if="!task.students?.length" description="当前任务未绑定学生" :image-size="72" />
                    <el-collapse v-else class="archive-student-collapse">
                      <el-collapse-item v-for="student in task.students" :key="student.student_id" :name="student.student_id">
                        <template #title>
                          <div class="archive-student-title">
                            <strong>{{ student.student_name }}</strong>
                            <span>{{ student.student_num }}</span>
                            <span>{{ student.class_name || '未设置班级' }}</span>
                          </div>
                        </template>
                        <InternshipArchiveMaterialList
                          :materials="student.materials || []"
                          :templates="templateMap"
                          :can-manage="canManage"
                          :can-edit-appraisal="canEditAppraisal"
                          :can-approve="canApprove"
                          :loading-key="loadingKey"
                          @appraisal="openAppraisal"
                          @archive="archiveMaterial"
                          @generate="generateMaterial"
                          @history="showHistory"
                          @open-file="openFile"
                          @open-template="openTemplate"
                          @upload="selectUpload"
                        />
                        <div v-if="student.compliance" class="archive-student-compliance">
                          <span>合规附加材料</span>
                          <el-tag
                            size="small"
                            :type="student.compliance.insurance.ready ? 'success' : 'warning'"
                          >
                            保险：{{ student.compliance.insurance.text }}
                          </el-tag>
                          <el-tag size="small" type="info">
                            抽查：{{ student.compliance.inspection.text }}
                          </el-tag>
                          <small v-if="student.compliance.inspection.latest_result">
                            最近结果：{{ student.compliance.inspection.latest_result }}
                          </small>
                        </div>
                      </el-collapse-item>
                    </el-collapse>
                  </el-tab-pane>
                </el-tabs>
              </el-collapse-item>
            </el-collapse>
          </el-tab-pane>
        </el-tabs>
      </template>
    </div>

    <input ref="uploadInput" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx" hidden @change="uploadFinalFile">

    <el-dialog v-model="historyVisible" append-to-body title="材料历史版本" width="760px">
      <el-table :data="historyItems" border empty-text="暂无历史版本">
        <el-table-column type="index" label="序号" width="66" align="center" />
        <el-table-column prop="archive_version" label="版本" width="80" />
        <el-table-column prop="status" label="状态" width="100" />
        <el-table-column prop="updated_at" label="更新时间" width="168" />
        <el-table-column label="文件" min-width="220">
          <template #default="{ row }">
            <el-button v-if="row.signed_file" link type="primary" @click="openFile(row.signed_file)">查看定稿</el-button>
            <el-button v-if="row.generated_file" link type="primary" @click="openFile(row.generated_file)">查看生成件</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>

    <GraduationAppraisalDialog
      v-model="appraisalVisible"
      :target="appraisalTarget"
      :can-edit="canEditAppraisal"
      :can-approve="canApprove"
      @changed="changed"
    />
  </el-dialog>
</template>

<script setup>
import { previewFile } from '../../../shared/filePreview';
import { computed, ref, watch } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { backendUrl } from '../api/client';
import {
  archiveInternshipMaterial,
  fetchInternshipArchiveMaterialDetail,
  fetchInternshipArchiveMaterialHistory,
  fetchTemplateList,
  generateInternshipArchiveMaterial,
  saveInternshipArchiveMaterial,
  uploadFile,
} from '../api/system';
import InternshipArchiveMaterialList from './InternshipArchiveMaterialList.vue';
import GraduationAppraisalDialog from './GraduationAppraisalDialog.vue';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  planId: { type: Number, default: 0 },
  canManage: { type: Boolean, default: false },
  canEditAppraisal: { type: Boolean, default: false },
  canApprove: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'changed']);

const loading = ref(false);
const loadingKey = ref('');
const message = ref('');
const detail = ref({ plan: null, arrangements: [], materials: [] });
const templates = ref([]);
const activeSection = ref('plan');
const activeTasks = ref([]);
const uploadInput = ref(null);
const uploadTarget = ref(null);
const historyVisible = ref(false);
const historyItems = ref([]);
const appraisalVisible = ref(false);
const appraisalTarget = ref({});

const templateMap = computed(() => Object.fromEntries(templates.value
  .filter(item => item.material_type)
  .map(item => [item.material_type, item])));
const planScopeLabel = computed(() => detail.value.plan?.scope_type === 'cohort' || detail.value.plan?.graduation_cohort_id ? '毕业届次' : '年级');
const planScopeName = computed(() => planScopeLabel.value === '毕业届次'
  ? detail.value.plan?.cohort_name
  : detail.value.plan?.grade_name);

watch(() => [props.modelValue, props.planId], ([visible, planId]) => {
  if (visible && planId) {
    loadDetail();
  }
}, { immediate: true });

async function loadDetail() {
  loading.value = true;
  message.value = '';
  try {
    const [archiveDetail, templateData] = await Promise.all([
      fetchInternshipArchiveMaterialDetail({ plan_id: props.planId }),
      fetchTemplateList({ business_code: 'internship_archive', page: 1, page_size: 100 }),
    ]);
    detail.value = {
      plan: archiveDetail.plan || null,
      arrangements: archiveDetail.arrangements || [],
      materials: archiveDetail.materials || [],
    };
    templates.value = templateData.items || [];
    activeTasks.value = detail.value.arrangements.length ? [detail.value.arrangements[0].id] : [];
  } catch (error) {
    message.value = error.message;
  } finally {
    loading.value = false;
  }
}

function close() {
  emit('update:modelValue', false);
}

function targetPayload(row) {
  return {
    material_type: row.material_type,
    plan_id: row.plan_id,
    arrangement_id: row.arrangement_id || undefined,
    student_id: row.student_id || undefined,
    class_id: row.class_id || undefined,
  };
}

function targetKey(action, row) {
  return `${action}:${[row.material_type, row.plan_id || 0, row.arrangement_id || 0, row.student_id || 0, row.class_id || 0].join(':')}`;
}

async function generateMaterial(row) {
  const key = targetKey('generate', row);
  if (loadingKey.value) return;
  loadingKey.value = key;
  try {
    await generateInternshipArchiveMaterial(targetPayload(row));
    ElMessage.success('档案文件已生成');
    await changed();
  } catch (error) {
    ElMessage.error(error.message);
  } finally {
    loadingKey.value = '';
  }
}

function selectUpload(row) {
  if (loadingKey.value) return;
  uploadTarget.value = row;
  uploadInput.value?.click();
}

async function uploadFinalFile(event) {
  const file = event.target.files?.[0];
  const row = uploadTarget.value;
  event.target.value = '';
  if (!file || !row) return;
  const key = targetKey('upload', row);
  loadingKey.value = key;
  try {
    const uploaded = await uploadFile(file, { category: 'internship_archive', is_temporary: 'false' });
    await saveInternshipArchiveMaterial({
      ...targetPayload(row),
      signed_file_id: uploaded.file_id,
      status: 'submitted',
    });
    ElMessage.success('定稿文件已上传');
    await changed();
  } catch (error) {
    ElMessage.error(error.message);
  } finally {
    uploadTarget.value = null;
    loadingKey.value = '';
  }
}

async function archiveMaterial(row) {
  if (loadingKey.value || !row.material?.id) return;
  try {
    await ElMessageBox.confirm(`归档后将冻结“${row.label}”当前版本，确认继续？`, '确认归档', {
      confirmButtonText: '确认归档',
      cancelButtonText: '取消',
      type: 'warning',
    });
  } catch {
    return;
  }
  loadingKey.value = targetKey('archive', row);
  try {
    await archiveInternshipMaterial({ id: row.material.id });
    ElMessage.success('材料已归档');
    await changed();
  } catch (error) {
    ElMessage.error(error.message);
  } finally {
    loadingKey.value = '';
  }
}

async function showHistory(row) {
  if (!row.material?.id) return;
  try {
    const data = await fetchInternshipArchiveMaterialHistory({ id: row.material.id });
    historyItems.value = data.items || [];
    historyVisible.value = true;
  } catch (error) {
    ElMessage.error(error.message);
  }
}

function openTemplate(row) {
  const template = templateMap.value[row.material_type];
  const url = template?.file_url || template?.url || template?.file?.url;
  if (!url) {
    ElMessage.warning('模板文件暂不可用');
    return;
  }
  previewFile({ url, file_id: template?.file_id, name: template?.file?.name || template?.file_name });
}

function openFile(file) {
  if (file?.url) {
    previewFile(file);
  }
}

function openAppraisal(row) {
  appraisalTarget.value = row;
  appraisalVisible.value = true;
}

async function changed() {
  await loadDetail();
  emit('changed');
}

function dateRange(start, end) {
  return [start, end].filter(Boolean).join(' 至 ') || '未设置时间';
}
</script>

<style scoped>
.archive-detail-body {
  min-height: 420px;
  max-height: calc(100vh - 190px);
  overflow: auto;
  padding: 0 2px 16px;
}

.archive-dialog-title {
  display: flex;
  align-items: baseline;
  gap: 12px;
}

.archive-dialog-title span {
  font-size: 18px;
  font-weight: 700;
}

.archive-dialog-title small {
  color: #667085;
}

.archive-plan-summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 1px;
  margin: 4px 0 16px;
  overflow: hidden;
  border: 1px solid #e4e7ec;
  border-radius: 6px;
  background: #e4e7ec;
}

.archive-plan-summary div {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 6px;
  padding: 12px 14px;
  background: #fff;
}

.archive-plan-summary span,
.archive-level-section header span,
.archive-task-title span,
.archive-student-title span {
  color: #667085;
  font-size: 12px;
}

.archive-plan-summary strong {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.archive-detail-tabs,
.archive-sub-tabs {
  width: 100%;
}

.archive-task-title,
.archive-student-title {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;
}

.archive-task-title strong {
  max-width: 460px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.archive-level-section {
  margin-bottom: 18px;
}

.archive-level-section.compact {
  margin-bottom: 14px;
}

.archive-compliance-section {
  margin: 4px 0 18px;
  padding: 12px 14px;
  border: 1px solid #d9e2f2;
  border-radius: 8px;
  background: #f7faff;
}

.archive-compliance-section > header {
  display: flex;
  align-items: baseline;
  gap: 10px;
  margin-bottom: 10px;
}

.archive-compliance-section > header span,
.archive-student-compliance,
.archive-student-compliance small,
.archive-compliance-item small {
  color: #667085;
  font-size: 12px;
}

.archive-compliance-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.archive-compliance-item {
  display: flex;
  align-items: baseline;
  gap: 10px;
  min-width: 0;
  padding: 10px 12px;
  border: 1px solid #e4e7ec;
  border-radius: 6px;
  background: #fff;
}

.archive-compliance-item span {
  color: #667085;
  white-space: nowrap;
}

.archive-compliance-item strong {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.archive-compliance-item strong.is-ready {
  color: #087443;
}

.archive-compliance-item strong.is-pending {
  color: #b54708;
}

.archive-student-compliance {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 10px;
  padding: 8px 10px;
  border-top: 1px solid #eef2f6;
}

.archive-level-section > header {
  display: flex;
  align-items: baseline;
  gap: 10px;
  margin-bottom: 10px;
}

.archive-task-collapse,
.archive-student-collapse {
  border-top: 0;
}

@media (max-width: 980px) {
  .archive-plan-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .archive-compliance-grid {
    grid-template-columns: 1fr;
  }
}
</style>

<style>
.internship-archive-dialog .el-dialog__body {
  padding-top: 8px;
}
</style>
