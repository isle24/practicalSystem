<template>
  <section v-if="desktop" class="preview-cache-settings">
    <template v-if="office">
      <header><strong>本机 Office 预览</strong></header>
      <div>
        <label>预览方式<el-select v-model="officeSettings.mode" :disabled="officeBusy" class="office-select">
          <el-option label="内置预览" value="builtin" />
          <el-option label="本机转换 PDF 后预览" value="pdf" />
          <el-option label="使用本机 Office 打开" value="native" />
        </el-select></label>
        <label v-if="officeSettings.mode === 'pdf'">转换引擎<el-select v-model="officeSettings.engine" :disabled="officeBusy" class="office-select">
          <el-option label="Word 优先，LibreOffice 备用" value="auto" />
          <el-option label="Microsoft Word（Windows）" value="word" :disabled="platform !== 'windows'" />
          <el-option label="LibreOffice" value="libreoffice" />
        </el-select></label>
        <el-button :loading="officeBusy" @click="saveOffice">保存</el-button>
        <el-button :disabled="officeBusy" @click="loadOffice">重新检测</el-button>
      </div>
      <small v-if="officeSettings.mode === 'native'">使用系统关联的 Office 打开 DOCX/XLSX 本机只读副本。</small>
      <small v-else-if="officeSettings.mode === 'pdf'">普通 DOCX 在本机转换，PDF 仅保存于本机缓存。不会自动安装转换引擎。缺少引擎或转换失败时可手动使用本机 Office 打开。</small>
      <small v-else>保持当前内置文档预览。此设置仅保存于当前设备。</small>
      <small>使用本机 Office 打开后，外部窗口不受客户端锁屏控制，另存或修改不会上传服务器。</small>
      <span class="office-engines">{{ platform === 'windows' ? `Microsoft Word：${officeSettings.engines.word ? '已检测到' : '未检测到'}；` : 'Microsoft Word 自动转换仅支持 Windows；' }}LibreOffice：{{ officeSettings.engines.libreoffice ? '已检测到' : '未检测到' }}</span>
      <p v-if="officeError" role="alert">{{ officeError }}</p>
      <div class="office-divider" />
    </template>
    <header><strong>文件预览缓存</strong><span>{{ used }} / {{ state.max_gb }} GB · {{ state.files }} 个文件</span></header>
    <div><label>最大缓存<el-input-number v-model="limit" :min="0" :max="128" :step="1" :disabled="busy" /><span>GB</span></label><el-button :loading="busy" @click="save">保存</el-button><el-button :disabled="busy || !state.files" @click="clear">清空缓存</el-button></div>
    <small>设为 0 关闭持久缓存。原文件和本机转换的 PDF 共用上限，按学校和账号隔离，超出上限清理最久未使用的文件；PDF 缓存闲置 7 天后重新转换。</small>
    <p v-if="error" role="alert">{{ error }}</p>
  </section>
</template>
<script setup>
import { ref, computed, onMounted } from 'vue';
import { ElMessageBox, ElMessage } from 'element-plus';
const bridge = window.__PRACTICAL_DESKTOP__;
const desktop = bridge?.previewCache, office = bridge?.officePreview;
const platform = bridge?.platform;
const officeSettings = ref({ mode: 'builtin', engine: 'auto', engines: { word: false, libreoffice: false } });
const officeBusy = ref(false), officeError = ref('');
async function officeRun(action) {
  officeBusy.value = true; officeError.value = '';
  try {
    officeSettings.value = await office(action, action === 'save' ? { mode: officeSettings.value.mode, engine: officeSettings.value.engine } : {});
    if (action === 'save') ElMessage.success('本机 Office 预览设置已保存');
  } catch (failure) { officeError.value = failure.message || '本机 Office 设置读取失败'; }
  finally { officeBusy.value = false; }
}
function saveOffice() { return officeRun('save'); }
function loadOffice() { return officeRun('status'); }
const state = ref({max_gb:8,used_bytes:0,files:0}), limit = ref(8), busy=ref(false), error=ref('');
const used = computed(()=>(state.value.used_bytes / 1073741824).toFixed(2));
async function run(action) { busy.value=true;error.value='';try{state.value=await desktop(action,limit.value);limit.value=state.value.max_gb;if(action!=='status')ElMessage.success('缓存设置已更新');}catch(e){error.value=e.message;}finally{busy.value=false;} }
async function save(){ if(limit.value < state.value.max_gb) { try{ await ElMessageBox.confirm('降低容量会清理超出上限的本机缓存，不影响服务器文件。确认继续？','调整缓存'); }catch{return;} } await run('save'); }
async function clear(){try{await ElMessageBox.confirm('清空本机全部学校账号的预览缓存？服务器原文件不会删除。','清空缓存');}catch{return;}await run('clear');}
onMounted(()=>{if(desktop)run('status');if(office)loadOffice();});
</script>
<style scoped>.preview-cache-settings{display:flex;flex-direction:column;gap:14px;padding:22px 0;border-top:1px solid var(--line)}.preview-cache-settings header,.preview-cache-settings>div,.preview-cache-settings label{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.preview-cache-settings header{justify-content:space-between}.preview-cache-settings strong{font-size:14px}.preview-cache-settings span,.preview-cache-settings small{font-size:12px;color:var(--muted)}.preview-cache-settings label{font-size:13px}.preview-cache-settings p{color:var(--danger);font-size:13px}.office-select{width:240px}.office-divider{height:1px;background:var(--line);margin:6px 0}.office-engines{line-height:1.7}</style>
