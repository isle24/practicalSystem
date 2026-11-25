<template>
  <details class="agent-usage" @keydown.esc.stop="closeDetails" @focusout="closeWhenFocusLeaves">
    <summary title="展开 Token 用量详情">
      <span v-if="turn?.calls" class="usage-item">
        <Gauge :size="14" aria-hidden="true" />
        <span title="本次运行中已收到响应的模型请求">{{ format(turn.calls) }} 次模型</span>
        <span v-if="Number.isSafeInteger(turn.tool_calls)" class="usage-extra">{{ format(turn.tool_calls) }} 次工具</span>
        <span v-if="outputRate !== null" class="usage-extra" title="本次运行的平均输出速率，含模型请求往返耗时">{{ rate.format(outputRate) }} Token/s</span>
      </span>
      <span class="usage-item">
        <Database :size="14" aria-hidden="true" />
        <span>{{ totalLabel }}</span>
        <span v-if="cacheRate !== null" class="usage-extra" title="本次运行中模型报告的输入 Token 缓存命中率">缓存命中 {{ rate.format(cacheRate) }}%</span>
      </span>
      <span v-if="context" class="usage-item" :title="context">
        <ChartPie :size="14" aria-hidden="true" />
        <span>上下文 {{ contextPercent ?? context }}</span>
      </span>
      <ChevronUp :size="13" class="usage-chevron" aria-hidden="true" />
    </summary>
    <div v-if="turn || totals" class="usage-details">
      <table>
        <thead><tr><th>统计范围</th><th>输入</th><th>输出</th><th>合计</th></tr></thead>
        <tbody>
          <tr v-if="turn">
            <th scope="row">本次运行</th>
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
        <template v-else>最近一次请求占用 {{ context }}。{{ turn.context_input_only ? '仅计输入。' : '计入输入和输出。' }}</template>
        {{ turn.context_window ? '窗口上限取自模型目录。' : '模型窗口上限未知。' }}
      </p>
    </div>
    <div v-else class="usage-details">模型返回后会显示本次用量和会话累计。</div>
  </details>
</template>
<script setup>
import { computed } from 'vue';
import { ChartPie, ChevronUp, Database, Gauge } from '@lucide/vue';
const props = defineProps({ turn: Object, totals: Object, running: Boolean });
const fields = ['input', 'output', 'total'];
const number = new Intl.NumberFormat('zh-CN');
const compact = new Intl.NumberFormat('zh-CN', { notation: 'compact', maximumFractionDigits: 1 });
const rate = new Intl.NumberFormat('zh-CN', { maximumFractionDigits: 1 });
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
const totalLabel = computed(() => {
  const total = cumulative.value?.total;
  if (!total?.reports) return cumulative.value?.calls ? 'Token 用量未报告' : 'Token 用量';
  const amount = total.tokens >= 10000 ? compact.format(total.tokens) : format(total.tokens);
  return `会话 ${amount} Token${total.reports < cumulative.value.calls ? '（部分）' : ''}`;
});
const outputRate = computed(() => Number.isFinite(props.turn?.output_rate) ? props.turn.output_rate : null);
const cacheRate = computed(() => Number.isFinite(props.turn?.cache_hit_percent) ? props.turn.cache_hit_percent : null);
const contextPercent = computed(() => {
  const used = props.turn?.context_tokens, limit = props.turn?.context_window;
  if (!Number.isSafeInteger(used) || !limit) return null;
  if (used > 0 && used < limit / 100) return '<1%';
  return Math.round(used / limit * 100) + '%';
});
const context = computed(() => {
  const used = props.turn?.context_tokens;
  if (!Number.isSafeInteger(used)) return '';
  const limit = props.turn.context_window;
  if (!limit) return `${format(used)} Token`;
  return `${format(used)} / ${format(limit)} Token（${contextPercent.value}）`;
});
function closeDetails(event) {
  if (!event.currentTarget.open) return;
  event.preventDefault();
  event.currentTarget.open = false;
  event.currentTarget.querySelector('summary')?.focus();
}
function closeWhenFocusLeaves(event) {
  if (!event.currentTarget.contains(event.relatedTarget)) event.currentTarget.open = false;
}
</script>
<style scoped>
.agent-usage { min-width: 0; flex: 1; position: relative; color: var(--muted); font-size: 11px; }
.agent-usage summary {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 5px clamp(8px, 1.2vw, 18px);
  color: inherit;
  cursor: pointer;
  list-style: none;
  min-height: 23px;
  font-variant-numeric: tabular-nums;
}
.agent-usage summary::-webkit-details-marker { display: none; }
.usage-item, .usage-extra { display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
.usage-extra::before { content: '·'; margin-right: 2px; }
.usage-item svg, .usage-chevron { flex: none; }
.agent-usage[open] .usage-chevron { transform: rotate(180deg); }
.usage-details {
  position: absolute;
  z-index: 4;
  right: 0;
  bottom: calc(100% + 8px);
  width: min(420px, 100%);
  display: grid;
  gap: 7px;
  max-height: 180px;
  overflow: auto;
  padding: 11px 12px;
  color: inherit;
  border: 1px solid var(--border);
  border-radius: 13px;
  background: var(--surface);
  box-shadow: 0 9px 24px #12192324;
}
.agent-usage table { border-collapse: collapse; text-align: right; font-variant-numeric: tabular-nums; }
.agent-usage th, .agent-usage td { padding: 4px 5px; }
.agent-usage th:first-child { text-align: left; padding-left: 0; }
.agent-usage p { margin: 0; line-height: 1.6; overflow-wrap: anywhere; }
</style>
