<template>
  <section class="practice-schedule-board">
    <header class="schedule-board-toolbar">
      <div>
        <strong>周课表</strong>
        <small>{{ weekRangeText }}</small>
      </div>
      <div class="schedule-board-actions">
        <el-button text @click="emit('previous-week')">上一周</el-button>
        <el-button text @click="emit('today')">本周</el-button>
        <el-button text @click="emit('next-week')">下一周</el-button>
      </div>
    </header>

    <div v-if="!periods.length" class="schedule-board-empty">暂无启用课节</div>
    <div v-else class="schedule-board-scroll">
      <div class="schedule-board-grid" :style="gridStyle">
        <div class="schedule-board-corner">课节</div>
        <div v-for="day in days" :key="day.date" class="schedule-board-day" :class="{ today: day.today }">
          <strong>{{ day.weekday }}</strong>
          <span>{{ day.date.slice(5) }}</span>
        </div>

        <template v-for="(period, periodIndex) in periods" :key="period.id">
          <div class="schedule-board-period" :style="{ gridRow: periodIndex + 2 }">
            <strong>{{ period.name }}</strong>
            <span>{{ timeText(period) }}</span>
          </div>
          <button
            v-for="day in days"
            :key="`${day.date}-${period.id}`"
            type="button"
            class="schedule-board-cell"
            :style="{ gridColumn: day.index + 2, gridRow: periodIndex + 2 }"
            :disabled="readonly"
            :aria-label="`${day.date} ${period.name}`"
            @click="emit('create', { schedule_date: day.date, period_start_id: period.id, period_end_id: period.id })"
          />
        </template>

        <button
          v-for="item in scheduleItems"
          :key="item.id"
          type="button"
          class="schedule-board-item"
          :class="item.module_type === 'lab' ? 'lab' : 'training'"
          :style="item.style"
          :disabled="readonly"
          @click.stop="emit('edit', item.row)"
        >
          <strong>{{ item.row.title || item.row.course_name || '未命名课程' }}</strong>
          <span>{{ moduleTypeText(item.row.module_type) }} / {{ item.row.teacher_name || '未设置教师' }}</span>
          <span>{{ item.row.room_name || item.row.base_name || item.row.location || '未设置场地' }}</span>
        </button>
      </div>
      <div v-if="!loading && !scheduleItems.length" class="schedule-board-empty-overlay">本周暂无排课</div>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  weekStart: { type: String, required: true },
  periods: { type: Array, default: () => [] },
  rows: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['create', 'edit', 'previous-week', 'next-week', 'today']);

const days = computed(() => Array.from({ length: 7 }, (_, index) => {
  const date = addDays(props.weekStart, index);
  return {
    index,
    date,
    weekday: `周${['一', '二', '三', '四', '五', '六', '日'][index]}`,
    today: date === formatDate(new Date()),
  };
}));

const weekRangeText = computed(() => {
  const start = days.value[0]?.date || '';
  const end = days.value.at(-1)?.date || '';
  return start && end ? `${start} 至 ${end}` : '';
});

const gridStyle = computed(() => ({
  gridTemplateRows: `54px repeat(${props.periods.length}, minmax(62px, 1fr))`,
}));

const periodIndexById = computed(() => new Map(props.periods.map((period, index) => [Number(period.id), index])));

const scheduleItems = computed(() => props.rows
  .map((row) => {
    const dayIndex = days.value.findIndex(day => day.date === row.schedule_date);
    const startIndex = resolvePeriodIndex(row.period_start_id, row.start_time, 'start');
    const endIndex = resolvePeriodIndex(row.period_end_id, row.end_time, 'end');
    if (dayIndex < 0 || startIndex === undefined) {
      return null;
    }
    const span = Math.max(1, (endIndex === undefined ? startIndex : endIndex) - startIndex + 1);
    return {
      id: row.id,
      row,
      module_type: row.module_type,
      style: {
        gridColumn: dayIndex + 2,
        gridRow: `${startIndex + 2} / span ${span}`,
      },
    };
  })
  .filter(Boolean));

function resolvePeriodIndex(periodId, time, edge) {
  const direct = periodIndexById.value.get(Number(periodId));
  if (direct !== undefined) {
    return direct;
  }
  const target = timeMinutes(time);
  if (target === null || !props.periods.length) {
    return undefined;
  }
  let matched;
  let distance = Number.POSITIVE_INFINITY;
  props.periods.forEach((period, index) => {
    const value = timeMinutes(edge === 'end' ? period.end_time : period.start_time);
    if (value === null) {
      return;
    }
    const nextDistance = Math.abs(value - target);
    if (nextDistance < distance) {
      matched = index;
      distance = nextDistance;
    }
  });
  return matched;
}

function timeMinutes(value) {
  const match = String(value || '').match(/^(\d{1,2}):(\d{2})/);
  return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

function timeText(period) {
  return [period.start_time, period.end_time].filter(Boolean).map(value => String(value).slice(0, 5)).join('-');
}

function moduleTypeText(value) {
  return value === 'lab' ? '实验' : '实训';
}

function addDays(value, offset) {
  const date = new Date(`${value}T00:00:00`);
  date.setDate(date.getDate() + offset);
  return formatDate(date);
}

function formatDate(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}
</script>
