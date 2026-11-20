<template>
  <section v-if="snapshot" class="archive-evidence">
    <dl>
      <div><dt>适用范围</dt><dd>{{ snapshot.plan?.scopes?.length ?? '—' }}</dd></div>
      <div><dt>师生关系</dt><dd>{{ snapshot.pairs?.length ?? '—' }}</dd></div>
      <div><dt>安全材料</dt><dd>{{ snapshot.safety?.length ?? '—' }}</dd></div>
      <div><dt>签到与补签</dt><dd>{{ snapshot.attendance?.length ?? '—' }} / {{ snapshot.patch_signs?.length ?? '—' }}</dd></div>
      <div><dt>材料记录</dt><dd>{{ snapshot.materials?.length ?? '—' }}</dd></div>
      <div><dt>审核意见</dt><dd>{{ snapshot.reviews?.length ?? '—' }}</dd></div>
      <div><dt>成绩</dt><dd>{{ snapshot.scores?.at(-1)?.final_score ?? '—' }}</dd></div>
    </dl>
    <button type="button" @click="downloadEvidence">下载本版本归档依据</button>
  </section>
</template>

<script setup>
const props = defineProps({
  snapshot: { type: Object, default: null },
  recordId: { type: [Number, String], default: '' },
  version: { type: [Number, String], default: 1 },
});

function downloadEvidence() {
  const href = URL.createObjectURL(new Blob([JSON.stringify(props.snapshot, null, 2)], { type: 'application/json;charset=utf-8' }));
  const link = document.createElement('a');
  link.href = href;
  link.download = `社会实践归档-${props.recordId}-第${props.version}版.json`;
  link.click();
  setTimeout(() => URL.revokeObjectURL(href), 1000);
}
</script>

<style scoped>
.archive-evidence,
.archive-evidence dl {
  display: grid;
  gap: 8px;
}
.archive-evidence dl {
  grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
  margin: 0;
}
.archive-evidence dd {
  margin: 4px 0 0;
}
.archive-evidence button {
  justify-self: start;
}
</style>
