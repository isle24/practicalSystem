<template>
  <main v-if="!isLoggedIn" class="login-shell" :style="loginPageStyle">
    <form class="login-panel" @submit.prevent="submitLogin">
      <header>
        <UserRound :size="26" />
        <div>
          <strong>实践管理系统</strong>
          <span>{{ loginSchoolName }}</span>
        </div>
      </header>
      <label>
        <span>账号</span>
        <input ref="loginNameInput" v-model="loginForm.login_name" autocomplete="username" placeholder="admin">
      </label>
      <label>
        <span>密码</span>
        <input v-model="loginForm.password" autocomplete="current-password" placeholder="admin123456" type="password">
      </label>
      <button type="submit" :disabled="loginState.loading">
        <LogIn :size="17" />
        登录
      </button>
      <small v-if="loginState.message">{{ loginState.message }}</small>
    </form>
  </main>

  <main v-else class="desktop-shell" :style="desktopStyle" @click.left="closeDesktopContextMenu" @contextmenu.prevent="openDesktopContextMenu">
    <header class="topbar">
      <div class="brand">
        <span class="brand-mark">实</span>
        <span>实践管理系统</span>
      </div>
      <label class="global-search">
        <Search :size="15" />
        <input v-model="keyword" type="search" placeholder="搜索模块、学生、企业或文档">
      </label>
      <div class="top-actions">
        <el-button text :icon="Bell" />
        <el-button v-if="isLoggedIn" text class="operator-button" @click="openProfile">
          <span class="top-avatar" :style="topAvatarStyle">
            <UserRound v-if="!profileState.form.avatar" :size="14" />
          </span>
          <span>{{ operatorName }}</span>
        </el-button>
        <span v-else>{{ operatorName }}</span>
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
        <a
          v-for="module in visibleModules"
          :key="module.id"
          :href="moduleHref(module)"
          class="desktop-icon"
          :class="{ active: isModuleFocused(module.id) }"
          @click="openModule(module)"
        >
          <span class="app-glyph" :class="module.color">
            <component :is="module.icon" :size="25" />
          </span>
          <span>{{ module.name }}</span>
        </a>
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
        @panel="setWindowPanel(win.id, $event)"
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
                @pointerdown="activateWindowPanel(win, item.key)"
                @mousedown="activateWindowPanel(win, item.key)"
                @click="activateWindowPanel(win, item.key)"
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
                        新增安排
                      </el-button>
                      <el-button v-if="win.panel === 'plans' && canManageInternshipPlan" :icon="FileText" @click="openPlanDialog">
                        新增计划
                      </el-button>
                      <el-button v-if="win.panel === 'scores' && canManageInternship" :icon="GraduationCap" @click="openScoreDialog">
                        录入成绩
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
                    <section class="operation-dialog">
                      <header>
                        <strong>{{ internshipState.dialog.title }}</strong>
                        <button type="button" @click="closeInternshipDialog">关闭</button>
                      </header>

                      <div v-if="internshipState.dialog.type === 'arrangement'" class="operation-form">
                        <label><span>标题</span><input v-model="internshipState.arrangementForm.title"></label>
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
                          <span>学院</span>
                          <el-select v-model="internshipState.planForm.dep_id" filterable>
                            <el-option v-for="dep in internshipState.options.departments" :key="dep.dep_id" :label="dep.dep_name" :value="dep.dep_id" />
                          </el-select>
                        </label>
                        <label>
                          <span>计划内容</span>
                          <textarea v-model="internshipState.planForm.content" rows="8" />
                        </label>
                        <label>
                          <span>状态</span>
                          <el-select v-model="internshipState.planForm.status">
                            <el-option label="保存草稿" value="draft" />
                            <el-option label="提交审核" value="wait" />
                          </el-select>
                        </label>
                      </div>

                      <div v-else-if="internshipState.dialog.type === 'score'" class="operation-form">
                        <label>
                          <span>学生</span>
                          <el-select v-model="internshipState.scoreForm.pair_id" filterable placeholder="选择指导关系" @change="selectScorePair">
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

                      <div v-else-if="['review', 'reopen'].includes(internshipState.dialog.type)" class="operation-form single">
                        <p>{{ internshipState.dialog.description }}</p>
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
                        <el-button v-if="internshipState.dialog.type !== 'timeline'" type="primary" :loading="internshipState.loading" @click="confirmInternshipDialog">
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
                        :description="studentPanelMeta('applications').description"
                        :empty-text="studentPanelMeta('applications').emptyText"
                        :fields="studentPanelFields('applications')"
                        :loading="internshipState.loading"
                        :pagination="studentPanelList('applications').pagination"
                        :rows="studentPanelList('applications').items"
                        :status-formatter="statusText"
                        :status-tag-type="statusTagType"
                        timeline-entity="application"
                        :title="studentPanelMeta('applications').title"
                        @page-change="page => loadInternshipPanel('applications', page)"
                        @refresh="loadInternshipPanel('applications')"
                        @timeline="row => openTimelineDialog('application', row)"
                      />
                      <section v-else-if="activeOverviewTab === 'arrangements'" class="internship-card overview-card-single">
                        <header>
                          <strong>近期安排</strong>
                          <small>{{ internshipState.lists.arrangements.pagination.total || 0 }} 条</small>
                        </header>
                        <el-table :data="internshipState.lists.arrangements.items" height="100%" stripe>
                          <el-table-column prop="title" label="安排" min-width="170" />
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
                          <strong>待处理申请</strong>
                          <small>{{ internshipState.overview.applications_waiting || 0 }} 条</small>
                        </header>
                        <el-table :data="internshipState.lists.applications.items" height="100%" stripe>
                          <el-table-column prop="student_name" label="学生" width="110" />
                          <el-table-column prop="arrangement_title" label="实习安排" min-width="170" />
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
                      />
                    </div>
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
                        <el-button v-if="canManageInternship" link type="primary" @click="prepareScore(row)">录入</el-button>
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
                    />
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
                    <div class="internship-list-only">
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
                      />
                    </div>
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

                <div v-else-if="isUserManageWindow(win)" class="admin-panel user-admin-panel">
                  <div class="admin-toolbar">
                    <el-button type="primary" :icon="Plus" @click="openUserDialog()">
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
                      <el-button link type="primary" :disabled="!canMaintainUser(row)" @click="openUserDialog(row)">
                        编辑
                      </el-button>
                      <el-button
                        link
                        :type="row.status === 'enabled' ? 'warning' : 'success'"
                        :disabled="Number(row.id) === Number(permissionState.context.account_id) || !canMaintainUser(row)"
                        @click="toggleUserStatus(row)"
                      >
                        {{ row.status === 'enabled' ? '停用' : '启用' }}
                      </el-button>
                      <el-button link type="warning" :disabled="!canMaintainUser(row)" @click="openResetPasswordDialog(row)">
                        重置密码
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
                          <el-select v-model="userAdminState.editing.role_id" filterable>
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
                        <label>
                          <span>状态</span>
                          <el-select v-model="userAdminState.editing.status">
                            <el-option label="启用" value="enabled" />
                            <el-option label="停用" value="disabled" />
                          </el-select>
                        </label>
                        <label>
                          <span>{{ userAdminState.editing.id ? '新密码' : '初始密码' }}</span>
                          <input v-model="userAdminState.editing.password" type="password" :placeholder="userAdminState.editing.id ? '留空表示不修改' : '默认 admin123456'">
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
                        <label><span>新密码</span><input v-model="userAdminState.passwordForm.password" type="password"></label>
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
                    <el-button :icon="RefreshCw" :loading="archiveStateForWindow(win).loading" @click="loadArchiveItems(archiveTypeForWindow(win))">
                      读取
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
                  <div v-if="archiveStateForWindow(win).dialogVisible" class="operation-mask" @click.self="closeArchiveDialog(archiveTypeForWindow(win))">
                    <section class="operation-dialog menu-dialog">
                      <header>
                        <strong>{{ archiveStateForWindow(win).editing.id ? '编辑档案' : '新增档案' }}</strong>
                        <button type="button" @click="closeArchiveDialog(archiveTypeForWindow(win))">关闭</button>
                      </header>
                      <div class="operation-form menu-dialog-form">
                      <label
                        v-for="field in archiveFieldsForWindow(win)"
                        :key="field.key"
                      >
                        <span>{{ field.label }}</span>
                        <el-select
                          v-if="field.options"
                          v-model="archiveStateForWindow(win).editing[field.key]"
                          clearable
                          filterable
                        >
                          <el-option
                            v-for="option in archiveFieldOptions(field)"
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
                  <TemplateLibrary :can-manage="hasPermission('template:manage')" />
                </div>

                <div v-else-if="win.module.id === 'exportTask'" class="module-content-panel">
                  <ExportTaskCenter />
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
                          :class="{ unread: !item.is_read, urgent: item.level === 'urgent' }"
                          @click="handleMessageClick(item)"
                        >
                          <header>
                            <span class="message-kind" :class="item.type">{{ messageTypeText(item.type) }}</span>
                            <strong>{{ item.title }}</strong>
                            <small>{{ messageTimeText(item.created_at) }}</small>
                          </header>
                          <p>{{ item.content }}</p>
                          <footer>
                            <span>{{ item.sender_name || '系统' }}</span>
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
                        <el-button link type="primary" :disabled="!row.url" @click="openFileUrl(row.url)">
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
                  </div>
                  <section class="menu-tree-panel menu-tree-full">
                    <header>
                      <strong>菜单树</strong>
                      <small>{{ adminState.menu.items.length }} 项</small>
                    </header>
                    <el-tree
                      class="permission-tree menu-edit-tree"
                      :data="adminState.menus"
                      :props="treeProps"
                      node-key="id"
                      default-expand-all
                      highlight-current
                      :current-node-key="adminState.menu.selected?.id"
                      :expand-on-click-node="false"
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
                            <small>{{ menuNodeTypeText(data.type) }} / {{ data.platform }} / {{ data.path || '-' }}</small>
                          </span>
                        </span>
                      </template>
                    </el-tree>
                  </section>
                  <small v-if="adminState.menu.message">{{ adminState.menu.message }}</small>

                  <div v-if="adminState.menu.dialogVisible" class="operation-mask" @click.self="closeMenuDialog">
                    <section class="operation-dialog menu-dialog">
                      <header>
                        <strong>{{ adminState.menu.dialogMode === 'edit' ? '编辑菜单' : '新增菜单' }}</strong>
                        <button type="button" @click="closeMenuDialog">关闭</button>
                      </header>
                      <div class="operation-form menu-dialog-form">
                        <label>
                          <span>名称</span>
                          <input v-model="adminState.menu.editing.name">
                        </label>
                        <label>
                          <span>权限码</span>
                          <input v-model="adminState.menu.editing.code" placeholder="如 internship:apply">
                        </label>
                        <label>
                          <span>路径</span>
                          <input v-model="adminState.menu.editing.path" placeholder="页面菜单填写路由，按钮可为空">
                        </label>
                        <label>
                          <span>图标</span>
                          <input v-model="adminState.menu.editing.icon" placeholder="lucide 图标名">
                        </label>
                        <label>
                          <span>父级</span>
                          <el-tree-select
                            v-model="adminState.menu.editing.parent_id"
                            :data="parentMenuTreeOptions"
                            :props="treeProps"
                            check-strictly
                            default-expand-all
                            filterable
                            node-key="id"
                          />
                        </label>
                        <label>
                          <span>平台</span>
                          <el-select v-model="adminState.menu.editing.platform">
                            <el-option label="PC" value="pc" />
                            <el-option label="H5" value="h5" />
                            <el-option label="双端" value="both" />
                          </el-select>
                        </label>
                        <label>
                          <span>类型</span>
                          <el-radio-group v-model="adminState.menu.editing.type" class="menu-type-radios">
                            <el-radio-button label="directory">目录</el-radio-button>
                            <el-radio-button label="menu">菜单</el-radio-button>
                            <el-radio-button label="list">列表</el-radio-button>
                            <el-radio-button label="button">按钮</el-radio-button>
                          </el-radio-group>
                        </label>
                        <label>
                          <span>排序</span>
                          <input v-model="adminState.menu.editing.sort" type="number">
                        </label>
                        <label>
                          <span>可见</span>
                          <el-select v-model="adminState.menu.editing.visible">
                            <el-option label="是" value="true" />
                            <el-option label="否" value="false" />
                          </el-select>
                        </label>
                        <label>
                          <span>状态</span>
                          <el-select v-model="adminState.menu.editing.status">
                            <el-option label="启用" value="enabled" />
                            <el-option label="禁用" value="disabled" />
                          </el-select>
                        </label>
                      </div>
                      <footer>
                        <el-button @click="closeMenuDialog">取消</el-button>
                        <el-button type="primary" :loading="adminState.menu.loading" @click="saveMenuConfig">
                          保存
                        </el-button>
                      </footer>
                    </section>
                  </div>
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
                    <el-table-column label="企业" min-width="180">
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

                <div v-else-if="win.module.id === 'log'" class="admin-panel log-panel">
                  <div class="admin-toolbar log-toolbar">
                    <el-input v-model="logState.filters.keyword" clearable placeholder="关键词、账号、姓名、IP" @keyup.enter="loadLogs(1)" />
                    <el-input v-model="logState.filters.action" clearable placeholder="动作" @keyup.enter="loadLogs(1)" />
                    <el-input v-model="logState.filters.ip" clearable placeholder="IP" @keyup.enter="loadLogs(1)" />
                    <input v-model="logState.filters.date_from" type="date">
                    <input v-model="logState.filters.date_to" type="date">
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
                    <el-table-column prop="action" label="动作" min-width="150" />
                    <el-table-column prop="ip" label="IP" width="140" />
                    <el-table-column prop="source_table" label="来源表" width="150" />
                    <el-table-column label="内容" min-width="260">
                      <template #default="{ row }">
                        <span class="log-payload" :title="payloadText(row.payload)">{{ payloadText(row.payload) }}</span>
                      </template>
                    </el-table-column>
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
                        <el-select v-model="statState.filters.grade_id" class="filter-select" :class="{ 'is-filter-active': hasFilterValue(statState.filters.grade_id) }" clearable filterable placeholder="全部">
                          <el-option v-for="item in optionItems(internshipState.options.grades, 'grade_id', 'grade_name')" :key="item.value" :label="item.label" :value="item.value" />
                        </el-select>
                      </label>
                      <label :class="{ 'filter-active': hasFilterValue(statState.filters.dep_id) }">
                        <span>学院</span>
                        <el-select v-model="statState.filters.dep_id" class="filter-select" :class="{ 'is-filter-active': hasFilterValue(statState.filters.dep_id) }" clearable filterable placeholder="全部">
                          <el-option v-for="item in optionItems(internshipState.options.departments, 'dep_id', 'dep_name')" :key="item.value" :label="item.label" :value="item.value" />
                        </el-select>
                      </label>
                      <label :class="{ 'filter-active': hasFilterValue(statState.filters.profession_id) }">
                        <span>专业</span>
                        <el-select v-model="statState.filters.profession_id" class="filter-select" :class="{ 'is-filter-active': hasFilterValue(statState.filters.profession_id) }" clearable filterable placeholder="全部">
                          <el-option v-for="item in optionItems(internshipState.options.professions, 'profession_id', 'profession_name')" :key="item.value" :label="item.label" :value="item.value" />
                        </el-select>
                      </label>
                      <label :class="{ 'filter-active': hasFilterValue(statState.filters.keyword) }">
                        <span>关键词</span>
                        <input v-model="statState.filters.keyword" placeholder="学生、学号、企业、教师">
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
                      <el-table :data="statState.rows" height="100%" stripe v-loading="statState.loading">
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
                      <label class="check-row">
                        <input v-model="wechatProxy.proxy_enabled" type="checkbox">
                        <span>启用企业微信代理</span>
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

    <footer class="taskbar">
      <button class="taskbar-icon-button" title="开始" aria-label="开始">
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
          <span class="taskbar-glyph" :class="win.module.color">
            <component :is="win.module.icon" :size="17" />
          </span>
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
  GraduationCap,
  HardDrive,
  ImagePlus,
  LayoutGrid,
  ListTree,
  LogIn,
  LogOut,
  MapPin,
  MessageCircle,
  Plus,
  RefreshCw,
  Save,
  Search,
  Settings,
  SlidersHorizontal,
  Trash2,
  Upload,
  UsersRound,
  UserRound,
  Workflow,
} from '@lucide/vue';
import DesktopWindow from './components/DesktopWindow.vue';
import DataListPanel from './components/DataListPanel.vue';
import DocCenter from './components/DocCenter.vue';
import ExportTaskCenter from './components/ExportTaskCenter.vue';
import StudentOwnPanel from './components/StudentOwnPanel.vue';
import TemplateLibrary from './components/TemplateLibrary.vue';
import { usePermissions } from './composables/usePermissions';
import {
  changeAdminAccountStatus,
  deleteArchiveItem,
  fetchAdminAccountDetail,
  fetchAdminAccounts,
  fetchAdminMenus,
  fetchAdminOptions,
  fetchAdminRoles,
  fetchArchiveList,
  fetchFileList,
  fetchInternshipArchiveMaterials,
  fetchInternshipApplications,
  fetchInternshipArrangements,
  fetchInternshipDelays,
  fetchInternshipInsurances,
  fetchInternshipJournals,
  fetchInternshipOptions,
  fetchInternshipOverview,
  fetchInternshipPairs,
  fetchInternshipPlans,
  fetchInternshipReports,
  fetchInternshipSafetyLetters,
  fetchInternshipScores,
  fetchInternshipSignIns,
  fetchInternshipStats,
  fetchInternshipTimeline,
  fetchLoginPageSettings,
  fetchMessages,
  fetchMessageSummary,
  fetchOrganizationScopes,
  fetchOperationGuide,
  fetchOperationGuides,
  fetchOperationLogs,
  fetchProfileSettings,
  fetchRolePermissions,
  fetchWechatConfig,
  importArchiveExcel,
  deleteMenu as deleteMenuApi,
  deleteOperationGuide,
  login as loginApi,
  logout as logoutApi,
  markMessagesRead,
  reviewInternshipApplication,
  reviewInternshipDelay,
  reviewInternshipJournal,
  reviewInternshipPlan,
  reviewInternshipReport,
  requestInternshipModification,
  resetAdminAccountPassword,
  saveArchiveItem,
  saveAdminAccount,
  saveInternshipArrangement,
  saveInternshipPlan,
  saveInternshipScore,
  saveMenu as saveMenuApi,
  saveOrganizationScopes,
  saveOperationGuide,
  saveProfileSettings,
  saveRoleMenus,
  saveWechatConfig,
  uploadLoginBackground,
  uploadProfileAsset,
} from './api/system';

