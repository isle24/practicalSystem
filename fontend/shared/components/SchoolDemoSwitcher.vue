<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { leaveSchoolDemo, schoolDemo, selectSchoolDemo } from '../schoolDemo';

const props = defineProps({ request: { type: Function, required: true }, platform: { type: String, required: true }, actor: { type: Object, required: true } });
const open = ref(false);
const keyword = ref('');
const rows = ref([]);
const more = ref(false);
const busy = ref(false);
const searching = ref(false);
const error = ref('');
let timer;
let generation = 0;
watch([keyword, open], ([value, showing]) => {
  clearTimeout(timer);
  const version = ++generation;
  rows.value = [];
  more.value = false;
  error.value = '';
  if (!showing || !schoolDemo.enabled) return;
  searching.value = true;
  timer = setTimeout(async () => {
    try {
      const result = await props.request('/school-demo/accounts?' + new URLSearchParams({ keyword: value.trim() }));
      if (version !== generation) return;
      rows.value = result.items;
      more.value = result.has_more;
    } catch (failure) {
      if (version === generation) error.value = failure.message;
    } finally {
      if (version === generation) searching.value = false;
    }
  }, value ? 200 : 0);
});
onBeforeUnmount(() => { clearTimeout(timer); generation++; });
async function choose(id, roleId) {
  if (busy.value) return;
  busy.value = true;
  try { await selectSchoolDemo(props.request, props.platform, id, roleId); }
  catch (failure) { error.value = failure.message; busy.value = false; }
}
</script>

<template>
  <aside v-if="schoolDemo.available && (schoolDemo.enabled || schoolDemo.selection)" class="school-demo" :class="{ mobile: platform === 'h5' }" @click.stop @contextmenu.stop>
    <button class="demo-trigger" type="button" :aria-expanded="open" @click="open = !open">
      {{ schoolDemo.selection ? (schoolDemo.error ? '代办已暂停' : '代办中 · ' + schoolDemo.subject.name) : '业务演示' }}
    </button>
    <section v-if="open" class="demo-popover" aria-label="业务演示办理人" @keydown.esc="open = false">
      <header><strong>业务演示</strong><button type="button" :disabled="busy" @click="open = false">关闭</button></header>
      <p>实际办理人 {{ actor.user_name }}。选择本校业务办理人后，使用其业务档案和待办。需要签名时使用您本人的签名。</p>
      <p v-if="schoolDemo.selection"><strong>当前代办</strong> {{ schoolDemo.subject.name }} · {{ schoolDemo.subject.role_name }}</p>
      <p v-if="schoolDemo.error || error" role="alert">{{ schoolDemo.error || error }}</p>
      <button v-if="schoolDemo.selection" type="button" :disabled="busy" @click="leaveSchoolDemo">退出代办并返回本人工作区</button>
      <template v-if="schoolDemo.enabled">
        <button type="button" :disabled="busy" @click="choose(actor.account_id, actor.role_id)">以管理员本人办理</button>
        <label>查找办理人<input v-model="keyword" type="search" placeholder="输入姓名或账号" autocomplete="off"></label>
        <div class="demo-choices" aria-live="polite">
          <span v-if="searching">正在查询…</span>
          <span v-else-if="!rows.length && !error">没有匹配的有效账号</span>
          <button v-for="row in rows" :key="row.id + ':' + row.role_id" type="button" :disabled="busy" @click="choose(row.id, row.role_id)">
            <strong>{{ row.name }}</strong><span>{{ row.role_name }} · {{ row.login_name }}</span>
          </button>
          <small v-if="more">请补充姓名或账号以缩小范围</small>
        </div>
      </template>
    </section>
  </aside>
</template>

<style scoped>
.school-demo { position: absolute; z-index: 9500; top: 8px; left: 50%; transform: translateX(-50%); width: max-content; max-width: 94%; color: var(--text, #223145); }
.school-demo.mobile { position: relative; top: auto; left: auto; transform: none; margin: 0 0 8px auto; }
.school-demo button, .school-demo input { font: inherit; color: inherit; }
.school-demo button { cursor: pointer; border: 1px solid var(--line, #c4d1df); background: var(--surface, #fff); border-radius: 7px; padding: 6px 10px; }
.demo-trigger { font-size: 13px !important; }
.demo-popover { position: absolute; top: calc(100% + 6px); left: 50%; transform: translateX(-50%); width: min(350px, 88vw); max-height: 70vh; overflow: auto; box-sizing: border-box; padding: 14px; display: grid; gap: 9px; box-shadow: 0 8px 26px #17273c29; background: var(--surface, #fff); border: 1px solid var(--line, #c4d1df); border-radius: 12px; font-size: 14px; }
.mobile .demo-popover { left: auto; right: 0; transform: none; }
.demo-popover header,.demo-choices button { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.demo-popover p { margin: 0; line-height: 1.5; }
.demo-popover label,.demo-choices { display: grid; gap: 5px; }
.demo-popover input { width: 100%; box-sizing: border-box; min-height: 36px; padding: 6px 8px; border-radius: 6px; border: 1px solid var(--line, #c4d1df); background: var(--surface, #fff); }
.demo-choices { max-height: 190px; overflow: auto; }
.demo-choices span,.demo-choices small { font-size: 12px; }
</style>
