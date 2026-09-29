<script setup>
import { onMounted, reactive } from 'vue';
import { Building2, ImagePlus, RefreshCw } from '@lucide/vue';
import { backendUrl, request } from '../api/client';

const emit = defineEmits(['updated']);
const state = reactive({ loading: false, uploading: '', error: '', school_name: '', school_logo_url: '', login_background_url: '', default_wallpaper_url: '', sharedWallpapers: [], wallpaperPage: 1, wallpaperTotal: 0 });
const assets = [
  { type: 'logo', label: '学校 Logo', field: 'school_logo_url', accept: 'image/png,image/jpeg,image/webp', limit: 2, hint: '推荐 256 × 256 px，透明 PNG / WebP，最大 2 MB。按原比例完整显示。' },
  { type: 'background', label: '登录页背景', field: 'login_background_url', accept: 'image/png,image/jpeg,image/webp,image/gif', limit: 8, hint: '推荐 1920 × 1080 px 或更大横图，最大 8 MB。按比例铺满登录页。' },
];

async function load() {
  state.loading = true;
  state.error = '';
  try { Object.assign(state, await request('/school-appearance/settings')); await loadShared(); }
  catch (error) { state.error = error.message; }
  finally { state.loading = false; }
}

async function loadShared() {
  const data = await request(`/wallpaper/list?scope=shared&page=${state.wallpaperPage}&page_size=8`);
  state.sharedWallpapers = data.items || [];
  state.wallpaperTotal = data.pagination?.total || 0;
}

async function setWallpaper(id) {
  state.uploading = 'wallpaper';
  state.error = '';
  try {
    const data = await request('/school-appearance/wallpaper', { method: 'POST', body: JSON.stringify({ id }) });
    Object.assign(state, data);
    emit('updated', data);
  } catch (error) { state.error = error.message; }
  finally { state.uploading = ''; }
}

async function uploadWallpaper(event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file || state.uploading) return;
  if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type) || file.size > 8 * 1024 * 1024) {
    state.error = '请选择不超过 8 MB 的 JPG、PNG、WebP 或 GIF 图片';
    return;
  }
  state.uploading = 'wallpaper';
  state.error = '';
  try {
    const body = new FormData();
    body.append('file', file);
    const data = await request('/school-appearance/upload-wallpaper', { method: 'POST', body });
    Object.assign(state, data);
    emit('updated', data);
  } catch (error) { state.error = error.message; }
  finally { state.uploading = ''; }
}

async function upload(asset, event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file || state.uploading) return;
  if (!asset.accept.split(',').includes(file.type) || file.size > asset.limit * 1024 * 1024) {
    state.error = `请选择支持的图片格式，且不超过 ${asset.limit} MB`;
    return;
  }
  state.uploading = asset.type;
  state.error = '';
  try {
    const body = new FormData();
    body.append('file', file);
    const data = await request(`/school-appearance/upload-${asset.type}`, { method: 'POST', body });
    Object.assign(state, data);
    emit('updated', data);
  } catch (error) { state.error = error.message; }
  finally { state.uploading = ''; }
}

onMounted(load);
</script>

