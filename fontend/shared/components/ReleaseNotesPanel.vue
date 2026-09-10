<template>
  <section class="release-notes"><header><strong>更新说明</strong><button v-if="desktop" :disabled="checking" @click="check"><Download :size="17" />{{ checking ? '正在检查' : '检查客户端更新' }}</button></header><p v-if="error" role="alert">{{ error }}</p><article v-for="item in items" :key="item.id"><div><span>{{ item.product === 'desktop' ? '客户端' : '系统' }} {{ item.version }}</span><time>{{ item.published_at }}</time></div><h3>{{ item.title }}</h3><div class="release-body" v-html="safe(item.notes_html)" /><button v-if="item.product === 'desktop'" @click="packages(item.id)">下载安装包</button><div v-if="downloads[item.id]" class="package-links"><a v-for="file in downloads[item.id]" :key="file.file_name" :href="file.download_path" target="_blank" rel="noopener">{{ file.file_name }} · {{ (file.size / 1048576).toFixed(1) }} MB</a><small v-if="!downloads[item.id].length">此版本已被新版替代，请下载学校最新发布的版本。</small></div></article><p v-if="!items.length && !loading">暂无已发布的更新说明</p><footer><button :disabled="page <= 1 || loading" @click="load(page - 1)"><ChevronLeft :size="18" />上一页</button><span>共 {{ total }} 条</span><button :disabled="page * 10 >= total || loading" @click="load(page + 1)">下一页<ChevronRight :size="18" /></button></footer></section>
</template>
<script setup>
import { ref, onMounted } from 'vue';
import { Download, ChevronLeft, ChevronRight } from '@lucide/vue';
import DOMPurify from 'dompurify';
const props = defineProps({ request: { type: Function, required: true } });
const items = ref([]), page = ref(1), total = ref(0), error = ref(''), loading = ref(false), checking = ref(false);
const downloads = ref({});
async function packages(id) { try { downloads.value[id] = (await props.request(`/release/packages?id=${id}`)).items; } catch (e) { error.value = e.message; } }
const desktop = window.__PRACTICAL_DESKTOP__?.checkUpdate;
const safe = value => DOMPurify.sanitize(value || '');
async function load(target = 1) { loading.value = true; try { const data = await props.request(`/release/list?page=${target}&page_size=10`); items.value = data.items; page.value = target; total.value = data.pagination.total; } catch (e) { error.value = e.message; } finally { loading.value = false; } }
async function check() { if (checking.value) return; checking.value = true; try { await desktop(true); } catch (e) { error.value = e.message; } finally { checking.value = false; } }
onMounted(() => load());
</script>
<style scoped>
.release-notes{height:100%;overflow:auto;padding:24px;box-sizing:border-box;color:#364151}.release-notes header{display:flex;align-items:center;justify-content:space-between;gap:12px}.release-notes header strong{font-size:19px}.release-notes button{display:inline-flex;gap:6px;align-items:center;border:0;color:#336ac4;background:#edf3fc;border-radius:5px;padding:8px 12px;cursor:pointer}.release-notes button:disabled{opacity:.5}.release-notes article{padding:24px 0;border-bottom:1px solid #e5e8ee}.release-notes article>div:first-child{display:flex;gap:10px;justify-content:space-between;font-size:12px;color:#78818e}.release-notes h3{font-size:17px;margin:12px 0}.release-body{line-height:1.85;overflow-wrap:anywhere}.release-body :deep(h2){font-size:16px}.release-notes footer{display:flex;align-items:center;justify-content:space-between;padding-top:20px;font-size:12px}@media(max-width:700px){.release-notes{padding:20px 16px}.release-notes header{flex-wrap:wrap}.release-notes article>div:first-child{flex-wrap:wrap}}
.package-links{display:flex;flex-direction:column;gap:12px;padding:16px 0}.package-links a{color:#336ac4;overflow-wrap:anywhere;font-size:13px}
</style>
