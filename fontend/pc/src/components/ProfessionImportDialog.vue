<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Download, Upload } from '@lucide/vue';
import { ElMessage } from 'element-plus';
import OperationDialog from './OperationDialog.vue';
import {
  confirmProfessionImport,
  previewProfessionImport,
  professionImportTemplateUrl,
} from '../api/system';

const props = defineProps({
  visible: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'completed']);
const fileInput = ref(null);
const state = reactive({
  loading: false,
  previewLoading: false,
  fileName: '',
  fileId: 0,
  items: [],
  summary: null,
  message: '',
});

const canConfirm = computed(() => Boolean(state.fileId && Number(state.summary?.valid_rows) > 0 && !state.loading && !state.previewLoading));

function reset() {
  state.loading = false;
  state.previewLoading = false;
  state.fileName = '';
  state.fileId = 0;
  state.items = [];
  state.summary = null;
  state.message = '';
}

function close() {
  if (state.loading || state.previewLoading) return;
  emit('close');
}

async function handleFileChange(event) {
  const file = event.target?.files?.[0];
  event.target.value = '';
  if (!file || state.previewLoading) return;
  state.previewLoading = true;
  state.message = '';
  state.fileName = file.name;
  try {
    const data = await previewProfessionImport(file);
    state.fileId = Number(data.file?.id || data.file?.file_id || 0);
    state.items = data.items || [];
    state.summary = data.summary || null;
  } catch (error) {
    state.fileId = 0;
    state.items = [];
    state.summary = null;
    state.message = error.message;
  } finally {
    state.previewLoading = false;
  }
}

async function confirm(skipErrors) {
  if (!canConfirm.value) return;
  state.loading = true;
  state.message = '';
  try {
    const result = await confirmProfessionImport({ file_id: state.fileId, skip_errors: skipErrors });
    emit('completed', result);
    emit('close');
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

watch(() => props.visible, (visible) => {
  if (visible) reset();
});
</script>

<template>
  <OperationDialog :visible="visible" title="导入专业信息" :busy="state.loading || state.previewLoading" dialog-class="profession-import-dialog" @close="close">
    <div class="profession-import-body">
      <p>按模板导入专业基础档案。所属学院支持全称、已维护简称及去除空白字符后的名称。</p>
      <div class="profession-import-actions">
        <a :href="professionImportTemplateUrl()" class="profession-import-template" target="_blank" rel="noopener noreferrer"><Download :size="15" />下载专业导入模板</a>
        <el-button :icon="Upload" :loading="state.previewLoading" :disabled="state.loading" @click="fileInput?.click()">选择文件并预览</el-button>
        <span>{{ state.fileName }}</span>
        <input ref="fileInput" type="file" accept=".xls,.xlsx" hidden @change="handleFileChange">
      </div>
      <el-alert v-if="state.message" :title="state.message" type="error" :closable="false" />
      <template v-if="state.summary">
        <div class="profession-import-summary">
          <span>原始行 {{ state.summary.source_rows || 0 }}</span>
          <span>可导入 {{ state.summary.valid_rows || 0 }}</span>
          <span>错误行 {{ state.summary.error_rows || 0 }}</span>
          <span>重复行 {{ state.summary.duplicate_rows || 0 }}</span>
        </div>
        <el-alert v-if="state.summary.error_rows" title="存在错误行。确认时可选择跳过错误行，只导入可导入的数据。" type="warning" :closable="false" />
        <el-table :data="state.items" height="360" stripe size="small">
          <el-table-column prop="row_number" label="行号" width="68" />
          <el-table-column prop="dep_name" label="所属学院" min-width="150" />
          <el-table-column prop="profession_code" label="专业代码" width="110" />
          <el-table-column prop="profession_name" label="专业名称" min-width="170" />
          <el-table-column label="校验结果" min-width="260">
            <template #default="{ row }">
              <span v-if="row.errors?.length" class="profession-import-error">{{ row.errors.join('；') }}</span>
              <span v-else-if="row.duplicate" class="profession-import-warning">重复数据将跳过</span>
              <span v-else class="profession-import-ok">可导入</span>
            </template>
          </el-table-column>
        </el-table>
      </template>
    </div>
    <template #footer>
      <el-button :disabled="state.loading" @click="close">取消</el-button>
      <el-button v-if="state.summary?.error_rows" type="warning" :loading="state.loading" :disabled="!canConfirm" @click="confirm(true)">跳过错误行并导入</el-button>
      <el-button v-else type="primary" :loading="state.loading" :disabled="!canConfirm" @click="confirm(false)">确认导入</el-button>
    </template>
  </OperationDialog>
</template>

<style scoped>
:global(.operation-dialog.profession-import-dialog) { width: min(940px, calc(100vw - 36px)); }
.profession-import-body { display: grid; gap: 12px; min-height: 0; padding: 18px; overflow: auto; }
.profession-import-body p { margin: 0; font-size: 13px; line-height: 1.7; }
.profession-import-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.profession-import-actions > span { overflow-wrap: anywhere; font-size: 13px; }
.profession-import-template { display: inline-flex; align-items: center; gap: 5px; color: var(--el-color-primary); font-size: 13px; text-decoration: none; }
.profession-import-summary { display: flex; flex-wrap: wrap; gap: 18px; color: var(--el-text-color-secondary); font-size: 13px; }
.profession-import-error { color: var(--el-color-danger); }
.profession-import-warning { color: var(--el-color-warning); }
.profession-import-ok { color: var(--el-color-success); }
</style>
