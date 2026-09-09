<template>
  <OperationDialog
    :visible="modelValue"
    title="归档材料要求"
    :busy="loading || saving"
    dialog-class="archive-requirement-dialog"
    @close="close"
  >
    <div class="archive-requirement-body">
      <nav class="archive-requirement-tabs" aria-label="实习类别">
        <button
          v-for="item in practiceTypes"
          :key="item.value"
          type="button"
          :class="{ active: practiceType === item.value }"
          @click="switchType(item.value)"
        >
          {{ item.label }}
        </button>
      </nav>

      <el-alert
        v-if="message"
        :title="message"
        type="warning"
        :closable="false"
        show-icon
      />

      <div v-loading="loading" class="archive-requirement-list">
        <article v-for="item in items" :key="item.material_type">
          <div>
            <strong>{{ materialLabel(item.material_type) }}</strong>
            <span>{{ scopeLabel(item.material_type) }}</span>
          </div>
          <el-switch
            v-model="item.required"
            inline-prompt
            active-text="必交"
            inactive-text="不适用"
            :disabled="loading || saving"
          />
        </article>
        <el-empty v-if="!loading && !items.length" description="暂无可配置材料" :image-size="72" />
      </div>
    </div>

    <template #footer>
      <el-button :disabled="saving" @click="close">取消</el-button>
      <el-button type="primary" :loading="saving" :disabled="loading || !items.length" @click="save">
        保存配置
      </el-button>
    </template>
  </OperationDialog>
</template>

<script setup>
import { ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import {
  fetchInternshipArchiveRequirements,
  saveInternshipArchiveRequirements,
} from '../api/system';
import OperationDialog from './OperationDialog.vue';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'saved']);
const practiceTypes = [
  { value: 'graduation', label: '毕业实习' },
  { value: 'internship', label: '其他实习' },
];
const practiceType = ref('graduation');
const loading = ref(false);
const saving = ref(false);
const message = ref('');
const items = ref([]);
const materials = ref([]);

watch(() => props.modelValue, visible => {
  if (visible) load();
});

/** 切换归档实践类别。 */
function switchType(value) {
  if (value === practiceType.value || loading.value || saving.value) return;
  practiceType.value = value;
  load();
}

/** 加载当前类别的归档要求。 */
async function load() {
  loading.value = true;
  message.value = '';
  try {
    const data = await fetchInternshipArchiveRequirements({ practice_type: practiceType.value });
    materials.value = data.materials || [];
    items.value = (data.items || []).map((item, index) => ({
      material_type: item.material_type,
      required: Boolean(Number(item.required)) || item.required === true,
      sort: Number.isFinite(Number(item.sort)) ? Number(item.sort) : index,
    }));
  } catch (error) {
    items.value = [];
    message.value = error.message;
  } finally {
    loading.value = false;
  }
}

/** 保存当前类别的归档要求。 */
async function save() {
  if (saving.value || loading.value) return;
  saving.value = true;
  message.value = '';
  try {
    await saveInternshipArchiveRequirements({
      practice_type: practiceType.value,
      items: items.value.map((item, index) => ({
        material_type: item.material_type,
        required: item.required,
        sort: item.sort ?? index,
      })),
    });
    ElMessage.success('归档材料要求已保存');
    emit('saved');
    emit('update:modelValue', false);
  } catch (error) {
    message.value = error.message;
  } finally {
    saving.value = false;
  }
}

/** 关闭配置弹窗。 */
function close() {
  if (!loading.value && !saving.value) emit('update:modelValue', false);
}

/** 返回材料名称。 */
function materialLabel(type) {
  return materials.value.find(item => item.material_type === type)?.label || type;
}

/** 返回材料归属层级。 */
function scopeLabel(type) {
  const scope = materials.value.find(item => item.material_type === type)?.scope_type;
  return ({ plan: '计划级', arrangement: '任务级', student_task: '学生任务级', plan_class: '任务班级级' }[scope] || '归档材料');
}
</script>

<style scoped>
.archive-requirement-body {
  min-height: 0;
  display: grid;
  grid-template-rows: auto auto minmax(0, 1fr);
  gap: 12px;
  overflow: hidden;
  padding: 8px 20px 18px;
}

.archive-requirement-tabs {
  min-height: 42px;
  display: flex;
  align-items: flex-end;
  gap: 26px;
  border-bottom: 1px solid var(--line);
}

.archive-requirement-tabs button {
  position: relative;
  height: 42px;
  border: 0;
  padding: 0 2px;
  color: var(--muted);
  background: transparent;
  font: inherit;
  cursor: pointer;
}

.archive-requirement-tabs button.active {
  color: var(--primary);
  font-weight: 600;
}

.archive-requirement-tabs button.active::after {
  position: absolute;
  right: 0;
  bottom: -1px;
  left: 0;
  height: 2px;
  background: var(--primary);
  content: '';
}

.archive-requirement-list {
  min-height: 280px;
  overflow: auto;
  border: 1px solid var(--line);
  border-radius: 6px;
  background: var(--surface);
}

.archive-requirement-list article {
  min-height: 62px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 10px 16px;
  border-bottom: 1px solid var(--line);
}

.archive-requirement-list article:last-child { border-bottom: 0; }
.archive-requirement-list article > div { min-width: 0; display: grid; gap: 4px; }
.archive-requirement-list strong { color: var(--text); font-size: 14px; }
.archive-requirement-list span { color: var(--muted); font-size: 12px; }

:global(.archive-requirement-dialog) {
  width: min(760px, calc(100vw - 40px));
  height: min(680px, calc(100vh - 108px));
}
</style>
