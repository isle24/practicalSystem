<template><OperationDialog :visible="!!item" title="本机认证配置" :busy="busy" @close="emit('close')"><div class="credentials-form"><strong>{{ item?.title }}</strong><p>配置仅用于此账号、本机及此链接。只支持 HTTPS 同源认证，不会同步到学校或其他用户。</p><el-alert v-if="stored" type="info" title="此链接已有本机配置。保存将完整替换旧配置；关闭则保持不变。" :closable="false" /><el-alert v-if="error" type="error" :title="error" :closable="false" /><LinkParameterEditor v-model="form.headers" title="Header" secret /><LinkParameterEditor v-model="form.cookies" title="Cookie" secret /><LinkParameterEditor v-model="form.form" title="POST 认证参数" secret /><p>Header/POST 用于首次认证交换，接口需返回同源跳转并设置登录 Cookie；不支持每次资源请求都要求自定义 Header 的网站。</p></div><template #footer><el-button :disabled="busy || !stored" @click="clear">清除本机配置</el-button><el-button :disabled="busy" @click="emit('close')">取消</el-button><el-button type="primary" :loading="busy" @click="save">保存到本机</el-button></template></OperationDialog></template>
<script setup>
import { ref, watch } from 'vue';
import { ElMessageBox } from 'element-plus';
import OperationDialog from './OperationDialog.vue';
import LinkParameterEditor from './LinkParameterEditor.vue';
const props = defineProps({ item: Object });
const emit = defineEmits(['close']);
const busy = ref(false), error = ref(''), stored = ref(false), form = ref({ headers: [], cookies: [], form: [] });
watch(() => props.item, async item => { form.value = { headers: [], cookies: [], form: [] }; error.value = ''; stored.value = false; if (!item) return; busy.value = true; try { const result = await window.__PRACTICAL_DESKTOP__.favoriteCredentials(item.id,'status'); stored.value = result.stored; } catch(e) { error.value = e.message; } finally { busy.value = false; } });
async function save() { busy.value = true; error.value = ''; try { await window.__PRACTICAL_DESKTOP__.favoriteCredentials(props.item.id,'save',form.value); emit('close'); } catch(e) { error.value = e.message; } finally { busy.value = false; } }
async function clear() { try { await ElMessageBox.confirm('清除该账号在此链接保存的认证配置？','清除本机配置'); } catch { return; } busy.value = true; try { await window.__PRACTICAL_DESKTOP__.favoriteCredentials(props.item.id,'clear'); stored.value = false; emit('close'); } catch(e) { error.value = e.message; } finally { busy.value = false; } }
</script>
<style scoped>.credentials-form{display:flex;flex-direction:column;gap:18px;padding:24px;overflow:auto;min-height:0}.credentials-form p{font-size:12px;line-height:1.7;color:#748094;margin:0}</style>
