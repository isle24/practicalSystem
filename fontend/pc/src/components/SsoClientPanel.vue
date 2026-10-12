<template>
  <section class="sso-clients">
    <header><el-input v-model="keyword" clearable placeholder="搜索应用名称" @keyup.enter="load(1)" /><el-button @click="load(1)">查询</el-button><el-button type="primary" @click="edit()">注册应用</el-button></header>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table :data="items" v-loading="loading" height="100%">
      <el-table-column prop="client_name" label="应用名称" min-width="180" />
      <el-table-column prop="client_id" label="Client ID" min-width="220" />
      <el-table-column label="状态" width="100"><template #default="{ row }">{{ row.status === 'enabled' ? '已启用' : '已停用' }}</template></el-table-column>
      <el-table-column label="操作" width="245"><template #default="{ row }"><el-button link @click="edit(row)">编辑</el-button><el-button link @click="setStatus(row)">{{ row.status === 'enabled' ? '停用' : '启用' }}</el-button><el-button link @click="rotate(row)">轮换密钥</el-button></template></el-table-column>
    </el-table>
    <footer><span>共 {{ pagination.total }} 个应用</span><el-pagination layout="prev, pager, next" :current-page="pagination.page" :page-size="pagination.page_size" :total="pagination.total" @current-change="load" /></footer>
    <el-dialog v-model="dialog" :title="form.uuid ? '编辑授权应用' : '注册授权应用'" width="680px" :close-on-click-modal="false">
      <el-form label-position="top" :model="form" class="sso-form">
        <el-form-item label="应用名称"><el-input v-model="form.client_name" maxlength="180" /></el-form-item>
        <el-form-item label="回调地址（每行一个 HTTPS 固定路径）"><el-input v-model="redirectText" type="textarea" :rows="3" placeholder="https://example.com/oidc/callback" /></el-form-item>
        <el-form-item label="登录入口"><el-input v-model="form.initiate_login_uri" placeholder="https://example.com/sso/login" /></el-form-item>
        <el-form-item label="转接准备入口"><el-input v-model="form.launch_prepare_uri" placeholder="https://example.com/sso/prepare" /></el-form-item>
        <el-form-item label="转接页面入口"><el-input v-model="form.launch_entry_uri" placeholder="https://example.com/sso/launch" /></el-form-item>
        <el-form-item label="目标上下文标识"><el-input v-model="form.target_context" maxlength="80" /></el-form-item>
        <el-form-item label="身份范围"><el-checkbox-group v-model="form.allowed_scopes"><el-checkbox value="openid" label="openid" disabled /><el-checkbox value="profile" label="profile" /></el-checkbox-group></el-form-item>
        <el-form-item label="允许角色"><el-checkbox-group v-model="form.allowed_role_types"><el-checkbox v-for="role in roles" :key="role.value" :value="role.value" :label="role.value">{{ role.label }}</el-checkbox></el-checkbox-group></el-form-item>
      </el-form>
      <template #footer><el-button @click="dialog = false">取消</el-button><el-button type="primary" :loading="saving" @click="save">保存</el-button></template>
    </el-dialog>
    <el-dialog v-model="secretDialog" title="应用密钥" width="560px" :close-on-click-modal="false" @closed="secret = ''"><p>请立即复制并安全保存。关闭后将无法再次查看。</p><el-input :model-value="secret" readonly><template #append><el-button @click="copySecret">复制</el-button></template></el-input><template #footer><el-button type="primary" @click="secretDialog = false">我已保存</el-button></template></el-dialog>
  </section>
</template>
<script setup>
import { ref, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { request } from '../api/client';
import { ssoMutation } from '../utils/ssoClient';
const roles = [{ value: 'super_admin', label: '超级管理员' }, { value: 'school_admin', label: '学校管理员' }, { value: 'college_admin', label: '学院管理员' }, { value: 'profession_admin', label: '专业管理员' }, { value: 'teacher', label: '教师' }, { value: 'student', label: '学生' }, { value: 'enterprise', label: '企业' }];
const items = ref([]), pagination = ref({ page: 1, page_size: 20, total: 0 }), keyword = ref(''), loading = ref(false), saving = ref(false), error = ref('');
const dialog = ref(false), secretDialog = ref(false), secret = ref(''), redirectText = ref(''), form = ref({});
async function load(page = 1) { loading.value = true; error.value = ''; try { const data = await request(`/sso-client/list?${new URLSearchParams({ keyword: keyword.value, page, page_size: 20 })}`); items.value = data.items; pagination.value = data.pagination; } catch (reason) { error.value = reason.message; } finally { loading.value = false; } }
async function edit(row) {
  if (!row) { form.value = { client_name: '', initiate_login_uri: '', launch_prepare_uri: '', launch_entry_uri: '', target_context: '', allowed_scopes: ['openid', 'profile'], allowed_role_types: ['teacher', 'student'], launch_profile: 'launch-v1' }; redirectText.value = ''; dialog.value = true; return; }
  try { const item = await request(`/sso-client/detail?uuid=${encodeURIComponent(row.uuid)}`); form.value = { ...item.config, uuid: item.uuid, revision: item.revision, client_name: item.client_name, allowed_scopes: item.config.allowed_scopes || ['openid'], allowed_role_types: item.config.allowed_role_types || [] }; redirectText.value = (item.config.redirect_uris || []).join('\n'); dialog.value = true; } catch (reason) { ElMessage.error(reason.message); }
}
function showSecret(value) { if (value) { secret.value = value; secretDialog.value = true; } }
async function save() { if (saving.value) return; saving.value = true; try { const data = await ssoMutation('/sso-client/save', { ...form.value, redirect_uris: redirectText.value.split(/\r?\n/).map(value => value.trim()).filter(Boolean), launch_profile: 'launch-v1' }); dialog.value = false; showSecret(data.client_secret); await load(pagination.value.page); ElMessage.success('应用已保存'); } catch (reason) { ElMessage.error(reason.message); } finally { saving.value = false; } }
async function setStatus(row) { try { await ElMessageBox.confirm(`确认${row.status === 'enabled' ? '停用' : '启用'}“${row.client_name}”？`, '确认状态变更'); const data = await ssoMutation('/sso-client/set-status', { uuid: row.uuid, revision: row.revision, status: row.status === 'enabled' ? 'disabled' : 'enabled' }); await load(pagination.value.page); ElMessage.success(data.item.status === 'enabled' ? '已启用' : '已停用'); } catch (reason) { if (reason !== 'cancel') ElMessage.error(reason.message || '操作失败'); } }
async function rotate(row) { try { await ElMessageBox.confirm(`轮换“${row.client_name}”的密钥将使旧密钥立即失效，确认继续？`, '轮换应用密钥'); const data = await ssoMutation('/sso-client/rotate-secret', { uuid: row.uuid, revision: row.revision }); showSecret(data.client_secret); await load(pagination.value.page); } catch (reason) { if (reason !== 'cancel') ElMessage.error(reason.message || '操作失败'); } }
async function copySecret() { try { await navigator.clipboard.writeText(secret.value); ElMessage.success('已复制'); } catch { ElMessage.error('复制失败，请手动选择'); } }
onMounted(() => load());
</script>
<style scoped>
.sso-clients{display:flex;flex-direction:column;gap:12px;height:100%;min-height:0;padding:20px;box-sizing:border-box}.sso-clients header,.sso-clients footer{display:flex;align-items:center;gap:10px}.sso-clients header .el-input{max-width:300px}.sso-clients footer{justify-content:space-between}.sso-form{max-height:65vh;overflow:auto;padding-right:8px}
</style>
