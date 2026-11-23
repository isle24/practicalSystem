<template>
  <section class="release-notes"><header><strong>更新说明</strong><button v-if="desktop" :disabled="checking" @click="check"><Download :size="17" />{{ checking ? '正在检查' : '检查客户端更新' }}</button></header><p v-if="error" role="alert">{{ error }}</p><article v-for="item in items" :key="item.id"><div><span>{{ item.product === 'desktop' ? '客户端' : '系统' }} {{ item.version }}</span><time>{{ item.published_at }}</time></div><h3>{{ item.title }}</h3><div class="release-body" v-html="safe(item.notes_html)" /><button v-if="item.product === 'desktop'" @click="packages(item.id)">下载安装包</button><ClientPackages v-if="downloads[item.id]" class="release-packages" :items="downloads[item.id]" empty-text="该版本暂无可下载的安装包，请查看学校最新发布的版本。" /></article><p v-if="!items.length && !loading">暂无已发布的更新说明</p><footer><button :disabled="page <= 1 || loading" @click="load(page - 1)"><ChevronLeft :size="18" />上一页</button><span class="release-page-summary"><span>共 {{ total }} 条</span><span>{{ pageSize }} 条/页</span></span><button :disabled="page * pageSize >= total || loading" @click="load(page + 1)">下一页<ChevronRight :size="18" /></button></footer></section>
</template>
<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { Download, ChevronLeft, ChevronRight } from '@lucide/vue';
import { sanitizeRichHtml } from '../richHtml';
import ClientPackages from './ClientPackages.vue';
const props = defineProps({ request: { type: Function, required: true } });
const items = ref([]), page = ref(1), total = ref(0), error = ref(''), loading = ref(false), checking = ref(false);
const downloads = ref({});
const pageSize = ref(10);
let generation = 0, disposed = false;
async function packages(id) {
  const version = generation;
  try {
    const data = await props.request(`/release/packages?id=${id}`);
    if (!disposed && version === generation) downloads.value[id] = data.items || [];
  } catch (e) { if (!disposed && version === generation) error.value = e.message; }
}
const desktop = window.__PRACTICAL_DESKTOP__?.checkUpdate;
const safe = sanitizeRichHtml;
async function load(target = 1) {
  const version = ++generation;
  loading.value = true; error.value = '';
  try {
    const data = await props.request(`/release/list?page=${target}&page_size=${pageSize.value}`);
    if (disposed || version !== generation) return;
    items.value = data.items || []; page.value = target; total.value = data.pagination.total;
    pageSize.value = Number(data.pagination.page_size || pageSize.value);
    downloads.value = {};
  } catch (e) { if (!disposed && version === generation) error.value = e.message; }
  finally { if (!disposed && version === generation) loading.value = false; }
}
async function check() { if (checking.value) return; checking.value = true; try { await desktop(true); } catch (e) { error.value = e.message; } finally { checking.value = false; } }
onMounted(() => load());
onBeforeUnmount(() => { disposed = true; generation++; });
</script>
<style scoped>
.release-page-summary{display:grid;gap:3px;text-align:center}
.release-notes{height:100%;overflow:auto;padding:24px;box-sizing:border-box;color:var(--text, #364151)}.release-notes header{display:flex;align-items:center;justify-content:space-between;gap:12px}.release-notes header strong{font-size:19px}.release-notes button{display:inline-flex;gap:6px;align-items:center;border:0;color:var(--primary-ink, #336ac4);background:var(--primary-soft, #edf3fc);border-radius:5px;padding:8px 12px;cursor:pointer}.release-notes button:disabled{opacity:.5}.release-notes article{padding:24px 0;border-bottom:1px solid var(--line, #e5e8ee)}.release-notes article>div:first-child{display:flex;gap:10px;justify-content:space-between;font-size:12px;color:var(--muted, #78818e)}.release-notes h3{font-size:17px;margin:12px 0}.release-body{line-height:1.85;overflow-wrap:anywhere}.release-body :deep(h2){font-size:16px}.release-notes footer{display:flex;align-items:center;justify-content:space-between;padding-top:20px;font-size:12px}@media(max-width:700px){.release-notes{padding:20px 16px}.release-notes header{flex-wrap:wrap}.release-notes article>div:first-child{flex-wrap:wrap}}
.release-packages{margin-top:16px}
</style>