<template>
  <section class="school-settings" v-loading="state.loading">
    <header class="school-settings-heading">
      <div><h2>学校设置</h2><p>{{ state.school_name }} · 学校标识与外观</p></div>
      <el-button :icon="RefreshCw" :disabled="Boolean(state.uploading)" @click="load">刷新</el-button>
    </header>
    <el-alert v-if="state.error" :title="state.error" type="error" :closable="false" />
    <section v-for="asset in assets" :key="asset.type" class="school-asset-card">
      <div><h3>{{ asset.label }}</h3><p>{{ asset.hint }}</p></div>
      <div class="school-asset-controls">
        <div class="school-asset-preview" :class="asset.type">
          <img v-if="state[asset.field]" :src="backendUrl(state[asset.field])" :alt="asset.label">
          <Building2 v-else-if="asset.type === 'logo'" :size="40" />
          <ImagePlus v-else :size="40" />
        </div>
        <label class="school-asset-upload" :class="{ disabled: state.loading || state.uploading }">
          <ImagePlus :size="16" />
          {{ state.uploading === asset.type ? '正在上传' : state[asset.field] ? '更换图片' : '上传图片' }}
          <input type="file" :accept="asset.accept" :disabled="state.loading || Boolean(state.uploading)" @change="upload(asset, $event)">
        </label>
      </div>
    </section>
    <section class="school-asset-card">
      <div><h3>学校默认桌面壁纸</h3><p>推荐 1920 × 1080 px 或更大横图，最大 8 MB。</p></div>
      <div class="school-asset-controls">
        <div class="school-asset-preview background"><img v-if="state.default_wallpaper_url" :src="backendUrl(state.default_wallpaper_url)" alt="学校默认桌面壁纸"><ImagePlus v-else :size="40" /></div>
        <label class="school-asset-upload" :class="{ disabled: state.loading || state.uploading }"><ImagePlus :size="16" />{{ state.uploading === 'wallpaper' ? '正在上传' : '上传新壁纸' }}<input type="file" accept="image/png,image/jpeg,image/webp,image/gif" :disabled="state.loading || Boolean(state.uploading)" @change="uploadWallpaper"></label>
      </div>
      <h4>从壁纸库选择</h4>
      <div class="school-wallpaper-list">
        <button v-for="item in state.sharedWallpapers" :key="item.id" type="button" :disabled="Boolean(state.uploading)" @click="setWallpaper(item.id)"><img :src="backendUrl(item.url)" :alt="item.name"><span>{{ item.name }}</span></button>
      </div>
      <p v-if="!state.sharedWallpapers.length">暂无共享壁纸</p>
      <el-pagination v-if="state.wallpaperTotal > 8" v-model:current-page="state.wallpaperPage" :page-size="8" :total="state.wallpaperTotal" layout="prev, pager, next" background @current-change="loadShared" />
    </section>
    <slot />
  </section>
</template>

<style scoped>
.school-settings { height: 100%; overflow: auto; padding: 24px; display: flex; flex-direction: column; gap: 20px; }
.school-settings-heading { display: flex; justify-content: space-between; align-items: center; gap: 16px; }
.school-settings h2, .school-settings h3 { margin: 0; font-size: 18px; }
.school-settings h3 { font-size: 15px; }
.school-settings p { color: #6b7280; font-size: 13px; line-height: 1.6; margin: 8px 0 0; }
.school-asset-card { border: 1px solid #e3e8ef; border-radius: 10px; padding: 20px; background: #fff; }
.school-asset-controls { display: flex; align-items: center; gap: 20px; margin-top: 18px; flex-wrap: wrap; }
.school-asset-preview { background: #f3f5f8; color: #8290a3; display: grid; place-items: center; border: 1px solid #e3e8ef; border-radius: 8px; overflow: hidden; }
.school-asset-preview.logo { width: 96px; height: 96px; padding: 6px; }
.school-asset-preview.background { width: min(320px, 100%); aspect-ratio: 16/9; }
.school-asset-preview img { width: 100%; height: 100%; object-fit: cover; }
.school-asset-preview.logo img { object-fit: contain; }
.school-asset-upload { display: inline-flex; align-items: center; gap: 8px; padding: 10px 14px; border: 1px solid #ccd6e3; border-radius: 6px; color: #316aa9; cursor: pointer; position: relative; }
.school-asset-upload input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
.school-asset-upload:focus-within { outline: 2px solid #2563eb; outline-offset: 2px; }
.school-asset-upload.disabled { opacity: .55; cursor: wait; }
.school-asset-card h4 { font-size: 13px; margin: 20px 0 10px; }
.school-wallpaper-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
.school-wallpaper-list button { min-width: 0; border: 1px solid #d5dfe8; border-radius: 6px; overflow: hidden; background: #fff; padding: 0; text-align: left; cursor: pointer; }
.school-wallpaper-list img { display: block; width: 100%; aspect-ratio: 16/9; object-fit: cover; }
.school-wallpaper-list span { display: block; padding: 7px; font-size: 12px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
</style>
