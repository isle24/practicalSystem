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
      <span>内容</span>
      <textarea v-model="internship.forms.journal.content" rows="4" />
    </label>
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
    <label>
      <span>内容</span>
      <textarea v-model="internship.forms.report.content" rows="4" />
    </label>
    <AppActionBar>
      <AppButton variant="secondary" :loading="internship.loading" @click="submitReport('draft')">保存草稿</AppButton>
      <AppButton :loading="internship.loading" :disabled="isStageExpired('report_deadline')" @click="submitReport('wait')">
        {{ internship.forms.report.id ? '重新提交审核' : '提交审核' }}
      </AppButton>
    </AppActionBar>
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
import { FileClock, FileText, MapPin } from '@lucide/vue';
import AppActionBar from '../../components/ui/AppActionBar.vue';
import AppButton from '../../components/ui/AppButton.vue';
import { useInternshipContext } from './internshipContext';

const {
  coordinateText,
  delayConfigOptions,
  internship,
  isStageExpired,
  locateSignPosition,
  openDelayForStage,
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
  submitSignIn,
} = useInternshipContext();
</script>