const { state: permissionState, hasPermission, load } = usePermissions();
const keyword = ref('');
const clock = ref('');
const loginNameInput = ref(null);
const wallpaperSectionRef = ref(null);
const archiveImportInputRef = ref(null);
const archiveImportType = ref('');
const focusedWindowId = ref(null);
const zIndexSeed = ref(20);
const wallpaperCacheKey = 'practical_pc_wallpaper';
const loginForm = reactive({
  login_name: 'admin',
  password: 'admin123456',
});
const loginState = reactive({
  loading: false,
  message: '',
});
const loginPageState = reactive({
  loading: false,
  message: '',
  school_code: '2184',
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
  message: '',
  filters: {
    type: 'all',
    status: 'all',
    keyword: '',
  },
  items: [],
  summary: {
    unread: 0,
    by_type: {},
  },
  pagination: {
    page: 1,
    page_size: 20,
    total: 0,
  },
});
const statState = reactive({
  report: 'overview',
  filters: {
    semester: '',
    dep_id: '',
    profession_id: '',
    grade_id: '',
    keyword: '',
  },
  cards: [],
  columns: [],
  rows: [],
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
    dialogVisible: false,
    dialogMode: 'create',
    loading: false,
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
    password: 'admin123456',
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
    scope: '学院 / 专业 / 指导关系',
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
  },
  {
    id: 'lab',
    name: '实验管理',
    icon: FlaskConical,
    color: 'green',
    scope: '实验模块入口',
    viewPermission: 'lab:view',
    managePermission: 'lab:manage',
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
    scope: '材料模板 / 下载',
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
    name: '企业管理',
    icon: Building2,
    color: 'amber',
    scope: '企业基础档案',
    viewPermission: 'config:company',
    managePermission: 'config:manage',
    defaultPanel: 'companyManage',
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
  { label: '审核通知', value: 'audit' },
  { label: '预警提醒', value: 'alert' },
];
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
      { key: 'profession_name', label: '专业名称', required: true },
      { key: 'profession_short_name', label: '专业简称' },
      { key: 'profession_code', label: '专业代码' },
      { key: 'dep_id', label: '所属学院', options: 'departments' },
      { key: 'grade_id', label: '所属届次', options: 'grades' },
      { key: 'sort', label: '排序', inputType: 'number' },
      { key: 'flag', label: '状态', options: 'flag' },
    ],
  },
  {
    type: 'class',
    name: '班级',
    idField: 'class_id',
    fields: [
      { key: 'class_name', label: '班级名称', required: true },
      { key: 'class_short_name', label: '班级简称' },
      { key: 'class_num', label: '班号' },
      { key: 'dep_id', label: '所属学院', options: 'departments' },
      { key: 'profession_id', label: '所属专业', options: 'professions' },
      { key: 'grade_id', label: '所属届次', options: 'grades' },
      { key: 'sort', label: '排序', inputType: 'number' },
      { key: 'flag', label: '状态', options: 'flag' },
    ],
  },
  {
    type: 'company',
    name: '企业',
    idField: 'company_id',
    fields: [
      { key: 'company_name', label: '企业名称', required: true },
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
};

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

const internshipSidebarItems = [
  { key: 'overview', name: '总览', icon: ChartColumn },
  { key: 'arrangements', name: '实习安排', icon: CalendarCheck },
  { key: 'plans', name: '实习计划', icon: FileText, permission: 'internship:plan' },
  { key: 'applications', name: '申请审核', icon: ClipboardList },
  { key: 'pairs', name: '指导关系', icon: UsersRound },
  { key: 'signIns', name: '签到记录', icon: MapPin },
  { key: 'journals', name: '实习日志', icon: FileClock },
  { key: 'reports', name: '实习报告', icon: FileText },
  { key: 'delays', name: '延期申请', icon: FileClock },
  { key: 'scores', name: '成绩管理', icon: GraduationCap },
  { key: 'documents', name: '归档材料', icon: FolderOpen },
];

const internshipState = reactive({
  loading: false,
  message: '',
  savedMessage: '',
  reviewOpinion: '',
  overviewTab: 'metrics',
  dialog: emptyOperationDialog(),
  overview: emptyInternshipOverview(),
  options: emptyInternshipOptions(),
  arrangementForm: emptyArrangementForm(),
  planForm: emptyPlanForm(),
  scoreForm: emptyScoreForm(),
  filters: {
    arrangements: emptyInternshipFilters(),
    plans: emptyInternshipFilters(),
    applications: emptyInternshipFilters(),
    pairs: emptyInternshipFilters(),
    signIns: emptyInternshipFilters(),
    journals: emptyInternshipFilters(),
    reports: emptyInternshipFilters(),
    delays: emptyInternshipFilters(),
    scores: emptyInternshipFilters(),
    archiveMaterials: emptyInternshipFilters(),
    insurances: emptyInternshipFilters(),
    safetyLetters: emptyInternshipFilters(),
  },
  lists: {
    arrangements: emptyPagedList(),
    plans: emptyPagedList(),
    applications: emptyPagedList(),
    pairs: emptyPagedList(),
    signIns: emptyPagedList(),
    journals: emptyPagedList(),
    reports: emptyPagedList(),
    delays: emptyPagedList(),
    scores: emptyPagedList(),
    archiveMaterials: emptyPagedList(),
    insurances: emptyPagedList(),
    safetyLetters: emptyPagedList(),
  },
});

const openWindows = reactive([]);

const isLoggedIn = computed(() => Boolean(permissionState.context.account_id));
const currentRoleType = computed(() => permissionState.context.role_type || '');
const isStudentRole = computed(() => currentRoleType.value === 'student');
const isTeacherRole = computed(() => currentRoleType.value === 'teacher');
const isAdminRole = computed(() => ['super_admin', 'school_admin', 'college_admin', 'profession_admin'].includes(currentRoleType.value));
const visibleModules = computed(() => modules.filter(canShowModule));
const visibleWindows = computed(() => openWindows.filter(win => !win.minimized));
const canManageConfig = computed(() => hasPermission('config:manage') && ['super_admin', 'school_admin'].includes(permissionState.context.role_type));
const canManageInternship = computed(() => hasPermission('internship:manage'));
const canManageInternshipPlan = computed(() => hasPermission('internship:plan') && isAdminRole.value);
const canApproveInternship = computed(() => hasPermission('internship:approve') && !isStudentRole.value);
const statReports = [
  { key: 'overview', name: '实习总览', description: '实习安排、申请、指导关系和过程材料汇总。', icon: ChartColumn },
  { key: 'department', name: '学院统计', description: '按学院统计学生参与、审核进度和成绩分布。', icon: Building2 },
  { key: 'profession', name: '专业统计', description: '按专业统计实习覆盖、提交进度和材料归档。', icon: GraduationCap },
  { key: 'teacher', name: '指导统计', description: '按指导关系统计教师指导数量和评阅进度。', icon: UsersRound },
  { key: 'student', name: '学生过程统计', description: '查看学生申请、签到、日志、报告、成绩的过程状态。', icon: UserRound },
  { key: 'archive', name: '归档材料统计', description: '统计保险、安全承诺和报告归档材料完整性。', icon: FolderOpen },
];
const currentStatReport = computed(() => statReports.find(item => item.key === statState.report) || statReports[0]);
const statCardIcons = [UserRound, ClipboardList, CheckCircle2, UsersRound, GraduationCap, MapPin];
const guideModuleOptions = computed(() => modules.map(item => ({
  label: item.name,
  value: item.id,
})));
const statCards = computed(() => {
  const cards = statState.cards.length ? statState.cards : [
    { name: '实习安排', value: internshipState.overview.arrangements || 0, desc: '可见数据内安排数量' },
    { name: '待审申请', value: internshipState.overview.applications_waiting || 0, desc: '等待审核的申请' },
    { name: '指导关系', value: internshipState.overview.active_pairs || 0, desc: '有效师生指导关系' },
    { name: '今日日志', value: internshipState.overview.journals_waiting || 0, desc: '待评阅实习日志' },
  ];
  return cards.map((item, index) => ({
    ...item,
    icon: statCardIcons[index % statCardIcons.length],
  }));
});
const currentStatColumns = computed(() => statState.columns || []);
const messageGroups = computed(() => groupMessagesByDay(messageState.items));
const messageUnreadCount = computed(() => Number(messageState.summary.unread || 0));
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
  .filter(item => !item.permission || hasPermission(item.permission))
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
const schoolDataText = computed(() => {
  if (!isLoggedIn.value) {
    return '未登录';
  }
  return permissionState.context.school_name || permissionState.context.school?.school_name || '成都锦城学院';
});
const roleText = computed(() => permissionState.context.role_name || roleTypeNames[permissionState.context.role_type] || permissionState.context.role_type || permissionState.context.role_id || '-');
const loginSchoolName = computed(() => loginPageState.school_name || '成都锦城学院');
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

function canShowModule(module) {
  if (module.id === 'profile') {
    return isLoggedIn.value;
  }
  if (!hasPermission(module.viewPermission)) {
    return false;
  }
  if (module.adminOnly || module.id === 'config') {
    return ['super_admin', 'school_admin'].includes(currentRoleType.value);
  }
  if (['training', 'lab'].includes(module.id)) {
    return isAdminRole.value;
  }
  if (module.id === 'internship') {
    return ['student', 'teacher', 'super_admin', 'school_admin', 'college_admin', 'profession_admin'].includes(currentRoleType.value);
  }
  return true;
}

const internshipOverviewCards = computed(() => [
  { name: '实习安排', value: internshipState.overview.arrangements || 0, theme: 'primary', icon: CalendarCheck },
  { name: '待审申请', value: internshipState.overview.applications_waiting || 0, theme: 'amber', icon: ClipboardList },
  { name: '指导关系', value: internshipState.overview.active_pairs || 0, theme: 'green', icon: UsersRound },
  { name: '待评日志', value: internshipState.overview.journals_waiting || 0, theme: 'teal', icon: FileClock },
  { name: '待评报告', value: internshipState.overview.reports_waiting || 0, theme: 'primary', icon: FileText },
  { name: '今日签到', value: internshipState.overview.today_sign_ins || 0, theme: 'green', icon: MapPin },
]);
const internshipOverviewTabs = computed(() => {
  const tabs = [
    { key: 'metrics', name: '数据概览', count: `${internshipOverviewCards.value.length} 项` },
  ];
  if (isStudentRole.value) {
    tabs.push({
      key: 'applications',
      name: '我的申请',
      count: studentPanelList('applications').pagination.total || 0,
    });
    return tabs;
  }
  tabs.push(
    {
      key: 'arrangements',
      name: '近期安排',
      count: internshipState.lists.arrangements.pagination.total || 0,
    },
    {
      key: 'applications',
      name: '待处理申请',
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
  arrangements: {
    listKey: 'arrangements',
    filename: '实习安排',
    filters: internshipListFilters('arrangements', ['semester', 'grade_id', 'dep_id', 'profession_id', 'type', 'organize_mode', 'status', 'keyword']),
    columns: [
      { prop: 'title', label: '实习安排', minWidth: 180 },
      // 暂时隐藏学期列，后续需要时恢复。
      // { prop: 'semester', label: '学期', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { key: 'type', label: '类型', width: 130, formatter: row => arrangementTypeText(row.type) },
      { key: 'mode', label: '组织方式', width: 100, formatter: row => organizeModeText(row.organize_mode) },
      { key: 'date', label: '时间', minWidth: 170, formatter: row => `${row.start_date || '-'} 至 ${row.end_date || '-'}` },
      { key: 'scope', label: '范围', minWidth: 190, formatter: row => arrangementScopeText(row) },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
    ],
  },
  applications: {
    listKey: 'applications',
    filename: '实习申请',
    filters: internshipListFilters('applications', ['semester', 'grade_id', 'dep_id', 'profession_id', 'status', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'arrangement_title', label: '实习安排', minWidth: 190 },
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
    filters: internshipListFilters('plans', ['semester', 'dep_id', 'status', 'keyword']),
    columns: [
      // 暂时隐藏学期列，后续需要时恢复。
      // { prop: 'semester', label: '学期', width: 130 },
      { prop: 'dep_name', label: '学院', minWidth: 150 },
      { key: 'content', label: '计划内容', minWidth: 260, formatter: row => planContentText(row.plan_content) },
      { prop: 'submitter_name', label: '提交人', width: 120 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
      { prop: 'created_at', label: '提交时间', width: 168 },
    ],
  },
  pairs: {
    listKey: 'pairs',
    filename: '指导关系',
    filters: internshipListFilters('pairs', ['semester', 'grade_id', 'dep_id', 'profession_id', 'status', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 120 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'teacher_name', label: '指导教师', width: 120 },
      { prop: 'arrangement_title', label: '实习安排', minWidth: 190 },
      { prop: 'status', label: '状态', width: 90, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) },
      { prop: 'created_at', label: '创建时间', width: 168 },
    ],
  },
  signIns: {
    listKey: 'signIns',
    filename: '签到记录',
    filters: internshipListFilters('signIns', ['semester', 'grade_id', 'dep_id', 'profession_id', 'arrangement_id', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'arrangement_title', label: '实习安排', minWidth: 180 },
      { prop: 'date', label: '日期', width: 110 },
      { prop: 'sign_time', label: '签到时间', width: 168 },
      { prop: 'location', label: '地点', minWidth: 150 },
      { key: 'sign_type', label: '方式', width: 90, formatter: row => signTypeText(row.sign_type) },
    ],
  },
  journals: {
    listKey: 'journals',
    filename: '实习日志',
    filters: internshipListFilters('journals', ['semester', 'grade_id', 'dep_id', 'profession_id', 'arrangement_id', 'status', 'keyword']),
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
    filters: internshipListFilters('reports', ['semester', 'grade_id', 'dep_id', 'profession_id', 'arrangement_id', 'status', 'keyword']),
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
    filters: internshipListFilters('delays', ['semester', 'grade_id', 'dep_id', 'profession_id', 'arrangement_id', 'config_key', 'status', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'arrangement_title', label: '实习安排', minWidth: 190 },
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
    filters: internshipListFilters('scores', ['semester', 'grade_id', 'dep_id', 'profession_id', 'arrangement_id', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'arrangement_title', label: '实习安排', minWidth: 180 },
      { prop: 'sign_in_score', label: '签到', width: 80 },
      { prop: 'journal_score', label: '日志', width: 80 },
      { prop: 'report_score', label: '报告', width: 80 },
      { prop: 'enterprise_score', label: '企业', width: 80 },
      { prop: 'final_score', label: '总评', width: 90 },
      { prop: 'teacher_name', label: '评分人', width: 110 },
    ],
  },
  archiveMaterials: {
    listKey: 'archiveMaterials',
    filename: '归档材料',
    filters: internshipListFilters('archiveMaterials', ['semester', 'grade_id', 'dep_id', 'profession_id', 'arrangement_id', 'archive_status', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'dep_name', label: '学院', minWidth: 130 },
      { prop: 'profession_name', label: '专业', minWidth: 130 },
      { prop: 'arrangement_title', label: '实习安排', minWidth: 190 },
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
      { prop: 'insurance_status', label: '保险单', width: 95 },
      { prop: 'missing_materials', label: '缺失材料', minWidth: 240 },
      { prop: 'archive_status_text', label: '归档状态', width: 100 },
    ],
  },
  insurances: {
    listKey: 'insurances',
    filename: '保险记录',
    filters: internshipListFilters('insurances', ['semester', 'grade_id', 'dep_id', 'profession_id', 'arrangement_id', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'arrangement_title', label: '实习安排', minWidth: 180 },
      { prop: 'insurance_company', label: '保险公司', minWidth: 160 },
      { prop: 'policy_number', label: '保单号', minWidth: 150 },
      { prop: 'start_date', label: '开始', width: 110 },
      { prop: 'end_date', label: '结束', width: 110 },
    ],
  },
  safetyLetters: {
    listKey: 'safetyLetters',
    filename: '安全承诺',
    filters: internshipListFilters('safetyLetters', ['semester', 'grade_id', 'dep_id', 'profession_id', 'arrangement_id', 'keyword']),
    columns: [
      { prop: 'student_name', label: '学生', width: 110 },
      { prop: 'student_num', label: '学号', width: 130 },
      { prop: 'grade_name', label: '届次', width: 100 },
      { prop: 'arrangement_title', label: '实习安排', minWidth: 180 },
      { prop: 'signed_at', label: '签署时间', minWidth: 160 },
      { key: 'status', label: '状态', width: 90, formatter: row => statusText(row.status) },
    ],
  },
}));

function internshipRolePanelName(key) {
  if (!isStudentRole.value) {
    return internshipSidebarItems.find(item => item.key === key)?.name || '实习管理';
  }

  const names = {
    overview: '我的实习',
    arrangements: '可申请安排',
    plans: '实习计划',
    applications: '我的申请',
    pairs: '指导教师',
    signIns: '我的签到',
    journals: '我的日志',
    reports: '我的报告',
    delays: '我的延期',
    scores: '我的成绩',
    documents: '我的材料',
  };
  return names[key] || '我的实习';
}

function internshipListFilters(listKey, adminKeys) {
  if (isStudentRole.value) {
    return [];
  }
  if (isTeacherRole.value) {
    return listKey === 'arrangements' ? [] : internshipFilters(['grade_id', 'student_keyword']);
  }
  return internshipFilters(adminKeys);
}

function hasInternshipToolbarActions(panel) {
  if (panel === 'arrangements') {
    return canManageInternship.value;
  }
  if (panel === 'plans') {
    return canManageInternshipPlan.value;
  }
  if (panel === 'scores') {
    return canManageInternship.value;
  }
  return false;
}

function isStudentOwnPanel(panel) {
  return isStudentRole.value && ['arrangements', 'applications', 'pairs', 'signIns', 'journals', 'reports', 'delays', 'scores'].includes(panel);
}

function studentPanelList(panel) {
  return internshipState.lists[panel] || emptyPagedList();
}

function studentTimelineEntity(panel) {
  const map = {
    applications: 'application',
    journals: 'journal',
    reports: 'report',
    delays: 'delay',
  };
  return map[panel] || '';
}

function studentPanelMeta(panel) {
  const metas = {
    arrangements: {
      title: '可申请安排',
      description: '展示与本人学院、专业匹配的实习安排。',
      emptyText: '暂无可申请实习安排',
    },
    applications: {
      title: '我的申请',
      description: '只展示当前学生本人的实习申请和审核状态。',
      emptyText: '暂无实习申请',
    },
    pairs: {
      title: '指导教师',
      description: '展示当前学生本人的指导关系。',
      emptyText: '暂无指导关系',
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
      description: '展示当前学生本人的实习成绩。',
      emptyText: '暂无成绩记录',
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
      { key: 'type', label: '类型', formatter: row => arrangementTypeText(row.type) },
      { key: 'organize_mode', label: '组织方式', formatter: row => organizeModeText(row.organize_mode) },
      { key: 'date', label: '时间', formatter: row => `${row.start_date || '-'} 至 ${row.end_date || '-'}` },
      { key: 'location', label: '地点' },
    ],
    applications: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习安排' },
      { key: 'teacher_status', label: '教师审核', formatter: row => statusText(row.teacher_status) },
      { key: 'admin_status', label: '管理审核', formatter: row => statusText(row.admin_status) },
      { key: 'created_at', label: '提交时间' },
    ],
    pairs: [
      { key: 'grade_name', label: '届次' },
      { key: 'teacher_name', label: '指导教师' },
      { key: 'arrangement_title', label: '实习安排' },
      { key: 'created_at', label: '创建时间' },
    ],
    signIns: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习安排' },
      { key: 'date', label: '日期' },
      { key: 'sign_time', label: '签到时间' },
      { key: 'sign_type', label: '方式', formatter: row => signTypeText(row.sign_type) },
      { key: 'location', label: '地点' },
    ],
    journals: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习安排' },
      { key: 'date', label: '日期' },
      { key: 'content', label: '内容' },
    ],
    reports: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习安排' },
      { key: 'submitted_at', label: '提交时间' },
      { key: 'content', label: '内容' },
    ],
    delays: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习安排' },
      { key: 'config_key', label: '延期类型', formatter: row => delayConfigText(row.config_key) },
      { key: 'requested_date', label: '申请延期至' },
      { key: 'reason', label: '原因' },
      { key: 'created_at', label: '申请时间' },
    ],
    scores: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习安排' },
      { key: 'sign_in_score', label: '签到' },
      { key: 'journal_score', label: '日志' },
      { key: 'report_score', label: '报告' },
      { key: 'enterprise_score', label: '企业' },
      { key: 'final_score', label: '总评' },
    ],
    archiveMaterials: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习安排' },
      { key: 'arrangement_type_text', label: '实习类型' },
      // 暂时隐藏学期字段，后续需要时恢复。
      // { key: 'semester', label: '学期' },
      { key: 'material_progress', label: '归档进度' },
      { key: 'missing_materials', label: '缺失材料' },
    ],
    insurances: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习安排' },
      { key: 'insurance_company', label: '保险公司' },
      { key: 'policy_number', label: '保单号' },
      { key: 'start_date', label: '开始日期' },
      { key: 'end_date', label: '结束日期' },
    ],
    safetyLetters: [
      { key: 'grade_name', label: '届次' },
      { key: 'arrangement_title', label: '实习安排' },
      { key: 'signed_at', label: '签署时间' },
    ],
  };
  return fields[panel] || [];
}

