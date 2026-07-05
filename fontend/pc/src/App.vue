<template>
  <main v-if="!isLoggedIn" class="login-shell" :style="loginPageStyle">
    <section class="login-panel">
      <header>
        <UserRound :size="26" />
        <div>
          <strong>实践管理系统</strong>
          <span>{{ loginSchoolName }}</span>
        </div>
      </header>
      <label>
        <span>账号</span>
        <input ref="loginNameInput" v-model="loginForm.login_name" autocomplete="username" placeholder="请输入账号">
      </label>
      <label>
        <span>密码</span>
        <input v-model="loginForm.password" autocomplete="current-password" placeholder="请输入密码" type="password">
      </label>
      <button type="button" :disabled="loginState.loading" @click="submitLogin">
        <LogIn :size="17" />
        登录
      </button>
      <div v-if="registerState.enabled" class="login-register">
        <button type="button" class="login-secondary-button" @click="toggleRegisterForm">
          {{ registerState.open ? '收起注册' : '注册测试账号' }}
        </button>
        <div v-if="registerState.open" class="login-register-form">
          <label>
            <span>注册角色</span>
            <select v-model="registerForm.role_type">
              <option
                v-for="role in registerRoleOptions"
                :key="role.role_type"
                :value="role.role_type"
              >
                {{ role.name || roleTypeNames[role.role_type] || role.role_type }}
              </option>
            </select>
          </label>
          <label><span>姓名</span><input v-model="registerForm.name" autocomplete="name"></label>
          <label><span>登录账号</span><input v-model="registerForm.login_name" autocomplete="username"></label>
          <label><span>密码</span><input v-model="registerForm.password" autocomplete="new-password" type="password"></label>
          <label><span>手机</span><input v-model="registerForm.mobile" autocomplete="tel"></label>
          <label v-if="registerForm.role_type === 'student'"><span>学号</span><input v-model="registerForm.student_num"></label>
          <label v-if="registerForm.role_type === 'teacher'"><span>工号</span><input v-model="registerForm.teacher_num"></label>
          <button type="button" :disabled="registerState.loading" @click="submitRegister">
            创建并登录
          </button>
        </div>
      </div>
      <small v-if="loginState.message">{{ loginState.message }}</small>
      <small v-if="registerState.message">{{ registerState.message }}</small>
    </section>
  </main>

  <main v-else class="desktop-shell" :style="desktopStyle" @click.left="closeDesktopContextMenu" @contextmenu.prevent="openDesktopContextMenu">
    <header class="topbar">
      <div class="brand">
        <span class="brand-mark">实</span>
        <span>实践管理系统</span>
      </div>
      <div class="global-search-wrap">
        <label class="global-search">
          <Search :size="15" />
          <input
            v-model="keyword"
            type="search"
            placeholder="搜索模块、基地或消息"
            @keyup.enter="submitGlobalSearch"
          >
        </label>
        <div v-if="showGlobalSearchResults" class="global-search-results">
          <button
            v-for="module in globalSearchResults"
            :key="module.id"
            type="button"
            @mousedown.prevent="openGlobalSearchModule(module)"
          >
            <AppIcon class="search-result-glyph" :icon="module.icon" :icon-url="module.iconUrl" :label="module.name" :color="module.color" :size="15" :backend-url="backendUrl" />
            <span>
              <strong>{{ module.name }}</strong>
              <small>{{ module.scope }}</small>
            </span>
          </button>
        </div>
      </div>
      <div class="top-actions">
        <el-button text :icon="Bell" title="消息中心" @click="openMessageCenter" />
        <el-button v-if="isLoggedIn" text class="operator-button" @click="openProfile">
          <span class="top-avatar" :style="topAvatarStyle">
            <UserRound v-if="!profileState.form.avatar" :size="14" />
          </span>
          <span>{{ operatorName }}</span>
        </el-button>
        <div v-if="isLoggedIn && switchableLoginAccounts.length > 0" class="switch-account-wrap">
          <el-button class="switch-account-button" :loading="switchAccountState.loading" @click="toggleSwitchAccountMenu">
            <UsersRound :size="15" />
            切换身份
          </el-button>
          <div v-if="switchAccountState.open" class="switch-account-menu">
            <button
              v-for="account in switchableLoginAccounts"
              :key="account.id"
              type="button"
              class="switch-account-item"
              @click="switchLoginAccount(account.id)"
            >
              <strong>{{ switchAccountName(account) }}</strong>
              <small>{{ switchAccountMeta(account) }}</small>
            </button>
          </div>
        </div>
        <div v-if="switchAccountState.open && switchableLoginAccounts.length > 0" class="switch-account-backdrop" @click="switchAccountState.open = false" />
        <span v-if="!isLoggedIn">{{ operatorName }}</span>
        <el-button v-if="!isLoggedIn" text :icon="LogIn" @click="focusLogin">
          登录
        </el-button>
        <el-button v-else text :icon="LogOut" :loading="loginState.loading" @click="submitLogout">
          退出
        </el-button>
        <span>{{ clock }}</span>
      </div>
    </header>

    <section class="workspace">
      <nav class="desktop-icons" aria-label="应用模块">
        <ShortcutTile
          v-for="module in visibleDesktopModules"
          :key="module.id"
          :item="module"
          mode="desktop"
          :href="moduleHref(module)"
          :active="isModuleFocused(module.id)"
          :backend-url="backendUrl"
          @open="openModule(module)"
        />
      </nav>

      <DesktopWindow
        v-for="win in visibleWindows"
        :key="win.id"
        :title="win.module.name"
        :icon="win.module.icon"
        :z-index="win.zIndex"
        :initial-left="win.left"
        :initial-top="win.top"
        :initial-width="win.width"
        :initial-height="win.height"
        @focus="focusWindow(win.id)"
        @minimize="minimizeWindow(win.id)"
        @close="closeWindow(win.id)"
      >
        <div v-if="win.module.id === 'profile'" class="profile-app">
          <section class="profile-content">
            <div class="content-head">
              <div>
                <h1>个人设置</h1>
                <p>头像资料、桌面壁纸和消息接收偏好。</p>
              </div>
              <div class="head-actions">
                <el-button :icon="RefreshCw" :loading="profileState.loading" @click="loadProfile">
                  读取
                </el-button>
                <el-button type="primary" :icon="Save" :loading="profileState.loading" @click="saveProfile">
                  保存设置
                </el-button>
              </div>
            </div>

            <el-alert
              v-if="profileState.message"
              :type="profileAlertType"
              :closable="false"
              show-icon
              :title="profileState.message"
            />

            <div class="profile-settings">
              <section class="profile-panel-card profile-card-main">
                <div class="avatar-edit">
                  <label
                    for="profile-avatar-file"
                    class="avatar-upload-button"
                    :class="{ disabled: profileState.loading }"
                    aria-label="上传头像"
                    @click="guardProfileAssetClick"
                  >
                    <span class="avatar-preview" :style="avatarStyle">
                      <UserRound v-if="!profileState.form.avatar" :size="42" />
                    </span>
                    <span class="asset-action"><ImagePlus :size="15" /></span>
                  </label>
                  <input
                    id="profile-avatar-file"
                    class="hidden-file"
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/gif"
                    @change="event => handleAssetSelected('avatar', event)"
                  >
                  <div>
                    <strong>{{ profileState.form.name || operatorName }}</strong>
                    <small>{{ roleText }} / {{ schoolDataText }}</small>
                  </div>
                </div>
                <div class="profile-form-grid">
                  <label>
                    <span>姓名</span>
                    <input v-model="profileState.form.name">
                  </label>
                  <label>
                    <span>手机号</span>
                    <input v-model="profileState.form.mobile">
                  </label>
                  <label>
                    <span>邮箱</span>
                    <input v-model="profileState.form.email">
                  </label>
                </div>
              </section>

              <section
                ref="wallpaperSectionRef"
                class="profile-panel-card"
                :class="{ focused: profileState.focus === 'wallpaper' }"
              >
                <header>
                  <strong>桌面壁纸</strong>
                  <small>保存后立即应用到 PC 工作台。</small>
                </header>
                <div class="wallpaper-grid">
                  <button
                    v-for="preset in wallpaperPresets"
                    :key="preset.key"
                    type="button"
                    :class="{ active: profileState.form.wallpaper === preset.key && !profileState.form.wallpaper_url }"
                    @click="selectWallpaper(preset.key)"
                  >
                    <span class="wallpaper-swatch" :style="{ background: preset.background }" />
                    <span>{{ preset.name }}</span>
                  </button>
                </div>
                <label
                  for="profile-wallpaper-file"
                  class="wallpaper-upload-button"
                  :class="{ active: Boolean(profileState.form.wallpaper_url), disabled: profileState.loading }"
                  :style="wallpaperUploadStyle"
                  aria-label="上传壁纸"
                  @click="guardProfileAssetClick"
                >
                  <ImagePlus v-if="!profileState.form.wallpaper_url" :size="22" />
                  <span class="asset-action"><ImagePlus :size="15" /></span>
                </label>
                <input
                  id="profile-wallpaper-file"
                  class="hidden-file"
                  type="file"
                  accept="image/jpeg,image/png,image/webp,image/gif"
                  @change="event => handleAssetSelected('wallpaper', event)"
                >
              </section>

              <section v-if="canManageLoginBackground" class="profile-panel-card">
                <header>
                  <strong>学校登录背景</strong>
                  <small>用于未登录页面，上传后全校 PC 登录页立即生效。</small>
                </header>
                <label
                  for="school-login-background-file"
                  class="login-background-upload-button"
                  :class="{ active: Boolean(loginPageState.login_background_url), disabled: loginPageState.loading }"
                  :style="loginBackgroundUploadStyle"
                  aria-label="上传学校登录背景"
                  @click="guardLoginBackgroundClick"
                >
                  <ImagePlus v-if="!loginPageState.login_background_url" :size="22" />
                  <span>{{ loginPageState.login_background_url ? '点击更换背景' : '点击上传背景' }}</span>
                </label>
                <input
                  id="school-login-background-file"
                  class="hidden-file"
                  type="file"
                  accept="image/jpeg,image/png,image/webp,image/gif"
                  @change="handleLoginBackgroundSelected"
                >
                <small v-if="loginPageState.message" class="profile-inline-message">{{ loginPageState.message }}</small>
              </section>

              <section class="profile-panel-card">
                <header>
                  <strong>消息接收</strong>
                  <small>用于系统消息、企业微信和邮件通知。</small>
                </header>
                <div class="notify-list">
                  <label>
                    <span>
                      <strong>站内消息</strong>
                      <small>系统内消息中心提醒</small>
                    </span>
                    <el-switch v-model="profileState.form.notify.system" />
                  </label>
                  <label>
                    <span>
                      <strong>企业微信</strong>
                      <small>通过企业微信应用推送</small>
                    </span>
                    <el-switch v-model="profileState.form.notify.wechat" />
                  </label>
                  <label>
                    <span>
                      <strong>邮件</strong>
                      <small>发送到个人邮箱</small>
                    </span>
                    <el-switch v-model="profileState.form.notify.email" />
                  </label>
                </div>
              </section>
            </div>
          </section>
        </div>

        <div v-else class="app-body" :class="{ 'no-sidebar': !sidebarItems(win).length }">
          <aside v-if="sidebarItems(win).length" class="module-sidebar">
            <section>
              <p>{{ win.module.id === 'config' ? '设置' : '模块' }}</p>
              <a
                v-for="item in sidebarItems(win)"
                :key="item.key"
                :href="panelHref(win, item.key)"
                :data-window-panel="item.key"
                class="side-item"
                :class="{ active: win.panel === item.key }"
                @click.prevent.stop="activateWindowPanel(win, item.key)"
              >
                <span />
                {{ item.name }}
              </a>
            </section>
          </aside>

          <section class="content">
            <div class="content-head">
              <div>
                <h1>{{ win.module.name }}</h1>
              </div>
              <div class="head-actions">
                <el-button :icon="BookOpen" @click="openGuide(win)">
                  操作说明
                </el-button>
              </div>
            </div>

            <el-alert
              v-if="permissionState.error"
              type="warning"
              :closable="false"
              show-icon
              :title="permissionState.error"
            />

            <div class="module-workspace">
              <section class="module-content-panel">
                <div v-if="win.module.id === 'internship'" class="internship-panel">
                  <div v-if="hasInternshipToolbarActions(win.panel)" class="internship-toolbar action-only">
                    <div class="data-list-actions">
                      <el-button v-if="win.panel === 'arrangements' && canManageInternship" :icon="CalendarCheck" @click="openArrangementDialog">
                        新增任务
                      </el-button>
                      <el-button v-if="win.panel === 'arrangements' && canManageInternship" :icon="Upload" :loading="internshipState.importing" @click="chooseArrangementImportExcel">
                        导入任务分配
                      </el-button>
                      <el-button v-if="win.panel === 'plans' && canManageInternshipPlan" :icon="FileText" @click="openPlanDialog">
                        新增计划
                      </el-button>
                      <el-button v-if="win.panel === 'scores' && canSaveInternshipScore" :icon="GraduationCap" @click="openScoreDialog">
                        录入成绩
                      </el-button>
                      <el-button v-if="win.panel === 'baseFlows' && canManageInternship" :icon="Plus" @click="openBaseFlowDialog">
                        新增基地流程
                      </el-button>
                    </div>
                  </div>

                  <el-alert
                    v-if="internshipState.message"
                    type="warning"
                    :closable="false"
                    show-icon
                    :title="internshipState.message"
                  />

                  <div v-if="internshipState.dialog.type" class="operation-mask" @click.self="closeInternshipDialog">
                    <section class="operation-dialog" :class="{ 'task-detail-dialog': internshipState.dialog.type === 'arrangementDetail' }">
                      <header>
                        <strong>{{ internshipState.dialog.title }}</strong>
                        <button type="button" @click="closeInternshipDialog">关闭</button>
                      </header>

                      <div v-if="internshipState.dialog.type === 'arrangement'" class="operation-form">
                        <label>
                          <span>实习计划</span>
                          <el-select v-model="internshipState.arrangementForm.plan_id" clearable filterable @change="handleArrangementPlanChange">
                            <el-option v-for="plan in arrangementPlanOptions()" :key="plan.id" :label="internshipPlanLabel(plan)" :value="plan.id" />
                          </el-select>
                        </label>
                        <label><span>任务标题</span><input v-model="internshipState.arrangementForm.title"></label>
                        <label><span>任务编号</span><input v-model="internshipState.arrangementForm.task_no" placeholder="同一计划下唯一"></label>
                        <label><span>批次</span><input v-model="internshipState.arrangementForm.batch_no"></label>
                        <!-- 暂时隐藏学期输入，后续需要时恢复。 -->
                        <!--
                        <label><span>学期</span><input v-model="internshipState.arrangementForm.semester"></label>
                        -->
                        <label>
                          <span>届次</span>
                          <el-select v-model="internshipState.arrangementForm.grade_id" clearable filterable @change="handleArrangementGradeChange">
                            <el-option v-for="grade in internshipState.options.grades" :key="grade.grade_id" :label="grade.grade_name" :value="grade.grade_id" />
                          </el-select>
                        </label>
                        <label>
                          <span>学院</span>
                          <el-select v-model="internshipState.arrangementForm.dep_id" clearable filterable @change="handleArrangementDepartmentChange">
                            <el-option v-for="dep in arrangementDepartmentOptions()" :key="dep.dep_id" :label="dep.dep_name" :value="dep.dep_id" />
                          </el-select>
                        </label>
                        <label>
                          <span>专业</span>
                          <el-select v-model="internshipState.arrangementForm.profession_id" clearable filterable @change="handleArrangementProfessionChange">
                            <el-option v-for="profession in arrangementProfessionOptions()" :key="profession.profession_id" :label="profession.profession_name" :value="profession.profession_id" />
                          </el-select>
                        </label>
                        <label>
                          <span>负责老师</span>
                          <el-select v-model="internshipState.arrangementForm.teacher_id" clearable filterable>
                            <el-option v-for="teacher in arrangementTeacherOptions()" :key="teacher.teacher_id" :label="teacher.teacher_name" :value="teacher.teacher_id" />
                          </el-select>
                        </label>
                        <label>
                          <span>任务班级</span>
                          <el-select v-model="internshipState.arrangementForm.class_ids" multiple collapse-tags collapse-tags-tooltip filterable>
                            <el-option v-for="classItem in arrangementClassOptions()" :key="classItem.class_id" :label="classItem.class_name" :value="classItem.class_id" />
                          </el-select>
                        </label>
                        <label><span>学分</span><input v-model="internshipState.arrangementForm.credit" type="number" min="0" step="0.5"></label>
                        <label>
                          <span>基地</span>
                          <el-select v-model="internshipState.arrangementForm.base_id" clearable filterable>
                            <el-option v-for="base in internshipState.options.bases" :key="base.id" :label="base.name" :value="base.id" />
                          </el-select>
                        </label>
                        <label>
                          <span>类型</span>
                          <el-select v-model="internshipState.arrangementForm.type">
                            <el-option v-for="type in internshipState.options.types" :key="type" :label="arrangementTypeText(type)" :value="type" />
                          </el-select>
                        </label>
                        <label>
                          <span>组织方式</span>
                          <el-select v-model="internshipState.arrangementForm.organize_mode">
                            <el-option v-for="mode in internshipState.options.organize_modes" :key="mode" :label="organizeModeText(mode)" :value="mode" />
                          </el-select>
                        </label>
                        <label><span>开始日期</span><input v-model="internshipState.arrangementForm.start_date" type="date"></label>
                        <label><span>结束日期</span><input v-model="internshipState.arrangementForm.end_date" type="date"></label>
                        <label><span>地点</span><input v-model="internshipState.arrangementForm.location"></label>
                        <label class="span-2">
                          <span>任务说明</span>
                          <textarea v-model="internshipState.arrangementForm.description" rows="3" />
                        </label>
                        <label v-if="internshipState.arrangementForm.id" class="span-2">
                          <span>变更申请原因</span>
                          <textarea v-model="internshipState.arrangementForm.change_reason" rows="3" placeholder="已存在任务调整后需审核通过才会生效" />
                        </label>
                      </div>

                      <div v-else-if="internshipState.dialog.type === 'plan'" class="operation-form single">
                        <!-- 暂时隐藏学期输入，后续需要时恢复。 -->
                        <!--
                        <label>
                          <span>学期</span>
                          <input v-model="internshipState.planForm.semester">
                        </label>
                        -->
                        <label>
                          <span>课程名称</span>
                          <input v-model="internshipState.planForm.course_name">
                        </label>
                        <label>
                          <span>课程代码</span>
                          <input v-model="internshipState.planForm.course_code">
                        </label>
                        <label>
                          <span>届次</span>
                          <el-select v-model="internshipState.planForm.grade_id" filterable @change="handlePlanGradeChange">
                            <el-option v-for="grade in internshipState.options.grades" :key="grade.grade_id" :label="grade.grade_name" :value="grade.grade_id" />
                          </el-select>
                        </label>
                        <label>
                          <span>学院</span>
                          <el-select v-model="internshipState.planForm.dep_id" filterable @change="handlePlanDepartmentChange">
                            <el-option v-for="dep in internshipState.options.departments" :key="dep.dep_id" :label="dep.dep_name" :value="dep.dep_id" />
                          </el-select>
                        </label>
                        <label>
                          <span>专业</span>
                          <el-select v-model="internshipState.planForm.profession_id" filterable>
                            <el-option v-for="profession in planProfessionOptions()" :key="profession.profession_id" :label="profession.profession_name" :value="profession.profession_id" />
                          </el-select>
                        </label>
                        <label><span>学分</span><input v-model="internshipState.planForm.credit" type="number" min="0" step="0.5"></label>
                        <label>
                          <span>成绩规则</span>
                          <el-select v-model="internshipState.planForm.score_rule">
                            <el-option label="按任务平均" value="average" />
                            <el-option label="按任务累计" value="sum" />
                            <el-option label="按权重核定" value="weighted" />
                            <el-option label="人工核定" value="manual" />
                          </el-select>
                        </label>
                        <label>
                          <span>计划内容</span>
                          <textarea v-model="internshipState.planForm.content" rows="8" />
                        </label>
                      </div>

                      <div v-else-if="internshipState.dialog.type === 'score'" class="operation-form">
                        <label>
                          <span>学生</span>
                          <el-select
                            v-model="internshipState.scoreForm.pair_id"
                            filterable
                            remote
                            reserve-keyword
                            :remote-method="searchScorePairs"
                            :loading="internshipState.scorePairLoading"
                            placeholder="搜索学生、学号或任务"
                            @change="selectScorePair"
                          >
                            <el-option
                              v-for="pair in internshipState.lists.pairs.items"
                              :key="pair.id"
                              :label="`${pair.student_name} / ${pair.arrangement_title}`"
                              :value="pair.id"
                            />
                          </el-select>
                        </label>
                        <label><span>签到成绩</span><input v-model="internshipState.scoreForm.sign_in_score" type="number"></label>
                        <label><span>日志成绩</span><input v-model="internshipState.scoreForm.journal_score" type="number"></label>
                        <label><span>报告成绩</span><input v-model="internshipState.scoreForm.report_score" type="number"></label>
                        <label><span>企业成绩</span><input v-model="internshipState.scoreForm.enterprise_score" type="number"></label>
                        <label><span>评语</span><input v-model="internshipState.scoreForm.comment"></label>
                      </div>

                      <div v-else-if="internshipState.dialog.type === 'courseScore'" class="operation-form single">
                        <p>{{ internshipState.dialog.description }}</p>
                        <label><span>课程成绩</span><input v-model="internshipState.courseScoreForm.score_value" type="number" min="0" max="100" step="0.1"></label>
                        <label>
                          <span>核定说明</span>
                          <textarea v-model="internshipState.courseScoreForm.remark" rows="4" maxlength="1000" />
                        </label>
                      </div>

                      <div v-else-if="internshipState.dialog.type === 'baseFlow'" class="operation-form">
                        <label>
                          <span>流程类型</span>
                          <el-select v-model="internshipState.baseFlowForm.type">
                            <el-option v-for="item in baseFlowTypes" :key="item.value" :label="item.label" :value="item.value" />
                          </el-select>
                        </label>
                        <label>
                          <span>实习基地</span>
                          <el-select v-model="internshipState.baseFlowForm.base_id" clearable filterable>
                            <el-option v-for="base in internshipState.options.bases" :key="base.id" :label="base.name" :value="base.id" />
                          </el-select>
                        </label>
                        <label>
                          <span>学院</span>
                          <el-select v-model="internshipState.baseFlowForm.dep_id" clearable filterable>
                            <el-option v-for="dep in internshipState.options.departments" :key="dep.dep_id" :label="dep.dep_name" :value="dep.dep_id" />
                          </el-select>
                        </label>
                        <label>
                          <span>标题</span>
                          <input v-model="internshipState.baseFlowForm.title">
                        </label>
                        <label v-if="internshipState.baseFlowForm.type === 'application'">
                          <span>基地类型</span>
                          <el-select v-model="internshipState.baseFlowForm.base_type">
                            <el-option label="固定基地" value="fixed" />
                            <el-option label="实习点" value="spot" />
                          </el-select>
                        </label>
                        <label v-if="internshipState.baseFlowForm.type === 'usage'">
                          <span>使用类型</span>
                          <input v-model="internshipState.baseFlowForm.usage_type" placeholder="文件制度、基地运行等">
                        </label>
                        <label v-if="internshipState.baseFlowForm.type === 'result'">
                          <span>成果类型</span>
                          <input v-model="internshipState.baseFlowForm.result_type" placeholder="成果、运行数据等">
                        </label>
                        <label v-if="internshipState.baseFlowForm.type === 'expense'">
                          <span>费用金额</span>
                          <input v-model="internshipState.baseFlowForm.amount" type="number">
                        </label>
                        <label class="span-2">
                          <span>内容</span>
                          <textarea v-model="internshipState.baseFlowForm.content" rows="7" />
                        </label>
                      </div>

                      <div v-else-if="internshipState.dialog.type === 'arrangementDetail'" class="operation-form single">
                        <section class="task-detail-view">
                          <div class="task-detail-grid">
                            <article>
                              <span>课程计划</span>
                              <strong>{{ internshipState.arrangementDetail.item?.course_name || '-' }}</strong>
                            </article>
                            <article>
                              <span>负责老师</span>
                              <strong>{{ internshipState.arrangementDetail.item?.teacher_name || '-' }}</strong>
                            </article>
                            <article>
                              <span>任务编号</span>
                              <strong>{{ internshipState.arrangementDetail.item?.task_no || '-' }}</strong>
                            </article>
                            <article>
                              <span>批次</span>
                              <strong>{{ internshipState.arrangementDetail.item?.batch_no || '-' }}</strong>
                            </article>
                            <article>
                              <span>时间</span>
                              <strong>{{ dateRangeText(internshipState.arrangementDetail.item?.start_date, internshipState.arrangementDetail.item?.end_date) }}</strong>
                            </article>
                            <article>
                              <span>地点</span>
                              <strong>{{ internshipState.arrangementDetail.item?.location || '-' }}</strong>
                            </article>
                            <article>
                              <span>绑定班级</span>
                              <strong>{{ internshipState.arrangementDetail.classes.length }} 个</strong>
                            </article>
                            <article>
                              <span>绑定学生</span>
                              <strong>{{ internshipState.arrangementDetail.students.length }} 人</strong>
                            </article>
                          </div>
                          <div class="task-detail-section">
                            <header>
                              <strong>绑定班级</strong>
                              <small>{{ internshipState.arrangementDetail.classes.length }} 个</small>
                            </header>
                            <el-table :data="internshipState.arrangementDetail.classes" max-height="220" stripe>
                              <el-table-column prop="class_name" label="班级" min-width="150" />
                              <el-table-column prop="class_num" label="班号" width="120" />
                              <el-table-column prop="student_count_snapshot" label="学生数" width="90" />
                            </el-table>
                          </div>
                          <div class="task-detail-section">
                            <header>
                              <strong>绑定学生</strong>
                              <small>{{ internshipState.arrangementDetail.students.length }} 人</small>
                            </header>
                            <el-table :data="internshipState.arrangementDetail.students" max-height="280" stripe>
                              <el-table-column prop="student_name" label="学生" width="120" />
                              <el-table-column prop="student_num" label="学号" width="130" />
                              <el-table-column prop="class_name" label="班级" min-width="150" />
                              <el-table-column prop="teacher_name" label="负责老师" width="120" />
                              <el-table-column label="评分状态" width="100">
                                <template #default="{ row }">
                                  <el-tag :type="row.final_score !== null && row.final_score !== undefined ? 'success' : 'warning'">
                                    {{ row.final_score !== null && row.final_score !== undefined ? '已评分' : '待评分' }}
                                  </el-tag>
                                </template>
                              </el-table-column>
                              <el-table-column prop="final_score" label="总评" width="90" />
                              <el-table-column prop="score_teacher_name" label="评分人" width="120" />
                              <el-table-column prop="status" label="状态" width="90">
                                <template #default="{ row }">
                                  <el-tag :type="statusTagType(row.status)">{{ statusText(row.status) }}</el-tag>
                                </template>
                              </el-table-column>
                            </el-table>
                          </div>
                        </section>
                      </div>

                      <div v-else-if="['review', 'reopen'].includes(internshipState.dialog.type)" class="operation-form single">
                        <p>{{ internshipState.dialog.description }}</p>
                        <label v-if="internshipState.dialog.type === 'review'">
                          <span>审核结论</span>
                          <el-segmented v-model="internshipState.dialog.status" :options="reviewStatusOptions(internshipState.dialog.entity)" />
                        </label>
                        <label>
                          <span>{{ internshipState.dialog.type === 'reopen' ? '修改理由' : (isRejectReviewStatus(internshipState.dialog.status) ? '退回原因' : '审核意见') }}</span>
                          <textarea
                            v-model="internshipState.dialog.reason"
                            rows="5"
                            :maxlength="reviewRuleMax(internshipState.dialog.entity, internshipState.dialog.status)"
                            @input="trimReviewReasonMax"
                          />
                          <small class="review-counter">
                            <span>{{ reviewRuleText(internshipState.dialog.entity, internshipState.dialog.status, internshipState.dialog.type === 'reopen' ? '修改理由' : null) }}</span>
                            <span>{{ reviewReasonLength }}/{{ reviewRuleMaxText(internshipState.dialog.entity, internshipState.dialog.status) }}</span>
                          </small>
                        </label>
                      </div>

                      <div v-else-if="internshipState.dialog.type === 'timeline'" class="operation-form single">
                        <div class="timeline-view">
                          <section
                            v-for="(cycle, index) in internshipTimelineCycles"
                            :key="timelineCycleKey(cycle, index)"
                            class="timeline-cycle"
                          >
                            <span />
                            <div>
                              <header class="timeline-node-head">
                                <strong>{{ timelineCycleTitle(cycle) }}</strong>
                                <small>{{ timelineCycleTime(cycle) }}</small>
                              </header>
                              <p>{{ timelineCycleContent(cycle) }}</p>
                              <div v-if="cycle.branches?.length" class="timeline-branches">
                                <article
                                  v-for="(branch, branchIndex) in cycle.branches"
                                  :key="timelineBranchKey(branch, branchIndex)"
                                  class="timeline-branch"
                                  :class="{ reopen: isModifyAfterAcceptBranch(branch) }"
                                >
                                  <span />
                                  <div>
                                    <header class="timeline-node-head">
                                      <strong>{{ timelineBranchTitle(branch) }}</strong>
                                      <small>{{ timelineBranchTime(branch) }}</small>
                                    </header>
                                    <p v-if="timelineBranchContent(branch)">{{ timelineBranchContent(branch) }}</p>
                                    <p v-for="review in timelineBranchReviews(branch)" :key="review.id" class="timeline-review">
                                      <span>状态：{{ statusText(review.status) }}</span>
                                      <span>审核意见：{{ review.opinion || '-' }}</span>
                                      <span v-if="review.score !== null && review.score !== undefined">评分：{{ review.score }}</span>
                                    </p>
                                  </div>
                                </article>
                              </div>
                            </div>
                          </section>
                          <small v-if="!internshipTimelineCycles.length">暂无流程记录</small>
                        </div>
                      </div>

                      <footer>
                        <el-button @click="closeInternshipDialog">取消</el-button>
                        <template v-if="internshipState.dialog.type === 'plan'">
                          <el-button :disabled="internshipState.loading" :loading="internshipState.loading" @click="submitInternshipPlan('draft')">保存草稿</el-button>
                          <el-button type="primary" :disabled="internshipState.loading" :loading="internshipState.loading" @click="submitInternshipPlan('wait')">提交审核</el-button>
                        </template>
                        <template v-else-if="internshipState.dialog.type === 'baseFlow'">
                          <el-button :disabled="internshipState.loading" :loading="internshipState.loading" @click="submitBaseFlow('draft')">保存草稿</el-button>
                          <el-button type="primary" :disabled="internshipState.loading" :loading="internshipState.loading" @click="submitBaseFlow('wait')">提交审核</el-button>
                        </template>
                        <template v-else-if="internshipState.dialog.type === 'review'">
                          <el-button :disabled="internshipState.loading" :loading="internshipState.loading" @click="saveInternshipDialogReviewDraft">保存草稿</el-button>
                          <el-button type="primary" :disabled="internshipState.loading" :loading="internshipState.loading" @click="confirmInternshipDialog">提交审核</el-button>
                        </template>
                        <el-button v-else-if="internshipState.dialog.type !== 'timeline'" type="primary" :disabled="internshipState.loading" :loading="internshipState.loading" @click="confirmInternshipDialog">
                          确认
                        </el-button>
                      </footer>
                    </section>
                  </div>

                  <template v-if="win.panel === 'overview'">
                    <div class="internship-overview-work">
                      <div class="overview-switch">
                        <button
                          v-for="item in internshipOverviewTabs"
                          :key="item.key"
                          type="button"
                          :class="{ active: activeOverviewTab === item.key }"
                          @click="internshipState.overviewTab = item.key"
                        >
                          <span>{{ item.name }}</span>
                          <small>{{ item.count }}</small>
                        </button>
                      </div>
                      <div v-if="activeOverviewTab === 'metrics'" class="internship-overview">
                        <section v-for="item in internshipOverviewCards" :key="item.name" class="internship-stat" :class="item.theme">
                          <component :is="item.icon" :size="21" />
                          <strong>{{ item.value }}</strong>
                          <span>{{ item.name }}</span>
                        </section>
                      </div>
                      <StudentOwnPanel
                        v-else-if="isStudentRole"
                        :description="studentPanelMeta(activeOverviewTab).description"
                        :empty-text="studentPanelMeta(activeOverviewTab).emptyText"
                        :fields="studentPanelFields(activeOverviewTab)"
                        :loading="internshipState.loading"
                        :pagination="studentPanelList(activeOverviewTab).pagination"
                        :rows="studentPanelList(activeOverviewTab).items"
                        :status-formatter="statusText"
                        :status-tag-type="statusTagType"
                        :timeline-entity="studentTimelineEntity(activeOverviewTab)"
                        :title="studentPanelMeta(activeOverviewTab).title"
                        @page-change="page => loadInternshipPanel(activeOverviewTab, page)"
                        @refresh="loadInternshipPanel(activeOverviewTab)"
                        @timeline="row => openTimelineDialog(studentTimelineEntity(activeOverviewTab), row)"
                      />
                      <section v-else-if="activeOverviewTab === 'arrangements'" class="internship-card overview-card-single">
                        <header>
                          <strong>近期任务</strong>
                          <small>{{ internshipState.lists.arrangements.pagination.total || 0 }} 条</small>
                        </header>
                        <el-table :data="internshipState.lists.arrangements.items" height="100%" stripe>
                          <el-table-column prop="title" label="任务" min-width="170" />
                          <!-- 暂时隐藏学期列，后续需要时恢复。 -->
                          <!--
                          <el-table-column prop="semester" label="学期" width="130" />
                          -->
                          <el-table-column label="范围" min-width="160">
                            <template #default="{ row }">
                              {{ arrangementScopeText(row) }}
                            </template>
                          </el-table-column>
                          <el-table-column label="状态" width="90">
                            <template #default="{ row }">
                              <el-tag :type="statusTagType(row.status)">{{ statusText(row.status) }}</el-tag>
                            </template>
                          </el-table-column>
                        </el-table>
                      </section>
                      <section v-else class="internship-card overview-card-single">
                        <header>
                          <strong>特殊申请</strong>
                          <small>{{ internshipState.overview.applications_waiting || 0 }} 条</small>
                        </header>
                        <el-table :data="internshipState.lists.applications.items" height="100%" stripe>
                          <el-table-column prop="student_name" label="学生" width="110" />
                          <el-table-column prop="arrangement_title" label="实习任务" min-width="170" />
                          <el-table-column label="状态" width="90">
                            <template #default="{ row }">
                              <el-tag :type="statusTagType(row.status)">{{ statusText(row.status) }}</el-tag>
                            </template>
                          </el-table-column>
                        </el-table>
                      </section>
                    </div>
                  </template>

                  <template v-else-if="isStudentOwnPanel(win.panel)">
                    <StudentOwnPanel
                      :description="studentPanelMeta(win.panel).description"
                      :empty-text="studentPanelMeta(win.panel).emptyText"
                      :fields="studentPanelFields(win.panel)"
                      :loading="internshipState.loading"
                      :pagination="studentPanelList(win.panel).pagination"
                      :rows="studentPanelList(win.panel).items"
                      :status-formatter="statusText"
                      :status-tag-type="statusTagType"
                      :timeline-entity="studentTimelineEntity(win.panel)"
                      :title="studentPanelMeta(win.panel).title"
                      @page-change="page => loadInternshipPanel(win.panel, page)"
                      @refresh="loadInternshipPanel(win.panel)"
                      @timeline="row => openTimelineDialog(studentTimelineEntity(win.panel), row)"
                    />
                  </template>

                  <template v-else-if="win.panel === 'baseFlows'">
                    <DataListPanel
                      :columns="internshipListConfigs.baseFlows.columns"
                      :filters="internshipListConfigs.baseFlows.filters"
                      :filter-values="internshipState.filters.baseFlows"
                      :loading="internshipState.loading"
                      :pagination="internshipState.lists.baseFlows.pagination"
                      :rows="internshipState.lists.baseFlows.items"
                      @filter-change="setInternshipFilter('baseFlows', $event)"
                      @page-change="page => loadInternshipPanel('baseFlows', page)"
                      @reset="resetInternshipFilters('baseFlows')"
                      @search="loadInternshipPanel('baseFlows', 1)"
                    >
                      <template #actions="{ row }">
                        <el-button v-if="canManageInternship && ['draft', 'modify'].includes(row.status)" link type="primary" @click="openBaseFlowDialog(row)">
                          编辑
                        </el-button>
                        <el-button v-if="canReviewRow(row, baseFlowEntity(row))" link type="primary" @click="openReviewDialog(baseFlowEntity(row), row, 'accept')">
                          通过
                        </el-button>
                        <el-button v-if="canReviewRow(row, baseFlowEntity(row))" link type="warning" @click="openReviewDialog(baseFlowEntity(row), row, 'modify')">
                          退回
                        </el-button>
                        <el-button v-if="canRequestModification(row, baseFlowEntity(row))" link type="danger" @click="openReopenDialog(baseFlowEntity(row), row)">
                          通过后修改
                        </el-button>
                        <el-button size="small" type="primary" plain @click="openTimelineDialog(baseFlowEntity(row), row)">
                          记录
                        </el-button>
                      </template>
                    </DataListPanel>
                  </template>

                  <template v-else-if="win.panel === 'arrangements'">
                    <div class="internship-list-only">
                      <DataListPanel
                        :columns="internshipListConfigs.arrangements.columns"
                        :filters="internshipListConfigs.arrangements.filters"
                        :filter-values="internshipState.filters.arrangements"
                        :loading="internshipState.loading"
                        :pagination="internshipState.lists.arrangements.pagination"
                        :rows="internshipState.lists.arrangements.items"
                        @filter-change="setInternshipFilter('arrangements', $event)"
                        @page-change="page => loadInternshipPanel('arrangements', page)"
                        @reset="resetInternshipFilters('arrangements')"
                        @search="loadInternshipPanel('arrangements', 1)"
                      >
                        <template #actions="{ row }">
                          <el-button size="small" type="primary" plain @click="openArrangementDetail(row)">
                            详情
                          </el-button>
                          <el-button size="small" type="success" plain @click="openTimelineDialog('arrangement', row)">
                            记录
                          </el-button>
                          <el-button v-if="canManageInternship" size="small" type="primary" link @click="openArrangementDialog(row)">
                            编辑
                          </el-button>
                        </template>
                      </DataListPanel>
                    </div>
                  </template>

                  <template v-else-if="win.panel === 'arrangementChanges'">
                    <DataListPanel
                      :columns="internshipListConfigs.arrangementChanges.columns"
                      :filters="internshipListConfigs.arrangementChanges.filters"
                      :filter-values="internshipState.filters.arrangementChanges"
                      :loading="internshipState.loading"
                      :pagination="internshipState.lists.arrangementChanges.pagination"
                      :rows="internshipState.lists.arrangementChanges.items"
                      @filter-change="setInternshipFilter('arrangementChanges', $event)"
                      @page-change="page => loadInternshipPanel('arrangementChanges', page)"
                      @reset="resetInternshipFilters('arrangementChanges')"
                      @search="loadInternshipPanel('arrangementChanges', 1)"
                    >
                      <template #actions="{ row }">
                        <el-button size="small" type="primary" plain @click="openArrangementDetail(row.arrangement_id)">
                          原任务
                        </el-button>
                        <el-button v-if="row.new_arrangement_id" size="small" type="success" plain @click="openArrangementDetail(row.new_arrangement_id)">
                          生效任务
                        </el-button>
                        <el-button v-if="canReviewRow(row, 'arrangement_change')" link type="primary" @click="openReviewDialog('arrangement_change', row, 'accept')">
                          通过
                        </el-button>
                        <el-button v-if="canReviewRow(row, 'arrangement_change')" link type="warning" @click="openReviewDialog('arrangement_change', row, 'modify')">
                          退回
                        </el-button>
                        <el-button v-if="canManageInternship && ['draft', 'modify'].includes(row.status)" link type="primary" @click="reopenArrangementChangeDialog(row)">
                          重新提交
                        </el-button>
                        <el-button size="small" type="primary" plain @click="openTimelineDialog('arrangement_change', row)">
                          记录
                        </el-button>
                      </template>
                    </DataListPanel>
                  </template>

                  <template v-else-if="win.panel === 'plans'">
                    <DataListPanel
                      :columns="internshipListConfigs.plans.columns"
                      :filters="internshipListConfigs.plans.filters"
                      :filter-values="internshipState.filters.plans"
                      :loading="internshipState.loading"
                      :pagination="internshipState.lists.plans.pagination"
                      :rows="internshipState.lists.plans.items"
                      @filter-change="setInternshipFilter('plans', $event)"
                      @page-change="page => loadInternshipPanel('plans', page)"
                      @reset="resetInternshipFilters('plans')"
                      @search="loadInternshipPanel('plans', 1)"
                    >
                      <template #actions="{ row }">
                        <el-button v-if="canEditInternshipPlan(row)" link type="primary" @click="openPlanDialog(row)">
                          {{ row.status === 'modify' ? '重新提交' : '编辑' }}
                        </el-button>
                        <el-button v-if="canReviewRow(row, 'plan')" link type="primary" @click="openReviewDialog('plan', row, 'accept')">
                          通过
                        </el-button>
                        <el-button v-if="canReviewRow(row, 'plan')" link type="warning" @click="openReviewDialog('plan', row, 'modify')">
                          退回
                        </el-button>
                        <el-button v-if="canRequestModification(row, 'plan')" link type="danger" @click="openReopenDialog('plan', row)">
                          通过后修改
                        </el-button>
                        <el-button size="small" type="primary" plain @click="openTimelineDialog('plan', row)">
                          记录
                        </el-button>
                      </template>
                    </DataListPanel>
                  </template>

                  <template v-else-if="isInternshipReadOnlyListPanel(win.panel)">
                    <DataListPanel
                      :columns="internshipListConfigs[win.panel].columns"
                      :filters="internshipListConfigs[win.panel].filters"
                      :filter-values="internshipState.filters[win.panel]"
                      :loading="internshipState.loading"
                      :pagination="internshipState.lists[win.panel].pagination"
                      :rows="internshipState.lists[win.panel].items"
                      @filter-change="setInternshipFilter(win.panel, $event)"
                      @page-change="page => loadInternshipPanel(win.panel, page)"
                      @reset="resetInternshipFilters(win.panel)"
                      @search="loadInternshipPanel(win.panel, 1)"
                    >
                      <template #actions="{ row }">
                        <el-button v-if="internshipTimelineEntity(win.panel)" size="small" type="primary" plain @click="openTimelineDialog(internshipTimelineEntity(win.panel), row)">
                          记录
                        </el-button>
                      </template>
                    </DataListPanel>
                  </template>

                  <template v-else-if="win.panel === 'applications'">
                    <DataListPanel
                      :columns="internshipListConfigs.applications.columns"
                      :filters="internshipListConfigs.applications.filters"
                      :filter-values="internshipState.filters.applications"
                      :loading="internshipState.loading"
                      :pagination="internshipState.lists.applications.pagination"
                      :rows="internshipState.lists.applications.items"
                      @filter-change="setInternshipFilter('applications', $event)"
                      @page-change="page => loadInternshipPanel('applications', page)"
                      @reset="resetInternshipFilters('applications')"
                      @search="loadInternshipPanel('applications', 1)"
                    >
                      <template #actions="{ row }">
                        <el-button v-if="canReviewRow(row, 'application')" link type="primary" @click="openReviewDialog('application', row, 'accept')">
                          通过
                        </el-button>
                        <el-button v-if="canReviewRow(row, 'application')" link type="warning" @click="openReviewDialog('application', row, 'modify')">
                          退回
                        </el-button>
                        <el-button v-if="canRequestModification(row, 'application')" link type="danger" @click="openReopenDialog('application', row)">
                          通过后修改
                        </el-button>
                        <el-button size="small" type="primary" plain @click="openTimelineDialog('application', row)">
                          记录
                        </el-button>
                      </template>
                    </DataListPanel>
                  </template>

                  <template v-else-if="win.panel === 'pairs'">
                    <DataListPanel
                      :columns="internshipListConfigs.pairs.columns"
                      :filters="internshipListConfigs.pairs.filters"
                      :filter-values="internshipState.filters.pairs"
                      :loading="internshipState.loading"
                      :pagination="internshipState.lists.pairs.pagination"
                      :rows="internshipState.lists.pairs.items"
                      @filter-change="setInternshipFilter('pairs', $event)"
                      @page-change="page => loadInternshipPanel('pairs', page)"
                      @reset="resetInternshipFilters('pairs')"
                      @search="loadInternshipPanel('pairs', 1)"
                    >
                      <template #actions="{ row }">
                        <el-button v-if="canSaveInternshipScore" link type="primary" @click="prepareScore(row)">录入</el-button>
                      </template>
                    </DataListPanel>
                  </template>

                  <template v-else-if="win.panel === 'signIns'">
                    <DataListPanel
                      :columns="internshipListConfigs.signIns.columns"
                      :filters="internshipListConfigs.signIns.filters"
                      :filter-values="internshipState.filters.signIns"
                      :loading="internshipState.loading"
                      :pagination="internshipState.lists.signIns.pagination"
                      :rows="internshipState.lists.signIns.items"
                      @filter-change="setInternshipFilter('signIns', $event)"
                      @page-change="page => loadInternshipPanel('signIns', page)"
                      @reset="resetInternshipFilters('signIns')"
                      @search="loadInternshipPanel('signIns', 1)"
                    >
                      <template #actions="{ row }">
                        <el-button size="small" type="primary" plain @click="openTimelineDialog('sign_in', row)">
                          记录
                        </el-button>
                      </template>
                    </DataListPanel>
                  </template>

                  <template v-else-if="win.panel === 'journals'">
                    <DataListPanel
                      :columns="internshipListConfigs.journals.columns"
                      :filters="internshipListConfigs.journals.filters"
                      :filter-values="internshipState.filters.journals"
                      :loading="internshipState.loading"
                      :pagination="internshipState.lists.journals.pagination"
                      :rows="internshipState.lists.journals.items"
                      @filter-change="setInternshipFilter('journals', $event)"
                      @page-change="page => loadInternshipPanel('journals', page)"
                      @reset="resetInternshipFilters('journals')"
                      @search="loadInternshipPanel('journals', 1)"
                    >
                      <template #actions="{ row }">
                        <el-button v-if="canReviewRow(row, 'journal')" link type="primary" @click="openReviewDialog('journal', row, 'accept')">
                          通过
                        </el-button>
                        <el-button v-if="canReviewRow(row, 'journal')" link type="warning" @click="openReviewDialog('journal', row, 'modify')">
                          退回
                        </el-button>
                        <el-button v-if="canRequestModification(row, 'journal')" link type="danger" @click="openReopenDialog('journal', row)">
                          通过后修改
                        </el-button>
                        <el-button size="small" type="primary" plain @click="openTimelineDialog('journal', row)">
                          记录
                        </el-button>
                      </template>
                    </DataListPanel>
                  </template>

                  <template v-else-if="win.panel === 'reports'">
                    <DataListPanel
                      :columns="internshipListConfigs.reports.columns"
                      :filters="internshipListConfigs.reports.filters"
                      :filter-values="internshipState.filters.reports"
                      :loading="internshipState.loading"
                      :pagination="internshipState.lists.reports.pagination"
                      :rows="internshipState.lists.reports.items"
                      @filter-change="setInternshipFilter('reports', $event)"
                      @page-change="page => loadInternshipPanel('reports', page)"
                      @reset="resetInternshipFilters('reports')"
                      @search="loadInternshipPanel('reports', 1)"
                    >
                      <template #actions="{ row }">
                        <el-button v-if="canReviewRow(row, 'report')" link type="primary" @click="openReviewDialog('report', row, 'accept')">
                          通过
                        </el-button>
                        <el-button v-if="canReviewRow(row, 'report')" link type="warning" @click="openReviewDialog('report', row, 'modify')">
                          退回
                        </el-button>
                        <el-button v-if="canRequestModification(row, 'report')" link type="danger" @click="openReopenDialog('report', row)">
                          通过后修改
                        </el-button>
                        <el-button size="small" type="primary" plain @click="openTimelineDialog('report', row)">
                          记录
                        </el-button>
                      </template>
                    </DataListPanel>
                  </template>

                  <template v-else-if="win.panel === 'delays'">
                    <DataListPanel
                      :columns="internshipListConfigs.delays.columns"
                      :filters="internshipListConfigs.delays.filters"
                      :filter-values="internshipState.filters.delays"
                      :loading="internshipState.loading"
                      :pagination="internshipState.lists.delays.pagination"
                      :rows="internshipState.lists.delays.items"
                      @filter-change="setInternshipFilter('delays', $event)"
                      @page-change="page => loadInternshipPanel('delays', page)"
                      @reset="resetInternshipFilters('delays')"
                      @search="loadInternshipPanel('delays', 1)"
                    >
                      <template #actions="{ row }">
                        <el-button v-if="canReviewRow(row, 'delay')" link type="primary" @click="openReviewDialog('delay', row, 'accept')">
                          通过
                        </el-button>
                        <el-button v-if="canReviewRow(row, 'delay')" link type="warning" @click="openReviewDialog('delay', row, 'refuse')">
                          退回
                        </el-button>
                        <el-button v-if="canRequestModification(row, 'delay')" link type="danger" @click="openReopenDialog('delay', row)">
                          通过后修改
                        </el-button>
                        <el-button size="small" type="primary" plain @click="openTimelineDialog('delay', row)">
                          记录
                        </el-button>
                      </template>
                    </DataListPanel>
                  </template>

                  <template v-else-if="win.panel === 'scores'">
                    <div class="internship-score-workspace">
                      <DataListPanel
                        :columns="internshipListConfigs.scores.columns"
                        :filters="internshipListConfigs.scores.filters"
                        :filter-values="internshipState.filters.scores"
                        :loading="internshipState.loading"
                        :pagination="internshipState.lists.scores.pagination"
                        :rows="internshipState.lists.scores.items"
                        @filter-change="setInternshipFilter('scores', $event)"
                        @page-change="page => loadInternshipPanel('scores', page)"
                        @reset="resetInternshipFilters('scores')"
                        @search="loadInternshipPanel('scores', 1)"
                      >
                        <template #actions="{ row }">
                          <el-button v-if="canSaveInternshipScore" link type="primary" @click="openScoreDialog(row)">
                            {{ row.id ? '修改' : '录入' }}
                          </el-button>
                          <el-button v-if="row.id" size="small" type="primary" plain @click="openTimelineDialog('score', row)">
                            记录
                          </el-button>
                        </template>
                      </DataListPanel>
                      <section class="course-score-panel">
                        <header>
                          <strong>课程成绩汇总</strong>
                          <small>按实习计划的成绩规则汇总同一学生多个任务成绩</small>
                        </header>
                        <DataListPanel
                          :columns="internshipListConfigs.courseScores.columns"
                          :filters="internshipListConfigs.courseScores.filters"
                          :filter-values="internshipState.filters.courseScores"
                          :loading="internshipState.loading"
                          :pagination="internshipState.lists.courseScores.pagination"
                          :rows="internshipState.lists.courseScores.items"
                          @filter-change="setInternshipFilter('courseScores', $event)"
                          @page-change="page => loadInternshipPanel('courseScores', page)"
                          @reset="resetInternshipFilters('courseScores')"
                          @search="loadInternshipPanel('courseScores', 1)"
                        >
                          <template #actions="{ row }">
                            <el-button v-if="canSaveManualCourseScore(row)" link type="primary" @click="openCourseScoreDialog(row)">
                              核定
                            </el-button>
                          </template>
                        </DataListPanel>
                      </section>
                    </div>
                  </template>

                  <template v-else-if="win.panel === 'courseScores'">
                    <DataListPanel
                      :columns="internshipListConfigs.courseScores.columns"
                      :filters="internshipListConfigs.courseScores.filters"
                      :filter-values="internshipState.filters.courseScores"
                      :loading="internshipState.loading"
                      :pagination="internshipState.lists.courseScores.pagination"
                      :rows="internshipState.lists.courseScores.items"
                      @filter-change="setInternshipFilter('courseScores', $event)"
                      @page-change="page => loadInternshipPanel('courseScores', page)"
                      @reset="resetInternshipFilters('courseScores')"
                      @search="loadInternshipPanel('courseScores', 1)"
                    >
                      <template #actions="{ row }">
                        <el-button v-if="canSaveManualCourseScore(row)" link type="primary" @click="openCourseScoreDialog(row)">
                          核定
                        </el-button>
                      </template>
                    </DataListPanel>
                  </template>

                  <template v-else-if="win.panel === 'documents'">
                    <StudentOwnPanel
                      v-if="isStudentRole"
                      :description="studentPanelMeta('archiveMaterials').description"
                      :empty-text="studentPanelMeta('archiveMaterials').emptyText"
                      :fields="studentPanelFields('archiveMaterials')"
                      :loading="internshipState.loading"
                      :pagination="studentPanelList('archiveMaterials').pagination"
                      :rows="studentPanelList('archiveMaterials').items"
                      :status-formatter="statusText"
                      :status-tag-type="statusTagType"
                      :title="studentPanelMeta('archiveMaterials').title"
                      @page-change="page => loadInternshipPanel('documents', page)"
                      @refresh="loadInternshipPanel('documents')"
                    />
                    <DataListPanel
                      v-else
                      :columns="internshipListConfigs.archiveMaterials.columns"
                      :filters="internshipListConfigs.archiveMaterials.filters"
                      :filter-values="internshipState.filters.archiveMaterials"
                      :loading="internshipState.loading"
                      :pagination="internshipState.lists.archiveMaterials.pagination"
                      :rows="internshipState.lists.archiveMaterials.items"
                      @filter-change="setInternshipFilter('archiveMaterials', $event)"
                      @page-change="page => loadInternshipPanel('documents', page)"
                      @reset="resetInternshipFilters('archiveMaterials')"
                      @search="loadInternshipPanel('documents', 1)"
                    />
                  </template>
                </div>

                <div v-else-if="isPracticeModule(win.module.id)" class="internship-panel practice-panel">
                  <div v-if="canManagePractice(win.module.id) || showPracticeAddButton(win.module.id, win.panel)" class="internship-toolbar action-only">
                    <div class="data-list-actions">
                      <el-button
                        v-if="showPracticeAddButton(win.module.id, win.panel)"
                        :icon="Plus"
                        @click="openPracticeDialog(win.module.id, win.panel)"
                      >
                        新增{{ practicePanelLabel(win.panel) }}
                      </el-button>
                      <el-button :icon="RefreshCw" :loading="practiceModuleState(win.module.id).loading" @click="loadPracticePanel(win.module.id, win.panel)">
                        刷新
                      </el-button>
                    </div>
                  </div>

                  <el-alert
                    v-if="practiceModuleState(win.module.id).message"
                    type="warning"
                    :closable="false"
                    show-icon
                    :title="practiceModuleState(win.module.id).message"
                  />

                  <div v-if="practiceModuleState(win.module.id).dialog.type" class="operation-mask" @click.self="closePracticeDialog(win.module.id)">
                    <section class="operation-dialog">
                      <header>
                        <strong>{{ practiceModuleState(win.module.id).dialog.title }}</strong>
                        <button type="button" @click="closePracticeDialog(win.module.id)">关闭</button>
                      </header>

                      <div v-if="practiceModuleState(win.module.id).dialog.type === 'execution'" class="operation-form">
                        <label>
                          <span>项目</span>
                          <el-select v-model="practiceModuleState(win.module.id).form.project_id" clearable filterable>
                            <el-option v-for="project in practiceModuleState(win.module.id).options.projects" :key="project.id" :label="project.title || project.course_name" :value="project.id" />
                          </el-select>
                        </label>
                        <label v-if="!isStudentRole">
                          <span>学生</span>
                          <el-select v-model="practiceModuleState(win.module.id).form.student_id" clearable filterable>
                            <el-option v-for="student in practiceModuleState(win.module.id).options.students" :key="student.student_id" :label="`${student.name} / ${student.student_num}`" :value="student.student_id" />
                          </el-select>
                        </label>
                        <label v-if="win.panel === 'signIns'"><span>日期</span><input v-model="practiceModuleState(win.module.id).form.date" type="date"></label>
                        <label v-if="win.panel === 'signIns'"><span>位置</span><input v-model="practiceModuleState(win.module.id).form.location"></label>
                        <label v-if="win.panel === 'signIns'"><span>经度</span><input v-model="practiceModuleState(win.module.id).form.longitude" type="number"></label>
                        <label v-if="win.panel === 'signIns'"><span>纬度</span><input v-model="practiceModuleState(win.module.id).form.latitude" type="number"></label>
                        <label v-if="win.panel !== 'signIns'"><span>标题</span><input v-model="practiceModuleState(win.module.id).form.title"></label>
                        <label v-if="win.panel !== 'signIns'"><span>日期</span><input v-model="practiceModuleState(win.module.id).form.date" type="date"></label>
                        <label v-if="win.panel !== 'signIns'" class="span-2">
                          <span>内容</span>
                          <textarea v-model="practiceModuleState(win.module.id).form.content" rows="7" />
                        </label>
                        <label class="span-2">
                          <span>备注</span>
                          <textarea v-model="practiceModuleState(win.module.id).form.remark" rows="3" />
                        </label>
                      </div>

                      <div v-else-if="practiceModuleState(win.module.id).dialog.type === 'scoreProject'" class="operation-form">
                        <label>
                          <span>项目</span>
                          <el-select v-model="practiceModuleState(win.module.id).form.project_id" clearable filterable>
                            <el-option v-for="project in practiceModuleState(win.module.id).options.projects" :key="project.id" :label="project.title || project.course_name" :value="project.id" />
                          </el-select>
                        </label>
                        <label>
                          <span>学生</span>
                          <el-select v-model="practiceModuleState(win.module.id).form.student_id" clearable filterable>
                            <el-option v-for="student in practiceModuleState(win.module.id).options.students" :key="student.student_id" :label="`${student.name} / ${student.student_num}`" :value="student.student_id" />
                          </el-select>
                        </label>
                        <label><span>考勤成绩</span><input v-model="practiceModuleState(win.module.id).form.attendance_score" type="number"></label>
                        <label><span>过程成绩</span><input v-model="practiceModuleState(win.module.id).form.material_score" type="number"></label>
                        <label><span>报告成绩</span><input v-model="practiceModuleState(win.module.id).form.report_score" type="number"></label>
                        <label><span>总评成绩</span><input v-model="practiceModuleState(win.module.id).form.score_value" type="number" placeholder="不填则按分项平均"></label>
                      </div>

                      <div v-else-if="practiceModuleState(win.module.id).dialog.type === 'edit'" class="operation-form">
                        <label>
                          <span>标题</span>
                          <input v-model="practiceModuleState(win.module.id).form.title">
                        </label>
                        <label>
                          <span>届次</span>
                          <el-select v-model="practiceModuleState(win.module.id).form.grade_id" clearable filterable @change="normalizePracticeCascade(win.module.id)">
                            <el-option v-for="grade in practiceModuleState(win.module.id).options.grades" :key="grade.grade_id" :label="grade.grade_name" :value="grade.grade_id" />
                          </el-select>
                        </label>
                        <label>
                          <span>学院</span>
                          <el-select v-model="practiceModuleState(win.module.id).form.dep_id" clearable filterable @change="normalizePracticeCascade(win.module.id)">
                            <el-option v-for="dep in practiceModuleState(win.module.id).options.departments" :key="dep.dep_id" :label="dep.dep_name" :value="dep.dep_id" />
                          </el-select>
                        </label>
                        <label>
                          <span>专业</span>
                          <el-select v-model="practiceModuleState(win.module.id).form.profession_id" clearable filterable @change="handlePracticeProfessionChange(win.module.id)">
                            <el-option v-for="profession in practiceProfessionOptions(win.module.id)" :key="profession.profession_id" :label="profession.profession_name" :value="profession.profession_id" />
                          </el-select>
                        </label>
                        <label v-if="win.panel === 'schedules'">
                          <span>班级</span>
                          <el-select v-model="practiceModuleState(win.module.id).form.class_id" clearable filterable>
                            <el-option v-for="clazz in practiceClassOptions(win.module.id)" :key="clazz.class_id" :label="clazz.class_name" :value="clazz.class_id" />
                          </el-select>
                        </label>
                        <label>
                          <span>任课教师</span>
                          <el-select v-model="practiceModuleState(win.module.id).form.teacher_id" clearable filterable>
                            <el-option v-for="teacher in practiceModuleState(win.module.id).options.teachers" :key="teacher.teacher_id" :label="teacher.teacher_name" :value="teacher.teacher_id" />
                          </el-select>
                        </label>
                        <label v-if="practiceNeedsPlan(win.panel)">
                          <span>关联计划</span>
                          <el-select v-model="practiceModuleState(win.module.id).form.plan_id" clearable filterable @change="handlePracticePlanChange(win.module.id)">
                            <el-option v-for="plan in practicePlanOptions(win.module.id, win.panel)" :key="plan.id" :label="plan.title || plan.course_name" :value="plan.id" />
                          </el-select>
                        </label>
                        <label v-if="win.panel === 'projects'">
                          <span>关联课表</span>
                          <el-select v-model="practiceModuleState(win.module.id).form.schedule_id" clearable filterable @change="handlePracticeScheduleChange(win.module.id)">
                            <el-option v-for="schedule in practiceScheduleOptions(win.module.id)" :key="schedule.id" :label="practiceScheduleLabel(schedule)" :value="schedule.id" />
                          </el-select>
                        </label>
                        <label v-if="win.panel === 'plans'">
                          <span>来源</span>
                          <el-select v-model="practiceModuleState(win.module.id).form.source_type">
                            <el-option label="教务拉取" value="jw" />
                            <el-option label="手动填报" value="manual" />
                          </el-select>
                        </label>
                        <label v-if="win.panel === 'schedules'">
                          <span>地点类型</span>
                          <el-select v-model="practiceModuleState(win.module.id).form.place_type" @change="handlePracticePlaceTypeChange(win.module.id)">
                            <el-option label="校内" value="inside" />
                            <el-option label="校外" value="outside" />
                          </el-select>
                        </label>
                        <label v-if="win.panel === 'schedules' && practiceModuleState(win.module.id).form.place_type === 'inside'">
                          <span>场地</span>
                          <el-select v-model="practiceModuleState(win.module.id).form.room_id" clearable filterable>
                            <el-option v-for="room in practiceModuleState(win.module.id).options.rooms" :key="room.id" :label="room.name" :value="room.id" />
                          </el-select>
                        </label>
                        <label v-if="win.panel === 'schedules' && practiceModuleState(win.module.id).form.place_type === 'outside'">
                          <span>校外基地</span>
                          <el-select v-model="practiceModuleState(win.module.id).form.base_id" clearable filterable>
                            <el-option v-for="base in practiceBaseOptions(win.module.id)" :key="base.id" :label="base.name" :value="base.id" />
                          </el-select>
                        </label>
                        <label v-if="win.panel === 'schedules'"><span>日期</span><input v-model="practiceModuleState(win.module.id).form.schedule_date" type="date"></label>
                        <label v-if="win.panel === 'schedules'"><span>开始时间</span><input v-model="practiceModuleState(win.module.id).form.start_time" type="time"></label>
                        <label v-if="win.panel === 'schedules'"><span>结束时间</span><input v-model="practiceModuleState(win.module.id).form.end_time" type="time"></label>
                        <label v-if="win.panel === 'schedules'"><span>地点</span><input v-model="practiceModuleState(win.module.id).form.location"></label>
                        <label v-if="win.panel === 'schedules'"><span>学生数</span><input v-model="practiceModuleState(win.module.id).form.student_count" type="number" disabled placeholder="保存时按班级自动计算"></label>
                        <label v-if="win.panel === 'projects'"><span>开始日期</span><input v-model="practiceModuleState(win.module.id).form.start_date" type="date"></label>
                        <label v-if="win.panel === 'projects'"><span>结束日期</span><input v-model="practiceModuleState(win.module.id).form.end_date" type="date"></label>
                        <label v-if="win.panel === 'projects'"><span>学生范围</span><input v-model="practiceModuleState(win.module.id).form.student_count" type="number" disabled placeholder="保存时按课表班级自动生成"></label>
                        <label v-if="win.panel === 'rooms'"><span>名称</span><input v-model="practiceModuleState(win.module.id).form.name"></label>
                        <label v-if="win.panel === 'rooms'"><span>编号</span><input v-model="practiceModuleState(win.module.id).form.code"></label>
                        <label v-if="win.panel === 'rooms'"><span>容量</span><input v-model="practiceModuleState(win.module.id).form.capacity" type="number"></label>
                        <label v-if="win.panel === 'rooms'"><span>位置</span><input v-model="practiceModuleState(win.module.id).form.location"></label>
                        <label v-if="win.panel === 'scores'">
                          <span>学生</span>
                          <el-select v-model="practiceModuleState(win.module.id).form.student_id" clearable filterable>
                            <el-option v-for="student in practiceModuleState(win.module.id).options.students" :key="student.student_id" :label="`${student.name} / ${student.student_num}`" :value="student.student_id" />
                          </el-select>
                        </label>
                        <label v-if="win.panel === 'scores'"><span>成绩</span><input v-model="practiceModuleState(win.module.id).form.score_value" type="number"></label>
                        <label v-if="win.panel === 'gradeRules'" class="span-2">
                          <span>比例配置</span>
                          <textarea v-model="practiceModuleState(win.module.id).form.ratio_text" rows="4" placeholder="平时成绩40%，报告60%" />
                        </label>
                        <label v-if="practiceTextPanel(win.panel)" class="span-2">
                          <span>内容</span>
                          <textarea v-model="practiceModuleState(win.module.id).form.content" rows="8" />
                        </label>
                        <label v-if="!practiceDialogUsesWorkflowButtons(win.module.id) && practiceStatusUsesSwitch(win.panel)">
                          <span>状态</span>
                          <el-switch
                            v-model="practiceModuleState(win.module.id).form.status"
                            active-value="enabled"
                            inactive-value="disabled"
                            active-text="启用"
                            inactive-text="停用"
                          />
                        </label>
                      </div>

                      <div v-else-if="['review', 'reopen'].includes(practiceModuleState(win.module.id).dialog.type)" class="operation-form single">
                        <p>{{ practiceModuleState(win.module.id).dialog.description }}</p>
                        <label v-if="practiceModuleState(win.module.id).dialog.type === 'review'">
                          <span>审核结论</span>
                          <el-segmented v-model="practiceModuleState(win.module.id).dialog.status" :options="practiceReviewStatusOptions()" />
                        </label>
                        <label>
                          <span>{{ practiceModuleState(win.module.id).dialog.type === 'reopen' ? '修改理由' : (practiceModuleState(win.module.id).dialog.status === 'modify' ? '退回原因' : '审核意见') }}</span>
                          <textarea
                            v-model="practiceModuleState(win.module.id).dialog.reason"
                            rows="5"
                            :maxlength="practiceReviewRuleMax(win.module.id, practiceModuleState(win.module.id).dialog.entity, practiceModuleState(win.module.id).dialog.status)"
                            @input="event => trimPracticeReviewReasonMax(win.module.id, event)"
                          />
                          <small class="review-counter">
                            <span>{{ practiceReviewRuleText(win.module.id, practiceModuleState(win.module.id).dialog.entity, practiceModuleState(win.module.id).dialog.status) }}</span>
                            <span>{{ textLength(practiceModuleState(win.module.id).dialog.reason) }}/{{ practiceReviewRuleMaxText(win.module.id, practiceModuleState(win.module.id).dialog.entity, practiceModuleState(win.module.id).dialog.status) }}</span>
                          </small>
                        </label>
                      </div>

                      <div v-else-if="practiceModuleState(win.module.id).dialog.type === 'timeline'" class="operation-form single">
                        <div class="timeline-view">
                          <section
                            v-for="(cycle, index) in normalizeTimelineCycles(practiceModuleState(win.module.id).dialog.cycles || [], [])"
                            :key="timelineCycleKey(cycle, index)"
                            class="timeline-cycle"
                          >
                            <span />
                            <div>
                              <header class="timeline-node-head">
                                <strong>{{ timelineCycleTitle(cycle) }}</strong>
                                <small>{{ timelineCycleTime(cycle) }}</small>
                              </header>
                              <p>{{ timelineCycleContent(cycle) }}</p>
                              <div v-if="cycle.branches?.length" class="timeline-branches">
                                <article
                                  v-for="(branch, branchIndex) in cycle.branches"
                                  :key="timelineBranchKey(branch, branchIndex)"
                                  class="timeline-branch"
                                  :class="{ reopen: isModifyAfterAcceptBranch(branch) }"
                                >
                                  <span />
                                  <div>
                                    <header class="timeline-node-head">
                                      <strong>{{ timelineBranchTitle(branch) }}</strong>
                                      <small>{{ timelineBranchTime(branch) }}</small>
                                    </header>
                                    <p v-if="timelineBranchContent(branch)">{{ timelineBranchContent(branch) }}</p>
                                    <p v-for="review in timelineBranchReviews(branch)" :key="review.id" class="timeline-review">
                                      <span>状态：{{ statusText(review.status) }}</span>
                                      <span>审核意见：{{ review.opinion || '-' }}</span>
                                    </p>
                                  </div>
                                </article>
                              </div>
                            </div>
                          </section>
                          <small v-if="!practiceModuleState(win.module.id).dialog.cycles?.length">暂无流程记录</small>
                        </div>
                      </div>

                      <footer>
                        <el-button @click="closePracticeDialog(win.module.id)">取消</el-button>
                        <template v-if="practiceDialogUsesWorkflowButtons(win.module.id)">
                          <el-button :disabled="practiceModuleState(win.module.id).loading" :loading="practiceModuleState(win.module.id).loading" @click="submitPracticeWorkflowDialog(win.module.id, 'draft')">保存草稿</el-button>
                          <el-button type="primary" :disabled="practiceModuleState(win.module.id).loading" :loading="practiceModuleState(win.module.id).loading" @click="submitPracticeWorkflowDialog(win.module.id, 'wait')">提交审核</el-button>
                        </template>
                        <template v-else-if="practiceModuleState(win.module.id).dialog.type === 'review'">
                          <el-button :disabled="practiceModuleState(win.module.id).loading" :loading="practiceModuleState(win.module.id).loading" @click="savePracticeDialogReviewDraft(win.module.id)">保存草稿</el-button>
                          <el-button type="primary" :disabled="practiceModuleState(win.module.id).loading" :loading="practiceModuleState(win.module.id).loading" @click="confirmPracticeDialog(win.module.id)">提交审核</el-button>
                        </template>
                        <el-button v-else-if="practiceModuleState(win.module.id).dialog.type !== 'timeline'" type="primary" :disabled="practiceModuleState(win.module.id).loading" :loading="practiceModuleState(win.module.id).loading" @click="confirmPracticeDialog(win.module.id)">
                          确认
                        </el-button>
                      </footer>
                    </section>
                  </div>

                  <div v-if="win.panel === 'overview'" class="practice-overview-work">
                    <div class="internship-overview">
                      <button
                        v-for="item in practiceOverviewCards(win.module.id)"
                        :key="item.name"
                        type="button"
                        class="internship-stat practice-stat-button"
                        :class="item.theme"
                        @click="activateWindowPanel(win, item.panel)"
                      >
                        <component :is="item.icon" :size="21" />
                        <strong>{{ item.value }}</strong>
                        <span>{{ item.name }}</span>
                      </button>
                    </div>
                    <section class="practice-flow-card">
                      <header>
                        <strong>{{ win.module.name }}流程</strong>
                        <small>按教学计划、课表、大纲、教案、成绩、反思闭环处理。</small>
                      </header>
                      <div>
                        <button
                          v-for="item in practiceSidebarItems().filter(panel => panel.key !== 'overview')"
                          :key="item.key"
                          type="button"
                          @click="activateWindowPanel(win, item.key)"
                        >
                          {{ item.name }}
                        </button>
                      </div>
                    </section>
                  </div>
                  <DataListPanel
                    v-else
                    :columns="practiceListConfig(win.module.id, win.panel).columns"
                    :filters="practiceListConfig(win.module.id, win.panel).filters"
                    :filter-values="practiceModuleState(win.module.id).filters[win.panel]"
                    :loading="practiceModuleState(win.module.id).loading"
                    :pagination="practiceModuleState(win.module.id).lists[win.panel].pagination"
                    :rows="practiceModuleState(win.module.id).lists[win.panel].items"
                    @filter-change="event => setPracticeFilter(win.module.id, win.panel, event)"
                    @page-change="page => loadPracticePanel(win.module.id, win.panel, page)"
                    @reset="resetPracticeFilters(win.module.id, win.panel)"
                    @search="loadPracticePanel(win.module.id, win.panel, 1)"
                  >
                    <template #actions="{ row }">
                      <el-button v-if="showPracticeRowEdit(win.module.id, win.panel, row)" link type="primary" @click="openPracticeDialog(win.module.id, win.panel, row)">
                        编辑
                      </el-button>
                      <el-button v-if="canReviewPracticeRow(win.module.id, win.panel, row)" link type="primary" @click="openPracticeReviewDialog(win.module.id, win.panel, row, 'accept')">
                        通过
                      </el-button>
                      <el-button v-if="canReviewPracticeRow(win.module.id, win.panel, row)" link type="warning" @click="openPracticeReviewDialog(win.module.id, win.panel, row, 'modify')">
                        退回
                      </el-button>
                      <el-button v-if="canReopenPracticeRow(win.module.id, win.panel, row)" link type="danger" @click="openPracticeReopenDialog(win.module.id, win.panel, row)">
                        通过后修改
                      </el-button>
                      <el-button v-if="practiceReviewEntity(win.panel)" size="small" type="primary" plain @click="openPracticeTimelineDialog(win.module.id, win.panel, row)">
                        记录
                      </el-button>
                    </template>
                  </DataListPanel>
                </div>

                <div v-else-if="isUserManageWindow(win)" class="admin-panel user-admin-panel">
                  <div class="admin-toolbar">
                    <el-button v-if="canManageConfig" type="primary" :icon="Plus" @click="openUserDialog()">
                      新增用户
                    </el-button>
                    <el-button :icon="RefreshCw" :loading="userAdminState.loading" @click="loadUserAccounts(userAdminState.pagination.page || 1)">
                      刷新
                    </el-button>
                  </div>
                  <DataListPanel
                    :columns="userListColumns"
                    :filters="userListFilters"
                    :filter-values="userAdminState.filters"
                    :loading="userAdminState.loading"
                    :pagination="userAdminState.pagination"
                    :rows="userAdminState.items"
                    @filter-change="setUserFilter"
                    @page-change="page => loadUserAccounts(page)"
                    @reset="resetUserFilters"
                    @search="loadUserAccounts(1)"
                  >
                    <template #actions="{ row }">
                      <el-button v-if="canManageConfig" link type="primary" :disabled="!canMaintainUser(row)" @click="openUserDialog(row)">
                        编辑
                      </el-button>
                      <el-button
                        v-if="canManageConfig"
                        link
                        :type="row.status === 'enabled' ? 'warning' : 'success'"
                        :disabled="Number(row.id) === Number(permissionState.context.account_id) || !canMaintainUser(row)"
                        @click="toggleUserStatus(row)"
                      >
                        {{ row.status === 'enabled' ? '停用' : '启用' }}
                      </el-button>
                      <el-button v-if="canManageConfig" link type="warning" :disabled="!canMaintainUser(row)" @click="openResetPasswordDialog(row)">
                        重置密码
                      </el-button>
                      <el-button
                        link
                        type="success"
                        :loading="userAdminState.passkeyLoadingId === row.id"
                        :disabled="!canGenerateLoginPasskey(row)"
                        @click="copyAdminLoginUrl(row)"
                      >
                        一键登录
                      </el-button>
                      <el-button link type="primary" @click="openUserDetailDialog(row, 'logs')">
                        日志
                      </el-button>
                      <el-button link type="primary" @click="openUserDetailDialog(row, 'bindings')">
                        绑定
                      </el-button>
                    </template>
                  </DataListPanel>
                  <div v-if="userAdminState.dialogVisible" class="operation-mask" @click.self="closeUserDialog">
                    <section class="operation-dialog menu-dialog">
                      <header>
                        <strong>{{ userAdminState.editing.id ? '编辑用户' : '新增用户' }}</strong>
                        <button type="button" @click="closeUserDialog">关闭</button>
                      </header>
                      <div class="operation-form menu-dialog-form">
                        <label><span>姓名</span><input v-model="userAdminState.editing.name"></label>
                        <label><span>登录账号</span><input v-model="userAdminState.editing.login_name"></label>
                        <label>
                          <span>角色</span>
                          <el-select v-model="userAdminState.editing.role_id" filterable @change="handleUserRoleChange">
                            <el-option
                              v-for="role in manageableUserRoles()"
                              :key="role.id"
                              :label="role.name"
                              :value="role.id"
                            />
                          </el-select>
                        </label>
                        <label><span>手机</span><input v-model="userAdminState.editing.mobile"></label>
                        <label><span>邮箱</span><input v-model="userAdminState.editing.email"></label>
                        <label v-if="userEditingRoleType === 'student'"><span>学号</span><input v-model="userAdminState.editing.student_num"></label>
                        <label v-if="userEditingRoleType === 'teacher'"><span>工号</span><input v-model="userAdminState.editing.teacher_num"></label>
                        <label v-if="userEditingRoleType === 'student'">
                          <span>届次</span>
                          <el-select v-model="userAdminState.editing.grade_id" clearable filterable @change="handleUserAcademicChange('grade_id')">
                            <el-option
                              v-for="grade in adminState.options.grades"
                              :key="grade.grade_id"
                              :label="grade.grade_name"
                              :value="grade.grade_id"
                            />
                          </el-select>
                        </label>
                        <label v-if="['student', 'teacher'].includes(userEditingRoleType)">
                          <span>所属学院</span>
                          <el-select v-model="userAdminState.editing.dep_id" clearable filterable @change="handleUserAcademicChange('dep_id')">
                            <el-option
                              v-for="department in userEditDepartmentOptions()"
                              :key="department.dep_id"
                              :label="department.dep_name"
                              :value="department.dep_id"
                            />
                          </el-select>
                        </label>
                        <label v-if="['student', 'teacher'].includes(userEditingRoleType)">
                          <span>所属专业</span>
                          <el-select v-model="userAdminState.editing.profession_id" clearable filterable @change="handleUserAcademicChange('profession_id')">
                            <el-option
                              v-for="profession in userEditProfessionOptions()"
                              :key="profession.profession_id"
                              :label="profession.profession_name"
                              :value="profession.profession_id"
                            />
                          </el-select>
                        </label>
                        <label v-if="userEditingRoleType === 'student'">
                          <span>所在班级</span>
                          <el-select v-model="userAdminState.editing.class_id" clearable filterable @change="handleUserAcademicChange('class_id')">
                            <el-option
                              v-for="classItem in userEditClassOptions()"
                              :key="classItem.class_id"
                              :label="classItem.class_name"
                              :value="classItem.class_id"
                            />
                          </el-select>
                        </label>
                        <label v-if="userEditingRoleType === 'student'"><span>班号</span><input v-model="userAdminState.editing.class_num"></label>
                        <label>
                          <span>状态</span>
                          <el-switch
                            v-model="userAdminState.editing.status"
                            active-value="enabled"
                            inactive-value="disabled"
                            active-text="启用"
                            inactive-text="停用"
                          />
                        </label>
                        <label>
                          <span>{{ userAdminState.editing.id ? '新密码' : '初始密码' }}</span>
                          <input v-model="userAdminState.editing.password" type="password" :placeholder="userAdminState.editing.id ? '留空表示不修改' : '留空使用系统初始密码'">
                        </label>
                      </div>
                      <footer>
                        <el-button @click="closeUserDialog">取消</el-button>
                        <el-button type="primary" :icon="Save" :loading="userAdminState.loading" @click="saveUserConfig">
                          保存
                        </el-button>
                      </footer>
                    </section>
                  </div>
                  <div v-if="userAdminState.passwordDialogVisible" class="operation-mask" @click.self="closeResetPasswordDialog">
                    <section class="operation-dialog menu-dialog">
                      <header>
                        <strong>重置密码</strong>
                        <button type="button" @click="closeResetPasswordDialog">关闭</button>
                      </header>
                      <div class="operation-form menu-dialog-form">
                        <label><span>用户</span><input :value="userAdminState.passwordForm.name" disabled></label>
                        <label><span>新密码</span><input v-model="userAdminState.passwordForm.password" type="password" placeholder="请输入新密码" maxlength="120"></label>
                      </div>
                      <footer>
                        <el-button @click="closeResetPasswordDialog">取消</el-button>
                        <el-button type="primary" :icon="Save" :loading="userAdminState.loading" @click="resetUserPassword">
                          保存
                        </el-button>
                      </footer>
                    </section>
                  </div>
                  <div v-if="userAdminState.detailDialogVisible" class="operation-mask" @click.self="closeUserDetailDialog">
                    <section class="operation-dialog user-detail-dialog">
                      <header>
                        <strong>{{ userDetailTitle }}</strong>
                        <button type="button" @click="closeUserDetailDialog">关闭</button>
                      </header>
                      <div class="user-detail-body">
                        <section class="user-detail-summary">
                          <strong>{{ userAdminState.detail.account?.name || '-' }}</strong>
                          <span>{{ userAdminState.detail.account?.login_name || '-' }} / {{ userAdminState.detail.account?.role_name || '-' }}</span>
                        </section>
                        <template v-if="userAdminState.detailMode === 'logs'">
                          <el-table
                            :data="userAdminState.detail.logs"
                            height="340"
                            stripe
                            v-loading="userAdminState.detailLoading"
                          >
                            <el-table-column prop="id" label="ID" width="76" />
                            <el-table-column prop="action" label="操作" min-width="180" />
                            <el-table-column prop="ip" label="IP" width="130" />
                            <el-table-column label="内容" min-width="240">
                              <template #default="{ row }">
                                {{ logPayloadText(row.payload) }}
                              </template>
                            </el-table-column>
                            <el-table-column prop="created_at" label="时间" width="168" />
                          </el-table>
                          <div class="user-detail-pagination">
                            <span>共 {{ userAdminState.detailPagination.total || 0 }} 条日志</span>
                            <el-pagination
                              size="small"
                              layout="prev, pager, next"
                              :current-page="userAdminState.detailPagination.page || 1"
                              :page-size="userAdminState.detailPagination.page_size || 20"
                              :total="userAdminState.detailPagination.total || 0"
                              @current-change="loadUserDetailPage"
                            />
                          </div>
                        </template>
                        <template v-else>
                          <section class="detail-table-block">
                            <header>
                              <strong>其他登录账号</strong>
                              <small>{{ userAdminState.detail.boundAccounts.length }} 个</small>
                            </header>
                            <el-table :data="userAdminState.detail.boundAccounts" height="190" stripe v-loading="userAdminState.detailLoading">
                              <el-table-column prop="id" label="ID" width="76" />
                              <el-table-column prop="login_name" label="登录账号" min-width="140" />
                              <el-table-column prop="role_name" label="角色" min-width="130" />
                              <el-table-column label="状态" width="96">
                                <template #default="{ row }">
                                  <el-tag :type="statusTagType(row.status)">
                                    {{ statusText(row.status) }}
                                  </el-tag>
                                </template>
                              </el-table-column>
                              <el-table-column prop="created_at" label="创建时间" width="168" />
                            </el-table>
                          </section>
                          <section class="detail-table-block">
                            <header>
                              <strong>企业微信绑定</strong>
                              <small>{{ userAdminState.detail.wechatAccounts.length }} 个</small>
                            </header>
                            <el-table :data="userAdminState.detail.wechatAccounts" height="190" stripe v-loading="userAdminState.detailLoading">
                              <el-table-column prop="wechat_userid" label="企业微信账号" min-width="150" />
                              <el-table-column prop="wechat_name" label="姓名" min-width="120" />
                              <el-table-column prop="mobile" label="手机" min-width="130" />
                              <el-table-column prop="email" label="邮箱" min-width="160" />
                              <el-table-column prop="last_synced_at" label="同步时间" width="168" />
                            </el-table>
                          </section>
                        </template>
                      </div>
                    </section>
                  </div>
                  <small v-if="userAdminState.message">{{ userAdminState.message }}</small>
                </div>

                <div v-else-if="isArchiveManageWindow(win)" class="admin-panel archive-panel">
                  <div class="admin-toolbar">
                    <strong class="admin-toolbar-title">{{ archiveDefinitionForWindow(win).name }}管理</strong>
                    <el-button :icon="RefreshCw" :loading="archiveStateForWindow(win).loading" @click="loadArchiveItems(archiveTypeForWindow(win), archiveStateForWindow(win).pagination.page)">
                      刷新
                    </el-button>
                    <el-button :icon="Plus" @click="openArchiveDialog(archiveTypeForWindow(win))">
                      新增
                    </el-button>
                    <el-button
                      v-if="canImportArchiveExcel(archiveTypeForWindow(win))"
                      :icon="Upload"
                      :loading="archiveStateForWindow(win).importing"
                      @click="chooseArchiveExcel(archiveTypeForWindow(win))"
                    >
                      导入Excel
                    </el-button>
                    <el-button :icon="Edit3" :disabled="!archiveStateForWindow(win).selected" @click="openArchiveDialog(archiveTypeForWindow(win), archiveStateForWindow(win).selected)">
                      编辑
                    </el-button>
                    <el-button
                      type="danger"
                      :icon="Trash2"
                      :disabled="!archiveStateForWindow(win).selected"
                      @click="deleteArchiveConfig(archiveTypeForWindow(win))"
                    >
                      删除
                    </el-button>
                  </div>
                  <div class="archive-filter-bar data-list-toolbar">
                    <div class="data-list-filters">
                      <label :class="{ 'filter-active': hasFilterValue(archiveStateForWindow(win).filters.keyword) }">
                        <span>关键词</span>
                        <input
                          v-model="archiveStateForWindow(win).filters.keyword"
                          :placeholder="archiveKeywordPlaceholder(archiveTypeForWindow(win))"
                          @keyup.enter="loadArchiveItems(archiveTypeForWindow(win), 1)"
                        >
                      </label>
                      <label :class="{ 'filter-active': archiveStateForWindow(win).filters.filter_flag !== 'all' }">
                        <span>状态</span>
                        <el-select
                          v-model="archiveStateForWindow(win).filters.filter_flag"
                          class="filter-select"
                          :class="{ 'is-filter-active': archiveStateForWindow(win).filters.filter_flag !== 'all' }"
                          filterable
                          @change="loadArchiveItems(archiveTypeForWindow(win), 1)"
                        >
                          <el-option v-for="option in archiveStatusFilterOptions" :key="option.value" :label="option.label" :value="option.value" />
                        </el-select>
                      </label>
                    </div>
                    <div class="data-list-actions">
                      <el-button :icon="Search" :loading="archiveStateForWindow(win).loading" @click="loadArchiveItems(archiveTypeForWindow(win), 1)">
                        查询
                      </el-button>
                      <el-button @click="resetArchiveFilters(archiveTypeForWindow(win))">
                        重置
                      </el-button>
                    </div>
                  </div>
                  <el-table :data="archiveStateForWindow(win).items" height="100%" stripe highlight-current-row @row-click="row => selectArchiveItem(archiveTypeForWindow(win), row)">
                    <el-table-column :prop="archiveIdFieldForWindow(win)" label="ID" width="76" />
                    <el-table-column
                      v-for="field in archiveTableFieldsForWindow(win)"
                      :key="field.key"
                      :prop="field.key"
                      :label="field.label"
                      min-width="120"
                    >
                      <template #default="{ row }">
                        {{ archiveFieldText(field, row[field.key]) }}
                      </template>
                    </el-table-column>
                  </el-table>
                  <div class="archive-pagination data-list-pagination">
                    <span>共 {{ archiveStateForWindow(win).pagination.total || 0 }} 条</span>
                    <el-pagination
                      size="small"
                      layout="prev, pager, next"
                      :current-page="archiveStateForWindow(win).pagination.page || 1"
                      :page-size="archiveStateForWindow(win).pagination.page_size || 20"
                      :total="archiveStateForWindow(win).pagination.total || 0"
                      @current-change="page => loadArchiveItems(archiveTypeForWindow(win), page)"
                    />
                  </div>
                  <div v-if="archiveStateForWindow(win).dialogVisible" class="operation-mask" @click.self="closeArchiveDialog(archiveTypeForWindow(win))">
                    <section class="operation-dialog menu-dialog">
                      <header>
                        <strong>{{ archiveStateForWindow(win).editing.id ? `编辑${archiveDefinitionForWindow(win).name}` : `新增${archiveDefinitionForWindow(win).name}` }}</strong>
                        <button type="button" @click="closeArchiveDialog(archiveTypeForWindow(win))">关闭</button>
                      </header>
                      <div class="operation-form menu-dialog-form">
                      <label
                        v-for="field in archiveFieldsForWindow(win)"
                        :key="field.key"
                      >
                        <span>{{ field.label }}</span>
                        <el-switch
                          v-if="archiveFieldUsesSwitch(field)"
                          v-model="archiveStateForWindow(win).editing[field.key]"
                          :active-value="archiveSwitchActiveValue(field)"
                          :inactive-value="archiveSwitchInactiveValue(field)"
                          :active-text="archiveSwitchActiveText(field)"
                          :inactive-text="archiveSwitchInactiveText(field)"
                        />
                        <el-select
                          v-else-if="field.options"
                          v-model="archiveStateForWindow(win).editing[field.key]"
                          clearable
                          filterable
                          @change="handleArchiveFieldChange(archiveTypeForWindow(win), field.key)"
                        >
                          <el-option
                            v-for="option in archiveEditFieldOptions(field, archiveTypeForWindow(win))"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                          />
                        </el-select>
                        <input
                          v-else
                          v-model="archiveStateForWindow(win).editing[field.key]"
                          :type="field.inputType || 'text'"
                        >
                      </label>
                      </div>
                      <footer>
                        <el-button @click="closeArchiveDialog(archiveTypeForWindow(win))">取消</el-button>
                        <el-button type="primary" :icon="Save" :loading="archiveStateForWindow(win).loading" @click="saveArchiveConfig(archiveTypeForWindow(win))">
                          保存
                        </el-button>
                      </footer>
                    </section>
                  </div>
                  <small v-if="archiveStateForWindow(win).message">{{ archiveStateForWindow(win).message }}</small>
                </div>

                <div v-else-if="win.module.id === 'config' && win.panel === 'operationGuides'" class="admin-panel guide-admin-panel">
                  <div class="admin-toolbar">
                    <el-button type="primary" :icon="Plus" :disabled="!hasPermission('guide:save')" @click="openGuideAdminDialog()">
                      新增说明
                    </el-button>
                    <el-button :icon="RefreshCw" :loading="guideAdminState.loading" @click="loadGuideAdminItems(true)">
                      读取
                    </el-button>
                  </div>
                  <section class="guide-admin-list">
                    <el-table :data="guideAdminState.items" height="100%" stripe>
                      <el-table-column label="模块" width="150">
                        <template #default="{ row }">
                          {{ guideModuleName(row.module_key) }}
                        </template>
                      </el-table-column>
                      <el-table-column prop="title" label="标题" min-width="180" />
                      <el-table-column label="内容摘要" min-width="260">
                        <template #default="{ row }">
                          <span class="guide-content-preview">{{ guideContentPreview(row.content) }}</span>
                        </template>
                      </el-table-column>
                      <el-table-column prop="sort" label="排序" width="90" />
                      <el-table-column prop="updated_at" label="更新时间" width="168" />
                      <el-table-column label="操作" width="150" fixed="right">
                        <template #default="{ row }">
                          <el-button link type="primary" :disabled="!hasPermission('guide:save')" @click="openGuideAdminDialog(row)">
                            编辑
                          </el-button>
                          <el-button link type="danger" :disabled="!hasPermission('guide:delete')" @click="deleteGuideAdminItem(row)">
                            删除
                          </el-button>
                        </template>
                      </el-table-column>
                    </el-table>
                  </section>
                  <small v-if="guideAdminState.message">{{ guideAdminState.message }}</small>

                  <div v-if="guideAdminState.dialogVisible" class="operation-mask" @click.self="closeGuideAdminDialog">
                    <section class="operation-dialog guide-edit-dialog">
                      <header>
                        <strong>{{ guideAdminState.dialogMode === 'edit' ? '编辑操作说明' : '新增操作说明' }}</strong>
                        <button type="button" @click="closeGuideAdminDialog">关闭</button>
                      </header>
                      <div class="guide-admin-fields">
                        <label>
                          <span>模块</span>
                          <el-select v-model="guideAdminState.editing.module_key" filterable placeholder="选择模块">
                            <el-option
                              v-for="item in guideModuleOptions"
                              :key="item.value"
                              :label="item.label"
                              :value="item.value"
                            />
                          </el-select>
                        </label>
                        <label>
                          <span>标题</span>
                          <input v-model="guideAdminState.editing.title">
                        </label>
                        <label>
                          <span>排序</span>
                          <input v-model="guideAdminState.editing.sort" type="number">
                        </label>
                      </div>
                      <div class="rich-editor-toolbar">
                        <button type="button" @click="applyGuideFormat('bold')">B</button>
                        <button type="button" @click="applyGuideFormat('insertUnorderedList')">列表</button>
                        <button type="button" @click="insertGuideTemplate">模板</button>
                      </div>
                      <div
                        ref="guideEditorRef"
                        class="rich-editor"
                        contenteditable="true"
                        @input="syncGuideEditor"
                        v-html="guideAdminState.editing.content"
                      />
                      <footer>
                        <el-button @click="closeGuideAdminDialog">取消</el-button>
                        <el-button
                          type="primary"
                          :icon="Save"
                          :disabled="!hasPermission('guide:save')"
                          :loading="guideAdminState.loading"
                          @click="saveGuideAdminItem"
                        >
                          保存
                        </el-button>
                      </footer>
                    </section>
                  </div>
                </div>

                <div v-else-if="win.module.id === 'doc'" class="module-content-panel">
                  <DocCenter :can-manage="hasPermission('doc:manage')" />
                </div>

                <div v-else-if="win.module.id === 'templateLib'" class="module-content-panel">
                  <TemplateLibrary
                    :can-manage="hasPermission('template:manage')"
                    :can-view-message-templates="canViewMessageTemplates"
                    :can-manage-message-templates="canManageMessageTemplates"
                  />
                </div>

                <div v-else-if="win.module.id === 'exportTask'" class="module-content-panel">
                  <ExportTaskCenter />
                </div>

                <div v-else-if="win.module.id === 'favorite'" class="favorite-panel">
                  <div class="admin-toolbar favorite-toolbar">
                    <el-input
                      v-model="favoriteState.filters.keyword"
                      clearable
                      placeholder="搜索标题或网址"
                      @keyup.enter="loadFavorites(1)"
                    />
                    <el-button :icon="Search" :loading="favoriteState.loading" @click="loadFavorites(1)">
                      查询
                    </el-button>
                    <el-button :icon="RefreshCw" :loading="favoriteState.loading" @click="loadFavorites(favoriteState.pagination.page)">
                      刷新
                    </el-button>
                    <el-button type="primary" :icon="Plus" @click="openFavoriteDialog()">
                      新增收藏
                    </el-button>
                  </div>
                  <el-alert
                    v-if="favoriteState.message"
                    type="warning"
                    :closable="false"
                    show-icon
                    :title="favoriteState.message"
                  />
                  <div class="favorite-grid-wrap" v-loading="favoriteState.loading">
                    <article
                      v-for="(row, index) in favoriteState.items"
                      :key="row.id"
                      class="favorite-card"
                      :class="favoriteCardClass(row, index)"
                    >
                      <button type="button" class="favorite-card-main" @click="openFavoriteLink(row)">
                        <AppIcon class="favorite-card-icon" :icon="Globe2" :icon-url="row.icon_url" :label="row.title" color="" :size="24" :backend-url="backendUrl" />
                        <span>
                          <strong>{{ row.title || '未命名收藏' }}</strong>
                          <small>{{ favoriteHost(row) }}</small>
                        </span>
                      </button>
                      <el-dropdown trigger="click" @command="command => handleFavoriteCommand(row, command)">
                        <button type="button" class="favorite-card-more" title="更多操作" @click.stop>
                          <MoreHorizontal :size="22" />
                        </button>
                        <template #dropdown>
                          <el-dropdown-menu>
                            <el-dropdown-item command="open">打开</el-dropdown-item>
                            <el-dropdown-item command="edit">编辑</el-dropdown-item>
                            <el-dropdown-item command="desktop">{{ isFavoriteDesktop(row.id) ? '从桌面移除' : '加入桌面' }}</el-dropdown-item>
                            <el-dropdown-item command="delete" divided>删除</el-dropdown-item>
                          </el-dropdown-menu>
                        </template>
                      </el-dropdown>
                    </article>
                    <div v-if="!favoriteState.loading && !favoriteState.items.length" class="empty-grid-state">
                      暂无收藏
                    </div>
                  </div>
                  <div class="file-pagination">
                    <span>共 {{ favoriteState.pagination.total }} 个收藏</span>
                    <el-pagination
                      size="small"
                      layout="prev, pager, next"
                      :current-page="favoriteState.pagination.page"
                      :page-size="favoriteState.pagination.page_size"
                      :total="favoriteState.pagination.total"
                      @current-change="loadFavorites"
                    />
                  </div>

                  <div v-if="favoriteState.dialogVisible" class="operation-mask" @click.self="closeFavoriteDialog">
                    <section class="operation-dialog favorite-dialog">
                      <header>
                        <strong>{{ favoriteState.editing.id ? '编辑收藏' : '新增收藏' }}</strong>
                        <button type="button" @click="closeFavoriteDialog">关闭</button>
                      </header>
                      <div class="favorite-editor">
                        <div class="favorite-preview-card" :class="favoriteCardClass(favoriteState.editing, 0)">
                          <AppIcon class="favorite-card-icon" :icon="Globe2" :icon-url="favoriteState.editing.icon_url" label="收藏图标" color="" :size="24" :backend-url="backendUrl" />
                          <strong>{{ favoriteState.editing.title || '收藏标题' }}</strong>
                          <MoreHorizontal :size="22" />
                        </div>
                        <div class="operation-form favorite-form">
                          <label><span>标题</span><input v-model="favoriteState.editing.title" maxlength="180" placeholder="请输入收藏标题"></label>
                          <label><span>网址</span><input v-model="favoriteState.editing.url" maxlength="500" placeholder="https://example.com"></label>
                          <label><span>排序</span><input v-model="favoriteState.editing.sort" type="number"></label>
                          <div class="favorite-desktop-check">
                            <span>桌面</span>
                            <label class="inline-check">
                              <input v-model="favoriteState.editing.add_to_desktop" type="checkbox">
                              <span>发送快捷方式到桌面</span>
                            </label>
                          </div>
                          <label class="favorite-icon-field">
                            <span>图标</span>
                            <IconUpload
                              button-class="favorite-icon-upload"
                              :icon="Globe2"
                              :icon-url="favoriteState.editing.icon_url"
                              label="收藏图标"
                              :uploading="favoriteState.iconUploading"
                              :backend-url="backendUrl"
                              @select="handleFavoriteIconSelected"
                            />
                          </label>
                        </div>
                      </div>
                      <footer>
                        <el-button @click="closeFavoriteDialog">取消</el-button>
                        <el-button type="primary" :icon="Save" :loading="favoriteState.loading" @click="saveFavoriteItem">
                          保存
                        </el-button>
                      </footer>
                    </section>
                  </div>
                </div>

                <div v-else-if="win.module.source === 'menu'" class="module-content-panel menu-module-panel">
                  <section class="menu-module-card">
                    <header>
                      <AppIcon class="app-glyph" :icon="win.module.icon" :icon-url="win.module.iconUrl" :label="win.module.name" :color="win.module.color" :size="26" :backend-url="backendUrl" />
                      <span>
                        <strong>{{ win.module.name }}</strong>
                        <small>{{ win.module.scope }}</small>
                      </span>
                    </header>
                    <div class="menu-module-grid">
                      <button
                        v-for="item in menuModuleChildren(win.module.menuId)"
                        :key="item.id"
                        type="button"
                        @click="openMenuModuleChild(item)"
                      >
                        <component :is="resolveMenuIcon(item.icon)" :size="18" />
                        <span>{{ item.name }}</span>
                        <small>{{ menuNodeTypeText(item.type) }}</small>
                      </button>
                    </div>
                    <div v-if="!menuModuleChildren(win.module.menuId).length" class="empty-grid-state">
                      暂无子菜单
                    </div>
                  </section>
                </div>

                <div v-else-if="win.module.id === 'message'" class="message-center-panel">
                  <div class="message-toolbar">
                    <el-select v-model="messageState.filters.type" @change="loadMessages(1)">
                      <el-option
                        v-for="item in messageTypeOptions"
                        :key="item.value"
                        :label="item.label"
                        :value="item.value"
                      />
                    </el-select>
                    <el-select v-model="messageState.filters.status" @change="loadMessages(1)">
                      <el-option
                        v-for="item in messageStatusOptions"
                        :key="item.value"
                        :label="item.label"
                        :value="item.value"
                      />
                    </el-select>
                    <el-input
                      v-model="messageState.filters.keyword"
                      clearable
                      placeholder="搜索标题、内容、发送人"
                      @keyup.enter="loadMessages(1)"
                    />
                    <el-button :icon="Search" :loading="messageState.loading" @click="loadMessages(1)">
                      查询
                    </el-button>
                    <el-button :icon="RefreshCw" :loading="messageState.loading" @click="resetMessageFilters">
                      重置
                    </el-button>
                    <el-button
                      v-if="canSendMessages"
                      type="primary"
                      :icon="Plus"
                      :loading="messageState.sendDialog.sending"
                      @click="openMessageSendDialog"
                    >
                      发送消息
                    </el-button>
                    <el-button
                      v-if="canViewMessageTemplates"
                      :icon="FileText"
                      :loading="messageState.templateLoading"
                      @click="openMessageTemplateManager"
                    >
                      {{ canManageMessageTemplates ? '模板管理' : '模板查看' }}
                    </el-button>
                    <el-button
                      type="primary"
                      plain
                      :disabled="messageUnreadCount <= 0"
                      :loading="messageState.loading"
                      @click="markAllMessagesRead"
                    >
                      全部已读
                    </el-button>
                  </div>

                  <section class="message-summary-strip">
                    <button
                      v-for="item in messageTypeOptions"
                      :key="`summary-${item.value}`"
                      type="button"
                      :class="{ active: messageState.filters.type === item.value }"
                      @click="setMessageType(item.value)"
                    >
                      <span>{{ item.label }}</span>
                      <strong>{{ messageTypeUnread(item.value) }}</strong>
                    </button>
                  </section>

                  <el-alert
                    v-if="messageState.message"
                    type="warning"
                    :closable="false"
                    show-icon
                    :title="messageState.message"
                  />

                  <section class="message-timeline" v-loading="messageState.loading">
                    <template v-if="messageGroups.length">
                      <div
                        v-for="group in messageGroups"
                        :key="group.key"
                        class="message-day-group"
                      >
                        <div class="message-day-separator">
                          <span>{{ group.label }}</span>
                        </div>
                        <article
                          v-for="item in group.items"
                          :key="item.target_id"
                          class="message-bubble"
                          :class="{ unread: !item.is_read, urgent: item.level === 'urgent', own: isOwnMessage(item) }"
                          @click="handleMessageClick(item)"
                        >
                          <header>
                            <span class="message-kind" :class="item.type">{{ messageTypeText(item.type) }}</span>
                            <strong>{{ item.title }}</strong>
                            <small>{{ messageTimeText(item) }}</small>
                          </header>
                          <p>{{ item.content }}</p>
                          <footer>
                            <span>{{ isOwnMessage(item) ? '我发送' : (item.sender_name || '系统') }}</span>
                            <el-tag size="small" :type="messageLevelTagType(item.level)">
                              {{ messageLevelText(item.level) }}
                            </el-tag>
                            <em>{{ item.is_read ? `已读 ${item.read_at || ''}` : '未读' }}</em>
                            <button v-if="item.link_url" type="button" @click.stop="openMessageLink(item)">
                              打开关联页面
                            </button>
                          </footer>
                        </article>
                      </div>
                    </template>
                    <div v-else class="module-empty-state">
                      <strong>暂无消息</strong>
                      <span>新的待办、审核结果和系统通知会在这里按时间显示。</span>
                    </div>
                  </section>

                  <div class="file-pagination">
                    <span>共 {{ messageState.pagination.total }} 条消息，未读 {{ messageUnreadCount }} 条</span>
                    <el-pagination
                      size="small"
                      layout="prev, pager, next"
                      :current-page="messageState.pagination.page"
                      :page-size="messageState.pagination.page_size"
                      :total="messageState.pagination.total"
                      @current-change="loadMessages"
                    />
                  </div>

                  <div v-if="messageState.sendDialog.visible" class="operation-mask" @click.self="closeMessageSendDialog">
                    <section class="operation-dialog message-send-dialog">
                      <header>
                        <strong>发送消息</strong>
                        <button type="button" @click="closeMessageSendDialog">关闭</button>
                      </header>
                      <div class="message-send-body">
                        <div class="message-send-grid">
                          <label class="message-send-field">
                            <span>发送方式</span>
                            <el-select v-model="messageState.sendDialog.form.send_mode" @change="handleMessageSendModeChange">
                              <el-option label="直接填写" value="manual" />
                              <el-option label="使用模板" value="template" />
                            </el-select>
                          </label>
                          <label v-if="messageState.sendDialog.form.send_mode === 'template'" class="message-send-field">
                            <span>消息模板</span>
                            <el-select v-model="messageState.sendDialog.form.template_code" filterable placeholder="请选择模板" @change="handleMessageTemplateChange">
                              <el-option
                                v-for="item in enabledMessageTemplates"
                                :key="item.code"
                                :label="`${item.name} / ${item.code}`"
                                :value="item.code"
                              />
                            </el-select>
                          </label>
                          <label v-else class="message-send-field">
                            <span>消息级别</span>
                            <el-select v-model="messageState.sendDialog.form.level">
                              <el-option
                                v-for="item in messageLevelOptions"
                                :key="item.value"
                                :label="item.label"
                                :value="item.value"
                              />
                            </el-select>
                          </label>
                          <label class="message-send-field">
                            <span>发送范围</span>
                            <el-select v-model="messageState.sendDialog.form.send_scope" @change="handleMessageSendScopeChange">
                              <el-option label="指定人员" value="custom" />
                              <el-option label="按角色发送" value="role" />
                              <el-option label="全部账号" value="all" />
                            </el-select>
                          </label>
                          <label v-if="messageState.sendDialog.form.send_scope === 'role'" class="message-send-field">
                            <span>接收角色</span>
                            <el-select v-model="messageState.sendDialog.form.role_type" placeholder="请选择角色">
                              <el-option
                                v-for="item in messageTargetRoleOptions"
                                :key="item.value"
                                :label="item.label"
                                :value="item.value"
                              />
                            </el-select>
                          </label>
                          <label v-else class="message-send-field">
                            <span>接收说明</span>
                            <div class="message-send-hint">
                              {{ messageSendScopeHint }}
                            </div>
                          </label>
                        </div>
                        <div v-if="messageState.sendDialog.form.send_scope === 'custom'" class="message-send-target-panel">
                          <div class="message-send-target-tools">
                            <el-select v-model="messageState.sendDialog.filters.role_type" clearable placeholder="角色" @change="loadMessageTargets">
                              <el-option
                                v-for="item in messageTargetRoleOptions"
                                :key="item.value"
                                :label="item.label"
                                :value="item.value"
                              />
                            </el-select>
                            <el-input
                              v-model="messageState.sendDialog.filters.keyword"
                              clearable
                              placeholder="搜索姓名、账号、手机、角色"
                              @keyup.enter="loadMessageTargets"
                            />
                            <el-button :icon="Search" :loading="messageState.targetLoading" @click="loadMessageTargets">
                              查找
                            </el-button>
                          </div>
                          <label class="message-send-field wide">
                            <span>收件人</span>
                            <el-select
                              v-model="messageState.sendDialog.form.account_ids"
                              multiple
                              filterable
                              collapse-tags
                              collapse-tags-tooltip
                              placeholder="请选择收件人"
                            >
                              <el-option
                                v-for="item in messageState.targetOptions"
                                :key="item.id"
                                :label="messageTargetLabel(item)"
                                :value="item.id"
                              />
                            </el-select>
                          </label>
                        </div>
                        <div v-if="messageState.sendDialog.form.send_mode === 'manual'" class="message-send-grid message-send-grid-compact">
                          <label class="message-send-field">
                            <span>消息类型</span>
                            <el-select v-model="messageState.sendDialog.form.type">
                              <el-option
                                v-for="item in messageTypeOptions.filter(option => option.value !== 'all')"
                                :key="item.value"
                                :label="item.label"
                                :value="item.value"
                              />
                            </el-select>
                          </label>
                          <label class="message-send-field">
                            <span>消息级别</span>
                            <el-select v-model="messageState.sendDialog.form.level">
                              <el-option
                                v-for="item in messageLevelOptions"
                                :key="item.value"
                                :label="item.label"
                                :value="item.value"
                              />
                            </el-select>
                          </label>
                        </div>
                        <label v-if="messageState.sendDialog.form.send_mode === 'template'" class="message-send-field wide message-template-vars">
                          <span>模板变量 JSON</span>
                          <el-input
                            v-model="messageState.sendDialog.form.variables_text"
                            type="textarea"
                            :rows="6"
                            placeholder="{ &quot;module_name&quot;: &quot;实习日志&quot;, &quot;status_text&quot;: &quot;通过&quot; }"
                          />
                        </label>
                        <section v-if="messageState.sendDialog.form.send_mode === 'template' && currentMessageTemplate()" class="message-template-preview">
                          <strong>{{ currentMessageTemplate().title_tpl }}</strong>
                          <p>{{ currentMessageTemplate().content_tpl }}</p>
                          <small>变量：{{ messageTemplateVariableText(currentMessageTemplate()) }}</small>
                        </section>
                        <label v-if="messageState.sendDialog.form.send_mode === 'manual'" class="message-send-field wide message-send-title">
                          <span>标题</span>
                          <el-input v-model="messageState.sendDialog.form.title" maxlength="180" show-word-limit />
                        </label>
                        <label v-if="messageState.sendDialog.form.send_mode === 'manual'" class="message-send-field wide message-send-content">
                          <span>内容</span>
                          <el-input
                            v-model="messageState.sendDialog.form.content"
                            type="textarea"
                            :rows="4"
                            maxlength="1000"
                            show-word-limit
                          />
                        </label>
                        <label class="message-send-field wide message-send-link">
                          <span>关联地址</span>
                          <el-input v-model="messageState.sendDialog.form.link_url" placeholder="#panel=internship:applications 或 https://..." />
                        </label>
                      </div>
                      <footer>
                        <el-button @click="closeMessageSendDialog">取消</el-button>
                        <el-button
                          type="primary"
                          :icon="Save"
                          :loading="messageState.sendDialog.sending"
                          @click="submitMessageSend"
                        >
                          发送
                        </el-button>
                      </footer>
                    </section>
                  </div>

                  <div v-if="messageState.templateManager.visible" class="operation-mask" @click.self="closeMessageTemplateManager">
                    <section class="operation-dialog message-template-dialog">
                      <header>
                        <strong>流程待办/消息模板管理</strong>
                        <button type="button" @click="closeMessageTemplateManager">关闭</button>
                      </header>
                      <div class="message-template-toolbar">
                        <el-select v-model="messageState.templateFilters.type" @change="loadMessageTemplates(1)">
                          <el-option
                            v-for="item in messageTemplateTypeOptions"
                            :key="item.value"
                            :label="item.label"
                            :value="item.value"
                          />
                        </el-select>
                        <el-select v-model="messageState.templateFilters.status" @change="loadMessageTemplates(1)">
                          <el-option label="全部状态" value="all" />
                          <el-option label="启用" value="enabled" />
                          <el-option label="停用" value="disabled" />
                        </el-select>
                        <el-input v-model="messageState.templateFilters.keyword" clearable placeholder="搜索名称、编码、内容" @keyup.enter="loadMessageTemplates(1)" />
                        <el-button :icon="Search" :loading="messageState.templateLoading" @click="loadMessageTemplates(1)">查询</el-button>
                        <el-button
                          v-if="canManageMessageTemplates"
                          :icon="RefreshCw"
                          :loading="messageState.templateSyncing"
                          @click="syncDefaultMessageTemplates"
                        >
                          同步默认流程模板
                        </el-button>
                        <el-button v-if="canManageMessageTemplates" type="primary" :icon="Plus" @click="openMessageTemplateEdit()">新增</el-button>
                      </div>
                      <div class="message-template-table">
                        <el-table :data="messageState.templates" height="100%" stripe v-loading="messageState.templateLoading">
                          <template #empty>
                            <div class="template-empty-state">
                              <strong>{{ messageTemplateEmptyTitle }}</strong>
                              <span>{{ messageTemplateEmptyText }}</span>
                              <el-button
                                v-if="canManageMessageTemplates"
                                type="primary"
                                :icon="RefreshCw"
                                :loading="messageState.templateSyncing"
                                @click="syncDefaultMessageTemplates"
                              >
                                同步默认流程模板
                              </el-button>
                            </div>
                          </template>
                          <el-table-column prop="name" label="模板名称" min-width="150" />
                          <el-table-column prop="code" label="模板编码" min-width="180" />
                          <el-table-column label="类型" width="100">
                            <template #default="{ row }">{{ messageTypeText(row.type) }}</template>
                          </el-table-column>
                          <el-table-column label="级别" width="90">
                            <template #default="{ row }">{{ messageLevelText(row.level) }}</template>
                          </el-table-column>
                          <el-table-column prop="title_tpl" label="标题模板" min-width="210" show-overflow-tooltip />
                          <el-table-column label="状态" width="90">
                            <template #default="{ row }">
                              <el-tag :type="row.status === 'enabled' ? 'success' : 'info'" size="small">{{ row.status === 'enabled' ? '启用' : '停用' }}</el-tag>
                            </template>
                          </el-table-column>
                          <el-table-column label="操作" width="140" fixed="right">
                            <template #default="{ row }">
                              <template v-if="canManageMessageTemplates">
                                <el-button link type="primary" @click="openMessageTemplateEdit(row)">编辑</el-button>
                                <el-button link type="danger" :disabled="row.is_system" @click="removeMessageTemplate(row)">删除</el-button>
                              </template>
                              <span v-else>-</span>
                            </template>
                          </el-table-column>
                        </el-table>
                      </div>
                      <footer>
                        <span>共 {{ messageState.templatePagination.total }} 个模板</span>
                        <el-pagination
                          size="small"
                          layout="prev, pager, next"
                          :current-page="messageState.templatePagination.page"
                          :page-size="messageState.templatePagination.page_size"
                          :total="messageState.templatePagination.total"
                          @current-change="loadMessageTemplates"
                        />
                      </footer>
                    </section>
                  </div>

                  <div v-if="messageState.templateEdit.visible" class="operation-mask" @click.self="closeMessageTemplateEdit">
                    <section class="operation-dialog message-template-edit-dialog">
                      <header>
                        <strong>{{ messageState.templateEdit.form.id ? '编辑消息模板' : '新增消息模板' }}</strong>
                        <button type="button" @click="closeMessageTemplateEdit">关闭</button>
                      </header>
                      <div class="message-template-edit-body">
                        <label><span>模板名称</span><el-input v-model="messageState.templateEdit.form.name" maxlength="180" /></label>
                        <label><span>模板编码</span><el-input v-model="messageState.templateEdit.form.code" :disabled="messageState.templateEdit.form.is_system" maxlength="120" /></label>
                        <label><span>消息类型</span><el-select v-model="messageState.templateEdit.form.type"><el-option v-for="item in messageTemplateTypeOptions.filter(option => option.value !== 'all')" :key="item.value" :label="item.label" :value="item.value" /></el-select></label>
                        <label><span>消息级别</span><el-select v-model="messageState.templateEdit.form.level"><el-option v-for="item in messageLevelOptions" :key="item.value" :label="item.label" :value="item.value" /></el-select></label>
                        <label><span>状态</span><el-switch v-model="messageState.templateEdit.form.status" active-value="enabled" inactive-value="disabled" active-text="启用" inactive-text="停用" /></label>
                        <label><span>排序</span><el-input v-model.number="messageState.templateEdit.form.sort" type="number" /></label>
                        <label class="wide"><span>标题模板</span><el-input v-model="messageState.templateEdit.form.title_tpl" maxlength="255" show-word-limit /></label>
                        <label class="wide"><span>内容模板</span><el-input v-model="messageState.templateEdit.form.content_tpl" type="textarea" :rows="4" /></label>
                        <label class="wide"><span>跳转地址模板</span><el-input v-model="messageState.templateEdit.form.link_url_tpl" placeholder="#panel={module_key}:{panel_key}" /></label>
                        <label class="wide"><span>变量说明 JSON</span><el-input v-model="messageState.templateEdit.form.variables_text" type="textarea" :rows="5" /></label>
                        <label class="wide"><span>说明</span><el-input v-model="messageState.templateEdit.form.description" type="textarea" :rows="2" maxlength="500" show-word-limit /></label>
                      </div>
                      <footer>
                        <el-button @click="closeMessageTemplateEdit">取消</el-button>
                        <el-button type="primary" :loading="messageState.templateEdit.saving" @click="submitMessageTemplate">保存</el-button>
                      </footer>
                    </section>
                  </div>
                </div>

                <div v-else-if="win.module.id === 'file' && win.panel === 'fileManage'" class="admin-panel file-admin">
                  <div class="admin-toolbar file-toolbar">
                    <el-input
                      v-model="fileState.filters.keyword"
                      clearable
                      placeholder="搜索文件名、上传人、MD5"
                      @keyup.enter="loadFiles(1)"
                    />
                    <el-select v-model="fileState.filters.status" @change="loadFiles(1)">
                      <el-option label="全部文件" value="all" />
                      <el-option label="正常文件" value="active" />
                      <el-option label="已删除" value="deleted" />
                    </el-select>
                    <el-select v-model="fileState.filters.category" clearable placeholder="分类" @change="loadFiles(1)">
                      <el-option label="学生材料" value="student" />
                      <el-option label="个人资源" value="profile" />
                      <el-option label="通用文件" value="general" />
                    </el-select>
                    <el-button :icon="RefreshCw" :loading="fileState.loading" @click="loadFiles(fileState.pagination.page)">
                      读取
                    </el-button>
                  </div>
                  <el-table :data="fileState.items" height="100%" stripe>
                    <el-table-column label="文件" min-width="220">
                      <template #default="{ row }">
                        <div class="file-cell">
                          <FolderOpen :size="17" />
                          <span>
                            <strong>{{ row.name }}</strong>
                            <small>{{ row.blob.ext }} / {{ row.blob.mime_type || '-' }}</small>
                          </span>
                        </div>
                      </template>
                    </el-table-column>
                    <el-table-column label="分类" width="110">
                      <template #default="{ row }">
                        <el-tag>{{ fileCategoryText(row.category) }}</el-tag>
                      </template>
                    </el-table-column>
                    <el-table-column label="大小" width="100">
                      <template #default="{ row }">
                        {{ formatFileSize(row.blob.size) }}
                      </template>
                    </el-table-column>
                    <el-table-column label="上传人" min-width="140">
                      <template #default="{ row }">
                        {{ row.uploader.name || row.uploader.login_name || '-' }}
                      </template>
                    </el-table-column>
                    <el-table-column prop="created_at" label="上传时间" width="168" />
                    <el-table-column label="设备信息" min-width="220">
                      <template #default="{ row }">
                        <div class="device-cell">
                          <HardDrive :size="16" />
                          <span>
                            <strong>{{ row.device.client || '-' }} / {{ row.device.ip || '-' }}</strong>
                            <small :title="row.device.user_agent || '-'">{{ compactUserAgent(row.device.user_agent) }}</small>
                          </span>
                        </div>
                      </template>
                    </el-table-column>
                    <el-table-column label="状态" width="96">
                      <template #default="{ row }">
                        <el-tag :type="row.status === 'deleted' ? 'info' : 'success'">
                          {{ row.status === 'deleted' ? '已删除' : '正常' }}
                        </el-tag>
                      </template>
                    </el-table-column>
                    <el-table-column label="操作" width="120" fixed="right">
                      <template #default="{ row }">
                        <el-button link type="primary" :disabled="!row.url" @click="openFileUrl(row)">
                          打开
                        </el-button>
                      </template>
                    </el-table-column>
                  </el-table>
                  <div class="file-pagination">
                    <span>共 {{ fileState.pagination.total }} 个文件</span>
                    <el-pagination
                      size="small"
                      layout="prev, pager, next"
                      :current-page="fileState.pagination.page"
                      :page-size="fileState.pagination.page_size"
                      :total="fileState.pagination.total"
                      @current-change="loadFiles"
                    />
                  </div>
                  <small v-if="fileState.message">{{ fileState.message }}</small>
                </div>

                <div v-else-if="win.module.id === 'config' && win.panel === 'menuManage'" class="admin-panel menu-admin">
                  <div class="admin-toolbar">
                    <el-button :icon="Plus" @click="openMenuDialog()">
                      新增主菜单
                    </el-button>
                    <el-button :icon="ListTree" :disabled="!adminState.menu.selected || adminState.menu.selected.type === 'button'" @click="openMenuDialog(null, adminState.menu.selected)">
                      新增子级
                    </el-button>
                    <el-button
                      :icon="Edit3"
                      :disabled="!adminState.menu.selected"
                      @click="openMenuDialog(adminState.menu.selected)"
                    >
                      编辑
                    </el-button>
                    <el-button
                      type="danger"
                      :icon="Trash2"
                      :disabled="!adminState.menu.selected"
                      @click="deleteMenuConfig(adminState.menu.selected)"
                    >
                      删除菜单
                    </el-button>
                    <el-button :icon="RefreshCw" :loading="adminState.loading" @click="loadAdminFoundation">
                      刷新
                    </el-button>
                    <el-input
                      v-model="adminState.menu.keyword"
                      class="menu-search-input"
                      clearable
                      placeholder="搜索菜单名称、权限码、路径"
                      @input="filterMenuTree"
                    />
                    <el-button @click="setMenuTreeExpanded(true)">
                      一键展开
                    </el-button>
                    <el-button @click="setMenuTreeExpanded(false)">
                      一键收缩
                    </el-button>
                  </div>
                  <section class="menu-tree-panel menu-tree-full">
                    <header>
                      <strong>菜单树</strong>
                      <small>{{ adminState.menu.items.length }} 项</small>
                    </header>
                    <el-tree
                      :key="adminState.menu.treeKey"
                      ref="menuTreeRef"
                      class="permission-tree menu-edit-tree"
                      :data="adminState.menus"
                      :props="treeProps"
                      node-key="id"
                      :default-expanded-keys="adminState.menu.expandedKeys"
                      highlight-current
                      :current-node-key="adminState.menu.selected?.id"
                      :expand-on-click-node="false"
                      :filter-node-method="filterMenuNode"
                      @node-click="selectMenu"
                    >
                      <template #default="{ data }">
                        <span class="tree-node menu-manage-node">
                          <span class="menu-node-main">
                            <strong>{{ data.name }}</strong>
                            <small>{{ data.code || '未配置权限码' }}</small>
                          </span>
                          <span class="menu-node-meta">
                            <em>{{ menuNodeKind(data) }}</em>
                            <small>{{ menuNodeTypeText(data.type) }} / {{ data.platform }} / {{ data.path || '-' }}{{ data.is_module === 'true' ? ' / 模块' : '' }}</small>
                          </span>
                        </span>
                      </template>
                    </el-tree>
                  </section>
                  <small v-if="adminState.menu.message">{{ adminState.menu.message }}</small>

                  <MenuEditDialog
                    :visible="adminState.menu.dialogVisible"
                    :mode="adminState.menu.dialogMode"
                    :form="adminState.menu.editing"
                    :parent-options="parentMenuTreeOptions"
                    :tree-props="treeProps"
                    :resolve-icon="resolveMenuIcon"
                    :icon-uploading="adminState.menu.iconUploading"
                    :loading="adminState.menu.loading"
                    :backend-url="backendUrl"
                    @close="closeMenuDialog"
                    @save="saveMenuConfig"
                    @select-icon="handleMenuIconSelected"
                  />
                </div>

                <div v-else-if="win.module.id === 'config' && win.panel === 'roleMenus'" class="admin-panel">
                  <div class="admin-toolbar">
                    <el-select
                      v-model="adminState.roleMenus.role_id"
                      filterable
                      placeholder="选择角色"
                      @change="loadRolePermissions"
                    >
                      <el-option
                        v-for="role in adminState.roles"
                        :key="role.id"
                        :label="`${role.name} / ${role.role_type}`"
                        :value="role.id"
                      />
                    </el-select>
                    <el-button :icon="RefreshCw" :loading="adminState.roleMenus.loading" @click="loadAdminFoundation">
                      读取
                    </el-button>
                    <el-button
                      type="primary"
                      :icon="Save"
                      :loading="adminState.roleMenus.loading"
                      :disabled="!adminState.roleMenus.role_id"
                      @click="saveRoleMenuConfig"
                    >
                      保存
                    </el-button>
                  </div>
                  <el-tree
                    ref="roleTreeRef"
                    class="permission-tree role-permission-tree"
                    :data="adminState.menus"
                    :props="treeProps"
                    node-key="id"
                    show-checkbox
                    default-expand-all
                  >
                    <template #default="{ data }">
                      <span class="tree-node menu-manage-node">
                        <span class="menu-node-main">
                          <strong>{{ data.name }}</strong>
                          <small>{{ data.code || '未配置权限码' }}</small>
                        </span>
                        <span class="menu-node-meta">
                          <em>{{ menuNodeKind(data) }}</em>
                          <small>{{ menuNodeTypeText(data.type) }} / {{ data.platform }}</small>
                        </span>
                      </span>
                    </template>
                  </el-tree>
                  <small v-if="adminState.roleMenus.message">{{ adminState.roleMenus.message }}</small>
                </div>

                <div v-else-if="win.module.id === 'config' && win.panel === 'organizationScope'" class="admin-panel">
                  <div class="admin-toolbar scope-toolbar">
                    <el-select
                      v-model="adminState.scope.account_id"
                      filterable
                      placeholder="选择账号"
                      @change="handleScopeAccountChange"
                    >
                      <el-option
                        v-for="account in adminState.options.accounts"
                        :key="account.id"
                        :label="`${account.name} / ${account.login_name}`"
                        :value="account.id"
                      />
                    </el-select>
                    <el-select
                      v-model="adminState.scope.role_id"
                      filterable
                      placeholder="选择角色"
                      @change="loadOrganizationScopeConfig"
                    >
                      <el-option
                        v-for="role in adminState.roles"
                        :key="role.id"
                        :label="`${role.name} / ${role.role_type}`"
                        :value="role.id"
                      />
                    </el-select>
                    <el-button :icon="SlidersHorizontal" @click="addScopeRow">
                      添加
                    </el-button>
                    <el-button
                      type="primary"
                      :icon="Save"
                      :loading="adminState.scope.loading"
                      :disabled="!adminState.scope.account_id || !adminState.scope.role_id"
                      @click="saveScopeConfig"
                    >
                      保存
                    </el-button>
                  </div>
                  <el-table :data="adminState.scope.scopes" height="100%" stripe>
                    <el-table-column label="学院" min-width="150">
                      <template #default="{ row }">
                        <el-select v-model="row.dep_id" clearable filterable placeholder="不限">
                          <el-option
                            v-for="dep in adminState.options.departments"
                            :key="dep.dep_id"
                            :label="dep.dep_name"
                            :value="String(dep.dep_id)"
                          />
                        </el-select>
                      </template>
                    </el-table-column>
                    <el-table-column label="专业" min-width="160">
                      <template #default="{ row }">
                        <el-select v-model="row.profession_id" clearable filterable placeholder="不限">
                          <el-option
                            v-for="profession in adminState.options.professions"
                            :key="profession.profession_id"
                            :label="profession.profession_name"
                            :value="String(profession.profession_id)"
                          />
                        </el-select>
                      </template>
                    </el-table-column>
                    <el-table-column label="班级" min-width="160">
                      <template #default="{ row }">
                        <el-select v-model="row.class_id" clearable filterable placeholder="不限">
                          <el-option
                            v-for="clazz in adminState.options.classes"
                            :key="clazz.class_id"
                            :label="clazz.class_name"
                            :value="String(clazz.class_id)"
                          />
                        </el-select>
                      </template>
                    </el-table-column>
                    <el-table-column label="基地" min-width="180">
                      <template #default="{ row }">
                        <el-select v-model="row.company_id" clearable filterable placeholder="不限">
                          <el-option
                            v-for="company in adminState.options.companies"
                            :key="company.company_id"
                            :label="company.company_name"
                            :value="String(company.company_id)"
                          />
                        </el-select>
                      </template>
                    </el-table-column>
                    <el-table-column label="操作" width="90" fixed="right">
                      <template #default="{ $index }">
                        <el-button link type="danger" @click="removeScopeRow($index)">
                          移除
                        </el-button>
                      </template>
                    </el-table-column>
                  </el-table>
                  <small v-if="adminState.scope.message">{{ adminState.scope.message }}</small>
                </div>

                <div v-else-if="win.module.id === 'dataManage' || (win.module.id === 'config' && win.panel === 'dataManage')" class="admin-panel data-manage-panel">
                  <section class="maintenance-card danger">
                    <header>
                      <div>
                        <strong>清除测试数据</strong>
                        <span>清空除超级管理员外的账号、用户业务数据、消息、文件记录和操作日志，保留角色、菜单、基础档案、流程配置和系统配置。</span>
                      </div>
                      <el-tag type="danger">危险操作</el-tag>
                    </header>
                    <p>该功能用于测试库重新验收。执行前会要求输入确认文本，执行后无法从页面撤销。</p>
                    <footer>
                      <el-button
                        type="danger"
                        :icon="Trash2"
                        :disabled="permissionState.context.role_type !== 'super_admin'"
                        :loading="dataManageState.clearLoading"
                        @click="clearCurrentTestData"
                      >
                        一键清除测试数据
                      </el-button>
                    </footer>
                  </section>
                  <el-alert
                    v-if="dataManageState.message"
                    type="warning"
                    :closable="false"
                    show-icon
                    :title="dataManageState.message"
                  />
                </div>

                <div v-else-if="win.module.id === 'log'" class="admin-panel log-panel">
                  <div class="admin-toolbar log-toolbar">
                    <el-input v-model="logState.filters.keyword" clearable placeholder="关键词、账号、姓名、IP" @keyup.enter="loadLogs(1)" />
                    <el-input v-model="logState.filters.action" clearable placeholder="动作" @keyup.enter="loadLogs(1)" />
                    <el-input v-model="logState.filters.ip" clearable placeholder="IP" @keyup.enter="loadLogs(1)" />
                    <div class="date-range-filter">
                      <label>
                        <span>开始日期</span>
                        <input v-model="logState.filters.date_from" type="date">
                      </label>
                      <em>至</em>
                      <label>
                        <span>结束日期</span>
                        <input v-model="logState.filters.date_to" type="date">
                      </label>
                    </div>
                    <el-button :icon="Search" :loading="logState.loading" @click="loadLogs(1)">
                      查询
                    </el-button>
                    <el-button :icon="RefreshCw" :loading="logState.loading" @click="resetLogFilters">
                      重置
                    </el-button>
                  </div>
                  <el-table :data="logState.items" height="100%" stripe>
                    <el-table-column prop="created_at" label="时间" width="168" />
                    <el-table-column label="账号" min-width="150">
                      <template #default="{ row }">
                        {{ row.user_name || row.login_name || row.account_id || '-' }}
                      </template>
                    </el-table-column>
                    <el-table-column label="接口" min-width="240">
                      <template #default="{ row }">
                        <span class="log-endpoint">
                          <em>{{ row.method || logMethodText(row.action) }}</em>
                          <strong :title="row.path || row.action">{{ row.path || row.action || '-' }}</strong>
                        </span>
                      </template>
                    </el-table-column>
                    <el-table-column label="HTTP" width="96">
                      <template #default="{ row }">
                        <el-tag :type="logStatusTagType(row.status_code)">
                          {{ row.status_code || '-' }}
                        </el-tag>
                      </template>
                    </el-table-column>
                    <el-table-column label="业务码" width="96">
                      <template #default="{ row }">
                        <el-tag :type="Number(row.response_code) === 0 ? 'success' : 'warning'">
                          {{ row.response_code ?? '-' }}
                        </el-tag>
                      </template>
                    </el-table-column>
                    <el-table-column label="耗时" width="98">
                      <template #default="{ row }">
                        {{ logDurationText(row.duration_ms) }}
                      </template>
                    </el-table-column>
                    <el-table-column prop="ip" label="IP" width="140" />
                    <el-table-column label="响应消息" min-width="220">
                      <template #default="{ row }">
                        <span class="log-payload" :title="row.response_message || row.error || '-'">
                          {{ row.response_message || row.error || '-' }}
                        </span>
                      </template>
                    </el-table-column>
                    <el-table-column label="请求摘要" min-width="240">
                      <template #default="{ row }">
                        <span class="log-payload" :title="payloadText(row.payload)">{{ payloadText(row.payload) }}</span>
                      </template>
                    </el-table-column>
                    <el-table-column prop="source_table" label="分表" width="138" />
                  </el-table>
                  <div class="file-pagination">
                    <span>共 {{ logState.pagination.total }} 条日志，{{ logState.tables.length }} 个分表</span>
                    <el-pagination
                      size="small"
                      layout="prev, pager, next"
                      :current-page="logState.pagination.page"
                      :page-size="logState.pagination.page_size"
                      :total="logState.pagination.total"
                      @current-change="loadLogs"
                    />
                  </div>
                  <small v-if="logState.message">{{ logState.message }}</small>
                </div>

                <div v-else-if="win.module.id === 'stat'" class="admin-panel stat-panel">
                  <section class="stat-report-content">
                    <header>
                      <div>
                        <strong>{{ currentStatReport.name }}</strong>
                        <small>{{ currentStatReport.description }}</small>
                      </div>
                      <small>{{ statState.generated_at ? `生成时间 ${statState.generated_at}` : '等待生成' }}</small>
                    </header>
                    <div class="stat-filter-bar">
                      <!-- 暂时隐藏学期筛选，后续需要时恢复。 -->
                      <!--
                      <label>
                        <span>学期</span>
                        <el-select v-model="statState.filters.semester" clearable filterable placeholder="全部">
                          <el-option v-for="item in semesterOptions()" :key="item.value" :label="item.label" :value="item.value" />
                        </el-select>
                      </label>
                      -->
                      <label :class="{ 'filter-active': hasFilterValue(statState.filters.grade_id) }">
                        <span>届次</span>
                        <el-select v-model="statState.filters.grade_id" class="filter-select" :class="{ 'is-filter-active': hasFilterValue(statState.filters.grade_id) }" clearable filterable placeholder="全部" @change="handleStatFilterChange('grade_id')">
                          <el-option v-for="item in statGradeOptions()" :key="item.value" :label="item.label" :value="item.value" />
                        </el-select>
                      </label>
                      <label :class="{ 'filter-active': hasFilterValue(statState.filters.dep_id) }">
                        <span>学院</span>
                        <el-select v-model="statState.filters.dep_id" class="filter-select" :class="{ 'is-filter-active': hasFilterValue(statState.filters.dep_id) }" clearable filterable placeholder="全部" @change="handleStatFilterChange('dep_id')">
                          <el-option v-for="item in statDepartmentOptions()" :key="item.value" :label="item.label" :value="item.value" />
                        </el-select>
                      </label>
                      <label :class="{ 'filter-active': hasFilterValue(statState.filters.profession_id) }">
                        <span>专业</span>
                        <el-select v-model="statState.filters.profession_id" class="filter-select" :class="{ 'is-filter-active': hasFilterValue(statState.filters.profession_id) }" clearable filterable placeholder="全部" @change="handleStatFilterChange('profession_id')">
                          <el-option v-for="item in statProfessionOptions()" :key="item.value" :label="item.label" :value="item.value" />
                        </el-select>
                      </label>
                      <label :class="{ 'filter-active': hasFilterValue(statState.filters.class_id) }">
                        <span>班级</span>
                        <el-select v-model="statState.filters.class_id" class="filter-select" :class="{ 'is-filter-active': hasFilterValue(statState.filters.class_id) }" clearable filterable placeholder="全部" @change="handleStatFilterChange('class_id')">
                          <el-option v-for="item in statClassOptions()" :key="item.value" :label="item.label" :value="item.value" />
                        </el-select>
                      </label>
                      <label v-if="isPracticeScoreSheetReport()" :class="{ 'filter-active': hasFilterValue(statState.filters.module_type) }">
                        <span>模块</span>
                        <el-select v-model="statState.filters.module_type" class="filter-select" :class="{ 'is-filter-active': hasFilterValue(statState.filters.module_type) }" filterable placeholder="实训" @change="handleStatFilterChange('module_type')">
                          <el-option label="实训" value="training" />
                          <el-option label="实验" value="lab" />
                        </el-select>
                      </label>
                      <label v-if="isPracticeScoreSheetReport()" :class="{ 'filter-active': hasFilterValue(statState.filters.plan_id) }">
                        <span>教学计划</span>
                        <el-select v-model="statState.filters.plan_id" class="filter-select" :class="{ 'is-filter-active': hasFilterValue(statState.filters.plan_id) }" clearable filterable placeholder="全部计划" @change="handleStatFilterChange('plan_id')">
                          <el-option v-for="item in practiceScoreSheetPlanOptions()" :key="item.value" :label="item.label" :value="item.value" />
                        </el-select>
                      </label>
                      <label v-if="isPracticeScoreSheetReport()" :class="{ 'filter-active': hasFilterValue(statState.filters.academic_year) }">
                        <span>学年</span>
                        <input v-model="statState.filters.academic_year" placeholder="如 2025-2026">
                      </label>
                      <label :class="{ 'filter-active': hasFilterValue(statState.filters.keyword) }">
                        <span>关键词</span>
                        <input v-model="statState.filters.keyword" placeholder="学生、学号、基地、教师">
                      </label>
                      <div class="stat-filter-actions">
                        <el-button :icon="Search" :loading="statState.loading" @click="loadStats(1)">
                          查询
                        </el-button>
                        <el-button :icon="RefreshCw" :loading="statState.loading" @click="resetStatFilters">
                          重置
                        </el-button>
                      </div>
                    </div>
                    <el-alert
                      v-if="statState.message"
                      type="warning"
                      :closable="false"
                      show-icon
                      :title="statState.message"
                    />
                    <div class="stat-grid">
                      <section v-for="item in statCards" :key="item.name" class="stat-card">
                        <component :is="item.icon" :size="22" />
                        <strong>{{ item.value }}</strong>
                        <span>{{ item.name }}</span>
                        <small>{{ item.desc }}</small>
                      </section>
                    </div>
                    <section class="stat-detail-panel">
                      <section v-if="isPracticeScoreSheetReport()" class="course-score-sheet" v-loading="statState.loading">
                        <header>
                          <strong>成都锦城学院{{ statState.sheet_meta.title || currentStatReport.name }}</strong>
                          <small>{{ statState.sheet_meta.module_name || '实训' }}成绩记载 / 共 {{ statState.pagination.total }} 人</small>
                        </header>
                        <div class="course-score-meta">
                          <span v-for="item in courseScoreSheetMetaItems" :key="item.key">
                            <em>{{ item.label }}</em>
                            <strong>{{ item.value || '-' }}</strong>
                          </span>
                        </div>
                        <div class="course-score-scroll">
                          <table class="course-score-table">
                            <thead>
                              <tr>
                                <th rowspan="2">序号</th>
                                <th rowspan="2">学号</th>
                                <th rowspan="2">姓名</th>
                                <th rowspan="2">行政班级</th>
                                <th :colspan="scoreSheetAttendanceIndexes.length + 1">考勤与课堂表现（占20%）</th>
                                <th :colspan="scoreSheetProjectIndexes.length">项目（实操）成绩（占70%）</th>
                                <th rowspan="2">课程报告<br>10%</th>
                                <th rowspan="2">总分</th>
                              </tr>
                              <tr>
                                <th v-for="index in scoreSheetAttendanceIndexes" :key="`att-${index}`">{{ index }}</th>
                                <th>小计</th>
                                <th v-for="index in scoreSheetProjectIndexes" :key="`project-${index}`">{{ index }}</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr v-for="row in statState.rows" :key="row.student_id || row.sequence">
                                <td>{{ statCellText(row.sequence) }}</td>
                                <td>{{ statCellText(row.student_num) }}</td>
                                <td>{{ statCellText(row.student_name) }}</td>
                                <td>{{ statCellText(row.class_name) }}</td>
                                <td v-for="index in scoreSheetAttendanceIndexes" :key="`row-att-${row.sequence}-${index}`">{{ scoreSheetCell(row, `attendance_${index}`) }}</td>
                                <td>{{ scoreSheetCell(row, 'attendance_total') }}</td>
                                <td v-for="index in scoreSheetProjectIndexes" :key="`row-project-${row.sequence}-${index}`">{{ scoreSheetCell(row, `project_${index}`) }}</td>
                                <td>{{ scoreSheetCell(row, 'report_score') }}</td>
                                <td>{{ scoreSheetCell(row, 'total_score') }}</td>
                              </tr>
                              <tr v-if="!statState.rows.length">
                                <td :colspan="currentStatColumns.length || 35">暂无成绩记载数据</td>
                              </tr>
                            </tbody>
                          </table>
                        </div>
                        <footer>
                          <span>注：考勤与课堂表现、项目实操、课程报告按成绩规则记录，空白表示未录入。</span>
                          <span>生成时间：{{ statState.generated_at || '-' }}</span>
                        </footer>
                      </section>
                      <el-table v-else :data="statState.rows" height="100%" stripe v-loading="statState.loading">
                        <el-table-column
                          v-for="column in currentStatColumns"
                          :key="column.key"
                          :label="column.label"
                          :prop="column.key"
                          :width="column.width"
                          :min-width="column.min_width"
                          show-overflow-tooltip
                        >
                          <template #default="{ row }">
                            <el-tag v-if="column.type === 'status'" :type="statusTagType(row[column.key])">
                              {{ statusText(row[column.key]) }}
                            </el-tag>
                            <span v-else>{{ statCellText(row[column.key]) }}</span>
                          </template>
                        </el-table-column>
                      </el-table>
                      <div class="file-pagination">
                        <span>共 {{ statState.pagination.total }} 条，生成时间 {{ statState.generated_at || '-' }}</span>
                        <el-pagination
                          size="small"
                          layout="prev, pager, next"
                          :current-page="statState.pagination.page"
                          :page-size="statState.pagination.page_size"
                          :total="statState.pagination.total"
                          @current-change="loadStats"
                        />
                      </div>
                    </section>
                  </section>
                </div>

                <div v-else-if="win.module.id === 'config' && win.panel === 'wechatProxy'" class="admin-panel wechat-config-panel">
                  <div class="admin-toolbar">
                    <el-button type="primary" :icon="Save" :loading="wechatProxy.loading" :disabled="!hasPermission('wechat:proxy:save')" @click="saveProxy">
                      保存配置
                    </el-button>
                    <el-button :icon="RefreshCw" :loading="wechatProxy.loading" @click="loadProxy">
                      重新读取
                    </el-button>
                    <el-button :icon="Plus" :disabled="wechatProxy.menu.length >= 3" @click="addWechatMenu()">
                      一级菜单
                    </el-button>
                    <el-button :icon="ListTree" :disabled="wechatProxy.selectedMenuIndex < 0 || (wechatProxy.menu[wechatProxy.selectedMenuIndex]?.children || []).length >= 5" @click="addWechatMenu(wechatProxy.selectedMenuIndex)">
                      子菜单
                    </el-button>
                    <el-button :icon="Trash2" :disabled="wechatProxy.selectedMenuIndex < 0" @click="removeSelectedWechatMenu">
                      删除菜单
                    </el-button>
                  </div>
                  <div class="wechat-config-layout">
                    <section class="settings-form wechat-app-form">
                      <label><span>应用 AppID</span><input v-model="wechatProxy.app_id" placeholder="第三方应用或自建应用标识"></label>
                      <label><span>企业 ID</span><input v-model="wechatProxy.corp_id" placeholder="wwxxxxxxxx"></label>
                      <label><span>AgentId</span><input v-model="wechatProxy.agent_id" placeholder="1000002"></label>
                      <label><span>应用 Secret</span><input v-model="wechatProxy.secret" placeholder="企业微信应用 Secret"></label>
                      <label><span>回调 Token</span><input v-model="wechatProxy.token"></label>
                      <label><span>EncodingAESKey</span><input v-model="wechatProxy.encoding_aes_key"></label>
                      <label><span>代理地址</span><input v-model="wechatProxy.proxy_url" placeholder="http://127.0.0.1:9000/wechat-proxy"></label>
                      <label>
                        <span>启用代理</span>
                        <el-switch
                          v-model="wechatProxy.proxy_enabled"
                          active-text="启用"
                          inactive-text="停用"
                        />
                      </label>
                    </section>
                    <section class="wechat-menu-editor">
                      <header>
                        <strong>应用菜单</strong>
                        <small>一级最多 3 个，每个一级菜单最多 5 个子菜单</small>
                      </header>
                      <div class="wechat-menu-board">
                        <button
                          v-for="(menu, index) in wechatProxy.menu"
                          :key="`main-${index}`"
                          type="button"
                          :class="{ active: wechatProxy.selectedMenuIndex === index && wechatProxy.selectedSubMenuIndex < 0 }"
                          @click="selectWechatMenu(index)"
                        >
                          {{ menu.name || `菜单 ${index + 1}` }}
                        </button>
                      </div>
                      <div v-if="wechatProxy.selectedMenuIndex >= 0" class="wechat-submenu-board">
                        <button
                          v-for="(menu, index) in wechatProxy.menu[wechatProxy.selectedMenuIndex]?.children || []"
                          :key="`sub-${index}`"
                          type="button"
                          :class="{ active: wechatProxy.selectedSubMenuIndex === index }"
                          @click="selectWechatMenu(wechatProxy.selectedMenuIndex, index)"
                        >
                          {{ menu.name || `子菜单 ${index + 1}` }}
                        </button>
                      </div>
                      <section v-if="selectedWechatMenu" class="menu-form wechat-menu-form">
                        <label><span>菜单名称</span><input v-model="selectedWechatMenu.name"></label>
                        <label>
                          <span>菜单类型</span>
                          <el-select v-model="selectedWechatMenu.type">
                            <el-option label="跳转网页" value="view" />
                            <el-option label="点击事件" value="click" />
                            <el-option label="小程序" value="miniprogram" />
                          </el-select>
                        </label>
                        <label v-if="selectedWechatMenu.type === 'view' || selectedWechatMenu.type === 'miniprogram'"><span>URL</span><input v-model="selectedWechatMenu.url"></label>
                        <label v-if="selectedWechatMenu.type === 'click'"><span>Key</span><input v-model="selectedWechatMenu.key"></label>
                        <label v-if="selectedWechatMenu.type === 'miniprogram'"><span>AppID</span><input v-model="selectedWechatMenu.appid"></label>
                        <label v-if="selectedWechatMenu.type === 'miniprogram'"><span>页面路径</span><input v-model="selectedWechatMenu.pagepath"></label>
                      </section>
                      <small v-else>请先新增或选择菜单。</small>
                    </section>
                  </div>
                  <small v-if="wechatProxy.message">{{ wechatProxy.message }}</small>
                </div>

                <div v-else class="module-empty-state">
                  <strong>功能未开放</strong>
                  <span>请选择左侧已开放的功能菜单。</span>
                </div>
              </section>

            </div>
          </section>
        </div>
      </DesktopWindow>
    </section>

    <div
      v-if="desktopContextMenu.visible"
      class="desktop-context-menu"
      :style="{ left: `${desktopContextMenu.x}px`, top: `${desktopContextMenu.y}px` }"
      @click.stop
      @contextmenu.prevent
    >
      <button type="button" @click="openWallpaperSettings">
        <ImagePlus :size="16" />
        <span>更换壁纸</span>
      </button>
      <button type="button" @click="openProfile">
        <UserRound :size="16" />
        <span>个人设置</span>
      </button>
    </div>

    <div v-if="guideState.visible" class="operation-mask" @click.self="closeGuide">
      <section class="operation-dialog guide-dialog">
        <header>
          <strong>{{ guideState.title }}</strong>
          <button type="button" @click="closeGuide">关闭</button>
        </header>
        <div class="guide-content rich-content">
          <el-alert v-if="guideState.message" type="warning" :closable="false" :title="guideState.message" />
          <div v-if="guideState.loading" class="guide-loading">正在读取操作说明...</div>
          <article v-else v-html="guideState.content" />
        </div>
        <footer>
          <el-button type="primary" @click="closeGuide">知道了</el-button>
        </footer>
      </section>
    </div>

    <DesktopLauncher
      v-model:keyword="desktopLauncherState.keyword"
      :visible="desktopLauncherState.visible"
      :modules="launcherFilteredModules"
      :loading="desktopLauncherState.loading"
      :message="desktopLauncherState.message"
      :backend-url="backendUrl"
      :is-desktop-shortcut="isDesktopShortcut"
      :is-default-desktop-shortcut="isDefaultDesktopShortcut"
      :shortcut-title="launcherShortcutTitle"
      @close="closeDesktopLauncher"
      @open="openLauncherModule"
      @toggle="toggleDesktopShortcut"
    />

    <footer class="taskbar">
      <button class="taskbar-icon-button" title="开始" aria-label="开始" @click="openDesktopLauncher">
        <LayoutGrid :size="18" />
      </button>
      <div class="taskbar-apps">
        <a
          v-for="win in openWindows"
          :key="win.id"
          :href="windowHref(win)"
          :title="win.module.name"
          :aria-label="win.module.name"
          :class="{ active: focusedWindowId === win.id && !win.minimized }"
          @click="toggleTaskWindow(win.id)"
        >
          <AppIcon class="taskbar-glyph" :icon="win.module.icon" :icon-url="win.module.iconUrl" :label="win.module.name" :color="win.module.color" :size="17" :backend-url="backendUrl" />
        </a>
        <button
          class="taskbar-icon-button message-taskbar-button"
          :class="{ active: isModuleFocused('message') }"
          title="消息中心"
          aria-label="消息中心"
          @click="openMessageCenter"
        >
          <MessageCircle :size="18" />
          <i v-if="messageUnreadCount > 0">{{ messageUnreadCount > 99 ? '99+' : messageUnreadCount }}</i>
        </button>
      </div>
      <span>在线</span>
    </footer>
    <input
      ref="archiveImportInputRef"
      type="file"
      accept=".xls,.xlsx"
      hidden
      @change="handleArchiveImportFile"
    >
    <input
      ref="arrangementImportInputRef"
      type="file"
      accept=".xls,.xlsx"
      hidden
      @change="handleArrangementImportFile"
    >
  </main>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import {
  Bell,
  BookOpen,
  BriefcaseBusiness,
  Building2,
  CalendarCheck,
  ChartColumn,
  CheckCircle2,
  ClipboardList,
  Edit3,
  FileClock,
  FileText,
  FlaskConical,
  FolderOpen,
  Globe2,
  GraduationCap,
  HardDrive,
  ImagePlus,
  List,
  LayoutGrid,
  ListTree,
  LogIn,
  LogOut,
  MapPin,
  MessageCircle,
  MoreHorizontal,
  Network,
  Plus,
  RefreshCw,
  Save,
  Search,
  Settings,
  ShieldCheck,
  SlidersHorizontal,
  Star,
  Table2,
  Trash2,
  Upload,
  UsersRound,
  UserRound,
  Workflow,
} from '@lucide/vue';
import DesktopWindow from './components/DesktopWindow.vue';
import DesktopLauncher from './components/DesktopLauncher.vue';
import AppIcon from './components/AppIcon.vue';
import DataListPanel from './components/DataListPanel.vue';
import DocCenter from './components/DocCenter.vue';
import ExportTaskCenter from './components/ExportTaskCenter.vue';
import IconUpload from './components/IconUpload.vue';
import MenuEditDialog from './components/MenuEditDialog.vue';
import OperationDialog from './components/OperationDialog.vue';
import ShortcutTile from './components/ShortcutTile.vue';
import StudentOwnPanel from './components/StudentOwnPanel.vue';
import TemplateLibrary from './components/TemplateLibrary.vue';
import { DEFAULT_DESKTOP_MODULE_IDS, useDesktopLauncher } from './composables/useDesktopLauncher';
import { usePermissions } from './composables/usePermissions';
import { buildLaunchableMenuModules, mergeLaunchableModules as mergeMenuModules } from './utils/menuModules';
import { backendUrl } from './api/client';
import {
  changeAdminAccountStatus,
  clearTestData,
  deleteArchiveItem,
  deleteFavorite,
  fetchAdminAccountDetail,
  fetchAdminAccounts,
  fetchAdminMenus,
  fetchAdminOptions,
  fetchAdminRoles,
  fetchArchiveList,
  fetchDesktopShortcuts,
  fetchFavorites,
  fetchFileList,
  fetchSwitchableAccounts,
  fetchInternshipArchiveMaterials,
  fetchInternshipApplications,
  fetchInternshipArrangementChanges,
  fetchInternshipArrangementDetail,
  fetchInternshipArrangements,
  fetchInternshipDelays,
  fetchInternshipBaseFlows,
  fetchInternshipCourseScores,
  fetchInternshipInsurances,
  fetchInternshipJournals,
  fetchInternshipOptions,
  fetchInternshipOverview,
  fetchInternshipPairs,
  fetchInternshipPlans,
  fetchInternshipReports,
  fetchInternshipReviewDraft,
  fetchInternshipSafetyLetters,
  fetchInternshipScores,
  fetchInternshipSignIns,
  fetchInternshipStats,
  fetchInternshipSyllabusGuides,
  fetchInternshipImplementationSheets,
  fetchInternshipTeacherWorkReports,
  fetchInternshipInspections,
  fetchInternshipTimeline,
  fetchLoginPageSettings,
  fetchMessages,
  fetchMessageTargets,
  fetchMessageTemplates,
  fetchMessageSummary,
  fetchOrganizationScopes,
  fetchOperationGuide,
  fetchOperationGuides,
  fetchOperationLogs,
  fetchPracticeList,
  fetchPracticeOptions,
  fetchPracticeOverview,
  fetchPracticeExecutionList,
  fetchPracticeExecutionTimeline,
  fetchPracticeReviewDraft,
  fetchPracticeTimeline,
  fetchProfileSettings,
  fetchRolePermissions,
  fetchWechatConfig,
  generateAdminLoginPasskey,
  importArchiveExcel,
  importInternshipArrangementAssignments,
  deleteMenu as deleteMenuApi,
  deleteOperationGuide,
  login as loginApi,
  logout as logoutApi,
  markMessagesRead,
  passkeyLogin,
  fetchRegisterOptions,
  registerAccount,
  reviewInternshipBaseFlow,
  reviewInternshipApplication,
  reviewInternshipArrangementChange,
  reviewInternshipDelay,
  reviewInternshipJournal,
  reviewInternshipPlan,
  reviewInternshipReport,
  requestInternshipModification,
  requestPracticeExecutionModification,
  requestPracticeModification,
  reviewPracticeExecution,
  resetAdminAccountPassword,
  reviewPracticeItem,
  saveArchiveItem,
  saveAdminAccount,
  saveInternshipArrangement,
  saveInternshipArrangementChange,
  saveInternshipBaseFlow,
  saveInternshipCourseScore,
  saveInternshipPlan,
  saveInternshipReviewDraft,
  saveInternshipScore,
  savePracticeExecution,
  savePracticeItem,
  savePracticeReviewDraft,
  savePracticeProjectScore,
  saveDesktopShortcuts,
  saveFavorite,
  saveMenu as saveMenuApi,
  saveMessageTemplate,
  sendMessage,
  syncMessageTemplates,
  saveOrganizationScopes,
  saveOperationGuide,
  saveProfileSettings,
  saveRoleMenus,
  saveWechatConfig,
  switchAccount,
  uploadFavoriteIcon,
  uploadLoginBackground,
  uploadMenuIcon,
  uploadProfileAsset,
  deleteMessageTemplate,
} from './api/system';

const { state: permissionState, hasPermission, load } = usePermissions();
const keyword = ref('');
const clock = ref('');
const loginNameInput = ref(null);
const wallpaperSectionRef = ref(null);
const menuTreeRef = ref(null);
const archiveImportInputRef = ref(null);
const archiveImportType = ref('');
const arrangementImportInputRef = ref(null);
const focusedWindowId = ref(null);
const zIndexSeed = ref(20);
const wallpaperCacheKey = 'practical_pc_wallpaper';
const loginBackgroundMaxSize = 8 * 1024 * 1024;
const imageAssetTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
const iconRegistry = {
  Bell,
  BookOpen,
  BriefcaseBusiness,
  Building2,
  CalendarCheck,
  ChartColumn,
  CheckCircle2,
  ClipboardList,
  Edit3,
  FileClock,
  FileText,
  FlaskConical,
  FolderOpen,
  Globe2,
  GraduationCap,
  HardDrive,
  ImagePlus,
  List,
  LayoutGrid,
  ListTree,
  MapPin,
  MessageCircle,
  MoreHorizontal,
  Network,
  Plus,
  RefreshCw,
  Save,
  Search,
  Settings,
  ShieldCheck,
  SlidersHorizontal,
  Star,
  Table2,
  Trash2,
  Upload,
  UsersRound,
  UserRound,
  Workflow,
};
const loginForm = reactive({
  login_name: '',
  password: '',
});
const loginState = reactive({
  loading: false,
  message: '',
});
const registerForm = reactive({
  role_type: 'student',
  name: '',
  login_name: '',
  password: '',
  mobile: '',
  email: '',
  student_num: '',
  teacher_num: '',
});
const registerState = reactive({
  enabled: false,
  open: false,
  loading: false,
  message: '',
  roles: [],
});
const switchAccountState = reactive({
  loading: false,
  open: false,
  items: [],
  message: '',
});
const desktopLauncher = useDesktopLauncher({
  allModules: computed(() => allLaunchableModules.value),
  favoriteItems: computed(() => favoriteState.items),
  defaultModuleIds: DEFAULT_DESKTOP_MODULE_IDS,
  searchText: moduleSearchText,
});
const desktopLauncherState = desktopLauncher.state;
const loginPageState = reactive({
  loading: false,
  message: '',
  school_name: '成都锦城学院',
  login_background_url: '',
});
const wechatProxy = reactive({
  app_id: '',
  corp_id: '',
  agent_id: '',
  secret: '',
  token: '',
  encoding_aes_key: '',
  proxy_url: '',
  proxy_enabled: false,
  menu: [],
  selectedMenuIndex: -1,
  selectedSubMenuIndex: -1,
  loading: false,
  message: '',
});
const logState = reactive({
  loading: false,
  message: '',
  filters: {
    keyword: '',
    action: '',
    ip: '',
    date_from: '',
    date_to: '',
  },
  items: [],
  tables: [],
  pagination: {
    page: 1,
    page_size: 20,
    total: 0,
  },
});
const messageState = reactive({
  loading: false,
  targetLoading: false,
  templateLoading: false,
  templateSyncing: false,
  templateDefaultSynced: false,
  message: '',
  filters: {
    type: 'all',
    status: 'all',
    keyword: '',
  },
  items: [],
  targetOptions: [],
  templates: [],
  templateFilters: {
    type: 'all',
    status: 'all',
    keyword: '',
  },
  templatePagination: {
    page: 1,
    page_size: 100,
    total: 0,
  },
  templateManager: {
    visible: false,
  },
  templateEdit: {
    visible: false,
    saving: false,
    form: emptyMessageTemplateForm(),
  },
  summary: {
    unread: 0,
    by_type: {},
  },
  pagination: {
    page: 1,
    page_size: 20,
    total: 0,
  },
  sendDialog: {
    visible: false,
    sending: false,
    filters: {
      keyword: '',
      role_type: '',
    },
    form: emptyMessageSendForm(),
  },
});
const statState = reactive({
  report: 'overview',
  filters: {
    semester: '',
    dep_id: '',
    profession_id: '',
    grade_id: '',
    class_id: '',
    module_type: 'training',
    plan_id: '',
    academic_year: '',
    keyword: '',
  },
  cards: [],
  columns: [],
  rows: [],
  sheet_meta: {},
  pagination: {
    page: 1,
    page_size: 20,
    total: 0,
  },
  generated_at: '',
  loading: false,
  message: '',
});
const profileState = reactive({
  loading: false,
  message: '',
  saved: false,
  focus: '',
  form: emptyProfile(),
});
const desktopContextMenu = reactive({
  visible: false,
  x: 0,
  y: 0,
});
const guideState = reactive({
  visible: false,
  title: '',
  content: '',
  loading: false,
  message: '',
});
const guideAdminState = reactive({
  items: [],
  selected: null,
  editing: emptyGuideForm(),
  dialogVisible: false,
  dialogMode: 'create',
  loading: false,
  message: '',
});
const guideEditorRef = ref(null);
const roleTreeRef = ref(null);
const treeProps = {
  label: 'name',
  children: 'children',
};
const adminState = reactive({
  loading: false,
  roles: [],
  menus: [],
  menu: {
    items: [],
    editing: emptyMenu(),
    selected: null,
    keyword: '',
    expanded: true,
    expandedKeys: [],
    treeKey: 0,
    dialogVisible: false,
    dialogMode: 'create',
    loading: false,
    iconUploading: false,
    message: '',
  },
  roleMenus: {
    role_id: null,
    menu_ids: [],
    permissions: [],
    loading: false,
    message: '',
  },
  options: {
    accounts: [],
    departments: [],
    grades: [],
    professions: [],
    classes: [],
    companies: [],
  },
  scope: {
    account_id: null,
    role_id: null,
    scopes: [],
    loading: false,
    message: '',
  },
});
const userAdminState = reactive({
  items: [],
  filters: {
    keyword: '',
    role_type: '',
    status: 'all',
  },
  editing: emptyUserForm(),
  dialogVisible: false,
  passwordDialogVisible: false,
  passwordForm: {
    id: null,
    name: '',
    password: '',
  },
  detailDialogVisible: false,
  detailMode: 'logs',
  detailLoading: false,
  detail: emptyUserDetail(),
  detailPagination: {
    page: 1,
    page_size: 20,
    total: 0,
  },
  passkeyLoadingId: null,
  passkeyUrl: '',
  pagination: {
    page: 1,
    page_size: 20,
    total: 0,
  },
  loading: false,
  message: '',
});

const modules = [
  {
    id: 'internship',
    name: '实习管理',
    icon: BriefcaseBusiness,
    color: 'blue',
    scope: '学院 / 专业 / 任务绑定',
    viewPermission: 'internship:view',
    managePermission: 'internship:manage',
  },
  {
    id: 'training',
    name: '实训管理',
    icon: Workflow,
    color: 'teal',
    scope: '实训模块入口',
    viewPermission: 'training:view',
    managePermission: 'training:manage',
    defaultPanel: 'overview',
  },
  {
    id: 'lab',
    name: '实验管理',
    icon: FlaskConical,
    color: 'green',
    scope: '实验模块入口',
    viewPermission: 'lab:view',
    managePermission: 'lab:manage',
    defaultPanel: 'overview',
  },
  {
    id: 'stat',
    name: '统计报表',
    icon: ChartColumn,
    color: 'amber',
    scope: '按数据范围聚合',
    viewPermission: 'stat:view',
    managePermission: 'stat:manage',
  },
  {
    id: 'log',
    name: '日志审计',
    icon: FileClock,
    color: 'red',
    scope: '学校日志',
    viewPermission: 'log:view',
    managePermission: 'log:manage',
  },
  {
    id: 'file',
    name: '文件管理',
    icon: FolderOpen,
    color: 'blue',
    scope: '学校文件库',
    viewPermission: 'file:view',
    managePermission: 'file:manage',
    defaultPanel: 'fileManage',
  },
  {
    id: 'doc',
    name: '文档中心',
    icon: BookOpen,
    color: 'green',
    scope: '制度 / 流程 / 帮助文档',
    viewPermission: 'doc:view',
    managePermission: 'doc:manage',
    defaultPanel: 'docList',
  },
  {
    id: 'templateLib',
    name: '模板库',
    icon: FileText,
    color: 'teal',
    scope: '流程消息模板 / 材料模板',
    viewPermission: 'template:view',
    managePermission: 'template:manage',
    defaultPanel: 'templateList',
  },
  {
    id: 'exportTask',
    name: '导出任务',
    icon: FileClock,
    color: 'amber',
    scope: '导出队列 / 下载记录',
    viewPermission: 'export:view',
    managePermission: 'export:create',
    defaultPanel: 'taskList',
  },
  {
    id: 'favorite',
    name: '收藏夹',
    icon: Star,
    color: 'blue',
    scope: '常用网址 / 桌面快捷方式',
    viewPermission: '',
    managePermission: '',
    defaultPanel: 'favoriteList',
  },
  {
    id: 'userManage',
    name: '用户管理',
    icon: UsersRound,
    color: 'blue',
    scope: '学校账号',
    viewPermission: 'config:user',
    managePermission: 'config:manage',
    defaultPanel: 'userManage',
    adminOnly: true,
  },
  {
    id: 'gradeManage',
    name: '届次管理',
    icon: GraduationCap,
    color: 'amber',
    scope: '届次基础档案',
    viewPermission: 'config:grade',
    managePermission: 'config:manage',
    defaultPanel: 'gradeManage',
    adminOnly: true,
  },
  {
    id: 'departmentManage',
    name: '学院管理',
    icon: Building2,
    color: 'green',
    scope: '学院基础档案',
    viewPermission: 'config:department',
    managePermission: 'config:manage',
    defaultPanel: 'departmentManage',
    adminOnly: true,
  },
  {
    id: 'professionManage',
    name: '专业管理',
    icon: GraduationCap,
    color: 'teal',
    scope: '专业基础档案',
    viewPermission: 'config:profession',
    managePermission: 'config:manage',
    defaultPanel: 'professionManage',
    adminOnly: true,
  },
  {
    id: 'classManage',
    name: '班级管理',
    icon: UsersRound,
    color: 'gray',
    scope: '班级基础档案',
    viewPermission: 'config:class',
    managePermission: 'config:manage',
    defaultPanel: 'classManage',
    adminOnly: true,
  },
  {
    id: 'companyManage',
    name: '基地管理',
    icon: Building2,
    color: 'amber',
    scope: '基地相关单位档案',
    viewPermission: 'config:company',
    managePermission: 'config:manage',
    defaultPanel: 'companyManage',
    adminOnly: true,
  },
  {
    id: 'dataManage',
    name: '数据管理',
    icon: HardDrive,
    color: 'red',
    scope: '测试数据清理',
    viewPermission: 'config:view',
    managePermission: 'config:manage',
    defaultPanel: 'dataManage',
    adminOnly: true,
  },
  {
    id: 'config',
    name: '系统配置',
    icon: Settings,
    color: 'gray',
    scope: '学校设置',
    viewPermission: 'config:view',
    managePermission: 'config:manage',
    defaultPanel: 'menuManage',
  },
  {
    id: 'profile',
    name: '个人设置',
    icon: UserRound,
    color: 'green',
    scope: '个人资料 / 桌面偏好 / 消息接收',
    viewPermission: '',
    managePermission: '',
  },
];

const messageModule = {
  id: 'message',
  name: '消息中心',
  icon: MessageCircle,
  color: 'teal',
  scope: '站内消息 / 待办提醒 / 审核结果',
  defaultPanel: 'inbox',
};

const wallpaperPresets = [
  {
    key: 'default',
    name: '清爽蓝绿',
    background: 'linear-gradient(135deg, rgba(31, 115, 210, .18), transparent 36%), linear-gradient(225deg, rgba(15, 143, 126, .18), transparent 38%), #eef3f8',
  },
  {
    key: 'light',
    name: '浅灰办公',
    background: 'linear-gradient(135deg, rgba(83, 96, 111, .14), transparent 34%), #f4f7fb',
  },
  {
    key: 'campus',
    name: '校园绿',
    background: 'linear-gradient(135deg, rgba(46, 125, 79, .20), transparent 34%), linear-gradient(225deg, rgba(185, 109, 8, .13), transparent 36%), #edf6f1',
  },
  {
    key: 'warm',
    name: '暖光',
    background: 'linear-gradient(135deg, rgba(185, 109, 8, .16), transparent 32%), linear-gradient(225deg, rgba(199, 67, 80, .10), transparent 34%), #f7f3ec',
  },
];
const roleTypeNames = {
  super_admin: '超级管理员',
  school_admin: '学校管理员',
  college_admin: '学院管理员',
  profession_admin: '专业管理员',
  teacher: '教师',
  student: '学生',
  enterprise: '企业用户',
};
const messageTypeOptions = [
  { label: '全部类型', value: 'all' },
  { label: '系统通知', value: 'system' },
  { label: '待办提醒', value: 'todo' },
  { label: '处理结果', value: 'result' },
  { label: '预警提醒', value: 'alert' },
];
const messageTemplateTypeOptions = messageTypeOptions;
const messageStatusOptions = [
  { label: '全部消息', value: 'all' },
  { label: '未读消息', value: 'unread' },
  { label: '已读消息', value: 'read' },
];
const messageTypeNames = Object.fromEntries(messageTypeOptions.map(item => [item.value, item.label.replace('全部类型', '全部')]));
const messageLevelNames = {
  normal: '普通',
  important: '重要',
  urgent: '紧急',
};
const messageLevelOptions = [
  { label: '普通', value: 'normal' },
  { label: '重要', value: 'important' },
  { label: '紧急', value: 'urgent' },
];
const messageTargetRoleOptions = [
  { label: '学生', value: 'student' },
  { label: '教师', value: 'teacher' },
  { label: '专业管理员', value: 'profession_admin' },
  { label: '学院管理员', value: 'college_admin' },
  { label: '学校管理员', value: 'school_admin' },
  { label: '超级管理员', value: 'super_admin' },
];

function emptyMessageSendForm() {
  return {
    send_mode: 'manual',
    template_code: '',
    variables_text: '{}',
    send_scope: 'custom',
    role_type: '',
    account_ids: [],
    type: 'system',
    level: 'normal',
    title: '',
    content: '',
    link_url: '',
  };
}

function emptyMessageTemplateForm(row = {}) {
  return {
    id: row.id || null,
    name: row.name || '',
    code: row.code || '',
    title_tpl: row.title_tpl || '',
    content_tpl: row.content_tpl || '',
    type: row.type || 'system',
    level: row.level || 'normal',
    description: row.description || '',
    variables_text: JSON.stringify(row.variables || {}, null, 2),
    link_url_tpl: row.link_url_tpl || '',
    status: row.status || 'enabled',
    sort: row.sort ?? 100,
    is_system: Boolean(row.is_system),
  };
}

const archiveDefinitions = [
  {
    type: 'department',
    name: '学院',
    idField: 'dep_id',
    fields: [
      { key: 'dep_name', label: '学院名称', required: true },
      { key: 'dep_short_name', label: '学院简称' },
      { key: 'dep_code', label: '学院代码' },
      { key: 'sort', label: '排序', inputType: 'number' },
      { key: 'flag', label: '状态', options: 'flag' },
    ],
  },
  {
    type: 'grade',
    name: '届次',
    idField: 'grade_id',
    fields: [
      { key: 'grade_name', label: '届次名称', required: true },
      { key: 'is_current', label: '当前届次', options: 'boolean' },
      { key: 'sort', label: '排序', inputType: 'number' },
      { key: 'flag', label: '状态', options: 'flag' },
    ],
  },
  {
    type: 'profession',
    name: '专业',
    idField: 'profession_id',
    fields: [
      { key: 'grade_id', label: '所属届次', options: 'grades' },
      { key: 'dep_id', label: '所属学院', options: 'departments' },
      { key: 'profession_name', label: '专业名称', required: true },
      { key: 'profession_short_name', label: '专业简称' },
      { key: 'profession_code', label: '专业代码' },
      { key: 'sort', label: '排序', inputType: 'number' },
      { key: 'flag', label: '状态', options: 'flag' },
    ],
  },
  {
    type: 'class',
    name: '班级',
    idField: 'class_id',
    fields: [
      { key: 'grade_id', label: '所属届次', options: 'grades' },
      { key: 'dep_id', label: '所属学院', options: 'departments' },
      { key: 'profession_id', label: '所属专业', options: 'professions' },
      { key: 'class_name', label: '班级名称', required: true },
      { key: 'class_short_name', label: '班级简称' },
      { key: 'class_num', label: '班号' },
      { key: 'sort', label: '排序', inputType: 'number' },
      { key: 'flag', label: '状态', options: 'flag' },
    ],
  },
  {
    type: 'company',
    name: '基地',
    idField: 'company_id',
    fields: [
      { key: 'company_name', label: '基地名称', required: true },
      { key: 'credit_code', label: '信用代码' },
      { key: 'contact_name', label: '联系人' },
      { key: 'contact_mobile', label: '联系电话' },
      { key: 'address', label: '地址' },
      { key: 'flag', label: '状态', options: 'flag' },
    ],
  },
];
const archiveManagePanels = {
  archive: 'department',
  departmentManage: 'department',
  gradeManage: 'grade',
  professionManage: 'profession',
  classManage: 'class',
  companyManage: 'company',
};
const archiveManageModules = {
  departmentManage: 'department',
  gradeManage: 'grade',
  professionManage: 'profession',
  classManage: 'class',
  companyManage: 'company',
};
const archiveStatusFilterOptions = [
  { label: '全部', value: 'all' },
  { label: '启用', value: 'on' },
  { label: '停用', value: 'off' },
];
const userListColumns = [
  { key: 'id', label: 'ID', width: 76 },
  { key: 'name', label: '姓名', minWidth: 130 },
  { key: 'login_name', label: '登录账号', minWidth: 130 },
  { key: 'role_name', label: '当前角色', minWidth: 150, formatter: row => row.role_name || roleTypeNames[row.role_type] || '-' },
  { key: 'mobile', label: '手机', minWidth: 130 },
  { key: 'email', label: '邮箱', minWidth: 170 },
  { key: 'status', label: '账号状态', width: 100, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
  { key: 'created_at', label: '创建时间', minWidth: 160 },
];
const userListFilters = computed(() => [
  { key: 'keyword', label: '关键词', placeholder: '姓名/账号/手机/邮箱/角色' },
  {
    key: 'role_type',
    label: '角色',
    type: 'select',
    options: uniqueRoleOptions().map(role => ({
      label: role.name || roleTypeNames[role.role_type] || role.role_type,
      value: role.role_type,
    })),
  },
  {
    key: 'status',
    label: '状态',
    type: 'select',
    options: [
      { label: '全部', value: 'all' },
      { label: '启用', value: 'enabled' },
      { label: '停用', value: 'disabled' },
    ],
  },
]);

const defaultInternshipReviewRules = {
  arrangement_change: {
    accept: { min: 0, max: 300 },
    modify: { min: 5, max: 500 },
    refuse: { min: 5, max: 500 },
  },
  application: {
    accept: { min: 0, max: 200 },
    modify: { min: 5, max: 500 },
    skipped: { min: 0, max: 200 },
  },
  journal: {
    accept: { min: 0, max: 200 },
    modify: { min: 5, max: 500 },
  },
  report: {
    accept: { min: 0, max: 300 },
    modify: { min: 8, max: 800 },
  },
  plan: {
    accept: { min: 0, max: 300 },
    modify: { min: 8, max: 800 },
  },
  delay: {
    accept: { min: 0, max: 300 },
    refuse: { min: 5, max: 500 },
    modify: { min: 5, max: 500 },
  },
  base_application: {
    accept: { min: 0, max: 300 },
    modify: { min: 5, max: 500 },
  },
  base_usage: {
    accept: { min: 0, max: 300 },
    modify: { min: 5, max: 500 },
  },
  base_result: {
    accept: { min: 0, max: 300 },
    modify: { min: 5, max: 500 },
  },
  base_expense: {
    accept: { min: 0, max: 300 },
    modify: { min: 5, max: 500 },
  },
};

const baseFlowTypes = [
  { value: 'application', label: '基地申报' },
  { value: 'usage', label: '基地使用' },
  { value: 'result', label: '基地成果' },
  { value: 'expense', label: '基地费用' },
];

const archiveStates = reactive(Object.fromEntries(
  archiveDefinitions.map(definition => [definition.type, createArchiveState(definition.type)]),
));

const fileState = reactive({
  items: [],
  filters: {
    keyword: '',
    status: 'all',
    category: '',
  },
  pagination: {
    page: 1,
    page_size: 20,
    total: 0,
  },
  loading: false,
  message: '',
});

const favoriteState = reactive({
  items: [],
  filters: {
    keyword: '',
  },
  pagination: {
    page: 1,
    page_size: 20,
    total: 0,
  },
  loading: false,
  iconUploading: false,
  message: '',
  dialogVisible: false,
  editing: emptyFavoriteForm(),
});

const dataManageState = reactive({
  clearLoading: false,
  message: '',
});

const internshipSidebarItems = [
  { key: 'overview', name: '总览', icon: ChartColumn },
  { key: 'baseFlows', name: '基地建设', icon: Building2, permission: 'internship:manage' },
  { key: 'plans', name: '实习计划', icon: FileText, permission: 'internship:plan' },
  { key: 'arrangements', name: '实习任务', icon: CalendarCheck },
  { key: 'pairs', name: '任务绑定', icon: UsersRound },
  { key: 'arrangementChanges', name: '任务变更', icon: Workflow, permission: 'internship:manage' },
  { key: 'syllabusGuides', name: '大纲指导书', icon: BookOpen },
  { key: 'implementationSheets', name: '实施表', icon: ClipboardList },
  { key: 'signIns', name: '签到记录', icon: MapPin },
  { key: 'journals', name: '实习日志', icon: FileClock },
  { key: 'reports', name: '实习报告', icon: FileText },
  { key: 'applications', name: '特殊申请', icon: ClipboardList },
  { key: 'teacherWorkReports', name: '教师工作报告', icon: FileText },
  { key: 'delays', name: '延期申请', icon: FileClock },
  { key: 'scores', name: '成绩管理', icon: GraduationCap },
  { key: 'courseScores', name: '课程成绩', icon: GraduationCap },
  { key: 'inspections', name: '巡查记录', icon: Search, permission: 'internship:archive' },
  { key: 'documents', name: '归档材料', icon: FolderOpen },
];

const internshipState = reactive({
  loading: false,
  message: '',
  savedMessage: '',
  reviewOpinion: '',
  importing: false,
  overviewTab: 'metrics',
  dialog: emptyOperationDialog(),
  overview: emptyInternshipOverview(),
  options: emptyInternshipOptions(),
  arrangementDetail: emptyArrangementDetail(),
  arrangementForm: emptyArrangementForm(),
  planForm: emptyPlanForm(),
  scoreForm: emptyScoreForm(),
  scorePairLoading: false,
  courseScoreForm: emptyCourseScoreForm(),
  baseFlowForm: emptyBaseFlowForm(),
  filters: {
    arrangements: emptyInternshipFilters(),
    arrangementChanges: emptyInternshipFilters(),
    plans: emptyInternshipFilters(),
    syllabusGuides: emptyInternshipFilters(),
    implementationSheets: emptyInternshipFilters(),
    applications: emptyInternshipFilters(),
    pairs: emptyInternshipFilters(),
    signIns: emptyInternshipFilters(),
    journals: emptyInternshipFilters(),
    reports: emptyInternshipFilters(),
    teacherWorkReports: emptyInternshipFilters(),
    delays: emptyInternshipFilters(),
    scores: emptyInternshipFilters(),
    courseScores: emptyInternshipFilters(),
    inspections: emptyInternshipFilters(),
    archiveMaterials: emptyInternshipFilters(),
    baseFlows: emptyInternshipFilters(),
    insurances: emptyInternshipFilters(),
    safetyLetters: emptyInternshipFilters(),
  },
  lists: {
    arrangements: emptyPagedList(),
    arrangementChanges: emptyPagedList(),
    plans: emptyPagedList(),
    syllabusGuides: emptyPagedList(),
    implementationSheets: emptyPagedList(),
    applications: emptyPagedList(),
    pairs: emptyPagedList(),
    signIns: emptyPagedList(),
    journals: emptyPagedList(),
    reports: emptyPagedList(),
    teacherWorkReports: emptyPagedList(),
    delays: emptyPagedList(),
    scores: emptyPagedList(),
    courseScores: emptyPagedList(),
    inspections: emptyPagedList(),
    archiveMaterials: emptyPagedList(),
    baseFlows: emptyPagedList(),
    insurances: emptyPagedList(),
    safetyLetters: emptyPagedList(),
  },
});

const practiceState = reactive({
  training: createPracticeModuleState(),
  lab: createPracticeModuleState(),
});

const openWindows = reactive([]);

const moduleSearchKeywords = {
  internship: '学生 教师 学院 专业 企业 任务 绑定 特殊申请 审核 签到 日志 报告 延期 成绩 归档 实习任务 实习计划',
  training: '实训 项目 任务 过程 记录 成绩 审核',
  lab: '实验 项目 任务 过程 记录 成绩 审核',
  stat: '统计 报表 数据 概览 分析 学院 专业 学生 成绩',
  log: '日志 审计 操作 接口 账号 IP 登录 工作台 基础档案 流程配置',
  file: '文件 附件 上传 下载 预览 头像 壁纸 材料',
  doc: '文档 制度 流程 帮助 操作说明',
  templateLib: '模板 表格 材料 下载 模板库',
  exportTask: '导出 下载 队列 任务',
  favorite: '收藏 收藏夹 网址 常用 网站 快捷方式 桌面',
  userManage: '用户 账号 角色 学生 老师 管理员 绑定 日志',
  gradeManage: '届次 当前届次 年级',
  departmentManage: '学院 院系 部门',
  professionManage: '专业',
  classManage: '班级',
  companyManage: '基地 合作单位 单位 企业',
  dataManage: '数据 管理 清理 测试数据 初始化',
  config: '设置 配置 菜单 权限 角色 企业微信 操作说明 学校',
  profile: '个人设置 头像 壁纸 背景 消息接收 密码 资料',
  message: '消息 通知 待办 审核结果 站内信',
};
const defaultDesktopModuleIds = DEFAULT_DESKTOP_MODULE_IDS;
const launcherModuleIds = modules.map(module => module.id);
const launcherModuleIdSet = new Set(launcherModuleIds);
const configSidebarDefinitions = [
  { key: 'userManage', name: '用户管理', permission: 'config:user' },
  { key: 'gradeManage', name: '届次管理', permission: 'config:grade' },
  { key: 'departmentManage', name: '学院管理', permission: 'config:department' },
  { key: 'professionManage', name: '专业管理', permission: 'config:profession' },
  { key: 'classManage', name: '班级管理', permission: 'config:class' },
  { key: 'menuManage', name: '菜单管理', permission: 'config:view', schoolConfig: true },
  { key: 'roleMenus', name: '角色权限', permission: 'config:view', schoolConfig: true },
  { key: 'organizationScope', name: '组织范围', permission: 'config:view', schoolConfig: true },
  { key: 'operationGuides', name: '操作说明', permission: 'config:view', schoolConfig: true },
  { key: 'wechatProxy', name: '企业微信应用', permission: 'config:view', schoolConfig: true },
];

const isLoggedIn = computed(() => Boolean(permissionState.context.account_id));
const currentRoleType = computed(() => permissionState.context.role_type || '');
const isStudentRole = computed(() => currentRoleType.value === 'student');
const isTeacherRole = computed(() => currentRoleType.value === 'teacher');
const isAdminRole = computed(() => ['super_admin', 'school_admin', 'college_admin', 'profession_admin'].includes(currentRoleType.value));
const configChildModuleIds = new Set(['userManage', 'gradeManage', 'departmentManage', 'professionManage', 'classManage', 'companyManage']);
const schoolConfigModuleIds = new Set(['gradeManage', 'departmentManage', 'professionManage', 'classManage', 'companyManage', 'dataManage']);
const visibleModules = computed(() => modules.map(decorateModule).filter(canShowModule));
const menuModuleItems = computed(() => buildLaunchableMenuModules(permissionState.menus, modules, resolveMenuIcon));
const allLaunchableModules = computed(() => mergeMenuModules(visibleModules.value, menuModuleItems.value));
const mainEntryModules = computed(() => allLaunchableModules.value.filter(module => launcherModuleIdSet.has(module.id) || module.source === 'menu'));
const globalSearchKeyword = computed(() => keyword.value.trim().toLowerCase());
const searchCandidateModules = computed(() => {
  const items = [...allLaunchableModules.value, ...favoriteDesktopShortcuts.value];
  if (isLoggedIn.value) {
    items.push(messageModule);
  }
  return items;
});
const globalSearchResults = computed(() => {
  const value = globalSearchKeyword.value;
  if (!value) {
    return [];
  }
  return searchCandidateModules.value
    .filter(module => moduleSearchText(module).includes(value))
    .slice(0, 8);
});
const visibleDesktopModules = desktopLauncher.visibleDesktopModules;
const launcherFilteredModules = desktopLauncher.filteredModules;
const customDesktopShortcutItems = desktopLauncher.customShortcutItems;
const desktopShortcutPayloadItems = desktopLauncher.payloadItems;
const favoriteDesktopShortcuts = desktopLauncher.favoriteDesktopShortcuts;
const showGlobalSearchResults = computed(() => globalSearchKeyword.value && globalSearchResults.value.length > 0);
const visibleWindows = computed(() => openWindows.filter(win => !win.minimized));
const canManageConfig = computed(() => hasPermission('config:manage') && ['super_admin', 'school_admin'].includes(permissionState.context.role_type));
const canViewUserAdmin = computed(() => ['super_admin', 'school_admin', 'college_admin', 'profession_admin'].includes(permissionState.context.role_type));
const canSendMessages = computed(() => ['super_admin', 'school_admin'].includes(currentRoleType.value));
const canManageMessageTemplates = computed(() => ['super_admin', 'school_admin'].includes(currentRoleType.value) || hasPermission('template:manage'));
const canViewMessageTemplates = computed(() => ['super_admin', 'school_admin'].includes(currentRoleType.value) || hasPermission('template:view') || hasPermission('template:manage'));
const hasMessageTemplateFilters = computed(() => messageState.templateFilters.type !== 'all'
  || messageState.templateFilters.status !== 'all'
  || Boolean(String(messageState.templateFilters.keyword || '').trim()));
const messageTemplateEmptyTitle = computed(() => (
  hasMessageTemplateFilters.value ? '当前筛选无流程模板' : '暂无流程待办/消息模板'
));
const messageTemplateEmptyText = computed(() => {
  if (hasMessageTemplateFilters.value) {
    return '请调整类型、状态或关键词后重新查询。';
  }

  return canManageMessageTemplates.value
    ? '默认流程模板会自动写入学校业务库，也可点击同步后再调整。'
    : '未读取到默认流程模板，请联系管理员同步。';
});
const canManageInternship = computed(() => hasPermission('internship:manage'));
const canSaveInternshipScore = computed(() => hasPermission('internship:score') || canManageInternship.value);
const canManageInternshipPlan = computed(() => hasPermission('internship:plan') && isAdminRole.value);
const canApproveInternship = computed(() => hasPermission('internship:approve') && !isStudentRole.value);
const canManagePractice = module => hasPermission(`${module}:manage`) && !isStudentRole.value;
const canApprovePractice = module => hasPermission(`${module}:approve`) && !isStudentRole.value;
const statReports = [
  { key: 'overview', name: '实习总览', description: '实习任务、任务绑定和过程材料汇总。', icon: ChartColumn },
  { key: 'department', name: '学院统计', description: '按学院统计学生参与、审核进度和成绩分布。', icon: Building2 },
  { key: 'profession', name: '专业统计', description: '按专业统计实习覆盖、提交进度和材料归档。', icon: GraduationCap },
  { key: 'teacher', name: '任务老师统计', description: '按任务老师统计学生数量和评阅进度。', icon: UsersRound },
  { key: 'student', name: '学生过程统计', description: '查看学生申请、签到、日志、报告、成绩的过程状态。', icon: UserRound },
  { key: 'archive', name: '归档材料统计', description: '统计保险、安全承诺和报告归档材料完整性。', icon: FolderOpen },
  { key: 'practice_score_sheet', name: '实验实训成绩记载表', description: '按教学计划生成考勤、项目实操、课程报告和总分记载表。', icon: Table2 },
];
const currentStatReport = computed(() => statReports.find(item => item.key === statState.report) || statReports[0]);
const statCardIcons = [UserRound, ClipboardList, CheckCircle2, UsersRound, GraduationCap, MapPin];
const scoreSheetAttendanceIndexes = Array.from({ length: 16 }, (_, index) => index + 1);
const scoreSheetProjectIndexes = Array.from({ length: 12 }, (_, index) => index + 1);
const guideModuleOptions = computed(() => modules.map(item => ({
  label: moduleDisplayName(item),
  value: item.id,
})));
const statCards = computed(() => {
  const cards = statState.cards.length ? statState.cards : [
    { name: '实习任务', value: internshipState.overview.arrangements || 0, desc: '可见数据内任务数量' },
    { name: '任务绑定', value: internshipState.overview.active_pairs || 0, desc: '有效任务级师生绑定' },
    { name: '待评日志', value: internshipState.overview.journals_waiting || 0, desc: '待评阅实习日志' },
    { name: '特殊申请', value: internshipState.overview.applications_waiting || 0, desc: '分散、自主等场景待审申请' },
  ];
  return cards.map((item, index) => ({
    ...item,
    icon: statCardIcons[index % statCardIcons.length],
  }));
});
const currentStatColumns = computed(() => statState.columns || []);
const courseScoreSheetMetaItems = computed(() => {
  const meta = statState.sheet_meta || {};
  return [
    { key: 'academic_year', label: '学年', value: meta.academic_year || statState.filters.academic_year },
    { key: 'course_number', label: '选课课号', value: meta.course_number },
    { key: 'course_name', label: '名称', value: meta.course_name },
    { key: 'teacher_name', label: '教师姓名', value: meta.teacher_name },
    { key: 'teacher_unit', label: '教师单位', value: meta.teacher_unit },
    { key: 'class_time', label: '上课时间', value: meta.class_time },
    { key: 'location', label: '地点', value: meta.location },
  ];
});
const messageGroups = computed(() => groupMessagesByDay(messageState.items));
const messageUnreadCount = computed(() => Number(messageState.summary.unread || 0));
const enabledMessageTemplates = computed(() => messageState.templates.filter(item => item.status === 'enabled'));
const messageSendScopeHint = computed(() => {
  const form = messageState.sendDialog.form;
  if (form.send_scope === 'all') {
    return '发送给当前学校所有启用账号';
  }
  if (form.send_scope === 'role') {
    const roleName = messageTargetRoleOptions.find(item => item.value === form.role_type)?.label;
    return roleName ? `发送给所有${roleName}` : '请选择接收角色';
  }
  return '从下方列表选择一个或多个收件人';
});
const selectedWechatMenu = computed(() => {
  const main = wechatProxy.menu[wechatProxy.selectedMenuIndex];
  if (!main) {
    return null;
  }
  if (wechatProxy.selectedSubMenuIndex >= 0) {
    return (main.children || [])[wechatProxy.selectedSubMenuIndex] || null;
  }
  return main;
});
const visibleInternshipSidebarItems = computed(() => internshipSidebarItems
  .filter(internshipSidebarItemVisible)
  .map((item) => ({
    ...item,
    name: internshipRolePanelName(item.key),
  })));
const parentMenuTreeOptions = computed(() => [
  {
    id: 0,
    name: '顶级',
    children: selectableParentMenus(adminState.menus),
  },
]);
const operatorName = computed(() => permissionState.context.user_name || (permissionState.context.user_id ? `用户 ${permissionState.context.user_id}` : '未登录'));
const switchableLoginAccounts = computed(() => {
  const currentAccountId = Number(permissionState.context.account_id || 0);
  return (switchAccountState.items || []).filter((account) => {
    const accountId = Number(account?.id || 0);
    const hasRole = Boolean(account?.role_id || account?.role_type || account?.role_name);
    return accountId > 0 && accountId !== currentAccountId && !account?.is_current && account?.status !== 'disabled' && hasRole;
  });
});
const schoolDataText = computed(() => {
  if (!isLoggedIn.value) {
    return '未登录';
  }
  return permissionState.context.school_name || permissionState.context.school?.school_name || '成都锦城学院';
});
const roleText = computed(() => permissionState.context.role_name || roleTypeNames[permissionState.context.role_type] || permissionState.context.role_type || permissionState.context.role_id || '-');
const loginSchoolName = computed(() => loginPageState.school_name || '成都锦城学院');
const registerRoleOptions = computed(() => registerState.roles.length
  ? registerState.roles
  : [
      { role_type: 'student', name: '学生' },
      { role_type: 'teacher', name: '教师' },
    ]);
const userEditingRoleType = computed(() => userSelectedRoleType());
const canManageLoginBackground = computed(() => ['super_admin', 'school_admin'].includes(permissionState.context.role_type));
const defaultLoginBackground = 'linear-gradient(135deg, rgba(31, 115, 210, .22), rgba(15, 143, 126, .14)), #eaf1f7';
const loginPageStyle = computed(() => ({
  background: loginPageState.login_background_url
    ? `linear-gradient(135deg, rgba(20, 31, 43, .42), rgba(20, 31, 43, .24)), url("${safeCssUrl(loginPageState.login_background_url)}") center / cover no-repeat`
    : defaultLoginBackground,
}));
const selectedWallpaper = computed(() => wallpaperPresets.find(item => item.key === profileState.form.wallpaper) || wallpaperPresets[0]);
const desktopStyle = computed(() => ({
  background: profileState.form.wallpaper_url
    ? `linear-gradient(135deg, rgba(30, 42, 54, .20), rgba(30, 42, 54, .08)), url("${safeCssUrl(profileState.form.wallpaper_url)}") center / cover no-repeat`
    : selectedWallpaper.value.background,
}));
const avatarStyle = computed(() => (profileState.form.avatar ? {
  backgroundImage: `url("${safeCssUrl(profileState.form.avatar)}")`,
} : {}));
const topAvatarStyle = computed(() => (profileState.form.avatar ? {
  backgroundImage: `url("${safeCssUrl(profileState.form.avatar)}")`,
} : {}));
const wallpaperUploadStyle = computed(() => (profileState.form.wallpaper_url ? {
  backgroundImage: `url("${safeCssUrl(profileState.form.wallpaper_url)}")`,
} : {}));
const loginBackgroundUploadStyle = computed(() => (loginPageState.login_background_url ? {
  backgroundImage: `url("${safeCssUrl(loginPageState.login_background_url)}")`,
} : {}));
const profileAlertType = computed(() => (profileState.saved ? 'success' : 'warning'));
const userDetailTitle = computed(() => (userAdminState.detailMode === 'logs' ? '操作日志' : '绑定账号'));
const reviewReasonLength = computed(() => textLength(internshipState.dialog.reason));
const internshipTimelineCycles = computed(() => normalizeTimelineCycles(
  internshipState.dialog.cycles || [],
  internshipState.dialog.timeline || [],
));

function resolveMenuIcon(iconName) {
  if (!iconName) {
    return LayoutGrid;
  }
  if (typeof iconName !== 'string') {
    return iconName;
  }
  return iconRegistry[iconName.trim()] || LayoutGrid;
}

function canShowModule(module) {
  if (module.source === 'menu') {
    return isLoggedIn.value && module.menu?.visible !== 'false';
  }
  if (module.id === 'profile') {
    return isLoggedIn.value;
  }
  if (module.id === 'favorite') {
    return isLoggedIn.value;
  }
  if (module.id === 'config') {
    return configSidebarItemsForCurrentRole().length > 0;
  }
  if (module.id === 'userManage') {
    return canViewUserAdmin.value;
  }
  if (schoolConfigModuleIds.has(module.id)) {
    return canShowSchoolConfigModule(module);
  }
  if (!hasPermission(module.viewPermission)) {
    return false;
  }
  if (module.adminOnly) {
    return ['super_admin', 'school_admin'].includes(currentRoleType.value);
  }
  if (['training', 'lab'].includes(module.id)) {
    return ['student', 'teacher', 'super_admin', 'school_admin', 'college_admin', 'profession_admin'].includes(currentRoleType.value);
  }
  if (module.id === 'internship') {
    return ['student', 'teacher', 'super_admin', 'school_admin', 'college_admin', 'profession_admin'].includes(currentRoleType.value);
  }
  return true;
}

function canShowSchoolConfigModule(module) {
  if (!isSchoolConfigRole()) {
    return false;
  }
  if (module.id === 'dataManage') {
    return hasPermission('config:view') || hasPermission('config:manage');
  }
  return !module.viewPermission || hasPermission(module.viewPermission);
}

function isSchoolConfigRole() {
  return ['super_admin', 'school_admin'].includes(currentRoleType.value);
}

function canAccessConfigSidebarItem(item) {
  if (item.schoolConfig && !isSchoolConfigRole()) {
    return false;
  }
  return !item.permission || hasPermission(item.permission);
}

function configSidebarItemsForCurrentRole() {
  return configSidebarDefinitions.filter(canAccessConfigSidebarItem);
}

const internshipOverviewCards = computed(() => [
  { name: '实习任务', value: internshipState.overview.arrangements || 0, theme: 'primary', icon: CalendarCheck },
  { name: '任务绑定', value: internshipState.overview.active_pairs || 0, theme: 'green', icon: UsersRound },
  { name: '待评日志', value: internshipState.overview.journals_waiting || 0, theme: 'teal', icon: FileClock },
  { name: '待评报告', value: internshipState.overview.reports_waiting || 0, theme: 'primary', icon: FileText },
  { name: '今日签到', value: internshipState.overview.today_sign_ins || 0, theme: 'green', icon: MapPin },
  { name: '特殊申请', value: internshipState.overview.applications_waiting || 0, theme: 'amber', icon: ClipboardList },
]);
const internshipOverviewTabs = computed(() => {
  const tabs = [
  ];
  if (isStudentRole.value) {
    tabs.push(
      {
        key: 'arrangements',
        name: '我的任务',
        count: studentPanelList('arrangements').pagination.total || 0,
      },
      {
        key: 'applications',
        name: '特殊申请',
        count: studentPanelList('applications').pagination.total || 0,
      },
    );
    return tabs;
  }
  tabs.push({ key: 'metrics', name: '数据概览', count: `${internshipOverviewCards.value.length} 项` });
  tabs.push(
    {
      key: 'arrangements',
      name: '近期任务',
      count: internshipState.lists.arrangements.pagination.total || 0,
    },
    {
      key: 'applications',
      name: '特殊申请',
      count: internshipState.overview.applications_waiting || 0,
    },
  );
  return tabs;
});
const activeOverviewTab = computed(() => {
  const keys = new Set(internshipOverviewTabs.value.map(item => item.key));
  return keys.has(internshipState.overviewTab) ? internshipState.overviewTab : internshipOverviewTabs.value[0]?.key || 'metrics';
});
const internshipListConfigs = computed(() => ({
  baseFlows: {
    listKey: 'baseFlows',
    filename: '基地建设',
    filters: baseFlowFilters(),
    columns: [
      { key: 'flow_type', label: '流程类型', width: 110, formatter: row => baseFlowTypeText(row.flow_type || internshipState.filters.baseFlows.type) },
      { prop: 'base_name', label: '基地', minWidth: 160 },
      { prop: 'dep_name', label: '学院', minWidth: 130 },
      { prop: 'title', label: '标题', minWidth: 180 },
      { key: 'detail', label: '类型/金额', minWidth: 130, formatter: row => baseFlowDetailText(row) },
      { prop: 'submitter_name', label: '提交人', width: 110 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
      { prop: 'created_at', label: '创建时间', width: 168 },
    ],
  },
  arrangements: {
    listKey: 'arrangements',
    filename: '实习任务',
    filters: internshipListFilters('arrangements', ['grade_id', 'dep_id', 'profession_id', 'plan_id', 'type', 'organize_mode', 'status', 'keyword']),
    columns: [
      { prop: 'title', label: '实习任务', minWidth: 180 },
      { key: 'plan', label: '课程计划', minWidth: 180, formatter: row => row.course_name || row.course_code || '-' },
      { prop: 'teacher_name', label: '负责老师', width: 120 },
      { prop: 'task_no', label: '任务编号', width: 140 },
      { prop: 'batch_no', label: '批次', width: 100 },
      // 暂时隐藏学期列，后续需要时恢复。
      // { prop: 'semester', label: '学期', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'class_names', label: '绑定班级', minWidth: 180 },
      { key: 'type', label: '类型', width: 130, formatter: row => arrangementTypeText(row.type) },
      { key: 'mode', label: '组织方式', width: 100, formatter: row => organizeModeText(row.organize_mode) },
      { prop: 'credit', label: '学分', width: 80 },
      { prop: 'student_count', label: '人数', width: 80 },
      { prop: 'task_binding_count', label: '绑定人数', width: 90 },
      { prop: 'task_score_progress_text', label: '任务评分', width: 100 },
      { key: 'date', label: '时间', minWidth: 170, formatter: row => `${row.start_date || '-'} 至 ${row.end_date || '-'}` },
      { key: 'scope', label: '范围', minWidth: 190, formatter: row => arrangementScopeText(row) },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
    ],
  },
  arrangementChanges: {
    listKey: 'arrangementChanges',
    filename: '任务变更',
    filters: internshipListFilters('arrangementChanges', ['grade_id', 'dep_id', 'profession_id', 'arrangement_id', 'status', 'keyword']),
    columns: [
      { prop: 'arrangement_title', label: '原任务', minWidth: 190 },
      { prop: 'course_name', label: '课程计划', minWidth: 170 },
      { prop: 'teacher_name', label: '原负责老师', width: 120 },
      { prop: 'task_no', label: '任务编号', width: 140 },
      { key: 'change_title', label: '拟变更任务', minWidth: 190, formatter: row => row.payload?.title || '-' },
      { key: 'change_date', label: '拟变更时间', minWidth: 170, formatter: row => `${row.payload?.start_date || '-'} 至 ${row.payload?.end_date || '-'}` },
      { key: 'new_arrangement_title', label: '生效任务', minWidth: 190, formatter: row => row.new_arrangement_title || '-' },
      { prop: 'new_task_no', label: '新任务编号', width: 140 },
      { prop: 'new_teacher_name', label: '新负责老师', width: 120 },
      { prop: 'reason', label: '变更原因', minWidth: 220 },
      { prop: 'submitter_name', label: '提交人', width: 110 },
      { prop: 'submitted_at', label: '提交时间', width: 168 },
      { prop: 'reviewer_name', label: '审核人', width: 110 },
      { prop: 'review_opinion', label: '审核意见', minWidth: 180 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
    ],
  },
  applications: {
    listKey: 'applications',
    filename: '特殊申请',
    filters: internshipListFilters('applications', ['grade_id', 'dep_id', 'profession_id', 'class_id', 'status', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'arrangement_title', label: '实习任务', minWidth: 190 },
      { prop: 'dep_name', label: '学院', minWidth: 130 },
      { prop: 'profession_name', label: '专业', minWidth: 130 },
      { prop: 'status', label: '申请状态', width: 100, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
      { key: 'teacher_status', label: '教师审核', width: 100, formatter: row => statusText(row.teacher_status) },
      { key: 'admin_status', label: '管理审核', width: 100, formatter: row => statusText(row.admin_status) },
      { prop: 'created_at', label: '提交时间', width: 168 },
    ],
  },
  plans: {
    listKey: 'plans',
    filename: '实习计划',
    filters: internshipListFilters('plans', ['grade_id', 'dep_id', 'profession_id', 'status', 'keyword']),
    columns: [
      { prop: 'course_name', label: '课程名称', minWidth: 180 },
      { prop: 'course_code', label: '课程代码', width: 130 },
      // 暂时隐藏学期列，后续需要时恢复。
      // { prop: 'semester', label: '学期', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'dep_name', label: '学院', minWidth: 150 },
      { prop: 'profession_name', label: '专业', minWidth: 150 },
      { prop: 'credit', label: '学分', width: 80 },
      { prop: 'task_count', label: '任务数', width: 80 },
      { prop: 'task_coverage_text', label: '任务覆盖', width: 100 },
      { prop: 'task_score_progress_text', label: '任务评分', width: 100 },
      { key: 'score_rule', label: '成绩规则', width: 110, formatter: row => scoreRuleText(row.score_rule) },
      { key: 'content', label: '计划内容', minWidth: 220, formatter: row => planContentText(row.plan_content) },
      { prop: 'submitter_name', label: '提交人', width: 120 },
      { prop: 'approval_progress_text', label: '审核进度', width: 100 },
      { prop: 'current_approval_name', label: '当前节点', width: 110 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
      { prop: 'created_at', label: '提交时间', width: 168 },
    ],
  },
  pairs: {
    listKey: 'pairs',
    filename: '任务绑定',
    filters: internshipListFilters('pairs', ['grade_id', 'dep_id', 'profession_id', 'class_id', 'status', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 120 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'dep_name', label: '学院', minWidth: 130 },
      { prop: 'profession_name', label: '专业', minWidth: 130 },
      { prop: 'class_name', label: '班级', minWidth: 130 },
      { prop: 'arrangement_title', label: '实习任务', minWidth: 190 },
      { prop: 'task_no', label: '任务编号', width: 140 },
      { prop: 'batch_no', label: '批次', width: 100 },
      { prop: 'teacher_name', label: '负责老师', width: 120 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
      { prop: 'created_at', label: '创建时间', width: 168 },
    ],
  },
  signIns: {
    listKey: 'signIns',
    filename: '签到记录',
    filters: internshipListFilters('signIns', ['grade_id', 'dep_id', 'profession_id', 'class_id', 'arrangement_id', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'arrangement_title', label: '实习任务', minWidth: 180 },
      { prop: 'date', label: '日期', width: 110 },
      { prop: 'sign_time', label: '签到时间', width: 168 },
      { prop: 'location', label: '地点', minWidth: 150 },
      { key: 'sign_type', label: '方式', width: 90, formatter: row => signTypeText(row.sign_type) },
    ],
  },
  journals: {
    listKey: 'journals',
    filename: '实习日志',
    filters: internshipListFilters('journals', ['grade_id', 'dep_id', 'profession_id', 'class_id', 'arrangement_id', 'status', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'title', label: '日志标题', minWidth: 180 },
      { prop: 'date', label: '日期', width: 110 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
      { prop: 'content', label: '内容', minWidth: 220 },
    ],
  },
  reports: {
    listKey: 'reports',
    filename: '实习报告',
    filters: internshipListFilters('reports', ['grade_id', 'dep_id', 'profession_id', 'class_id', 'arrangement_id', 'status', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'title', label: '报告标题', minWidth: 180 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
      { prop: 'submitted_at', label: '提交时间', width: 168 },
      { prop: 'content', label: '内容', minWidth: 240 },
    ],
  },
  delays: {
    listKey: 'delays',
    filename: '延期申请',
    filters: internshipListFilters('delays', ['grade_id', 'dep_id', 'profession_id', 'class_id', 'arrangement_id', 'config_key', 'status', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'arrangement_title', label: '实习任务', minWidth: 190 },
      { key: 'config_key', label: '延期类型', width: 110, formatter: row => delayConfigText(row.config_key) },
      { prop: 'requested_date', label: '申请延期至', width: 120 },
      { prop: 'reason', label: '原因', minWidth: 220 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
      { prop: 'created_at', label: '申请时间', width: 168 },
    ],
  },
  scores: {
    listKey: 'scores',
    filename: '实习成绩',
    filters: internshipListFilters('scores', ['grade_id', 'dep_id', 'profession_id', 'class_id', 'arrangement_id', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'arrangement_title', label: '实习任务', minWidth: 180 },
      { key: 'score_status', label: '评分状态', width: 90, tag: true, tagType: row => row.final_score !== null && row.final_score !== undefined ? 'success' : 'warning', formatter: row => row.final_score !== null && row.final_score !== undefined ? '已评分' : '待评分' },
      { prop: 'sign_in_score', label: '签到', width: 80 },
      { prop: 'journal_score', label: '日志', width: 80 },
      { prop: 'report_score', label: '报告', width: 80 },
      { prop: 'enterprise_score', label: '企业', width: 80 },
      { prop: 'final_score', label: '总评', width: 90 },
      { prop: 'teacher_name', label: '评分人', width: 110 },
    ],
  },
  courseScores: {
    listKey: 'courseScores',
    filename: '课程成绩汇总',
    filters: internshipListFilters('courseScores', ['grade_id', 'dep_id', 'profession_id', 'class_id', 'plan_id', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'course_name', label: '课程计划', minWidth: 180 },
      { key: 'score_rule', label: '成绩规则', width: 110, formatter: row => scoreRuleText(row.score_rule) },
      { key: 'course_score_status', label: '汇总状态', width: 100, tag: true, tagType: row => row.course_score_status === 'complete' ? 'success' : 'warning', formatter: row => courseScoreStatusText(row.course_score_status) },
      { prop: 'task_count', label: '任务数', width: 80 },
      { prop: 'scored_task_count', label: '已评分', width: 80 },
      { key: 'course_final_score', label: '课程成绩', width: 100, formatter: row => row.course_final_score ?? '-' },
      { prop: 'task_score_text', label: '任务成绩', minWidth: 240 },
      { prop: 'manual_score_remark', label: '核定说明', minWidth: 160 },
      { key: 'manual_score_operator_name', label: '核定人', width: 110, formatter: row => row.manual_score_operator_name || row.manual_score_operator_login || '-' },
      { prop: 'manual_score_updated_at', label: '核定时间', width: 168 },
      { prop: 'class_name', label: '班级', minWidth: 130 },
    ],
  },
  archiveMaterials: {
    listKey: 'archiveMaterials',
    filename: '归档材料',
    filters: internshipListFilters('archiveMaterials', ['grade_id', 'dep_id', 'profession_id', 'class_id', 'arrangement_id', 'archive_status', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'dep_name', label: '学院', minWidth: 130 },
      { prop: 'profession_name', label: '专业', minWidth: 130 },
      { prop: 'arrangement_title', label: '实习任务', minWidth: 190 },
      { prop: 'arrangement_type_text', label: '实习类型', width: 110 },
      // 暂时隐藏学期列，后续需要时恢复。
      // { prop: 'semester', label: '学期', width: 120 },
      { prop: 'material_progress', label: '归档进度', width: 100 },
      { prop: 'plan_status', label: '计划表', width: 95 },
      { prop: 'implementation_sheet_status', label: '实施表', width: 95 },
      { prop: 'syllabus_guide_status', label: '大纲指导书', width: 110 },
      { prop: 'score_summary_status', label: '成绩汇总', width: 95 },
      { prop: 'safety_letter_status', label: '安全承诺', width: 95 },
      { prop: 'journal_status', label: '实习周志', width: 95 },
      { prop: 'report_status', label: '实习报告', width: 95 },
      { prop: 'graduation_appraisal_status', label: '鉴定表', width: 95 },
      { prop: 'teacher_work_report_status', label: '教师工作报告', width: 120 },
      { prop: 'inspection_record_status', label: '抽检记录', width: 95 },
      { prop: 'insurance_status', label: '保险单', width: 95 },
      { prop: 'missing_materials', label: '缺失材料', minWidth: 240 },
      { prop: 'archive_status_text', label: '归档状态', width: 100 },
    ],
  },
  insurances: {
    listKey: 'insurances',
    filename: '保险记录',
    filters: internshipListFilters('insurances', ['grade_id', 'dep_id', 'profession_id', 'class_id', 'arrangement_id', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'arrangement_title', label: '实习任务', minWidth: 180 },
      { prop: 'insurance_company', label: '保险公司', minWidth: 160 },
      { prop: 'policy_number', label: '保单号', minWidth: 150 },
      { prop: 'start_date', label: '开始', width: 110 },
      { prop: 'end_date', label: '结束', width: 110 },
    ],
  },
  safetyLetters: {
    listKey: 'safetyLetters',
    filename: '安全承诺',
    filters: internshipListFilters('safetyLetters', ['grade_id', 'dep_id', 'profession_id', 'class_id', 'arrangement_id', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'arrangement_title', label: '实习任务', minWidth: 180 },
      { prop: 'signed_at', label: '签署时间', minWidth: 160 },
      { key: 'status', label: '状态', width: 90, formatter: row => statusText(row.status) },
    ],
  },
  syllabusGuides: {
    listKey: 'syllabusGuides',
    filename: '实习大纲及指导书',
    filters: internshipListFilters('syllabusGuides', ['grade_id', 'dep_id', 'profession_id', 'arrangement_id', 'status', 'keyword']),
    columns: [
      { prop: 'title', label: '标题', minWidth: 180 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'arrangement_title', label: '实习任务', minWidth: 190 },
      { prop: 'dep_name', label: '学院', minWidth: 130 },
      { prop: 'profession_name', label: '专业', minWidth: 130 },
      { prop: 'creator_name', label: '录入人', width: 110 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
      { prop: 'created_at', label: '创建时间', width: 168 },
      { prop: 'content', label: '内容', minWidth: 240 },
    ],
  },
  implementationSheets: {
    listKey: 'implementationSheets',
    filename: '教学实习实施表',
    filters: internshipListFilters('implementationSheets', ['grade_id', 'dep_id', 'profession_id', 'arrangement_id', 'status', 'keyword']),
    columns: [
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'arrangement_title', label: '实习任务', minWidth: 190 },
      { prop: 'dep_name', label: '学院', minWidth: 130 },
      { prop: 'profession_name', label: '专业', minWidth: 130 },
      { prop: 'teacher_name', label: '负责老师', width: 120 },
      { prop: 'signed_count', label: '已签承诺', width: 100 },
      { prop: 'unsigned_count', label: '未签承诺', width: 100 },
      { key: 'insurance_verified', label: '保险核验', width: 100, formatter: row => row.insurance_verified === 'true' ? '已核验' : '未核验' },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
      { prop: 'confirmed_at', label: '确认时间', width: 168 },
    ],
  },
  teacherWorkReports: {
    listKey: 'teacherWorkReports',
    filename: '实习指导教师工作报告',
    filters: internshipListFilters('teacherWorkReports', ['grade_id', 'dep_id', 'profession_id', 'arrangement_id', 'status', 'keyword']),
    columns: [
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'arrangement_title', label: '实习任务', minWidth: 190 },
      { prop: 'teacher_name', label: '任务老师', width: 120 },
      { prop: 'guidance_count', label: '负责人数', width: 100 },
      { prop: 'summary', label: '工作总结', minWidth: 220 },
      { prop: 'problems', label: '问题', minWidth: 180 },
      { prop: 'suggestions', label: '建议', minWidth: 180 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
      { prop: 'created_at', label: '提交时间', width: 168 },
    ],
  },
  inspections: {
    listKey: 'inspections',
    filename: '实习巡查记录',
    filters: internshipListFilters('inspections', ['grade_id', 'dep_id', 'profession_id', 'class_id', 'arrangement_id', 'result', 'keyword']),
    columns: [
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'arrangement_title', label: '实习任务', minWidth: 190 },
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'dep_name', label: '学院', minWidth: 130 },
      { prop: 'profession_name', label: '专业', minWidth: 130 },
      { prop: 'inspector_name', label: '巡查人', width: 110 },
      { key: 'result', label: '结果', width: 90, formatter: row => inspectionResultText(row.result) },
      { prop: 'remark', label: '说明', minWidth: 220 },
      { prop: 'created_at', label: '记录时间', width: 168 },
    ],
  },
}));

function internshipRolePanelName(key) {
  if (!isStudentRole.value) {
    return internshipSidebarItems.find(item => item.key === key)?.name || '实习管理';
  }

  const names = {
    overview: '总览',
    arrangements: '我的任务',
    applications: '特殊申请',
    pairs: '任务老师',
    signIns: '我的签到',
    journals: '我的日志',
    reports: '我的报告',
    delays: '我的延期',
    scores: '我的成绩',
    courseScores: '课程成绩',
    documents: '我的材料',
  };
  return names[key] || internshipSidebarItems.find(item => item.key === key)?.name || '实习管理';
}

function internshipSidebarItemVisible(item) {
  if (item.permission && !hasPermission(item.permission)) {
    return false;
  }
  if (!isStudentRole.value) {
    return true;
  }
  return ['overview', 'arrangements', 'applications', 'signIns', 'journals', 'reports', 'delays', 'scores', 'courseScores', 'documents'].includes(item.key);
}

function isInternshipReadOnlyListPanel(panel) {
  return ['syllabusGuides', 'implementationSheets', 'teacherWorkReports', 'inspections'].includes(panel);
}

function internshipTimelineEntity(panel) {
  const map = {
    syllabusGuides: 'syllabus_guide',
    implementationSheets: 'implementation_sheet',
    teacherWorkReports: 'teacher_work_report',
    inspections: 'inspection',
    insurances: 'insurance',
    safetyLetters: 'safety_letter',
  };
  return map[panel] || '';
}

function internshipListFilters(listKey, adminKeys) {
  if (isStudentRole.value) {
    return [];
  }
  if (isTeacherRole.value) {
    return listKey === 'arrangements' ? [] : internshipFilters(['grade_id', 'student_keyword'], internshipState.filters[listKey] || {});
  }
  return internshipFilters(adminKeys, internshipState.filters[listKey] || {});
}

function hasInternshipToolbarActions(panel) {
  if (panel === 'baseFlows') {
    return canManageInternship.value;
  }
  if (panel === 'arrangements') {
    return canManageInternship.value;
  }
  if (panel === 'plans') {
    return canManageInternshipPlan.value;
  }
  if (panel === 'scores') {
    return canSaveInternshipScore.value;
  }
  return false;
}

function canSaveManualCourseScore(row) {
  return canSaveInternshipScore.value && row?.score_rule === 'manual';
}

function isStudentOwnPanel(panel) {
  return isStudentRole.value && ['arrangements', 'applications', 'signIns', 'journals', 'reports', 'delays', 'scores', 'courseScores'].includes(panel);
}

function studentPanelList(panel) {
  return internshipState.lists[panel] || emptyPagedList();
}

function studentTimelineEntity(panel) {
  const map = {
    arrangements: 'arrangement',
    applications: 'application',
    signIns: 'sign_in',
    journals: 'journal',
    reports: 'report',
    delays: 'delay',
    scores: 'score',
  };
  return map[panel] || '';
}

function studentPanelMeta(panel) {
  const metas = {
    arrangements: {
      title: '我的任务',
      description: '展示管理员已分配给当前学生的实习任务。',
      emptyText: '暂无实习任务',
    },
    applications: {
      title: '特殊申请',
      description: '只展示分散、自主等特殊场景下当前学生本人的申请。',
      emptyText: '暂无特殊申请',
    },
    pairs: {
      title: '任务老师',
      description: '展示当前学生每个实习任务绑定的负责老师。',
      emptyText: '暂无任务老师',
    },
    signIns: {
      title: '我的签到',
      description: '只展示当前学生本人的签到记录。',
      emptyText: '暂无签到记录',
    },
    journals: {
      title: '我的日志',
      description: '只展示当前学生本人的实习日志和评阅状态。',
      emptyText: '暂无实习日志',
    },
    reports: {
      title: '我的报告',
      description: '只展示当前学生本人的实习报告和评阅状态。',
      emptyText: '暂无实习报告',
    },
    delays: {
      title: '我的延期',
      description: '只展示当前学生本人的截止延期申请和审核状态。',
      emptyText: '暂无延期申请',
    },
    scores: {
      title: '我的成绩',
      description: '展示当前学生本人按任务记录的实习成绩。',
      emptyText: '暂无成绩记录',
    },
    courseScores: {
      title: '课程成绩',
      description: '展示当前学生本人按课程计划汇总后的最终成绩。',
      emptyText: '暂无课程成绩',
    },
    archiveMaterials: {
      title: '我的归档材料',
      description: '展示当前学生本人各项实习归档材料的完整性。',
      emptyText: '暂无归档材料',
    },
    insurances: {
      title: '保险记录',
      description: '只展示当前学生本人的保险材料。',
      emptyText: '暂无保险记录',
    },
    safetyLetters: {
      title: '安全承诺',
      description: '只展示当前学生本人的安全承诺签署记录。',
      emptyText: '暂无安全承诺记录',
    },
  };
  return metas[panel] || { title: '我的实习', description: '', emptyText: '暂无数据' };
}

function studentPanelFields(panel) {
  const fields = {
    arrangements: [
      // 暂时隐藏学期字段，后续需要时恢复。
      // { key: 'semester', label: '学期' },
      { key: 'teacher_name', label: '任务老师' },
      { key: 'task_no', label: '任务编号' },
      { key: 'batch_no', label: '批次' },
      { key: 'type', label: '类型', formatter: row => arrangementTypeText(row.type) },
      { key: 'organize_mode', label: '组织方式', formatter: row => organizeModeText(row.organize_mode) },
      { key: 'date', label: '时间', formatter: row => `${row.start_date || '-'} 至 ${row.end_date || '-'}` },
      { key: 'location', label: '地点' },
    ],
    applications: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习任务' },
      { key: 'teacher_status', label: '教师审核', formatter: row => statusText(row.teacher_status) },
      { key: 'admin_status', label: '管理审核', formatter: row => statusText(row.admin_status) },
      { key: 'created_at', label: '提交时间' },
    ],
    pairs: [
      { key: 'grade_name', label: '届次' },
      { key: 'teacher_name', label: '任务老师' },
      { key: 'arrangement_title', label: '实习任务' },
      { key: 'created_at', label: '创建时间' },
    ],
    signIns: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习任务' },
      { key: 'date', label: '日期' },
      { key: 'sign_time', label: '签到时间' },
      { key: 'sign_type', label: '方式', formatter: row => signTypeText(row.sign_type) },
      { key: 'location', label: '地点' },
    ],
    journals: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习任务' },
      { key: 'date', label: '日期' },
      { key: 'content', label: '内容' },
    ],
    reports: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习任务' },
      { key: 'submitted_at', label: '提交时间' },
      { key: 'content', label: '内容' },
    ],
    delays: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习任务' },
      { key: 'config_key', label: '延期类型', formatter: row => delayConfigText(row.config_key) },
      { key: 'requested_date', label: '申请延期至' },
      { key: 'reason', label: '原因' },
      { key: 'created_at', label: '申请时间' },
    ],
    scores: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习任务' },
      { key: 'sign_in_score', label: '签到' },
      { key: 'journal_score', label: '日志' },
      { key: 'report_score', label: '报告' },
      { key: 'enterprise_score', label: '企业' },
      { key: 'final_score', label: '总评' },
    ],
    courseScores: [
      { key: 'grade_name', label: '届次' },
      { key: 'course_name', label: '课程计划', formatter: row => row.course_name || row.course_code || '-' },
      { key: 'score_rule', label: '成绩规则', formatter: row => scoreRuleText(row.score_rule) },
      { key: 'course_score_status', label: '汇总状态', formatter: row => courseScoreStatusText(row.course_score_status) },
      { key: 'task_count', label: '任务数' },
      { key: 'scored_task_count', label: '已评分' },
      { key: 'course_final_score', label: '课程成绩', formatter: row => row.course_final_score ?? '-' },
      { key: 'manual_score_remark', label: '核定说明' },
      { key: 'manual_score_operator_name', label: '核定人', formatter: row => row.manual_score_operator_name || row.manual_score_operator_login || '-' },
      { key: 'task_score_text', label: '任务成绩' },
    ],
    archiveMaterials: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习任务' },
      { key: 'arrangement_type_text', label: '实习类型' },
      // 暂时隐藏学期字段，后续需要时恢复。
      // { key: 'semester', label: '学期' },
      { key: 'material_progress', label: '归档进度' },
      { key: 'inspection_record_status', label: '抽检记录' },
      { key: 'missing_materials', label: '缺失材料' },
    ],
    insurances: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习任务' },
      { key: 'insurance_company', label: '保险公司' },
      { key: 'policy_number', label: '保单号' },
      { key: 'start_date', label: '开始日期' },
      { key: 'end_date', label: '结束日期' },
    ],
    safetyLetters: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习任务' },
      { key: 'signed_at', label: '签署时间' },
    ],
  };
  return fields[panel] || [];
}

function sidebarItems(win) {
  if (win.module.source === 'menu') {
    return [];
  }
  if (['message', 'doc', 'templateLib', 'exportTask', 'favorite'].includes(win.module.id)) {
    return [];
  }
  if (win.module.id === 'userManage' || archiveManageModules[win.module.id] || win.module.id === 'dataManage') {
    return [];
  }
  if (win.module.id === 'file') {
    return [{ key: 'fileManage', name: '文件列表' }];
  }
  if (win.module.id === 'internship') {
    return visibleInternshipSidebarItems.value;
  }
  if (isPracticeModule(win.module.id)) {
    return practiceSidebarItems();
  }
  if (win.module.id === 'config') {
    return configSidebarItemsForCurrentRole();
  }
  if (win.module.id === 'stat') {
    return statReports;
  }

  return [];
}

function openMessageCenter() {
  openModuleWindow(messageModule, { reuse: true });
}

async function loadMessageSummary() {
  if (!isLoggedIn.value) {
    resetMessageState();
    return;
  }

  try {
    const data = await fetchMessageSummary();
    messageState.summary = {
      unread: Number(data.unread || 0),
      by_type: data.by_type || {},
    };
  } catch (error) {
    messageState.message = error.message;
  }
}

async function loadMessages(page = messageState.pagination.page || 1) {
  if (!isLoggedIn.value || messageState.loading) {
    return;
  }

  messageState.loading = true;
  messageState.message = '';
  try {
    const data = await fetchMessages({
      page,
      page_size: messageState.pagination.page_size,
      type: messageState.filters.type,
      status: messageState.filters.status,
      keyword: messageState.filters.keyword,
    });
    messageState.items = data.items || [];
    messageState.pagination = {
      page: Number(data.pagination?.page || page),
      page_size: Number(data.pagination?.page_size || messageState.pagination.page_size),
      total: Number(data.pagination?.total || 0),
    };
    await loadMessageSummary();
  } catch (error) {
    messageState.message = error.message;
  } finally {
    messageState.loading = false;
  }
}

function resetMessageFilters() {
  messageState.filters = {
    type: 'all',
    status: 'all',
    keyword: '',
  };
  loadMessages(1);
}

function resetMessageState() {
  messageState.message = '';
  messageState.items = [];
  messageState.summary = {
    unread: 0,
    by_type: {},
  };
  messageState.pagination = {
    page: 1,
    page_size: 20,
    total: 0,
  };
}

function setMessageType(type) {
  messageState.filters.type = type;
  loadMessages(1);
}

async function handleMessageClick(item) {
  if (!item?.is_read) {
    await markSingleMessageRead(item);
  }
}

async function openMessageLink(item) {
  await handleMessageClick(item);
  const link = String(item.link_url || '').trim();
  if (!link) {
    return;
  }
  if (link.startsWith('#')) {
    window.location.hash = link;
    handleHashNavigation();
    return;
  }
  window.open(link, '_blank', 'noopener,noreferrer');
}

async function markSingleMessageRead(item) {
  if (!item?.target_id) {
    return;
  }

  try {
    const data = await markMessagesRead({ ids: [item.target_id] });
    item.is_read = true;
    item.read_at = new Date().toLocaleString();
    if (data.summary) {
      messageState.summary = data.summary;
    } else {
      await loadMessageSummary();
    }
    if (messageState.filters.status === 'unread') {
      loadMessages(messageState.pagination.page);
    }
  } catch (error) {
    messageState.message = error.message;
  }
}

async function markAllMessagesRead() {
  if (messageUnreadCount.value <= 0) {
    return;
  }

  messageState.loading = true;
  messageState.message = '';
  try {
    const data = await markMessagesRead({ all: true });
    messageState.items = messageState.items.map(item => ({
      ...item,
      is_read: true,
      read_at: item.read_at || new Date().toLocaleString(),
    }));
    messageState.summary = data.summary || { unread: 0, by_type: {} };
    if (messageState.filters.status === 'unread') {
      messageState.items = [];
      messageState.pagination = {
        ...messageState.pagination,
        page: 1,
        total: 0,
      };
    }
  } catch (error) {
    messageState.message = error.message;
  } finally {
    messageState.loading = false;
  }
}

function messageTypeUnread(type) {
  if (type === 'all') {
    return messageUnreadCount.value;
  }
  return Number(messageState.summary.by_type?.[type] || 0);
}

function messageTypeText(type) {
  return messageTypeNames[type] || type || '系统通知';
}

function messageLevelText(level) {
  return messageLevelNames[level] || level || '普通';
}

function messageLevelTagType(level) {
  if (level === 'urgent') {
    return 'danger';
  }
  if (level === 'important') {
    return 'warning';
  }
  return 'info';
}

function isOwnMessage(item) {
  return Number(item?.sender_id || 0) > 0
    && Number(item.sender_id) === Number(permissionState.context.account_id || 0);
}

function messageTargetLabel(item) {
  const name = item?.name || item?.login_name || `账号${item?.id || ''}`;
  const loginName = item?.login_name && item.login_name !== name ? ` / ${item.login_name}` : '';
  const roleName = roleTypeNames[item?.role_type] || item?.role_name || item?.role_type || '未分配角色';
  const mobile = item?.mobile ? ` / ${item.mobile}` : '';
  return `${name}${loginName} / ${roleName}${mobile}`;
}

async function openMessageSendDialog() {
  if (!canSendMessages.value) {
    messageState.message = '当前角色不能发送消息';
    return;
  }
  messageState.message = '';
  messageState.sendDialog.visible = true;
  messageState.sendDialog.form = emptyMessageSendForm();
  if (!enabledMessageTemplates.value.length) {
    Object.assign(messageState.templateFilters, { type: 'all', status: 'enabled', keyword: '' });
    await loadMessageTemplates(1);
  }
  if (!messageState.targetOptions.length) {
    await loadMessageTargets();
  }
}

function handleMessageSendModeChange() {
  const form = messageState.sendDialog.form;
  if (form.send_mode === 'template' && !form.template_code && enabledMessageTemplates.value.length) {
    form.template_code = enabledMessageTemplates.value[0].code;
    handleMessageTemplateChange();
  }
}

function handleMessageTemplateChange() {
  const template = currentMessageTemplate();
  if (!template) {
    return;
  }
  formApplyMessageTemplate(messageState.sendDialog.form, template);
}

function formApplyMessageTemplate(form, template) {
  form.type = template.type || form.type;
  form.level = template.level || form.level;
  form.link_url = '';
  if (!form.variables_text || form.variables_text === '{}') {
    form.variables_text = JSON.stringify(messageTemplateVariableDefaults(template), null, 2);
  }
}

function currentMessageTemplate() {
  const code = messageState.sendDialog.form.template_code;
  return messageState.templates.find(item => item.code === code) || null;
}

function messageTemplateVariableText(template) {
  const variables = template?.variables || {};
  if (!variables || !Object.keys(variables).length) {
    return '无';
  }
  return Object.entries(variables)
    .map(([key, value]) => `${key}：${value}`)
    .join('；');
}

function messageTemplateVariableDefaults(template) {
  const variables = template?.variables || {};
  return Object.keys(variables).reduce((defaults, key) => {
    defaults[key] = '';
    return defaults;
  }, {});
}

function handleMessageSendScopeChange() {
  const form = messageState.sendDialog.form;
  form.account_ids = [];
  if (form.send_scope !== 'role') {
    form.role_type = '';
  }
  if (form.send_scope === 'custom' && !messageState.targetOptions.length) {
    loadMessageTargets();
  }
}

function closeMessageSendDialog() {
  if (messageState.sendDialog.sending) {
    return;
  }
  messageState.sendDialog.visible = false;
}

async function loadMessageTargets() {
  if (!canSendMessages.value || messageState.targetLoading) {
    return;
  }

  messageState.targetLoading = true;
  messageState.message = '';
  try {
    const data = await fetchMessageTargets({
      keyword: messageState.sendDialog.filters.keyword,
      role_type: messageState.sendDialog.filters.role_type,
      limit: 300,
    });
    messageState.targetOptions = data.items || [];
  } catch (error) {
    messageState.message = error.message;
  } finally {
    messageState.targetLoading = false;
  }
}

async function submitMessageSend() {
  if (messageState.sendDialog.sending) {
    return;
  }

  const form = messageState.sendDialog.form;
  let variables = {};
  try {
    variables = form.send_mode === 'template' ? parseJsonObject(form.variables_text, '模板变量 JSON') : {};
  } catch (error) {
    return;
  }
  const payload = {
    send_scope: form.send_scope,
    role_type: form.send_scope === 'role' ? String(form.role_type || '').trim() : '',
    account_ids: (form.account_ids || []).map(Number).filter(Boolean),
    template_code: form.send_mode === 'template' ? String(form.template_code || '').trim() : '',
    variables,
    type: form.type,
    level: form.level,
    title: String(form.title || '').trim(),
    content: String(form.content || '').trim(),
    link_url: String(form.link_url || '').trim(),
  };

  if (payload.send_scope === 'custom' && !payload.account_ids.length) {
    messageState.message = '请选择收件人';
    return;
  }
  if (payload.send_scope === 'role' && !payload.role_type) {
    messageState.message = '请选择接收角色';
    return;
  }
  if (form.send_mode === 'template' && !payload.template_code) {
    messageState.message = '请选择消息模板';
    return;
  }
  if (form.send_mode === 'manual' && !payload.title) {
    messageState.message = '请填写消息标题';
    return;
  }
  if (form.send_mode === 'manual' && !payload.content) {
    messageState.message = '请填写消息内容';
    return;
  }
  if (payload.send_scope === 'all' && !window.confirm('确认发送给当前学校所有启用账号？')) {
    return;
  }

  messageState.sendDialog.sending = true;
  messageState.message = '';
  try {
    const data = await sendMessage(payload);
    if (data.summary) {
      messageState.summary = data.summary;
    }
    messageState.sendDialog.visible = false;
    messageState.sendDialog.form = emptyMessageSendForm();
    await loadMessages(1);
  } catch (error) {
    messageState.message = error.message;
  } finally {
    messageState.sendDialog.sending = false;
  }
}

async function openMessageTemplateManager() {
  if (!canViewMessageTemplates.value) {
    messageState.message = '当前角色不能查看消息模板';
    return;
  }
  messageState.templateManager.visible = true;
  Object.assign(messageState.templateFilters, { type: 'all', status: 'all', keyword: '' });
  messageState.templatePagination.page = 1;
  if (canManageMessageTemplates.value) {
    await syncDefaultMessageTemplates(false);
  }
  await loadMessageTemplates(1);
}

function closeMessageTemplateManager() {
  if (messageState.templateLoading || messageState.templateEdit.saving) {
    return;
  }
  messageState.templateManager.visible = false;
}

async function loadMessageTemplates(page = messageState.templatePagination.page || 1) {
  if (!(canViewMessageTemplates.value || canSendMessages.value) || messageState.templateLoading) {
    return;
  }
  messageState.templateLoading = true;
  messageState.message = '';
  try {
    if (page === 1 && shouldSyncDefaultMessageTemplates()) {
      await ensureDefaultMessageTemplates();
    }
    let data = await fetchMessageTemplates({
      page,
      page_size: messageState.templatePagination.page_size,
      type: messageState.templateFilters.type,
      status: messageState.templateFilters.status,
      keyword: messageState.templateFilters.keyword,
    });
    const shouldAutoSync = page === 1
      && canManageMessageTemplates.value
      && (data.items || []).length === 0
      && messageState.templateFilters.type === 'all'
      && ['all', 'enabled'].includes(messageState.templateFilters.status)
      && !String(messageState.templateFilters.keyword || '').trim();
    if (shouldAutoSync) {
      await syncDefaultMessageTemplates(false);
      data = await fetchMessageTemplates({
        page,
        page_size: messageState.templatePagination.page_size,
        type: messageState.templateFilters.type,
        status: messageState.templateFilters.status,
        keyword: messageState.templateFilters.keyword,
      });
    }
    messageState.templates = data.items || [];
    messageState.templatePagination = {
      ...messageState.templatePagination,
      ...(data.pagination || {}),
    };
  } catch (error) {
    messageState.message = error.message;
  } finally {
    messageState.templateLoading = false;
  }
}

async function syncDefaultMessageTemplates(showMessage = true) {
  if (!canManageMessageTemplates.value || messageState.templateSyncing) {
    return;
  }
  messageState.templateSyncing = true;
  messageState.message = '';
  try {
    const data = await syncMessageTemplates();
    messageState.templateDefaultSynced = true;
    if (showMessage) {
      await loadMessageTemplates(1);
      messageState.message = `已同步默认流程模板，当前系统模板 ${data.system_total || 0} 个`;
    }
  } catch (error) {
    messageState.message = error.message;
  } finally {
    messageState.templateSyncing = false;
  }
}

async function ensureDefaultMessageTemplates() {
  if (!canManageMessageTemplates.value || messageState.templateDefaultSynced || messageState.templateSyncing) {
    return;
  }

  messageState.templateSyncing = true;
  try {
    await syncMessageTemplates();
    messageState.templateDefaultSynced = true;
  } finally {
    messageState.templateSyncing = false;
  }
}

function shouldSyncDefaultMessageTemplates() {
  return canManageMessageTemplates.value
    && !messageState.templateDefaultSynced
    && messageState.templateFilters.type === 'all'
    && ['all', 'enabled'].includes(messageState.templateFilters.status)
    && !String(messageState.templateFilters.keyword || '').trim();
}

function openMessageTemplateEdit(row = null) {
  if (!canManageMessageTemplates.value) {
    messageState.message = '当前账号不能维护消息模板';
    return;
  }
  messageState.templateEdit.form = emptyMessageTemplateForm(row || {});
  messageState.templateEdit.visible = true;
}

function closeMessageTemplateEdit() {
  if (messageState.templateEdit.saving) {
    return;
  }
  messageState.templateEdit.visible = false;
}

async function submitMessageTemplate() {
  if (messageState.templateEdit.saving) {
    return;
  }
  const form = messageState.templateEdit.form;
  let variables = {};
  try {
    variables = parseJsonObject(form.variables_text, '变量说明 JSON');
  } catch (error) {
    return;
  }
  if (!String(form.name || '').trim()) {
    messageState.message = '请填写模板名称';
    return;
  }
  if (!String(form.code || '').trim()) {
    messageState.message = '请填写模板编码';
    return;
  }
  if (!String(form.title_tpl || '').trim()) {
    messageState.message = '请填写标题模板';
    return;
  }
  if (!String(form.content_tpl || '').trim()) {
    messageState.message = '请填写内容模板';
    return;
  }

  messageState.templateEdit.saving = true;
  messageState.message = '';
  try {
    await saveMessageTemplate({
      id: form.id || undefined,
      name: form.name,
      code: form.code,
      title_tpl: form.title_tpl,
      content_tpl: form.content_tpl,
      type: form.type,
      level: form.level,
      description: form.description,
      variables,
      link_url_tpl: form.link_url_tpl,
      status: form.status,
      sort: Number(form.sort || 100),
      is_system: form.is_system,
    });
    messageState.templateEdit.visible = false;
    await loadMessageTemplates(messageState.templatePagination.page || 1);
  } catch (error) {
    messageState.message = error.message;
  } finally {
    messageState.templateEdit.saving = false;
  }
}

async function removeMessageTemplate(row) {
  if (!row?.id || row.is_system || !window.confirm(`确认删除模板「${row.name || row.code}」？`)) {
    return;
  }
  messageState.templateLoading = true;
  messageState.message = '';
  try {
    await deleteMessageTemplate(row.id);
    messageState.templateLoading = false;
    await loadMessageTemplates(messageState.templatePagination.page || 1);
  } catch (error) {
    messageState.message = error.message;
  } finally {
    messageState.templateLoading = false;
  }
}

function parseJsonObject(value, label) {
  const text = String(value || '').trim();
  if (!text) {
    return {};
  }
  try {
    const data = JSON.parse(text);
    if (!data || Array.isArray(data) || typeof data !== 'object') {
      throw new Error();
    }
    return data;
  } catch (error) {
    messageState.message = `${label}格式不正确`;
    throw new Error(messageState.message);
  }
}

function groupMessagesByDay(items) {
  const groups = new Map();
  const sortedItems = [...(items || [])].sort((a, b) => {
    const timeA = new Date(String(a.created_at || '').replace(' ', 'T')).getTime() || 0;
    const timeB = new Date(String(b.created_at || '').replace(' ', 'T')).getTime() || 0;
    if (timeA !== timeB) {
      return timeA - timeB;
    }
    return Number(a.target_id || 0) - Number(b.target_id || 0);
  });
  sortedItems.forEach((item) => {
    const key = messageDateKey(item.date_key || item.created_at);
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        label: messageDayLabel(key),
        items: [],
      });
    }
    groups.get(key).items.push(item);
  });
  return Array.from(groups.values());
}

function messageDateKey(value) {
  const text = String(value || '');
  if (/^\d{4}-\d{2}-\d{2}/.test(text)) {
    return text.slice(0, 10);
  }
  return formatDateKey(new Date());
}

function messageDayLabel(key) {
  const today = formatDateKey(new Date());
  const yesterdayDate = new Date();
  yesterdayDate.setDate(yesterdayDate.getDate() - 1);
  const yesterday = formatDateKey(yesterdayDate);
  if (key === today) {
    return '今天';
  }
  if (key === yesterday) {
    return '昨天';
  }
  return key;
}

function messageTimeText(value) {
  if (value && typeof value === 'object') {
    return value.time_label || messageTimeText(value.created_at);
  }
  const text = String(value || '');
  if (/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(text)) {
    return text.slice(11, 16);
  }
  return text || '-';
}

function formatDateKey(date) {
  return [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
  ].join('-');
}

function dateRangeText(startDate, endDate) {
  const start = startDate || '-';
  const end = endDate || '-';
  if (start === '-' && end === '-') {
    return '-';
  }
  return `${start} 至 ${end}`;
}

function renderClock() {
  const now = new Date();
  clock.value = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
}

function moduleSearchText(module) {
  return [
    module.id,
    module.name,
    module.scope,
    module.defaultPanel,
    moduleSearchKeywords[module.id],
  ].filter(Boolean).join(' ').toLowerCase();
}

function decorateModule(module) {
  return {
    ...module,
    name: moduleDisplayName(module),
  };
}

function moduleDisplayName(module) {
  if (module.id === 'dataManage') {
    return module.name;
  }
  const item = permissionState.menus.find(menu => moduleMatchesMenu(module, menu));
  if (!item) {
    return module.name;
  }
  if (module.id === 'companyManage') {
    return companyModuleDisplayName(item, module.name);
  }
  if (configChildModuleIds.has(module.id)) {
    return item.parent_name || item.name || module.name;
  }
  return item.root_name || item.name || module.name;
}

function companyModuleDisplayName(item, fallback) {
  const ignoredNames = new Set(['系统配置', '系统设置', '配置管理']);
  return [item.parent_name, item.root_name, item.name]
    .find(name => name && !ignoredNames.has(name))
    || fallback;
}

function moduleMatchesMenu(module, menu) {
  if (!module?.viewPermission || !menu) {
    return false;
  }
  if (menu.code === module.viewPermission) {
    return true;
  }
  const path = String(menu.path || '');
  if (!path) {
    return false;
  }
  return path.split(/[/?#]/).filter(Boolean).includes(module.id);
}

function submitGlobalSearch() {
  const module = globalSearchResults.value[0];
  if (module) {
    openGlobalSearchModule(module);
  }
}

function openGlobalSearchModule(module) {
  keyword.value = '';
  if (module.id === 'message') {
    openMessageCenter();
    return;
  }
  openModule(module);
}

function openModule(module) {
  if (module?.type === 'favoriteLink' && module.url) {
    window.open(module.url, '_blank', 'noopener,noreferrer');
    return;
  }
  if (module?.source === 'menu' && module.menu?.url) {
    window.open(backendUrl(module.menu.url), '_blank', 'noopener,noreferrer');
    return;
  }
  if (module?.source === 'menu' && /^https?:\/\//i.test(String(module.menu?.path || ''))) {
    window.open(module.menu.path, '_blank', 'noopener,noreferrer');
    return;
  }
  openModuleWindow(module, { reuse: true });
}

function openDesktopLauncher() {
  desktopLauncherState.visible = true;
  if (!favoriteState.items.length && !favoriteState.loading) {
    loadFavorites(1);
  }
}

function closeDesktopLauncher() {
  desktopLauncherState.visible = false;
}

function openLauncherModule(module) {
  openModule(module);
  closeDesktopLauncher();
}

function isDesktopShortcut(moduleId) {
  return desktopLauncher.isShortcut(moduleId);
}

function isDefaultDesktopShortcut(moduleId) {
  return desktopLauncher.isDefaultShortcut(moduleId);
}

function launcherShortcutTitle(moduleId) {
  return desktopLauncher.shortcutTitle(moduleId);
}

async function toggleDesktopShortcut(moduleId) {
  if (isDefaultDesktopShortcut(moduleId) || desktopLauncherState.loading) {
    return;
  }

  const items = desktopShortcutPayloadItems.value.slice();
  const index = items.findIndex(item => item.type === 'module' && item.key === moduleId);
  if (index >= 0) {
    items.splice(index, 1);
  } else {
    items.push({ type: 'module', key: moduleId });
  }
  await persistDesktopShortcuts(items);
}

async function loadDesktopShortcuts() {
  if (!isLoggedIn.value) {
    desktopLauncher.reset();
    return;
  }

  desktopLauncherState.loading = true;
  desktopLauncherState.message = '';
  try {
    const data = await fetchDesktopShortcuts();
    desktopLauncher.setItems(data.items || []);
  } catch (error) {
    desktopLauncher.setItems([]);
    desktopLauncherState.message = error.message;
  } finally {
    desktopLauncherState.loading = false;
  }
}

async function persistDesktopShortcuts(items) {
  desktopLauncherState.loading = true;
  desktopLauncherState.message = '';
  const previous = desktopLauncherState.items.slice();
  desktopLauncher.setItems(items);
  try {
    const data = await saveDesktopShortcuts({ items });
    desktopLauncher.setItems(data.items || []);
    await nextTick();
    syncShortcutWorkspace();
  } catch (error) {
    desktopLauncherState.items = previous;
    desktopLauncherState.message = error.message;
  } finally {
    desktopLauncherState.loading = false;
  }
}

function syncShortcutWorkspace() {
  syncOpenWindowModules();
  ensureDefaultWindow();
}

function defaultWindowModule() {
  return defaultDesktopModuleIds
    .map(id => allLaunchableModules.value.find(module => module.id === id))
    .find(Boolean)
    || mainEntryModules.value.find(module => module.id !== 'config')
    || mainEntryModules.value[0]
    || null;
}

function isPrimaryWorkWindow(win) {
  return win?.module?.id && !['profile', 'favorite', 'message'].includes(win.module.id);
}

function ensureDefaultWindow() {
  const defaultModule = defaultWindowModule();
  if (!defaultModule) {
    return;
  }
  if (!openWindows.length && defaultModule) {
    openModule(defaultModule);
    return;
  }
  if (!openWindows.some(isPrimaryWorkWindow) && isPrimaryWorkWindow({ module: defaultModule })) {
    openModule(defaultModule);
  }
}

function scheduleDefaultWindow() {
  [200, 1000].forEach((delay) => {
    window.setTimeout(() => {
      if (isLoggedIn.value) {
        ensureDefaultWindow();
        handleHashNavigation();
      }
    }, delay);
  });
}

function moduleHref(module) {
  return `#module=${encodeURIComponent(module.id)}`;
}

function panelHref(win, panel) {
  return `#panel=${encodeURIComponent(win.module.id)}:${encodeURIComponent(panel)}`;
}

async function openGuide(win) {
  guideState.title = `${win.module.name}操作说明`;
  guideState.content = fallbackGuideContent(win);
  guideState.message = '';
  guideState.loading = true;
  guideState.visible = true;
  try {
    const data = await fetchOperationGuide(win.module.id);
    if (data.guide?.title) {
      guideState.title = data.guide.title;
    }
    if (data.guide?.content) {
      guideState.content = data.guide.content;
    }
  } catch (error) {
    guideState.message = error.message;
  } finally {
    guideState.loading = false;
  }
}

function closeGuide() {
  guideState.visible = false;
}

function emptyFavoriteForm(row = {}) {
  return {
    id: row.id || null,
    title: row.title || '',
    url: row.url || '',
    icon_url: row.icon_url || '',
    icon_file_id: row.icon_file_id || null,
    sort: row.sort || 0,
    add_to_desktop: false,
  };
}

async function loadFavorites(page = favoriteState.pagination.page || 1) {
  if (!isLoggedIn.value) {
    favoriteState.items = [];
    favoriteState.pagination.total = 0;
    return;
  }

  favoriteState.loading = true;
  favoriteState.message = '';
  try {
    const data = await fetchFavorites({
      ...favoriteState.filters,
      page,
      page_size: favoriteState.pagination.page_size,
    });
    favoriteState.items = data.items || [];
    favoriteState.pagination = {
      ...favoriteState.pagination,
      ...(data.pagination || {}),
      page,
    };
  } catch (error) {
    favoriteState.message = error.message;
  } finally {
    favoriteState.loading = false;
  }
}

function openFavoriteDialog(row = null) {
  favoriteState.editing = emptyFavoriteForm(row || {});
  favoriteState.editing.add_to_desktop = row?.id ? isFavoriteDesktop(row.id) : true;
  favoriteState.message = '';
  favoriteState.dialogVisible = true;
}

function closeFavoriteDialog() {
  favoriteState.dialogVisible = false;
}

async function saveFavoriteItem() {
  if (favoriteState.loading) {
    return;
  }

  favoriteState.loading = true;
  favoriteState.message = '';
  const form = { ...favoriteState.editing };
  try {
    const data = await saveFavorite({
      ...form,
      sort: Number(form.sort || 0),
    });
    favoriteState.items = data.items || [];
    favoriteState.pagination = {
      ...favoriteState.pagination,
      ...(data.pagination || {}),
    };
    const savedRow = resolveSavedFavoriteRow(data.items || [], form);
    if (savedRow?.id) {
      await setFavoriteDesktopShortcut(savedRow, Boolean(form.add_to_desktop));
    }
    favoriteState.dialogVisible = false;
    favoriteState.message = '已保存';
  } catch (error) {
    favoriteState.message = error.message;
  } finally {
    favoriteState.loading = false;
  }
}

async function deleteFavoriteItem(row) {
  if (!row?.id || !window.confirm(`确认删除收藏「${row.title || row.url}」？`)) {
    return;
  }

  favoriteState.loading = true;
  favoriteState.message = '';
  try {
    const data = await deleteFavorite(row.id);
    favoriteState.items = data.items || [];
    favoriteState.pagination = {
      ...favoriteState.pagination,
      ...(data.pagination || {}),
    };
    await loadDesktopShortcuts();
    favoriteState.message = '已删除';
  } catch (error) {
    favoriteState.message = error.message;
  } finally {
    favoriteState.loading = false;
  }
}

function openFavoriteLink(row) {
  if (row?.url) {
    window.open(row.url, '_blank', 'noopener,noreferrer');
  }
}

function handleFavoriteCommand(row, command) {
  if (command === 'open') {
    openFavoriteLink(row);
    return;
  }
  if (command === 'edit') {
    openFavoriteDialog(row);
    return;
  }
  if (command === 'desktop') {
    toggleFavoriteDesktop(row);
    return;
  }
  if (command === 'delete') {
    deleteFavoriteItem(row);
  }
}

function isFavoriteDesktop(id) {
  return customDesktopShortcutItems.value.some(item => item.type === 'favorite' && Number(item.ref_id) === Number(id));
}

async function toggleFavoriteDesktop(row) {
  if (!row?.id || desktopLauncherState.loading) {
    return;
  }

  await setFavoriteDesktopShortcut(row, !isFavoriteDesktop(row.id));
}

async function setFavoriteDesktopShortcut(row, enabled) {
  if (!row?.id) {
    return;
  }
  const items = desktopShortcutPayloadItems.value.slice();
  const index = items.findIndex(item => item.type === 'favorite' && Number(item.ref_id) === Number(row.id));
  if (!enabled && index >= 0) {
    items.splice(index, 1);
  } else if (enabled && index < 0) {
    items.push({ type: 'favorite', ref_id: Number(row.id) });
  } else {
    return;
  }
  await persistDesktopShortcuts(items);
}

function resolveSavedFavoriteRow(items, form) {
  if (!Array.isArray(items)) {
    return null;
  }
  if (form.id) {
    const byId = items.find(item => Number(item.id) === Number(form.id));
    if (byId) {
      return byId;
    }
  }
  return items.find(item => String(item.url || '') === String(form.url || '') && String(item.title || '') === String(form.title || '')) || null;
}

function favoriteInitial(row) {
  const value = String(row?.title || row?.url || '收').trim();
  return value.charAt(0).toUpperCase() || '收';
}

function favoriteHost(row) {
  const value = String(row?.url || '').trim();
  if (!value) {
    return '未填写地址';
  }
  try {
    return new URL(value).host || value;
  } catch {
    return value.replace(/^https?:\/\//i, '').split('/')[0] || value;
  }
}

function favoriteCardClass(row, index = 0) {
  const themes = ['mint', 'green', 'tan', 'blue', 'cyan', 'purple', 'gold', 'rose'];
  const source = Number(row?.id || 0) || index + 1;
  return `favorite-card-${themes[Math.abs(source) % themes.length]}`;
}

async function handleFavoriteIconSelected(file) {
  if (!file) {
    return;
  }
  favoriteState.iconUploading = true;
  favoriteState.message = '';
  try {
    const data = await uploadFavoriteIcon(file);
    favoriteState.editing.icon_url = data.url || '';
    favoriteState.editing.icon_file_id = data.file_id || null;
  } catch (error) {
    favoriteState.message = error.message;
  } finally {
    favoriteState.iconUploading = false;
  }
}

function fallbackGuideContent(win) {
  const common = [
    {
      title: '窗口操作',
      lines: ['窗口可拖动、最小化、关闭，也可以拖拽边缘调整大小。', '底部任务栏图标用于恢复已最小化窗口。'],
    },
  ];
  const map = {
    internship: [
      { title: '实习业务', lines: ['学生端以管理员分配的本人实习任务为主，按任务提交签到、日志、报告和材料。', '教师端默认按任务查看绑定学生，审核类操作会进入确认弹窗并记录流程。', '管理员端通过计划、任务、班级绑定、记录查看和归档材料完成过程监管。'] },
    ],
    config: [
      { title: '系统配置', lines: ['菜单管理用于维护主菜单、业务菜单、列表和按钮节点。', '角色权限按菜单树授权，按钮节点用于控制页面内操作。', '组织范围用于配置学院、专业、班级、基地等数据边界。'] },
    ],
    log: [
      { title: '日志审计', lines: ['默认读取当前学校下所有操作日志。', '可按关键词、动作、IP 和日期范围查询。'] },
    ],
    stat: [
      { title: '统计报表', lines: ['统计项按当前角色的数据范围展示。', '后续实训和实验流程确认后，可在此扩展跨模块统计卡片和报表。'] },
    ],
    file: [
      { title: '文件管理', lines: ['展示学校上传文件、上传人、上传时间和设备信息。', '管理员可通过关键词、状态和分类快速定位文件。'] },
    ],
  };

  return [...(map[win.module.id] || [{ title: '模块说明', lines: ['该模块流程确认后接入具体业务页面。'] }]), ...common]
    .map(section => `<section><h3>${escapeHtml(section.title)}</h3>${section.lines.map(line => `<p>${escapeHtml(line)}</p>`).join('')}</section>`)
    .join('');
}

function emptyGuideForm() {
  return {
    module_key: '',
    title: '',
    content: '',
    sort: 0,
  };
}

function guideModuleName(moduleKey) {
  return modules.find(item => item.id === moduleKey)?.name || moduleKey || '-';
}

function guideContentPreview(content) {
  const text = stripHtml(content).replace(/\s+/g, ' ').trim();
  return text ? text.slice(0, 96) : '-';
}

function stripHtml(content) {
  const div = document.createElement('div');
  div.innerHTML = content || '';
  return div.textContent || div.innerText || '';
}

function guideTemplateHtml(title = '操作说明') {
  return [
    `<section><h3>${escapeHtml(title)}操作流程</h3><ol><li>进入对应模块，按角色查看可处理事项。</li><li>根据页面提示完成提交、审核、退回或查看记录。</li><li>审核类操作会写入流程记录和审核意见。</li></ol></section>`,
    '<section><h3>流程规则</h3><p>学生仅处理本人任务，教师按任务绑定处理学生，管理员按届次、学院、专业和班级范围处理。</p></section>',
    '<section><h3>常见问题</h3><ul><li>看不到数据时，先确认当前角色和组织范围是否正确。</li><li>材料或记录异常时，可通过记录查看追溯每次提交和审核。</li></ul></section>',
  ].join('');
}

async function loadGuideAdminItems(force = false) {
  if (!canManageConfig.value || guideAdminState.loading) {
    return;
  }
  if (!force && guideAdminState.items.length) {
    return;
  }

  guideAdminState.loading = true;
  guideAdminState.message = '';
  try {
    const data = await fetchOperationGuides();
    guideAdminState.items = data.items || [];
  } catch (error) {
    guideAdminState.message = error.message;
  } finally {
    guideAdminState.loading = false;
  }
}

function openGuideAdminDialog(item = null) {
  if (!item) {
    guideAdminState.selected = null;
    guideAdminState.editing = {
      ...emptyGuideForm(),
      module_key: defaultGuideModuleKey(),
      content: guideTemplateHtml('操作说明'),
    };
    guideAdminState.dialogMode = 'create';
    guideAdminState.dialogVisible = true;
    nextTick(syncGuideEditorDom);
    return;
  }

  guideAdminState.selected = item;
  guideAdminState.editing = {
    module_key: item.module_key || '',
    title: item.title || '',
    content: item.content || '',
    sort: Number(item.sort || 0),
  };
  guideAdminState.dialogMode = 'edit';
  guideAdminState.dialogVisible = true;
  nextTick(syncGuideEditorDom);
}

function closeGuideAdminDialog() {
  guideAdminState.dialogVisible = false;
}

function defaultGuideModuleKey() {
  const usedKeys = new Set(guideAdminState.items.map(item => item.module_key));
  const unused = guideModuleOptions.value.find(item => !usedKeys.has(item.value));
  return unused?.value || guideModuleOptions.value[0]?.value || '';
}

function syncGuideEditorDom() {
  const editor = activeGuideEditor();
  if (editor && editor.innerHTML !== guideAdminState.editing.content) {
    editor.innerHTML = guideAdminState.editing.content || '';
  }
}

function activeGuideEditor() {
  const editor = guideEditorRef.value;
  return Array.isArray(editor) ? editor.at(-1) : editor;
}

function syncGuideEditor() {
  const editor = activeGuideEditor();
  guideAdminState.editing.content = editor?.innerHTML || '';
}

function applyGuideFormat(command) {
  const editor = activeGuideEditor();
  if (!editor) {
    return;
  }
  editor.focus();
  document.execCommand(command, false, null);
  syncGuideEditor();
}

function insertGuideTemplate() {
  const editor = activeGuideEditor();
  const html = guideTemplateHtml(guideModuleName(guideAdminState.editing.module_key));
  if (!editor) {
    guideAdminState.editing.content = html;
    return;
  }

  editor.focus();
  if (!guideAdminState.editing.content.trim()) {
    editor.innerHTML = html;
  } else {
    document.execCommand('insertHTML', false, html);
  }
  syncGuideEditor();
}

async function saveGuideAdminItem() {
  if (!guideAdminState.editing.module_key || !hasPermission('guide:save')) {
    return;
  }

  syncGuideEditor();
  guideAdminState.loading = true;
  guideAdminState.message = '';
  try {
    const data = await saveOperationGuide({
      module_key: guideAdminState.editing.module_key,
      title: guideAdminState.editing.title,
      content: guideAdminState.editing.content,
      sort: Number(guideAdminState.editing.sort || 0),
    });
    guideAdminState.items = data.items || [];
    guideAdminState.selected = guideAdminState.items.find(item => item.module_key === guideAdminState.editing.module_key) || null;
    guideAdminState.message = '已保存';
    closeGuideAdminDialog();
  } catch (error) {
    guideAdminState.message = error.message;
  } finally {
    guideAdminState.loading = false;
  }
}

async function deleteGuideAdminItem(row) {
  if (!row?.id || !hasPermission('guide:delete')) {
    return;
  }
  if (!window.confirm(`确认删除「${guideModuleName(row.module_key)}」的操作说明？`)) {
    return;
  }

  guideAdminState.loading = true;
  guideAdminState.message = '';
  try {
    const data = await deleteOperationGuide(row.id);
    guideAdminState.items = data.items || [];
    if (guideAdminState.selected?.id === row.id) {
      guideAdminState.selected = null;
      guideAdminState.editing = emptyGuideForm();
    }
    guideAdminState.message = '已删除';
  } catch (error) {
    guideAdminState.message = error.message;
  } finally {
    guideAdminState.loading = false;
  }
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function windowHref(win) {
  return `#window=${encodeURIComponent(win.id)}`;
}

function normalizeConfigPanel(panel) {
  const visibleItems = configSidebarItemsForCurrentRole();
  if (!visibleItems.length || visibleItems.some(item => item.key === panel)) {
    return panel;
  }
  return visibleItems[0].key;
}

function defaultPanelForModule(module) {
  if (module.id === 'config') {
    return normalizeConfigPanel(module.defaultPanel || 'menuManage');
  }
  return module.defaultPanel || 'overview';
}

function openModuleWindow(module, options = {}) {
  const existing = options.reuse ? openWindows.find(win => win.module.id === module.id) : null;
  if (existing) {
    existing.minimized = false;
    focusWindow(existing.id);
    if (module.id === 'stat') {
      statState.report = statReports.some(item => item.key === existing.panel) ? existing.panel : 'overview';
      loadStats(statState.pagination.page || 1);
    }
    if (module.id === 'config' || module.id === 'userManage' || archiveManageModules[module.id] || module.id === 'dataManage') {
      activateWindowPanel(existing, module.id === 'config' ? normalizeConfigPanel(existing.panel) : existing.panel);
    }
    if (module.id === 'message') {
      loadMessages(messageState.pagination.page || 1);
    }
    if (module.id === 'favorite') {
      loadFavorites(favoriteState.pagination.page || 1);
    }
    if (isPracticeModule(module.id)) {
      loadPracticePanel(module.id, existing.panel);
    }
    return;
  }

  const index = openWindows.length;
  const frame = defaultWindowFrame(index);
  const win = {
    id: `${module.id}-${Date.now()}`,
    module,
    panel: defaultPanelForModule(module),
    minimized: false,
    left: frame.left,
    top: frame.top,
    width: frame.width,
    height: frame.height,
    zIndex: ++zIndexSeed.value,
  };
  openWindows.push(win);
  focusedWindowId.value = win.id;
  if (module.id === 'internship') {
    loadInternshipPanel(win.panel);
  }
  if (isPracticeModule(module.id)) {
    loadPracticePanel(module.id, win.panel);
  }
  if (module.id === 'stat') {
    statState.report = win.panel;
    loadStats(1);
  }
  if (module.id === 'config' || module.id === 'userManage' || archiveManageModules[module.id] || module.id === 'dataManage') {
    activateWindowPanel(win, win.panel);
  }
  if (module.id === 'message') {
    loadMessages(1);
  }
  if (module.id === 'favorite') {
    loadFavorites(1);
  }
}

function defaultWindowFrame(index = 0) {
  const viewportWidth = window.innerWidth || 1280;
  const viewportHeight = window.innerHeight || 760;
  const iconReserve = viewportWidth >= 900 ? 390 : 120;
  const minWidth = viewportWidth >= 900 ? 680 : Math.min(680, viewportWidth);
  const left = Math.min(iconReserve + index * 28, Math.max(24, viewportWidth - minWidth - 30));
  const top = 60 + index * 24;
  const width = Math.max(minWidth, Math.min(1120, viewportWidth - left - 30));
  const height = Math.max(500, Math.min(680, viewportHeight - top - 70));

  return { left, top, width, height };
}

function openProfile(section = '') {
  const profileModule = modules.find(item => item.id === 'profile');
  if (profileModule) {
    openModuleWindow(profileModule, { reuse: true });
  }
  loginPageState.message = '';
  if (section === 'wallpaper') {
    profileState.focus = 'wallpaper';
    nextTick(() => {
      window.setTimeout(() => {
        wallpaperSectionRef.value?.scrollIntoView({ block: 'center', behavior: 'smooth' });
      }, 80);
      window.setTimeout(() => {
        if (profileState.focus === 'wallpaper') {
          profileState.focus = '';
        }
      }, 1800);
    });
  }
}

function openDesktopContextMenu(event) {
  const target = event.target instanceof Element ? event.target : event.target?.parentElement;
  if (!isLoggedIn.value || target?.closest('.desktop-window, .topbar, .taskbar, .login-layer, .desktop-context-menu')) {
    return;
  }
  const width = 172;
  const height = 92;
  desktopContextMenu.x = Math.min(event.clientX, window.innerWidth - width - 8);
  desktopContextMenu.y = Math.min(event.clientY, window.innerHeight - height - 8);
  desktopContextMenu.visible = true;
}

function closeDesktopContextMenu() {
  desktopContextMenu.visible = false;
}

function openWallpaperSettings() {
  closeDesktopContextMenu();
  openProfile('wallpaper');
}

function focusWindow(id) {
  const win = openWindows.find(item => item.id === id);
  if (!win) {
    return;
  }
  win.zIndex = ++zIndexSeed.value;
  focusedWindowId.value = id;
}

function minimizeWindow(id) {
  const win = openWindows.find(item => item.id === id);
  if (!win) {
    return;
  }
  win.minimized = true;
  if (focusedWindowId.value === id) {
    focusedWindowId.value = visibleWindows.value.at(-1)?.id || null;
  }
}

function closeWindow(id) {
  const index = openWindows.findIndex(item => item.id === id);
  if (index === -1) {
    return;
  }
  openWindows.splice(index, 1);
  if (focusedWindowId.value === id) {
    focusedWindowId.value = visibleWindows.value.at(-1)?.id || openWindows.at(-1)?.id || null;
  }
}

function toggleTaskWindow(id) {
  const win = openWindows.find(item => item.id === id);
  if (!win) {
    return;
  }
  if (focusedWindowId.value === id && !win.minimized) {
    minimizeWindow(id);
    return;
  }
  win.minimized = false;
  focusWindow(id);
}

async function handleHashNavigation() {
  const hash = window.location.hash.slice(1);
  if (!hash) {
    return;
  }

  if (hash.startsWith('internship-action=')) {
    const [listKey, action, id] = hash.slice(18).split(':').map(value => decodeURIComponent(value || ''));
    await handleInternshipHashAction(listKey, action, id);
    clearNavigationHash();
    return;
  }

  if (hash.startsWith('module=')) {
    const moduleId = decodeURIComponent(hash.slice(7));
    const module = allLaunchableModules.value.find(item => item.id === moduleId);
    if (module) {
      openModule(module);
      clearNavigationHash();
    }
    return;
  }

  if (hash.startsWith('window=')) {
    const windowId = decodeURIComponent(hash.slice(7));
    const win = openWindows.find(item => item.id === windowId);
    if (win) {
      win.minimized = false;
      focusWindow(win.id);
      clearNavigationHash();
    }
    return;
  }

  if (hash.startsWith('panel=')) {
    const value = hash.slice(6);
    const separator = value.indexOf(':');
    if (separator > 0) {
      const moduleId = decodeURIComponent(value.slice(0, separator));
      const panel = decodeURIComponent(value.slice(separator + 1));
      let win = openWindows.find(item => item.module.id === moduleId);
      if (!win) {
        const module = allLaunchableModules.value.find(item => item.id === moduleId);
        if (module) {
          openModuleWindow(module, { reuse: true });
          win = openWindows.at(-1);
        }
      }
      if (win) {
        win.minimized = false;
        focusWindow(win.id);
        nextTick(() => {
          window.setTimeout(() => activateWindowPanel(win, panel), 50);
        });
        clearNavigationHash();
      }
    }
  }
}

function clearNavigationHash() {
  history.replaceState(null, '', window.location.pathname + window.location.search);
}

function isModuleFocused(moduleId) {
  const focused = openWindows.find(win => win.id === focusedWindowId.value);
  return focused?.module.id === moduleId && !focused.minimized;
}

function syncOpenWindowModules() {
  openWindows.forEach((win) => {
    const module = allLaunchableModules.value.find(item => item.id === win.module.id);
    if (module) {
      win.module = module;
    }
  });
}

function activateWindowPanel(win, panel) {
  if (!win) {
    return;
  }
  if (win.module.id === 'config') {
    panel = normalizeConfigPanel(panel);
  }
  win.panel = panel;
  focusWindow(win.id);

  if (isUserManageWindow(win)) {
    loadAdminFoundation();
    loadUserAccounts();
  }
  if (isArchiveManageWindow(win)) {
    loadAdminFoundation();
    loadArchiveItems(archiveTypeForWindow(win));
  }
  if (win.module.id === 'dataManage') {
    loadAdminFoundation();
  }
  if (win.module.id === 'file' && panel === 'fileManage') {
    loadFiles();
  }
  if (win.module.id === 'favorite') {
    loadFavorites();
  }
  if (win.module.id === 'config' && panel === 'operationGuides') {
    loadGuideAdminItems();
  }
  if (win.module.id === 'stat') {
    statState.report = statReports.some(item => item.key === panel) ? panel : 'overview';
    loadStats(1);
  }
  if (win.module.id === 'internship') {
    loadInternshipPanel(panel);
  }
  if (isPracticeModule(win.module.id)) {
    loadPracticePanel(win.module.id, panel);
  }
}

function focusLogin() {
  loginNameInput.value?.focus();
}

function applyRegisterOptions(data = {}) {
  const roles = (data.roles || []).filter(role => ['student', 'teacher'].includes(role.role_type));
  registerState.enabled = data.enabled === true;
  registerState.roles = roles;
  if (roles.length && !roles.some(role => role.role_type === registerForm.role_type)) {
    registerForm.role_type = roles[0].role_type;
  }
  if (!registerState.enabled) {
    registerState.open = false;
  }
}

async function loadRegisterOptions() {
  registerState.message = '';
  try {
    applyRegisterOptions(await fetchRegisterOptions());
  } catch (error) {
    registerState.enabled = false;
    registerState.open = false;
  }
}

function toggleRegisterForm() {
  registerState.open = !registerState.open;
  registerState.message = '';
}

async function submitRegister() {
  if (!registerState.enabled || registerState.loading) {
    return;
  }
  if (!registerForm.name.trim() || !registerForm.login_name.trim() || !registerForm.password.trim()) {
    registerState.message = '姓名、账号和密码不能为空';
    return;
  }

  registerState.loading = true;
  registerState.message = '';
  try {
    await registerAccount({
      ...registerForm,
      client: 'WEB',
    });
    registerState.open = false;
    await refreshAuthenticatedSession(true);
  } catch (error) {
    registerState.message = error.message;
  } finally {
    registerState.loading = false;
  }
}

async function submitLogin() {
  if (loginState.loading) {
    return;
  }
  loginState.loading = true;
  loginState.message = '';
  try {
    await loginApi({
      login_name: loginForm.login_name,
      password: loginForm.password,
      client: 'WEB',
    });
    await refreshAuthenticatedSession(true);
  } catch (error) {
    loginState.message = error.message;
  } finally {
    loginState.loading = false;
  }
}

async function refreshAuthenticatedSession(resetWorkspace = false) {
  if (resetWorkspace) {
    resetAdminState();
    resetProfileState();
    resetMessageState();
    resetInternshipState();
    openWindows.splice(0);
    focusedWindowId.value = null;
  }

  await load();
  await loadProfile();
  await loadDesktopShortcuts();
  await loadProxy();
  await loadMessageSummary();
  await loadSwitchableAccounts();
  ensureDefaultWindow();
  handleHashNavigation();
  scheduleDefaultWindow();
  loadAdminFoundation();
}

async function loadSwitchableAccounts() {
  if (!isLoggedIn.value) {
    switchAccountState.open = false;
    switchAccountState.items = [];
    switchAccountState.message = '';
    return;
  }

  switchAccountState.loading = true;
  switchAccountState.message = '';
  try {
    const data = await fetchSwitchableAccounts();
    switchAccountState.items = data.accounts || [];
    if (!switchableLoginAccounts.value.length) {
      switchAccountState.open = false;
    }
  } catch (error) {
    switchAccountState.open = false;
    switchAccountState.items = [];
    switchAccountState.message = error.message;
  } finally {
    switchAccountState.loading = false;
  }
}

function toggleSwitchAccountMenu() {
  if (switchAccountState.loading || !switchableLoginAccounts.value.length) {
    switchAccountState.open = false;
    return;
  }
  switchAccountState.open = !switchAccountState.open;
}

async function switchLoginAccount(accountId) {
  const targetId = Number(accountId || 0);
  if (!targetId || targetId === Number(permissionState.context.account_id || 0) || switchAccountState.loading) {
    switchAccountState.open = false;
    return;
  }

  switchAccountState.loading = true;
  switchAccountState.open = false;
  switchAccountState.message = '';
  try {
    await switchAccount({
      account_id: targetId,
      client: 'WEB',
    });
    await refreshAuthenticatedSession(true);
    switchAccountState.message = `已切换为 ${operatorName.value}`;
  } catch (error) {
    switchAccountState.message = error.message;
  } finally {
    switchAccountState.loading = false;
  }
}

function switchAccountName(account) {
  return account?.name || account?.login_name || '未命名账号';
}

function switchAccountMeta(account) {
  const roleName = account?.role_name || roleTypeNames[account?.role_type] || account?.role_type || '未分配角色';
  const loginName = account?.login_name ? ` / ${account.login_name}` : '';
  return `${roleName}${loginName}`;
}

async function consumeUrlPasskey() {
  const params = new URLSearchParams(window.location.search);
  const passkey = params.get('passkey') || params.get('login_key');
  if (!passkey) {
    return false;
  }

  loginState.loading = true;
  loginState.message = '';
  try {
    await passkeyLogin({
      passkey,
      client: 'WEB',
    });
    params.delete('passkey');
    params.delete('login_key');
    const query = params.toString();
    history.replaceState(null, '', `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`);
    return true;
  } catch (error) {
    loginState.message = error.message;
    return false;
  } finally {
    loginState.loading = false;
  }
}

async function submitLogout() {
  if (loginState.loading) {
    return;
  }
  loginState.loading = true;
  loginState.message = '';
  try {
    await logoutApi();
    resetAdminState();
    resetProfileState();
    resetMessageState();
    switchAccountState.items = [];
    switchAccountState.message = '';
    desktopLauncherState.items = [];
    desktopLauncherState.message = '';
    resetFavoriteState();
    openWindows.splice(0);
    focusedWindowId.value = null;
    await load();
    await loadLoginPageSettings();
    await loadRegisterOptions();
  } catch (error) {
    loginState.message = error.message;
  } finally {
    loginState.loading = false;
  }
}

function emptyProfile() {
  const cachedDesktop = readWallpaperCache();
  return {
    name: '',
    avatar: '',
    mobile: '',
    email: '',
    wallpaper: cachedDesktop.wallpaper || 'default',
    wallpaper_url: cachedDesktop.wallpaper_url || '',
    notify: {
      system: true,
      wechat: true,
      email: false,
    },
  };
}

function readWallpaperCache() {
  try {
    const value = localStorage.getItem(wallpaperCacheKey);
    const data = value ? JSON.parse(value) : {};
    return typeof data === 'object' && data ? data : {};
  } catch {
    return {};
  }
}

function cacheWallpaper() {
  try {
    localStorage.setItem(wallpaperCacheKey, JSON.stringify({
      wallpaper: profileState.form.wallpaper,
      wallpaper_url: profileState.form.wallpaper_url,
    }));
  } catch {}
}

function emptyMenu() {
  return {
    id: null,
    parent_id: 0,
    name: '',
    code: '',
    path: '',
    url: '',
    platform: 'both',
    type: 'menu',
    sort: 0,
    icon: '',
    is_module: 'false',
    module_key: '',
    icon_url: '',
    icon_file_id: null,
    visible: 'true',
    status: 'enabled',
  };
}

function applyMenusData(data) {
  adminState.menus = normalizeMenuTree(data.menus || []);
  adminState.menu.items = data.items || [];
  setMenuTreeExpanded(adminState.menu.expanded);
}

function normalizeMenuTree(items, depth = 1) {
  return items.map(item => ({
    ...item,
    depth,
    children: normalizeMenuTree(item.children || [], depth + 1),
  }));
}

function selectableParentMenus(nodes) {
  return nodes
    .filter(node => node.id !== adminState.menu.editing.id && node.type !== 'button')
    .map(node => ({
      ...node,
      name: `${node.name}（${menuNodeKind(node)}）`,
      children: selectableParentMenus(node.children || []),
    }));
}

function menuNodeKind(node) {
  if (Number(node.parent_id || 0) === 0) {
    return '主菜单';
  }
  if (node.type === 'button') {
    return '按钮';
  }
  if (node.type === 'list' || (node.children || []).some(child => child.type === 'button')) {
    return '列表';
  }
  return '菜单';
}

function menuNodeTypeText(type) {
  const names = {
    directory: '目录',
    menu: '菜单',
    list: '列表',
    button: '按钮',
  };
  return names[type] || type || '-';
}

function filterMenuTree() {
  activeMenuTree()?.filter(adminState.menu.keyword || '');
}

function filterMenuNode(value, data) {
  const keywordValue = String(value || '').trim().toLowerCase();
  if (!keywordValue) {
    return true;
  }
  return [
    data.name,
    data.code,
    data.path,
    data.url,
    menuNodeTypeText(data.type),
    data.platform,
  ].filter(Boolean).some(item => String(item).toLowerCase().includes(keywordValue));
}

async function setMenuTreeExpanded(expanded) {
  adminState.menu.expanded = expanded;
  adminState.menu.keyword = '';
  adminState.menu.expandedKeys = expanded ? menuTreeNodeIds(adminState.menus) : [];
  adminState.menu.treeKey += 1;
  await nextTick();
  const tree = activeMenuTree();
  if (tree?.$el) {
    tree.$el.scrollTop = 0;
  }
  tree?.filter('');
}

function menuTreeNodeIds(nodes) {
  return (nodes || []).flatMap(node => [
    node.id,
    ...menuTreeNodeIds(node.children || []),
  ]).filter(Boolean);
}

function menuModuleChildren(menuId) {
  if (!menuId) {
    return [];
  }
  return (permissionState.menus || [])
    .filter(item => Number(item.parent_id || 0) === Number(menuId) && item.visible !== 'false')
    .filter(item => ['directory', 'menu', 'list'].includes(item.type || 'menu'));
}

function openMenuModuleChild(item) {
  if (item?.url) {
    window.open(backendUrl(item.url), '_blank', 'noopener,noreferrer');
    return;
  }
  if (item?.path && /^https?:\/\//i.test(item.path)) {
    window.open(item.path, '_blank', 'noopener,noreferrer');
    return;
  }
  if (item?.path) {
    window.location.hash = item.path.startsWith('#') ? item.path : `#${item.path.replace(/^\/+/, '')}`;
  }
}

function activeMenuTree() {
  const tree = menuTreeRef.value;
  return Array.isArray(tree) ? tree.at(-1) : tree;
}

function selectMenu(row) {
  adminState.menu.selected = row;
  adminState.menu.message = '';
}

function openMenuDialog(row = null, parent = null) {
  if (row) {
    editMenu(row);
    adminState.menu.dialogMode = 'edit';
  } else {
    newMenu(parent);
    adminState.menu.dialogMode = 'create';
  }
  adminState.menu.dialogVisible = true;
}

function closeMenuDialog() {
  adminState.menu.dialogVisible = false;
}

function emptyUserForm(row = {}) {
  return {
    id: row.id || null,
    name: row.name || '',
    login_name: row.login_name || '',
    role_id: row.role_id || null,
    role_type: row.role_type || '',
    mobile: row.mobile || '',
    email: row.email || '',
    student_num: row.student_num || '',
    teacher_num: row.teacher_num || '',
    grade_id: row.grade_id || null,
    dep_id: row.dep_id || null,
    profession_id: row.profession_id || null,
    class_id: row.class_id || null,
    class_num: row.class_num || '',
    status: row.status || 'enabled',
    password: '',
  };
}

function emptyUserDetail() {
  return {
    account: null,
    boundAccounts: [],
    wechatAccounts: [],
    logs: [],
  };
}

function manageableUserRoles() {
  return adminState.roles.filter(role => permissionState.context.role_type === 'super_admin' || role.role_type !== 'super_admin');
}

function userSelectedRoleType() {
  const role = adminState.roles.find(item => Number(item.id) === Number(userAdminState.editing.role_id || 0));
  return role?.role_type || userAdminState.editing.role_type || '';
}

function handleUserRoleChange() {
  userAdminState.editing.role_type = userSelectedRoleType();
  if (userAdminState.editing.role_type !== 'student') {
    userAdminState.editing.student_num = '';
    userAdminState.editing.grade_id = null;
    userAdminState.editing.class_id = null;
    userAdminState.editing.class_num = '';
  }
  if (userAdminState.editing.role_type !== 'teacher') {
    userAdminState.editing.teacher_num = '';
  }
  if (!['student', 'teacher'].includes(userAdminState.editing.role_type)) {
    userAdminState.editing.dep_id = null;
    userAdminState.editing.profession_id = null;
  }
}

function userAcademicOptions() {
  return {
    departments: adminState.options.departments,
    professions: adminState.options.professions,
    classes: adminState.options.classes,
  };
}

function userEditDepartmentOptions() {
  return filterAcademicDepartments(userAcademicOptions(), userAdminState.editing);
}

function userEditProfessionOptions() {
  const keys = userEditingRoleType.value === 'student' ? ['grade_id', 'dep_id'] : ['dep_id'];
  return filterAcademicItems(adminState.options.professions, userAdminState.editing, keys);
}

function userEditClassOptions() {
  return filterAcademicItems(adminState.options.classes, userAdminState.editing, ['grade_id', 'dep_id', 'profession_id']);
}

function handleUserAcademicChange(field) {
  normalizeFilterCascade(userAdminState.editing, userAcademicOptions(), field);
  const selectedClass = adminState.options.classes.find(item => sameFilterValue(item.class_id, userAdminState.editing.class_id));
  if (selectedClass) {
    userAdminState.editing.class_num = selectedClass.class_num || userAdminState.editing.class_num || '';
  }
}

function canMaintainUser(row) {
  return permissionState.context.role_type === 'super_admin' || row.role_type !== 'super_admin';
}

function canGenerateLoginPasskey(row) {
  if (!row || Number(row.id) === Number(permissionState.context.account_id || 0)) {
    return false;
  }
  const roleType = permissionState.context.role_type;
  if (roleType === 'super_admin') {
    return true;
  }
  if (roleType === 'school_admin') {
    return row.role_type !== 'super_admin';
  }
  if (['college_admin', 'profession_admin'].includes(roleType)) {
    return ['teacher', 'student'].includes(row.role_type);
  }
  return false;
}

function openUserDialog(row = null) {
  const roles = manageableUserRoles();
  userAdminState.editing = emptyUserForm(row || {});
  if (!userAdminState.editing.role_id && roles.length) {
    userAdminState.editing.role_id = roles[0].id;
  }
  handleUserRoleChange();
  userAdminState.message = '';
  userAdminState.dialogVisible = true;
}

function closeUserDialog() {
  userAdminState.dialogVisible = false;
}

async function saveUserConfig() {
  if (!canManageConfig.value || userAdminState.loading) {
    return;
  }

  userAdminState.loading = true;
  userAdminState.message = '';
  try {
    const data = await saveAdminAccount({
      ...userAdminState.editing,
      id: userAdminState.editing.id || null,
      role_id: Number(userAdminState.editing.role_id || 0),
    });
    if (data?.initial_password) {
      await copyText(data.initial_password);
      userAdminState.message = `已保存，初始密码已复制：${data.initial_password}`;
    } else {
      userAdminState.message = '已保存';
    }
    userAdminState.dialogVisible = false;
    userAdminState.loading = false;
    await Promise.all([
      loadUserAccounts(userAdminState.pagination.page || 1),
      loadAdminFoundation(),
    ]);
  } catch (error) {
    userAdminState.message = error.message;
  } finally {
    userAdminState.loading = false;
  }
}

async function toggleUserStatus(row) {
  if (!row || userAdminState.loading) {
    return;
  }
  const nextStatus = row.status === 'enabled' ? 'disabled' : 'enabled';
  const text = nextStatus === 'enabled' ? '启用' : '停用';
  if (!window.confirm(`确认${text}账号 ${row.login_name || row.name}？`)) {
    return;
  }

  userAdminState.loading = true;
  userAdminState.message = '';
  try {
    await changeAdminAccountStatus({
      id: row.id,
      status: nextStatus,
    });
    userAdminState.message = `已${text}`;
    userAdminState.loading = false;
    await Promise.all([
      loadUserAccounts(userAdminState.pagination.page || 1),
      loadAdminFoundation(),
    ]);
  } catch (error) {
    userAdminState.message = error.message;
  } finally {
    userAdminState.loading = false;
  }
}

function openResetPasswordDialog(row) {
  userAdminState.passwordForm = {
    id: row.id,
    name: `${row.name || '-'} / ${row.login_name || '-'}`,
    password: '',
  };
  userAdminState.message = '';
  userAdminState.passwordDialogVisible = true;
}

function closeResetPasswordDialog() {
  userAdminState.passwordDialogVisible = false;
}

async function resetUserPassword() {
  if (!userAdminState.passwordForm.id || userAdminState.loading) {
    return;
  }
  if (!String(userAdminState.passwordForm.password || '').trim()) {
    userAdminState.message = '请输入新密码';
    return;
  }

  userAdminState.loading = true;
  userAdminState.message = '';
  try {
    await resetAdminAccountPassword({
      id: userAdminState.passwordForm.id,
      password: userAdminState.passwordForm.password,
    });
    userAdminState.message = '密码已重置';
    userAdminState.passwordDialogVisible = false;
  } catch (error) {
    userAdminState.message = error.message;
  } finally {
    userAdminState.loading = false;
  }
}

async function clearCurrentTestData() {
  if (permissionState.context.role_type !== 'super_admin' || dataManageState.clearLoading) {
    return;
  }
  const confirmation = window.prompt('该操作会清除测试数据。请输入 CLEAR_TEST_DATA 确认：');
  if (confirmation !== 'CLEAR_TEST_DATA') {
    dataManageState.message = '已取消或确认文本不正确';
    return;
  }

  dataManageState.clearLoading = true;
  dataManageState.message = '';
  try {
    const data = await clearTestData({ confirmation });
    const affectedTotal = Object.values(data.affected || {}).reduce((sum, value) => sum + Number(value || 0), 0);
    dataManageState.message = `已清除测试数据，影响 ${affectedTotal} 行`;
    await Promise.all([
      loadAdminFoundation(),
      loadDesktopShortcuts(),
      loadFavorites(1),
    ]);
  } catch (error) {
    dataManageState.message = error.message;
  } finally {
    dataManageState.clearLoading = false;
  }
}

async function copyAdminLoginUrl(row) {
  if (!row?.id || userAdminState.passkeyLoadingId) {
    return;
  }

  userAdminState.passkeyLoadingId = row.id;
  userAdminState.message = '';
  userAdminState.passkeyUrl = '';
  try {
    const data = await generateAdminLoginPasskey({
      id: row.id,
      client: 'WEB',
      return_url: frontendLoginReturnUrl(),
    });
    const url = data.login_url || buildPasskeyUrl(data.passkey);
    if (!url) {
      throw new Error('一键登录链接生成失败');
    }
    await copyText(url);
    userAdminState.passkeyUrl = url;
    userAdminState.message = `已复制 ${row.name || row.login_name || '账号'} 的一键登录链接，${data.expires_in || 600} 秒内有效`;
  } catch (error) {
    userAdminState.message = error.message;
  } finally {
    userAdminState.passkeyLoadingId = null;
  }
}

function frontendLoginReturnUrl() {
  return `${window.location.origin}${window.location.pathname}`;
}

function buildPasskeyUrl(passkey) {
  if (!passkey) {
    return '';
  }
  const url = new URL(frontendLoginReturnUrl());
  url.searchParams.set('passkey', passkey);
  return url.href;
}

async function copyText(text) {
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(text);
    return;
  }

  const textarea = document.createElement('textarea');
  textarea.value = text;
  textarea.setAttribute('readonly', 'readonly');
  textarea.style.position = 'fixed';
  textarea.style.left = '-9999px';
  document.body.appendChild(textarea);
  textarea.select();
  document.execCommand('copy');
  document.body.removeChild(textarea);
}

async function openUserDetailDialog(row, mode) {
  userAdminState.detailMode = mode;
  userAdminState.detailDialogVisible = true;
  userAdminState.message = '';
  userAdminState.detail = emptyUserDetail();
  userAdminState.detailPagination = {
    page: 1,
    page_size: 20,
    total: 0,
  };
  await loadUserDetailData(row, 1);
}

async function loadUserDetailData(row, page = 1) {
  userAdminState.detailLoading = true;
  try {
    const data = await fetchAdminAccountDetail({
      id: row.id,
      page,
      page_size: userAdminState.detailPagination.page_size || 20,
    });
    userAdminState.detail = {
      account: data.account || row,
      boundAccounts: data.bound_accounts || [],
      wechatAccounts: data.wechat_accounts || [],
      logs: data.operation_logs?.items || [],
    };
    userAdminState.detailPagination = {
      ...userAdminState.detailPagination,
      ...(data.operation_logs?.pagination || {}),
    };
  } catch (error) {
    userAdminState.message = error.message;
  } finally {
    userAdminState.detailLoading = false;
  }
}

async function loadUserDetailPage(page) {
  const account = userAdminState.detail.account;
  if (!account?.id) {
    return;
  }

  await loadUserDetailData(account, page);
}

function closeUserDetailDialog() {
  userAdminState.detailDialogVisible = false;
}

function logPayloadText(payload) {
  if (!payload || typeof payload !== 'object') {
    return '-';
  }
  const path = payload.path || '';
  const status = payload.status_code ? `状态 ${payload.status_code}` : '';
  const duration = payload.duration_ms ? `${payload.duration_ms}ms` : '';
  return [path, status, duration].filter(Boolean).join(' / ') || '-';
}

function uniqueRoleOptions() {
  const roles = [];
  const seen = new Set();
  adminState.roles.forEach((role) => {
    if (!role.role_type || seen.has(role.role_type)) {
      return;
    }
    seen.add(role.role_type);
    roles.push(role);
  });
  return roles;
}

function setUserFilter({ key, value }) {
  userAdminState.filters[key] = value ?? '';
}

function resetUserFilters() {
  userAdminState.filters.keyword = '';
  userAdminState.filters.role_type = '';
  userAdminState.filters.status = 'all';
  loadUserAccounts(1);
}

async function loadUserAccounts(page = 1) {
  if (!canViewUserAdmin.value || userAdminState.loading) {
    return;
  }

  userAdminState.loading = true;
  userAdminState.message = '';
  try {
    const data = await fetchAdminAccounts({
      page,
      page_size: userAdminState.pagination.page_size,
      ...userAdminState.filters,
    });
    userAdminState.items = data.accounts || [];
    userAdminState.pagination = {
      ...userAdminState.pagination,
      ...(data.pagination || {}),
    };
  } catch (error) {
    userAdminState.message = error.message;
  } finally {
    userAdminState.loading = false;
  }
}

function newMenu(parent = null) {
  const parentId = parent?.id && parent.type !== 'button'
    ? parent.id
    : 0;
  adminState.menu.editing = {
    ...emptyMenu(),
    parent_id: parentId,
    type: defaultChildMenuType(parent),
  };
  adminState.menu.message = '';
}

function defaultChildMenuType(parent = null) {
  if (!parent) {
    return 'directory';
  }
  if (parent.type === 'directory') {
    return 'menu';
  }
  if (parent.type === 'menu') {
    return 'list';
  }
  if (parent.type === 'list') {
    return 'button';
  }

  return 'menu';
}

function editMenu(row) {
  adminState.menu.editing = {
    id: row.id,
    parent_id: Number(row.parent_id || 0),
    name: row.name || '',
    code: row.code || '',
    path: row.path || '',
    url: row.url || '',
    platform: row.platform || 'both',
    type: row.type || 'menu',
    sort: Number(row.sort || 0),
    icon: row.icon || '',
    is_module: row.is_module || 'false',
    module_key: row.module_key || '',
    icon_url: row.icon_url || '',
    icon_file_id: row.icon_file_id || null,
    visible: row.visible || 'true',
    status: row.status || 'enabled',
  };
  adminState.menu.message = '';
}

async function handleMenuIconSelected(file) {
  if (!file) {
    return;
  }
  adminState.menu.iconUploading = true;
  adminState.menu.message = '';
  try {
    const data = await uploadMenuIcon(file);
    adminState.menu.editing.icon_url = data.url || '';
    adminState.menu.editing.icon_file_id = data.file_id || null;
  } catch (error) {
    adminState.menu.message = error.message;
  } finally {
    adminState.menu.iconUploading = false;
  }
}

async function saveMenuConfig() {
  adminState.menu.loading = true;
  adminState.menu.message = '';
  try {
    const isModule = adminState.menu.editing.is_module === 'true';
    const payload = {
      ...adminState.menu.editing,
      sort: Number(adminState.menu.editing.sort || 0),
      is_module: isModule ? 'true' : 'false',
      module_key: isModule ? String(adminState.menu.editing.module_key || '').trim() : '',
      icon_url: adminState.menu.editing.icon_url || '',
      icon_file_id: adminState.menu.editing.icon_file_id || null,
    };
    const data = await saveMenuApi(payload);
    applyMenusData(data);
    adminState.menu.message = '已保存';
    if (data.items?.length) {
      const saved = data.items.find(item => (payload.id && item.id === payload.id) || (item.name === payload.name && item.code === payload.code));
      if (saved) {
        editMenu(saved);
        adminState.menu.selected = saved;
      }
    }
    closeMenuDialog();
    await load();
    await loadDesktopShortcuts();
    syncShortcutWorkspace();
  } catch (error) {
    adminState.menu.message = error.message;
  } finally {
    adminState.menu.loading = false;
  }
}

async function deleteMenuConfig(row) {
  if (!window.confirm(`确认删除菜单「${row.name}」？`)) {
    return;
  }

  adminState.menu.loading = true;
  adminState.menu.message = '';
  try {
    const data = await deleteMenuApi(row.id);
    applyMenusData(data);
    if (adminState.menu.editing.id === row.id || adminState.menu.selected?.id === row.id) {
      adminState.menu.selected = null;
      newMenu(null);
    }
    adminState.menu.message = '已删除';
    await load();
  } catch (error) {
    adminState.menu.message = error.message;
  } finally {
    adminState.menu.loading = false;
  }
}

async function loadAdminFoundation() {
  if (!canManageConfig.value || adminState.loading) {
    return;
  }

  adminState.loading = true;
  adminState.roleMenus.message = '';
  adminState.scope.message = '';
  try {
    const [rolesData, menusData, optionsData] = await Promise.all([
      fetchAdminRoles(),
      fetchAdminMenus(),
      fetchAdminOptions(),
    ]);

    adminState.roles = rolesData.roles || [];
    applyMenusData(menusData);
    adminState.options.accounts = optionsData.accounts || [];
    adminState.options.departments = optionsData.departments || [];
    adminState.options.grades = optionsData.grades || [];
    adminState.options.professions = optionsData.professions || [];
    adminState.options.classes = optionsData.classes || [];
    adminState.options.companies = optionsData.companies || [];

    if (!adminState.roleMenus.role_id && adminState.roles.length) {
      adminState.roleMenus.role_id = adminState.roles[0].id;
    }
    if (!adminState.scope.account_id && adminState.options.accounts.length) {
      adminState.scope.account_id = adminState.options.accounts[0].id;
      adminState.scope.role_id = adminState.options.accounts[0].role_id || adminState.roles[0]?.id || null;
    }

    await Promise.all([
      loadRolePermissions(),
      loadOrganizationScopeConfig(),
    ]);
  } catch (error) {
    adminState.roleMenus.message = error.message;
    adminState.scope.message = error.message;
  } finally {
    adminState.loading = false;
  }
}

async function loadRolePermissions() {
  if (!adminState.roleMenus.role_id || !canManageConfig.value) {
    return;
  }

  adminState.roleMenus.loading = true;
  adminState.roleMenus.message = '';
  try {
    const data = await fetchRolePermissions(adminState.roleMenus.role_id);
    adminState.roleMenus.menu_ids = data.menu_ids || [];
    adminState.roleMenus.permissions = data.permissions || [];
    await nextTick();
    activeRoleTree()?.setCheckedKeys(adminState.roleMenus.menu_ids);
  } catch (error) {
    adminState.roleMenus.message = error.message;
  } finally {
    adminState.roleMenus.loading = false;
  }
}

async function saveRoleMenuConfig() {
  const tree = activeRoleTree();
  if (!adminState.roleMenus.role_id || !tree) {
    return;
  }

  adminState.roleMenus.loading = true;
  adminState.roleMenus.message = '';
  try {
    const checked = tree.getCheckedKeys(false);
    const halfChecked = tree.getHalfCheckedKeys();
    const data = await saveRoleMenus({
      role_id: adminState.roleMenus.role_id,
      menu_ids: Array.from(new Set([...checked, ...halfChecked])).map(Number),
    });
    adminState.roleMenus.menu_ids = data.menu_ids || [];
    adminState.roleMenus.permissions = data.permissions || [];
    adminState.roleMenus.message = '已保存';
    await load();
  } catch (error) {
    adminState.roleMenus.message = error.message;
  } finally {
    adminState.roleMenus.loading = false;
  }
}

function activeRoleTree() {
  const tree = roleTreeRef.value;
  return Array.isArray(tree) ? tree.at(-1) : tree;
}

function handleScopeAccountChange() {
  const account = adminState.options.accounts.find(item => item.id === adminState.scope.account_id);
  adminState.scope.role_id = account?.role_id || adminState.roles[0]?.id || null;
  loadOrganizationScopeConfig();
}

async function loadOrganizationScopeConfig() {
  if (!adminState.scope.account_id || !adminState.scope.role_id || !canManageConfig.value) {
    return;
  }

  adminState.scope.loading = true;
  adminState.scope.message = '';
  try {
    const data = await fetchOrganizationScopes({
      account_id: adminState.scope.account_id,
      role_id: adminState.scope.role_id,
    });
    adminState.scope.scopes = (data.organization_scopes || []).map(scopeRow);
  } catch (error) {
    adminState.scope.message = error.message;
  } finally {
    adminState.scope.loading = false;
  }
}

function addScopeRow() {
  adminState.scope.scopes.push(scopeRow());
}

function removeScopeRow(index) {
  adminState.scope.scopes.splice(index, 1);
}

async function saveScopeConfig() {
  if (!adminState.scope.account_id || !adminState.scope.role_id) {
    return;
  }

  adminState.scope.loading = true;
  adminState.scope.message = '';
  try {
    const payload = adminState.scope.scopes
      .map(scopePayload)
      .filter(scope => Object.values(scope).some(value => value !== null));
    const data = await saveOrganizationScopes({
      account_id: adminState.scope.account_id,
      role_id: adminState.scope.role_id,
      scopes: payload,
    });
    adminState.scope.scopes = (data.organization_scopes || []).map(scopeRow);
    adminState.scope.message = '已保存';
  } catch (error) {
    adminState.scope.message = error.message;
  } finally {
    adminState.scope.loading = false;
  }
}

function scopeRow(row = {}) {
  return {
    dep_id: row.dep_id ? String(row.dep_id) : '',
    profession_id: row.profession_id ? String(row.profession_id) : '',
    class_id: row.class_id ? String(row.class_id) : '',
    company_id: row.company_id ? String(row.company_id) : '',
    cate_id: row.cate_id ? String(row.cate_id) : '',
  };
}

function scopePayload(row) {
  return {
    dep_id: row.dep_id || null,
    profession_id: row.profession_id || null,
    class_id: row.class_id || null,
    company_id: row.company_id || null,
    cate_id: row.cate_id || null,
  };
}

function createArchiveState(type) {
  return {
    type,
    items: [],
    filters: {
      keyword: '',
      filter_flag: 'all',
    },
    pagination: {
      page: 1,
      page_size: 20,
      total: 0,
    },
    editing: emptyArchiveItem(type),
    selected: null,
    dialogVisible: false,
    loading: false,
    importing: false,
    message: '',
  };
}

function archiveDefinition(type) {
  return archiveDefinitions.find(item => item.type === type) || archiveDefinitions[0];
}

function archiveStateByType(type) {
  return archiveStates[type] || archiveStates.department;
}

function archiveTypeForWindow(win) {
  return archiveManageModules[win.module.id] || archiveManagePanels[win.panel] || 'department';
}

function archiveStateForWindow(win) {
  return archiveStateByType(archiveTypeForWindow(win));
}

function archiveDefinitionForWindow(win) {
  return archiveDefinition(archiveTypeForWindow(win));
}

function archiveFieldsForWindow(win) {
  return archiveDefinitionForWindow(win).fields;
}

function archiveIdFieldForWindow(win) {
  return archiveDefinitionForWindow(win).idField;
}

function archiveTableFieldsForWindow(win) {
  return archiveFieldsForWindow(win).filter(field => field.key !== 'sort');
}

function archiveRequestParams(type, page = null) {
  const state = archiveStateByType(type);
  return {
    page: page || state.pagination.page || 1,
    page_size: state.pagination.page_size || 20,
    keyword: state.filters.keyword,
    filter_flag: state.filters.filter_flag || 'all',
  };
}

function applyArchivePage(type, data = {}) {
  const state = archiveStateByType(type);
  state.items = data.items || [];
  state.pagination = {
    ...state.pagination,
    ...(data.pagination || {}),
  };
  state.selected = null;
}

function archiveKeywordPlaceholder(type) {
  return {
    department: '学院名称、简称、代码',
    grade: '届次名称',
    profession: '专业名称、简称、代码',
    class: '班级名称、简称、班号',
    company: '基地名称、信用代码、联系人',
  }[type] || '关键词';
}

function resetArchiveFilters(type) {
  const state = archiveStateByType(type);
  state.filters.keyword = '';
  state.filters.filter_flag = 'all';
  state.pagination.page = 1;
  loadArchiveItems(type, 1);
}

function emptyArchiveItem(type = 'department') {
  const definition = archiveDefinitions.find(item => item.type === type) || archiveDefinitions[0];
  const item = {
    id: null,
    [definition.idField]: null,
  };

  definition.fields.forEach((field) => {
    if (field.key === 'flag') {
      item[field.key] = 'on';
      return;
    }
    if (field.key === 'is_current') {
      item[field.key] = 'false';
      return;
    }
    item[field.key] = '';
  });

  return item;
}

function isUserManageWindow(win) {
  return Boolean(win && (win.module.id === 'userManage' || (win.module.id === 'config' && win.panel === 'userManage')));
}

function isArchiveManageWindow(win) {
  return Boolean(win && (archiveManageModules[win.module.id] || (win.module.id === 'config' && archiveManagePanels[win.panel])));
}

function openArchiveDialog(type, row = null) {
  if (row) {
    editArchiveItem(type, row);
  } else {
    newArchiveItem(type);
  }
  archiveStateByType(type).dialogVisible = true;
}

function closeArchiveDialog(type) {
  archiveStateByType(type).dialogVisible = false;
}

function newArchiveItem(type) {
  const state = archiveStateByType(type);
  state.editing = emptyArchiveItem(type);
  state.message = '';
}

function selectArchiveItem(type, row) {
  const state = archiveStateByType(type);
  state.selected = row;
  state.message = '';
}

function editArchiveItem(type, row) {
  const definition = archiveDefinition(type);
  const editing = emptyArchiveItem(type);
  editing.id = row[definition.idField];
  editing[definition.idField] = row[definition.idField];
  definition.fields.forEach((field) => {
    editing[field.key] = row[field.key] === null || row[field.key] === undefined ? '' : String(row[field.key]);
  });
  const state = archiveStateByType(type);
  state.editing = editing;
  state.message = '';
}

async function loadArchiveItems(type = 'department', page = null) {
  const state = archiveStateByType(type);
  if (!canManageConfig.value || state.loading) {
    return;
  }

  state.loading = true;
  state.message = '';
  try {
    const data = await fetchArchiveList(type, archiveRequestParams(type, page));
    applyArchivePage(type, data);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

async function saveArchiveConfig(type) {
  if (!canManageConfig.value) {
    return;
  }

  const state = archiveStateByType(type);
  state.loading = true;
  state.message = '';
  try {
    const definition = archiveDefinition(type);
    const payload = {
      type,
      id: state.editing.id || null,
      [definition.idField]: state.editing[definition.idField] || null,
    };
    definition.fields.forEach((field) => {
      payload[field.key] = state.editing[field.key];
    });
    const data = await saveArchiveItem({
      ...payload,
      ...archiveRequestParams(type, payload.id ? state.pagination.page : 1),
    });
    applyArchivePage(type, data);
    state.message = '已保存';
    state.dialogVisible = false;
    state.selected = null;
    await loadAdminFoundation();
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

async function deleteArchiveConfig(type) {
  const state = archiveStateByType(type);
  if (!state.selected || !window.confirm(`确认删除当前${archiveDefinition(type).name}？`)) {
    return;
  }

  state.loading = true;
  state.message = '';
  try {
    const definition = archiveDefinition(type);
    const data = await deleteArchiveItem({
      type,
      id: state.selected[definition.idField],
      ...archiveRequestParams(type),
    });
    state.editing = emptyArchiveItem(type);
    applyArchivePage(type, data);
    if (!state.items.length && (state.pagination.total || 0) > 0 && (state.pagination.page || 1) > 1) {
      applyArchivePage(type, await fetchArchiveList(type, archiveRequestParams(type, (state.pagination.page || 1) - 1)));
    }
    state.message = '已删除';
    await loadAdminFoundation();
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

function canImportArchiveExcel(type) {
  return type === 'profession' || type === 'class';
}

function chooseArchiveExcel(type) {
  if (!canManageConfig.value || !canImportArchiveExcel(type)) {
    return;
  }

  archiveImportType.value = type;
  if (archiveImportInputRef.value) {
    archiveImportInputRef.value.value = '';
    archiveImportInputRef.value.click();
  }
}

async function handleArchiveImportFile(event) {
  const file = event.target?.files?.[0];
  const type = archiveImportType.value;
  if (!file || !canImportArchiveExcel(type)) {
    return;
  }

  const state = archiveStateByType(type);
  state.importing = true;
  state.message = '';
  try {
    const data = await importArchiveExcel(type, file, archiveRequestParams(type, 1));
    applyArchivePage(type, data);
    state.editing = emptyArchiveItem(type);
    const failedText = data.failed ? `，失败 ${data.failed} 条` : '';
    const firstError = data.errors?.[0]?.message ? `；首条错误：${data.errors[0].message}` : '';
    state.message = `导入完成：新增 ${data.created || 0} 条，跳过 ${data.skipped || 0} 条${failedText}${firstError}`;
    await loadAdminFoundation();
  } catch (error) {
    state.message = error.message;
  } finally {
    state.importing = false;
    archiveImportType.value = '';
    if (event.target) {
      event.target.value = '';
    }
  }
}

function archiveFieldOptions(field) {
  if (field.options === 'flag') {
    return [
      { label: '启用', value: 'on' },
      { label: '停用', value: 'off' },
    ];
  }
  if (field.options === 'boolean') {
    return [
      { label: '是', value: 'true' },
      { label: '否', value: 'false' },
    ];
  }
  if (field.options === 'departments') {
    return adminState.options.departments.map(item => ({ label: item.dep_name, value: String(item.dep_id) }));
  }
  if (field.options === 'grades') {
    return adminState.options.grades.map(item => ({ label: item.grade_name, value: String(item.grade_id) }));
  }
  if (field.options === 'professions') {
    return adminState.options.professions.map(item => ({ label: item.profession_name, value: String(item.profession_id) }));
  }
  return [];
}

function archiveFieldUsesSwitch(field) {
  return ['flag', 'boolean'].includes(field.options);
}

function archiveSwitchActiveValue(field) {
  return field.options === 'boolean' ? 'true' : 'on';
}

function archiveSwitchInactiveValue(field) {
  return field.options === 'boolean' ? 'false' : 'off';
}

function archiveSwitchActiveText(field) {
  return field.options === 'boolean' ? '是' : '启用';
}

function archiveSwitchInactiveText(field) {
  return field.options === 'boolean' ? '否' : '停用';
}

function archiveEditFieldOptions(field, type) {
  if (field.options !== 'professions' || type !== 'class') {
    return archiveFieldOptions(field);
  }

  const editing = archiveStateByType(type).editing;
  return adminState.options.professions
    .filter((item) => {
      const matchGrade = !editing.grade_id || Number(item.grade_id || 0) === Number(editing.grade_id);
      const matchDepartment = !editing.dep_id || Number(item.dep_id || 0) === Number(editing.dep_id);
      return matchGrade && matchDepartment;
    })
    .map(item => ({ label: item.profession_name, value: String(item.profession_id) }));
}

function handleArchiveFieldChange(type, fieldKey) {
  if (type !== 'class') {
    return;
  }

  const editing = archiveStateByType(type).editing;
  if (fieldKey === 'profession_id') {
    const profession = adminState.options.professions.find(item => String(item.profession_id) === String(editing.profession_id));
    if (profession) {
      editing.grade_id = String(profession.grade_id || editing.grade_id || '');
      editing.dep_id = String(profession.dep_id || editing.dep_id || '');
    }
    return;
  }

  if (!['grade_id', 'dep_id'].includes(fieldKey) || !editing.profession_id) {
    return;
  }

  const profession = adminState.options.professions.find(item => String(item.profession_id) === String(editing.profession_id));
  if (!profession) {
    editing.profession_id = '';
    return;
  }

  const mismatchGrade = editing.grade_id && Number(profession.grade_id || 0) !== Number(editing.grade_id);
  const mismatchDepartment = editing.dep_id && Number(profession.dep_id || 0) !== Number(editing.dep_id);
  if (mismatchGrade || mismatchDepartment) {
    editing.profession_id = '';
  }
}

function archiveFieldText(field, value) {
  if (value === null || value === undefined || value === '') {
    return '-';
  }

  return archiveFieldOptions(field).find(item => item.value === String(value))?.label || value;
}

async function loadFiles(page = 1) {
  if (!hasPermission('file:view') || fileState.loading) {
    return;
  }

  fileState.loading = true;
  fileState.message = '';
  try {
    const data = await fetchFileList({
      page,
      page_size: fileState.pagination.page_size,
      keyword: fileState.filters.keyword,
      status: fileState.filters.status,
      category: fileState.filters.category,
    });
    fileState.items = data.items || [];
    fileState.pagination = {
      ...fileState.pagination,
      ...(data.pagination || {}),
    };
  } catch (error) {
    fileState.message = error.message;
  } finally {
    fileState.loading = false;
  }
}

function fileCategoryText(category) {
  const names = {
    student: '学生材料',
    profile: '个人资源',
    general: '通用文件',
  };
  return names[category] || category || '-';
}

function formatFileSize(size) {
  const value = Number(size || 0);
  if (value < 1024) {
    return `${value} B`;
  }
  if (value < 1024 * 1024) {
    return `${(value / 1024).toFixed(1)} KB`;
  }
  return `${(value / 1024 / 1024).toFixed(1)} MB`;
}

function compactUserAgent(userAgent) {
  const value = String(userAgent || '');
  if (!value) {
    return '-';
  }
  const browser = value.includes('Edg/') ? 'Edge' : value.includes('Chrome') ? 'Chrome' : value.includes('Safari') ? 'Safari' : value.includes('Firefox') ? 'Firefox' : '';
  const os = value.includes('Mac OS X') || value.includes('macOS') ? 'macOS' : value.includes('Windows') ? 'Windows' : value.includes('Android') ? 'Android' : value.includes('iPhone') ? 'iOS' : '';
  if (!browser && !os) {
    return value.length > 80 ? `${value.slice(0, 77)}...` : value;
  }
  return `${os || '设备'} / ${browser || '浏览器'}`;
}

function openFileUrl(row) {
  const url = typeof row === 'string' ? row : row?.url;
  if (!url) {
    return;
  }
  window.open(backendUrl(url), '_blank', 'noopener');
}

async function loadLogs(page = 1) {
  if (!hasPermission('log:view') || logState.loading) {
    return;
  }

  logState.loading = true;
  logState.message = '';
  try {
    const data = await fetchOperationLogs({
      page,
      page_size: logState.pagination.page_size,
      ...logState.filters,
    });
    logState.items = data.items || [];
    logState.tables = data.tables || [];
    logState.pagination = {
      ...logState.pagination,
      ...(data.pagination || {}),
    };
  } catch (error) {
    logState.message = error.message;
  } finally {
    logState.loading = false;
  }
}

async function loadStats(page = 1) {
  if (!hasPermission('stat:view') || statState.loading) {
    return;
  }

  statState.loading = true;
  statState.message = '';
  try {
    await loadInternshipFoundation();
    applyAcademicDefaults(statState.filters, organizationScopeDefaults(statAcademicOptions()));
    normalizeStatCascade();
    if (isPracticeScoreSheetReport()) {
      await loadPracticeFoundation(statState.filters.module_type || 'training');
      applyAcademicDefaults(statState.filters, organizationScopeDefaults(statAcademicOptions()));
      normalizeStatCascade();
    }
    const data = await fetchInternshipStats(statQueryParams(page));
    statState.cards = data.cards || [];
    statState.columns = data.columns || [];
    statState.rows = data.rows || [];
    statState.sheet_meta = data.sheet_meta || {};
    statState.pagination = {
      page: data.pagination?.page || page,
      page_size: data.pagination?.page_size || statState.pagination.page_size,
      total: data.pagination?.total || 0,
    };
    statState.generated_at = data.generated_at || '';
  } catch (error) {
    statState.message = error.message;
  } finally {
    statState.loading = false;
  }
}

function statQueryParams(page = 1) {
  return {
    report: statState.report,
    page,
    page_size: statState.pagination.page_size,
    ...statState.filters,
  };
}

function resetLogFilters() {
  Object.assign(logState.filters, {
    keyword: '',
    action: '',
    ip: '',
    date_from: '',
    date_to: '',
  });
  loadLogs(1);
}

function resetStatFilters() {
  Object.assign(statState.filters, {
    semester: '',
    dep_id: '',
    profession_id: '',
    grade_id: '',
    class_id: '',
    module_type: 'training',
    plan_id: '',
    academic_year: '',
    keyword: '',
  });
  applyAcademicDefaults(statState.filters, organizationScopeDefaults(statAcademicOptions()));
  normalizeStatCascade();
  loadStats(1);
}

function isPracticeScoreSheetReport() {
  return statState.report === 'practice_score_sheet';
}

function practiceScoreSheetPlanOptions() {
  const module = statState.filters.module_type || 'training';
  const plans = practiceModuleState(module).options.plans || [];
  const gradeId = statState.filters.grade_id;
  const depId = statState.filters.dep_id;
  const professionId = statState.filters.profession_id;
  const classId = statState.filters.class_id;
  return plans
    .filter(item => matchAcademicFilters(item, { grade_id: gradeId, dep_id: depId, profession_id: professionId, class_id: classId }))
    .map(item => ({
      value: item.id,
      label: item.course_name || item.title || `计划 ${item.id}`,
    }));
}

function scoreSheetCell(row, key) {
  return statCellText(row?.[key]);
}

function logMethodText(action) {
  const match = String(action || '').trim().match(/^(GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD)\s+/i);
  return match ? match[1].toUpperCase() : '-';
}

function logStatusTagType(statusCode) {
  const code = Number(statusCode || 0);
  if (code >= 200 && code < 300) {
    return 'success';
  }
  if (code >= 400 && code < 500) {
    return 'warning';
  }
  if (code >= 500) {
    return 'danger';
  }
  return 'info';
}

function logDurationText(durationMs) {
  const value = Number(durationMs);
  if (!Number.isFinite(value) || value < 0) {
    return '-';
  }
  if (value < 1000) {
    return `${Math.round(value)}ms`;
  }
  return `${(value / 1000).toFixed(2)}s`;
}

function payloadText(payload) {
  if (payload === null || payload === undefined || payload === '') {
    return '-';
  }
  if (typeof payload === 'string') {
    return payload.length > 180 ? `${payload.slice(0, 177)}...` : payload;
  }
  const text = JSON.stringify(payload);
  return text.length > 180 ? `${text.slice(0, 177)}...` : text;
}

function emptyPagedList() {
  return {
    items: [],
    pagination: {
      page: 1,
      page_size: 20,
      total: 0,
    },
  };
}

function emptyInternshipOverview() {
  return {
    arrangements: 0,
    applications_waiting: 0,
    active_pairs: 0,
    journals_waiting: 0,
    reports_waiting: 0,
    today_sign_ins: 0,
  };
}

function emptyInternshipOptions() {
  return {
    types: [],
    organize_modes: [],
    departments: [],
    grades: [],
    professions: [],
    classes: [],
    companies: [],
    teachers: [],
    students: [],
    bases: [],
    plans: [],
    arrangements: [],
    report_templates: [],
    review_rules: defaultInternshipReviewRules,
  };
}

function emptyArrangementDetail() {
  return {
    item: null,
    classes: [],
    students: [],
  };
}

function emptyInternshipFilters() {
  return {
    keyword: '',
    semester: '',
    dep_id: '',
    base_id: '',
    profession_id: '',
    grade_id: '',
    class_id: '',
    arrangement_id: '',
    config_key: '',
    status: '',
    archive_status: '',
    result: '',
    type: '',
    organize_mode: '',
  };
}

function emptyArrangementForm() {
  return {
    plan_id: null,
    title: '',
    task_no: '',
    batch_no: '',
    semester: '',
    grade_id: null,
    base_id: null,
    dep_id: null,
    profession_id: null,
    teacher_id: null,
    class_ids: [],
    credit: '',
    type: 'major_external',
    organize_mode: 'centralized',
    start_date: '',
    end_date: '',
    location: '',
    description: '',
    change_reason: '',
    change_id: null,
    status: 'enabled',
  };
}

function emptyPlanForm() {
  return {
    id: null,
    source_type: 'edu_system',
    course_code: '',
    course_name: '',
    grade_id: null,
    semester: '',
    dep_id: null,
    profession_id: null,
    credit: '',
    score_rule: 'average',
    content: '',
    status: 'wait',
  };
}

function emptyScoreForm() {
  return {
    pair_id: null,
    student_id: null,
    arrangement_id: null,
    sign_in_score: '',
    journal_score: '',
    report_score: '',
    enterprise_score: '',
    comment: '',
  };
}

async function searchScorePairs(keyword = '') {
  if (!canSaveInternshipScore.value) {
    return;
  }
  internshipState.scorePairLoading = true;
  try {
    const pairs = await fetchInternshipPairs({
      page: 1,
      page_size: 50,
      keyword,
    });
    setPagedList('pairs', pairs);
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.scorePairLoading = false;
  }
}

function emptyCourseScoreForm() {
  return {
    plan_id: null,
    student_id: null,
    score_value: '',
    remark: '',
  };
}

function emptyBaseFlowForm(row = {}) {
  return {
    id: row.id || null,
    type: row.flow_type || row.type || 'application',
    base_id: row.base_id || null,
    dep_id: row.dep_id || null,
    title: row.title || '',
    content: row.content || '',
    base_type: row.base_type || 'fixed',
    usage_type: row.usage_type || '',
    result_type: row.result_type || '',
    amount: row.amount || '',
    status: row.status || 'draft',
  };
}

function emptyOperationDialog() {
  return {
    type: '',
    title: '',
    description: '',
    entity: '',
    status: '',
    row: null,
    reason: '',
    timeline: [],
    cycles: [],
  };
}

function createPracticeModuleState() {
  const panels = ['plans', 'schedules', 'projects', 'signIns', 'journals', 'reports', 'syllabus', 'lessonPlans', 'gradeRules', 'scores', 'reflections', 'rooms'];
  return {
    loading: false,
    message: '',
    overview: emptyPracticeOverview(),
    options: emptyPracticeOptions(),
    form: emptyPracticeForm(),
    dialog: emptyOperationDialog(),
    filters: Object.fromEntries(panels.map(panel => [panel, emptyPracticeFilters()])),
    lists: Object.fromEntries(panels.map(panel => [panel, emptyPagedList()])),
  };
}

function emptyPracticeOverview() {
  return {
    plans_waiting: 0,
    schedules: 0,
    projects: 0,
    syllabus_waiting: 0,
    lesson_plans_waiting: 0,
    scores_submitted: 0,
    reflections_waiting: 0,
    today_schedules: 0,
  };
}

function emptyPracticeOptions() {
  return {
    departments: [],
    grades: [],
    professions: [],
    classes: [],
    teachers: [],
    students: [],
    bases: [],
    rooms: [],
    plans: [],
    schedules: [],
    review_rules: {},
  };
}

function emptyPracticeFilters() {
  return {
    keyword: '',
    status: '',
    grade_id: '',
    dep_id: '',
    profession_id: '',
    class_id: '',
    plan_id: '',
    teacher_id: '',
    room_id: '',
    place_type: '',
    source_type: '',
  };
}

function emptyPracticeForm(row = {}) {
  return {
    id: row.id || null,
    title: row.title || '',
    course_name: row.course_name || '',
    grade_id: row.grade_id || null,
    dep_id: row.dep_id || null,
    profession_id: row.profession_id || null,
    class_id: row.class_id || null,
    teacher_id: row.teacher_id || null,
    plan_id: row.plan_id || null,
    schedule_id: row.schedule_id || null,
    source_type: row.source_type || 'manual',
    place_type: row.place_type || 'inside',
    room_id: row.room_id || null,
    base_id: row.base_id || null,
    schedule_date: row.schedule_date || '',
    start_time: row.start_time || '',
    end_time: row.end_time || '',
    start_date: row.start_date || '',
    end_date: row.end_date || '',
    published_at: row.published_at || '',
    location: row.location || '',
    student_count: row.student_count || '',
    name: row.name || '',
    code: row.code || '',
    capacity: row.capacity || '',
    student_id: row.student_id || null,
    score_value: row.score_value || '',
    ratio_text: practiceRatioText(row.ratio_json),
    content: row.content || '',
    remark: row.remark || '',
    status: row.status || 'wait',
  };
}

function emptyPracticeExecutionForm(row = {}) {
  return {
    id: row.id || null,
    project_id: row.project_id || row.entity_id || null,
    student_id: row.student_id || null,
    title: row.title || '',
    date: row.date || '',
    content: row.content || '',
    location: row.location || '',
    longitude: row.longitude || '',
    latitude: row.latitude || '',
    remark: row.remark || '',
    attendance_score: row.attendance_score || '',
    material_score: row.material_score || '',
    report_score: row.report_score || '',
    score_value: row.score_value || '',
  };
}

function practiceModuleState(module) {
  return practiceState[module] || practiceState.training;
}

function isPracticeModule(module) {
  return ['training', 'lab'].includes(module);
}

function practiceSidebarItems() {
  const items = [
    { key: 'overview', name: '总览' },
    { key: 'plans', name: '教学计划' },
    { key: 'schedules', name: '课表安排' },
    { key: 'projects', name: '项目发布' },
    { key: 'signIns', name: '签到记录' },
    { key: 'journals', name: '过程日志' },
    { key: 'reports', name: '总结报告' },
    { key: 'syllabus', name: '大纲编写' },
    { key: 'lessonPlans', name: '教案编写' },
    { key: 'gradeRules', name: '成绩比例' },
    { key: 'scores', name: '成绩评定' },
    { key: 'reflections', name: '反思报告' },
    { key: 'rooms', name: '场地管理' },
  ];
  if (isStudentRole.value) {
    return items.filter(item => ['overview', 'projects', 'signIns', 'journals', 'reports', 'scores'].includes(item.key));
  }
  if (isTeacherRole.value) {
    return items.filter(item => item.key !== 'rooms');
  }
  return items;
}

function practicePanelEntity(panel) {
  return {
    plans: 'plan',
    schedules: 'schedule',
    projects: 'project',
    syllabus: 'syllabus',
    lessonPlans: 'lessonPlan',
    gradeRules: 'gradeRule',
    scores: 'score',
    reflections: 'reflection',
    rooms: 'room',
  }[panel] || 'plan';
}

function practiceExecutionType(panel) {
  return {
    signIns: 'sign_in',
    journals: 'journal',
    reports: 'report',
  }[panel] || '';
}

function practiceReviewEntity(panel) {
  return {
    plans: 'plan',
    journals: 'journal',
    reports: 'report',
    syllabus: 'syllabus',
    lessonPlans: 'lessonPlan',
    reflections: 'reflection',
  }[panel] || '';
}

function practiceReviewStatusOptions() {
  return [
    { label: '通过', value: 'accept' },
    { label: '修改', value: 'modify' },
  ];
}

function reviewStatusOptions(entity) {
  if (entity === 'delay') {
    return [
      { label: '通过', value: 'accept' },
      { label: '退回', value: 'refuse' },
    ];
  }
  if (entity === 'arrangement_change') {
    return [
      { label: '通过', value: 'accept' },
      { label: '修改', value: 'modify' },
      { label: '拒绝', value: 'refuse' },
    ];
  }
  return [
    { label: '通过', value: 'accept' },
    { label: '修改', value: 'modify' },
  ];
}

function isPracticeExecutionPanel(panel) {
  return Boolean(practiceExecutionType(panel));
}

function practicePanelLabel(panel) {
  return practiceSidebarItems().find(item => item.key === panel)?.name || '数据';
}

function practiceNeedsPlan(panel) {
  return ['schedules', 'projects', 'syllabus', 'lessonPlans', 'gradeRules', 'scores', 'reflections'].includes(panel);
}

function practiceTextPanel(panel) {
  return ['plans', 'syllabus', 'lessonPlans', 'reflections'].includes(panel);
}

function showPracticeAddButton(module, panel) {
  if (panel === 'overview') {
    return false;
  }
  if (panel === 'scores') {
    return canManagePractice(module) || canApprovePractice(module);
  }
  if (panel === 'signIns') {
    return isStudentRole.value;
  }
  if (['journals', 'reports'].includes(panel)) {
    return isStudentRole.value;
  }
  return canManagePractice(module);
}

function showPracticeRowEdit(module, panel, row) {
  if (panel === 'signIns') {
    return false;
  }
  if (['journals', 'reports'].includes(panel)) {
    return isStudentRole.value && ['draft', 'modify'].includes(row?.status || '');
  }
  if (practiceReviewEntity(panel)) {
    return canManagePractice(module) && ['draft', 'modify'].includes(row?.status || '');
  }
  return canManagePractice(module);
}

function practiceOverviewCards(module) {
  const overview = practiceModuleState(module).overview;
  return [
    { name: '待审计划', value: overview.plans_waiting || 0, theme: 'primary', icon: FileText, panel: 'plans' },
    { name: '课表安排', value: overview.schedules || 0, theme: 'green', icon: CalendarCheck, panel: 'schedules' },
    { name: '项目发布', value: overview.projects || 0, theme: 'primary', icon: Workflow, panel: 'projects' },
    { name: '签到记录', value: practiceModuleState(module).lists.signIns.pagination.total || 0, theme: 'green', icon: MapPin, panel: 'signIns' },
    { name: '过程日志', value: practiceModuleState(module).lists.journals.pagination.total || 0, theme: 'teal', icon: FileClock, panel: 'journals' },
    { name: '总结报告', value: practiceModuleState(module).lists.reports.pagination.total || 0, theme: 'primary', icon: FileText, panel: 'reports' },
    { name: '待审大纲', value: overview.syllabus_waiting || 0, theme: 'teal', icon: BookOpen, panel: 'syllabus' },
    { name: '待审教案', value: overview.lesson_plans_waiting || 0, theme: 'amber', icon: FileText, panel: 'lessonPlans' },
    { name: '成绩记录', value: overview.scores_submitted || 0, theme: 'primary', icon: GraduationCap, panel: 'scores' },
    { name: '待审反思', value: overview.reflections_waiting || 0, theme: 'teal', icon: FileClock, panel: 'reflections' },
    { name: '今日课表', value: overview.today_schedules || 0, theme: 'green', icon: MapPin, panel: 'schedules' },
  ];
}

function practiceStatusUsesSwitch(panel) {
  return ['schedules', 'projects', 'gradeRules', 'rooms'].includes(panel);
}

function practiceDefaultStatus(panel) {
  if (practiceReviewEntity(panel)) {
    return 'draft';
  }
  if (panel === 'scores') {
    return 'accept';
  }
  return 'enabled';
}

function practiceRatioText(value) {
  if (Array.isArray(value)) {
    return value.map(item => `${item.name || item.label || '项目'}${item.weight || item.ratio || ''}%`).join('，');
  }
  if (value && typeof value === 'object') {
    return Object.entries(value).map(([key, val]) => `${key}${val}%`).join('，');
  }
  return value ? String(value) : '';
}

function practiceListConfig(module, panel) {
  const commonFilters = ['grade_id', 'dep_id', 'profession_id', 'status', 'keyword'];
  const filters = practiceFilters(module, panel, panel === 'schedules'
    ? ['grade_id', 'dep_id', 'profession_id', 'plan_id', 'place_type', 'status', 'keyword']
    : panel === 'projects'
      ? ['grade_id', 'dep_id', 'profession_id', 'class_id', 'plan_id', 'schedule_id', 'status', 'keyword']
    : panel === 'rooms'
      ? ['dep_id', 'status', 'keyword']
    : ['signIns', 'journals', 'reports'].includes(panel)
      ? ['grade_id', 'dep_id', 'profession_id', 'class_id', 'plan_id', 'project_id', 'status', 'date', 'keyword']
      : panel === 'scores'
        ? ['grade_id', 'dep_id', 'profession_id', 'plan_id', 'keyword']
        : commonFilters);
  const columns = {
    plans: [
      { prop: 'title', label: '计划标题', minWidth: 180 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'dep_name', label: '学院', minWidth: 140 },
      { prop: 'profession_name', label: '专业', minWidth: 140 },
      { prop: 'teacher_name', label: '任课教师', width: 120 },
      { key: 'source_type', label: '来源', width: 100, formatter: row => practiceSourceText(row.source_type) },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
      { prop: 'created_at', label: '创建时间', width: 168 },
    ],
    schedules: [
      { prop: 'title', label: '课表标题', minWidth: 180 },
      { prop: 'plan_title', label: '关联计划', minWidth: 160 },
      { prop: 'schedule_date', label: '日期', width: 110 },
      { key: 'time', label: '时间', width: 120, formatter: row => `${row.start_time || '-'}-${row.end_time || '-'}` },
      { key: 'place', label: '地点', minWidth: 180, formatter: row => row.room_name || row.base_name || row.location || '-' },
      { prop: 'student_count', label: '学生数', width: 90 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
    ],
    projects: [
      { prop: 'title', label: '项目名称', minWidth: 180 },
      { prop: 'plan_title', label: '教学计划', minWidth: 160 },
      { prop: 'schedule_title', label: '关联课表', minWidth: 160 },
      { prop: 'class_name', label: '班级', minWidth: 120 },
      { prop: 'teacher_name', label: '负责人', width: 120 },
      { key: 'date_range', label: '执行日期', width: 160, formatter: row => [row.start_date, row.end_date].filter(Boolean).join(' 至 ') || '-' },
      { key: 'bound_student_count', label: '学生范围', width: 100, formatter: row => row.bound_student_count ?? row.student_count ?? 0 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
    ],
    signIns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'project_title', label: '项目', minWidth: 180 },
      { prop: 'date', label: '日期', width: 110 },
      { prop: 'sign_time', label: '签到时间', width: 168 },
      { prop: 'location', label: '位置', minWidth: 180 },
      { prop: 'teacher_name', label: '负责老师', width: 120 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
    ],
    journals: [
      { prop: 'title', label: '日志标题', minWidth: 180 },
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'project_title', label: '项目', minWidth: 180 },
      { prop: 'date', label: '日期', width: 110 },
      { prop: 'content', label: '内容', minWidth: 240 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
      { prop: 'created_at', label: '提交时间', width: 168 },
    ],
    reports: [
      { prop: 'title', label: '报告标题', minWidth: 180 },
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'project_title', label: '项目', minWidth: 180 },
      { prop: 'content', label: '内容', minWidth: 240 },
      { prop: 'submitted_at', label: '提交时间', width: 168 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
    ],
    syllabus: practiceDocumentColumns('大纲'),
    lessonPlans: practiceDocumentColumns('教案'),
    gradeRules: [
      { prop: 'title', label: '规则名称', minWidth: 180 },
      { prop: 'plan_title', label: '关联计划', minWidth: 160 },
      { key: 'ratio', label: '比例', minWidth: 220, formatter: row => practiceRatioText(row.ratio_json) || '-' },
      { prop: 'teacher_name', label: '任课教师', width: 120 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
    ],
    scores: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'plan_title', label: '关联计划', minWidth: 160 },
      { prop: 'score_value', label: '成绩', width: 90 },
      { prop: 'teacher_name', label: '评分教师', width: 120 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
    ],
    reflections: practiceDocumentColumns('反思报告'),
    rooms: [
      { prop: 'name', label: '场地名称', minWidth: 180 },
      { prop: 'code', label: '编号', width: 120 },
      { prop: 'dep_name', label: '学院', minWidth: 140 },
      { prop: 'room_type', label: '类型', width: 110 },
      { prop: 'capacity', label: '容量', width: 90 },
      { prop: 'location', label: '位置', minWidth: 180 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
    ],
  }[panel] || [];
  return { filters, columns };
}

function practiceDocumentColumns(label) {
  return [
    { prop: 'title', label: `${label}标题`, minWidth: 180 },
    { prop: 'plan_title', label: '关联计划', minWidth: 160 },
    { prop: 'grade_name', label: '届次', width: 100 },
    { prop: 'dep_name', label: '学院', minWidth: 130 },
    { prop: 'teacher_name', label: '任课教师', width: 120 },
    { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
    { prop: 'content', label: '内容', minWidth: 240 },
    { prop: 'created_at', label: '提交时间', width: 168 },
  ];
}

function practiceFilters(module, panel, keys) {
  if (isStudentRole.value) {
    return [];
  }
  if (isTeacherRole.value) {
    return practiceFilterDefinitions(module, panel, ['grade_id', 'keyword']);
  }
  return practiceFilterDefinitions(module, panel, keys);
}

function practiceFilterDefinitions(module, panel, keys) {
  const options = practiceModuleState(module).options;
  const values = practiceModuleState(module).filters[panel] || {};
  const definitions = {
    keyword: { key: 'keyword', label: '关键词', placeholder: '标题、课程、学生、内容' },
    grade_id: { key: 'grade_id', label: '届次', type: 'select', options: optionItems(options.grades, 'grade_id', 'grade_name') },
    dep_id: { key: 'dep_id', label: '学院', type: 'select', options: optionItems(filterAcademicDepartments(options, values), 'dep_id', 'dep_name') },
    profession_id: { key: 'profession_id', label: '专业', type: 'select', options: optionItems(filterAcademicItems(options.professions, values, ['grade_id', 'dep_id']), 'profession_id', 'profession_name') },
    class_id: { key: 'class_id', label: '班级', type: 'select', options: optionItems(filterAcademicItems(options.classes, values, ['grade_id', 'dep_id', 'profession_id']), 'class_id', 'class_name') },
    plan_id: { key: 'plan_id', label: '教学计划', type: 'select', options: optionItems(filterAcademicItems(options.plans, values, ['grade_id', 'dep_id', 'profession_id', 'class_id']), 'id', 'title') },
    project_id: { key: 'project_id', label: '项目', type: 'select', options: optionItems(filterAcademicItems(options.projects || [], values, ['grade_id', 'dep_id', 'profession_id', 'class_id', 'plan_id']), 'id', 'title') },
    schedule_id: { key: 'schedule_id', label: '课表', type: 'select', options: optionItems(filterAcademicItems(options.schedules, values, ['grade_id', 'dep_id', 'profession_id', 'class_id', 'plan_id']), 'id', 'title') },
    room_id: { key: 'room_id', label: '场地', type: 'select', options: optionItems(options.rooms, 'id', 'name') },
    place_type: { key: 'place_type', label: '地点类型', type: 'select', options: practicePlaceTypeOptions() },
    source_type: { key: 'source_type', label: '来源', type: 'select', options: practiceSourceTypeOptions() },
    status: { key: 'status', label: '状态', type: 'select', options: statusOptions() },
    date: { key: 'date', label: '日期', placeholder: 'YYYY-MM-DD' },
  };
  return keys.map(key => definitions[key]).filter(Boolean);
}

function practiceSourceTypeOptions() {
  return [
    { value: 'jw', label: '教务拉取' },
    { value: 'manual', label: '手动填报' },
  ];
}

function practicePlaceTypeOptions() {
  return [
    { value: 'inside', label: '校内' },
    { value: 'outside', label: '校外' },
  ];
}

function practiceSourceText(value) {
  return practiceSourceTypeOptions().find(item => item.value === value)?.label || value || '-';
}

function practiceProfessionOptions(module) {
  const state = practiceModuleState(module);
  const gradeId = Number(state.form.grade_id || 0);
  const depId = Number(state.form.dep_id || 0);
  return state.options.professions.filter((item) => {
    const matchGrade = !gradeId || Number(item.grade_id || 0) === gradeId;
    const matchDepartment = !depId || Number(item.dep_id || 0) === depId;
    return matchGrade && matchDepartment;
  });
}

function normalizePracticeCascade(module) {
  const state = practiceModuleState(module);
  const options = practiceProfessionOptions(module);
  const current = Number(state.form.profession_id || 0);
  if (current && !options.some(item => Number(item.profession_id) === current)) {
    state.form.profession_id = null;
  }
  normalizePracticeClass(module);
  normalizePracticePlan(module);
}

function handlePracticeProfessionChange(module) {
  const state = practiceModuleState(module);
  const profession = state.options.professions.find(item => Number(item.profession_id) === Number(state.form.profession_id || 0));
  if (!profession) {
    return;
  }
  state.form.grade_id = profession.grade_id || state.form.grade_id;
  state.form.dep_id = profession.dep_id || state.form.dep_id;
  normalizePracticeClass(module);
}

function handlePracticePlanChange(module) {
  const state = practiceModuleState(module);
  const plan = state.options.plans.find(item => Number(item.id) === Number(state.form.plan_id || 0));
  if (!plan) {
    return;
  }
  ['grade_id', 'dep_id', 'profession_id', 'class_id', 'teacher_id', 'course_name'].forEach((field) => {
    if (hasFilterValue(plan[field])) {
      state.form[field] = plan[field];
    }
  });
}

function handlePracticePlaceTypeChange(module) {
  const form = practiceModuleState(module).form;
  if (form.place_type === 'inside') {
    form.base_id = null;
    return;
  }
  form.room_id = null;
}

function handlePracticeScheduleChange(module) {
  const state = practiceModuleState(module);
  const schedule = state.options.schedules.find(item => Number(item.id) === Number(state.form.schedule_id || 0));
  if (!schedule) {
    return;
  }
  ['plan_id', 'grade_id', 'dep_id', 'profession_id', 'class_id', 'teacher_id', 'course_name'].forEach((field) => {
    if (hasFilterValue(schedule[field])) {
      state.form[field] = schedule[field];
    }
  });
  if (schedule.schedule_date) {
    state.form.start_date = state.form.start_date || schedule.schedule_date;
    state.form.end_date = state.form.end_date || schedule.schedule_date;
  }
  state.form.student_count = schedule.student_count || state.form.student_count || '';
}

function practiceClassOptions(module) {
  const state = practiceModuleState(module);
  return filterAcademicItems(state.options.classes, state.form, ['grade_id', 'dep_id', 'profession_id']);
}

function practicePlanOptions(module, panel) {
  const state = practiceModuleState(module);
  const planKeys = panel === 'schedules' ? ['grade_id', 'dep_id', 'profession_id'] : ['grade_id', 'dep_id', 'profession_id', 'class_id'];
  const plans = filterAcademicItems(state.options.plans, state.form, planKeys);
  if (panel === 'schedules') {
    return plans.filter(item => ['accept', 'enabled'].includes(String(item.status || '')));
  }
  return plans;
}

function practiceBaseOptions(module) {
  const state = practiceModuleState(module);
  const depId = state.form.dep_id;
  return (state.options.bases || []).filter(item => !hasFilterValue(depId) || !hasFilterValue(item.dep_id) || sameFilterValue(item.dep_id, depId));
}

function practiceScheduleOptions(module) {
  const state = practiceModuleState(module);
  return filterAcademicItems(state.options.schedules, state.form, ['grade_id', 'dep_id', 'profession_id', 'class_id', 'plan_id']);
}

function practiceScheduleLabel(schedule) {
  return [
    schedule.title || schedule.course_name || `课表 ${schedule.id}`,
    schedule.schedule_date,
    [schedule.start_time, schedule.end_time].filter(Boolean).join('-'),
  ].filter(Boolean).join(' / ');
}

function normalizePracticeClass(module) {
  const state = practiceModuleState(module);
  const current = Number(state.form.class_id || 0);
  if (!current || practiceClassOptions(module).some(item => Number(item.class_id) === current)) {
    return;
  }
  state.form.class_id = null;
}

function normalizePracticePlan(module) {
  const state = practiceModuleState(module);
  const current = Number(state.form.plan_id || 0);
  if (!current || practicePlanOptions(module, 'schedules').some(item => Number(item.id) === current)) {
    return;
  }
  state.form.plan_id = null;
}

function validatePracticeForm(module, panel, form) {
  const title = String(form.title || form.course_name || '').trim();
  if (panel === 'plans') {
    if (!title) {
      return '请填写教学计划标题或课程名称';
    }
    if (!form.grade_id) {
      return '请选择届次';
    }
    if (!form.dep_id) {
      return '请选择学院';
    }
    if (!form.profession_id) {
      return '请选择专业';
    }
  }
  if (panel !== 'schedules') {
    if (panel === 'projects') {
      if (!title) {
        return '请填写项目名称';
      }
      if (!form.plan_id) {
        return '请选择教学计划';
      }
      if (!form.schedule_id) {
        return '请选择已发布课表';
      }
      if (!form.teacher_id) {
        return '请选择项目负责人';
      }
      if (!form.class_id) {
        return '请选择项目班级';
      }
      if (form.start_date && form.end_date && String(form.end_date) < String(form.start_date)) {
        return '项目结束日期不能早于开始日期';
      }
    }
    return '';
  }
  if (!form.plan_id) {
    return '请选择已审核通过的教学计划';
  }
  if (!practicePlanOptions(module, panel).some(item => Number(item.id) === Number(form.plan_id))) {
    return '请选择已审核通过且匹配当前条件的教学计划';
  }
  if (!form.teacher_id) {
    return '请选择任课教师';
  }
  if (!form.class_id) {
    return '请选择课表班级';
  }
  if (!form.schedule_date || !form.start_time || !form.end_time) {
    return '请填写完整课表日期和时间';
  }
  if (String(form.end_time) <= String(form.start_time)) {
    return '课表结束时间必须晚于开始时间';
  }
  if ((form.place_type || 'inside') === 'inside' && !form.room_id) {
    return '请选择可用实验实训室';
  }
  if ((form.place_type || 'inside') === 'outside' && !form.base_id && !String(form.location || '').trim()) {
    return '校外安排需选择基地或填写地点';
  }
  return '';
}

function practiceQueryParams(module, panel, page = 1) {
  const state = practiceModuleState(module);
  const filters = state.filters[panel] || {};
  const params = {
    entity: practicePanelEntity(panel),
    page,
    page_size: state.lists[panel]?.pagination.page_size || 20,
  };
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) {
      params[key] = value;
    }
  });
  return params;
}

function setPracticeFilter(module, panel, event) {
  const values = practiceModuleState(module).filters[panel];
  values[event.key] = event.value ?? '';
  normalizeFilterCascade(values, practiceModuleState(module).options, event.key);
}

function resetPracticeFilters(module, panel) {
  const state = practiceModuleState(module);
  state.filters[panel] = {
    ...emptyPracticeFilters(),
    ...organizationScopeDefaults(state.options),
  };
  normalizeFilterCascade(state.filters[panel], state.options);
  loadPracticePanel(module, panel, 1);
}

async function loadPracticeFoundation(module) {
  if (!hasPermission(`${module}:view`)) {
    return;
  }
  const state = practiceModuleState(module);
  const [overview, options] = await Promise.all([
    fetchPracticeOverview(module),
    fetchPracticeOptions(module),
  ]);
  state.overview = {
    ...emptyPracticeOverview(),
    ...(overview || {}),
  };
  state.options = {
    ...emptyPracticeOptions(),
    ...(options || {}),
  };
  const defaults = organizationScopeDefaults(state.options);
  Object.keys(state.filters).forEach((panel) => {
    applyAcademicDefaults(state.filters[panel], defaults);
    normalizeFilterCascade(state.filters[panel], state.options);
  });
}

async function loadPracticePanel(module, panel = 'overview', page = 1) {
  if (!hasPermission(`${module}:view`)) {
    return;
  }
  const state = practiceModuleState(module);
  state.loading = true;
  state.message = '';
  try {
    await loadPracticeFoundation(module);
    if (panel !== 'overview') {
      const data = isPracticeExecutionPanel(panel)
        ? await fetchPracticeExecutionList(module, practiceExecutionType(panel), practiceExecutionQueryParams(module, panel, page))
        : await fetchPracticeList(module, practiceQueryParams(module, panel, page));
      state.lists[panel].items = data.items || [];
      state.lists[panel].pagination = {
        ...state.lists[panel].pagination,
        ...(data.pagination || {}),
      };
    }
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

function openPracticeDialog(module, panel, row = null) {
  const state = practiceModuleState(module);
  state.form = panel === 'scores'
    ? emptyPracticeExecutionForm(row || {})
    : isPracticeExecutionPanel(panel)
    ? emptyPracticeExecutionForm(row || {})
    : emptyPracticeForm(row || {});
  if (!row) {
    if (isPracticeExecutionPanel(panel) || panel === 'scores') {
      state.form.project_id = state.options.projects[0]?.id || null;
    } else {
      const defaults = organizationScopeDefaults(state.options);
      state.form.grade_id = defaults.grade_id || currentGradeId(state.options) || null;
      state.form.dep_id = defaults.dep_id || state.options.departments[0]?.dep_id || null;
      state.form.profession_id = defaults.profession_id || null;
      state.form.class_id = defaults.class_id || null;
      state.form.plan_id = practiceNeedsPlan(panel) ? state.options.plans[0]?.id || null : null;
      state.form.status = practiceDefaultStatus(panel);
    }
  }
  state.dialog = {
    ...emptyOperationDialog(),
    type: panel === 'scores' ? 'scoreProject' : (isPracticeExecutionPanel(panel) ? 'execution' : 'edit'),
    title: `${row ? '编辑' : '新增'}${practicePanelLabel(panel)}`,
    entity: practicePanelEntity(panel),
    panel,
    row,
  };
}

function closePracticeDialog(module) {
  practiceModuleState(module).dialog = emptyOperationDialog();
}

async function confirmPracticeDialog(module) {
  const state = practiceModuleState(module);
  if (state.loading) {
    return;
  }
  if (state.dialog.type === 'execution') {
    await savePracticeExecutionDialog(module);
    return;
  }
  if (state.dialog.type === 'scoreProject') {
    await savePracticeProjectScoreDialog(module);
    return;
  }
  if (state.dialog.type === 'edit') {
    await savePractice(module);
    return;
  }
  if (state.dialog.type === 'review') {
    const error = validatePracticeReviewReason(module, state.dialog.entity, state.dialog.status, state.dialog.reason);
    if (error) {
      state.message = error;
      return;
    }
    await reviewPractice(module);
    return;
  }
  if (state.dialog.type === 'reopen') {
    const error = validatePracticeReviewReason(module, state.dialog.entity, 'modify', state.dialog.reason, '修改理由');
    if (error) {
      state.message = error;
      return;
    }
    await requestPracticeReopen(module);
  }
}

function practiceDialogUsesWorkflowButtons(module) {
  const state = practiceModuleState(module);
  if (state.dialog.type === 'execution') {
    return Boolean(practiceReviewEntity(state.dialog.panel));
  }
  if (state.dialog.type !== 'edit') {
    return false;
  }
  return Boolean(practiceReviewEntity(state.dialog.panel));
}

async function submitPracticeWorkflowDialog(module, status) {
  const state = practiceModuleState(module);
  if (state.loading) {
    return;
  }
  if (state.dialog.type === 'execution') {
    await savePracticeExecutionDialog(module, status);
    return;
  }
  state.form.status = status;
  await savePractice(module, status);
}

function practiceExecutionQueryParams(module, panel, page = 1) {
  const state = practiceModuleState(module);
  const filters = state.filters[panel] || {};
  const params = {
    page,
    page_size: state.lists[panel]?.pagination.page_size || 20,
  };
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) {
      params[key] = value;
    }
  });
  return params;
}

async function savePracticeExecutionDialog(module, workflowStatus = 'wait') {
  const state = practiceModuleState(module);
  if (state.loading) {
    return;
  }
  const panel = state.dialog.row ? state.dialog.panel || currentPracticeDialogPanel(module) : currentPracticeDialogPanel(module);
  const execution = practiceExecutionType(panel);
  if (!state.form.project_id) {
    state.message = '请选择项目';
    return;
  }
  if (execution !== 'sign_in' && !String(state.form.content || '').trim()) {
    state.message = '请填写内容';
    return;
  }
  state.loading = true;
  state.message = '';
  try {
    await savePracticeExecution(module, execution, {
      id: state.form.id || undefined,
      project_id: state.form.project_id,
      student_id: state.form.student_id || undefined,
      title: state.form.title,
      date: state.form.date,
      content: state.form.content,
      location: state.form.location,
      longitude: state.form.longitude,
      latitude: state.form.latitude,
      remark: state.form.remark,
      status: execution === 'sign_in' ? 'signed' : workflowStatus,
    });
    closePracticeDialog(module);
    await loadPracticePanel(module, panel, state.lists[panel]?.pagination.page || 1);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

async function savePracticeProjectScoreDialog(module) {
  const state = practiceModuleState(module);
  if (!state.form.project_id || !state.form.student_id) {
    state.message = '请选择项目和学生';
    return;
  }
  state.loading = true;
  state.message = '';
  try {
    await savePracticeProjectScore(module, {
      project_id: state.form.project_id,
      student_id: state.form.student_id,
      attendance_score: state.form.attendance_score,
      material_score: state.form.material_score,
      report_score: state.form.report_score,
      score_value: state.form.score_value,
      status: 'accept',
    });
    closePracticeDialog(module);
    await loadPracticePanel(module, 'scores', state.lists.scores?.pagination.page || 1);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

function currentPracticeDialogPanel(module) {
  const state = practiceModuleState(module);
  if (state.dialog.panel) {
    return state.dialog.panel;
  }
  return Object.keys(state.lists).find(panel => practicePanelEntity(panel) === state.dialog.entity) || 'plans';
}

async function savePractice(module, workflowStatus = null) {
  const state = practiceModuleState(module);
  if (state.loading) {
    return;
  }
  const panel = practiceSidebarItems().find(item => practicePanelEntity(item.key) === state.dialog.entity)?.key || 'plans';
  const error = validatePracticeForm(module, panel, state.form);
  if (error) {
    state.message = error;
    return;
  }
  const payload = {
    ...state.form,
    entity: state.dialog.entity,
    status: workflowStatus || state.form.status,
    ratio_json: ratioTextToJson(state.form.ratio_text),
  };
  state.loading = true;
  state.message = '';
  try {
    await savePracticeItem(module, payload);
    closePracticeDialog(module);
    await loadPracticePanel(module, panel, state.lists[panel]?.pagination.page || 1);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

function ratioTextToJson(value) {
  const text = String(value || '').trim();
  if (!text) {
    return [];
  }
  return text.split(/[，,;\n]+/).map((part) => {
    const item = part.trim();
    const match = item.match(/^(.+?)(\d+(?:\.\d+)?)%?$/);
    return match ? { name: match[1].trim(), weight: Number(match[2]) } : { name: item, weight: null };
  });
}

function canReviewPracticeRow(module, panel, row) {
  return Boolean(row && row.status === 'wait' && practiceReviewEntity(panel) && canApprovePractice(module));
}

function canReopenPracticeRow(module, panel, row) {
  return Boolean(row && row.status === 'accept' && practiceReviewEntity(panel) && canApprovePractice(module));
}

async function openPracticeReviewDialog(module, panel, row, status) {
  if (!canReviewPracticeRow(module, panel, row)) {
    practiceModuleState(module).message = '仅待审核数据可处理';
    return;
  }
  const state = practiceModuleState(module);
  const actionText = status === 'accept' ? '通过' : '退回';
  state.dialog = {
    ...emptyOperationDialog(),
    type: 'review',
    title: `${actionText}${practicePanelLabel(panel)}`,
    description: `请确认是否${actionText}「${row.title || row.name || row.id}」。`,
    entity: practiceReviewEntity(panel),
    panel,
    status,
    row,
    reason: status === 'accept' ? '同意' : '',
  };
  await loadPracticeDialogReviewDraft(module);
}

function openPracticeReopenDialog(module, panel, row) {
  if (!canReopenPracticeRow(module, panel, row)) {
    practiceModuleState(module).message = '仅已通过数据可发起通过后修改';
    return;
  }
  const state = practiceModuleState(module);
  state.dialog = {
    ...emptyOperationDialog(),
    type: 'reopen',
    title: `通过后修改${practicePanelLabel(panel)}`,
    description: `此操作会新增审核记录，并将「${row.title || row.name || row.id}」改为需修改。`,
    entity: practiceReviewEntity(panel),
    panel,
    status: 'modify',
    row,
    reason: '',
  };
}

async function openPracticeTimelineDialog(module, panel, row) {
  const state = practiceModuleState(module);
  state.dialog = {
    ...emptyOperationDialog(),
    type: 'timeline',
    title: `${practicePanelLabel(panel)}流程记录`,
    description: row.title || row.name || String(row.id),
    entity: practiceReviewEntity(panel),
    row,
  };
  state.loading = true;
  state.message = '';
  try {
    const data = isPracticeExecutionPanel(panel)
      ? await fetchPracticeExecutionTimeline(module, { execution: practiceExecutionType(panel), id: row.id })
      : await fetchPracticeTimeline(module, { entity: practiceReviewEntity(panel), id: row.id });
    state.dialog.cycles = data.cycles || [];
    state.dialog.timeline = data.items || data.records || [];
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

async function loadPracticeDialogReviewDraft(module) {
  const state = practiceModuleState(module);
  const { entity, panel, row } = state.dialog;
  if (!entity || !row?.id) {
    return;
  }
  const params = isPracticeExecutionPanel(panel)
    ? { execution: practiceExecutionType(panel), id: row.id }
    : { entity, id: row.id };
  try {
    const data = await fetchPracticeReviewDraft(module, params);
    const draft = data.draft || null;
    if (draft) {
      state.dialog.status = draft.review_status || state.dialog.status;
      state.dialog.reason = draft.opinion || '';
    }
  } catch (error) {
    state.message = error.message;
  }
}

async function savePracticeDialogReviewDraft(module) {
  const state = practiceModuleState(module);
  if (state.loading) {
    return;
  }
  const { entity, panel, row, status, reason } = state.dialog;
  if (!entity || !row?.id) {
    return;
  }
  const payload = isPracticeExecutionPanel(panel)
    ? { execution: practiceExecutionType(panel), id: row.id, status, opinion: reason }
    : { entity, id: row.id, status, opinion: reason };
  state.loading = true;
  state.message = '';
  try {
    await savePracticeReviewDraft(module, payload);
    state.message = '审核草稿已保存';
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

function practiceReviewRule(module, entity, status) {
  return practiceModuleState(module).options.review_rules?.[entity]?.[status]
    || { min: 0, max: null };
}

function practiceReviewRuleText(module, entity, status, label = null) {
  const rule = practiceReviewRule(module, entity, status);
  const fieldLabel = label || (status === 'modify' ? '退回原因' : '审核意见');
  if (!rule.min && !rule.max) {
    return `${fieldLabel}字数不限制`;
  }
  if (rule.min && rule.max) {
    return `${fieldLabel}需 ${rule.min}-${rule.max} 字`;
  }
  if (rule.min) {
    return `${fieldLabel}至少 ${rule.min} 字`;
  }
  return `${fieldLabel}最多 ${rule.max} 字`;
}

function practiceReviewRuleMax(module, entity, status) {
  return practiceReviewRule(module, entity, status).max || null;
}

function practiceReviewRuleMaxText(module, entity, status) {
  return practiceReviewRuleMax(module, entity, status) || '不限';
}

function trimPracticeReviewReasonMax(module, event) {
  const state = practiceModuleState(module);
  const max = practiceReviewRuleMax(module, state.dialog.entity, state.dialog.status);
  if (!max) {
    return;
  }
  const chars = Array.from(String(event.target.value || ''));
  if (chars.length <= max) {
    return;
  }
  const value = chars.slice(0, max).join('');
  event.target.value = value;
  state.dialog.reason = value;
}

function validatePracticeReviewReason(module, entity, status, reason, label = null) {
  const rule = practiceReviewRule(module, entity, status);
  const length = textLength(reason);
  const fieldLabel = label || (status === 'modify' ? '退回原因' : '审核意见');
  if (rule.min && length < rule.min) {
    return `${fieldLabel}至少 ${rule.min} 字`;
  }
  if (rule.max && length > rule.max) {
    return `${fieldLabel}最多 ${rule.max} 字`;
  }
  return '';
}

async function reviewPractice(module) {
  const state = practiceModuleState(module);
  const panel = state.dialog.panel || practiceSidebarItems().find(item => practiceReviewEntity(item.key) === state.dialog.entity)?.key || 'plans';
  state.loading = true;
  state.message = '';
  try {
    if (isPracticeExecutionPanel(panel)) {
      await reviewPracticeExecution(module, practiceExecutionType(panel), {
        id: state.dialog.row.id,
        status: state.dialog.status,
        opinion: state.dialog.reason,
      });
    } else {
      await reviewPracticeItem(module, {
        entity: state.dialog.entity,
        id: state.dialog.row.id,
        status: state.dialog.status,
        opinion: state.dialog.reason,
      });
    }
    closePracticeDialog(module);
    await loadPracticePanel(module, panel, state.lists[panel]?.pagination.page || 1);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

async function requestPracticeReopen(module) {
  const state = practiceModuleState(module);
  const panel = state.dialog.panel || practiceSidebarItems().find(item => practiceReviewEntity(item.key) === state.dialog.entity)?.key || 'plans';
  state.loading = true;
  state.message = '';
  try {
    if (isPracticeExecutionPanel(panel)) {
      await requestPracticeExecutionModification(module, {
        execution: practiceExecutionType(panel),
        id: state.dialog.row.id,
        opinion: state.dialog.reason,
      });
    } else {
      await requestPracticeModification(module, {
        entity: state.dialog.entity,
        id: state.dialog.row.id,
        opinion: state.dialog.reason,
      });
    }
    closePracticeDialog(module);
    await loadPracticePanel(module, panel, state.lists[panel]?.pagination.page || 1);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

function setPagedList(key, data) {
  internshipState.lists[key].items = data.items || [];
  internshipState.lists[key].pagination = {
    ...internshipState.lists[key].pagination,
    ...(data.pagination || {}),
  };
}

function hasFilterValue(value) {
  return value !== '' && value !== null && value !== undefined;
}

function internshipFilters(keys, values = {}, options = internshipState.options) {
  const visibleKeys = keys.filter(key => key !== 'semester'); // 暂时隐藏学期筛选，后续需要时恢复。
  const definitions = {
    keyword: { key: 'keyword', label: '关键词', placeholder: '学生、学号、教师、标题' },
    student_keyword: { key: 'keyword', label: '学生', placeholder: '姓名或学号' },
    semester: { key: 'semester', label: '学期', type: 'select', options: semesterOptions() },
    base_id: { key: 'base_id', label: '实习基地', type: 'select', options: optionItems(internshipState.options.bases, 'id', 'name') },
    grade_id: { key: 'grade_id', label: '届次', type: 'select', options: optionItems(options.grades, 'grade_id', 'grade_name') },
    dep_id: { key: 'dep_id', label: '学院', type: 'select', options: optionItems(filterAcademicDepartments(options, values), 'dep_id', 'dep_name') },
    profession_id: { key: 'profession_id', label: '专业', type: 'select', options: optionItems(filterAcademicItems(options.professions, values, ['grade_id', 'dep_id']), 'profession_id', 'profession_name') },
    class_id: { key: 'class_id', label: '班级', type: 'select', options: optionItems(filterAcademicItems(options.classes, values, ['grade_id', 'dep_id', 'profession_id']), 'class_id', 'class_name') },
    plan_id: { key: 'plan_id', label: '实习计划', type: 'select', options: optionItems(filterAcademicItems(options.plans, values, ['grade_id', 'dep_id', 'profession_id']), 'id', 'course_name') },
    arrangement_id: { key: 'arrangement_id', label: '实习任务', type: 'select', options: optionItems(filterAcademicItems(options.arrangements, values, ['grade_id', 'dep_id', 'profession_id', 'class_id']), 'id', 'title') },
    config_key: { key: 'config_key', label: '延期类型', type: 'select', options: delayConfigOptions() },
    status: { key: 'status', label: '状态', type: 'select', options: statusOptions() },
    archive_status: { key: 'archive_status', label: '归档状态', type: 'select', options: archiveStatusOptions() },
    result: { key: 'result', label: '巡查结果', type: 'select', options: inspectionResultOptions() },
    type: { key: 'type', label: '类型', type: 'select', options: internshipState.options.types.map(value => ({ value, label: arrangementTypeText(value) })) },
    organize_mode: { key: 'organize_mode', label: '组织方式', type: 'select', options: internshipState.options.organize_modes.map(value => ({ value, label: organizeModeText(value) })) },
  };
  return visibleKeys.map(key => definitions[key]).filter(Boolean);
}

function baseFlowFilters() {
  if (isStudentRole.value || isTeacherRole.value) {
    return [];
  }
  return [
    { key: 'type', label: '流程类型', type: 'select', options: baseFlowTypes },
    ...internshipFilters(['dep_id', 'base_id', 'status', 'keyword'], internshipState.filters.baseFlows),
  ];
}

function optionItems(items, valueKey, labelKey) {
  return (items || []).map(item => ({
    value: item[valueKey],
    label: item[labelKey] || item[valueKey],
  }));
}

function sameFilterValue(left, right) {
  return String(left ?? '') === String(right ?? '');
}

function matchAcademicFilters(item, values = {}, keys = ['grade_id', 'dep_id', 'profession_id', 'class_id']) {
  return keys.every((key) => {
    const expected = values[key];
    if (!hasFilterValue(expected)) {
      return true;
    }
    const actual = item?.[key];
    if (!hasFilterValue(actual)) {
      return true;
    }
    return sameFilterValue(actual, expected);
  });
}

function filterAcademicItems(items = [], values = {}, keys = ['grade_id', 'dep_id', 'profession_id', 'class_id']) {
  return (items || []).filter(item => matchAcademicFilters(item, values, keys));
}

function filterAcademicDepartments(options = {}, values = {}) {
  const departments = options.departments || [];
  if (!hasFilterValue(values.grade_id)) {
    return departments;
  }

  const depIds = new Set();
  [
    ...(options.professions || []),
    ...(options.classes || []),
    ...(options.students || []),
    ...(options.plans || []),
  ].forEach((item) => {
    if (matchAcademicFilters(item, values, ['grade_id']) && hasFilterValue(item.dep_id)) {
      depIds.add(String(item.dep_id));
    }
  });

  if (!depIds.size) {
    return departments;
  }

  const filtered = departments.filter(item => depIds.has(String(item.dep_id)));
  if (hasFilterValue(values.dep_id)) {
    const selected = departments.find(item => sameFilterValue(item.dep_id, values.dep_id));
    if (selected && !filtered.some(item => sameFilterValue(item.dep_id, selected.dep_id))) {
      return [selected, ...filtered];
    }
  }

  return filtered;
}

function findSelectedPlan(values = {}, options = {}) {
  if (!hasFilterValue(values.plan_id)) {
    return null;
  }
  return (options.plans || []).find(item => sameFilterValue(item.id ?? item.plan_id, values.plan_id)) || null;
}

function findSelectedArrangement(values = {}, options = {}) {
  if (!hasFilterValue(values.arrangement_id)) {
    return null;
  }
  return (options.arrangements || []).find(item => sameFilterValue(item.id, values.arrangement_id)) || null;
}

function normalizeFilterCascade(values = {}, options = {}, changedKey = '') {
  const selectedPlan = findSelectedPlan(values, options);
  if (changedKey === 'plan_id' && selectedPlan) {
    ['grade_id', 'dep_id', 'profession_id', 'class_id'].forEach((key) => {
      if (hasFilterValue(selectedPlan[key])) {
        values[key] = selectedPlan[key];
      }
    });
  }

  const selectedArrangement = findSelectedArrangement(values, options);
  if (changedKey === 'arrangement_id' && selectedArrangement) {
    ['grade_id', 'dep_id', 'profession_id'].forEach((key) => {
      if (hasFilterValue(selectedArrangement[key])) {
        values[key] = selectedArrangement[key];
      }
    });
  }

  const selectedClass = hasFilterValue(values.class_id)
    ? (options.classes || []).find(item => sameFilterValue(item.class_id, values.class_id))
    : null;
  if (hasFilterValue(values.class_id) && !selectedClass) {
    values.class_id = '';
  }
  if (changedKey === 'class_id' && selectedClass) {
    values.grade_id = selectedClass.grade_id || values.grade_id || '';
    values.dep_id = selectedClass.dep_id || values.dep_id || '';
    values.profession_id = selectedClass.profession_id || values.profession_id || '';
  }

  const selectedProfession = hasFilterValue(values.profession_id)
    ? (options.professions || []).find(item => sameFilterValue(item.profession_id, values.profession_id))
    : null;
  if (hasFilterValue(values.profession_id) && !selectedProfession) {
    values.profession_id = '';
    values.class_id = '';
  }
  if (changedKey === 'profession_id' && selectedProfession) {
    values.grade_id = selectedProfession.grade_id || values.grade_id || '';
    values.dep_id = selectedProfession.dep_id || values.dep_id || '';
  }

  const departments = options.departments || [];
  if (hasFilterValue(values.dep_id) && departments.length && !departments.some(item => sameFilterValue(item.dep_id, values.dep_id))) {
    values.dep_id = '';
    values.profession_id = '';
    values.class_id = '';
    values.plan_id = '';
  }

  if (hasFilterValue(values.profession_id)) {
    const professionVisible = filterAcademicItems(options.professions || [], values, ['grade_id', 'dep_id'])
      .some(item => sameFilterValue(item.profession_id, values.profession_id));
    if (!professionVisible) {
      values.profession_id = '';
      values.class_id = '';
      values.plan_id = '';
    }
  }

  if (hasFilterValue(values.class_id)) {
    const classVisible = filterAcademicItems(options.classes || [], values, ['grade_id', 'dep_id', 'profession_id'])
      .some(item => sameFilterValue(item.class_id, values.class_id));
    if (!classVisible) {
      values.class_id = '';
      values.plan_id = '';
    }
  }

  if (hasFilterValue(values.arrangement_id)) {
    const arrangementVisible = filterAcademicItems(options.arrangements || [], values, ['grade_id', 'dep_id', 'profession_id', 'class_id'])
      .some(item => sameFilterValue(item.id, values.arrangement_id));
    if (!arrangementVisible) {
      values.arrangement_id = '';
    }
  }

  if (hasFilterValue(values.plan_id)) {
    const planVisible = filterAcademicItems(options.plans || [], values, ['grade_id', 'dep_id', 'profession_id', 'class_id'])
      .some(item => sameFilterValue(item.id ?? item.plan_id, values.plan_id));
    if (!planVisible) {
      values.plan_id = '';
    }
  }
}

function statAcademicOptions() {
  if (!isPracticeScoreSheetReport()) {
    return internshipState.options;
  }
  return practiceModuleState(statState.filters.module_type || 'training').options;
}

function statGradeOptions() {
  return optionItems(statAcademicOptions().grades, 'grade_id', 'grade_name');
}

function statDepartmentOptions() {
  return optionItems(filterAcademicDepartments(statAcademicOptions(), statState.filters), 'dep_id', 'dep_name');
}

function statProfessionOptions() {
  return optionItems(filterAcademicItems(statAcademicOptions().professions, statState.filters, ['grade_id', 'dep_id']), 'profession_id', 'profession_name');
}

function statClassOptions() {
  return optionItems(filterAcademicItems(statAcademicOptions().classes, statState.filters, ['grade_id', 'dep_id', 'profession_id']), 'class_id', 'class_name');
}

async function handleStatFilterChange(key) {
  if (key === 'module_type') {
    statState.filters.plan_id = '';
    await loadPracticeFoundation(statState.filters.module_type || 'training');
    applyAcademicDefaults(statState.filters, organizationScopeDefaults(statAcademicOptions()));
  }

  normalizeStatCascade(key);
}

function normalizeStatCascade(changedKey = '') {
  normalizeFilterCascade(statState.filters, statAcademicOptions(), changedKey);
}

function arrangementSelectedProfession() {
  const professionId = Number(internshipState.arrangementForm.profession_id || 0);
  return internshipState.options.professions.find(item => Number(item.profession_id) === professionId) || null;
}

function arrangementSelectedPlan() {
  const planId = Number(internshipState.arrangementForm.plan_id || 0);
  return internshipState.options.plans.find(item => Number(item.id) === planId) || null;
}

function firstArrangementGrade() {
  return internshipState.options.grades[0] || null;
}

function internshipPlanLabel(plan) {
  return [plan.course_name, plan.course_code, plan.grade_name, plan.profession_name].filter(Boolean).join(' / ') || `计划 ${plan.id}`;
}

function arrangementPlanOptions() {
  const values = internshipState.arrangementForm;
  return filterAcademicItems(internshipState.options.plans, values, ['grade_id', 'dep_id', 'profession_id']);
}

function arrangementDepartmentOptions() {
  return internshipState.options.departments;
}

function arrangementProfessionOptions() {
  const gradeId = Number(internshipState.arrangementForm.grade_id || 0);
  const depId = Number(internshipState.arrangementForm.dep_id || 0);
  return internshipState.options.professions.filter((item) => {
    const matchGrade = !gradeId || Number(item.grade_id || 0) === gradeId;
    const matchDepartment = !depId || Number(item.dep_id || 0) === depId;
    return matchGrade && matchDepartment;
  });
}

function arrangementTeacherOptions() {
  const depId = Number(internshipState.arrangementForm.dep_id || 0);
  const professionId = Number(internshipState.arrangementForm.profession_id || 0);
  return internshipState.options.teachers.filter((teacher) => {
    const matchDep = !depId || Number(teacher.dep_id || 0) === depId;
    const matchProfession = !professionId || !teacher.profession_id || Number(teacher.profession_id || 0) === professionId;
    return matchDep && matchProfession;
  });
}

function arrangementClassOptions() {
  const values = internshipState.arrangementForm;
  return filterAcademicItems(internshipState.options.classes, values, ['grade_id', 'dep_id', 'profession_id']);
}

function planProfessionOptions() {
  return internshipState.options.professions.filter((profession) => {
    const gradeId = Number(internshipState.planForm.grade_id || 0);
    const depId = Number(internshipState.planForm.dep_id || 0);
    const matchGrade = !gradeId || Number(profession.grade_id || 0) === gradeId;
    const matchDep = !depId || Number(profession.dep_id || 0) === depId;
    return matchGrade && matchDep;
  });
}

function handleArrangementGradeChange() {
  normalizeArrangementCascade();
}

function handleArrangementDepartmentChange() {
  normalizeArrangementCascade();
}

function handleArrangementProfessionChange() {
  const profession = arrangementSelectedProfession();
  if (!profession) {
    return;
  }
  internshipState.arrangementForm.dep_id = profession.dep_id || internshipState.arrangementForm.dep_id;
  internshipState.arrangementForm.grade_id = profession.grade_id || internshipState.arrangementForm.grade_id;
}

function handleArrangementPlanChange() {
  const plan = arrangementSelectedPlan();
  if (!plan) {
    return;
  }
  internshipState.arrangementForm.grade_id = plan.grade_id || null;
  internshipState.arrangementForm.dep_id = plan.dep_id || null;
  internshipState.arrangementForm.profession_id = plan.profession_id || null;
  internshipState.arrangementForm.credit = plan.credit ?? internshipState.arrangementForm.credit;
  internshipState.arrangementForm.title = internshipState.arrangementForm.title || plan.course_name || '';
  normalizeArrangementCascade();
}

function handlePlanGradeChange() {
  normalizePlanCascade();
}

function handlePlanDepartmentChange() {
  normalizePlanCascade();
}

function normalizeArrangementCascade() {
  const plan = arrangementSelectedPlan();
  if (plan) {
    internshipState.arrangementForm.grade_id = plan.grade_id || internshipState.arrangementForm.grade_id;
    internshipState.arrangementForm.dep_id = plan.dep_id || internshipState.arrangementForm.dep_id;
    internshipState.arrangementForm.profession_id = plan.profession_id || internshipState.arrangementForm.profession_id;
  }
  const profession = arrangementSelectedProfession();
  if (!internshipState.arrangementForm.grade_id && profession?.grade_id) {
    internshipState.arrangementForm.grade_id = profession.grade_id;
  }
  if (!internshipState.arrangementForm.grade_id) {
    const grade = firstArrangementGrade();
    internshipState.arrangementForm.grade_id = grade?.grade_id || null;
  }

  if (!internshipState.arrangementForm.dep_id && profession?.dep_id) {
    internshipState.arrangementForm.dep_id = profession.dep_id;
  }
  if (!internshipState.arrangementForm.dep_id) {
    internshipState.arrangementForm.dep_id = arrangementDepartmentOptions()[0]?.dep_id || null;
  }

  normalizeArrangementProfession();
  normalizeArrangementClasses();
  normalizeArrangementTeacher();
}

function normalizeArrangementProfession() {
  const options = arrangementProfessionOptions();
  const current = Number(internshipState.arrangementForm.profession_id || 0);
  if (current && options.some(item => Number(item.profession_id) === current)) {
    return;
  }
  internshipState.arrangementForm.profession_id = options[0]?.profession_id || null;
}

function normalizeArrangementClasses() {
  const visibleIds = new Set(arrangementClassOptions().map(item => Number(item.class_id)));
  internshipState.arrangementForm.class_ids = (internshipState.arrangementForm.class_ids || []).filter(id => visibleIds.has(Number(id)));
}

function normalizeArrangementTeacher() {
  const teacherId = Number(internshipState.arrangementForm.teacher_id || 0);
  if (!teacherId) {
    return;
  }
  if (!arrangementTeacherOptions().some(item => Number(item.teacher_id) === teacherId)) {
    internshipState.arrangementForm.teacher_id = null;
  }
}

function normalizePlanCascade() {
  const visible = planProfessionOptions();
  const current = Number(internshipState.planForm.profession_id || 0);
  if (current && visible.some(item => Number(item.profession_id) === current)) {
    return;
  }
  internshipState.planForm.profession_id = visible[0]?.profession_id || null;
}

function scoreRuleText(value) {
  return {
    average: '按任务平均',
    sum: '按任务累计',
    weighted: '按权重核定',
    manual: '人工核定',
  }[value] || value || '-';
}

function courseScoreStatusText(value) {
  return value === 'complete' ? '已汇总' : '待汇总';
}

function statCellText(value) {
  return value === null || value === undefined || value === '' ? '-' : value;
}

function planContentText(value) {
  let text = '';
  if (typeof value === 'string') {
    try {
      const parsed = JSON.parse(value);
      text = parsed?.content || parsed?.summary || value;
    } catch {
      text = value;
    }
  } else {
    text = value?.content || value?.summary || JSON.stringify(value || {});
  }
  text = String(text || '').replace(/\s+/g, ' ').trim();
  return text ? (text.length > 80 ? `${text.slice(0, 80)}...` : text) : '-';
}

async function exportStatReport() {
  if (statState.loading) {
    return;
  }
  statState.loading = true;
  statState.message = '';
  let rows = [];
  try {
    rows = await collectPagedRows(fetchInternshipStats, {
      report: statState.report,
      ...statState.filters,
    });
  } catch (error) {
    statState.message = error.message;
  } finally {
    statState.loading = false;
  }
  if (!rows.length) {
    return;
  }
  const columns = currentStatColumns.value.map(column => ({
    ...column,
    prop: column.key,
    formatter: row => (column.type === 'status' ? statusText(row[column.key]) : statCellText(row[column.key])),
  }));
  exportRowsToCsv(currentStatReport.value.name, columns, rows);
}

async function collectPagedRows(fetcher, params = {}) {
  const rows = [];
  let page = 1;
  let total = 0;
  do {
    const data = await fetcher({
      ...params,
      page,
      page_size: 100,
    });
    const items = data.items || data.rows || [];
    rows.push(...items);
    total = data.pagination?.total || rows.length;
    page += 1;
  } while (rows.length < total && page <= 200);

  return rows;
}

function exportRowsToCsv(filename, columns, rows) {
  const exportColumns = (columns || []).filter(column => column.label && (column.prop || column.key));
  if (!exportColumns.length || !rows.length) {
    return;
  }
  const lines = [
    exportColumns.map(column => csvCell(column.label)).join(','),
    ...rows.map(row => exportColumns.map(column => csvCell(exportCellValue(column, row))).join(',')),
  ];
  const name = `${safeFilename(filename)}_${dateStamp()}.csv`;
  downloadTextFile(name, `\uFEFF${lines.join('\n')}`, 'text/csv;charset=utf-8');
}

function exportCellValue(column, row) {
  if (typeof column.formatter === 'function') {
    return column.formatter(row);
  }
  const key = column.prop || column.key;
  return statCellText(row[key]);
}

function csvCell(value) {
  const text = String(value ?? '').replace(/"/g, '""');
  return `"${text}"`;
}

function safeFilename(value) {
  return String(value || '导出数据').replace(/[\\/:*?"<>|]+/g, '_').slice(0, 80);
}

function dateStamp() {
  const now = new Date();
  const pad = value => String(value).padStart(2, '0');
  return `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}_${pad(now.getHours())}${pad(now.getMinutes())}`;
}

function downloadTextFile(filename, content, mimeType) {
  const blob = new Blob([content], { type: mimeType });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

function semesterOptions() {
  const values = new Set();
  internshipState.options.arrangements.forEach((item) => {
    if (item.semester) {
      values.add(item.semester);
    }
  });
  internshipState.lists.plans.items.forEach((item) => {
    if (item.semester) {
      values.add(item.semester);
    }
  });
  internshipState.options.students.forEach((item) => {
    if (item.semester) {
      values.add(item.semester);
    }
  });
  return Array.from(values).map(value => ({ value, label: value }));
}

function statusOptions() {
  return ['draft', 'wait', 'accept', 'modify', 'refuse', 'enabled', 'changing', 'disabled', 'changed', 'active', 'removed'].map(value => ({
    value,
    label: statusText(value),
  }));
}

function archiveStatusOptions() {
  return [
    { value: 'complete', label: '完整' },
    { value: 'incomplete', label: '待补齐' },
    { value: 'archived', label: '已归档' },
    { value: 'missing', label: '待补齐' },
    { value: 'not_required', label: '不适用' },
  ];
}

function inspectionResultOptions() {
  return [
    { value: 'pass', label: '通过' },
    { value: 'fail', label: '不通过' },
  ];
}

function inspectionResultText(value) {
  return inspectionResultOptions().find(item => item.value === value)?.label || value || '-';
}

function delayConfigOptions() {
  return [
    { value: 'journal_deadline', label: '日志截止' },
    { value: 'report_deadline', label: '报告截止' },
  ];
}

function delayConfigText(value) {
  return delayConfigOptions().find(item => item.value === value)?.label || value || '-';
}

function baseFlowTypeText(value) {
  return baseFlowTypes.find(item => item.value === value)?.label || value || '基地流程';
}

function baseFlowEntity(row = {}) {
  return {
    application: 'base_application',
    usage: 'base_usage',
    result: 'base_result',
    expense: 'base_expense',
  }[row.flow_type || row.type || internshipState.filters.baseFlows.type] || 'base_application';
}

function isBaseFlowEntity(entity) {
  return ['base_application', 'base_usage', 'base_result', 'base_expense'].includes(entity);
}

function internshipReviewPanel(entity) {
  if (entity === 'application') {
    return 'applications';
  }
  if (isBaseFlowEntity(entity)) {
    return 'baseFlows';
  }
  return `${entity}s`;
}

function baseFlowDetailText(row) {
  if (row.base_type) {
    return row.base_type === 'fixed' ? '固定基地' : '实习点';
  }
  if (row.usage_type) {
    return row.usage_type;
  }
  if (row.result_type) {
    return row.result_type;
  }
  if (row.amount !== null && row.amount !== undefined && row.amount !== '') {
    return `费用 ${row.amount}`;
  }
  return '-';
}

function markBaseFlowItems(data, flowType) {
  return {
    ...data,
    items: (data.items || []).map(item => ({
      ...item,
      flow_type: flowType,
    })),
  };
}

async function handleInternshipHashAction(listKey, action, id) {
  let row = internshipState.lists[listKey]?.items.find(item => String(item.id) === String(id));
  if (!row && internshipState.lists[listKey]) {
    await loadInternshipPanel(listKey, internshipState.lists[listKey].pagination.page || 1);
    row = internshipState.lists[listKey]?.items.find(item => String(item.id) === String(id));
  }
  if (!row) {
    internshipState.message = '当前列表数据已刷新，请重新点击操作';
    return;
  }
  if (listKey === 'applications') {
    openReviewDialog('application', row, action);
  } else if (listKey === 'plans') {
    openReviewDialog('plan', row, action);
  } else if (listKey === 'journals') {
    openReviewDialog('journal', row, action);
  } else if (listKey === 'reports') {
    openReviewDialog('report', row, action);
  } else if (listKey === 'delays') {
    openReviewDialog('delay', row, action);
  } else if (listKey === 'pairs' && action === 'score') {
    prepareScore(row);
  }
}

function defaultScopedFilters() {
  const filters = emptyInternshipFilters();
  const defaults = organizationScopeDefaults(internshipState.options);
  applyAcademicDefaults(filters, defaults);
  normalizeFilterCascade(filters, internshipState.options);
  applyAcademicDefaults(filters, defaults);
  return filters;
}

function applyDefaultScopedFilters() {
  const defaults = defaultScopedFilters();
  Object.keys(internshipState.filters).forEach((key) => {
    const current = {
      ...emptyInternshipFilters(),
      ...internshipState.filters[key],
    };
    Object.entries(defaults).forEach(([filterKey, value]) => {
      if (value !== '' && (current[filterKey] === '' || current[filterKey] === null || current[filterKey] === undefined)) {
        current[filterKey] = value;
      }
    });
    normalizeFilterCascade(current, internshipState.options);
    internshipState.filters[key] = current;
  });
}

function currentGradeId(options = internshipState.options) {
  const currentGrade = (options.grades || []).find(item => sameFilterValue(item.is_current, 'true') || sameFilterValue(item.is_current, 1));
  return currentGrade?.grade_id || options.grades?.[0]?.grade_id || '';
}

function organizationScopeDefaults(options = internshipState.options) {
  const defaults = {
    grade_id: currentGradeId(options),
    dep_id: '',
    profession_id: '',
    class_id: '',
  };
  const roleType = permissionState.context.role_type || '';
  const classIds = organizationScopeIds('class_id');
  const professionIds = organizationScopeIds('profession_id');
  const depIds = organizationScopeIds('dep_id');
  const firstScope = (permissionState.context.organization_scopes || [])
    .find(item => item?.class_id || item?.profession_id || item?.dep_id) || {};
  const selectedClass = firstScopedOption(options.classes, 'class_id', classIds);

  if (selectedClass || firstScope.class_id) {
    defaults.class_id = selectedClass?.class_id || firstScope.class_id || '';
    defaults.profession_id = selectedClass?.profession_id || firstScope.profession_id || defaults.profession_id;
    defaults.dep_id = selectedClass?.dep_id || firstScope.dep_id || defaults.dep_id;
    defaults.grade_id = defaults.grade_id || selectedClass?.grade_id || '';
  }

  const selectedProfession = firstScopedOption(
    options.professions,
    'profession_id',
    [defaults.profession_id, ...professionIds].filter(Boolean),
  );
  if (selectedProfession || firstScope.profession_id) {
    defaults.profession_id = selectedProfession?.profession_id || firstScope.profession_id || '';
    defaults.dep_id = selectedProfession?.dep_id || firstScope.dep_id || defaults.dep_id;
    defaults.grade_id = defaults.grade_id || selectedProfession?.grade_id || '';
  }

  const selectedDepartment = firstScopedOption(
    options.departments,
    'dep_id',
    [defaults.dep_id, ...depIds, firstScope.dep_id].filter(Boolean),
  );
  if (selectedDepartment || firstScope.dep_id) {
    defaults.dep_id = selectedDepartment?.dep_id || firstScope.dep_id || defaults.dep_id;
  }

  if (roleType === 'college_admin' && !defaults.dep_id) {
    defaults.dep_id = firstNonEmpty(depIds) || (options.departments?.length === 1 ? options.departments[0]?.dep_id : '') || '';
  }

  if (roleType === 'profession_admin') {
    const selectedProfession = defaults.profession_id
      ? (options.professions || []).find(item => sameFilterValue(item.profession_id, defaults.profession_id))
      : null;
    const resolvedProfession = selectedProfession || (firstScope.profession_id
      ? (options.professions || []).find(item => sameFilterValue(item.profession_id, firstScope.profession_id))
      : null) || firstScopedOption(options.professions, 'profession_id', professionIds)
      || (options.professions?.length === 1 ? options.professions[0] : null);
    if (resolvedProfession) {
      defaults.profession_id = resolvedProfession.profession_id || defaults.profession_id;
      defaults.dep_id = resolvedProfession.dep_id || defaults.dep_id;
      defaults.grade_id = defaults.grade_id || resolvedProfession.grade_id || '';
    }
  }

  return defaults;
}

function organizationScopeIds(field) {
  const ids = [];
  (permissionState.context.organization_scopes || []).forEach((scope) => {
    if (hasFilterValue(scope?.[field])) {
      ids.push(scope[field]);
    }
  });

  const scopeFilter = permissionState.context.data_scope?.filter || permissionState.dataScope?.filter || {};
  const value = scopeFilter[field];
  (Array.isArray(value) ? value : [value]).forEach((item) => {
    if (hasFilterValue(item)) {
      ids.push(item);
    }
  });

  return Array.from(new Set(ids.map(item => String(item))));
}

function firstScopedOption(items = [], key, ids = []) {
  const values = (ids || []).filter(hasFilterValue).map(item => String(item));
  if (!values.length) {
    return null;
  }
  return (items || []).find(item => values.includes(String(item?.[key] ?? ''))) || null;
}

function firstNonEmpty(items = []) {
  return (items || []).find(hasFilterValue) || '';
}

function applyAcademicDefaults(target, defaults) {
  Object.entries(defaults).forEach(([key, value]) => {
    if (value !== '' && (target[key] === '' || target[key] === null || target[key] === undefined)) {
      target[key] = value;
    }
  });
}

function internshipQueryParams(key, page) {
  const filters = internshipState.filters[key] || {};
  const params = {
    page,
    page_size: internshipState.lists[key]?.pagination.page_size || 20,
  };
  Object.entries(filters).forEach(([filterKey, value]) => {
    if (value !== '' && value !== null && value !== undefined) {
      params[filterKey] = value;
    }
  });
  return params;
}

function setInternshipFilter(listKey, event) {
  const values = internshipState.filters[listKey];
  values[event.key] = event.value ?? '';
  normalizeFilterCascade(values, internshipState.options, event.key);
}

function resetInternshipFilters(listKey) {
  internshipState.filters[listKey] = defaultScopedFilters();
  normalizeFilterCascade(internshipState.filters[listKey], internshipState.options);
  loadInternshipPanel(listKey, 1);
}

async function loadArrangementDetail(id) {
  if (!id) {
    return emptyArrangementDetail();
  }
  return fetchInternshipArrangementDetail({ id });
}

function fillArrangementFormFromDetail(detail) {
  const item = detail.item || {};
  internshipState.arrangementForm = {
    ...emptyArrangementForm(),
    id: item.id || null,
    uuid: item.uuid || '',
    plan_id: item.plan_id || null,
    title: item.title || item.name || '',
    task_no: item.task_no || '',
    batch_no: item.batch_no || '',
    semester: item.semester || '',
    grade_id: item.grade_id || null,
    base_id: item.base_id || null,
    dep_id: item.dep_id || null,
    profession_id: item.profession_id || null,
    teacher_id: item.teacher_id || null,
    class_ids: (detail.classes || []).map(row => row.class_id).filter(Boolean),
    credit: item.credit ?? '',
    type: item.type || 'major_external',
    organize_mode: item.organize_mode || 'centralized',
    start_date: item.start_date || '',
    end_date: item.end_date || '',
    location: item.location || '',
    description: item.description || '',
    change_reason: '',
    status: item.status || 'enabled',
  };
  normalizeArrangementCascade();
}

function fillArrangementFormFromChange(row) {
  const payload = row?.payload && typeof row.payload === 'object' ? row.payload : {};
  internshipState.arrangementForm = {
    ...emptyArrangementForm(),
    id: row.arrangement_id || null,
    change_id: row.id || null,
    plan_id: payload.plan_id || null,
    title: payload.title || payload.name || row.arrangement_title || '',
    task_no: payload.task_no || row.task_no || '',
    batch_no: payload.batch_no || row.batch_no || '',
    grade_id: row.grade_id || null,
    base_id: payload.base_id || null,
    dep_id: row.dep_id || null,
    profession_id: row.profession_id || null,
    teacher_id: payload.teacher_id || row.teacher_id || null,
    class_ids: Array.isArray(payload.class_ids) ? payload.class_ids : [],
    credit: payload.credit ?? '',
    type: payload.type || 'major_external',
    organize_mode: payload.organize_mode || 'centralized',
    start_date: payload.start_date || '',
    end_date: payload.end_date || '',
    location: payload.location || '',
    description: payload.description || '',
    change_reason: row.reason || '',
    status: 'enabled',
  };
  normalizeArrangementCascade();
}

function reopenArrangementChangeDialog(row) {
  if (!row || !['draft', 'modify'].includes(row.status)) {
    internshipState.message = '仅草稿或退回的任务变更可重新提交';
    return;
  }
  fillArrangementFormFromChange(row);
  internshipState.dialog = {
    ...emptyOperationDialog(),
    type: 'arrangement',
    title: '重新提交任务变更申请',
  };
}

async function openArrangementDialog(row = null) {
  if (row?.id) {
    internshipState.loading = true;
    internshipState.message = '';
    try {
      const detail = await loadArrangementDetail(row.id);
      fillArrangementFormFromDetail(detail);
      internshipState.dialog = {
        ...emptyOperationDialog(),
        type: 'arrangement',
        title: '提交任务变更申请',
      };
    } catch (error) {
      internshipState.message = error.message;
    } finally {
      internshipState.loading = false;
    }
    return;
  }

  const defaults = defaultScopedFilters();
  const defaultPlan = internshipState.options.plans.find(item => matchAcademicFilters(item, defaults, ['grade_id', 'dep_id', 'profession_id'])) || internshipState.options.plans[0] || null;
  internshipState.arrangementForm = {
    ...emptyArrangementForm(),
    plan_id: defaultPlan?.id || null,
    base_id: internshipState.options.bases[0]?.id || null,
    grade_id: defaultPlan?.grade_id || defaults.grade_id || firstArrangementGrade()?.grade_id || null,
    dep_id: defaultPlan?.dep_id || defaults.dep_id || null,
    profession_id: defaultPlan?.profession_id || defaults.profession_id || null,
    credit: defaultPlan?.credit ?? '',
  };
  normalizeArrangementCascade();
  internshipState.dialog = {
    ...emptyOperationDialog(),
    type: 'arrangement',
    title: '新增实习任务',
  };
}

async function openArrangementDetail(row) {
  const id = typeof row === 'number' ? row : Number(row?.id || 0);
  if (!id) {
    return;
  }
  internshipState.loading = true;
  internshipState.message = '';
  try {
    internshipState.arrangementDetail = {
      ...emptyArrangementDetail(),
      ...(await loadArrangementDetail(id)),
    };
    internshipState.dialog = {
      ...emptyOperationDialog(),
      type: 'arrangementDetail',
      title: '实习任务详情',
    };
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

function openPlanDialog(row = null) {
  if (row) {
    const content = typeof row.plan_content === 'object' && row.plan_content !== null ? row.plan_content : {};
    internshipState.planForm = {
      ...emptyPlanForm(),
      id: row.id || null,
      source_type: row.source_type || 'edu_system',
      course_code: row.course_code || '',
      course_name: row.course_name || '',
      grade_id: row.grade_id || null,
      semester: row.semester || '',
      dep_id: row.dep_id || null,
      profession_id: row.profession_id || null,
      credit: row.credit ?? '',
      score_rule: row.score_rule || 'average',
      content: content.content || content.summary || '',
      status: row.status || 'draft',
    };
  } else {
    const defaults = defaultScopedFilters();
    const defaultProfession = internshipState.options.professions.find(item => Number(item.profession_id) === Number(defaults.profession_id || 0)) || null;
    internshipState.planForm = {
      ...emptyPlanForm(),
      grade_id: defaultProfession?.grade_id || defaults.grade_id || currentGradeId(internshipState.options) || null,
      dep_id: defaultProfession?.dep_id || defaults.dep_id || internshipState.options.departments[0]?.dep_id || null,
      profession_id: defaultProfession?.profession_id || defaults.profession_id || null,
    };
  }
  normalizePlanCascade();
  internshipState.dialog = {
    ...emptyOperationDialog(),
    type: 'plan',
    title: row ? '编辑实习计划' : '新增实习计划',
    row,
  };
}

function openScoreDialog(row = null) {
  internshipState.scoreForm = emptyScoreForm();
  if (row) {
    internshipState.scoreForm.pair_id = row.pair_id || row.id;
    internshipState.scoreForm.student_id = row.student_id;
    internshipState.scoreForm.arrangement_id = row.arrangement_id;
    fillScoreFormFromExisting(row.student_id, row.arrangement_id, row);
  }
  if (!internshipState.lists.pairs.items.length) {
    searchScorePairs();
  }
  internshipState.dialog = {
    ...emptyOperationDialog(),
    type: 'score',
    title: '录入实习成绩',
  };
}

function openCourseScoreDialog(row) {
  if (!canSaveManualCourseScore(row)) {
    internshipState.message = '仅人工核定规则的课程成绩可维护';
    return;
  }
  internshipState.courseScoreForm = {
    ...emptyCourseScoreForm(),
    plan_id: row.plan_id,
    student_id: row.student_id,
    score_value: row.manual_score ?? row.course_final_score ?? '',
    remark: row.manual_score_remark || '',
  };
  internshipState.dialog = {
    ...emptyOperationDialog(),
    type: 'courseScore',
    title: '核定课程成绩',
    description: `${row.student_name || '-'} / ${row.course_name || row.course_code || '-'}`,
    row,
  };
}

function openBaseFlowDialog(row = null) {
  const form = emptyBaseFlowForm(row || {});
  if (!row) {
    form.base_id = internshipState.options.bases[0]?.id || null;
    form.dep_id = defaultScopedFilters().dep_id || internshipState.options.departments[0]?.dep_id || null;
    form.type = internshipState.filters.baseFlows.type || 'application';
  }
  internshipState.baseFlowForm = form;
  internshipState.dialog = {
    ...emptyOperationDialog(),
    type: 'baseFlow',
    title: `${row ? '编辑' : '新增'}${baseFlowTypeText(form.type)}`,
    row,
  };
}

async function openReviewDialog(entity, row, status) {
  if (!canReviewRow(row, entity)) {
    internshipState.message = '仅待审核数据可处理';
    return;
  }
  const actionText = status === 'accept' ? '通过' : '退回';
  const target = row.title || row.course_name || row.arrangement_title || row.student_name || row.id;
  const nodeText = entity === 'plan' && row.current_approval_name ? `当前节点：${row.current_approval_name}。` : '';
  internshipState.dialog = {
    ...emptyOperationDialog(),
    type: 'review',
    title: `${actionText}${reviewEntityName(entity)}`,
    description: `请确认是否${actionText}「${target}」。${nodeText}`,
    entity,
    status,
    row,
    reason: status === 'accept' ? '同意' : '',
  };
  await loadInternshipDialogReviewDraft();
}

function openReopenDialog(entity, row) {
  if (!canRequestModification(row, entity)) {
    internshipState.message = '仅已通过数据可发起通过后修改';
    return;
  }
  internshipState.dialog = {
    ...emptyOperationDialog(),
    type: 'reopen',
    title: `通过后修改${reviewEntityName(entity)}`,
    description: `此操作会新增审核记录，并将「${row.title || row.arrangement_title || row.student_name || row.id}」改为待提交人重新修改的状态。`,
    entity,
    status: 'modify',
    row,
    reason: '',
  };
}

async function openTimelineDialog(entity, row) {
  internshipState.dialog = {
    ...emptyOperationDialog(),
    type: 'timeline',
    title: `${reviewEntityName(entity)}流程记录`,
    description: row.title || row.arrangement_title || row.student_name || String(row.id),
    entity,
    row,
    timeline: [],
    cycles: [],
  };
  internshipState.loading = true;
  internshipState.message = '';
  try {
    const data = await fetchInternshipTimeline({ entity, id: row.id });
    internshipState.dialog.cycles = data.cycles || [];
    internshipState.dialog.timeline = data.items || [];
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

async function loadInternshipDialogReviewDraft() {
  const { entity, row } = internshipState.dialog;
  if (!entity || !row?.id) {
    return;
  }
  try {
    const data = await fetchInternshipReviewDraft({ entity, id: row.id });
    const draft = data.draft || null;
    if (draft) {
      internshipState.dialog.status = draft.review_status || internshipState.dialog.status;
      internshipState.dialog.reason = draft.opinion || '';
    }
  } catch (error) {
    internshipState.message = error.message;
  }
}

async function saveInternshipDialogReviewDraft() {
  if (internshipState.loading) {
    return;
  }
  const { entity, status, row, reason } = internshipState.dialog;
  if (!entity || !row?.id) {
    return;
  }
  internshipState.loading = true;
  internshipState.message = '';
  try {
    await saveInternshipReviewDraft({
      entity,
      id: row.id,
      status,
      opinion: reason,
    });
    internshipState.message = '审核草稿已保存';
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

function closeInternshipDialog() {
  internshipState.dialog = emptyOperationDialog();
  internshipState.arrangementDetail = emptyArrangementDetail();
  internshipState.courseScoreForm = emptyCourseScoreForm();
}

async function confirmInternshipDialog() {
  if (internshipState.loading) {
    return;
  }
  if (internshipState.dialog.type === 'arrangement') {
    await saveArrangement();
    return;
  }
  if (internshipState.dialog.type === 'plan') {
    await savePlan();
    return;
  }
  if (internshipState.dialog.type === 'score') {
    await saveScore();
    return;
  }
  if (internshipState.dialog.type === 'courseScore') {
    await saveCourseScore();
    return;
  }
  if (internshipState.dialog.type === 'baseFlow') {
    await saveBaseFlow();
    return;
  }
  if (internshipState.dialog.type === 'review') {
    const error = validateReviewReason(internshipState.dialog.entity, internshipState.dialog.status, internshipState.dialog.reason);
    if (error) {
      internshipState.message = error;
      return;
    }
    if (internshipState.dialog.entity === 'application') {
      await reviewApplication(internshipState.dialog.row, internshipState.dialog.status, internshipState.dialog.reason);
      return;
    }
    if (internshipState.dialog.entity === 'arrangement_change') {
      await reviewArrangementChange(internshipState.dialog.row, internshipState.dialog.status, internshipState.dialog.reason);
      return;
    }
    if (internshipState.dialog.entity === 'plan') {
      await reviewPlan(internshipState.dialog.row, internshipState.dialog.status, internshipState.dialog.reason);
      return;
    }
    if (internshipState.dialog.entity === 'delay') {
      await reviewDelay(internshipState.dialog.row, internshipState.dialog.status, internshipState.dialog.reason);
      return;
    }
    if (isBaseFlowEntity(internshipState.dialog.entity)) {
      await reviewBaseFlow(internshipState.dialog.row, internshipState.dialog.status, internshipState.dialog.reason);
      return;
    }
    await reviewStudentWork(internshipState.dialog.entity, internshipState.dialog.row, internshipState.dialog.status, internshipState.dialog.reason);
  }
  if (internshipState.dialog.type === 'reopen') {
    const error = validateReviewReason(internshipState.dialog.entity, 'modify', internshipState.dialog.reason, '修改理由');
    if (error) {
      internshipState.message = error;
      return;
    }
    await requestModification();
  }
}

async function submitInternshipPlan(status) {
  if (internshipState.loading) {
    return;
  }
  internshipState.planForm.status = status;
  await savePlan();
}

function reviewRule(entity, status) {
  return internshipState.options.review_rules?.[entity]?.[status]
    || defaultInternshipReviewRules[entity]?.[status]
    || { min: 0, max: null };
}

function reviewRuleText(entity, status, label = null) {
  const rule = reviewRule(entity, status);
  const fieldLabel = label || (isRejectReviewStatus(status) ? '退回原因' : '审核意见');
  if (!rule.min && !rule.max) {
    return `${fieldLabel}字数不限制`;
  }
  if (rule.min && rule.max) {
    return `${fieldLabel}需 ${rule.min}-${rule.max} 字`;
  }
  if (rule.min) {
    return `${fieldLabel}至少 ${rule.min} 字`;
  }
  return `${fieldLabel}最多 ${rule.max} 字`;
}

function reviewRuleMax(entity, status) {
  const max = reviewRule(entity, status).max;
  return max || null;
}

function reviewRuleMaxText(entity, status) {
  return reviewRuleMax(entity, status) || '不限';
}

function reviewEntityName(entity) {
  const names = {
    application: '特殊申请',
    sign_in: '实习签到',
    journal: '实习日志',
    report: '实习报告',
    score: '实习成绩',
    plan: '实习计划',
    arrangement: '实习任务',
    arrangement_change: '任务变更',
    delay: '延期申请',
    insurance: '保险记录',
    safety_letter: '安全承诺',
    syllabus_guide: '大纲指导书',
    implementation_sheet: '实施表',
    teacher_work_report: '教师工作报告',
    inspection: '巡查记录',
    base_application: '基地申报',
    base_usage: '基地使用',
    base_result: '基地成果',
    base_expense: '基地费用',
  };
  return names[entity] || '审核事项';
}

function isRejectReviewStatus(status) {
  return ['modify', 'refuse'].includes(status);
}

function workflowActionText(action) {
  const names = {
    submit: '提交',
    review: '审核',
    teacher_review: '教师审核',
    admin_review: '管理员审核',
    modify_after_accept: '通过后修改',
  };
  return names[action] || action || '记录';
}

function normalizeTimelineCycles(cycles, items) {
  if (Array.isArray(cycles) && cycles.length) {
    return cycles;
  }

  if (!Array.isArray(items) || !items.length) {
    return [];
  }

  const normalized = [];
  let current = null;
  let sequence = 0;

  items.forEach((item) => {
    if (item.record && item.record.action === 'submit') {
      sequence += 1;
      current = {
        kind: 'cycle',
        sequence,
        created_at: item.created_at,
        record: item.record,
        branches: [],
      };
      normalized.push(current);
      return;
    }

    if (!current) {
      sequence += 1;
      current = {
        kind: 'cycle',
        sequence,
        created_at: item.created_at,
        record: null,
        branches: [],
      };
      normalized.push(current);
    }

    current.branches.push({
      kind: 'branch',
      type: item.kind || 'recording',
      created_at: item.created_at,
      record: item.record || null,
      review: item.review || null,
      reviews: item.reviews || (item.review ? [item.review] : []),
    });
  });

  return normalized;
}

function timelineCycleKey(cycle, index) {
  return `cycle-${cycle.record?.id || cycle.sequence || index}`;
}

function timelineCycleTitle(cycle) {
  const prefix = cycle.sequence ? `第 ${cycle.sequence} 次提交` : '提交记录';
  if (cycle.record) {
    return `${prefix}${timelineOperatorText(cycle.record)}：${statusText(cycle.record.from_status)} -> ${statusText(cycle.record.to_status)}`;
  }
  return cycle.sequence ? `第 ${cycle.sequence} 次流程记录` : '流程记录';
}

function timelineCycleTime(cycle) {
  return cycle.created_at || cycle.record?.created_at || '-';
}

function timelineCycleContent(cycle) {
  if (!cycle.record) {
    return '无提交内容';
  }
  return timelineContent({ record: cycle.record });
}

function timelineBranchKey(branch, index) {
  return `branch-${branch.record?.id || branch.review?.id || index}`;
}

function timelineBranchTitle(branch) {
  if (branch.record) {
    return `${workflowActionText(branch.record.action)}${timelineOperatorText(branch.record)}：${statusText(branch.record.from_status)} -> ${statusText(branch.record.to_status)}`;
  }
  const review = branch.review || branch.reviews?.[0];
  return `审核${timelineReviewerText(review)}：${statusText(review?.status)}`;
}

function timelineBranchTime(branch) {
  return branch.created_at || branch.record?.created_at || branch.review?.created_at || '-';
}

function timelineBranchReviews(branch) {
  if (Array.isArray(branch.reviews) && branch.reviews.length) {
    return branch.reviews;
  }
  return branch.review ? [branch.review] : [];
}

function timelineBranchContent(branch) {
  if (timelineBranchReviews(branch).length) {
    return '';
  }
  if (branch.record) {
    return branch.record.content || branch.record.opinion || '';
  }
  return branch.review?.opinion || '';
}

function timelineOperatorText(record) {
  const name = record?.operator_name || record?.operator_login_name;
  return name ? `（${name}）` : '';
}

function timelineReviewerText(review) {
  const name = review?.reviewer_name || review?.teacher_name || review?.reviewer_login_name || review?.teacher_num;
  return name ? `（${name}）` : '';
}

function isModifyAfterAcceptBranch(branch) {
  return branch.record?.action === 'modify_after_accept' || branch.review?.status === 'modify';
}

function timelineContent(item) {
  if (item.record) {
    if (item.record.action === 'submit' && isGenericSubmitContent(item.record.content)) {
      return submissionSnapshotText(internshipState.dialog.entity, internshipState.dialog.row);
    }
    return item.record.content || item.record.opinion || '-';
  }
  return item.review?.opinion || '-';
}

function isGenericSubmitContent(value) {
  return [
    '提交特殊申请',
    '提交补充申请',
    '提交实习签到',
    '提交实习日志',
    '提交实习报告',
    '提交延期申请',
    '提交实习计划',
  ].includes(String(value || '').trim());
}

function submissionSnapshotText(entity, row) {
  if (!row) {
    return '-';
  }
  const textMap = {
    application: row.remark || row.arrangement_title,
    sign_in: [row.date, row.sign_time, row.location].filter(Boolean).join(' / '),
    journal: [row.title, row.content].filter(Boolean).join('：'),
    report: [row.title, row.content].filter(Boolean).join('：'),
    plan: planContentText(row.plan_content),
    delay: row.reason,
  };
  const text = String(textMap[entity] || '').replace(/\s+/g, ' ').trim();
  return text ? (text.length > 180 ? `${text.slice(0, 180)}...` : text) : '-';
}

function canReviewRow(row, entity) {
  if (!row || row.status !== 'wait') {
    return false;
  }
  if (entity === 'plan') {
    return canManageInternshipPlan.value && canReviewPlanLevel(row);
  }
  if (entity === 'arrangement_change') {
    return canManageInternship.value || canApproveInternship.value;
  }
  if (entity === 'application') {
    if (!canApproveInternship.value) {
      return false;
    }
    if (isTeacherRole.value) {
      return ['pending', 'wait'].includes(row.teacher_status);
    }
    if (isAdminRole.value) {
      return ['pending', 'wait'].includes(row.admin_status);
    }
    return false;
  }
  return canApproveInternship.value;
}

function canEditInternshipPlan(row) {
  return canManageInternshipPlan.value && ['draft', 'modify'].includes(String(row?.status || ''));
}

function canReviewPlanLevel(row) {
  if (currentRoleType.value === 'super_admin') {
    return true;
  }
  const roles = Array.isArray(row.next_approval_role_types) ? row.next_approval_role_types : [];
  return roles.includes(currentRoleType.value);
}

function canRequestModification(row, entity) {
  if (!row || row.status !== 'accept') {
    return false;
  }
  if (!['application', 'journal', 'report', 'plan', 'delay', 'base_application', 'base_usage', 'base_result', 'base_expense'].includes(entity)) {
    return false;
  }
  if (entity === 'plan') {
    return canManageInternshipPlan.value;
  }
  return canApproveInternship.value;
}

function textLength(value) {
  return Array.from(String(value || '').trim()).length;
}

function trimReviewReasonMax(event) {
  const max = reviewRuleMax(internshipState.dialog.entity, internshipState.dialog.status);
  if (!max) {
    return;
  }
  const chars = Array.from(String(event.target.value || ''));
  if (chars.length <= max) {
    return;
  }
  const value = chars.slice(0, max).join('');
  event.target.value = value;
  internshipState.dialog.reason = value;
}

function validateReviewReason(entity, status, reason, label = null) {
  const rule = reviewRule(entity, status);
  const text = String(reason || '').trim();
  const length = textLength(text);
  const fieldLabel = label || (isRejectReviewStatus(status) ? '退回原因' : '审核意见');
  if (rule.min && length < rule.min) {
    return `${fieldLabel}至少 ${rule.min} 字`;
  }
  if (rule.max && length > rule.max) {
    return `${fieldLabel}最多 ${rule.max} 字`;
  }
  return '';
}

async function loadInternshipFoundation() {
  if (!hasPermission('internship:view')) {
    return;
  }

  const [overview, options] = await Promise.all([
    fetchInternshipOverview(),
    fetchInternshipOptions(),
  ]);
  internshipState.overview = {
    ...emptyInternshipOverview(),
    ...(overview || {}),
  };
  internshipState.options = {
    ...emptyInternshipOptions(),
    ...(options || {}),
  };
  if (!internshipState.arrangementForm.base_id && internshipState.options.bases.length) {
    internshipState.arrangementForm.base_id = internshipState.options.bases[0].id;
  }
  normalizeArrangementCascade();
  applyDefaultScopedFilters();
}

async function loadInternshipPanel(panel = 'overview', page = 1) {
  if (!hasPermission('internship:view')) {
    return;
  }

  internshipState.loading = true;
  internshipState.message = '';
  try {
    await loadInternshipFoundation();
    const params = (key) => internshipQueryParams(key, page);
    if (panel === 'overview') {
      const applicationParams = isStudentRole.value
        ? { ...internshipQueryParams('applications', 1), page_size: 8 }
        : { ...internshipQueryParams('applications', 1), page_size: 8, status: 'wait' };
      const [arrangements, applications] = await Promise.all([
        fetchInternshipArrangements({ ...internshipQueryParams('arrangements', 1), page_size: 8 }),
        fetchInternshipApplications(applicationParams),
      ]);
      setPagedList('arrangements', arrangements);
      setPagedList('applications', applications);
    } else if (panel === 'arrangements') {
      setPagedList('arrangements', await fetchInternshipArrangements(params('arrangements')));
    } else if (panel === 'arrangementChanges') {
      setPagedList('arrangementChanges', await fetchInternshipArrangementChanges(params('arrangementChanges')));
    } else if (panel === 'baseFlows') {
      const flowType = internshipState.filters.baseFlows.type || 'application';
      const data = await fetchInternshipBaseFlows({
        ...params('baseFlows'),
        type: flowType,
      });
      setPagedList('baseFlows', markBaseFlowItems(data, flowType));
    } else if (panel === 'plans') {
      setPagedList('plans', await fetchInternshipPlans(params('plans')));
    } else if (panel === 'syllabusGuides') {
      setPagedList('syllabusGuides', await fetchInternshipSyllabusGuides(params('syllabusGuides')));
    } else if (panel === 'implementationSheets') {
      setPagedList('implementationSheets', await fetchInternshipImplementationSheets(params('implementationSheets')));
    } else if (panel === 'applications') {
      setPagedList('applications', await fetchInternshipApplications(params('applications')));
    } else if (panel === 'pairs') {
      setPagedList('pairs', await fetchInternshipPairs(params('pairs')));
    } else if (panel === 'signIns') {
      setPagedList('signIns', await fetchInternshipSignIns(params('signIns')));
    } else if (panel === 'journals') {
      setPagedList('journals', await fetchInternshipJournals(params('journals')));
    } else if (panel === 'reports') {
      setPagedList('reports', await fetchInternshipReports(params('reports')));
    } else if (panel === 'teacherWorkReports') {
      setPagedList('teacherWorkReports', await fetchInternshipTeacherWorkReports(params('teacherWorkReports')));
    } else if (panel === 'delays') {
      setPagedList('delays', await fetchInternshipDelays(params('delays')));
    } else if (panel === 'scores') {
      const [scores, pairs, courseScores] = await Promise.all([
        fetchInternshipScores(params('scores')),
        fetchInternshipPairs({ page: 1, page_size: 50, keyword: internshipState.filters.pairs.keyword || '' }),
        fetchInternshipCourseScores(params('courseScores')),
      ]);
      setPagedList('scores', scores);
      setPagedList('pairs', pairs);
      setPagedList('courseScores', courseScores);
    } else if (panel === 'courseScores') {
      setPagedList('courseScores', await fetchInternshipCourseScores(params('courseScores')));
    } else if (panel === 'documents') {
      setPagedList('archiveMaterials', await fetchInternshipArchiveMaterials(params('archiveMaterials')));
    } else if (panel === 'inspections') {
      setPagedList('inspections', await fetchInternshipInspections(params('inspections')));
    } else if (panel === 'insurances') {
      setPagedList('insurances', await fetchInternshipInsurances(params('insurances')));
    } else if (panel === 'safetyLetters') {
      setPagedList('safetyLetters', await fetchInternshipSafetyLetters(params('safetyLetters')));
    }
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

async function submitBaseFlow(status) {
  if (internshipState.loading) {
    return;
  }
  internshipState.baseFlowForm.status = status;
  await saveBaseFlow();
}

async function saveBaseFlow() {
  if (!canManageInternship.value) {
    return;
  }
  if (!internshipState.baseFlowForm.base_id) {
    internshipState.message = '请选择实习基地';
    return;
  }
  internshipState.loading = true;
  internshipState.message = '';
  try {
    await saveInternshipBaseFlow({
      ...internshipState.baseFlowForm,
      status: ['draft', 'wait'].includes(internshipState.baseFlowForm.status) ? internshipState.baseFlowForm.status : 'draft',
      amount: numericOrNull(internshipState.baseFlowForm.amount),
    });
    internshipState.filters.baseFlows.type = internshipState.baseFlowForm.type;
    internshipState.baseFlowForm = emptyBaseFlowForm();
    closeInternshipDialog();
    await loadInternshipPanel('baseFlows');
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

async function saveArrangement() {
  if (!canManageInternship.value) {
    return;
  }
  const form = internshipState.arrangementForm;
  if (!internshipState.arrangementForm.plan_id) {
    internshipState.message = '请选择实习计划';
    return;
  }
  if (!String(internshipState.arrangementForm.task_no || '').trim()) {
    internshipState.message = '请填写任务编号';
    return;
  }
  if (!internshipState.arrangementForm.teacher_id) {
    internshipState.message = '请选择负责老师';
    return;
  }
  if (!internshipState.arrangementForm.class_ids.length) {
    internshipState.message = '请选择任务班级';
    return;
  }
  if (form.id && !String(form.change_reason || '').trim()) {
    internshipState.message = '请填写任务变更申请原因';
    return;
  }

  internshipState.loading = true;
  internshipState.message = '';
  internshipState.savedMessage = '';
  try {
    const payload = {
      ...form,
      name: form.title,
      base_id: form.base_id || null,
      dep_id: form.dep_id || null,
      profession_id: form.profession_id || null,
      teacher_id: form.teacher_id || null,
      class_ids: form.class_ids,
    };
    if (form.id) {
      await saveInternshipArrangementChange({
        ...payload,
        arrangement_id: form.id,
        change_id: form.change_id || undefined,
        id: undefined,
        uuid: undefined,
        reason: form.change_reason,
        status: 'wait',
      });
      internshipState.savedMessage = '变更申请已提交，审核通过后生效';
    } else {
      await saveInternshipArrangement(payload);
      internshipState.savedMessage = '已保存';
    }
    internshipState.arrangementForm = {
      ...emptyArrangementForm(),
      plan_id: form.plan_id,
      base_id: form.base_id,
      grade_id: form.grade_id,
      dep_id: form.dep_id,
      profession_id: form.profession_id,
      teacher_id: form.teacher_id,
      credit: form.credit,
    };
    closeInternshipDialog();
    await Promise.all([
      loadInternshipPanel('arrangements'),
      loadInternshipPanel('arrangementChanges'),
      loadInternshipPanel('pairs'),
      loadInternshipOptions(),
    ]);
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

async function reviewArrangementChange(row, status, opinion = '') {
  if (internshipState.loading) {
    return;
  }
  internshipState.loading = true;
  internshipState.message = '';
  try {
    await reviewInternshipArrangementChange({
      id: row.id,
      status,
      opinion: opinion || (status === 'accept' ? '同意任务变更' : '任务变更退回，请调整后重新提交'),
    });
    closeInternshipDialog();
    await Promise.all([
      loadInternshipPanel('arrangementChanges'),
      loadInternshipPanel('arrangements'),
      loadInternshipPanel('pairs'),
      loadInternshipOptions(),
    ]);
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

function chooseArrangementImportExcel() {
  if (!canManageInternship.value) {
    return;
  }
  if (arrangementImportInputRef.value) {
    arrangementImportInputRef.value.value = '';
    arrangementImportInputRef.value.click();
  }
}

async function handleArrangementImportFile(event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file || !canManageInternship.value) {
    return;
  }

  internshipState.importing = true;
  internshipState.loading = true;
  internshipState.message = '';
  try {
    const result = await importInternshipArrangementAssignments(file);
    const errors = (result.errors || []).map(item => `第${item.row}行：${item.message}`).join('；');
    const changeText = result.change_submitted ? `，提交变更 ${result.change_submitted}` : '';
    internshipState.savedMessage = `导入完成：新增 ${result.created || 0}，更新 ${result.updated || 0}${changeText}，失败 ${result.failed || 0}`;
    internshipState.message = errors ? `${internshipState.savedMessage}。${errors}` : '';
    await Promise.all([
      loadInternshipPanel('arrangements'),
      loadInternshipPanel('pairs'),
      loadInternshipOptions(),
    ]);
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.importing = false;
    internshipState.loading = false;
  }
}

async function reviewApplication(row, status, opinion = '') {
  if (internshipState.loading) {
    return;
  }
  internshipState.loading = true;
  internshipState.message = '';
  try {
    await reviewInternshipApplication({
      id: row.id,
      status,
      opinion: opinion || internshipState.reviewOpinion || (status === 'accept' ? '同意' : '请修改后重新提交'),
    });
    closeInternshipDialog();
    await Promise.all([
      loadInternshipPanel('applications'),
      loadInternshipPanel('pairs'),
    ]);
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

async function savePlan() {
  if (internshipState.loading) {
    return;
  }
  if (!canManageInternshipPlan.value) {
    return;
  }
  if (!internshipState.planForm.course_name) {
    internshipState.message = '请填写课程名称';
    return;
  }
  if (!internshipState.planForm.grade_id) {
    internshipState.message = '请选择届次';
    return;
  }
  if (!internshipState.planForm.dep_id) {
    internshipState.message = '请选择学院';
    return;
  }
  if (!internshipState.planForm.profession_id) {
    internshipState.message = '请选择专业';
    return;
  }

  internshipState.loading = true;
  internshipState.message = '';
  try {
    await saveInternshipPlan({
      id: internshipState.planForm.id || undefined,
      source_type: internshipState.planForm.source_type,
      course_code: internshipState.planForm.course_code,
      course_name: internshipState.planForm.course_name,
      grade_id: internshipState.planForm.grade_id,
      dep_id: internshipState.planForm.dep_id,
      profession_id: internshipState.planForm.profession_id,
      semester: '',
      credit: internshipState.planForm.credit || null,
      score_rule: internshipState.planForm.score_rule,
      plan_content: {
        content: internshipState.planForm.content,
      },
      status: internshipState.planForm.status,
    });
    internshipState.planForm = emptyPlanForm();
    closeInternshipDialog();
    await Promise.all([
      loadInternshipPanel('plans'),
      loadInternshipOptions(),
    ]);
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

async function reviewPlan(row, status, opinion = '') {
  if (internshipState.loading) {
    return;
  }
  internshipState.loading = true;
  internshipState.message = '';
  try {
    await reviewInternshipPlan({
      id: row.id,
      approval_level: row.next_approval_level || undefined,
      status,
      opinion: opinion || (status === 'accept' ? '同意' : '请修改后重新提交'),
    });
    closeInternshipDialog();
    await loadInternshipPanel('plans');
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

async function reviewDelay(row, status, opinion = '') {
  if (internshipState.loading) {
    return;
  }
  internshipState.loading = true;
  internshipState.message = '';
  try {
    await reviewInternshipDelay({
      id: row.id,
      status,
      opinion: opinion || (status === 'accept' ? '同意延期' : '不同意延期'),
    });
    closeInternshipDialog();
    await loadInternshipPanel('delays');
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

async function reviewBaseFlow(row, status, opinion = '') {
  if (internshipState.loading) {
    return;
  }
  const entity = baseFlowEntity(row);
  internshipState.loading = true;
  internshipState.message = '';
  try {
    await reviewInternshipBaseFlow({
      entity,
      type: row.flow_type || internshipState.filters.baseFlows.type || 'application',
      id: row.id,
      status,
      opinion: opinion || (status === 'accept' ? '同意' : '请修改后重新提交'),
    });
    closeInternshipDialog();
    await loadInternshipPanel('baseFlows');
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

async function reviewStudentWork(type, row, status, opinion = '') {
  if (internshipState.loading) {
    return;
  }
  internshipState.loading = true;
  internshipState.message = '';
  try {
    const payload = {
      id: row.id,
      status,
      opinion: opinion || internshipState.reviewOpinion || (status === 'accept' ? '通过' : '请修改'),
    };
    if (type === 'journal') {
      await reviewInternshipJournal(payload);
      await loadInternshipPanel('journals');
    } else {
      await reviewInternshipReport(payload);
      await loadInternshipPanel('reports');
    }
    closeInternshipDialog();
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

async function requestModification() {
  if (internshipState.loading) {
    return;
  }
  internshipState.loading = true;
  internshipState.message = '';
  try {
    const entity = internshipState.dialog.entity;
    await requestInternshipModification({
      entity,
      id: internshipState.dialog.row.id,
      opinion: internshipState.dialog.reason,
    });
    closeInternshipDialog();
    await loadInternshipPanel(internshipReviewPanel(entity));
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

function prepareScore(row) {
  openScoreDialog(row);
  const win = openWindows.find(item => item.module.id === 'internship' && item.id === focusedWindowId.value)
    || openWindows.find(item => item.module.id === 'internship');
  if (win) {
    win.panel = 'scores';
  }
  loadInternshipPanel('scores').then(() => {
    fillScoreFormFromExisting(row.student_id, row.arrangement_id);
  });
}

function selectScorePair(pairId) {
  const pair = internshipState.lists.pairs.items.find(item => item.id === pairId);
  if (!pair) {
    return;
  }
  internshipState.scoreForm.student_id = pair.student_id;
  internshipState.scoreForm.arrangement_id = pair.arrangement_id;
  fillScoreFormFromExisting(pair.student_id, pair.arrangement_id, pair);
}

function fillScoreFormFromExisting(studentId, arrangementId, fallback = null) {
  const score = internshipState.lists.scores.items.find(item =>
    Number(item.student_id) === Number(studentId) && Number(item.arrangement_id) === Number(arrangementId));
  const source = score || (fallback?.score_id ? fallback : null);
  if (!source) {
    internshipState.scoreForm.sign_in_score = '';
    internshipState.scoreForm.journal_score = '';
    internshipState.scoreForm.report_score = '';
    internshipState.scoreForm.enterprise_score = '';
    internshipState.scoreForm.comment = '';
    return;
  }
  internshipState.scoreForm.sign_in_score = source.sign_in_score ?? '';
  internshipState.scoreForm.journal_score = source.journal_score ?? '';
  internshipState.scoreForm.report_score = source.report_score ?? '';
  internshipState.scoreForm.enterprise_score = source.enterprise_score ?? '';
  internshipState.scoreForm.comment = source.comment || source.score_comment || '';
}

async function saveScore() {
  if (!canSaveInternshipScore.value || !internshipState.scoreForm.student_id || !internshipState.scoreForm.arrangement_id) {
    internshipState.message = '请先选择学生和实习任务';
    return;
  }

  internshipState.loading = true;
  internshipState.message = '';
  try {
    await saveInternshipScore({
      student_id: internshipState.scoreForm.student_id,
      arrangement_id: internshipState.scoreForm.arrangement_id,
      sign_in_score: numericOrNull(internshipState.scoreForm.sign_in_score),
      journal_score: numericOrNull(internshipState.scoreForm.journal_score),
      report_score: numericOrNull(internshipState.scoreForm.report_score),
      enterprise_score: numericOrNull(internshipState.scoreForm.enterprise_score),
      comment: internshipState.scoreForm.comment,
    });
    internshipState.scoreForm = emptyScoreForm();
    closeInternshipDialog();
    await loadInternshipPanel('scores');
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

async function saveCourseScore() {
  const scoreValue = numericOrNull(internshipState.courseScoreForm.score_value);
  if (!canSaveInternshipScore.value || !internshipState.courseScoreForm.plan_id || !internshipState.courseScoreForm.student_id) {
    internshipState.message = '请选择需要核定的课程成绩';
    return;
  }
  if (scoreValue === null || scoreValue < 0 || scoreValue > 100) {
    internshipState.message = '课程成绩必须在 0 到 100 之间';
    return;
  }

  internshipState.loading = true;
  internshipState.message = '';
  try {
    await saveInternshipCourseScore({
      plan_id: internshipState.courseScoreForm.plan_id,
      student_id: internshipState.courseScoreForm.student_id,
      score_value: scoreValue,
      remark: internshipState.courseScoreForm.remark,
    });
    closeInternshipDialog();
    await Promise.all([
      loadInternshipPanel('courseScores', internshipState.lists.courseScores.pagination.page || 1),
      loadInternshipPanel('scores', internshipState.lists.scores.pagination.page || 1),
    ]);
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

function numericOrNull(value) {
  return value === '' || value === null || value === undefined ? null : Number(value);
}

function arrangementTypeText(value) {
  const names = {
    cognition_internal: '认知校内',
    cognition_external: '认知校外',
    major_internal: '专业校内',
    major_external: '专业校外',
    production: '生产实习',
    graduation: '毕业实习',
  };
  return names[value] || value || '-';
}

function organizeModeText(value) {
  const names = {
    centralized: '集中',
    distributed: '分散',
    autonomous: '自主',
  };
  return names[value] || value || '-';
}

function arrangementScopeText(row) {
  return [
    row.dep_name || '全校',
    row.profession_name || '全部专业',
    row.grade_name || '',
  ].filter(Boolean).join(' / ');
}

function signTypeText(value) {
  const names = {
    gps: '定位',
    qrcode: '扫码',
    manual: '补录',
  };
  return names[value] || value || '-';
}

function statusText(value) {
  const names = {
    draft: '草稿',
    wait: '待审核',
    accept: '已通过',
    modify: '需修改',
    refuse: '已退回',
    enabled: '启用',
    changing: '变更中',
    disabled: '停用',
    changed: '已变更',
    completed: '已完成',
    pending: '待处理',
    skipped: '跳过',
    active: '有效',
    removed: '已解除',
    signed: '已签署',
    complete: '完整',
    incomplete: '待补齐',
    archived: '已归档',
    missing: '待补齐',
    not_required: '不适用',
    confirmed: '已确认',
    published: '已发布',
  };
  return names[value] || value || '-';
}

function statusTagType(value) {
  if (['accept', 'enabled', 'active', 'signed', 'complete', 'completed', 'archived', 'confirmed', 'published'].includes(value)) {
    return 'success';
  }
  if (['wait', 'pending', 'draft', 'changing'].includes(value)) {
    return 'warning';
  }
  if (['modify', 'removed', 'disabled', 'changed', 'not_required'].includes(value)) {
    return 'info';
  }
  if (['refuse', 'incomplete', 'missing'].includes(value)) {
    return 'danger';
  }
  return 'primary';
}

function resetAdminState() {
  adminState.roles = [];
  adminState.menus = [];
  adminState.menu.items = [];
  adminState.menu.editing = emptyMenu();
  adminState.menu.keyword = '';
  adminState.menu.expanded = true;
  adminState.menu.expandedKeys = [];
  adminState.menu.treeKey = 0;
  adminState.menu.message = '';
  adminState.roleMenus.role_id = null;
  adminState.roleMenus.menu_ids = [];
  adminState.roleMenus.permissions = [];
  adminState.roleMenus.message = '';
  adminState.options.accounts = [];
  adminState.options.departments = [];
  adminState.options.grades = [];
  adminState.options.professions = [];
  adminState.options.classes = [];
  adminState.options.companies = [];
  adminState.scope.account_id = null;
  adminState.scope.role_id = null;
  adminState.scope.scopes = [];
  adminState.scope.message = '';
  userAdminState.items = [];
  userAdminState.filters.keyword = '';
  userAdminState.filters.role_type = '';
  userAdminState.filters.status = 'all';
  userAdminState.editing = emptyUserForm();
  userAdminState.dialogVisible = false;
  userAdminState.passwordDialogVisible = false;
  userAdminState.passwordForm = {
    id: null,
    name: '',
    password: '',
  };
  userAdminState.detailDialogVisible = false;
  userAdminState.detailMode = 'logs';
  userAdminState.detailLoading = false;
  userAdminState.detail = emptyUserDetail();
  userAdminState.detailPagination = {
    page: 1,
    page_size: 20,
    total: 0,
  };
  userAdminState.pagination.page = 1;
  userAdminState.pagination.total = 0;
  userAdminState.passkeyLoadingId = null;
  userAdminState.passkeyUrl = '';
  userAdminState.message = '';
  Object.entries(archiveStates).forEach(([type, state]) => {
    state.items = [];
    state.filters.keyword = '';
    state.filters.filter_flag = 'all';
    state.pagination.page = 1;
    state.pagination.total = 0;
    state.editing = emptyArchiveItem(type);
    state.selected = null;
    state.dialogVisible = false;
    state.loading = false;
    state.importing = false;
    state.message = '';
  });
  fileState.items = [];
  fileState.message = '';
  fileState.pagination.page = 1;
  fileState.pagination.total = 0;
  resetFavoriteState();
  dataManageState.clearLoading = false;
  dataManageState.message = '';
  resetInternshipState();
}

function resetFavoriteState() {
  favoriteState.items = [];
  favoriteState.filters.keyword = '';
  favoriteState.pagination.page = 1;
  favoriteState.pagination.total = 0;
  favoriteState.loading = false;
  favoriteState.iconUploading = false;
  favoriteState.message = '';
  favoriteState.dialogVisible = false;
  favoriteState.editing = emptyFavoriteForm();
}

function resetInternshipState() {
  internshipState.message = '';
  internshipState.savedMessage = '';
  internshipState.reviewOpinion = '';
  internshipState.overview = emptyInternshipOverview();
  internshipState.options = emptyInternshipOptions();
  internshipState.arrangementForm = emptyArrangementForm();
  internshipState.planForm = emptyPlanForm();
  internshipState.scoreForm = emptyScoreForm();
  internshipState.courseScoreForm = emptyCourseScoreForm();
  internshipState.baseFlowForm = emptyBaseFlowForm();
  Object.keys(internshipState.filters).forEach((key) => {
    internshipState.filters[key] = emptyInternshipFilters();
  });
  Object.keys(internshipState.lists).forEach((key) => {
    internshipState.lists[key] = emptyPagedList();
  });
}

function applyProfileData(data) {
  profileState.form.name = data.user?.name || '';
  profileState.form.avatar = data.user?.avatar || '';
  profileState.form.mobile = data.user?.mobile || '';
  profileState.form.email = data.user?.email || '';
  profileState.form.wallpaper = data.desktop?.wallpaper || 'default';
  profileState.form.wallpaper_url = data.desktop?.wallpaper_url || '';
  profileState.form.notify.system = data.notify?.system !== false;
  profileState.form.notify.wechat = data.notify?.wechat !== false;
  profileState.form.notify.email = Boolean(data.notify?.email);
  cacheWallpaper();
}

function applyLoginPageData(data) {
  const payload = data?.data && typeof data.data === 'object' ? data.data : data || {};
  loginPageState.school_name = payload.school_name || '成都锦城学院';
  loginPageState.login_background_url = payload.login_background_url || payload.url || '';
}

async function loadLoginPageSettings() {
  loginPageState.loading = true;
  loginPageState.message = '';
  try {
    applyLoginPageData(await fetchLoginPageSettings());
  } catch (error) {
    loginPageState.message = error.message;
  } finally {
    loginPageState.loading = false;
  }
}

function resetProfileState() {
  Object.assign(profileState.form, emptyProfile());
  profileState.message = '';
  profileState.saved = false;
}

async function loadProfile() {
  if (!isLoggedIn.value) {
    return;
  }

  profileState.loading = true;
  profileState.message = '';
  profileState.saved = false;
  try {
    const data = await fetchProfileSettings();
    applyProfileData(data);
  } catch (error) {
    profileState.message = error.message;
  } finally {
    profileState.loading = false;
  }
}

async function saveProfile() {
  if (!isLoggedIn.value) {
    return;
  }

  profileState.loading = true;
  profileState.message = '';
  profileState.saved = false;
  try {
    await persistProfileSettings('已保存');
  } catch (error) {
    profileState.message = error.message;
  } finally {
    profileState.loading = false;
  }
}

function selectWallpaper(key) {
  profileState.form.wallpaper = key;
  profileState.form.wallpaper_url = '';
  cacheWallpaper();
}

function guardProfileAssetClick(event) {
  if (profileState.loading) {
    event.preventDefault();
  }
}

function guardLoginBackgroundClick(event) {
  if (loginPageState.loading || !canManageLoginBackground.value) {
    event.preventDefault();
  }
}

async function handleAssetSelected(type, event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file || !isLoggedIn.value) {
    return;
  }

  profileState.loading = true;
  profileState.message = '';
  profileState.saved = false;
  try {
    const data = await uploadProfileAsset(type, file);
    if (type === 'avatar') {
      profileState.form.avatar = data.url || '';
      await persistProfileSettings('头像已更新');
      return;
    }

    profileState.form.wallpaper = 'custom';
    profileState.form.wallpaper_url = data.url || '';
    cacheWallpaper();
    await persistProfileSettings('壁纸已更新');
  } catch (error) {
    profileState.message = error.message;
  } finally {
    profileState.loading = false;
  }
}

async function handleLoginBackgroundSelected(event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file || !canManageLoginBackground.value) {
    return;
  }
  const assetError = validateImageAsset(file, loginBackgroundMaxSize);
  if (assetError) {
    loginPageState.message = assetError;
    return;
  }

  loginPageState.loading = true;
  loginPageState.message = '';
  try {
    applyLoginPageData(await uploadLoginBackground(file));
    loginPageState.message = '登录背景已更新';
  } catch (error) {
    loginPageState.message = error.message;
  } finally {
    loginPageState.loading = false;
  }
}

function validateImageAsset(file, maxSize) {
  if (!imageAssetTypes.includes(file.type)) {
    return '仅支持 JPG、PNG、WEBP、GIF 图片';
  }
  if (file.size > maxSize) {
    return `图片大小不能超过 ${formatFileSize(maxSize)}`;
  }
  return '';
}

async function persistProfileSettings(message) {
  const data = await saveProfileSettings({
    name: profileState.form.name,
    avatar: profileState.form.avatar,
    mobile: profileState.form.mobile,
    email: profileState.form.email,
    wallpaper: profileState.form.wallpaper,
    wallpaper_url: profileState.form.wallpaper_url,
    notify: { ...profileState.form.notify },
  });
  applyProfileData(data);
  permissionState.context.user_name = data.user?.name || permissionState.context.user_name;
  profileState.message = message;
  profileState.saved = true;
}

function safeCssUrl(value) {
  return String(value || '').replace(/["\\\n\r]/g, '');
}

async function loadProxy() {
  if (!isLoggedIn.value || !hasPermission('wechat:proxy')) {
    return;
  }

  wechatProxy.loading = true;
  wechatProxy.message = '';
  try {
    const data = await fetchWechatConfig();
    applyWechatConfig(data);
  } catch (error) {
    wechatProxy.message = error.message;
  } finally {
    wechatProxy.loading = false;
  }
}

function applyWechatConfig(data) {
  wechatProxy.app_id = data.app_id || '';
  wechatProxy.corp_id = data.corp_id || '';
  wechatProxy.agent_id = data.agent_id || '';
  wechatProxy.secret = data.secret || '';
  wechatProxy.token = data.token || '';
  wechatProxy.encoding_aes_key = data.encoding_aes_key || '';
  wechatProxy.proxy_url = data.proxy_url || '';
  wechatProxy.proxy_enabled = Boolean(data.proxy_enabled);
  wechatProxy.menu = normalizeWechatMenu(data.menu || []);
  wechatProxy.selectedMenuIndex = wechatProxy.menu.length ? 0 : -1;
  wechatProxy.selectedSubMenuIndex = -1;
}

function normalizeWechatMenu(items) {
  return items.slice(0, 3).map(item => ({
    name: item.name || '',
    type: item.type || 'view',
    url: item.url || '',
    key: item.key || '',
    appid: item.appid || '',
    pagepath: item.pagepath || '',
    children: Array.isArray(item.children) ? item.children.slice(0, 5).map(child => ({
      name: child.name || '',
      type: child.type || 'view',
      url: child.url || '',
      key: child.key || '',
      appid: child.appid || '',
      pagepath: child.pagepath || '',
    })) : [],
  }));
}

function wechatMenuPayload(items) {
  return items.map((item) => {
    const payload = {
      name: item.name,
      type: item.type,
    };
    if (item.children?.length) {
      payload.children = wechatMenuPayload(item.children);
      delete payload.type;
      return payload;
    }
    if (item.type === 'click') {
      payload.key = item.key;
    } else if (item.type === 'miniprogram') {
      payload.url = item.url;
      payload.appid = item.appid;
      payload.pagepath = item.pagepath;
    } else {
      payload.url = item.url;
    }
    return payload;
  });
}

function validateWechatMenus(items, allowChildren = true) {
  if (items.length > (allowChildren ? 3 : 5)) {
    return allowChildren ? '企业微信一级菜单最多 3 个' : '企业微信子菜单最多 5 个';
  }

  for (const item of items) {
    if (!String(item.name || '').trim()) {
      return '企业微信菜单名称不能为空';
    }
    if (item.children?.length) {
      if (!allowChildren) {
        return '企业微信菜单只支持两级';
      }
      const childError = validateWechatMenus(item.children, false);
      if (childError) {
        return childError;
      }
      continue;
    }
    if (item.type === 'click' && !String(item.key || '').trim()) {
      return '点击菜单 Key 不能为空';
    }
    if (item.type === 'view' && !String(item.url || '').trim()) {
      return '跳转菜单 URL 不能为空';
    }
    if (item.type === 'miniprogram' && (!String(item.appid || '').trim() || !String(item.pagepath || '').trim())) {
      return '小程序菜单 AppID 和路径不能为空';
    }
  }

  return '';
}

function selectWechatMenu(index, subIndex = -1) {
  wechatProxy.selectedMenuIndex = index;
  wechatProxy.selectedSubMenuIndex = subIndex;
}

function emptyWechatMenu() {
  return {
    name: '',
    type: 'view',
    url: '',
    key: '',
    appid: '',
    pagepath: '',
    children: [],
  };
}

function addWechatMenu(parentIndex = null) {
  if (parentIndex === null || parentIndex === undefined || parentIndex < 0) {
    if (wechatProxy.menu.length >= 3) {
      return;
    }
    wechatProxy.menu.push(emptyWechatMenu());
    selectWechatMenu(wechatProxy.menu.length - 1);
    return;
  }

  const parent = wechatProxy.menu[parentIndex];
  if (!parent || (parent.children || []).length >= 5) {
    return;
  }
  parent.children = parent.children || [];
  parent.children.push(emptyWechatMenu());
  selectWechatMenu(parentIndex, parent.children.length - 1);
}

function removeSelectedWechatMenu() {
  if (wechatProxy.selectedMenuIndex < 0) {
    return;
  }
  if (wechatProxy.selectedSubMenuIndex >= 0) {
    const children = wechatProxy.menu[wechatProxy.selectedMenuIndex]?.children || [];
    children.splice(wechatProxy.selectedSubMenuIndex, 1);
    wechatProxy.selectedSubMenuIndex = children.length ? Math.min(wechatProxy.selectedSubMenuIndex, children.length - 1) : -1;
    return;
  }
  wechatProxy.menu.splice(wechatProxy.selectedMenuIndex, 1);
  wechatProxy.selectedMenuIndex = wechatProxy.menu.length ? Math.min(wechatProxy.selectedMenuIndex, wechatProxy.menu.length - 1) : -1;
  wechatProxy.selectedSubMenuIndex = -1;
}

async function saveProxy() {
  const menuError = validateWechatMenus(wechatProxy.menu);
  if (menuError) {
    wechatProxy.message = menuError;
    return;
  }

  wechatProxy.loading = true;
  wechatProxy.message = '';
  try {
    const data = await saveWechatConfig({
      app_id: wechatProxy.app_id,
      corp_id: wechatProxy.corp_id,
      agent_id: wechatProxy.agent_id,
      secret: wechatProxy.secret,
      token: wechatProxy.token,
      encoding_aes_key: wechatProxy.encoding_aes_key,
      proxy_url: wechatProxy.proxy_url,
      proxy_enabled: wechatProxy.proxy_enabled,
      menu: wechatMenuPayload(wechatProxy.menu),
    });
    applyWechatConfig(data);
    wechatProxy.message = '已保存';
  } catch (error) {
    wechatProxy.message = error.message;
  } finally {
    wechatProxy.loading = false;
  }
}

watch(openWindows, (windows) => {
  if (windows.some(win => ['menuManage', 'roleMenus', 'organizationScope'].includes(win.panel))) {
    loadAdminFoundation();
  }
  if (windows.some(win => win.panel === 'operationGuides')) {
    loadGuideAdminItems();
  }
  const archiveWindows = windows.filter(win => isArchiveManageWindow(win));
  if (archiveWindows.length) {
    loadAdminFoundation();
    Array.from(new Set(archiveWindows.map(archiveTypeForWindow))).forEach(type => loadArchiveItems(type));
  }
  if (windows.some(win => win.panel === 'fileManage')) {
    loadFiles(fileState.pagination.page);
  }
  if (windows.some(win => win.module.id === 'favorite')) {
    loadFavorites(favoriteState.pagination.page);
  }
  if (windows.some(win => win.module.id === 'log')) {
    loadLogs(logState.pagination.page);
  }
  if (windows.some(win => win.module.id === 'stat')) {
    loadStats(statState.pagination.page || 1);
  }
  const internshipWindow = windows.find(win => win.module.id === 'internship' && !win.minimized);
  if (internshipWindow) {
    loadInternshipPanel(internshipWindow.panel);
  }
}, { deep: true });

watch(() => permissionState.context.account_id, (accountId) => {
  if (accountId) {
    loadAdminFoundation();
    loadInternshipFoundation();
    loadMessageSummary();
    loadSwitchableAccounts();
    loadDesktopShortcuts();
  } else {
    resetMessageState();
    switchAccountState.items = [];
    desktopLauncherState.items = [];
    favoriteState.items = [];
  }
});

watch(allLaunchableModules, () => {
  if (isLoggedIn.value) {
    syncOpenWindowModules();
    ensureDefaultWindow();
    handleHashNavigation();
  }
});

function handleAuthExpired(event) {
  const message = event?.detail?.message || '登录已过期，请重新登录';
  permissionState.context = {};
  permissionState.permissions = [];
  permissionState.menus = [];
  permissionState.dataScope = null;
  permissionState.error = message;
  loginState.message = message;
  openWindows.splice(0, openWindows.length);
  focusedWindowId.value = null;
  switchAccountState.items = [];
  switchAccountState.message = '';
  desktopLauncherState.items = [];
  desktopLauncherState.message = '';
  resetFavoriteState();
  resetMessageState();
  resetInternshipState();
  window.location.hash = '';
}

onMounted(async () => {
  window.addEventListener('hashchange', handleHashNavigation);
  window.addEventListener('practical-auth-expired', handleAuthExpired);
  renderClock();
  setInterval(renderClock, 30000);
  await loadLoginPageSettings();
  await loadRegisterOptions();
  await consumeUrlPasskey();
  await load();
  await loadProfile();
  await loadDesktopShortcuts();
  await loadProxy();
  await loadMessageSummary();
  await loadSwitchableAccounts();
  await nextTick();
  ensureDefaultWindow();
  handleHashNavigation();
  scheduleDefaultWindow();
  loadAdminFoundation();
});

onBeforeUnmount(() => {
  window.removeEventListener('hashchange', handleHashNavigation);
  window.removeEventListener('practical-auth-expired', handleAuthExpired);
});
</script>
