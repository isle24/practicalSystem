<template>
  <section class="internship-edit-section">
    <header>
      <div><strong>{{ title }}</strong><small>支持维护多名人员</small></div>
      <div v-if="!readonly" class="person-row-actions">
        <el-button
          v-if="directory && canSync"
          :icon="RefreshCw"
          :loading="syncing"
          :disabled="syncing"
          size="small"
          @click="syncTeachers"
        >同步教师</el-button>
        <el-button :icon="Plus" size="small" @click="addRow">新增</el-button>
      </div>
    </header>
    <el-table v-if="readonly && model.length" :data="model" stripe size="small">
      <el-table-column prop="name" label="姓名" width="110" />
      <el-table-column v-if="directory" prop="teacher_num" label="教师编号" width="110" />
      <el-table-column v-if="directory" prop="dep_name" label="学院" min-width="140" show-overflow-tooltip />
      <el-table-column v-if="directory" prop="profession_name" label="专业" min-width="140" show-overflow-tooltip />
      <el-table-column prop="gender" label="性别" width="72" />
      <el-table-column prop="birth_date" label="出生日期" width="112" />
      <el-table-column prop="title" label="职务/职称" min-width="130" show-overflow-tooltip />
      <el-table-column prop="education" label="学历" width="100" />
      <el-table-column prop="phone" label="联系电话" width="130" />
      <el-table-column prop="duties" label="主要职责" min-width="220" show-overflow-tooltip />
    </el-table>
    <div v-else-if="model.length" class="dynamic-row-list">
      <div v-for="(item, index) in model" :key="`${title}-${index}`" class="dynamic-form-row person-row">
        <label v-if="directory" class="wide-field teacher-directory-field">
          <span>可从教师档案选择</span>
          <el-select
            v-model="item.teacher_id"
            clearable
            filterable
            remote
            reserve-keyword
            :loading="searching"
            :remote-method="searchTeachers"
            placeholder="输入姓名、工号或电话搜索"
            @change="teacherId => selectTeacher(item, teacherId)"
            @visible-change="visible => visible && searchTeachers('')"
          >
            <el-option
              v-for="teacher in availableTeacherOptions"
              :key="teacher.teacher_id"
              :label="teacherOptionLabel(teacher)"
              :value="teacher.teacher_id"
            />
          </el-select>
          <small v-if="item.teacher_id" class="teacher-directory-meta">
            {{ [item.dep_name, item.profession_name].filter(Boolean).join(' / ') || '未维护组织信息' }}
          </small>
        </label>
        <label><span>姓名</span><input v-model="item.name" :disabled="readonly"></label>
        <label>
          <span>性别</span>
          <el-select v-model="item.gender" clearable :disabled="readonly">
            <el-option label="男" value="男" />
            <el-option label="女" value="女" />
          </el-select>
        </label>
        <label><span>出生日期</span><input v-model="item.birth_date" type="date" :disabled="readonly"></label>
        <label><span>职务/职称</span><input v-model="item.title" :disabled="readonly"></label>
        <label><span>学历</span><input v-model="item.education" :disabled="readonly"></label>
        <label><span>联系电话</span><input v-model="item.phone" :disabled="readonly"></label>
        <label class="wide-field"><span>主要职责</span><input v-model="item.duties" :disabled="readonly"></label>
        <el-button v-if="!readonly" text type="danger" :icon="Trash2" @click="model.splice(index, 1)">删除</el-button>
      </div>
    </div>
    <el-empty v-else :description="`暂无${title}`" :image-size="52" />
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { ElMessage } from 'element-plus';
import { Plus, RefreshCw, Trash2 } from '@lucide/vue';
import { fetchTeacherSyncTeachers, pullTeacherSync } from '../api/system';

const props = defineProps({
  readonly: { type: Boolean, default: false },
  title: { type: String, required: true },
  directory: { type: Boolean, default: false },
  canSync: { type: Boolean, default: false },
});

