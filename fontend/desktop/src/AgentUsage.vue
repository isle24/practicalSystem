<template>
  <details class="agent-usage">
    <summary>
      Token 用量<span v-if="turn"> · 本轮 {{ count(turn, 'total') }}</span><span v-if="context"> · 上下文 {{ context }}</span>
    </summary>
    <div v-if="turn || totals" class="usage-details">
      <table>
        <thead><tr><th>统计范围</th><th>输入</th><th>输出</th><th>合计</th></tr></thead>
        <tbody>
          <tr v-if="turn">
            <th scope="row">本轮</th>
            <td v-for="key in fields" :key="key">{{ count(turn, key) }}</td>
          </tr>
          <tr>
            <th scope="row">会话累计</th>
            <td v-for="key in fields" :key="key">{{ count(cumulative, key) }}</td>
          </tr>
        </tbody>
      </table>
      <p v-if="turn">
        {{ turn.model }} ·
        <template v-if="turn.context_tokens == null">最近一次请求未报告 Token 用量。</template>
        <template v-else-if="turn.context_input_only">上下文计入最近一次请求的输入。</template>
        <template v-else>上下文计入最近一次请求的输入和输出。</template>
        {{ turn.context_window ? '窗口上限取自模型目录。' : '模型窗口上限未知。' }}
      </p>
    </div>
    <p v-else>模型返回后显示本轮用量和会话累计。</p>
  </details>
</template>
<script setup>
import { computed } from 'vue';
const props = defineProps({ turn: Object, totals: Object, running: Boolean });
const fields = ['input', 'output', 'total'];
const number = new Intl.NumberFormat('zh-CN');
const format = value => Number.isSafeInteger(value) ? number.format(value) : '未报告';
const count = (data, key) => {
  const metric = data?.[key];
  if (!metric?.reports) return '未报告';
  return format(metric.tokens) + (metric.reports < data.calls ? '（部分）' : '');
};
const cumulative = computed(() => {
  if (!props.running || !props.turn) return props.totals;
  const values = { calls: (props.totals?.calls || 0) + props.turn.calls };
  for (const key of fields) values[key] = {
    tokens: (props.totals?.[key]?.tokens || 0) + props.turn[key].tokens,
    reports: (props.totals?.[key]?.reports || 0) + props.turn[key].reports,
  };
  return values;
});
const context = computed(() => {
  const used = props.turn?.context_tokens;
  if (!Number.isSafeInteger(used)) return '';
  const limit = props.turn.context_window;
  if (!limit) return `${format(used)} Token`;
  const percent = used > 0 && used < limit / 100 ? '<1' : Math.round(used / limit * 100);
  return `${format(used)} / ${format(limit)}（${percent}%）`;
});
</script>
<style scoped>
.agent-usage { color: var(--muted); font-size: 11px; }
.agent-usage summary { cursor: pointer; overflow-wrap: anywhere; }
.usage-details { display: grid; gap: 4px; padding: 5px 0; }
.agent-usage table { border-collapse: collapse; text-align: right; font-variant-numeric: tabular-nums; }
.agent-usage th, .agent-usage td { padding: 2px 8px; }
.agent-usage th:first-child { text-align: left; padding-left: 0; }
.agent-usage p { margin: 4px 0; }
</style>
