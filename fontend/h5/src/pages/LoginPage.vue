<template>
  <section class="mobile-login app-login-page">
    <header>
      <UserRound :size="30" />
      <div>
        <strong>账号登录</strong>
        <span>{{ schoolName }}</span>
      </div>
    </header>
    <label class="app-field">
      <span>账号</span>
      <input v-model="loginForm.login_name" autocomplete="username" placeholder="请输入账号">
    </label>
    <label class="app-field">
      <span>密码</span>
      <input v-model="loginForm.password" autocomplete="current-password" placeholder="请输入密码" type="password">
    </label>
    <AppButton block :loading="loginState.loading" @click="emit('login')">
      <template #icon><LogIn :size="17" /></template>
      登录
    </AppButton>
    <section v-if="registerState.enabled" class="mobile-register">
      <AppButton block variant="secondary" @click="emit('toggle-register')">
        {{ registerState.open ? '收起注册' : '注册测试账号' }}
      </AppButton>
      <div v-if="registerState.open" class="mobile-register-form">
        <label class="app-field">
          <span>注册角色</span>
          <select v-model="registerForm.role_type">
            <option
              v-for="role in registerRoleOptions"
              :key="role.role_type"
              :value="role.role_type"
            >
              {{ role.name || roleLabels[role.role_type] || role.role_type }}
            </option>
          </select>
        </label>
        <label class="app-field"><span>姓名</span><input v-model="registerForm.name" autocomplete="name"></label>
        <label class="app-field"><span>登录账号</span><input v-model="registerForm.login_name" autocomplete="username"></label>
        <label class="app-field"><span>密码</span><input v-model="registerForm.password" autocomplete="new-password" type="password"></label>
        <label class="app-field"><span>手机</span><input v-model="registerForm.mobile" autocomplete="tel"></label>
        <label v-if="registerForm.role_type === 'student'" class="app-field"><span>学号</span><input v-model="registerForm.student_num"></label>
        <label v-if="registerForm.role_type === 'teacher'" class="app-field"><span>工号</span><input v-model="registerForm.teacher_num"></label>
        <AppButton block :loading="registerState.loading" @click="emit('register')">创建并登录</AppButton>
      </div>
    </section>
    <small v-if="loginState.message" class="app-login-message">{{ loginState.message }}</small>
    <small v-if="registerState.message" class="app-login-message">{{ registerState.message }}</small>
  </section>
</template>

<script setup>
import { LogIn, UserRound } from '@lucide/vue';
import AppButton from '../components/ui/AppButton.vue';

defineProps({
  schoolName: { type: String, default: '' },
  loginForm: { type: Object, required: true },
  loginState: { type: Object, required: true },
  registerForm: { type: Object, required: true },
  registerState: { type: Object, required: true },
  registerRoleOptions: { type: Array, default: () => [] },
  roleLabels: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['login', 'register', 'toggle-register']);
</script>

<style scoped>
.app-login-page {
  box-shadow: 0 18px 48px rgba(24, 33, 43, .12);
}

.app-login-page :deep(.app-field input),
.app-login-page :deep(.app-field select) {
  height: var(--app-touch-size);
}

.app-login-message {
  min-height: 0;
  color: var(--app-danger);
  line-height: 1.45;
}
</style>
