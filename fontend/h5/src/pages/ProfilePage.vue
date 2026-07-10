<template>
  <section class="profile-panel app-profile-identity">
    <UserRound :size="34" />
    <div>
      <strong>{{ userName }}</strong>
      <span>{{ schoolName }}</span>
      <small>{{ roleName }} · {{ scopeText }}</small>
    </div>
  </section>

  <van-cell-group inset class="app-profile-group">
    <van-cell title="姓名" :value="userName" />
    <van-cell title="登录账号" :value="accountName" />
    <van-cell title="当前角色" :value="roleName" />
    <van-cell title="所属学校" :value="schoolName" />
    <van-cell title="数据范围" :label="scopeDetail" :value="scopeText" />
    <van-cell v-if="documentVisible" title="文档中心" value="查看" is-link @click="emit('open-tab', 'doc')" />
    <van-cell v-if="templateVisible" title="模板库" value="下载" is-link @click="emit('open-tab', 'templateLib')" />
  </van-cell-group>

  <section v-if="switchState.items.length > 1" class="app-profile-section">
    <header>
      <strong>切换身份</strong>
      <small>{{ switchState.message || '选择同一用户绑定的其他账号' }}</small>
    </header>
    <div class="app-account-list">
      <button
        v-for="account in switchState.items"
        :key="account.id"
        type="button"
        :disabled="account.is_current || switchState.loading"
        :class="{ current: account.is_current }"
        @click="emit('switch-account', account)"
      >
        <span>
          <strong>{{ accountTitle(account) }}</strong>
          <small>{{ accountLabel(account) }}</small>
        </span>
        <em>{{ account.is_current ? '当前' : '切换' }}</em>
      </button>
    </div>
  </section>

  <section class="app-profile-logout">
    <AppButton block variant="danger" @click="emit('logout')">退出当前账号</AppButton>
  </section>
</template>

<script setup>
import { UserRound } from '@lucide/vue';
import AppButton from '../components/ui/AppButton.vue';

const props = defineProps({
  userName: { type: String, default: '' },
  accountName: { type: String, default: '' },
  roleName: { type: String, default: '' },
  schoolName: { type: String, default: '' },
  scopeText: { type: String, default: '' },
  scopeDetail: { type: String, default: '' },
  documentVisible: { type: Boolean, default: false },
  templateVisible: { type: Boolean, default: false },
  switchState: { type: Object, required: true },
  roleLabels: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['logout', 'open-tab', 'switch-account']);

function accountTitle(account) {
  return account?.name || account?.login_name || '未命名账号';
}

function accountLabel(account) {
  return [
    account?.role_name || props.roleLabels[account?.role_type] || account?.role_type || '未分配角色',
    account?.login_name || '',
  ].filter(Boolean).join(' / ');
}
</script>

<style scoped>
.app-profile-identity {
  box-shadow: var(--app-shadow-sm);
}

.app-profile-identity small {
  display: block;
  margin-top: 4px;
  color: var(--app-text-secondary);
  font-size: 12px;
}

.app-profile-group {
  margin-top: 12px;
  overflow: hidden;
  border: 1px solid var(--app-line);
  border-radius: var(--app-radius);
}

.app-profile-section {
  margin-top: 14px;
}

.app-profile-section > header {
  display: grid;
  gap: 3px;
  padding: 2px 2px 8px;
}

.app-profile-section > header strong {
  font-size: 15px;
}

.app-profile-section > header small {
  color: var(--app-text-secondary);
  font-size: 12px;
}

.app-account-list {
  border: 1px solid var(--app-line);
  border-radius: var(--app-radius);
  overflow: hidden;
  background: var(--app-surface);
}

.app-account-list button {
  width: 100%;
  min-height: 62px;
  border: 0;
  border-bottom: 1px solid var(--app-line);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  color: var(--app-text);
  text-align: left;
  background: var(--app-surface);
  transition: background var(--app-motion-control) var(--app-ease-standard),
    transform var(--app-motion-fast) var(--app-ease-standard);
}

.app-account-list button:last-child {
  border-bottom: 0;
}

.app-account-list button:active:not(:disabled) {
  background: var(--app-surface-pressed);
  transform: scale(.99);
}

.app-account-list button > span {
  min-width: 0;
  display: grid;
  gap: 4px;
}

.app-account-list button small {
  color: var(--app-text-secondary);
}

.app-account-list button em {
  color: var(--app-primary);
  font-size: 12px;
  font-style: normal;
}

.app-account-list button.current em {
  color: var(--app-success);
}

.app-profile-logout {
  margin-top: 16px;
}
</style>
