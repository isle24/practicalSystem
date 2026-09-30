<template>
  <section class="profile-panel-card theme-settings">
    <header><strong>本机主题</strong><small>按学校和当前用户保存在本机，壁纸不会上传服务器。</small></header>
    <div class="theme-presets" aria-label="预置与自定义主题"><button v-for="preset in [...theme.presets, ...theme.state.custom]" :key="preset.id" type="button" :class="{ active: draft.id === preset.id }" :aria-pressed="draft.id === preset.id" @click="select(preset)"><span class="theme-swatch" :style="{ '--swatch-accent': preset.accent, '--swatch-window': preset.window || '#ffffff' }" aria-hidden="true"><i /><b /></span><span>{{ preset.name || preset.id }}</span></button></div>
    <div class="theme-fields">
      <label><span>明暗模式</span><el-select v-model="draft.mode"><el-option label="跟随系统" value="system" /><el-option label="亮色" value="light" /><el-option label="暗色" value="dark" /></el-select></label>
      <label><span>桌面文字</span><input v-model="draft.text" type="color"></label>
      <label><span>强调色</span><input v-model="draft.accent" type="color"></label>
      <label><span>窗口颜色</span><input v-model="windowColor" type="color"></label>
      <label><span>窗口透明度</span><el-slider v-model="draft.opacity" :min="0.65" :max="1" :step="0.01" /></label>
      <label><span>图标密度</span><el-select v-model="draft.density"><el-option label="紧凑" value="compact" /><el-option label="标准" value="normal" /><el-option label="宽松" value="comfortable" /></el-select></label>
      <label><span>本地壁纸</span><input type="file" accept="image/jpeg,image/png,image/webp" @change="chooseWallpaper"><el-button v-if="draft.wallpaper_id" link @click="draft.wallpaper_id = ''">使用服务器壁纸</el-button></label>
      <label><span>另存主题名称</span><el-input v-model="name" maxlength="40" placeholder="留空只应用，不新增主题" /></label>
    </div>
    <el-alert v-if="error || theme.state.error" :title="error || theme.state.error" type="error" :closable="false" />
    <div class="theme-actions"><el-button :disabled="busy" @click="preview">预览</el-button><el-button :disabled="busy" @click="cancel">取消预览</el-button><el-button type="primary" :loading="busy" @click="apply">应用</el-button><el-button :disabled="busy" @click="reset">恢复默认</el-button><el-button v-if="theme.state.custom.some(item => item.id === draft.id)" type="danger" plain :disabled="busy" @click="remove">删除自定义主题</el-button></div>
  </section>
</template>
<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { ElMessageBox } from 'element-plus';
const props = defineProps({ theme: { type: Object, required: true } });
const draft = ref({ ...props.theme.state.saved }), name = ref(''), error = ref(''), busy = ref(false);
const windowColor = computed({ get: () => draft.value.window || (draft.value.mode === 'dark' ? '#18212f' : '#ffffff'), set: value => { draft.value.window = value; } });
watch(() => props.theme.state.saved, value => { draft.value = { ...value }; name.value = ''; });
function select(preset) { draft.value = { ...preset, wallpaper_id: preset.wallpaper_id || '', name: preset.name || '' }; name.value = ''; }
async function run(action) { busy.value = true; error.value = ''; try { await action(); } catch (reason) { error.value = reason.message; } finally { busy.value = false; } }
async function chooseWallpaper(event) { const file = event.target.files?.[0]; event.target.value = ''; if (file) await run(async () => { draft.value.wallpaper_id = await props.theme.selectWallpaper(file); }); }
function preview() { return run(() => props.theme.preview(draft.value)); }
function cancel() { return run(() => props.theme.cancelPreview()); }
function apply() { return run(() => props.theme.apply(draft.value, name.value)); }
function reset() { return run(() => props.theme.reset()); }
async function remove() { try { await ElMessageBox.confirm('删除这个本机自定义主题？', '删除主题'); } catch { return; } await run(() => props.theme.remove(draft.value.id)); }
onBeforeUnmount(() => { props.theme.cancelPreview(); });
</script>
<style scoped>
.theme-actions{display:flex;gap:10px;flex-wrap:wrap}.theme-presets{display:grid;grid-template-columns:repeat(auto-fill,minmax(105px,1fr));gap:12px}.theme-swatch{display:block;position:relative;height:66px;border-radius:6px;background:linear-gradient(135deg,var(--swatch-accent),#10243c);overflow:hidden;margin-bottom:10px}.theme-swatch i{position:absolute;left:20%;right:12%;top:15%;bottom:15%;border-radius:4px;background:var(--swatch-window);box-shadow:0 4px 8px #0003}.theme-swatch b{position:absolute;left:24%;right:17%;top:24%;height:4px;border-radius:4px;background:var(--swatch-accent)}.theme-presets button{border:1px solid var(--line);border-radius:8px;padding:8px;background:var(--surface-2);color:var(--text);cursor:pointer}.theme-presets .active{border-color:var(--primary);color:var(--primary)}.theme-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.theme-fields label{display:flex;flex-direction:column;gap:8px;font-size:13px;color:var(--muted)}.theme-fields input[type=color]{height:34px;width:70px;border:1px solid var(--line);background:var(--surface);cursor:pointer}.theme-fields input[type=file]{width:100%;color:var(--text)}@media(max-width:650px){.theme-fields{grid-template-columns:1fr}}
</style>