function sidebarItems(win) {
  if (['message', 'doc', 'templateLib', 'exportTask'].includes(win.module.id)) {
    return [];
  }
  if (win.module.id === 'userManage' || archiveManageModules[win.module.id]) {
    return [];
  }
  if (win.module.id === 'file') {
    return [{ key: 'fileManage', name: '文件列表' }];
  }
  if (win.module.id === 'internship') {
    return visibleInternshipSidebarItems.value;
  }
  if (win.module.id === 'config') {
    return [
      { key: 'menuManage', name: '菜单管理' },
      { key: 'roleMenus', name: '角色权限' },
      { key: 'organizationScope', name: '组织范围' },
      { key: 'operationGuides', name: '操作说明' },
      { key: 'wechatProxy', name: '企业微信应用' },
    ];
  }
  if (win.module.id === 'stat') {
    return statReports;
  }

  return [
    { key: 'overview', name: '工作台' },
    { key: 'archive', name: '基础档案' },
    { key: 'workflow', name: '流程配置' },
  ];
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

function groupMessagesByDay(items) {
  const groups = new Map();
  (items || []).forEach((item) => {
    const key = messageDateKey(item.created_at);
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

function renderClock() {
  const now = new Date();
  clock.value = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
}

function openModule(module) {
  openModuleWindow(module, { reuse: true });
}

function ensureDefaultWindow() {
  const defaultModule = visibleModules.value.find(item => item.id !== 'profile');
  if (!openWindows.length && defaultModule) {
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

function fallbackGuideContent(win) {
  const common = [
    {
      title: '窗口操作',
      lines: ['窗口可拖动、最小化、关闭，也可以拖拽边缘调整大小。', '底部任务栏图标用于恢复已最小化窗口。'],
    },
  ];
  const map = {
    internship: [
      { title: '实习业务', lines: ['学生端只显示本人申请、签到、日志、报告和材料。', '教师端默认按指导关系查看学生，审核类操作会进入确认弹窗并记录流程。', '管理员端通过列表筛选、记录查看和归档材料完成过程监管。'] },
    ],
    config: [
      { title: '系统配置', lines: ['菜单管理用于维护主菜单、业务菜单、列表和按钮节点。', '角色权限按菜单树授权，按钮节点用于控制页面内操作。', '组织范围用于配置学院、专业、班级、企业等数据边界。'] },
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
    '<section><h3>流程规则</h3><p>学生仅处理本人业务，教师按指导关系处理，管理员按学院、专业等组织范围处理。</p></section>',
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

function openModuleWindow(module, options = {}) {
  const existing = options.reuse ? openWindows.find(win => win.module.id === module.id) : null;
  if (existing) {
    existing.minimized = false;
    focusWindow(existing.id);
    if (module.id === 'stat') {
      statState.report = statReports.some(item => item.key === existing.panel) ? existing.panel : 'overview';
      loadStats(statState.pagination.page || 1);
    }
    if (module.id === 'config' || module.id === 'userManage' || archiveManageModules[module.id]) {
      activateWindowPanel(existing, existing.panel);
    }
    if (module.id === 'message') {
      loadMessages(messageState.pagination.page || 1);
    }
    return;
  }

  const index = openWindows.length;
  const win = {
    id: `${module.id}-${Date.now()}`,
    module,
    panel: module.defaultPanel || 'overview',
    minimized: false,
    left: 160 + index * 28,
    top: 60 + index * 24,
    width: 1120,
    height: 680,
    zIndex: ++zIndexSeed.value,
  };
  openWindows.push(win);
  focusedWindowId.value = win.id;
  if (module.id === 'internship') {
    loadInternshipPanel(win.panel);
  }
  if (module.id === 'stat') {
    statState.report = win.panel;
    loadStats(1);
  }
  if (module.id === 'config' || module.id === 'userManage' || archiveManageModules[module.id]) {
    activateWindowPanel(win, win.panel);
  }
  if (module.id === 'message') {
    loadMessages(1);
  }
}

function openProfile(section = '') {
  const profileModule = modules.find(item => item.id === 'profile');
  if (profileModule) {
    openModuleWindow(profileModule, { reuse: true });
  }
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
      const module = visibleModules.value.find(item => item.id === moduleId);
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
        const module = visibleModules.value.find(item => item.id === moduleId);
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

function setWindowPanel(windowId, panel) {
  const win = openWindows.find(item => item.id === windowId);
  activateWindowPanel(win, panel);
}

function activateWindowPanel(win, panel) {
  if (!win) {
    return;
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
  if (win.module.id === 'file' && panel === 'fileManage') {
    loadFiles();
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
}

function focusLogin() {
  loginNameInput.value?.focus();
}

async function submitLogin() {
  loginState.loading = true;
  loginState.message = '';
  try {
    await loginApi({
      login_name: loginForm.login_name,
      password: loginForm.password,
      client: 'WEB',
    });
    await load();
    await loadProfile();
    await loadProxy();
    await loadMessageSummary();
    ensureDefaultWindow();
    scheduleDefaultWindow();
    loadAdminFoundation();
  } catch (error) {
    loginState.message = error.message;
  } finally {
    loginState.loading = false;
  }
}

async function submitLogout() {
  loginState.loading = true;
  loginState.message = '';
  try {
    await logoutApi();
    resetAdminState();
    resetProfileState();
    resetMessageState();
    openWindows.splice(0);
    focusedWindowId.value = null;
    await load();
    await loadLoginPageSettings();
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
    visible: 'true',
    status: 'enabled',
  };
}

function applyMenusData(data) {
  adminState.menus = normalizeMenuTree(data.menus || []);
  adminState.menu.items = data.items || [];
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
    mobile: row.mobile || '',
    email: row.email || '',
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

function canMaintainUser(row) {
  return permissionState.context.role_type === 'super_admin' || row.role_type !== 'super_admin';
}

function openUserDialog(row = null) {
  const roles = manageableUserRoles();
  userAdminState.editing = emptyUserForm(row || {});
  if (!userAdminState.editing.role_id && roles.length) {
    userAdminState.editing.role_id = roles[0].id;
  }
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
    await saveAdminAccount({
      ...userAdminState.editing,
      id: userAdminState.editing.id || null,
      role_id: Number(userAdminState.editing.role_id || 0),
    });
    userAdminState.message = '已保存';
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
    password: 'admin123456',
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
  if (!canManageConfig.value || userAdminState.loading) {
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
    visible: row.visible || 'true',
    status: row.status || 'enabled',
  };
  adminState.menu.message = '';
}

async function saveMenuConfig() {
  adminState.menu.loading = true;
  adminState.menu.message = '';
  try {
    const payload = {
      ...adminState.menu.editing,
      sort: Number(adminState.menu.editing.sort || 0),
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
    adminState.menus = normalizeMenuTree(menusData.menus || []);
    adminState.menu.items = menusData.items || [];
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

async function loadArchiveItems(type = 'department') {
  const state = archiveStateByType(type);
  if (!canManageConfig.value || state.loading) {
    return;
  }

  state.loading = true;
  state.message = '';
  try {
    const data = await fetchArchiveList(type);
    state.items = data.items || [];
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
    const data = await saveArchiveItem(payload);
    state.items = data.items || [];
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
  if (!state.selected || !window.confirm('确认删除当前档案？')) {
    return;
  }

  state.loading = true;
  state.message = '';
  try {
    const definition = archiveDefinition(type);
    const data = await deleteArchiveItem({
      type,
      id: state.selected[definition.idField],
    });
    state.items = data.items || [];
    state.editing = emptyArchiveItem(type);
    state.selected = null;
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
    const data = await importArchiveExcel(type, file);
    state.items = data.items || [];
    state.selected = null;
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

function openFileUrl(url) {
  if (!url) {
    return;
  }
  window.open(url, '_blank', 'noopener');
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
    const data = await fetchInternshipStats(statQueryParams(page));
    statState.cards = data.cards || [];
    statState.columns = data.columns || [];
    statState.rows = data.rows || [];
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
    keyword: '',
  });
  loadStats(1);
}

function payloadText(payload) {
  if (payload === null || payload === undefined || payload === '') {
    return '-';
  }
  if (typeof payload === 'string') {
    return payload;
  }
  return JSON.stringify(payload);
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
    arrangements: [],
    report_templates: [],
    review_rules: defaultInternshipReviewRules,
  };
}

function emptyInternshipFilters() {
  return {
    keyword: '',
    semester: '',
    dep_id: '',
    profession_id: '',
    grade_id: '',
    arrangement_id: '',
    config_key: '',
    status: '',
    archive_status: '',
    type: '',
    organize_mode: '',
  };
}

function emptyArrangementForm() {
  return {
    title: '',
    semester: '2025-2026-2',
    grade_id: null,
    base_id: null,
    dep_id: null,
    profession_id: null,
    type: 'major_external',
    organize_mode: 'centralized',
    start_date: '',
    end_date: '',
    location: '',
    status: 'enabled',
  };
}

function emptyPlanForm() {
  return {
    semester: '2025-2026-2',
    dep_id: null,
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

function internshipFilters(keys) {
  const visibleKeys = keys.filter(key => key !== 'semester'); // 暂时隐藏学期筛选，后续需要时恢复。
  const definitions = {
    keyword: { key: 'keyword', label: '关键词', placeholder: '学生、学号、教师、标题' },
    student_keyword: { key: 'keyword', label: '学生', placeholder: '姓名或学号' },
    semester: { key: 'semester', label: '学期', type: 'select', options: semesterOptions() },
    dep_id: { key: 'dep_id', label: '学院', type: 'select', options: optionItems(internshipState.options.departments, 'dep_id', 'dep_name') },
    profession_id: { key: 'profession_id', label: '专业', type: 'select', options: optionItems(internshipState.options.professions, 'profession_id', 'profession_name') },
    grade_id: { key: 'grade_id', label: '届次', type: 'select', options: optionItems(internshipState.options.grades, 'grade_id', 'grade_name') },
    arrangement_id: { key: 'arrangement_id', label: '实习安排', type: 'select', options: optionItems(internshipState.options.arrangements, 'id', 'title') },
    config_key: { key: 'config_key', label: '延期类型', type: 'select', options: delayConfigOptions() },
    status: { key: 'status', label: '状态', type: 'select', options: statusOptions() },
    archive_status: { key: 'archive_status', label: '归档状态', type: 'select', options: archiveStatusOptions() },
    type: { key: 'type', label: '类型', type: 'select', options: internshipState.options.types.map(value => ({ value, label: arrangementTypeText(value) })) },
    organize_mode: { key: 'organize_mode', label: '组织方式', type: 'select', options: internshipState.options.organize_modes.map(value => ({ value, label: organizeModeText(value) })) },
  };
  return visibleKeys.map(key => definitions[key]).filter(Boolean);
}

function optionItems(items, valueKey, labelKey) {
  return (items || []).map(item => ({
    value: item[valueKey],
    label: item[labelKey] || item[valueKey],
  }));
}

function arrangementSelectedProfession() {
  const professionId = Number(internshipState.arrangementForm.profession_id || 0);
  return internshipState.options.professions.find(item => Number(item.profession_id) === professionId) || null;
}

function firstArrangementGrade() {
  return internshipState.options.grades[0] || null;
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

function normalizeArrangementCascade() {
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
}

function normalizeArrangementProfession() {
  const options = arrangementProfessionOptions();
  const current = Number(internshipState.arrangementForm.profession_id || 0);
  if (current && options.some(item => Number(item.profession_id) === current)) {
    return;
  }
  internshipState.arrangementForm.profession_id = options[0]?.profession_id || null;
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
  return ['draft', 'wait', 'accept', 'modify', 'refuse', 'enabled', 'disabled', 'active', 'removed'].map(value => ({
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

function delayConfigOptions() {
  return [
    { value: 'journal_deadline', label: '日志截止' },
    { value: 'report_deadline', label: '报告截止' },
  ];
}

function delayConfigText(value) {
  return delayConfigOptions().find(item => item.value === value)?.label || value || '-';
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
  const roleType = permissionState.context.role_type;
  const scopes = permissionState.context.organization_scopes || [];
  const firstScope = scopes.find(item => item.dep_id || item.profession_id) || {};

  if (roleType === 'college_admin') {
    filters.dep_id = firstScope.dep_id ? Number(firstScope.dep_id) : internshipState.options.departments[0]?.dep_id || '';
  }
  if (roleType === 'profession_admin') {
    filters.profession_id = firstScope.profession_id ? Number(firstScope.profession_id) : internshipState.options.professions[0]?.profession_id || '';
    filters.dep_id = firstScope.dep_id ? Number(firstScope.dep_id) : internshipState.options.professions.find(item => item.profession_id === filters.profession_id)?.dep_id || '';
  }
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
    internshipState.filters[key] = current;
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
  internshipState.filters[listKey][event.key] = event.value ?? '';
}

function resetInternshipFilters(listKey) {
  internshipState.filters[listKey] = defaultScopedFilters();
  loadInternshipPanel(listKey, 1);
}

function openArrangementDialog() {
  const defaults = defaultScopedFilters();
  const defaultProfession = internshipState.options.professions.find(item => Number(item.profession_id) === Number(defaults.profession_id || 0)) || null;
  const defaultDepId = defaults.dep_id || defaultProfession?.dep_id || null;
  const defaultGrade = firstArrangementGrade();
  internshipState.arrangementForm = {
    ...emptyArrangementForm(),
    base_id: internshipState.options.bases[0]?.id || null,
    grade_id: defaultProfession?.grade_id || defaultGrade?.grade_id || null,
    dep_id: defaultDepId,
    profession_id: defaultProfession?.profession_id || null,
  };
  normalizeArrangementCascade();
  internshipState.dialog = {
    ...emptyOperationDialog(),
    type: 'arrangement',
    title: '新增实习安排',
  };
}

function openPlanDialog() {
  internshipState.planForm = {
    ...emptyPlanForm(),
    dep_id: defaultScopedFilters().dep_id || internshipState.options.departments[0]?.dep_id || null,
  };
  internshipState.dialog = {
    ...emptyOperationDialog(),
    type: 'plan',
    title: '新增实习计划',
  };
}

function openScoreDialog(row = null) {
  internshipState.scoreForm = emptyScoreForm();
  if (row) {
    internshipState.scoreForm.pair_id = row.id;
    internshipState.scoreForm.student_id = row.student_id;
    internshipState.scoreForm.arrangement_id = row.arrangement_id;
  }
  if (!internshipState.lists.pairs.items.length) {
    loadInternshipPanel('pairs', 1);
  }
  internshipState.dialog = {
    ...emptyOperationDialog(),
    type: 'score',
    title: '录入实习成绩',
  };
}

function openReviewDialog(entity, row, status) {
  if (!canReviewRow(row, entity)) {
    internshipState.message = '仅待审核数据可处理';
    return;
  }
  const actionText = status === 'accept' ? '通过' : '退回';
  internshipState.dialog = {
    ...emptyOperationDialog(),
    type: 'review',
    title: `${actionText}${reviewEntityName(entity)}`,
    description: `请确认是否${actionText}「${row.title || row.arrangement_title || row.student_name || row.id}」。`,
    entity,
    status,
    row,
    reason: status === 'accept' ? '同意' : '',
  };
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
    description: `此操作会新增审核记录，并将「${row.title || row.arrangement_title || row.student_name || row.id}」改为待学生重新提交的修改状态。`,
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

function closeInternshipDialog() {
  internshipState.dialog = emptyOperationDialog();
}

async function confirmInternshipDialog() {
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
    if (internshipState.dialog.entity === 'plan') {
      await reviewPlan(internshipState.dialog.row, internshipState.dialog.status, internshipState.dialog.reason);
      return;
    }
    if (internshipState.dialog.entity === 'delay') {
      await reviewDelay(internshipState.dialog.row, internshipState.dialog.status, internshipState.dialog.reason);
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
    application: '实习申请',
    journal: '实习日志',
    report: '实习报告',
    plan: '实习计划',
    delay: '延期申请',
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
    return `${prefix}：${statusText(cycle.record.from_status)} -> ${statusText(cycle.record.to_status)}`;
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
    return `${workflowActionText(branch.record.action)}：${statusText(branch.record.from_status)} -> ${statusText(branch.record.to_status)}`;
  }
  const review = branch.review || branch.reviews?.[0];
  return `审核：${statusText(review?.status)}`;
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
    '提交实习申请',
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
    return canManageInternshipPlan.value;
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

function canRequestModification(row, entity) {
  if (!row || row.status !== 'accept') {
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
    } else if (panel === 'plans') {
      setPagedList('plans', await fetchInternshipPlans(params('plans')));
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
    } else if (panel === 'delays') {
      setPagedList('delays', await fetchInternshipDelays(params('delays')));
    } else if (panel === 'scores') {
      const [scores, pairs] = await Promise.all([
        fetchInternshipScores(params('scores')),
        fetchInternshipPairs({ page: 1, page_size: 100 }),
      ]);
      setPagedList('scores', scores);
      setPagedList('pairs', pairs);
    } else if (panel === 'documents') {
      setPagedList('archiveMaterials', await fetchInternshipArchiveMaterials(params('archiveMaterials')));
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

async function saveArrangement() {
  if (!canManageInternship.value) {
    return;
  }

  internshipState.loading = true;
  internshipState.message = '';
  internshipState.savedMessage = '';
  try {
    await saveInternshipArrangement({
      ...internshipState.arrangementForm,
      name: internshipState.arrangementForm.title,
      base_id: internshipState.arrangementForm.base_id || null,
      dep_id: internshipState.arrangementForm.dep_id || null,
      profession_id: internshipState.arrangementForm.profession_id || null,
    });
    internshipState.savedMessage = '已保存';
    internshipState.arrangementForm = {
      ...emptyArrangementForm(),
      base_id: internshipState.arrangementForm.base_id,
      grade_id: internshipState.arrangementForm.grade_id,
      dep_id: internshipState.arrangementForm.dep_id,
      profession_id: internshipState.arrangementForm.profession_id,
    };
    closeInternshipDialog();
    await loadInternshipPanel('arrangements');
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

async function reviewApplication(row, status, opinion = '') {
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
  if (!canManageInternshipPlan.value) {
    return;
  }
  if (!internshipState.planForm.dep_id) {
    internshipState.message = '请选择学院';
    return;
  }

  internshipState.loading = true;
  internshipState.message = '';
  try {
    await saveInternshipPlan({
      dep_id: internshipState.planForm.dep_id,
      semester: internshipState.planForm.semester,
      plan_content: {
        content: internshipState.planForm.content,
      },
      status: internshipState.planForm.status,
    });
    internshipState.planForm = emptyPlanForm();
    closeInternshipDialog();
    await loadInternshipPanel('plans');
  } catch (error) {
    internshipState.message = error.message;
  } finally {
    internshipState.loading = false;
  }
}

async function reviewPlan(row, status, opinion = '') {
  internshipState.loading = true;
  internshipState.message = '';
  try {
    await reviewInternshipPlan({
      id: row.id,
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

async function reviewStudentWork(type, row, status, opinion = '') {
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
    const panel = entity === 'application' ? 'applications' : `${entity}s`;
    await loadInternshipPanel(panel);
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
  loadInternshipPanel('scores');
}

function selectScorePair(pairId) {
  const pair = internshipState.lists.pairs.items.find(item => item.id === pairId);
  if (!pair) {
    return;
  }
  internshipState.scoreForm.student_id = pair.student_id;
  internshipState.scoreForm.arrangement_id = pair.arrangement_id;
}

async function saveScore() {
  if (!canManageInternship.value || !internshipState.scoreForm.student_id || !internshipState.scoreForm.arrangement_id) {
    internshipState.message = '请先选择学生和实习安排';
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
    disabled: '停用',
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
  if (['accept', 'enabled', 'active', 'signed', 'complete', 'archived', 'confirmed', 'published'].includes(value)) {
    return 'success';
  }
  if (['wait', 'pending', 'draft'].includes(value)) {
    return 'warning';
  }
  if (['modify', 'removed', 'disabled', 'not_required'].includes(value)) {
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
    password: 'admin123456',
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
  userAdminState.message = '';
  Object.entries(archiveStates).forEach(([type, state]) => {
    state.items = [];
    state.editing = emptyArchiveItem(type);
    state.selected = null;
    state.dialogVisible = false;
    state.loading = false;
    state.message = '';
  });
  fileState.items = [];
  fileState.message = '';
  fileState.pagination.page = 1;
  fileState.pagination.total = 0;
  resetInternshipState();
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
  loginPageState.school_code = data.school_code || '2184';
  loginPageState.school_name = data.school_name || '成都锦城学院';
  loginPageState.login_background_url = data.login_background_url || '';
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
  if (!isLoggedIn.value) {
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
  } else {
    resetMessageState();
  }
});

watch(visibleModules, () => {
  if (isLoggedIn.value) {
    ensureDefaultWindow();
    handleHashNavigation();
  }
});

onMounted(async () => {
  window.addEventListener('hashchange', handleHashNavigation);
  renderClock();
  setInterval(renderClock, 30000);
  await loadLoginPageSettings();
  await load();
  await loadProfile();
  await loadProxy();
  await loadMessageSummary();
  await nextTick();
  ensureDefaultWindow();
  handleHashNavigation();
  scheduleDefaultWindow();
  loadAdminFoundation();
});

onBeforeUnmount(() => {
  window.removeEventListener('hashchange', handleHashNavigation);
});
</script>
