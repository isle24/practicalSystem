<template>
  <el-table :data="materials" border stripe class="archive-material-table" empty-text="暂无材料">
    <el-table-column prop="label" label="材料" min-width="180" />
    <el-table-column label="业务来源" min-width="150">
      <template #default="{ row }">
        <div class="archive-source-state">
          <el-tag :type="row.source_ready ? 'success' : 'warning'" effect="light">
            {{ row.source_ready ? '数据完整' : '待完善' }}
          </el-tag>
          <span>{{ row.source_text || '-' }}</span>
        </div>
      </template>
    </el-table-column>
    <el-table-column label="归档状态" width="110">
      <template #default="{ row }">
        <el-tag :type="row.archive_status === 'archived' ? 'success' : 'info'" effect="light">
          {{ row.archive_status_text || '待补齐' }}
        </el-tag>
      </template>
    </el-table-column>
    <el-table-column label="当前文件" min-width="200">
      <template #default="{ row }">
        <div v-if="row.material?.signed_file || row.material?.generated_file" class="archive-file-list">
          <button v-if="row.material?.signed_file" type="button" @click="$emit('open-file', row.material.signed_file)">
            <Stamp :size="15" />
            <span>{{ row.material.signed_file.name }}</span>
          </button>
          <button v-if="row.material?.generated_file" type="button" @click="$emit('open-file', row.material.generated_file)">
            <FileText :size="15" />
            <span>{{ row.material.generated_file.name }}</span>
          </button>
        </div>
        <span v-else class="archive-empty-file">尚未生成或上传</span>
      </template>
    </el-table-column>
    <el-table-column label="操作" width="520" fixed="right" align="right">
      <template #default="{ row }">
        <div class="archive-row-actions">
          <el-button
            v-if="row.material_type === 'graduation_appraisal' && (row.source_id || canEditAppraisal || canApprove)"
            :icon="ClipboardPen"
            size="small"
            type="primary"
            plain
            @click="$emit('appraisal', row)"
          >
            鉴定详情
          </el-button>
          <el-button
            v-if="templates[row.material_type]"
            :icon="Download"
            size="small"
            @click="$emit('open-template', row)"
          >
            模板
          </el-button>
          <el-button
            v-if="canManage && canGenerate(row)"
            :icon="FileCog"
            size="small"
            :loading="loadingKey === actionKey('generate', row)"
            @click="$emit('generate', row)"
          >
            生成
          </el-button>
          <el-button
            v-if="canManage"
            :icon="Upload"
            size="small"
            :loading="loadingKey === actionKey('upload', row)"
            @click="$emit('upload', row)"
          >
            上传定稿
          </el-button>
          <el-button
            v-if="row.material"
            :icon="History"
            size="small"
            @click="$emit('history', row)"
          >
            历史
          </el-button>
          <el-button
            v-if="canManage && canArchive(row)"
            :icon="Archive"
            size="small"
            type="primary"
            :loading="loadingKey === actionKey('archive', row)"
            @click="$emit('archive', row)"
          >
            归档
          </el-button>
        </div>
      </template>
    </el-table-column>
  </el-table>
</template>

<script setup>
import { Archive, ClipboardPen, Download, FileCog, FileText, History, Stamp, Upload } from '@lucide/vue';

defineProps({
  materials: { type: Array, default: () => [] },
  templates: { type: Object, default: () => ({}) },
  canManage: { type: Boolean, default: false },
  canEditAppraisal: { type: Boolean, default: false },
  canApprove: { type: Boolean, default: false },
  loadingKey: { type: String, default: '' },
});

defineEmits(['appraisal', 'archive', 'generate', 'history', 'open-file', 'open-template', 'upload']);

function targetKey(row) {
  return [row.material_type, row.plan_id || 0, row.arrangement_id || 0, row.student_id || 0, row.class_id || 0].join(':');
}

function actionKey(action, row) {
  return `${action}:${targetKey(row)}`;
}

function canGenerate(row) {
  if (row.material_type === 'safety_commitment') {
    return false;
  }
  return row.source_ready || (row.material_type === 'graduation_appraisal' && row.source_text === '已通过');
}

function canArchive(row) {
  if (!row.material || row.archive_status === 'archived') {
    return false;
  }
  return Boolean(row.material.signed_file || (row.material.generated_file && row.source_ready));
}
</script>

<style scoped>
.archive-material-table {
  width: 100%;
}

.archive-source-state,
.archive-file-list {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.archive-source-state span {
  color: #667085;
  font-size: 12px;
}

.archive-file-list {
  align-items: flex-start;
  flex-direction: column;
  gap: 4px;
}

.archive-file-list button {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  padding: 0;
  border: 0;
  color: #2563eb;
  background: transparent;
  cursor: pointer;
}

.archive-file-list button span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.archive-empty-file {
  color: #98a2b3;
  font-size: 12px;
}

.archive-row-actions {
  display: flex;
  justify-content: flex-end;
  gap: 6px;
  white-space: nowrap;
}
</style>
