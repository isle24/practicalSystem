<template>
  <section v-if="internship.submitSection === 'sign'" class="mobile-card form-card">
    <header>
      <MapPin :size="20" />
      <strong>签到</strong>
    </header>
    <label>
      <span>实习任务</span>
      <select v-model.number="internship.forms.sign.arrangement_id">
        <option v-for="item in internship.options.arrangements" :key="item.id" :value="item.id">
          {{ item.title }}
        </option>
      </select>
    </label>
    <div class="gps-sign-card">
      <div class="gps-sign-head">
        <span>
          <strong>{{ signGpsTitle }}</strong>
          <small>{{ internship.forms.sign.location || signGpsHint }}</small>
        </span>
        <AppButton
          variant="secondary"
          size="small"
          :loading="internship.forms.sign.locating"
          @click="locateSignPosition"
        >
          重新定位
        </AppButton>
      </div>
      <div class="gps-map-preview">
        <img v-if="signMapUrl" :src="signMapUrl" alt="签到定位地图">
        <div v-else>
          <MapPin :size="24" />
          <span>获取 GPS 后显示地图</span>
        </div>
      </div>
      <div class="gps-coordinate-grid">
        <span>经度 {{ coordinateText(internship.forms.sign.longitude) }}</span>
        <span>纬度 {{ coordinateText(internship.forms.sign.latitude) }}</span>
        <span>精度 {{ signAccuracyText }}</span>
      </div>
      <small v-if="internship.forms.sign.gps_error" class="gps-error">{{ internship.forms.sign.gps_error }}</small>
    </div>
    <AppButton block :loading="internship.loading" :disabled="!signGpsReady" @click="submitSignIn">提交签到</AppButton>
  </section>

  <section v-else-if="internship.submitSection === 'journal'" class="mobile-card form-card">
    <header>
      <FileClock :size="20" />
      <strong>实习日志</strong>
      <small :class="{ danger: isStageExpired('journal_deadline') }">{{ stageDeadlineText('journal_deadline') }}</small>
    </header>
    <label>
      <span>实习任务</span>
      <select v-model.number="internship.forms.journal.arrangement_id">
        <option v-for="item in internship.options.arrangements" :key="item.id" :value="item.id">
          {{ item.title }}
        </option>
      </select>
    </label>
    <div class="stage-deadline-panel" :class="{ expired: isStageExpired('journal_deadline') }">
      <span>{{ stageDeadlineDetailText('journal_deadline') }}</span>
      <AppButton
        v-if="isStageExpired('journal_deadline')"
        variant="secondary"
        size="small"
        @click="openDelayForStage('journal_deadline')"
      >
        申请延期
      </AppButton>
    </div>
    <label>
      <span>标题</span>
      <input v-model="internship.forms.journal.title">
    </label>
    <label>
      <span>日期</span>
      <input v-model="internship.forms.journal.date" type="date">
    </label>
    <label>
      <span>实习地点</span>
      <input v-model="internship.forms.journal.location" placeholder="填写当天实习地点">
    </label>
    <label>
      <span>工作内容</span>
      <textarea v-model="internship.forms.journal.work_content" rows="5" placeholder="记录当天完成的工作和过程" />
    </label>
    <label>
      <span>收获与体会</span>
      <textarea v-model="internship.forms.journal.gains" rows="4" />
    </label>
    <label>
      <span>问题与改进</span>
      <textarea v-model="internship.forms.journal.problems" rows="4" />
    </label>
    <div class="material-upload-block">
      <span>图片与附件</span>
      <label class="material-upload-button">
        <Paperclip :size="17" />
        <span>选择文件</span>
        <input type="file" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx" @change="event => uploadInternshipAttachments('journal', event)">
      </label>
      <div v-if="internship.forms.journal.attachments.length" class="material-file-list">
        <div v-for="file in internship.forms.journal.attachments" :key="file.id">
          <FileText :size="16" />
          <span>{{ file.name }}</span>
          <button type="button" aria-label="移除附件" @click="removeInternshipAttachment('journal', file.id)"><X :size="16" /></button>
        </div>
      </div>
    </div>
    <AppActionBar>
      <AppButton variant="secondary" :loading="internship.loading" @click="submitJournal('draft')">保存草稿</AppButton>
      <AppButton :loading="internship.loading" :disabled="isStageExpired('journal_deadline')" @click="submitJournal('wait')">
        {{ internship.forms.journal.id ? '重新提交审核' : '提交审核' }}
      </AppButton>
    </AppActionBar>
  </section>

  <section v-else-if="internship.submitSection === 'report'" class="mobile-card form-card">
    <header>
      <FileText :size="20" />
      <strong>实习报告</strong>
      <small :class="{ danger: isStageExpired('report_deadline') }">{{ stageDeadlineText('report_deadline') }}</small>
    </header>
    <label>
      <span>实习任务</span>
      <select v-model.number="internship.forms.report.arrangement_id">
        <option v-for="item in internship.options.arrangements" :key="item.id" :value="item.id">
          {{ item.title }}
        </option>
      </select>
    </label>
    <div class="stage-deadline-panel" :class="{ expired: isStageExpired('report_deadline') }">
      <span>{{ stageDeadlineDetailText('report_deadline') }}</span>
      <AppButton
        v-if="isStageExpired('report_deadline')"
        variant="secondary"
        size="small"
        @click="openDelayForStage('report_deadline')"
      >
        申请延期
      </AppButton>
    </div>
    <label>
      <span>标题</span>
      <input v-model="internship.forms.report.title">
    </label>
    <div class="report-type-note">
      <span>报告类型</span>
      <strong>{{ currentReportIsGraduation ? '毕业实习报告' : '普通实习报告' }}</strong>
    </div>
    <label v-if="currentReportIsGraduation">
      <span>实习单位简介</span>
      <textarea v-model="internship.forms.report.company_profile" rows="4" />
    </label>
    <label>
      <span>实习目的及要求</span>
      <textarea v-model="internship.forms.report.purpose" rows="4" />
    </label>
    <label>
      <span>{{ currentReportIsGraduation ? '实习主要内容及进程' : '实习内容' }}</span>
      <textarea v-model="internship.forms.report.content" rows="7" />
    </label>
    <label>
      <span>成果、收获与体会</span>
      <textarea v-model="internship.forms.report.gains" rows="5" />
    </label>
    <label>
      <span>{{ currentReportIsGraduation ? '对实习单位的建议' : '问题与建议' }}</span>
      <textarea v-model="internship.forms.report.suggestions" rows="4" />
    </label>
    <div class="material-upload-block">
      <span>报告附件</span>
      <label class="material-upload-button">
        <Paperclip :size="17" />
        <span>选择文件</span>
        <input type="file" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx" @change="event => uploadInternshipAttachments('report', event)">
      </label>
      <div v-if="internship.forms.report.attachments.length" class="material-file-list">
        <div v-for="file in internship.forms.report.attachments" :key="file.id">
          <FileText :size="16" />
          <span>{{ file.name }}</span>
          <button type="button" aria-label="移除附件" @click="removeInternshipAttachment('report', file.id)"><X :size="16" /></button>
        </div>
      </div>
    </div>
    <AppActionBar>
      <AppButton variant="secondary" :loading="internship.loading" @click="submitReport('draft')">保存草稿</AppButton>
      <AppButton :loading="internship.loading" :disabled="isStageExpired('report_deadline')" @click="submitReport('wait')">
        {{ internship.forms.report.id ? '重新提交审核' : '提交审核' }}
      </AppButton>
    </AppActionBar>
  </section>

  <section v-else-if="internship.submitSection === 'safety'" class="mobile-card form-card">
    <header>
      <CheckCircle2 :size="20" />
      <strong>安全承诺</strong>
    </header>
    <label>
      <span>实习任务</span>
      <select v-model.number="internship.forms.safety.arrangement_id">
        <option v-for="item in internship.options.arrangements" :key="item.id" :value="item.id">
          {{ item.title }}
        </option>
      </select>
    </label>
    <div class="safety-template-panel">
      <FileText :size="22" />
      <span><strong>学生实习安全承诺书</strong><small>下载模板，完成签署后上传 PDF 或 Word 定稿</small></span>
      <AppButton variant="secondary" size="small" @click="openSafetyTemplate">
        <template #icon><Download :size="16" /></template>
        查看模板
      </AppButton>
    </div>
    <div class="material-upload-block signed-upload">
      <span>签署定稿</span>
      <label class="material-upload-button">
        <Upload :size="17" />
        <span>{{ internship.forms.safety.file_name || '选择签署文件' }}</span>
        <input type="file" accept=".pdf,.doc,.docx" @change="uploadSafetyFinal">
      </label>
    </div>
    <AppButton block :loading="internship.loading" :disabled="!internship.forms.safety.signature_file_id" @click="submitSafetyLetter">
      提交安全承诺
    </AppButton>
  </section>

  <section v-else-if="internship.submitSection === 'delay'" class="mobile-card form-card">
    <header>
      <FileClock :size="20" />
      <strong>延期申请</strong>
    </header>
    <label>
      <span>实习任务</span>
      <select v-model.number="internship.forms.delay.arrangement_id">
        <option v-for="item in internship.options.arrangements" :key="item.id" :value="item.id">
          {{ item.title }}
        </option>
      </select>
    </label>
    <label>
      <span>申请模块</span>
      <select v-model="internship.forms.delay.config_key">
        <option v-for="item in delayConfigOptions()" :key="item.value" :value="item.value">
          {{ item.label }}
        </option>
      </select>
      <small>{{ stageDeadlineText(internship.forms.delay.config_key) }}</small>
    </label>
    <label>
      <span>申请延期至</span>
      <input v-model="internship.forms.delay.requested_date" type="date">
    </label>
    <label>
      <span>申请原因</span>
      <textarea v-model="internship.forms.delay.reason" rows="4" />
    </label>
    <AppActionBar>
      <AppButton variant="secondary" :loading="internship.loading" @click="submitDelay('draft')">保存草稿</AppButton>
      <AppButton :loading="internship.loading" @click="submitDelay('wait')">提交审核</AppButton>
    </AppActionBar>
  </section>
