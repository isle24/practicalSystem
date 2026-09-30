<template><OperationDialog :visible="!!item" title="本机认证配置" :busy="busy" @close="close"><div class="credentials-form"><strong>{{ item?.title }}</strong><p>配置仅用于当前学校账号和此链接的 HTTPS 同源认证。默认保存在本机系统凭据库；在个人设置明确开启云同步后，保存和删除操作才会同步到本人服务器保险库。</p><el-alert v-if="stored" type="info" title="此链接已有本机配置。保存将完整替换旧配置；页面不会读取已保存的秘密。" :closable="false" /><el-alert v-if="error" type="error" :title="error" :closable="false" /><LinkParameterEditor v-model="form.headers" title="Header" secret /><LinkParameterEditor v-model="form.cookies" title="Cookie" secret /><LinkParameterEditor v-model="form.form" title="POST 认证参数" secret /><p>Header/POST 用于首次认证交换，接口需返回同源跳转并设置登录 Cookie；不支持每次资源请求都要求自定义 Header 的网站。每组最多 20 条，配置总计最多 60 KB。</p></div><template #footer><el-button :disabled="busy || !stored" @click="clear">清除本机配置</el-button><el-button :disabled="busy" @click="close">取消</el-button><el-button type="primary" :loading="busy" @click="save">保存到本机</el-button></template></OperationDialog></template>
<script setup>
import { ref, watch } from 'vue';
import { ElMessageBox } from 'element-plus';
import OperationDialog from './OperationDialog.vue';
import LinkParameterEditor from './LinkParameterEditor.vue';
const props = defineProps({ item: Object });
const emit = defineEmits(['close']);
const busy = ref(false), error = ref(''), stored = ref(false), form = ref({ headers: [], cookies: [], form: [] });
let sequence = 0;
watch(() => props.item, async item => { const ticket = ++sequence; form.value = { headers: [], cookies: [], form: [] }; error.value = ''; stored.value = false; if (!item) return; busy.value = true; try { const result = await window.__PRACTICAL_DESKTOP__.favoriteCredentials(item.id,'status'); if (ticket === sequence) stored.value = result.stored; } catch(e) { if (ticket === sequence) error.value = e.message; } finally { if (ticket === sequence) busy.value = false; } });
function close() { form.value = { headers: [], cookies: [], form: [] }; emit('close'); }
function changed() { window.dispatchEvent(new CustomEvent('practical-credential-vault-changed')); }
async function refreshStored() { try { const result = await window.__PRACTICAL_DESKTOP__.favoriteCredentials(props.item.id,'status'); stored.value = result.stored; } catch { /* 保留原操作错误。 */ } }
async function save() { if (busy.value) return; busy.value = true; error.value = ''; try { await window.__PRACTICAL_DESKTOP__.favoriteCredentials(props.item.id,'save',form.value); close(); } catch(e) { error.value = e.message; await refreshStored(); } finally { busy.value = false; changed(); } }
async function clear() { if (busy.value) return; try { await ElMessageBox.confirm('清除当前账号在此链接保存的认证配置？开启云同步时，此次删除也会同步。','清除本机配置'); } catch { return; } busy.value = true; error.value = ''; try { await window.__PRACTICAL_DESKTOP__.favoriteCredentials(props.item.id,'clear'); stored.value = false; close(); } catch(e) { error.value = e.message; await refreshStored(); } finally { busy.value = false; changed(); } }
</script>
<style scoped>.credentials-form{display:flex;flex-direction:column;gap:18px;padding:24px;overflow:auto;min-height:0}.credentials-form p{font-size:12px;line-height:1.7;color:var(--muted);margin:0}</style>
