<template>
  <OperationDialog
    :visible="visible"
    :title="mode === 'edit' ? '编辑菜单' : '新增菜单'"
    dialog-class="menu-dialog"
    @close="emit('close')"
  >
    <div class="operation-form menu-dialog-form">
      <label>
        <span>名称</span>
        <input v-model="form.name">
      </label>
      <label>
        <span>权限码</span>
        <input v-model="form.code" placeholder="如 internship:apply">
      </label>
      <label>
        <span>路径</span>
        <input v-model="form.path" placeholder="页面菜单填写路由，按钮可为空">
      </label>
      <label>
        <span>图标</span>
        <input v-model="form.icon" placeholder="lucide 图标名">
      </label>
      <label class="menu-icon-field">
        <span>上传图标</span>
        <IconUpload
          button-class="favorite-icon-upload"
          :icon="resolveIcon(form.icon)"
          :icon-url="form.icon_url"
          label="菜单图标"
          :uploading="iconUploading"
          :backend-url="backendUrl"
          @select="file => emit('select-icon', file)"
        />
      </label>
      <label>
        <span>作为模块</span>
        <el-select v-model="form.is_module">
          <el-option label="否" value="false" />
          <el-option label="是" value="true" />
        </el-select>
      </label>
      <label>
        <span>模块标识</span>
        <input v-model="form.module_key" :disabled="form.is_module !== 'true'" placeholder="为空时自动使用菜单ID">
      </label>
      <label>
        <span>父级</span>
        <el-tree-select
          v-model="form.parent_id"
          :data="parentOptions"
          :props="treeProps"
          check-strictly
          default-expand-all
          filterable
          node-key="id"
        />
      </label>
      <label>
        <span>平台</span>
        <el-select v-model="form.platform">
          <el-option label="PC" value="pc" />
          <el-option label="H5" value="h5" />
          <el-option label="双端" value="both" />
        </el-select>
      </label>
      <label>
        <span>类型</span>
        <el-radio-group v-model="form.type" class="menu-type-radios">
          <el-radio-button label="directory">目录</el-radio-button>
          <el-radio-button label="menu">菜单</el-radio-button>
          <el-radio-button label="list">列表</el-radio-button>
          <el-radio-button label="button">按钮</el-radio-button>
        </el-radio-group>
      </label>
      <label>
        <span>排序</span>
        <input v-model="form.sort" type="number">
      </label>
      <label>
        <span>可见</span>
        <el-select v-model="form.visible">
          <el-option label="是" value="true" />
          <el-option label="否" value="false" />
        </el-select>
      </label>
      <label>
        <span>状态</span>
        <el-select v-model="form.status">
          <el-option label="启用" value="enabled" />
          <el-option label="禁用" value="disabled" />
        </el-select>
      </label>
      <small class="menu-module-hint">
        勾选作为模块后，该菜单会出现在启动台，可由用户添加到桌面；未上传图标时使用默认图标。
      </small>
    </div>
    <template #footer>
      <el-button @click="emit('close')">取消</el-button>
      <el-button type="primary" :loading="loading" @click="emit('save')">
        保存
      </el-button>
    </template>
  </OperationDialog>
</template>

<script setup>
import IconUpload from './IconUpload.vue';
import OperationDialog from './OperationDialog.vue';

defineProps({
  visible: { type: Boolean, default: false },
  mode: { type: String, default: 'create' },
  form: { type: Object, required: true },
  parentOptions: { type: Array, default: () => [] },
  treeProps: { type: Object, required: true },
  resolveIcon: { type: Function, required: true },
  iconUploading: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  backendUrl: { type: Function, required: true },
});

const emit = defineEmits(['close', 'save', 'select-icon']);
</script>