</template>

<script setup>
import { CheckCircle2, Download, FileClock, FileText, MapPin, Paperclip, Upload, X } from '@lucide/vue';
import AppActionBar from '../../components/ui/AppActionBar.vue';
import AppButton from '../../components/ui/AppButton.vue';
import { useInternshipContext } from './internshipContext';

const {
  coordinateText,
  currentReportIsGraduation,
  delayConfigOptions,
  internship,
  isStageExpired,
  locateSignPosition,
  openDelayForStage,
  openSafetyTemplate,
  removeInternshipAttachment,
  signAccuracyText,
  signGpsHint,
  signGpsReady,
  signGpsTitle,
  signMapUrl,
  stageDeadlineDetailText,
  stageDeadlineText,
  submitDelay,
  submitJournal,
  submitReport,
  submitSafetyLetter,
  submitSignIn,
  uploadInternshipAttachments,
  uploadSafetyFinal,
} = useInternshipContext();
</script>

<style scoped>
.material-upload-block {
  display: grid;
  gap: 8px;
}

.material-upload-block > span {
  color: var(--app-text-secondary);
  font-size: 13px;
  font-weight: 600;
}

.material-upload-button {
  min-height: var(--app-touch-size);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 0 14px;
  border: 1px dashed color-mix(in srgb, var(--app-primary) 45%, var(--app-line));
  border-radius: var(--app-radius);
  color: var(--app-primary);
  background: var(--app-primary-soft);
  font-size: 14px;
  font-weight: 600;
}

.material-upload-button input {
  display: none;
}

.material-file-list {
  display: grid;
  gap: 6px;
}

.material-file-list > div {
  min-height: 40px;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 8px;
  padding: 7px 10px;
  border: 1px solid var(--app-line);
  border-radius: var(--app-radius-small);
  background: var(--app-surface-muted);
}

.material-file-list span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.material-file-list button {
  width: 30px;
  height: 30px;
  display: grid;
  place-items: center;
  padding: 0;
  border: 0;
  color: var(--app-text-secondary);
  background: transparent;
}

.report-type-note,
.safety-template-panel {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px;
  border: 1px solid var(--app-line);
  border-radius: var(--app-radius);
  background: var(--app-surface-muted);
}

.report-type-note {
  justify-content: space-between;
  color: var(--app-text-secondary);
  font-size: 13px;
}

.report-type-note strong {
  color: var(--app-primary);
}

.safety-template-panel > span {
  min-width: 0;
  flex: 1;
  display: grid;
  gap: 3px;
}

.safety-template-panel small {
  color: var(--app-text-secondary);
  line-height: 1.45;
}

.signed-upload {
  margin: 4px 0 12px;
}
</style>
