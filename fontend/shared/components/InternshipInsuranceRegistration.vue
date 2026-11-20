<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import FormFieldLabel from './FormFieldLabel.vue';

const props = defineProps({
  loadPairs: { type: Function, required: true },
  saveInsurance: { type: Function, required: true },
  uploadFile: { type: Function, required: true },
});
const emit = defineEmits(['saved']);
const form = reactive({ start_date: '', end_date: '', insurance_company: '', policy_number: '', insured_amount: '' });
const search = reactive({ keyword: '', page: 1, page_size: 20, total: 0, items: [], loading: false, message: '' });
const selection = ref(null);
const attachment = ref(null);
const saving = ref(false);
const message = ref('');
let revision = 0;
let active = true;
const pairs = computed(() => selection.value && !search.items.some(item => item.id === selection.value.id)
  ? [selection.value, ...search.items] : search.items);

async function readPairs(page = 1) {
  const ticket = ++revision;
  search.loading = true;
  search.message = '';
  search.items = [];
  try {
    const data = await props.loadPairs({ status: 'active', page, page_size: search.page_size, keyword: search.keyword.trim() });
    if (!active || ticket !== revision) return;
    search.items = data.items || [];
    Object.assign(search, data.pagination);
    if (selection.value) {
      selection.value = search.items.find(item => item.id === selection.value.id) || selection.value;
    }
  } catch (error) {
    if (active && ticket === revision) search.message = error.message;
  } finally {
    if (active && ticket === revision) search.loading = false;
  }
}

async function save() {
  if (saving.value || search.loading || !selection.value?.can_register_insurance || !attachment.value) return;
  if (form.end_date < form.start_date) { message.value = '保险结束日期须在开始日期当日或之后'; return; }
  saving.value = true;
  message.value = '';
  try {
    const file = await props.uploadFile(attachment.value, { category: 'internship_material', is_temporary: 'false' });
    if (!active) return;
    await props.saveInsurance({
      ...form,
      student_id: selection.value.student_id,
      arrangement_id: selection.value.arrangement_id,
      attachment_id: file.file_id,
      insured_amount: form.insured_amount === '' ? null : Number(form.insured_amount),
    });
    if (active) emit('saved');
  } catch (error) {
    if (active) message.value = error.message;
  } finally {
    if (active) saving.value = false;
  }
}

onMounted(() => readPairs());
onBeforeUnmount(() => { active = false; });
</script>

<template>
  <form class="insurance-registration-form" @submit.prevent="save">
    <p>选择当前有效的学生实习绑定，填写保障日期并上传保单。</p>
    <fieldset :disabled="saving">
      <label>搜索绑定
        <div class="insurance-registration-search">
          <input v-model="search.keyword" type="search" placeholder="学生、学号或实习任务" @keydown.enter.prevent="readPairs()">
          <button type="button" :disabled="search.loading" @click="readPairs()">搜索</button>
        </div>
      </label>
      <label><FormFieldLabel :required="true">学生及实习任务</FormFieldLabel>
        <select v-model="selection" :disabled="search.loading" required>
          <option :value="null" disabled>请选择有效绑定</option>
          <option v-for="pair in pairs" :key="pair.id" :value="pair" :disabled="!pair.can_register_insurance">
            {{ [pair.student_name, pair.student_num, pair.arrangement_title, pair.insurance_blocker].filter(Boolean).join(' / ') }}
          </option>
        </select>
      </label>
      <p v-if="search.loading || search.message || !search.items.length" class="insurance-registration-help" role="status">
        {{ search.loading ? '正在读取绑定' : search.message || '暂无符合条件的学生实习绑定' }}
      </p>
      <div v-if="search.total > search.page_size" class="insurance-registration-pages">
        <button type="button" :disabled="search.loading || search.page <= 1" @click="readPairs(search.page - 1)">上一页</button>
        <span>{{ search.page }} / {{ Math.ceil(search.total / search.page_size) }}</span>
        <button type="button" :disabled="search.loading || search.page * search.page_size >= search.total" @click="readPairs(search.page + 1)">下一页</button>
      </div>
      <div class="insurance-registration-dates">
        <label><FormFieldLabel :required="true">保险开始日期</FormFieldLabel><input v-model="form.start_date" type="date" required></label>
        <label><FormFieldLabel :required="true">保险结束日期</FormFieldLabel><input v-model="form.end_date" type="date" :min="form.start_date" required></label>
      </div>
      <label><FormFieldLabel :required="true">保单附件</FormFieldLabel><input type="file" required @change="attachment = $event.target.files?.[0] || null"></label>
      <label><FormFieldLabel :required="false">保险公司</FormFieldLabel><input v-model.trim="form.insurance_company" maxlength="120"></label>
      <label><FormFieldLabel :required="false">保单号</FormFieldLabel><input v-model.trim="form.policy_number" maxlength="120"></label>
      <label><FormFieldLabel :required="false">保额</FormFieldLabel><input v-model="form.insured_amount" type="number" step="0.01" inputmode="decimal"></label>
    </fieldset>
    <p v-if="message" class="insurance-registration-help" role="alert">{{ message }}</p>
    <button type="submit" class="insurance-registration-save" :disabled="saving || search.loading || !selection?.can_register_insurance">
      {{ saving ? '正在保存' : '保存保险登记' }}
    </button>
  </form>
</template>

<style scoped>
.insurance-registration-form { display: grid; gap: 12px; color: var(--app-text, var(--text)); }
.insurance-registration-form p { margin: 0; overflow-wrap: anywhere; }
.insurance-registration-form fieldset { display: grid; gap: 12px; min-width: 0; margin: 0; padding: 0; border: 0; }
.insurance-registration-form label { display: grid; gap: 6px; min-width: 0; font-size: 14px; }
.insurance-registration-form input,.insurance-registration-form select,.insurance-registration-form button { box-sizing: border-box; min-height: 40px; min-width: 0; padding: 8px 10px; border: 1px solid var(--app-line, var(--line)); border-radius: 6px; background: var(--app-surface, var(--surface)); color: inherit; font: inherit; }
.insurance-registration-form input,.insurance-registration-form select { width: 100%; }
.insurance-registration-form button:disabled { opacity: .55; }
.insurance-registration-search,.insurance-registration-pages { display: flex; gap: 8px; align-items: center; }
.insurance-registration-search button { flex-shrink: 0; }
.insurance-registration-dates { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.insurance-registration-form .insurance-registration-save { justify-self: end; color: #fff; background: var(--app-primary, var(--primary)); }
.insurance-registration-help { color: var(--app-text-secondary, var(--muted)); font-size: 13px; }
@media (max-width: 400px) { .insurance-registration-dates { grid-template-columns: 1fr; } }
</style>
