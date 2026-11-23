<script setup>
import { computed } from 'vue';
import FormFieldLabel from '../../../shared/components/FormFieldLabel.vue';

const props = defineProps({ field: { type: Object, required: true } });
const model = defineModel({ type: String, default: '' });
const date = computed(() => model.value.slice(0, 10));
const hour = computed(() => model.value.slice(11, 13));
const minute = computed(() => model.value.slice(14, 16));
const minutes = Array.from({ length: 60 }, (_, index) => String(index).padStart(2, '0'));
const hours = minutes.slice(0, 24);

function outsideWindow(time) {
  const value = `${date.value}T${time}`;
  const { min, max } = props.field;
  return value < min.slice(0, value.length) || Boolean(max && value > max.slice(0, value.length));
}

function changeTime(day, selectedHour, selectedMinute) {
  if (!day) {
    model.value = '';
    return;
  }
  const { min, max } = props.field;
  let value = `${day}T${selectedHour || '00'}:${selectedMinute || '00'}`;
  if (day === min.slice(0, 10) && value < min) value = min;
  if (day === max.slice(0, 10) && value > max) value = max;
  model.value = value;
}
</script>

<template>
  <fieldset class="plan-date-field">
    <legend><FormFieldLabel submit-required :help="field.help" :error="field.error">{{ field.label }}</FormFieldLabel></legend>
    <div class="plan-date-controls">
      <input type="date" :name="field.key" :aria-label="`${field.label}日期`" :aria-invalid="Boolean(field.error)" :value="date" :min="field.min.slice(0, 10)" :max="field.max.slice(0, 10)" @input="changeTime($event.target.value, hour, minute)">
      <el-select :model-value="hour" :disabled="!date" :persistent="false" :aria-label="`${field.label}小时`" placeholder="时" @change="value => changeTime(date, value, minute)">
        <el-option v-for="value in hours" :key="value" :value="value" :label="value" :disabled="outsideWindow(value)" />
      </el-select>
      <span aria-hidden="true">:</span>
      <el-select :model-value="minute" :disabled="!date" :persistent="false" :aria-label="`${field.label}分钟`" placeholder="分" @change="value => changeTime(date, hour, value)">
        <el-option v-for="value in minutes" :key="value" :value="value" :label="value" :disabled="outsideWindow(`${hour}:${value}`)" />
      </el-select>
    </div>
  </fieldset>
</template>

<style scoped>
.plan-date-field { min-width: 0; margin: 0; padding: 0; border: 0; container-type: inline-size; }
.plan-date-field legend { padding: 0 0 6px; }
.plan-date-controls { display: grid; grid-template-columns: minmax(0, 1fr) 62px auto 62px; align-items: center; gap: 4px; }
.plan-date-controls input { min-width: 0; width: 100%; height: var(--control-height); box-sizing: border-box; padding: 0 8px; border: 1px solid var(--line); border-radius: var(--control-radius); background: var(--surface); color: var(--text); font: inherit; }
@container (max-width: 260px) {
  .plan-date-controls { grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); }
  .plan-date-controls input[type="date"] { grid-column: 1 / -1; }
}
</style>