const model = defineModel({ type: Array, default: () => [] });
const teacherOptions = ref([]);
const searching = ref(false);
const syncing = ref(false);
let searchTimer = null;
let searchSequence = 0;
const availableTeacherOptions = computed(() => {
  const values = new Map();
  for (const item of teacherOptions.value) {
    values.set(Number(item.teacher_id), item);
  }
  for (const item of model.value) {
    if (item.teacher_id && !values.has(Number(item.teacher_id))) {
      values.set(Number(item.teacher_id), { ...item, teacher_name: item.name });
    }
  }
  return [...values.values()];
});

function addRow() {
  model.value.push({
    user_id: null,
    teacher_id: null,
    teacher_num: '',
    name: '',
    dep_name: '',
    profession_name: '',
    gender: '',
    birth_date: '',
    title: '',
    education: '',
    phone: '',
    duties: '',
  });
}

function searchTeachers(keyword = '') {
  if (!props.directory) {
    return;
  }
  window.clearTimeout(searchTimer);
  const sequence = ++searchSequence;
  searchTimer = window.setTimeout(async () => {
    searching.value = true;
    try {
      const result = await fetchTeacherSyncTeachers({ keyword: String(keyword || '').trim(), page: 1, page_size: 50 });
      if (sequence === searchSequence) {
        teacherOptions.value = result.items || [];
      }
    } catch (error) {
      if (sequence === searchSequence) {
        teacherOptions.value = [];
        ElMessage.error(error.message || '教师档案查询失败');
      }
    } finally {
      if (sequence === searchSequence) {
        searching.value = false;
      }
    }
  }, String(keyword || '').trim() ? 260 : 0);
}

function selectTeacher(item, teacherId) {
  if (!teacherId) {
    return;
  }
  const teacher = availableTeacherOptions.value.find(option => Number(option.teacher_id) === Number(teacherId));
  if (!teacher) {
    return;
  }
  Object.assign(item, {
    teacher_id: Number(teacher.teacher_id),
    user_id: teacher.user_id || null,
    teacher_num: teacher.teacher_num || '',
    name: teacher.teacher_name || teacher.name || '',
    dep_name: teacher.dep_name || '',
    profession_name: teacher.profession_name || '',
    gender: genderText(teacher.gender),
    birth_date: String(teacher.birth_date || '').slice(0, 10),
    title: teacher.title || '',
    education: teacher.education || '',
    phone: teacher.phone || '',
    email: teacher.email || '',
  });
}

async function syncTeachers() {
  if (syncing.value) {
    return;
  }
  syncing.value = true;
  try {
    const result = await pullTeacherSync();
    const summary = `同步完成：新增 ${result.created || 0}，更新 ${result.updated || 0}，停用 ${result.disabled || 0}，失败 ${result.failed || 0}`;
    if (Number(result.failed || 0) > 0) {
      ElMessage.warning(`${summary}。${result.errors?.[0]?.message || '请检查同步结果'}`);
    } else {
      ElMessage.success(summary);
    }
    searchTeachers('');
  } catch (error) {
    ElMessage.error(error.message || '教师同步失败');
  } finally {
    syncing.value = false;
  }
}

function teacherOptionLabel(teacher) {
  const scope = [teacher.dep_name, teacher.profession_name].filter(Boolean).join(' / ');
  return [teacher.teacher_name || teacher.name || '-', teacher.teacher_num, scope].filter(Boolean).join(' · ');
}

function genderText(value) {
  const text = String(value || '').trim().toLowerCase();
  if (['male', 'm', '1', '男'].includes(text)) return '男';
  if (['female', 'f', '2', '女'].includes(text)) return '女';
  return value || '';
}

onBeforeUnmount(() => window.clearTimeout(searchTimer));
</script>

<style scoped>
.person-row-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.teacher-directory-field {
  position: relative;
}

.teacher-directory-meta {
  min-height: 18px;
  color: var(--muted);
  line-height: 18px;
}
</style>
