<template>
  <main class="mobile-shell">
    <header class="mobile-top">
      <div class="mobile-history-actions">
        <button aria-label="后退" title="后退" :disabled="!canGoMobileBack" @click="goMobileBack">
          <ChevronLeft :size="18" />
          <span>后退</span>
        </button>
        <button aria-label="前进" title="前进" :disabled="!canGoMobileForward" @click="goMobileForward">
          <span>前进</span>
          <ChevronRight :size="18" />
        </button>
      </div>
      <div class="mobile-title">
        <span>实践管理系统</span>
        <strong>{{ currentPage.title }}</strong>
      </div>
      <div class="mobile-actions">
        <button aria-label="刷新" title="刷新" :disabled="state.loading" @click="refreshMobilePage">
          <RefreshCw :size="18" />
        </button>
        <button v-if="isLoggedIn" aria-label="退出" title="退出" :disabled="loginState.loading" @click="submitLogout">
          <LogOut :size="18" />
        </button>
      </div>
    </header>

    <section class="context-strip">
      <div>
        <small>当前角色</small>
        <strong>{{ roleText }}</strong>
      </div>
      <div>
        <small>数据范围</small>
        <strong>{{ scopeText }}</strong>
      </div>
    </section>

    <van-notice-bar
      v-if="state.error"
      color="#8a5a00"
      background="#fff3d8"
      left-icon="warning-o"
      :text="state.error"
    />

    <section class="page-content">
      <section v-if="!isLoggedIn" class="mobile-login">
        <header>
          <UserRound :size="30" />
          <div>
            <strong>账号登录</strong>
            <span>{{ schoolText }}</span>
          </div>
        </header>
        <label>
          <span>账号</span>
          <input v-model="loginForm.login_name" autocomplete="username" placeholder="admin">
        </label>
        <label>
          <span>密码</span>
          <input v-model="loginForm.password" autocomplete="current-password" placeholder="admin123456" type="password">
        </label>
        <button :disabled="loginState.loading" @click="submitLogin">
          <LogIn :size="17" />
          登录
        </button>
        <section v-if="registerState.enabled" class="mobile-register">
          <button type="button" class="mobile-secondary-button" @click="toggleRegisterForm">
            {{ registerState.open ? '收起注册' : '注册测试账号' }}
          </button>
          <div v-if="registerState.open" class="mobile-register-form">
            <label>
              <span>注册角色</span>
              <select v-model="registerForm.role_type">
                <option
                  v-for="role in registerRoleOptions"
                  :key="role.role_type"
                  :value="role.role_type"
                >
                  {{ role.name || roleNameMap[role.role_type] || role.role_type }}
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
        </section>
        <small v-if="loginState.message">{{ loginState.message }}</small>
        <small v-if="registerState.message">{{ registerState.message }}</small>
      </section>

      <template v-else-if="activeTab === 'home'">
        <section class="summary-band">
          <div v-for="item in summaries" :key="item.name">
            <strong>{{ item.value }}</strong>
            <span>{{ item.name }}</span>
          </div>
        </section>

        <section class="module-list">
          <button
            v-for="module in visibleMobileModules"
            :key="module.key"
            @click="activeTab = module.key"
          >
            <span :class="module.theme">
              <component :is="module.icon" :size="21" />
            </span>
            <div>
              <strong>{{ module.title }}</strong>
              <small>{{ module.desc }}</small>
            </div>
            <ChevronRight :size="18" />
          </button>
        </section>

        <section v-if="isStudentRole" class="student-guide-card">
          <header>
            <Route :size="20" />
            <strong>实习流程</strong>
          </header>
          <div class="student-guide-steps">
            <span v-for="step in studentFlowSteps" :key="step">{{ step }}</span>
          </div>
        </section>
      </template>

      <template v-else-if="activeTab === 'mine'">
        <section class="profile-panel">
          <UserRound :size="34" />
          <div>
            <strong>{{ userText }}</strong>
            <span>{{ schoolText }}</span>
          </div>
        </section>

        <van-cell-group inset>
          <van-cell title="姓名" :value="userText" />
          <van-cell title="登录账号" :value="accountText" />
          <van-cell title="当前角色" :value="roleDisplayText" />
          <van-cell title="所属学校" :value="schoolText" />
          <van-cell title="数据范围" :label="scopeDetailText" :value="scopeText" />
          <van-cell v-if="hasPermission('doc:view')" title="文档中心" value="查看" is-link @click="activeTab = 'doc'" />
          <van-cell v-if="hasPermission('template:view')" title="模板库" value="下载" is-link @click="activeTab = 'templateLib'" />
        </van-cell-group>

        <van-cell-group v-if="switchAccountState.items.length > 1" inset class="switch-account-group">
          <van-cell
            title="切换身份"
            :label="switchAccountState.message || '同一用户或同手机号绑定账号可快速切换'"
          />
          <van-cell
            v-for="account in switchAccountState.items"
            :key="account.id"
            :title="accountSwitchTitle(account)"
            :label="accountSwitchLabel(account)"
            :value="account.is_current ? '当前' : '切换'"
            :is-link="!account.is_current"
            @click="switchMobileAccount(account)"
          />
        </van-cell-group>
      </template>

      <template v-else-if="activeTab === 'message'">
        <section class="module-head">
          <span class="teal">
            <MessageCircle :size="25" />
          </span>
          <div>
            <h1>消息中心</h1>
            <p>按时间查看待办、审核结果和系统通知</p>
          </div>
        </section>

        <van-notice-bar
          v-if="messageState.message"
          color="#8a5a00"
          background="#fff3d8"
          left-icon="warning-o"
          :text="messageState.message"
        />

        <section class="mobile-message-actions">
          <button :class="{ active: messageState.filter === 'all' }" @click="setMobileMessageFilter('all')">
            全部
          </button>
          <button :class="{ active: messageState.filter === 'unread' }" @click="setMobileMessageFilter('unread')">
            未读 {{ messageUnreadCount }}
          </button>
          <button :disabled="messageUnreadCount <= 0 || messageState.loading" @click="markAllMobileMessagesRead">
            全部已读
          </button>
        </section>

        <section class="mobile-message-type-scroll" aria-label="消息类型">
          <button
            v-for="item in mobileMessageTypeOptions"
            :key="item.value"
            :class="{ active: messageState.type === item.value }"
            @click="setMobileMessageType(item.value)"
          >
            <span>{{ item.label }}</span>
            <strong>{{ mobileMessageTypeUnread(item.value) }}</strong>
          </button>
        </section>

        <section class="mobile-message-list">
          <template v-if="messageGroups.length">
            <div v-for="group in messageGroups" :key="group.key" class="mobile-message-day">
              <span>{{ group.label }}</span>
              <article
                v-for="item in group.items"
                :key="item.target_id"
                class="mobile-message-card"
                :class="{ unread: !item.is_read, urgent: item.level === 'urgent', own: isOwnMobileMessage(item) }"
                @click="handleMobileMessageClick(item)"
                >
                <header>
                  <em :class="item.type">{{ messageTypeText(item.type) }}</em>
                  <small>{{ messageTimeText(item) }}</small>
                </header>
                <strong>{{ item.title }}</strong>
                <p>{{ item.content }}</p>
                <footer>
                  <span>{{ isOwnMobileMessage(item) ? '我发送' : (item.sender_name || '系统') }}</span>
                  <span>{{ messageLevelText(item.level) }}</span>
                  <span>{{ item.is_read ? '已读' : '未读' }}</span>
                  <button v-if="item.link_url" type="button" @click.stop="openMobileMessageLink(item)">
                    查看关联
                  </button>
                </footer>
              </article>
            </div>
          </template>
          <section v-else class="mobile-empty-card">
            <strong>暂无消息</strong>
            <span>新的通知会按时间显示在这里。</span>
          </section>
          <div class="mobile-list-footer">
            <button
              :disabled="messageState.loading || messageState.items.length >= messageState.pagination.total"
              @click="loadMoreMessages"
            >
              {{ messageState.items.length >= messageState.pagination.total ? '没有更多' : '加载更多' }}
            </button>
          </div>
        </section>
      </template>

      <template v-else-if="activeTab === 'doc'">
        <section class="module-head">
          <span class="green">
            <BookOpen :size="25" />
          </span>
          <div>
            <h1>文档中心</h1>
            <p>学校制度、流程说明和常见问题</p>
          </div>
        </section>

        <section class="mobile-list-tools support-mobile-tools">
          <select v-model="support.doc.filters.category_id" @change="loadMobileDocs(1)">
            <option value="">全部分类</option>
            <option v-for="item in support.doc.categories" :key="item.id" :value="item.id">
              {{ item.name }}
            </option>
          </select>
          <input v-model="support.doc.filters.keyword" placeholder="搜索文档" @keyup.enter="loadMobileDocs(1)">
        </section>

        <section class="mobile-support-list">
          <article
            v-for="item in support.doc.items"
            :key="item.id"
            class="mobile-card support-mobile-card"
            @click="openMobileDoc(item)"
          >
            <header>
              <BookOpen :size="19" />
              <strong>{{ item.title }}</strong>
              <small>{{ item.category_name || '未分类' }}</small>
            </header>
            <p>{{ item.version ? `版本 ${item.version}` : '文档' }} / {{ item.updated_at || '-' }}</p>
          </article>
          <section v-if="!support.doc.items.length" class="mobile-empty-card">
            <strong>暂无文档</strong>
            <span>已发布文档会显示在这里。</span>
          </section>
          <div class="mobile-list-footer">
            <button :disabled="support.doc.loading" @click="loadMobileDocs(1)">刷新</button>
            <button v-if="canLoadMoreSupport('doc')" :disabled="support.doc.loading" @click="loadMobileDocs(support.doc.pagination.page + 1, true)">
              加载更多
            </button>
          </div>
        </section>
      </template>

      <template v-else-if="activeTab === 'templateLib'">
        <section class="module-head">
          <span class="teal">
            <FileText :size="25" />
          </span>
          <div>
            <h1>模板库</h1>
            <p>实习实践材料模板查看和下载</p>
          </div>
        </section>

        <section class="mobile-list-tools support-mobile-tools">
          <select v-model="support.template.filters.category_id" @change="loadMobileTemplates(1)">
            <option value="">全部分类</option>
            <option v-for="item in support.template.categories" :key="item.id" :value="item.id">
              {{ item.name }}
            </option>
          </select>
          <input v-model="support.template.filters.keyword" placeholder="搜索模板" @keyup.enter="loadMobileTemplates(1)">
        </section>

        <section class="mobile-support-list">
          <article
            v-for="item in support.template.items"
            :key="item.id"
            class="mobile-card support-mobile-card"
          >
            <header>
              <FileText :size="19" />
              <strong>{{ item.name }}</strong>
              <small>{{ item.category_name || '未分类' }}</small>
            </header>
            <p>{{ item.description || item.file?.download_name || '暂无说明' }}</p>
            <footer>
              <span>版本 {{ item.version || '-' }} / 下载 {{ item.download_count || 0 }}</span>
              <button type="button" @click="downloadMobileTemplate(item)">下载</button>
            </footer>
          </article>
          <section v-if="!support.template.items.length" class="mobile-empty-card">
            <strong>暂无模板</strong>
            <span>学校管理员上传模板后会显示在这里。</span>
          </section>
          <div class="mobile-list-footer">
            <button :disabled="support.template.loading" @click="loadMobileTemplates(1)">刷新</button>
            <button v-if="canLoadMoreSupport('template')" :disabled="support.template.loading" @click="loadMobileTemplates(support.template.pagination.page + 1, true)">
              加载更多
            </button>
          </div>
        </section>
      </template>

      <template v-else-if="activeTab === 'internship'">
        <section class="module-head">
          <span class="blue">
            <BriefcaseBusiness :size="25" />
          </span>
          <div>
            <h1>{{ internshipRoleTitle }}</h1>
            <p>{{ internshipRoleDesc }}</p>
          </div>
        </section>

        <van-notice-bar
          v-if="internship.message"
          color="#8a5a00"
          background="#fff3d8"
          left-icon="warning-o"
          :text="internship.message"
        />

        <section class="internship-action-tabs">
          <button
            v-for="item in internshipPanels"
            :key="item.key"
            :class="{ active: internship.panel === item.key }"
            @click="switchInternshipPanel(item.key)"
          >
            <component :is="item.icon" :size="18" />
            <span>{{ item.name }}</span>
          </button>
        </section>

        <section v-if="internship.panel === 'workbench'" class="summary-band internship-summary">
          <div v-for="item in internshipSummaries" :key="item.name">
            <strong>{{ item.value }}</strong>
            <span>{{ item.name }}</span>
          </div>
        </section>

        <template v-if="isStudentRole && internship.panel === 'apply'">
          <section class="mobile-card">
            <header>
              <ClipboardList :size="20" />
              <strong>我的实习任务</strong>
            </header>
            <van-cell
              v-for="row in internship.options.arrangements"
              :key="row.id"
              clickable
              :title="row.title"
              :label="joinFact([row.course_name, row.teacher_name, dateRangeText(row.start_date, row.end_date)])"
              :value="row.student_count ? `${row.student_count}人` : ''"
              @click="openTimelineDialog('arrangement', row)"
            >
              <template #right-icon>
                <div class="cell-actions">
                  <button @click.stop="openTimelineDialog('arrangement', row)">记录</button>
                </div>
              </template>
            </van-cell>
            <div v-if="!internship.options.arrangements.length" class="mobile-empty">暂无绑定任务</div>
          </section>
          <section class="mobile-card form-card">
            <header>
              <ClipboardList :size="20" />
              <strong>提交特殊申请</strong>
            </header>
            <label>
              <span>实习任务</span>
              <select v-model.number="internship.forms.application.arrangement_id">
                <option v-for="item in internship.options.arrangements" :key="item.id" :value="item.id">
                  {{ item.title }}
                </option>
              </select>
            </label>
            <label>
              <span>申请类型</span>
              <select v-model="internship.forms.application.type">
                <option value="centralized">集中实习</option>
                <option value="distributed">分散实习</option>
                <option value="autonomous">自主实习</option>
              </select>
            </label>
            <label>
              <span>申请说明</span>
              <textarea v-model="internship.forms.application.remark" rows="4" />
            </label>
            <van-button block type="primary" :loading="internship.loading" @click="submitApplication">
              提交申请
            </van-button>
          </section>
          <section class="mobile-card">
            <header>
              <FileClock :size="20" />
              <strong>历史申请记录</strong>
            </header>
            <van-cell
              v-for="row in internship.lists.applications.items"
              :key="row.id"
              :title="row.arrangement_title"
              :label="`${row.student_name || '-'} / ${statusText(row.teacher_status)} / ${statusText(row.admin_status)}`"
              :value="statusText(row.status)"
            >
              <template #right-icon>
                <div class="cell-actions">
                  <button @click.stop="openTimelineDialog('application', row)">记录</button>
                </div>
              </template>
            </van-cell>
            <div v-if="!internship.lists.applications.items.length" class="mobile-empty">暂无申请记录</div>
            <div class="mobile-list-footer">
              <span>共 {{ internship.lists.applications.pagination.total || 0 }} 条</span>
              <button v-if="canLoadMore('applications')" :disabled="internship.loading" @click="loadMoreInternshipList('applications')">
                加载更多
              </button>
            </div>
          </section>
        </template>

        <template v-if="isStudentRole && internship.panel === 'submit'">
          <section class="student-submit-cards">
            <button
              v-for="item in studentSubmitCards"
              :key="item.key"
              :class="{ active: internship.submitSection === item.key, expired: item.expired }"
              @click="openStudentSubmitSection(item.key)"
            >
              <component :is="item.icon" :size="20" />
              <span>
                <strong>{{ item.title }}</strong>
                <small>{{ item.desc }}</small>
              </span>
              <em>{{ item.meta }}</em>
            </button>
          </section>

          <section v-if="internship.submitSection === 'sign'" class="mobile-card form-card">
            <header>
              <MapPin :size="20" />
              <strong>签到</strong>
            </header>
            <label>
              <span>实习任务</span>
              <select v-model.number="internship.forms.sign.arrangement_id">
                <option v-for="item in internship.options.arrangements" :key="item.id" :value="item.id">
                  {{ item.title }}
                </option>
              </select>
            </label>
            <div class="gps-sign-card">
              <div class="gps-sign-head">
                <span>
                  <strong>{{ signGpsTitle }}</strong>
                  <small>{{ internship.forms.sign.location || signGpsHint }}</small>
                </span>
                <button type="button" :disabled="internship.forms.sign.locating" @click="locateSignPosition">
                  {{ internship.forms.sign.locating ? '定位中' : '重新定位' }}
                </button>
              </div>
              <div class="gps-map-preview">
                <img v-if="signMapUrl" :src="signMapUrl" alt="签到定位地图">
                <div v-else>
                  <MapPin :size="24" />
                  <span>获取 GPS 后显示地图</span>
                </div>
              </div>
              <div class="gps-coordinate-grid">
                <span>经度 {{ coordinateText(internship.forms.sign.longitude) }}</span>
                <span>纬度 {{ coordinateText(internship.forms.sign.latitude) }}</span>
                <span>精度 {{ signAccuracyText }}</span>
              </div>
              <small v-if="internship.forms.sign.gps_error" class="gps-error">{{ internship.forms.sign.gps_error }}</small>
            </div>
            <van-button block type="primary" :loading="internship.loading" :disabled="!signGpsReady" @click="submitSignIn">
              提交签到
            </van-button>
          </section>

          <section v-if="internship.submitSection === 'sign'" class="mobile-card">
            <header>
              <MapPin :size="20" />
              <strong>签到记录</strong>
            </header>
            <van-cell
              v-for="row in internship.lists.signIns.items"
              :key="row.id"
              clickable
              :title="row.arrangement_title || '实习签到'"
              :label="`${row.date || '-'} / ${row.sign_time || '-'} / ${row.location || '-'}`"
              :value="signTypeText(row.sign_type)"
              @click="openTimelineDialog('sign_in', row)"
            />
            <div v-if="!internship.lists.signIns.items.length" class="mobile-empty">暂无签到记录</div>
            <div class="mobile-list-footer">
              <span>共 {{ internship.lists.signIns.pagination.total || 0 }} 条</span>
              <button v-if="canLoadMore('signIns')" :disabled="internship.loading" @click="loadMoreInternshipList('signIns')">
                加载更多
              </button>
            </div>
          </section>

          <section v-if="internship.submitSection === 'journal'" class="mobile-card form-card">
            <header>
              <FileClock :size="20" />
              <strong>实习日志</strong>
              <small :class="{ danger: isStageExpired('journal_deadline') }">{{ stageDeadlineText('journal_deadline') }}</small>
            </header>
            <label>
              <span>实习任务</span>
              <select v-model.number="internship.forms.journal.arrangement_id">
                <option v-for="item in internship.options.arrangements" :key="item.id" :value="item.id">
                  {{ item.title }}
                </option>
              </select>
            </label>
            <div class="stage-deadline-panel" :class="{ expired: isStageExpired('journal_deadline') }">
              <span>{{ stageDeadlineDetailText('journal_deadline') }}</span>
              <button v-if="isStageExpired('journal_deadline')" type="button" @click="openDelayForStage('journal_deadline')">
                申请延期
              </button>
            </div>
            <label>
              <span>标题</span>
              <input v-model="internship.forms.journal.title">
            </label>
            <label>
              <span>内容</span>
              <textarea v-model="internship.forms.journal.content" rows="4" />
            </label>
            <van-button block type="primary" :loading="internship.loading" :disabled="isStageExpired('journal_deadline')" @click="submitJournal">
              {{ internship.forms.journal.id ? '重新提交日志' : '提交日志' }}
            </van-button>
          </section>

          <section v-if="internship.submitSection === 'report'" class="mobile-card form-card">
            <header>
              <FileText :size="20" />
              <strong>实习报告</strong>
              <small :class="{ danger: isStageExpired('report_deadline') }">{{ stageDeadlineText('report_deadline') }}</small>
            </header>
            <label>
              <span>实习任务</span>
              <select v-model.number="internship.forms.report.arrangement_id">
                <option v-for="item in internship.options.arrangements" :key="item.id" :value="item.id">
                  {{ item.title }}
                </option>
              </select>
            </label>
            <div class="stage-deadline-panel" :class="{ expired: isStageExpired('report_deadline') }">
              <span>{{ stageDeadlineDetailText('report_deadline') }}</span>
              <button v-if="isStageExpired('report_deadline')" type="button" @click="openDelayForStage('report_deadline')">
                申请延期
              </button>
            </div>
            <label>
              <span>标题</span>
              <input v-model="internship.forms.report.title">
            </label>
            <label>
              <span>内容</span>
              <textarea v-model="internship.forms.report.content" rows="4" />
            </label>
            <van-button block type="primary" :loading="internship.loading" :disabled="isStageExpired('report_deadline')" @click="submitReport">
              {{ internship.forms.report.id ? '重新提交报告' : '提交报告' }}
            </van-button>
          </section>

          <section v-if="internship.submitSection === 'delay'" class="mobile-card form-card">
            <header>
              <FileClock :size="20" />
              <strong>延期申请</strong>
            </header>
            <label>
              <span>实习任务</span>
              <select v-model.number="internship.forms.delay.arrangement_id">
                <option v-for="item in internship.options.arrangements" :key="item.id" :value="item.id">
                  {{ item.title }}
                </option>
              </select>
            </label>
            <label>
              <span>申请模块</span>
              <select v-model="internship.forms.delay.config_key">
                <option v-for="item in delayConfigOptions()" :key="item.value" :value="item.value">
                  {{ item.label }}
                </option>
              </select>
              <small>{{ stageDeadlineText(internship.forms.delay.config_key) }}</small>
            </label>
            <label>
              <span>申请延期至</span>
              <input v-model="internship.forms.delay.requested_date" type="date">
            </label>
            <label>
              <span>申请原因</span>
              <textarea v-model="internship.forms.delay.reason" rows="4" />
            </label>
            <van-button block type="primary" :loading="internship.loading" @click="submitDelay">
              提交延期申请
            </van-button>
          </section>

          <section v-if="internship.submitSection === 'journal'" class="mobile-card">
            <header>
              <FileClock :size="20" />
              <strong>日志记录</strong>
            </header>
            <van-cell
              v-for="row in internship.lists.journals.items"
              :key="row.id"
              :title="row.title"
              :label="row.date || row.created_at || '-'"
            >
              <template #right-icon>
                <div class="cell-actions record-status-actions">
                  <span class="cell-status">{{ statusText(row.status) }}</span>
                  <button v-if="canEditStudentWork(row)" @click.stop="editStudentWork('journal', row)">修改</button>
                  <button @click.stop="openTimelineDialog('journal', row)">记录</button>
                </div>
              </template>
            </van-cell>
            <div v-if="!internship.lists.journals.items.length" class="mobile-empty">暂无日志记录</div>
            <div class="mobile-list-footer">
              <span>共 {{ internship.lists.journals.pagination.total || 0 }} 条</span>
              <button v-if="canLoadMore('journals')" :disabled="internship.loading" @click="loadMoreInternshipList('journals')">
                加载更多
              </button>
            </div>
          </section>

          <section v-if="internship.submitSection === 'report'" class="mobile-card">
            <header>
              <FileText :size="20" />
              <strong>报告记录</strong>
            </header>
            <van-cell
              v-for="row in internship.lists.reports.items"
              :key="row.id"
              :title="row.title"
              :label="row.created_at || '-'"
            >
              <template #right-icon>
                <div class="cell-actions record-status-actions">
                  <span class="cell-status">{{ statusText(row.status) }}</span>
                  <button v-if="canEditStudentWork(row)" @click.stop="editStudentWork('report', row)">修改</button>
                  <button @click.stop="openTimelineDialog('report', row)">记录</button>
                </div>
              </template>
            </van-cell>
            <div v-if="!internship.lists.reports.items.length" class="mobile-empty">暂无报告记录</div>
            <div class="mobile-list-footer">
              <span>共 {{ internship.lists.reports.pagination.total || 0 }} 条</span>
              <button v-if="canLoadMore('reports')" :disabled="internship.loading" @click="loadMoreInternshipList('reports')">
                加载更多
              </button>
            </div>
          </section>

          <section v-if="internship.submitSection === 'delay'" class="mobile-card">
            <header>
              <FileClock :size="20" />
              <strong>延期记录</strong>
            </header>
            <van-cell
              v-for="row in internship.lists.delays.items"
              :key="row.id"
              :title="delayConfigText(row.config_key)"
              :label="`${row.arrangement_title || '-'} / 延期至 ${row.requested_date || '-'}`"
              :value="statusText(row.status)"
            >
              <template #right-icon>
                <div class="cell-actions record-status-actions">
                  <button v-if="canEditStudentWork(row)" @click.stop="editStudentWork('delay', row)">修改</button>
                  <button @click.stop="openTimelineDialog('delay', row)">记录</button>
                </div>
              </template>
            </van-cell>
            <div v-if="!internship.lists.delays.items.length" class="mobile-empty">暂无延期申请</div>
            <div class="mobile-list-footer">
              <span>共 {{ internship.lists.delays.pagination.total || 0 }} 条</span>
              <button v-if="canLoadMore('delays')" :disabled="internship.loading" @click="loadMoreInternshipList('delays')">
                加载更多
              </button>
            </div>
          </section>
        </template>

        <template v-if="canReviewInternship && internship.panel === 'review'">
          <section v-if="reviewListTabs.length > 1" class="mobile-list-switch">
            <button
              v-for="item in reviewListTabs"
              :key="item.key"
              :class="{ active: internship.reviewList === item.key }"
              @click="switchMobileList('review', item.key)"
            >
              <component :is="item.icon" :size="17" />
              <span>{{ item.shortTitle }}</span>
            </button>
          </section>

          <section v-if="currentReviewListConfig" class="mobile-card">
            <header>
              <component :is="currentReviewListConfig.icon" :size="20" />
              <strong>{{ currentReviewListConfig.title }}</strong>
            </header>
            <section class="mobile-list-tools" :class="mobileListToolClass(currentReviewListConfig)">
              <select
                v-for="filter in mobileListSelectFilters(currentReviewListConfig)"
                :key="filter.key"
                v-model="internship.filters[currentReviewListConfig.key][filter.key]"
                :aria-label="filter.label"
                @change="handleMobileListFilterChange(currentReviewListConfig.key, filter.key)"
              >
                <option value="">{{ filter.placeholder }}</option>
                <option v-for="item in filter.options" :key="item.value" :value="item.value">
                  {{ item.label }}
                </option>
              </select>
              <input
                class="mobile-keyword-input"
                v-model="internship.filters[currentReviewListConfig.key].keyword"
                :placeholder="currentReviewListConfig.keywordPlaceholder"
                @keyup.enter="reloadInternshipList(currentReviewListConfig.key)"
              >
              <select
                v-if="currentReviewListConfig.statusOptions.length"
                v-model="internship.filters[currentReviewListConfig.key].status"
                @change="reloadInternshipList(currentReviewListConfig.key)"
              >
                <option value="">全部状态</option>
                <option
                  v-for="option in currentReviewListConfig.statusOptions"
                  :key="option.value"
                  :value="option.value"
                >
                  {{ option.label }}
                </option>
              </select>
              <button type="button" :disabled="internship.loading" @click="reloadInternshipList(currentReviewListConfig.key)">
                查询
              </button>
            </section>
            <van-cell
              v-for="row in mobileListRows(currentReviewListConfig.key)"
              :key="row.id"
              :title="mobileListTitle(currentReviewListConfig.key, row)"
              :value="mobileListValue(currentReviewListConfig.key, row)"
            >
              <template #label>
                <div class="mobile-cell-meta">
                  <span
                    v-for="(fact, index) in mobileListFacts(currentReviewListConfig.key, row)"
                    :key="`${fact}-${index}`"
                  >
                    {{ fact }}
                  </span>
                </div>
              </template>
              <template #right-icon>
                <div v-if="mobileListActions(currentReviewListConfig.key, row, 'review').length" class="cell-actions">
                  <button
                    v-for="action in mobileListActions(currentReviewListConfig.key, row, 'review')"
                    :key="action.key"
                    @click.stop="handleMobileListAction(action, row)"
                  >
                    {{ action.label }}
                  </button>
                </div>
              </template>
            </van-cell>
            <div v-if="!mobileListRows(currentReviewListConfig.key).length" class="mobile-empty">
              {{ currentReviewListConfig.emptyText }}
            </div>
            <div class="mobile-list-footer">
              <span>共 {{ listTotal(currentReviewListConfig.key) }} 条</span>
              <button
                v-if="canLoadMore(currentReviewListConfig.key)"
                :disabled="internship.loading"
                @click="loadMoreInternshipList(currentReviewListConfig.key)"
              >
                加载更多
              </button>
            </div>
          </section>
        </template>

        <template v-if="internship.panel === 'score'">
          <section v-if="isTeacherRole" class="mobile-card form-card">
            <header>
              <GraduationCap :size="20" />
              <strong>成绩录入</strong>
            </header>
            <label>
              <span>学生</span>
              <input
                v-model="internship.filters.pairs.keyword"
                placeholder="搜索学生、学号或任务"
                @keyup.enter="reloadScorePairs"
              >
            </label>
            <label>
              <span>任务绑定</span>
              <select v-model.number="internship.forms.score.pair_id" @change="selectScorePair">
                <option v-for="pair in internship.lists.pairs.items" :key="pair.id" :value="pair.id">
                  {{ pair.student_name }} / {{ pair.arrangement_title }}
                </option>
              </select>
            </label>
            <van-button block plain type="primary" :loading="internship.loading" @click="reloadScorePairs">
              查询绑定学生
            </van-button>
            <label><span>签到成绩</span><input v-model="internship.forms.score.sign_in_score" type="number"></label>
            <label><span>日志成绩</span><input v-model="internship.forms.score.journal_score" type="number"></label>
            <label><span>报告成绩</span><input v-model="internship.forms.score.report_score" type="number"></label>
            <label><span>企业成绩</span><input v-model="internship.forms.score.enterprise_score" type="number"></label>
            <van-button block type="primary" :loading="internship.loading" @click="submitScore">
              保存成绩
            </van-button>
          </section>

          <section class="mobile-card">
            <header>
              <GraduationCap :size="20" />
              <strong>任务成绩记录</strong>
            </header>
            <section class="mobile-list-tools" :class="mobileListToolClass(getMobileListConfig('scores'))">
              <select
                v-for="filter in mobileListSelectFilters(getMobileListConfig('scores'))"
                :key="filter.key"
                v-model="internship.filters.scores[filter.key]"
                :aria-label="filter.label"
                @change="handleMobileListFilterChange('scores', filter.key)"
              >
                <option value="">{{ filter.placeholder }}</option>
                <option v-for="item in filter.options" :key="item.value" :value="item.value">
                  {{ item.label }}
                </option>
              </select>
              <input class="mobile-keyword-input" v-model="internship.filters.scores.keyword" placeholder="学生、学号、安排" @keyup.enter="reloadInternshipList('scores')">
              <button type="button" :disabled="internship.loading" @click="reloadInternshipList('scores')">查询</button>
            </section>
            <van-cell
              v-for="row in internship.lists.scores.items"
              :key="row.id"
              clickable
              :title="row.student_name || row.student_num"
              :label="`${row.arrangement_title || '-'} / 总评 ${row.final_score ?? '-'}`"
              :value="row.teacher_name || '-'"
              @click="openTimelineDialog('score', row)"
            />
            <div v-if="!internship.lists.scores.items.length" class="mobile-empty">暂无成绩记录</div>
            <div class="mobile-list-footer">
              <span>共 {{ internship.lists.scores.pagination.total || 0 }} 条</span>
              <button v-if="canLoadMore('scores')" :disabled="internship.loading" @click="loadMoreInternshipList('scores')">
                加载更多
              </button>
            </div>
          </section>

          <section class="mobile-card">
            <header>
              <GraduationCap :size="20" />
              <strong>课程成绩汇总</strong>
            </header>
            <section class="mobile-list-tools" :class="mobileListToolClass(getMobileListConfig('courseScores'))">
              <select
                v-for="filter in mobileListSelectFilters(getMobileListConfig('courseScores'))"
                :key="filter.key"
                v-model="internship.filters.courseScores[filter.key]"
                :aria-label="filter.label"
                @change="handleMobileListFilterChange('courseScores', filter.key)"
              >
                <option value="">{{ filter.placeholder }}</option>
                <option v-for="item in filter.options" :key="item.value" :value="item.value">
                  {{ item.label }}
                </option>
              </select>
              <input class="mobile-keyword-input" v-model="internship.filters.courseScores.keyword" placeholder="学生、学号、课程、任务" @keyup.enter="reloadInternshipList('courseScores')">
              <button type="button" :disabled="internship.loading" @click="reloadInternshipList('courseScores')">查询</button>
            </section>
            <van-cell
              v-for="row in internship.lists.courseScores.items"
              :key="`${row.plan_id}-${row.student_id}`"
              :title="joinFact([row.student_name, row.student_num]) || '学生成绩'"
              :label="joinFact([row.course_name || row.course_code, `任务 ${row.scored_task_count || 0}/${row.task_count || 0}`])"
              :value="row.course_final_score !== null && row.course_final_score !== undefined ? `${row.course_final_score} 分` : '-'"
            >
              <template #label>
                <div class="mobile-cell-meta">
                  <span v-for="(fact, index) in mobileListFacts('courseScores', row)" :key="`${fact}-${index}`">
                    {{ fact }}
                  </span>
                </div>
              </template>
            </van-cell>
            <div v-if="!internship.lists.courseScores.items.length" class="mobile-empty">暂无课程成绩汇总</div>
            <div class="mobile-list-footer">
              <span>共 {{ internship.lists.courseScores.pagination.total || 0 }} 条</span>
              <button v-if="canLoadMore('courseScores')" :disabled="internship.loading" @click="loadMoreInternshipList('courseScores')">
                加载更多
              </button>
            </div>
          </section>
        </template>

        <template v-if="isAdminRole && internship.panel === 'manage'">
          <section class="mobile-list-switch multi">
            <button
              v-for="item in manageListTabs"
              :key="item.key"
              :class="{ active: internship.manageList === item.key }"
              @click="switchMobileList('manage', item.key)"
            >
              <component :is="item.icon" :size="17" />
              <span>{{ item.shortTitle }}</span>
            </button>
          </section>

          <section v-if="currentManageListConfig" class="mobile-card">
            <header>
              <component :is="currentManageListConfig.icon" :size="20" />
              <strong>{{ currentManageListConfig.title }}</strong>
            </header>
            <section class="mobile-list-tools" :class="mobileListToolClass(currentManageListConfig)">
              <select
                v-for="filter in mobileListSelectFilters(currentManageListConfig)"
                :key="filter.key"
                v-model="internship.filters[currentManageListConfig.key][filter.key]"
                :aria-label="filter.label"
                @change="handleMobileListFilterChange(currentManageListConfig.key, filter.key)"
              >
                <option value="">{{ filter.placeholder }}</option>
                <option v-for="item in filter.options" :key="item.value" :value="item.value">
                  {{ item.label }}
                </option>
              </select>
              <input
                class="mobile-keyword-input"
                v-model="internship.filters[currentManageListConfig.key].keyword"
                :placeholder="currentManageListConfig.keywordPlaceholder"
                @keyup.enter="reloadInternshipList(currentManageListConfig.key)"
              >
              <select
                v-if="currentManageListConfig.statusOptions.length"
                v-model="internship.filters[currentManageListConfig.key][currentManageListConfig.statusKey || 'status']"
                @change="reloadInternshipList(currentManageListConfig.key)"
              >
                <option value="">全部状态</option>
                <option
                  v-for="option in currentManageListConfig.statusOptions"
                  :key="option.value"
                  :value="option.value"
                >
                  {{ option.label }}
                </option>
              </select>
              <button type="button" :disabled="internship.loading" @click="reloadInternshipList(currentManageListConfig.key)">
                查询
              </button>
            </section>
            <van-cell
              v-for="row in mobileListRows(currentManageListConfig.key)"
              :key="row.id"
              :title="mobileListTitle(currentManageListConfig.key, row)"
              :value="mobileListValue(currentManageListConfig.key, row)"
            >
              <template #label>
                <div class="mobile-cell-meta">
                  <span
                    v-for="(fact, index) in mobileListFacts(currentManageListConfig.key, row)"
                    :key="`${fact}-${index}`"
                  >
                    {{ fact }}
                  </span>
                </div>
              </template>
              <template #right-icon>
                <div v-if="mobileListActions(currentManageListConfig.key, row, 'manage').length" class="cell-actions">
                  <button
                    v-for="action in mobileListActions(currentManageListConfig.key, row, 'manage')"
                    :key="action.key"
                    @click.stop="handleMobileListAction(action, row)"
                  >
                    {{ action.label }}
                  </button>
                </div>
              </template>
            </van-cell>
            <div v-if="!mobileListRows(currentManageListConfig.key).length" class="mobile-empty">
              {{ currentManageListConfig.emptyText }}
            </div>
            <div class="mobile-list-footer">
              <span>共 {{ listTotal(currentManageListConfig.key) }} 条</span>
              <button
                v-if="canLoadMore(currentManageListConfig.key)"
                :disabled="internship.loading"
                @click="loadMoreInternshipList(currentManageListConfig.key)"
              >
                加载更多
              </button>
            </div>
          </section>
        </template>

        <section v-if="internship.panel === 'workbench'" class="mobile-card">
          <header>
            <BriefcaseBusiness :size="20" />
            <strong>当前任务</strong>
          </header>
          <van-cell
            v-for="item in internshipWorkbenchCells"
            :key="item.title"
            :title="item.title"
            :label="item.label"
            :value="item.value"
          />
        </section>
      </template>

      <template v-else-if="isPracticeTab(activeTab)">
        <section class="module-head">
          <span :class="currentPage.theme">
            <component :is="currentPage.icon" :size="25" />
          </span>
          <div>
            <h1>{{ currentPage.title }}</h1>
            <p>{{ currentPage.desc }}</p>
          </div>
        </section>

        <van-notice-bar
          v-if="practiceModule(activeTab).message"
          color="#8a5a00"
          background="#fff3d8"
          left-icon="warning-o"
          :text="practiceModule(activeTab).message"
        />

        <section class="summary-band internship-summary">
          <div v-for="item in practiceSummaries(activeTab)" :key="item.name">
            <strong>{{ item.value }}</strong>
            <span>{{ item.name }}</span>
          </div>
        </section>

        <section v-if="isStudentRole" class="student-guide-card">
          <header>
            <Route :size="20" />
            <strong>{{ currentPage.title }}流程</strong>
          </header>
          <div class="student-guide-steps">
            <span v-for="step in practiceFlowSteps" :key="step">{{ step }}</span>
          </div>
        </section>

        <section class="mobile-list-switch multi practice-switch">
          <button
            v-for="item in practicePanels(activeTab)"
            :key="item.key"
            :class="{ active: practiceModule(activeTab).panel === item.key }"
            @click="switchPracticePanel(activeTab, item.key)"
          >
            <component :is="item.icon" :size="17" />
            <span>{{ item.shortTitle }}</span>
          </button>
        </section>

        <section class="mobile-card">
          <header>
            <component :is="currentPracticePanel(activeTab).icon" :size="20" />
            <strong>{{ currentPracticePanel(activeTab).title }}</strong>
          </header>
          <section v-if="practiceFilters(activeTab).length || !isStudentRole" class="mobile-list-tools" :class="{ compact: isStudentRole }">
            <select
              v-for="filter in practiceFilters(activeTab)"
              :key="filter.key"
              v-model="practiceModule(activeTab).filters[practiceModule(activeTab).panel][filter.key]"
              :aria-label="filter.label"
              @change="reloadPracticeList(activeTab)"
            >
              <option value="">{{ filter.placeholder }}</option>
              <option v-for="item in filter.options" :key="item.value" :value="item.value">
                {{ item.label }}
              </option>
            </select>
            <input
              v-if="!isStudentRole"
              class="mobile-keyword-input"
              v-model="practiceModule(activeTab).filters[practiceModule(activeTab).panel].keyword"
              placeholder="标题、课程、学生、内容"
              @keyup.enter="reloadPracticeList(activeTab)"
            >
            <button v-if="!isStudentRole" type="button" :disabled="practiceModule(activeTab).loading" @click="reloadPracticeList(activeTab)">
              查询
            </button>
          </section>
          <van-cell
            v-for="row in currentPracticeRows(activeTab)"
            :key="row.id"
            :title="practiceRowTitle(activeTab, row)"
            :value="practiceRowValue(activeTab, row)"
          >
            <template #label>
              <div class="mobile-cell-meta">
                <span v-for="(fact, index) in practiceRowFacts(activeTab, row)" :key="`${fact}-${index}`">
                  {{ fact }}
                </span>
              </div>
            </template>
            <template #right-icon>
              <div v-if="practiceRowActions(activeTab, row).length" class="cell-actions">
                <button
                  v-for="action in practiceRowActions(activeTab, row)"
                  :key="action.key"
                  @click.stop="handlePracticeAction(activeTab, action, row)"
                >
                  {{ action.label }}
                </button>
              </div>
            </template>
          </van-cell>
          <div v-if="!currentPracticeRows(activeTab).length" class="mobile-empty">
            {{ currentPracticePanel(activeTab).emptyText }}
          </div>
          <div class="mobile-list-footer">
            <span>共 {{ practiceListTotal(activeTab) }} 条</span>
            <button v-if="canLoadMorePractice(activeTab)" :disabled="practiceModule(activeTab).loading" @click="loadMorePracticeList(activeTab)">
              加载更多
            </button>
          </div>
        </section>
      </template>

      <template v-else>
        <section class="module-head">
          <span :class="currentPage.theme">
            <component :is="currentPage.icon" :size="25" />
          </span>
          <div>
            <h1>{{ currentPage.title }}</h1>
            <p>{{ currentPage.desc }}</p>
          </div>
        </section>

      </template>
    </section>

    <van-tabbar v-model="activeTab" safe-area-inset-bottom>
      <van-tabbar-item name="home">
        <template #icon><Home :size="20" /></template>
        首页
      </van-tabbar-item>
      <van-tabbar-item v-if="isMobileModuleVisible('internship')" name="internship">
        <template #icon><BriefcaseBusiness :size="20" /></template>
        实习
      </van-tabbar-item>
      <van-tabbar-item v-if="isMobileModuleVisible('training')" name="training">
        <template #icon><Workflow :size="20" /></template>
        实训
      </van-tabbar-item>
      <van-tabbar-item v-if="isMobileModuleVisible('lab')" name="lab">
        <template #icon><FlaskConical :size="20" /></template>
        实验
      </van-tabbar-item>
      <van-tabbar-item v-if="isLoggedIn" name="message" :badge="messageUnreadCount > 0 ? (messageUnreadCount > 99 ? '99+' : String(messageUnreadCount)) : ''">
        <template #icon><MessageCircle :size="20" /></template>
        消息
      </van-tabbar-item>
      <van-tabbar-item name="mine">
        <template #icon><UserRound :size="20" /></template>
        我的
      </van-tabbar-item>
    </van-tabbar>

    <van-popup
      v-model:show="support.doc.detail.visible"
      round
      position="bottom"
      safe-area-inset-bottom
    >
      <section class="support-doc-sheet">
        <header>
          <span>{{ support.doc.detail.article?.category_name || '未分类' }}</span>
          <strong>{{ support.doc.detail.article?.title || '文档详情' }}</strong>
          <small>
            版本 {{ support.doc.detail.article?.version || '-' }} /
            浏览 {{ support.doc.detail.article?.view_count || 0 }}
          </small>
        </header>
        <article
          v-if="support.doc.detail.article"
          class="support-mobile-rich"
          v-html="support.doc.detail.article.content"
        />
        <div v-else class="mobile-empty-card">
          <strong>{{ support.doc.detail.loading ? '正在读取文档' : '暂无文档内容' }}</strong>
          <span>{{ support.doc.detail.message || '请稍后重试。' }}</span>
        </div>
        <div class="sheet-actions single">
          <button type="button" @click="closeMobileDoc">关闭</button>
        </div>
      </section>
    </van-popup>

    <van-popup
      v-model:show="internship.reviewDialog.visible"
      round
      position="bottom"
      safe-area-inset-bottom
    >
      <section class="review-sheet">
        <header>
          <strong>{{ reviewDialogTitle }}</strong>
          <span>{{ reviewDialogRuleText }}</span>
        </header>
        <section v-if="reviewDialogTargetDetails.length" class="review-target-card">
          <div
            v-for="item in reviewDialogTargetDetails"
            :key="item.label"
          >
            <span>{{ item.label }}</span>
            <strong>{{ item.value }}</strong>
          </div>
        </section>
        <label>
          <span>{{ reviewDialogReasonLabel }}</span>
          <textarea
            v-model="internship.reviewDialog.reason"
            :maxlength="reviewRuleMax(internship.reviewDialog.entity, internship.reviewDialog.status) || undefined"
            rows="5"
            @input="trimReviewDialogMax"
          />
          <small>
            {{ textLength(internship.reviewDialog.reason) }} / {{ reviewRuleMaxText(internship.reviewDialog.entity, internship.reviewDialog.status) }}
          </small>
        </label>
        <div class="sheet-actions">
          <button type="button" @click="closeReviewDialog">取消</button>
          <button type="button" :disabled="internship.loading" @click="confirmReviewDialog">
            {{ reviewDialogConfirmText }}
          </button>
        </div>
      </section>
    </van-popup>

    <van-popup
      v-model:show="practiceReviewDialog.visible"
      round
      position="bottom"
      safe-area-inset-bottom
    >
      <section class="review-sheet">
        <header>
          <strong>{{ practiceReviewDialogTitle }}</strong>
          <span>{{ practiceReviewDialogRuleText }}</span>
        </header>
        <section v-if="practiceReviewTargetDetails.length" class="review-target-card">
          <div v-for="item in practiceReviewTargetDetails" :key="item.label">
            <span>{{ item.label }}</span>
            <strong>{{ item.value }}</strong>
          </div>
        </section>
        <label>
          <span>{{ practiceReviewReasonLabel }}</span>
          <textarea
            v-model="practiceReviewDialog.reason"
            :maxlength="practiceRuleMax(practiceReviewDialog.module, practiceReviewDialog.entity, practiceReviewDialog.status) || undefined"
            rows="5"
            @input="trimPracticeReviewMax"
          />
          <small>
            {{ textLength(practiceReviewDialog.reason) }} / {{ practiceRuleMaxText(practiceReviewDialog.module, practiceReviewDialog.entity, practiceReviewDialog.status) }}
          </small>
        </label>
        <div class="sheet-actions">
          <button type="button" @click="closePracticeReviewDialog">取消</button>
          <button type="button" :disabled="practiceModule(practiceReviewDialog.module).loading" @click="confirmPracticeReview">
            {{ practiceReviewConfirmText }}
          </button>
        </div>
      </section>
    </van-popup>

    <van-popup
      v-model:show="practiceExecutionDialog.visible"
      round
      position="bottom"
      safe-area-inset-bottom
    >
      <section class="review-sheet">
        <header>
          <strong>{{ practiceExecutionDialogTitle }}</strong>
          <span>{{ practiceExecutionDialogSubtitle }}</span>
        </header>
        <label>
          <span>项目</span>
          <select v-model.number="practiceExecutionDialog.form.project_id">
            <option v-for="item in practiceModule(practiceExecutionDialog.module).options.projects" :key="item.id" :value="item.id">
              {{ item.title || item.course_name }}
            </option>
          </select>
        </label>
        <template v-if="practiceExecutionDialog.execution === 'sign_in'">
          <div class="gps-sign-card">
            <div class="gps-sign-head">
              <span>
                <strong>{{ practiceGpsTitle }}</strong>
                <small>{{ practiceExecutionDialog.form.location || practiceGpsHint }}</small>
              </span>
              <button type="button" :disabled="practiceExecutionDialog.form.locating" @click="locatePracticePosition">
                {{ practiceExecutionDialog.form.locating ? '定位中' : '重新定位' }}
              </button>
            </div>
            <div class="gps-map-preview">
              <img v-if="practiceMapUrl" :src="practiceMapUrl" alt="签到定位地图">
              <div v-else>
                <MapPin :size="24" />
                <span>获取 GPS 后显示地图</span>
              </div>
            </div>
            <div class="gps-coordinate-grid">
              <span>经度 {{ coordinateText(practiceExecutionDialog.form.longitude) }}</span>
              <span>纬度 {{ coordinateText(practiceExecutionDialog.form.latitude) }}</span>
              <span>精度 {{ practiceAccuracyText }}</span>
            </div>
            <small v-if="practiceExecutionDialog.form.gps_error" class="gps-error">{{ practiceExecutionDialog.form.gps_error }}</small>
          </div>
        </template>
        <template v-else>
          <label>
            <span>标题</span>
            <input v-model="practiceExecutionDialog.form.title">
          </label>
          <label>
            <span>日期</span>
            <input v-model="practiceExecutionDialog.form.date" type="date">
          </label>
          <label>
            <span>内容</span>
            <textarea v-model="practiceExecutionDialog.form.content" rows="5" />
          </label>
        </template>
        <label>
          <span>备注</span>
          <textarea v-model="practiceExecutionDialog.form.remark" rows="3" />
        </label>
        <div class="sheet-actions">
          <button type="button" @click="closePracticeExecutionDialog">取消</button>
          <button type="button" :disabled="practiceModule(practiceExecutionDialog.module).loading || (practiceExecutionDialog.execution === 'sign_in' && !practiceGpsReady)" @click="submitPracticeExecution">
            提交
          </button>
        </div>
      </section>
    </van-popup>

    <van-popup
      v-model:show="internship.timelineDialog.visible"
      round
      position="bottom"
      safe-area-inset-bottom
    >
      <section class="timeline-sheet">
        <header>
          <strong>{{ internship.timelineDialog.title }}</strong>
          <span>{{ internship.timelineDialog.subtitle }}</span>
        </header>
        <div class="timeline-list">
          <div v-if="internship.timelineDialog.loading" class="timeline-empty">正在读取流程记录...</div>
          <template v-else-if="internshipTimelineCycles.length">
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
          </template>
          <div v-else class="timeline-empty">{{ internship.timelineDialog.message || '暂无流程记录' }}</div>
        </div>
        <div class="sheet-actions single">
          <button type="button" @click="closeTimelineDialog">关闭</button>
        </div>
      </section>
    </van-popup>
  </main>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { showToast } from 'vant';
import {
  BriefcaseBusiness,
  BookOpen,
  CalendarCheck,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  ClipboardList,
  FileClock,
  FileText,
  FlaskConical,
  GraduationCap,
  Home,
  LogIn,
  LogOut,
  MapPin,
  MessageCircle,
  RefreshCw,
  Route,
  Search,
  Send,
  UsersRound,
  UserRound,
  Workflow,
} from '@lucide/vue';
import { useMobilePermissions } from './composables/useMobilePermissions';
import {
  downloadTemplateItem,
  fetchDocCategories,
  fetchDocDetail,
  fetchDocList,
  fetchInternshipArchiveMaterials,
  fetchInternshipApplications,
  fetchInternshipArrangementChanges,
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
  fetchInternshipCourseScores,
  fetchInternshipScores,
  fetchInternshipSignIns,
  fetchInternshipSyllabusGuides,
  fetchInternshipImplementationSheets,
  fetchInternshipTeacherWorkReports,
  fetchInternshipInspections,
  fetchInternshipTimeline,
  fetchMessages,
  fetchMessageSummary,
  fetchRegisterOptions,
  fetchSwitchableAccounts,
  fetchPracticeList,
  fetchPracticeOptions,
  fetchPracticeOverview,
  fetchPracticeExecutionList,
  fetchPracticeExecutionTimeline,
  fetchPracticeTimeline,
  fetchTemplateCategories,
  fetchTemplateList,
  markMessagesRead,
  requestInternshipModification,
  requestPracticeExecutionModification,
  requestPracticeModification,
  reviewInternshipApplication,
  reviewInternshipArrangementChange,
  reviewInternshipDelay,
  reviewInternshipJournal,
  reviewInternshipPlan,
  reviewInternshipReport,
  reviewPracticeExecution,
  reviewPracticeItem,
  saveInternshipApplication,
  saveInternshipDelay,
  saveInternshipJournal,
  saveInternshipReport,
  saveInternshipScore,
  saveInternshipSignIn,
  savePracticeExecution,
  savePracticeProjectScore,
  passkeyLogin,
  login as loginApi,
  logout as logoutApi,
  registerAccount,
  switchAccount,
} from './api/system';

const { state, hasPermission, load } = useMobilePermissions();
const activeTab = ref('home');
const loginForm = reactive({
  login_name: 'admin',
  password: 'admin123456',
});
const loginState = reactive({
  loading: false,
  message: '',
});
const registerForm = reactive({
  role_type: 'student',
  name: '',
  login_name: '',
  password: 'admin123456',
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
  message: '',
  items: [],
});
const messageState = reactive({
  loading: false,
  message: '',
  filter: 'all',
  type: 'all',
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

const messageTypeNames = {
  system: '系统通知',
  todo: '待办提醒',
  result: '处理结果',
  audit: '审核通知',
  alert: '预警提醒',
};
const mobileMessageTypeOptions = [
  { label: '全部类型', value: 'all' },
  { label: '待办', value: 'todo' },
  { label: '审核', value: 'audit' },
  { label: '结果', value: 'result' },
  { label: '预警', value: 'alert' },
  { label: '系统', value: 'system' },
];
const messageLevelNames = {
  normal: '普通',
  important: '重要',
  urgent: '紧急',
};

const support = reactive({
  doc: {
    loading: false,
    message: '',
    categories: [],
    items: [],
    detail: {
      visible: false,
      loading: false,
      message: '',
      article: null,
    },
    filters: {
      category_id: '',
      keyword: '',
    },
    pagination: {
      page: 1,
      page_size: 20,
      total: 0,
    },
  },
  template: {
    loading: false,
    message: '',
    categories: [],
    items: [],
    filters: {
      category_id: '',
      keyword: '',
    },
    pagination: {
      page: 1,
      page_size: 20,
      total: 0,
    },
  },
});

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
};

const internship = reactive({
  loading: false,
  message: '',
  panel: 'workbench',
  submitSection: '',
  reviewList: 'applications',
  manageList: 'arrangements',
  overview: emptyInternshipOverview(),
  options: emptyInternshipOptions(),
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
    insurances: emptyPagedList(),
    safetyLetters: emptyPagedList(),
  },
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
    insurances: emptyInternshipFilters(),
    safetyLetters: emptyInternshipFilters(),
  },
  forms: {
    application: {
      arrangement_id: null,
      type: 'distributed',
      remark: '',
    },
    sign: {
      arrangement_id: null,
      location: '',
      longitude: null,
      latitude: null,
      accuracy: null,
      located_at: '',
      locating: false,
      gps_error: '',
    },
    journal: {
      id: null,
      arrangement_id: null,
      title: '',
      content: '',
    },
    report: {
      id: null,
      arrangement_id: null,
      title: '',
      content: '',
    },
    delay: {
      id: null,
      arrangement_id: null,
      config_key: 'report_deadline',
      requested_date: '',
      reason: '',
    },
    score: {
      pair_id: null,
      student_id: null,
      arrangement_id: null,
      sign_in_score: '',
      journal_score: '',
      report_score: '',
      enterprise_score: '',
    },
  },
  reviewDialog: {
    visible: false,
    mode: 'review',
    entity: 'application',
    status: 'accept',
    row: null,
    reason: '',
  },
  timelineDialog: {
    visible: false,
    loading: false,
    entity: 'application',
    row: null,
    title: '',
    subtitle: '',
    items: [],
    cycles: [],
    message: '',
  },
});

const practice = reactive({
  training: createPracticeState(),
  lab: createPracticeState(),
});

const practiceReviewDialog = reactive({
  visible: false,
  mode: 'review',
  module: 'training',
  panel: 'plans',
  entity: 'plan',
  status: 'accept',
  row: null,
  reason: '',
});
const practiceExecutionDialog = reactive({
  visible: false,
  module: 'training',
  panel: 'journals',
  execution: 'journal',
  row: null,
  form: {
    id: null,
    project_id: null,
    title: '',
    date: '',
    content: '',
    location: '',
    longitude: '',
    latitude: '',
    accuracy: null,
    located_at: '',
    locating: false,
    gps_error: '',
    remark: '',
  },
});
const mobileNavigationState = reactive({
  backStack: [],
  forwardStack: [],
  restoring: false,
});

const modules = [
  {
    key: 'internship',
    title: '实习管理',
    desc: '任务、签到、日志和任务老师入口',
    icon: BriefcaseBusiness,
    theme: 'blue',
    permission: 'internship:view',
    flow: '按实习文档开发',
  },
  {
    key: 'training',
    title: '实训管理',
    desc: '实训模块入口',
    icon: Workflow,
    theme: 'teal',
    permission: 'training:view',
    flow: '-',
  },
  {
    key: 'lab',
    title: '实验管理',
    desc: '实验模块入口',
    icon: FlaskConical,
    theme: 'green',
    permission: 'lab:view',
    flow: '-',
  },
  {
    key: 'doc',
    title: '文档中心',
    desc: '制度流程和常见问题',
    icon: BookOpen,
    theme: 'green',
    permission: 'doc:view',
    flow: '-',
  },
  {
    key: 'templateLib',
    title: '模板库',
    desc: '材料模板查看和下载',
    icon: FileText,
    theme: 'teal',
    permission: 'template:view',
    flow: '-',
  },
];

const currentPage = computed(() => {
  if (activeTab.value === 'home') {
    return { title: '首页', desc: '移动端工作台', theme: 'blue', icon: Home, permission: '', flow: '-' };
  }
  if (activeTab.value === 'mine') {
    return { title: '我的', desc: '个人信息', theme: 'gray', icon: UserRound, permission: '', flow: '-' };
  }
  if (activeTab.value === 'message') {
    return { title: '消息中心', desc: '待办、审核结果和系统通知', theme: 'teal', icon: MessageCircle, permission: '', flow: '-' };
  }
  return modules.find(item => item.key === activeTab.value) || modules[0];
});

const summaries = computed(() => [
  { name: '学校', value: schoolShortText.value },
  { name: '角色', value: roleDisplayText.value },
  { name: '范围', value: scopeText.value },
]);
const messageUnreadCount = computed(() => Number(messageState.summary.unread || 0));
const messageGroups = computed(() => groupMessagesByDay(messageState.items));
const canGoMobileBack = computed(() => mobileNavigationState.backStack.length > 0);
const canGoMobileForward = computed(() => mobileNavigationState.forwardStack.length > 0);

const isLoggedIn = computed(() => Boolean(state.context.account_id));
const roleType = computed(() => state.context.role_type || '');
const isStudentRole = computed(() => roleType.value === 'student');
const isTeacherRole = computed(() => roleType.value === 'teacher');
const isAdminRole = computed(() => ['super_admin', 'school_admin', 'college_admin', 'profession_admin'].includes(roleType.value));
const visibleMobileModules = computed(() => modules.filter(canShowMobileModule));
const canReviewInternship = computed(() => hasPermission('internship:approve'));
const canReviewInternshipPlan = computed(() => hasPermission('internship:plan') && isAdminRole.value);
const signGpsReady = computed(() => hasCoordinateValue(internship.forms.sign.longitude) && hasCoordinateValue(internship.forms.sign.latitude));
const signGpsTitle = computed(() => (signGpsReady.value ? '已获取 GPS 定位' : '等待 GPS 定位'));
const signGpsHint = computed(() => (signGpsReady.value ? '坐标来自当前设备定位' : '签到前请先授权并获取当前位置'));
const signMapUrl = computed(() => coordinateMapUrl(internship.forms.sign.longitude, internship.forms.sign.latitude, signGpsReady.value));
const practiceGpsReady = computed(() => hasCoordinateValue(practiceExecutionDialog.form.longitude) && hasCoordinateValue(practiceExecutionDialog.form.latitude));
const practiceGpsTitle = computed(() => (practiceGpsReady.value ? '已获取 GPS 定位' : '等待 GPS 定位'));
const practiceGpsHint = computed(() => (practiceGpsReady.value ? '坐标来自当前设备定位' : '签到前请先授权并获取当前位置'));
const practiceMapUrl = computed(() => coordinateMapUrl(practiceExecutionDialog.form.longitude, practiceExecutionDialog.form.latitude, practiceGpsReady.value));
const signAccuracyText = computed(() => accuracyDisplayText(internship.forms.sign.accuracy));
const practiceAccuracyText = computed(() => accuracyDisplayText(practiceExecutionDialog.form.accuracy));

function coordinateMapUrl(longitudeValue, latitudeValue, ready) {
  if (!ready) {
    return '';
  }
  const longitude = Number(longitudeValue).toFixed(6);
  const latitude = Number(latitudeValue).toFixed(6);
  return `https://staticmap.openstreetmap.de/staticmap.php?center=${latitude},${longitude}&zoom=16&size=640x300&markers=${latitude},${longitude},red-pushpin`;
}

function accuracyDisplayText(value) {
  const accuracy = Number(value || 0);
  return accuracy > 0 ? `${Math.round(accuracy)} 米` : '-';
}
const roleNameMap = {
  super_admin: '系统管理员',
  school_admin: '学校管理员',
  college_admin: '学院管理员',
  profession_admin: '专业管理员',
  teacher: '任务老师',
  student: '学生',
  enterprise: '企业导师',
};

function canShowMobileModule(module) {
  if (!hasPermission(module.permission)) {
    return false;
  }
  if (['training', 'lab'].includes(module.key)) {
    return ['student', 'teacher', 'super_admin', 'school_admin', 'college_admin', 'profession_admin'].includes(roleType.value);
  }
  if (module.key === 'internship') {
    return ['student', 'teacher', 'super_admin', 'school_admin', 'college_admin', 'profession_admin'].includes(roleType.value);
  }
  return true;
}

function isMobileModuleVisible(key) {
  return visibleMobileModules.value.some(module => module.key === key);
}
const scopeNameMap = {
  dep_id: '学院',
  profession_id: '专业',
  company_id: '企业',
  teacher_user_id: '本人任务学生',
  student_user_id: '本人实习数据',
};
const roleDisplayText = computed(() => state.context.role_name || roleNameMap[roleType.value] || state.context.role_id || '未登录');
const roleText = computed(() => roleDisplayText.value);
const registerRoleOptions = computed(() => registerState.roles.length
  ? registerState.roles
  : [
      { role_type: 'student', name: '学生' },
      { role_type: 'teacher', name: '教师' },
    ]);
const userText = computed(() => state.context.user_name || state.context.name || state.context.login_name || '未登录');
const accountText = computed(() => state.context.login_name || '-');
const schoolText = computed(() => state.context.school_name || '成都锦城学院');
const schoolShortText = computed(() => schoolText.value.replace('成都', '').replace('学院', '') || schoolText.value);
const scopeText = computed(() => {
  if (!isLoggedIn.value) {
    return '未登录';
  }
  const filter = scopeFilter.value;
  if (!filter) {
    return '全校';
  }
  if (filter.deny_all) {
    return '无权限';
  }
  const names = activeScopeEntries.value.map(([key]) => scopeNameMap[key] || key);
  return names.length ? names.join(' / ') : '全校';
});
const scopeDetailText = computed(() => {
  const entries = activeScopeEntries.value;
  if (!isLoggedIn.value) {
    return '登录后显示当前账号可查看的数据范围';
  }
  if (!entries.length) {
    return '可查看全校数据';
  }
  return entries.map(([key, value]) => {
    const name = scopeNameMap[key] || key;
    return Array.isArray(value) ? `${name} ${value.length} 项` : name;
  }).join('，');
});
const scopeFilter = computed(() => state.dataScope?.filter || state.context.data_scope?.filter || null);
const activeScopeEntries = computed(() => {
  const filter = scopeFilter.value;
  if (!filter || filter.deny_all) {
    return [];
  }
  return Object.entries(filter).filter(([, value]) => {
    if (Array.isArray(value)) {
      return value.length > 0;
    }
    return value !== null && value !== undefined && value !== '' && value !== false;
  });
});
const internshipRoleTitle = computed(() => {
  if (isStudentRole.value) {
    return '学生实习';
  }
  if (isTeacherRole.value) {
    return '任务指导';
  }
  if (isAdminRole.value) {
    return '实习管理';
  }
  return '实习';
});
const internshipRoleDesc = computed(() => {
  if (isStudentRole.value) {
    return '查看任务、签到、日志和报告提交';
  }
  if (isTeacherRole.value) {
    return '按任务评阅材料和录入成绩';
  }
  if (isAdminRole.value) {
    return '查看计划、任务、任务绑定和数据状态';
  }
  return '按当前角色展示可用实习功能';
});
const internshipPanels = computed(() => {
  if (isStudentRole.value) {
    return [
      { key: 'workbench', name: '概况', icon: Home },
      { key: 'apply', name: '任务', icon: ClipboardList },
      { key: 'submit', name: '提交', icon: Send },
      { key: 'score', name: '成绩', icon: GraduationCap },
    ];
  }
  if (isTeacherRole.value) {
    return [
      { key: 'workbench', name: '概况', icon: Home },
      { key: 'review', name: '审核', icon: CheckCircle2 },
      { key: 'score', name: '成绩', icon: GraduationCap },
    ];
  }
  if (isAdminRole.value) {
    return [
      { key: 'workbench', name: '概况', icon: Home },
      { key: 'review', name: '审核', icon: CheckCircle2 },
      { key: 'manage', name: '数据', icon: CalendarCheck },
    ];
  }
  return [{ key: 'workbench', name: '概况', icon: Home }];
});
const reviewStatusOptions = [
  { value: 'wait', label: '待审核' },
  { value: 'accept', label: '已通过' },
  { value: 'modify', label: '需修改' },
];
const practiceEnabledStatusOptions = [
  { value: 'enabled', label: '启用' },
  { value: 'completed', label: '已完成' },
  { value: 'disabled', label: '停用' },
];
const delayStatusOptions = [
  { value: 'wait', label: '待审核' },
  { value: 'accept', label: '已通过' },
  { value: 'refuse', label: '已退回' },
];
const mobileListConfigs = computed(() => ({
  arrangements: {
    key: 'arrangements',
    entity: 'arrangement',
    title: '实习任务',
    shortTitle: '任务',
    icon: CalendarCheck,
    keywordPlaceholder: '任务、课程、届次、学院、专业',
    gradeFilter: true,
    statusOptions: [
      { value: 'enabled', label: '启用' },
      { value: 'completed', label: '已完成' },
      { value: 'changing', label: '变更中' },
      { value: 'changed', label: '已变更' },
      { value: 'disabled', label: '停用' },
    ],
    emptyText: '暂无实习任务',
  },
  arrangementChanges: {
    key: 'arrangementChanges',
    entity: 'arrangement_change',
    title: '任务变更',
    shortTitle: '变更',
    icon: Workflow,
    keywordPlaceholder: '任务、课程、教师、原因、提交人',
    gradeFilter: true,
    statusOptions: [
      { value: 'wait', label: '待审核' },
      { value: 'accept', label: '已通过' },
      { value: 'modify', label: '需修改' },
      { value: 'refuse', label: '已退回' },
    ],
    emptyText: '暂无任务变更',
  },
  plans: {
    key: 'plans',
    entity: 'plan',
    title: '实习计划',
    shortTitle: '计划',
    icon: FileText,
    keywordPlaceholder: '学院、提交人',
    statusOptions: reviewStatusOptions,
    emptyText: '暂无实习计划',
  },
  syllabusGuides: {
    key: 'syllabusGuides',
    entity: 'syllabus_guide',
    title: '大纲指导书',
    shortTitle: '大纲',
    icon: BookOpen,
    keywordPlaceholder: '标题、安排、学院、专业',
    gradeFilter: true,
    statusOptions: [
      { value: 'draft', label: '草稿' },
      { value: 'published', label: '已发布' },
    ],
    emptyText: '暂无大纲指导书',
  },
  implementationSheets: {
    key: 'implementationSheets',
    entity: 'implementation_sheet',
    title: '实施表',
    shortTitle: '实施',
    icon: ClipboardList,
    keywordPlaceholder: '安排、学院、专业、教师',
    gradeFilter: true,
    statusOptions: [
      { value: 'draft', label: '草稿' },
      { value: 'confirmed', label: '已确认' },
    ],
    emptyText: '暂无实施表',
  },
  applications: {
    key: 'applications',
    entity: 'application',
    title: '特殊申请',
    shortTitle: '特申',
    icon: ClipboardList,
    keywordPlaceholder: '学生、学号、实习任务、教师',
    gradeFilter: true,
    statusOptions: reviewStatusOptions,
    emptyText: '暂无特殊申请',
  },
  pairs: {
    key: 'pairs',
    entity: '',
    title: '任务绑定',
    shortTitle: '绑定',
    icon: UsersRound,
    keywordPlaceholder: '学生、学号、教师、安排',
    gradeFilter: true,
    statusOptions: [
      { value: 'active', label: '有效' },
      { value: 'removed', label: '已移除' },
    ],
    emptyText: '暂无任务绑定',
  },
  signIns: {
    key: 'signIns',
    entity: 'sign_in',
    title: '签到记录',
    shortTitle: '签到',
    icon: MapPin,
    keywordPlaceholder: '学生、学号、安排、地点',
    gradeFilter: true,
    statusOptions: [],
    emptyText: '暂无签到记录',
  },
  journals: {
    key: 'journals',
    entity: 'journal',
    title: '日志评阅',
    shortTitle: '日志',
    icon: FileClock,
    keywordPlaceholder: '学生、标题、内容、教师',
    gradeFilter: true,
    statusOptions: reviewStatusOptions,
    emptyText: '暂无日志记录',
  },
  reports: {
    key: 'reports',
    entity: 'report',
    title: '报告评阅',
    shortTitle: '报告',
    icon: FileText,
    keywordPlaceholder: '学生、标题、内容、教师',
    gradeFilter: true,
    statusOptions: reviewStatusOptions,
    emptyText: '暂无报告记录',
  },
  teacherWorkReports: {
    key: 'teacherWorkReports',
    entity: 'teacher_work_report',
    title: '教师工作报告',
    shortTitle: '工作报告',
    icon: FileText,
    keywordPlaceholder: '安排、教师、总结、问题',
    gradeFilter: true,
    statusOptions: reviewStatusOptions,
    emptyText: '暂无教师工作报告',
  },
  delays: {
    key: 'delays',
    entity: 'delay',
    title: '延期申请',
    shortTitle: '延期',
    icon: FileClock,
    keywordPlaceholder: '学生、学号、安排、原因',
    gradeFilter: true,
    statusOptions: delayStatusOptions,
    emptyText: '暂无延期申请',
  },
  scores: {
    key: 'scores',
    entity: 'score',
    title: '任务成绩',
    shortTitle: '任务成绩',
    icon: GraduationCap,
    keywordPlaceholder: '学生、学号、安排、教师',
    gradeFilter: true,
    statusOptions: [],
    emptyText: '暂无成绩记录',
  },
  courseScores: {
    key: 'courseScores',
    entity: '',
    title: '课程成绩汇总',
    shortTitle: '课程成绩',
    icon: GraduationCap,
    keywordPlaceholder: '学生、学号、课程、任务',
    gradeFilter: true,
    statusOptions: [],
    emptyText: '暂无课程成绩汇总',
  },
  inspections: {
    key: 'inspections',
    entity: 'inspection',
    title: '巡查记录',
    shortTitle: '巡查',
    icon: Search,
    keywordPlaceholder: '安排、学生、巡查人、说明',
    gradeFilter: true,
    statusOptions: [
      { value: 'pass', label: '通过' },
      { value: 'fail', label: '不通过' },
    ],
    statusKey: 'result',
    emptyText: '暂无巡查记录',
  },
  archiveMaterials: {
    key: 'archiveMaterials',
    entity: '',
    title: '归档材料',
    shortTitle: '归档',
    icon: FileText,
    keywordPlaceholder: '学生、学号、安排、材料',
    gradeFilter: true,
    statusOptions: [
      { value: 'complete', label: '完整' },
      { value: 'incomplete', label: '待补齐' },
    ],
    emptyText: '暂无归档材料',
  },
  insurances: {
    key: 'insurances',
    entity: 'insurance',
    title: '保险记录',
    shortTitle: '保险',
    icon: FileText,
    keywordPlaceholder: '学生、学号、安排、保单',
    gradeFilter: true,
    statusOptions: [],
    emptyText: '暂无保险记录',
  },
  safetyLetters: {
    key: 'safetyLetters',
    entity: 'safety_letter',
    title: '安全承诺',
    shortTitle: '承诺',
    icon: CheckCircle2,
    keywordPlaceholder: '学生、学号、安排',
    gradeFilter: true,
    statusOptions: [
      { value: 'pending', label: '待签署' },
      { value: 'signed', label: '已签署' },
    ],
    emptyText: '暂无安全承诺',
  },
}));
const reviewListTabs = computed(() => {
  const keys = isTeacherRole.value
    ? ['journals', 'reports', 'delays', 'applications']
    : ['arrangementChanges', 'plans', 'delays', 'applications'];
  if (!canReviewInternshipPlan.value) {
    const planIndex = keys.indexOf('plans');
    if (planIndex >= 0) {
      keys.splice(planIndex, 1);
    }
  }
  return keys.map(getMobileListConfig).filter(Boolean);
});
const manageListTabs = computed(() => [
  'plans',
  'arrangements',
  'pairs',
  'arrangementChanges',
  'syllabusGuides',
  'implementationSheets',
  'signIns',
  'journals',
  'reports',
  'teacherWorkReports',
  'delays',
  'scores',
  'courseScores',
  'inspections',
  'archiveMaterials',
].map(getMobileListConfig).filter(Boolean));
const currentReviewListConfig = computed(() => getMobileListConfig(internship.reviewList) || reviewListTabs.value[0] || null);
const currentManageListConfig = computed(() => getMobileListConfig(internship.manageList) || manageListTabs.value[0] || null);
const internshipSummaries = computed(() => [
  { name: '任务', value: internship.overview.arrangements || 0 },
  { name: '待审', value: internship.overview.applications_waiting || 0 },
  { name: '绑定', value: internship.overview.active_pairs || 0 },
]);
const studentFlowSteps = [
  '查看管理员分配的实习任务',
  '在任务中确认负责老师和阶段时间',
  '签到、日志、报告按阶段提交',
  '退回或需修改时重新提交',
  '完成归档和成绩确认',
];
const studentSubmitCards = computed(() => [
  {
    key: 'sign',
    title: '签到',
    desc: '提交当天实习位置',
    icon: MapPin,
    meta: `${internship.lists.signIns.pagination.total || 0} 条`,
  },
  {
    key: 'journal',
    title: '实习日志',
    desc: '填写过程记录，需修改可重新提交',
    icon: FileClock,
    meta: stageDeadlineText('journal_deadline'),
    expired: isStageExpired('journal_deadline'),
  },
  {
    key: 'report',
    title: '实习报告',
    desc: '提交阶段或总结报告',
    icon: FileText,
    meta: stageDeadlineText('report_deadline'),
    expired: isStageExpired('report_deadline'),
  },
  {
    key: 'delay',
    title: '延期申请',
    desc: '针对日志、报告等提交阶段申请延期',
    icon: FileClock,
    meta: delayConfigText(internship.forms.delay.config_key),
  },
]);
const internshipWorkbenchCells = computed(() => {
  if (isStudentRole.value) {
    return [
      { title: '我的任务', label: '已绑定的实习任务', value: internship.options.arrangements.length || '-' },
      { title: '已评分任务', label: '按任务记录成绩', value: internship.lists.scores.pagination.total || 0 },
      { title: '特殊申请', label: '分散、自主等场景', value: internship.lists.applications.pagination.total || 0 },
    ];
  }
  if (isTeacherRole.value) {
    return [
      { title: '待评日志', label: '学生提交的实习日志', value: internship.overview.journals_waiting || 0 },
      { title: '待评报告', label: '学生提交的实习报告', value: internship.overview.reports_waiting || 0 },
      { title: '特殊申请', label: '分散、自主等场景', value: internship.lists.applications.pagination.total || 0 },
    ];
  }
  return [
    { title: '实习任务', label: '全校实习任务', value: internship.overview.arrangements || 0 },
    { title: '任务绑定', label: '学生与任务老师绑定', value: internship.overview.active_pairs || 0 },
    { title: '特殊申请', label: '分散、自主等场景待审', value: internship.overview.applications_waiting || 0 },
  ];
});

const practiceFlowSteps = [
  '教学计划来源于教务拉取或教师填报',
  '课表明确老师、班级、时间和地点',
  '课表发布为项目并绑定学生范围',
  '成绩比例配置后录入成绩并统计',
];
const practicePanelDefinitions = [
  { key: 'plans', entity: 'plan', title: '教学计划', shortTitle: '计划', icon: FileText, review: true, emptyText: '暂无教学计划' },
  { key: 'schedules', entity: 'schedule', title: '课表安排', shortTitle: '课表', icon: CalendarCheck, review: false, emptyText: '暂无课表安排' },
  { key: 'projects', entity: 'project', title: '项目发布', shortTitle: '项目', icon: ClipboardList, review: false, emptyText: '暂无项目' },
  { key: 'signIns', entity: 'sign_in', execution: 'sign_in', title: '签到记录', shortTitle: '签到', icon: MapPin, review: false, emptyText: '暂无签到记录' },
  { key: 'journals', entity: 'journal', execution: 'journal', title: '过程日志', shortTitle: '日志', icon: FileClock, review: true, emptyText: '暂无日志记录' },
  { key: 'reports', entity: 'report', execution: 'report', title: '总结报告', shortTitle: '报告', icon: FileText, review: true, emptyText: '暂无报告记录' },
  { key: 'syllabus', entity: 'syllabus', title: '大纲编写', shortTitle: '大纲', icon: BookOpen, review: true, emptyText: '暂无大纲' },
  { key: 'lessonPlans', entity: 'lessonPlan', title: '教案编写', shortTitle: '教案', icon: FileText, review: true, emptyText: '暂无教案' },
  { key: 'gradeRules', entity: 'gradeRule', title: '成绩比例', shortTitle: '比例', icon: GraduationCap, review: false, emptyText: '暂无成绩比例' },
  { key: 'scores', entity: 'score', title: '成绩评定', shortTitle: '成绩', icon: GraduationCap, review: false, emptyText: '暂无成绩记录' },
  { key: 'reflections', entity: 'reflection', title: '反思报告', shortTitle: '反思', icon: FileClock, review: true, emptyText: '暂无反思报告' },
];

const practiceReviewDialogTitle = computed(() => {
  const action = practiceReviewDialog.mode === 'reopen'
    ? '通过后修改'
    : (practiceReviewDialog.status === 'accept' ? '通过' : '退回');
  return `${action}${practiceEntityName(practiceReviewDialog.entity)}`;
});
const practiceReviewReasonLabel = computed(() => (
  practiceReviewDialog.mode === 'reopen' ? '修改理由' : (practiceReviewDialog.status === 'modify' ? '退回原因' : '审核意见')
));
const practiceReviewDialogRuleText = computed(() => (
  practiceRuleText(practiceReviewDialog.module, practiceReviewDialog.entity, practiceReviewDialog.status, practiceReviewReasonLabel.value)
));
const practiceReviewConfirmText = computed(() => (
  practiceReviewDialog.mode === 'reopen'
    ? '确认修改'
    : (practiceReviewDialog.status === 'accept' ? '确认通过' : '确认退回')
));
const practiceReviewTargetDetails = computed(() => {
  const row = practiceReviewDialog.row;
  if (!row) {
    return [];
  }
  return [
    detailItem('模块', practiceModuleName(practiceReviewDialog.module)),
    detailItem('业务', practiceEntityName(practiceReviewDialog.entity)),
    detailItem('标题', row.title || row.name),
    detailItem('届次', row.grade_name),
    detailItem('学院专业', joinFact([row.dep_name, row.profession_name])),
    detailItem('当前状态', statusText(row.status)),
  ].filter(Boolean);
});
const practiceExecutionDialogTitle = computed(() => {
  const names = { sign_in: '签到', journal: '提交日志', report: '提交报告' };
  return `${practiceModuleName(practiceExecutionDialog.module)}${names[practiceExecutionDialog.execution] || '提交'}`;
});
const practiceExecutionDialogSubtitle = computed(() => {
  const project = practiceModule(practiceExecutionDialog.module).options.projects.find(item => Number(item.id) === Number(practiceExecutionDialog.form.project_id || 0));
  return project?.title || project?.course_name || '请选择项目';
});

function emptyPagedList() {
  return {
    items: [],
    pagination: {
      page: 1,
      page_size: 10,
      total: 0,
    },
  };
}

function createPracticeState() {
  const panels = ['plans', 'schedules', 'projects', 'signIns', 'journals', 'reports', 'syllabus', 'lessonPlans', 'gradeRules', 'scores', 'reflections'];
  return {
    loading: false,
    message: '',
    panel: 'plans',
    overview: {
      plans_waiting: 0,
      schedules: 0,
      projects: 0,
      syllabus_waiting: 0,
      lesson_plans_waiting: 0,
      scores_submitted: 0,
      reflections_waiting: 0,
      today_schedules: 0,
    },
    options: {
      grades: [],
      departments: [],
      professions: [],
      classes: [],
      teachers: [],
      students: [],
      rooms: [],
      plans: [],
      schedules: [],
      projects: [],
      review_rules: {},
    },
    filters: Object.fromEntries(panels.map(panel => [panel, emptyPracticeFilters()])),
    lists: Object.fromEntries(panels.map(panel => [panel, emptyPagedList()])),
  };
}

function emptyPracticeFilters() {
  return {
    grade_id: '',
    dep_id: '',
    profession_id: '',
    class_id: '',
    plan_id: '',
    status: '',
    keyword: '',
  };
}

function emptyInternshipFilters() {
  return {
    grade_id: '',
    dep_id: '',
    profession_id: '',
    class_id: '',
    arrangement_id: '',
    status: '',
    result: '',
    keyword: '',
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
    plans: [],
    arrangements: [],
    grades: [],
    departments: [],
    professions: [],
    classes: [],
    teachers: [],
    report_templates: [],
    review_rules: defaultInternshipReviewRules,
    deadline_configs: {},
  };
}

function getMobileListConfig(key) {
  return mobileListConfigs.value?.[key] || null;
}

const studentScopedListKeys = new Set([
  'applications',
  'pairs',
  'signIns',
  'journals',
  'reports',
  'delays',
  'scores',
  'courseScores',
  'archiveMaterials',
  'insurances',
  'safetyLetters',
]);

function selectFilterItems(items, valueKey, labelKey) {
  return (items || []).map(item => ({
    value: item[valueKey],
    label: item[labelKey] || item[valueKey],
  }));
}

function mobileDepartmentOptions(key) {
  return mobileDepartmentOptionsByValues(internship.filters[key] || {});
}

function mobileDepartmentOptionsByValues(filters = {}) {
  const gradeId = Number(filters.grade_id || 0);
  const grade = internship.options.grades.find(item => Number(item.grade_id) === gradeId);
  if (grade?.dep_id) {
    return internship.options.departments.filter(item => Number(item.dep_id) === Number(grade.dep_id));
  }
  return internship.options.departments;
}

function mobileProfessionOptions(key) {
  return mobileProfessionOptionsByValues(internship.filters[key] || {});
}

function mobileProfessionOptionsByValues(filters = {}) {
  const gradeId = Number(filters.grade_id || 0);
  const depId = Number(filters.dep_id || 0);
  return internship.options.professions.filter((item) => {
    const matchGrade = !gradeId || Number(item.grade_id || 0) === gradeId;
    const matchDepartment = !depId || Number(item.dep_id || 0) === depId;
    return matchGrade && matchDepartment;
  });
}

function mobileClassOptions(key) {
  return mobileClassOptionsByValues(internship.filters[key] || {});
}

function mobileClassOptionsByValues(filters = {}) {
  const gradeId = Number(filters.grade_id || 0);
  const depId = Number(filters.dep_id || 0);
  const professionId = Number(filters.profession_id || 0);
  return internship.options.classes.filter((item) => {
    const matchGrade = !gradeId || Number(item.grade_id || 0) === gradeId;
    const matchDepartment = !depId || Number(item.dep_id || 0) === depId;
    const matchProfession = !professionId || Number(item.profession_id || 0) === professionId;
    return matchGrade && matchDepartment && matchProfession;
  });
}

function mobileListSelectFilters(config) {
  if (!config) {
    return [];
  }
  const key = config.key;
  const filters = [];
  if (config.gradeFilter) {
    filters.push({
      key: 'grade_id',
      label: '届次',
      placeholder: '全部届次',
      options: selectFilterItems(internship.options.grades, 'grade_id', 'grade_name'),
    });
  }
  if (isAdminRole.value && (studentScopedListKeys.has(key) || ['arrangements', 'arrangementChanges', 'plans', 'syllabusGuides', 'implementationSheets', 'teacherWorkReports', 'inspections'].includes(key))) {
    filters.push({
      key: 'dep_id',
      label: '学院',
      placeholder: '全部学院',
      options: selectFilterItems(mobileDepartmentOptions(key), 'dep_id', 'dep_name'),
    });
  }
  if (isAdminRole.value && (studentScopedListKeys.has(key) || ['arrangements', 'arrangementChanges', 'syllabusGuides', 'implementationSheets', 'teacherWorkReports', 'inspections'].includes(key))) {
    filters.push({
      key: 'profession_id',
      label: '专业',
      placeholder: '全部专业',
      options: selectFilterItems(mobileProfessionOptions(key), 'profession_id', 'profession_name'),
    });
  }
  if (isAdminRole.value && studentScopedListKeys.has(key)) {
    filters.push({
      key: 'class_id',
      label: '班级',
      placeholder: '全部班级',
      options: selectFilterItems(mobileClassOptions(key), 'class_id', 'class_name'),
    });
  }
  return filters;
}

function mobileListToolClass(config) {
  return {
    compact: !config?.statusOptions?.length && !mobileListSelectFilters(config).length,
    'with-filters': mobileListSelectFilters(config).length > 0,
  };
}

function normalizeMobileListFilters(key) {
  const filters = internship.filters[key] || {};
  if (filters.dep_id && !mobileDepartmentOptions(key).some(item => Number(item.dep_id) === Number(filters.dep_id))) {
    filters.dep_id = '';
  }
  if (filters.profession_id && !mobileProfessionOptions(key).some(item => Number(item.profession_id) === Number(filters.profession_id))) {
    filters.profession_id = '';
  }
  if (filters.class_id && !mobileClassOptions(key).some(item => Number(item.class_id) === Number(filters.class_id))) {
    filters.class_id = '';
  }
}

function handleMobileListFilterChange(key) {
  normalizeMobileListFilters(key);
  reloadInternshipList(key);
}

function normalizeInternshipListViews() {
  const reviewKeys = reviewListTabs.value.map(item => item.key);
  if (reviewKeys.length && !reviewKeys.includes(internship.reviewList)) {
    internship.reviewList = reviewKeys[0];
  }

  const manageKeys = manageListTabs.value.map(item => item.key);
  if (manageKeys.length && !manageKeys.includes(internship.manageList)) {
    internship.manageList = manageKeys[0];
  }
}

function mobileListRows(key) {
  return internship.lists[key]?.items || [];
}

function listTotal(key) {
  return internship.lists[key]?.pagination?.total || 0;
}

function mobileListTitle(key, row) {
  const student = row.student_name || row.student_num || (row.student_id ? `学生ID ${row.student_id}` : '');
  const arrangement = row.arrangement_title || (row.arrangement_id ? `任务ID ${row.arrangement_id}` : '');
  const changePayload = arrangementChangePayload(row);
  const titles = {
    arrangements: row.title || row.name || `任务ID ${row.id}`,
    arrangementChanges: changePayload.title || row.arrangement_title || `变更ID ${row.id}`,
    // 暂时隐藏学期展示，后续需要时恢复 row.semester。
    plans: row.dep_name || `计划ID ${row.id}`,
    syllabusGuides: row.title || row.arrangement_title || `大纲ID ${row.id}`,
    implementationSheets: row.arrangement_title || `实施表ID ${row.id}`,
    applications: student || arrangement || `申请ID ${row.id}`,
    pairs: student || `关系ID ${row.id}`,
    signIns: student || arrangement || `签到ID ${row.id}`,
    journals: row.title || student || `日志ID ${row.id}`,
    reports: row.title || student || `报告ID ${row.id}`,
    teacherWorkReports: joinFact([row.teacher_name, row.arrangement_title]) || `工作报告ID ${row.id}`,
    delays: joinFact([student, delayConfigText(row.config_key)]) || `延期ID ${row.id}`,
    scores: student || `成绩ID ${row.id}`,
    courseScores: joinFact([row.student_name, row.student_num]) || `课程成绩 ${row.plan_id}-${row.student_id}`,
    inspections: joinFact([row.arrangement_title, row.student_name]) || `巡查ID ${row.id}`,
    archiveMaterials: student || arrangement || `归档ID ${row.id}`,
    insurances: student || row.insurance_company || `保险ID ${row.id}`,
    safetyLetters: student || `承诺ID ${row.id}`,
  };
  return titles[key] || row.title || row.name || `记录ID ${row.id}`;
}

function mobileListValue(key, row) {
  if (key === 'arrangementChanges') {
    return statusText(row.status);
  }
  if (key === 'signIns') {
    return signTypeText(row.sign_type);
  }
  if (key === 'scores') {
    return row.final_score !== null && row.final_score !== undefined ? `总评 ${row.final_score}` : '-';
  }
  if (key === 'courseScores') {
    return row.course_final_score !== null && row.course_final_score !== undefined ? `${row.course_final_score} 分` : '-';
  }
  if (key === 'insurances') {
    return row.policy_number || statusText(row.status);
  }
  if (key === 'archiveMaterials') {
    return row.archive_status_text || row.material_progress || statusText(row.archive_status);
  }
  if (key === 'inspections') {
    return inspectionResultText(row.result);
  }
  return statusText(row.status);
}

function mobileListFacts(key, row) {
  const student = joinFact([row.student_name, row.student_num]);
  const arrangement = row.arrangement_title || (row.arrangement_id ? `任务ID ${row.arrangement_id}` : '');
  const changePayload = arrangementChangePayload(row);
  const facts = {
    arrangements: [
      // 暂时隐藏学期字段，后续需要时恢复。
      // namedFact('学期', row.semester),
      namedFact('任务编号', row.task_no),
      namedFact('批次', row.batch_no),
      namedFact('类型', arrangementTypeText(row.type)),
      namedFact('方式', organizeModeText(row.organize_mode)),
      namedFact('时间', dateRangeText(row.start_date, row.end_date)),
      namedFact('绑定班级', row.class_names),
      namedFact('学生数', row.student_count),
      namedFact('绑定人数', row.task_binding_count),
      namedFact('任务评分', row.task_score_progress_text),
      namedFact('范围', joinFact([row.dep_name || '全校', row.profession_name || '全部专业', row.grade_name])),
    ],
    arrangementChanges: [
      namedFact('原任务', arrangement),
      namedFact('课程', row.course_name),
      namedFact('原老师', row.teacher_name),
      namedFact('拟变更时间', dateRangeText(changePayload.start_date, changePayload.end_date)),
      namedFact('生效任务', row.new_arrangement_title),
      namedFact('新任务编号', row.new_task_no),
      namedFact('新负责老师', row.new_teacher_name),
      namedFact('原因', previewText(row.reason, 42)),
      namedFact('提交人', row.submitter_name),
    ],
    plans: [
      // 暂时隐藏学期字段，后续需要时恢复。
      // namedFact('学期', row.semester),
      namedFact('学院', row.dep_name),
      namedFact('专业', row.profession_name),
      namedFact('任务数', row.task_count),
      namedFact('任务覆盖', row.task_coverage_text),
      namedFact('任务评分', row.task_score_progress_text),
      namedFact('提交人', row.submitter_name),
      namedFact('审核进度', row.approval_progress_text),
      namedFact('当前节点', row.current_approval_name),
      namedFact('内容', planContentText(row.plan_content, 48)),
    ],
    syllabusGuides: [
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('学院专业', joinFact([row.dep_name, row.profession_name])),
      namedFact('录入人', row.creator_name),
      namedFact('内容', previewText(row.content, 42)),
    ],
    implementationSheets: [
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('学院专业', joinFact([row.dep_name, row.profession_name])),
      namedFact('教师', row.teacher_name),
      namedFact('承诺签署', `${row.signed_count || 0}/${Number(row.signed_count || 0) + Number(row.unsigned_count || 0)}`),
      namedFact('保险', row.insurance_verified === 'true' ? '已核验' : '未核验'),
    ],
    applications: [
      namedFact('学号', row.student_num),
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('学院专业', joinFact([row.dep_name, row.profession_name])),
      namedFact('教师审核', statusText(row.teacher_status)),
      namedFact('管理审核', statusText(row.admin_status)),
      namedFact('提交', row.created_at),
    ],
    pairs: [
      namedFact('学号', row.student_num),
      namedFact('届次', row.grade_name),
      namedFact('学院专业', joinFact([row.dep_name, row.profession_name])),
      namedFact('班级', row.class_name),
      namedFact('任务', arrangement),
      namedFact('任务编号', row.task_no),
      namedFact('批次', row.batch_no),
      namedFact('教师', row.teacher_name || row.teacher_num),
      namedFact('创建', row.created_at),
    ],
    signIns: [
      namedFact('学生', student),
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('时间', joinFact([row.date, row.sign_time])),
      namedFact('地点', row.location),
    ],
    journals: [
      namedFact('学生', student),
      namedFact('届次', row.grade_name),
      namedFact('日期', row.date || row.created_at),
      namedFact('任务', arrangement),
      namedFact('内容', previewText(row.content, 42)),
    ],
    reports: [
      namedFact('学生', student),
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('提交', row.submitted_at || row.created_at),
      namedFact('内容', previewText(row.content, 42)),
    ],
    teacherWorkReports: [
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('教师', row.teacher_name),
      namedFact('指导人数', row.guidance_count),
      namedFact('总结', previewText(row.summary, 42)),
    ],
    delays: [
      namedFact('学生', student),
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('延期类型', delayConfigText(row.config_key)),
      namedFact('延期至', row.requested_date),
      namedFact('原因', previewText(row.reason, 42)),
    ],
    scores: [
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('评分状态', row.final_score !== null && row.final_score !== undefined ? '已评分' : '待评分'),
      namedFact('评分人', row.teacher_name || row.teacher_num),
      namedFact('分项', scoreBreakdownText(row)),
    ],
    courseScores: [
      namedFact('届次', row.grade_name),
      namedFact('学院专业', joinFact([row.dep_name, row.profession_name])),
      namedFact('班级', row.class_name),
      namedFact('课程', joinFact([row.course_code, row.course_name])),
      namedFact('成绩规则', scoreRuleText(row.score_rule)),
      namedFact('汇总状态', courseScoreStatusText(row.course_score_status)),
      namedFact('课程成绩', row.course_final_score ?? ''),
      namedFact('核定说明', previewText(row.manual_score_remark, 42)),
      namedFact('核定人', row.manual_score_operator_name || row.manual_score_operator_login),
      namedFact('任务成绩', row.task_score_text),
    ],
    inspections: [
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('学生', student),
      namedFact('巡查人', row.inspector_name),
      namedFact('说明', previewText(row.remark, 42)),
    ],
    archiveMaterials: [
      namedFact('学生', student || (row.student_id ? `学生ID ${row.student_id}` : '')),
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('类型', row.arrangement_type_text),
      namedFact('进度', row.material_progress),
      namedFact('抽检', row.inspection_record_status),
      namedFact('缺失', row.missing_materials),
    ],
    insurances: [
      namedFact('学生', student || (row.student_id ? `学生ID ${row.student_id}` : '')),
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('保险公司', row.insurance_company),
      namedFact('时间', dateRangeText(row.start_date, row.end_date)),
    ],
    safetyLetters: [
      namedFact('学生', student || (row.student_id ? `学生ID ${row.student_id}` : '')),
      namedFact('届次', row.grade_name),
      namedFact('任务', arrangement),
      namedFact('签署', row.signed_at),
    ],
  };
  return (facts[key] || []).filter(Boolean);
}

function mobileListActions(key, row, context) {
  const config = getMobileListConfig(key);
  if (!config?.entity) {
    return [];
  }

  const actions = [{ key: 'timeline', label: '记录', type: 'timeline', entity: config.entity }];
  const canActInContext = context === 'review' || (context === 'manage' && config.entity === 'arrangement_change');
  if (canActInContext && canReviewEntity(config.entity)) {
    if (canReviewRow(row, config.entity)) {
      const rejectStatus = config.entity === 'delay' ? 'refuse' : 'modify';
      actions.push(
        { key: 'accept', label: '通过', type: 'review', entity: config.entity, status: 'accept' },
        { key: rejectStatus, label: '退回', type: 'review', entity: config.entity, status: rejectStatus },
      );
    }
    if (canRequestModification(row, config.entity)) {
      actions.push({ key: 'reopen', label: '通过后修改', type: 'reopen', entity: config.entity });
    }
  }
  return actions;
}

function canReviewEntity(entity) {
  if (entity === 'plan') {
    return canReviewInternshipPlan.value;
  }
  if (entity === 'arrangement_change') {
    return isAdminRole.value && (hasPermission('internship:manage') || hasPermission('internship:approve'));
  }
  return canReviewInternship.value;
}

function handleMobileListAction(action, row) {
  if (action.type === 'timeline') {
    openTimelineDialog(action.entity, row);
    return;
  }
  if (action.type === 'reopen') {
    openReopenDialog(action.entity, row);
    return;
  }
  if (action.type === 'review') {
    openReviewDialog(action.entity, row, action.status);
  }
}

function namedFact(label, value) {
  const text = String(value ?? '').trim();
  return text && text !== '-' ? `${label}：${text}` : '';
}

function joinFact(values) {
  return values
    .map(value => String(value ?? '').trim())
    .filter(Boolean)
    .join(' / ');
}

function dateRangeText(start, end) {
  return joinFact([start, end]);
}

function previewText(value, length = 40) {
  const text = String(value || '').replace(/\s+/g, ' ').trim();
  if (text.length <= length) {
    return text;
  }
  return `${text.slice(0, length)}...`;
}

function planContentText(value, length = 48) {
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
  return previewText(text, length);
}

function scoreBreakdownText(row) {
  return [
    `签到 ${row.sign_in_score ?? '-'}`,
    `日志 ${row.journal_score ?? '-'}`,
    `报告 ${row.report_score ?? '-'}`,
    `企业 ${row.enterprise_score ?? '-'}`,
  ].join(' / ');
}

function setPagedList(key, data, append = false) {
  const items = data.items || [];
  internship.lists[key].items = append ? [...internship.lists[key].items, ...items] : items;
  internship.lists[key].pagination = {
    ...internship.lists[key].pagination,
    ...(data.pagination || {}),
  };
}

function hasFilterValue(value) {
  return value !== '' && value !== null && value !== undefined;
}

function sameFilterValue(left, right) {
  return String(left ?? '') === String(right ?? '');
}

function currentInternshipGradeId() {
  const currentGrade = (internship.options.grades || [])
    .find(item => sameFilterValue(item.is_current, 'true') || sameFilterValue(item.is_current, 1));
  return currentGrade?.grade_id || internship.options.grades?.[0]?.grade_id || '';
}

function scopeIds(field) {
  const ids = [];
  (state.context.organization_scopes || []).forEach((scope) => {
    if (hasFilterValue(scope?.[field])) {
      ids.push(scope[field]);
    }
  });

  const filter = scopeFilter.value || {};
  const value = filter[field];
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

function internshipScopeDefaults() {
  const defaults = {
    grade_id: currentInternshipGradeId(),
    dep_id: '',
    profession_id: '',
    class_id: '',
  };
  const depIds = scopeIds('dep_id');
  const professionIds = scopeIds('profession_id');
  const classIds = scopeIds('class_id');
  const scopedClass = firstScopedOption(internship.options.classes, 'class_id', classIds);
  const scopedProfession = firstScopedOption(internship.options.professions, 'profession_id', professionIds);
  const scopedDepartment = firstScopedOption(internship.options.departments, 'dep_id', depIds);

  if (scopedClass) {
    defaults.class_id = scopedClass.class_id || '';
    defaults.profession_id = scopedClass.profession_id || defaults.profession_id;
    defaults.dep_id = scopedClass.dep_id || defaults.dep_id;
    defaults.grade_id = defaults.grade_id || scopedClass.grade_id || '';
  }
  if (scopedProfession) {
    defaults.profession_id = scopedProfession.profession_id || defaults.profession_id;
    defaults.dep_id = scopedProfession.dep_id || defaults.dep_id;
    defaults.grade_id = defaults.grade_id || scopedProfession.grade_id || '';
  }
  if (scopedDepartment) {
    defaults.dep_id = scopedDepartment.dep_id || defaults.dep_id;
  }

  if (roleType.value === 'college_admin' && !defaults.dep_id) {
    defaults.dep_id = depIds[0] || (internship.options.departments?.length === 1 ? internship.options.departments[0]?.dep_id : '') || '';
  }
  if (roleType.value === 'profession_admin' && !defaults.profession_id) {
    const fallback = firstScopedOption(internship.options.professions, 'profession_id', professionIds)
      || (internship.options.professions?.length === 1 ? internship.options.professions[0] : null);
    if (fallback) {
      defaults.profession_id = fallback.profession_id || '';
      defaults.dep_id = fallback.dep_id || defaults.dep_id;
      defaults.grade_id = defaults.grade_id || fallback.grade_id || '';
    }
  }

  return defaults;
}

function applyInternshipFilterDefaults(target) {
  const defaults = internshipScopeDefaults();
  Object.entries(defaults).forEach(([key, value]) => {
    if (hasFilterValue(value) && !hasFilterValue(target[key])) {
      target[key] = value;
    }
  });
  normalizeMobileListFiltersByValues(target);
}

function applyDefaultInternshipFilters() {
  Object.keys(internship.filters).forEach((key) => {
    const current = {
      ...emptyInternshipFilters(),
      ...internship.filters[key],
    };
    applyInternshipFilterDefaults(current);
    internship.filters[key] = current;
  });
}

function normalizeMobileListFiltersByValues(filters) {
  if (!filters) {
    return;
  }
  if (filters.profession_id && !mobileProfessionOptionsByValues(filters).some(item => sameFilterValue(item.profession_id, filters.profession_id))) {
    filters.profession_id = '';
  }
  if (filters.dep_id && !mobileDepartmentOptionsByValues(filters).some(item => sameFilterValue(item.dep_id, filters.dep_id))) {
    filters.dep_id = '';
    filters.profession_id = '';
  }
  if (filters.class_id && !mobileClassOptionsByValues(filters).some(item => sameFilterValue(item.class_id, filters.class_id))) {
    filters.class_id = '';
  }
}

function internshipQueryParams(key, page = 1) {
  const filters = internship.filters[key] || {};
  const params = {
    page,
    page_size: internship.lists[key]?.pagination.page_size || 10,
  };
  Object.entries(filters).forEach(([filterKey, value]) => {
    if (value !== '' && value !== null && value !== undefined) {
      params[filterKey] = value;
    }
  });
  return params;
}

function internshipFetcher(key) {
  const fetchers = {
    arrangements: fetchInternshipArrangements,
    arrangementChanges: fetchInternshipArrangementChanges,
    plans: fetchInternshipPlans,
    applications: fetchInternshipApplications,
    pairs: fetchInternshipPairs,
    signIns: fetchInternshipSignIns,
    journals: fetchInternshipJournals,
    reports: fetchInternshipReports,
    delays: fetchInternshipDelays,
    scores: fetchInternshipScores,
    courseScores: fetchInternshipCourseScores,
    syllabusGuides: fetchInternshipSyllabusGuides,
    implementationSheets: fetchInternshipImplementationSheets,
    teacherWorkReports: fetchInternshipTeacherWorkReports,
    inspections: fetchInternshipInspections,
    archiveMaterials: fetchInternshipArchiveMaterials,
    insurances: fetchInternshipInsurances,
    safetyLetters: fetchInternshipSafetyLetters,
  };
  return fetchers[key] || null;
}

async function loadInternshipList(key, page = 1, append = false) {
  const fetcher = internshipFetcher(key);
  if (!fetcher) {
    return;
  }
  applyInternshipFilterDefaults(internship.filters[key] || {});
  const data = await fetcher(internshipQueryParams(key, page));
  setPagedList(key, data, append);
}

async function reloadInternshipList(key) {
  internship.loading = true;
  internship.message = '';
  try {
    await loadInternshipList(key, 1);
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function loadMoreInternshipList(key) {
  const pagination = internship.lists[key]?.pagination || {};
  if (!canLoadMore(key)) {
    return;
  }
  internship.loading = true;
  internship.message = '';
  try {
    await loadInternshipList(key, (pagination.page || 1) + 1, true);
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

function canLoadMore(key) {
  const list = internship.lists[key];
  if (!list) {
    return false;
  }
  return list.items.length < (list.pagination.total || 0);
}

function isPracticeTab(tab) {
  return ['training', 'lab'].includes(tab);
}

function practiceModule(module) {
  return practice[module] || practice.training;
}

function practiceModuleName(module) {
  return module === 'lab' ? '实验管理' : '实训管理';
}

function practicePanels(module) {
  if (isStudentRole.value) {
    return practicePanelDefinitions.filter(item => ['projects', 'signIns', 'journals', 'reports', 'scores'].includes(item.key));
  }
  if (isTeacherRole.value) {
    return practicePanelDefinitions.filter(item => item.key !== 'gradeRules');
  }
  return practicePanelDefinitions;
}

function currentPracticePanel(module) {
  const state = practiceModule(module);
  const panels = practicePanels(module);
  return panels.find(item => item.key === state.panel) || panels[0] || practicePanelDefinitions[0];
}

function practiceSummaries(module) {
  const overview = practiceModule(module).overview;
  return [
    { name: '待审计划', value: overview.plans_waiting || 0 },
    { name: '课表', value: overview.schedules || 0 },
    { name: '项目', value: overview.projects || 0 },
    { name: '日志', value: practiceModule(module).lists.journals.pagination.total || 0 },
    { name: '今日课表', value: overview.today_schedules || 0 },
  ];
}

function practiceFilters(module) {
  if (isStudentRole.value) {
    return [];
  }
  const state = practiceModule(module);
  const options = state.options;
  const common = [
    { key: 'grade_id', label: '届次', placeholder: '全部届次', options: selectFilterItems(options.grades, 'grade_id', 'grade_name') },
  ];
  if (isAdminRole.value) {
    common.push(
      { key: 'dep_id', label: '学院', placeholder: '全部学院', options: selectFilterItems(options.departments, 'dep_id', 'dep_name') },
      { key: 'profession_id', label: '专业', placeholder: '全部专业', options: selectFilterItems(options.professions, 'profession_id', 'profession_name') },
    );
  }
  if (currentPracticePanel(module).review) {
    common.push({ key: 'status', label: '状态', placeholder: '全部状态', options: reviewStatusOptions });
  } else if (['projects', 'schedules'].includes(currentPracticePanel(module).key)) {
    common.push({ key: 'status', label: '状态', placeholder: '全部状态', options: practiceEnabledStatusOptions });
  }
  return common;
}

function practiceQueryParams(module, page = 1) {
  const state = practiceModule(module);
  const panel = currentPracticePanel(module);
  const filters = state.filters[panel.key] || {};
  const params = {
    entity: panel.entity,
    page,
    page_size: state.lists[panel.key]?.pagination.page_size || 10,
  };
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) {
      params[key] = value;
    }
  });
  return params;
}

function practiceExecutionQueryParams(module, page = 1) {
  const state = practiceModule(module);
  const panel = currentPracticePanel(module);
  const filters = state.filters[panel.key] || {};
  const params = {
    page,
    page_size: state.lists[panel.key]?.pagination.page_size || 10,
  };
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) {
      params[key] = value;
    }
  });
  return params;
}

async function loadPractice(module) {
  if (!isLoggedIn.value || !hasPermission(`${module}:view`)) {
    return;
  }
  const state = practiceModule(module);
  state.loading = true;
  state.message = '';
  try {
    const [overview, options] = await Promise.all([
      fetchPracticeOverview(module),
      fetchPracticeOptions(module),
    ]);
    state.overview = { ...state.overview, ...(overview || {}) };
    state.options = { ...state.options, ...(options || {}) };
    normalizePracticePanel(module);
    await loadPracticeList(module, 1);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

async function loadPracticeList(module, page = 1, append = false) {
  const state = practiceModule(module);
  const panel = currentPracticePanel(module);
  const data = panel.execution
    ? await fetchPracticeExecutionList(module, panel.execution, practiceExecutionQueryParams(module, page))
    : await fetchPracticeList(module, practiceQueryParams(module, page));
  const items = data.items || [];
  state.lists[panel.key].items = append ? [...state.lists[panel.key].items, ...items] : items;
  state.lists[panel.key].pagination = {
    ...state.lists[panel.key].pagination,
    ...(data.pagination || {}),
  };
}

async function reloadPracticeList(module) {
  const state = practiceModule(module);
  state.loading = true;
  state.message = '';
  try {
    await loadPracticeList(module, 1);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

async function loadMorePracticeList(module) {
  const state = practiceModule(module);
  const panel = currentPracticePanel(module);
  const pagination = state.lists[panel.key]?.pagination || {};
  if (!canLoadMorePractice(module)) {
    return;
  }
  state.loading = true;
  state.message = '';
  try {
    await loadPracticeList(module, (pagination.page || 1) + 1, true);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

function canLoadMorePractice(module) {
  const state = practiceModule(module);
  const panel = currentPracticePanel(module);
  const list = state.lists[panel.key];
  return Boolean(list && list.items.length < (list.pagination.total || 0));
}

function currentPracticeRows(module) {
  const state = practiceModule(module);
  return state.lists[currentPracticePanel(module).key]?.items || [];
}

function practiceListTotal(module) {
  const state = practiceModule(module);
  return state.lists[currentPracticePanel(module).key]?.pagination.total || 0;
}

function normalizePracticePanel(module) {
  const state = practiceModule(module);
  const keys = practicePanels(module).map(item => item.key);
  if (!keys.includes(state.panel)) {
    state.panel = keys[0] || 'plans';
  }
}

function switchPracticePanel(module, panel) {
  practiceModule(module).panel = panel;
  reloadPracticeList(module);
}

function practiceRowTitle(module, row) {
  const panel = currentPracticePanel(module).key;
  if (panel === 'signIns') {
    return row.project_title || row.title || '签到记录';
  }
  if (['journals', 'reports'].includes(panel)) {
    return row.title || row.project_title || `记录 ${row.id}`;
  }
  if (panel === 'scores') {
    return row.student_name || row.student_num || row.title || `成绩 ${row.id}`;
  }
  if (panel === 'projects') {
    return row.title || row.course_name || `项目 ${row.id}`;
  }
  if (panel === 'schedules') {
    return row.title || row.course_name || `课表 ${row.id}`;
  }
  return row.title || row.name || row.course_name || `记录 ${row.id}`;
}

function practiceRowValue(module, row) {
  const panel = currentPracticePanel(module).key;
  if (panel === 'signIns') {
    return row.date || row.sign_time || statusText(row.status);
  }
  if (['journals', 'reports'].includes(panel)) {
    return statusText(row.status);
  }
  if (panel === 'schedules') {
    return row.schedule_date || statusText(row.status);
  }
  if (panel === 'projects') {
    return joinFact([row.start_date, row.end_date]) || statusText(row.status);
  }
  if (panel === 'scores') {
    return row.score_value !== null && row.score_value !== undefined ? `${row.score_value} 分` : statusText(row.status);
  }
  return statusText(row.status);
}

function practiceRowFacts(module, row) {
  const panel = currentPracticePanel(module).key;
  const common = [
    namedFact('届次', row.grade_name),
    namedFact('学院专业', joinFact([row.dep_name, row.profession_name])),
    namedFact('教师', row.teacher_name),
  ];
  const detail = {
    schedules: [
      namedFact('计划', row.plan_title),
      namedFact('时间', joinFact([row.schedule_date, row.start_time, row.end_time])),
      namedFact('地点', row.room_name || row.base_name || row.location),
      namedFact('学生数', row.student_count),
    ],
    projects: [
      namedFact('计划', row.plan_title),
      namedFact('课表', row.schedule_title),
      namedFact('日期', joinFact([row.start_date, row.end_date])),
      namedFact('绑定学生', row.bound_student_count ?? row.student_count),
    ],
    signIns: [
      namedFact('项目', row.project_title),
      namedFact('学生', joinFact([row.student_name, row.student_num])),
      namedFact('负责老师', row.teacher_name),
      namedFact('位置', row.location),
    ],
    journals: [
      namedFact('项目', row.project_title),
      namedFact('学生', joinFact([row.student_name, row.student_num])),
      namedFact('日期', row.date || row.created_at),
      namedFact('内容', previewText(row.content, 52)),
    ],
    reports: [
      namedFact('项目', row.project_title),
      namedFact('学生', joinFact([row.student_name, row.student_num])),
      namedFact('提交', row.submitted_at || row.created_at),
      namedFact('内容', previewText(row.content, 52)),
    ],
    scores: [
      namedFact('学号', row.student_num),
      namedFact('计划', row.plan_title),
      namedFact('评分教师', row.teacher_name),
    ],
    gradeRules: [
      namedFact('计划', row.plan_title),
      namedFact('比例', practiceRatioText(row.ratio_json)),
    ],
    plans: [
      namedFact('来源', practiceSourceText(row.source_type)),
      namedFact('内容', previewText(row.content, 52)),
    ],
  }[panel] || [
    namedFact('计划', row.plan_title),
    namedFact('内容', previewText(row.content, 52)),
  ];
  return [...common, ...detail].filter(Boolean);
}

function practiceRowActions(module, row) {
  const panel = currentPracticePanel(module);
  if (panel.key === 'projects' && isStudentRole.value) {
    return [
      { key: 'signIn', label: '签到' },
      { key: 'journal', label: '日志' },
      { key: 'report', label: '报告' },
    ];
  }
  if (panel.execution) {
    const actions = [{ key: 'timeline', label: '记录' }];
    if (isStudentRole.value && ['journals', 'reports'].includes(panel.key) && ['draft', 'modify'].includes(row.status || '')) {
      actions.unshift({ key: 'editExecution', label: '修改' });
    }
    if (!isStudentRole.value && row.status === 'wait' && hasPermission(`${module}:approve`) && panel.review) {
      actions.push(
        { key: 'accept', label: '通过' },
        { key: 'modify', label: '退回' },
      );
    }
    if (!isStudentRole.value && row.status === 'accept' && hasPermission(`${module}:approve`) && panel.review) {
      actions.push({ key: 'reopen', label: '通过后修改' });
    }
    return actions;
  }
  if (!panel.review) {
    return [];
  }
  const actions = [{ key: 'timeline', label: '记录' }];
  if (row.status === 'wait' && hasPermission(`${module}:approve`) && !isStudentRole.value) {
    actions.push(
      { key: 'accept', label: '通过' },
      { key: 'modify', label: '退回' },
    );
  }
  if (row.status === 'accept' && hasPermission(`${module}:approve`) && !isStudentRole.value) {
    actions.push({ key: 'reopen', label: '通过后修改' });
  }
  return actions;
}

function handlePracticeAction(module, action, row) {
  const panel = currentPracticePanel(module);
  if (['signIn', 'journal', 'report'].includes(action.key)) {
    openPracticeExecutionDialog(module, action.key === 'signIn' ? 'signIns' : `${action.key}s`, null, {
      projectId: row.id,
      projectTitle: row.title || row.course_name,
    });
    return;
  }
  if (action.key === 'editExecution') {
    openPracticeExecutionDialog(module, panel.key, row);
    return;
  }
  if (action.key === 'timeline') {
    openPracticeTimeline(module, panel, row);
    return;
  }
  if (action.key === 'reopen') {
    openPracticeReview(module, panel, row, 'modify', 'reopen');
    return;
  }
  openPracticeReview(module, panel, row, action.key, 'review');
}

function applyDefaultInternshipSelection() {
  const firstArrangement = internship.options.arrangements[0];
  if (firstArrangement) {
    internship.forms.application.arrangement_id ||= firstArrangement.id;
    internship.forms.sign.arrangement_id ||= firstArrangement.id;
    internship.forms.journal.arrangement_id ||= firstArrangement.id;
    internship.forms.report.arrangement_id ||= firstArrangement.id;
    internship.forms.delay.arrangement_id ||= firstArrangement.id;
  }
}

async function loadInternship() {
  if (!isLoggedIn.value || !hasPermission('internship:view')) {
    return;
  }

  internship.loading = true;
  internship.message = '';
  try {
    const [overview, options] = await Promise.all([
      fetchInternshipOverview(),
      fetchInternshipOptions(),
    ]);
    internship.overview = {
      ...emptyInternshipOverview(),
      ...(overview || {}),
    };
    internship.options = {
      ...emptyInternshipOptions(),
      ...(options || {}),
    };
    applyDefaultInternshipFilters();
    applyDefaultInternshipSelection();
    normalizeInternshipListViews();
    await loadInternshipPanelData();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function loadInternshipPanelData() {
  if (internship.panel === 'workbench') {
    await Promise.all([
      loadInternshipList('applications'),
      loadInternshipList('scores'),
    ]);
    return;
  }
  if (internship.panel === 'apply') {
    await loadInternshipList('applications');
    return;
  }
  if (internship.panel === 'submit') {
    await Promise.all([
      loadInternshipList('signIns'),
      loadInternshipList('journals'),
      loadInternshipList('reports'),
      loadInternshipList('delays'),
    ]);
    return;
  }
  if (internship.panel === 'review') {
    await loadInternshipList(currentReviewListConfig.value?.key || 'applications');
    return;
  }
  if (internship.panel === 'score') {
    if (isTeacherRole.value) {
      const [pairs] = await Promise.all([
        fetchInternshipPairs({ page: 1, page_size: 50, keyword: internship.filters.pairs.keyword || '' }),
        loadInternshipList('scores'),
        loadInternshipList('courseScores'),
      ]);
      setPagedList('pairs', pairs);
      const firstPair = internship.lists.pairs.items[0];
      if (firstPair && !internship.forms.score.pair_id) {
        internship.forms.score.pair_id = firstPair.id;
        selectScorePair();
      }
      return;
    }
    await Promise.all([
      loadInternshipList('scores'),
      loadInternshipList('courseScores'),
    ]);
    return;
  }
  if (internship.panel === 'manage') {
    await loadInternshipList(currentManageListConfig.value?.key || 'arrangements');
  }
}

async function reloadScorePairs() {
  internship.loading = true;
  internship.message = '';
  try {
    const pairs = await fetchInternshipPairs({
      page: 1,
      page_size: 50,
      keyword: internship.filters.pairs.keyword || '',
    });
    setPagedList('pairs', pairs);
    const firstPair = internship.lists.pairs.items[0];
    if (firstPair) {
      internship.forms.score.pair_id = firstPair.id;
      selectScorePair();
    } else {
      internship.forms.score.pair_id = null;
      internship.forms.score.student_id = null;
      internship.forms.score.arrangement_id = null;
    }
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

function switchInternshipPanel(panel) {
  internship.panel = panel;
  normalizeInternshipListViews();
  loadInternship();
}

async function switchMobileList(type, key) {
  if (type === 'review') {
    internship.reviewList = key;
  } else {
    internship.manageList = key;
  }
  await reloadInternshipList(key);
}

async function submitApplication() {
  if (!internship.forms.application.arrangement_id) {
    internship.message = '请选择实习任务';
    showToast(internship.message);
    return;
  }
  if (!String(internship.forms.application.remark || '').trim()) {
    internship.message = '请填写申请说明';
    showToast(internship.message);
    return;
  }

  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipApplication({
      arrangement_id: internship.forms.application.arrangement_id,
      type: internship.forms.application.type || 'distributed',
      status: 'wait',
      remark: internship.forms.application.remark,
    });
    internship.forms.application.remark = '';
    internship.message = '申请已提交';
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function submitSignIn() {
  if (!signGpsReady.value) {
    internship.message = '请先获取 GPS 定位后再签到';
    showToast(internship.message);
    return;
  }

  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipSignIn({
      arrangement_id: internship.forms.sign.arrangement_id,
      sign_type: 'gps',
      location: internship.forms.sign.location || 'GPS 定位签到',
      longitude: internship.forms.sign.longitude,
      latitude: internship.forms.sign.latitude,
      remark: internship.forms.sign.accuracy ? `GPS 精度 ${Math.round(Number(internship.forms.sign.accuracy))} 米` : '',
    });
    resetSignPosition();
    internship.message = '签到已提交';
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function submitJournal() {
  const arrangementId = internship.forms.journal.arrangement_id || internship.forms.sign.arrangement_id;
  if (!arrangementId) {
    internship.message = '请选择实习任务';
    showToast(internship.message);
    return;
  }
  if (isStageExpired('journal_deadline')) {
    internship.message = '实习日志已截止，请先申请延期';
    showToast(internship.message);
    openDelayForStage('journal_deadline');
    return;
  }
  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipJournal({
      id: internship.forms.journal.id || undefined,
      arrangement_id: arrangementId,
      title: internship.forms.journal.title,
      content: internship.forms.journal.content,
      status: 'wait',
    });
    internship.forms.journal.id = null;
    internship.forms.journal.arrangement_id = arrangementId;
    internship.forms.journal.title = '';
    internship.forms.journal.content = '';
    internship.message = '日志已提交';
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function submitReport() {
  const arrangementId = internship.forms.report.arrangement_id || internship.forms.sign.arrangement_id;
  if (!arrangementId) {
    internship.message = '请选择实习任务';
    showToast(internship.message);
    return;
  }
  if (isStageExpired('report_deadline')) {
    internship.message = '实习报告已截止，请先申请延期';
    showToast(internship.message);
    openDelayForStage('report_deadline');
    return;
  }
  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipReport({
      id: internship.forms.report.id || undefined,
      arrangement_id: arrangementId,
      template_id: internship.options.report_templates[0]?.id || null,
      title: internship.forms.report.title,
      content: internship.forms.report.content,
      status: 'wait',
    });
    internship.forms.report.id = null;
    internship.forms.report.arrangement_id = arrangementId;
    internship.forms.report.title = '';
    internship.forms.report.content = '';
    internship.message = '报告已提交';
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function submitDelay() {
  if (!internship.forms.delay.arrangement_id) {
    internship.message = '请选择实习任务';
    showToast(internship.message);
    return;
  }
  if (!internship.forms.delay.requested_date) {
    internship.message = '请选择申请延期日期';
    showToast(internship.message);
    return;
  }
  if (!String(internship.forms.delay.reason || '').trim()) {
    internship.message = '请填写申请原因';
    showToast(internship.message);
    return;
  }

  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipDelay({
      id: internship.forms.delay.id || undefined,
      entity_type: 'internship',
      entity_id: internship.forms.delay.arrangement_id,
      config_key: internship.forms.delay.config_key,
      requested_date: internship.forms.delay.requested_date,
      reason: internship.forms.delay.reason,
    });
    internship.forms.delay.id = null;
    internship.forms.delay.requested_date = '';
    internship.forms.delay.reason = '';
    internship.message = '延期申请已提交';
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

function canEditStudentWork(row) {
  return isStudentRole.value && ['draft', 'modify'].includes(row?.status || '');
}

function editStudentWork(type, row) {
  if (!canEditStudentWork(row)) {
    return;
  }
  internship.panel = 'submit';
  if (type === 'journal') {
    internship.submitSection = 'journal';
    internship.forms.journal.id = row.id || null;
    internship.forms.journal.arrangement_id = row.arrangement_id || null;
    internship.forms.journal.title = row.title || '';
    internship.forms.journal.content = row.content || '';
    internship.forms.sign.arrangement_id = row.arrangement_id || internship.forms.sign.arrangement_id;
    internship.message = '已载入日志内容，请修改后重新提交';
  }
  if (type === 'report') {
    internship.submitSection = 'report';
    internship.forms.report.id = row.id || null;
    internship.forms.report.arrangement_id = row.arrangement_id || null;
    internship.forms.report.title = row.title || '';
    internship.forms.report.content = row.content || '';
    internship.forms.sign.arrangement_id = row.arrangement_id || internship.forms.sign.arrangement_id;
    internship.message = '已载入报告内容，请修改后重新提交';
  }
  if (type === 'delay') {
    internship.submitSection = 'delay';
    internship.forms.delay.id = row.id || null;
    internship.forms.delay.arrangement_id = row.arrangement_id || row.entity_id || null;
    internship.forms.delay.config_key = row.config_key || 'report_deadline';
    internship.forms.delay.requested_date = row.requested_date || '';
    internship.forms.delay.reason = row.reason || '';
    internship.forms.sign.arrangement_id = row.arrangement_id || row.entity_id || internship.forms.sign.arrangement_id;
    internship.message = '已载入延期申请，请修改后重新提交';
  }
}

function openReviewDialog(entity, row, status) {
  if (!canReviewRow(row, entity)) {
    internship.message = '仅待审核数据可处理';
    showToast(internship.message);
    return;
  }
  internship.reviewDialog.mode = 'review';
  internship.reviewDialog.entity = entity;
  internship.reviewDialog.status = status;
  internship.reviewDialog.row = row;
  internship.reviewDialog.reason = status === 'accept' ? defaultReviewOpinion(entity, status) : '';
  trimReviewDialogMax();
  internship.reviewDialog.visible = true;
}

function openReopenDialog(entity, row) {
  if (!canRequestModification(row, entity)) {
    internship.message = '仅已通过数据可发起通过后修改';
    showToast(internship.message);
    return;
  }
  internship.reviewDialog.mode = 'reopen';
  internship.reviewDialog.entity = entity;
  internship.reviewDialog.status = 'modify';
  internship.reviewDialog.row = row;
  internship.reviewDialog.reason = '';
  trimReviewDialogMax();
  internship.reviewDialog.visible = true;
}

function closeReviewDialog() {
  internship.reviewDialog.visible = false;
  internship.reviewDialog.row = null;
}

async function openTimelineDialog(entity, row) {
  internship.timelineDialog.visible = true;
  internship.timelineDialog.loading = true;
  internship.timelineDialog.entity = entity;
  internship.timelineDialog.row = row;
  internship.timelineDialog.title = `${reviewEntityName(entity)}流程记录`;
  internship.timelineDialog.subtitle = row.title || row.arrangement_title || row.student_name || String(row.id);
  internship.timelineDialog.items = [];
  internship.timelineDialog.cycles = [];
  internship.timelineDialog.message = '';
  try {
    const data = await fetchInternshipTimeline({ entity, id: row.id });
    internship.timelineDialog.cycles = data.cycles || [];
    internship.timelineDialog.items = data.items || [];
  } catch (error) {
    internship.timelineDialog.message = error.message;
  } finally {
    internship.timelineDialog.loading = false;
  }
}

async function openPracticeTimeline(module, panel, row) {
  internship.timelineDialog.visible = true;
  internship.timelineDialog.loading = true;
  internship.timelineDialog.entity = panel.entity;
  internship.timelineDialog.row = row;
  internship.timelineDialog.title = `${practiceEntityName(panel.entity)}流程记录`;
  internship.timelineDialog.subtitle = row.title || row.name || String(row.id);
  internship.timelineDialog.items = [];
  internship.timelineDialog.cycles = [];
  internship.timelineDialog.message = '';
  try {
    const data = panel.execution
      ? await fetchPracticeExecutionTimeline(module, { execution: panel.execution, id: row.id })
      : await fetchPracticeTimeline(module, { entity: panel.entity, id: row.id });
    internship.timelineDialog.cycles = data.cycles || [];
    internship.timelineDialog.items = data.items || data.records || [];
  } catch (error) {
    internship.timelineDialog.message = error.message;
  } finally {
    internship.timelineDialog.loading = false;
  }
}

function openPracticeExecutionDialog(module, panelKey, row = null, context = {}) {
  const panel = practicePanelDefinitions.find(item => item.key === panelKey) || currentPracticePanel(module);
  const projectId = context.projectId
    || (panel.key === 'projects'
      ? row?.id
      : row?.project_id || row?.entity_id || practiceModule(module).options.projects[0]?.id || null);
  practiceExecutionDialog.visible = true;
  practiceExecutionDialog.module = module;
  practiceExecutionDialog.panel = panel.key;
  practiceExecutionDialog.execution = panel.execution || 'journal';
  practiceExecutionDialog.row = row;
  practiceExecutionDialog.form = {
    id: context.projectId ? null : (panel.execution && row?.id ? row.id : null),
    project_id: projectId,
    title: panel.execution ? (row?.title || context.projectTitle || '') : '',
    date: row?.date || '',
    content: row?.content || '',
    location: row?.location || '',
    longitude: row?.longitude || '',
    latitude: row?.latitude || '',
    accuracy: row?.accuracy || null,
    located_at: '',
    locating: false,
    gps_error: '',
    remark: row?.remark || '',
  };
  if (practiceExecutionDialog.execution === 'sign_in' && !practiceGpsReady.value) {
    locatePracticePosition();
  }
}

function closePracticeExecutionDialog() {
  practiceExecutionDialog.visible = false;
  practiceExecutionDialog.row = null;
}

async function submitPracticeExecution() {
  const state = practiceModule(practiceExecutionDialog.module);
  const execution = practiceExecutionDialog.execution;
  if (!practiceExecutionDialog.form.project_id) {
    state.message = '请选择项目';
    showToast(state.message);
    return;
  }
  if (execution !== 'sign_in' && !String(practiceExecutionDialog.form.content || '').trim()) {
    state.message = '请填写内容';
    showToast(state.message);
    return;
  }
  if (execution === 'sign_in' && !practiceGpsReady.value) {
    state.message = '请先获取 GPS 定位后再签到';
    showToast(state.message);
    return;
  }
  state.loading = true;
  state.message = '';
  try {
    await savePracticeExecution(practiceExecutionDialog.module, execution, {
      id: practiceExecutionDialog.form.id || undefined,
      project_id: practiceExecutionDialog.form.project_id,
      title: practiceExecutionDialog.form.title,
      date: practiceExecutionDialog.form.date,
      content: practiceExecutionDialog.form.content,
      location: practiceExecutionDialog.form.location,
      longitude: practiceExecutionDialog.form.longitude,
      latitude: practiceExecutionDialog.form.latitude,
      remark: practiceExecutionDialog.form.remark,
      status: execution === 'sign_in' ? 'signed' : 'wait',
    });
    closePracticeExecutionDialog();
    state.panel = practiceExecutionDialog.panel;
    await loadPractice(practiceExecutionDialog.module);
    showToast('已提交');
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

function closeTimelineDialog() {
  internship.timelineDialog.visible = false;
}

function openPracticeReview(module, panel, row, status, mode = 'review') {
  practiceReviewDialog.visible = true;
  practiceReviewDialog.mode = mode;
  practiceReviewDialog.module = module;
  practiceReviewDialog.panel = panel.key;
  practiceReviewDialog.entity = panel.entity;
  practiceReviewDialog.status = status;
  practiceReviewDialog.row = row;
  practiceReviewDialog.reason = status === 'accept' ? '同意' : '';
  trimPracticeReviewMax();
}

function closePracticeReviewDialog() {
  practiceReviewDialog.visible = false;
  practiceReviewDialog.row = null;
}

async function confirmPracticeReview() {
  const state = practiceModule(practiceReviewDialog.module);
  const error = validatePracticeReason(
    practiceReviewDialog.module,
    practiceReviewDialog.entity,
    practiceReviewDialog.status,
    practiceReviewDialog.reason,
    practiceReviewReasonLabel.value,
  );
  if (error) {
    state.message = error;
    showToast(error);
    return;
  }
  state.loading = true;
  state.message = '';
  try {
    const payload = {
      entity: practiceReviewDialog.entity,
      id: practiceReviewDialog.row.id,
      opinion: practiceReviewDialog.reason,
    };
    if (practiceReviewDialog.mode === 'reopen') {
      const panel = practicePanelDefinitions.find(item => item.key === practiceReviewDialog.panel);
      if (panel?.execution) {
        await requestPracticeExecutionModification(practiceReviewDialog.module, {
          execution: panel.execution,
          id: practiceReviewDialog.row.id,
          opinion: practiceReviewDialog.reason,
        });
      } else {
        await requestPracticeModification(practiceReviewDialog.module, payload);
      }
    } else {
      const panel = practicePanelDefinitions.find(item => item.key === practiceReviewDialog.panel);
      if (panel?.execution) {
        await reviewPracticeExecution(practiceReviewDialog.module, panel.execution, {
          id: practiceReviewDialog.row.id,
          status: practiceReviewDialog.status,
          opinion: practiceReviewDialog.reason,
        });
      } else {
        await reviewPracticeItem(practiceReviewDialog.module, {
          ...payload,
          status: practiceReviewDialog.status,
        });
      }
    }
    closePracticeReviewDialog();
    await loadPractice(practiceReviewDialog.module);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

async function confirmReviewDialog() {
  const { entity, status, row, mode } = internship.reviewDialog;
  if (!row?.id) {
    closeReviewDialog();
    return;
  }

  const error = validateReviewReason(entity, status, internship.reviewDialog.reason, reviewDialogReasonLabel.value);
  if (error) {
    internship.message = error;
    showToast(error);
    return;
  }

  if (mode === 'reopen') {
    if (!canRequestModification(row, entity)) {
      internship.message = '仅已通过数据可发起通过后修改';
      showToast(internship.message);
      return;
    }
    await requestModification(entity, row, internship.reviewDialog.reason);
    return;
  }
  if (!canReviewRow(row, entity)) {
    internship.message = '仅待审核数据可处理';
    showToast(internship.message);
    return;
  }

  if (entity === 'application') {
    await reviewApplication(row, status, internship.reviewDialog.reason);
    return;
  }
  if (entity === 'arrangement_change') {
    await reviewArrangementChange(row, status, internship.reviewDialog.reason);
    return;
  }
  if (entity === 'plan') {
    await reviewPlan(row, status, internship.reviewDialog.reason);
    return;
  }
  if (entity === 'delay') {
    await reviewDelay(row, status, internship.reviewDialog.reason);
    return;
  }
  await reviewWork(entity, row, status, internship.reviewDialog.reason);
}

async function reviewApplication(row, status, opinion) {
  internship.loading = true;
  internship.message = '';
  try {
    await reviewInternshipApplication({
      id: row.id,
      status,
      opinion: opinion || defaultReviewOpinion('application', status),
    });
    closeReviewDialog();
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function reviewArrangementChange(row, status, opinion) {
  internship.loading = true;
  internship.message = '';
  try {
    await reviewInternshipArrangementChange({
      id: row.id,
      status,
      opinion: opinion || defaultReviewOpinion('arrangement_change', status),
    });
    closeReviewDialog();
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function reviewWork(type, row, status, opinion) {
  internship.loading = true;
  internship.message = '';
  try {
    const payload = {
      id: row.id,
      status,
      opinion: opinion || defaultReviewOpinion(type, status),
    };
    if (type === 'journal') {
      await reviewInternshipJournal(payload);
    } else {
      await reviewInternshipReport(payload);
    }
    closeReviewDialog();
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function reviewPlan(row, status, opinion) {
  internship.loading = true;
  internship.message = '';
  try {
    await reviewInternshipPlan({
      id: row.id,
      approval_level: row.next_approval_level || undefined,
      status,
      opinion: opinion || defaultReviewOpinion('plan', status),
    });
    closeReviewDialog();
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function reviewDelay(row, status, opinion) {
  internship.loading = true;
  internship.message = '';
  try {
    await reviewInternshipDelay({
      id: row.id,
      status,
      opinion: opinion || defaultReviewOpinion('delay', status),
    });
    closeReviewDialog();
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function requestModification(entity, row, opinion) {
  internship.loading = true;
  internship.message = '';
  try {
    await requestInternshipModification({
      entity,
      id: row.id,
      opinion,
    });
    closeReviewDialog();
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

function defaultReviewOpinion(type, status) {
  if (status === 'accept') {
    return '同意';
  }
  if (type === 'delay' && status === 'refuse') {
    return '不同意延期';
  }
  if (type === 'report') {
    return '请补充完善报告内容';
  }
  return '请补充修改后再提交';
}

function reviewRule(entity, status) {
  return internship.options.review_rules?.[entity]?.[status]
    || defaultInternshipReviewRules[entity]?.[status]
    || { min: 0, max: null };
}

function reviewRuleText(entity, status, label = '意见') {
  const rule = reviewRule(entity, status);
  if (!rule.min && !rule.max) {
    return `${label}字数不限制`;
  }
  if (rule.min && rule.max) {
    return `${label}需 ${rule.min}-${rule.max} 字`;
  }
  if (rule.min) {
    return `${label}至少 ${rule.min} 字`;
  }
  return `${label}最多 ${rule.max} 字`;
}

function reviewRuleMax(entity, status) {
  const max = reviewRule(entity, status).max;
  return max || null;
}

function reviewRuleMaxText(entity, status) {
  return reviewRuleMax(entity, status) || '不限';
}

function practiceRule(module, entity, status) {
  return practiceModule(module).options.review_rules?.[entity]?.[status]
    || { min: 0, max: null };
}

function practiceRuleText(module, entity, status, label = '意见') {
  const rule = practiceRule(module, entity, status);
  if (!rule.min && !rule.max) {
    return `${label}字数不限制`;
  }
  if (rule.min && rule.max) {
    return `${label}需 ${rule.min}-${rule.max} 字`;
  }
  if (rule.min) {
    return `${label}至少 ${rule.min} 字`;
  }
  return `${label}最多 ${rule.max} 字`;
}

function practiceRuleMax(module, entity, status) {
  return practiceRule(module, entity, status).max || null;
}

function practiceRuleMaxText(module, entity, status) {
  return practiceRuleMax(module, entity, status) || '不限';
}

function trimPracticeReviewMax() {
  const max = practiceRuleMax(practiceReviewDialog.module, practiceReviewDialog.entity, practiceReviewDialog.status);
  if (!max) {
    return;
  }
  const chars = Array.from(String(practiceReviewDialog.reason || ''));
  if (chars.length > max) {
    practiceReviewDialog.reason = chars.slice(0, max).join('');
  }
}

function validatePracticeReason(module, entity, status, reason, label = null) {
  const rule = practiceRule(module, entity, status);
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

function reviewEntityName(entity) {
  const names = {
    arrangement: '实习任务',
    arrangement_change: '任务变更',
    application: '特殊申请',
    sign_in: '实习签到',
    journal: '实习日志',
    report: '实习报告',
    score: '实习成绩',
    plan: '实习计划',
    delay: '延期申请',
    insurance: '保险记录',
    safety_letter: '安全承诺',
    syllabus_guide: '大纲指导书',
    implementation_sheet: '实施表',
    teacher_work_report: '教师工作报告',
    inspection: '巡查记录',
  };
  return names[entity] || '审核事项';
}

function practiceEntityName(entity) {
  const names = {
    plan: '教学计划',
    schedule: '课表安排',
    syllabus: '大纲',
    lessonPlan: '教案',
    gradeRule: '成绩比例',
    score: '成绩',
    reflection: '反思报告',
  };
  return names[entity] || '实践事项';
}

function isRejectReviewStatus(status) {
  return ['modify', 'refuse'].includes(status);
}

function canReviewRow(row, entity) {
  if (!row || row.status !== 'wait') {
    return false;
  }
  if (entity === 'plan') {
    return canReviewInternshipPlan.value && canReviewPlanLevel(row);
  }
  if (entity === 'arrangement_change') {
    return isAdminRole.value && (hasPermission('internship:manage') || hasPermission('internship:approve'));
  }
  if (entity === 'application') {
    if (!canReviewInternship.value) {
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
  return canReviewInternship.value;
}

function canReviewPlanLevel(row) {
  if (roleType.value === 'super_admin') {
    return true;
  }
  const roles = Array.isArray(row.next_approval_role_types) ? row.next_approval_role_types : [];
  return roles.includes(roleType.value);
}

function canRequestModification(row, entity) {
  if (!row || row.status !== 'accept') {
    return false;
  }
  if (!['application', 'journal', 'report', 'plan', 'delay'].includes(entity)) {
    return false;
  }
  if (entity === 'plan') {
    return canReviewInternshipPlan.value;
  }
  return canReviewInternship.value;
}

function detailItem(label, value) {
  const text = String(value ?? '').trim();
  return text && text !== '-' ? { label, value: text } : null;
}

function reviewTargetDetails(entity, row) {
  if (!row) {
    return [];
  }
  const student = joinFact([row.student_name, row.student_num]);
  const arrangement = row.arrangement_title || (row.arrangement_id ? `任务ID ${row.arrangement_id}` : '');
  const changePayload = arrangementChangePayload(row);
  const base = [
    detailItem('审核模块', reviewEntityName(entity)),
    detailItem('学生', student || (row.student_id ? `学生ID ${row.student_id}` : '')),
    detailItem('实习任务', arrangement),
  ];

  const details = {
    arrangement_change: [
      detailItem('课程计划', row.course_name),
      detailItem('原负责老师', row.teacher_name),
      detailItem('拟变更任务', changePayload.title),
      detailItem('拟变更时间', dateRangeText(changePayload.start_date, changePayload.end_date)),
      detailItem('生效任务', row.new_arrangement_title),
      detailItem('新任务编号', row.new_task_no),
      detailItem('新负责老师', row.new_teacher_name),
      detailItem('变更原因', previewText(row.reason, 100)),
      detailItem('提交人', row.submitter_name),
    ],
    application: [
      detailItem('届次', row.grade_name),
      detailItem('学院专业', joinFact([row.dep_name, row.profession_name])),
      detailItem('教师审核', statusText(row.teacher_status)),
      detailItem('管理审核', statusText(row.admin_status)),
      detailItem('申请备注', previewText(row.remark, 80)),
    ],
    journal: [
      detailItem('届次', row.grade_name),
      detailItem('日志标题', row.title),
      detailItem('日志日期', row.date || row.created_at),
      detailItem('内容摘要', previewText(row.content, 100)),
    ],
    report: [
      detailItem('届次', row.grade_name),
      detailItem('报告标题', row.title),
      detailItem('提交时间', row.submitted_at || row.created_at),
      detailItem('内容摘要', previewText(row.content, 100)),
    ],
    plan: [
      // 暂时隐藏学期字段，后续需要时恢复。
      // detailItem('学期', row.semester),
      detailItem('学院', row.dep_name),
      detailItem('提交人', row.submitter_name),
      detailItem('审核进度', row.approval_progress_text),
      detailItem('当前节点', row.current_approval_name),
      detailItem('计划摘要', planContentText(row.plan_content, 100)),
    ],
    delay: [
      detailItem('届次', row.grade_name),
      detailItem('延期类型', delayConfigText(row.config_key)),
      detailItem('申请延期至', row.requested_date),
      detailItem('申请原因', previewText(row.reason, 100)),
    ],
  };

  return [
    ...base,
    ...(details[entity] || []),
    detailItem('当前状态', statusText(row.status)),
  ].filter(Boolean);
}

const reviewDialogTargetDetails = computed(() => (
  reviewTargetDetails(internship.reviewDialog.entity, internship.reviewDialog.row)
));
const internshipTimelineCycles = computed(() => normalizeTimelineCycles(
  internship.timelineDialog.cycles || [],
  internship.timelineDialog.items || [],
));

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
      return submissionSnapshotText(internship.timelineDialog.entity, internship.timelineDialog.row);
    }
    return item.record.content || item.record.opinion || '-';
  }
  return item.review?.opinion || '-';
}

function isGenericSubmitContent(value) {
  return [
    '提交特殊申请',
    '提交补充申请',
    '提交实习日志',
    '提交实习报告',
    '提交延期申请',
    '提交实习计划',
    '提交实习签到',
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
    plan: planContentText(row.plan_content, 120),
    delay: row.reason,
  };
  return previewText(textMap[entity] || '', 160) || '-';
}

const reviewDialogTitle = computed(() => {
  if (internship.reviewDialog.mode === 'reopen') {
    return `通过后修改${reviewEntityName(internship.reviewDialog.entity)}`;
  }
  const action = isRejectReviewStatus(internship.reviewDialog.status) ? '退回' : '通过';
  return `${action}${reviewEntityName(internship.reviewDialog.entity)}`;
});

const reviewDialogReasonLabel = computed(() => {
  if (internship.reviewDialog.mode === 'reopen') {
    return '修改理由';
  }
  return isRejectReviewStatus(internship.reviewDialog.status) ? '退回原因' : '审核意见';
});

const reviewDialogRuleText = computed(() => (
  reviewRuleText(internship.reviewDialog.entity, internship.reviewDialog.status, reviewDialogReasonLabel.value)
));

const reviewDialogConfirmText = computed(() => {
  if (internship.reviewDialog.mode === 'reopen') {
    return '确认修改';
  }
  return isRejectReviewStatus(internship.reviewDialog.status) ? '确认退回' : '确认通过';
});

function textLength(value) {
  return Array.from(String(value || '').trim()).length;
}

function trimReviewDialogMax() {
  const max = reviewRuleMax(internship.reviewDialog.entity, internship.reviewDialog.status);
  if (!max) {
    return;
  }
  const chars = Array.from(String(internship.reviewDialog.reason || ''));
  if (chars.length > max) {
    internship.reviewDialog.reason = chars.slice(0, max).join('');
  }
}

function validateReviewReason(entity, status, reason, label = null) {
  const rule = reviewRule(entity, status);
  const length = textLength(reason);
  const fieldLabel = label || (isRejectReviewStatus(status) ? '退回原因' : '审核意见');
  if (rule.min && length < rule.min) {
    return `${fieldLabel}至少 ${rule.min} 字`;
  }
  if (rule.max && length > rule.max) {
    return `${fieldLabel}最多 ${rule.max} 字`;
  }
  return '';
}

function selectScorePair() {
  const pair = internship.lists.pairs.items.find(item => item.id === internship.forms.score.pair_id);
  if (!pair) {
    return;
  }
  internship.forms.score.student_id = pair.student_id;
  internship.forms.score.arrangement_id = pair.arrangement_id;
  fillScoreFormFromExisting(pair.student_id, pair.arrangement_id, pair);
}

function fillScoreFormFromExisting(studentId, arrangementId, fallback = null) {
  const score = internship.lists.scores.items.find(item =>
    Number(item.student_id) === Number(studentId) && Number(item.arrangement_id) === Number(arrangementId));
  const source = score || (fallback?.score_id ? fallback : null);
  if (!source) {
    internship.forms.score.sign_in_score = '';
    internship.forms.score.journal_score = '';
    internship.forms.score.report_score = '';
    internship.forms.score.enterprise_score = '';
    return;
  }
  internship.forms.score.sign_in_score = source.sign_in_score ?? '';
  internship.forms.score.journal_score = source.journal_score ?? '';
  internship.forms.score.report_score = source.report_score ?? '';
  internship.forms.score.enterprise_score = source.enterprise_score ?? '';
}

async function submitScore() {
  selectScorePair();
  if (!internship.forms.score.student_id || !internship.forms.score.arrangement_id) {
    internship.message = '请先选择学生';
    return;
  }

  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipScore({
      student_id: internship.forms.score.student_id,
      arrangement_id: internship.forms.score.arrangement_id,
      sign_in_score: numericOrNull(internship.forms.score.sign_in_score),
      journal_score: numericOrNull(internship.forms.score.journal_score),
      report_score: numericOrNull(internship.forms.score.report_score),
      enterprise_score: numericOrNull(internship.forms.score.enterprise_score),
    });
    internship.message = '成绩已保存';
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

function numericOrNull(value) {
  return value === '' || value === null || value === undefined ? null : Number(value);
}

function arrangementChangePayload(row) {
  const payload = row?.payload || {};
  if (typeof payload === 'string') {
    try {
      const parsed = JSON.parse(payload);
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
      return {};
    }
  }
  return payload && typeof payload === 'object' ? payload : {};
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

function scoreRuleText(value) {
  const names = {
    average: '平均分',
    sum: '累计分',
    weighted: '按学分加权',
    manual: '人工核定',
  };
  return names[value] || value || '-';
}

function courseScoreStatusText(value) {
  return value === 'complete' ? '已汇总' : '待汇总';
}

function organizeModeText(value) {
  const names = {
    centralized: '集中',
    distributed: '分散',
    autonomous: '自主',
  };
  return names[value] || value || '-';
}

function signTypeText(value) {
  const names = {
    gps: '定位',
    qrcode: '扫码',
    manual: '补录',
  };
  return names[value] || value || '-';
}

function inspectionResultText(value) {
  const names = {
    pass: '通过',
    fail: '不通过',
  };
  return names[value] || value || '-';
}

function openStudentSubmitSection(section) {
  internship.submitSection = internship.submitSection === section ? '' : section;
  if (internship.submitSection === 'sign' && !signGpsReady.value) {
    locateSignPosition();
  }
}

function currentArrangement() {
  const formArrangementId = {
    journal: internship.forms.journal.arrangement_id,
    report: internship.forms.report.arrangement_id,
    delay: internship.forms.delay.arrangement_id,
    sign: internship.forms.sign.arrangement_id,
  }[internship.submitSection];
  const arrangementId = Number(
    formArrangementId
    || internship.forms.sign.arrangement_id
    || internship.forms.journal.arrangement_id
    || internship.forms.report.arrangement_id
    || internship.forms.application.arrangement_id
    || internship.forms.delay.arrangement_id
    || 0,
  );
  return internship.options.arrangements.find(item => Number(item.id) === arrangementId) || internship.options.arrangements[0] || null;
}

function stageDeadlineText(key) {
  const value = stageDeadlineDate(key);
  if (!value) {
    return '截止时间未配置';
  }
  return isStageExpired(key) ? `已截止 ${value}` : `截止 ${value}`;
}

function stageDeadlineDetailText(key) {
  const label = delayConfigText(key);
  const value = stageDeadlineDate(key);
  if (!value) {
    return `${label}暂未配置截止时间`;
  }
  return isStageExpired(key) ? `${label}已于 ${value} 截止` : `${label}截止时间 ${value}`;
}

function stageDeadlineDate(key) {
  const configured = String(internship.options.deadline_configs?.[key] || '').slice(0, 10);
  const arrangementEnd = String(currentArrangement()?.end_date || '').slice(0, 10);
  const value = configured || arrangementEnd;
  return /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : '';
}

function isStageExpired(key) {
  const value = stageDeadlineDate(key);
  return Boolean(value && value < formatDateKey(new Date()));
}

function openDelayForStage(key) {
  const arrangementId = currentArrangement()?.id || internship.forms.sign.arrangement_id || internship.forms.delay.arrangement_id || null;
  internship.submitSection = 'delay';
  internship.forms.delay.config_key = key;
  internship.forms.delay.arrangement_id = arrangementId;
  internship.forms.sign.arrangement_id = arrangementId || internship.forms.sign.arrangement_id;
}

function coordinateText(value) {
  if (!hasCoordinateValue(value)) {
    return '-';
  }
  const number = Number(value);
  return number.toFixed(6);
}

function hasCoordinateValue(value) {
  return value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value));
}

function resetSignPosition() {
  resetGpsForm(internship.forms.sign);
}

function resetPracticePosition() {
  resetGpsForm(practiceExecutionDialog.form);
}

function resetGpsForm(form) {
  form.location = '';
  form.longitude = null;
  form.latitude = null;
  form.accuracy = null;
  form.located_at = '';
  form.gps_error = '';
}

function geolocationErrorText(error) {
  if (!error) {
    return '无法获取当前位置';
  }
  if (error.code === 1) {
    return '定位权限未授权，请允许浏览器访问位置';
  }
  if (error.code === 2) {
    return '当前位置不可用，请检查定位服务';
  }
  if (error.code === 3) {
    return '定位超时，请重新定位';
  }
  return error.message || '无法获取当前位置';
}

async function locateSignPosition() {
  await locateGpsPosition(internship.forms.sign, resetSignPosition);
}

async function locatePracticePosition() {
  await locateGpsPosition(practiceExecutionDialog.form, resetPracticePosition);
}

async function locateGpsPosition(form, resetPosition) {
  if (!navigator.geolocation) {
    form.gps_error = '当前浏览器不支持 GPS 定位';
    showToast(form.gps_error);
    return;
  }

  form.locating = true;
  form.gps_error = '';
  try {
    const position = await new Promise((resolve, reject) => {
      navigator.geolocation.getCurrentPosition(resolve, reject, {
        enableHighAccuracy: true,
        timeout: 12000,
        maximumAge: 0,
      });
    });
    const { latitude, longitude, accuracy } = position.coords;
    form.latitude = Number(latitude.toFixed(6));
    form.longitude = Number(longitude.toFixed(6));
    form.accuracy = accuracy ? Math.round(accuracy) : null;
    form.located_at = new Date().toISOString();
    form.location = `GPS ${coordinateText(form.latitude)}, ${coordinateText(form.longitude)}`;
  } catch (error) {
    resetPosition();
    form.gps_error = geolocationErrorText(error);
    showToast(form.gps_error);
  } finally {
    form.locating = false;
  }
}

function delayConfigOptions() {
  return [
    { value: 'journal_deadline', label: '实习日志' },
    { value: 'report_deadline', label: '实习报告' },
  ];
}

function delayConfigText(value) {
  return delayConfigOptions().find(item => item.value === value)?.label || value || '-';
}

function practiceSourceText(value) {
  const names = {
    jw: '教务拉取',
    manual: '手动填报',
  };
  return names[value] || value || '-';
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

function statusText(value) {
  const names = {
    draft: '草稿',
    wait: '待审核',
    accept: '已通过',
    modify: '需修改',
    refuse: '已退回',
    skipped: '跳过',
    enabled: '启用',
    changing: '变更中',
    disabled: '停用',
    changed: '已变更',
    completed: '已完成',
    pending: '待处理',
    active: '有效',
    removed: '已移除',
    signed: '已签署',
    published: '已发布',
    confirmed: '已确认',
    complete: '完整',
    incomplete: '待补齐',
    archived: '已归档',
    missing: '待补齐',
    not_required: '不适用',
  };
  return names[value] || value || '-';
}

async function loadMobileSupportCategories() {
  if (!isLoggedIn.value) {
    support.doc.categories = [];
    support.template.categories = [];
    return;
  }

  const tasks = [];
  if (hasPermission('doc:view')) {
    tasks.push(fetchDocCategories().then((data) => {
      support.doc.categories = flattenSupportCategories(data.tree || data.items || []);
    }));
  }
  if (hasPermission('template:view')) {
    tasks.push(fetchTemplateCategories().then((data) => {
      support.template.categories = data.items || [];
    }));
  }

  if (!tasks.length) {
    return;
  }

  try {
    await Promise.all(tasks);
  } catch (error) {
    support.doc.message = error.message;
    support.template.message = error.message;
  }
}

async function loadMobileDocs(page = 1, append = false) {
  if (!isLoggedIn.value || !hasPermission('doc:view') || support.doc.loading) {
    return;
  }

  support.doc.loading = true;
  support.doc.message = '';
  try {
    if (!support.doc.categories.length) {
      const categories = await fetchDocCategories();
      support.doc.categories = flattenSupportCategories(categories.tree || categories.items || []);
    }
    const data = await fetchDocList({
      page,
      page_size: support.doc.pagination.page_size,
      category_id: support.doc.filters.category_id || '',
      keyword: support.doc.filters.keyword || '',
    });
    const items = data.items || [];
    support.doc.items = append ? [...support.doc.items, ...items] : items;
    support.doc.pagination = {
      page: Number(data.pagination?.page || page),
      page_size: Number(data.pagination?.page_size || support.doc.pagination.page_size),
      total: Number(data.pagination?.total || 0),
    };
  } catch (error) {
    support.doc.message = error.message;
    showToast(error.message);
  } finally {
    support.doc.loading = false;
  }
}

async function loadMobileTemplates(page = 1, append = false) {
  if (!isLoggedIn.value || !hasPermission('template:view') || support.template.loading) {
    return;
  }

  support.template.loading = true;
  support.template.message = '';
  try {
    if (!support.template.categories.length) {
      const categories = await fetchTemplateCategories();
      support.template.categories = categories.items || [];
    }
    const data = await fetchTemplateList({
      page,
      page_size: support.template.pagination.page_size,
      category_id: support.template.filters.category_id || '',
      keyword: support.template.filters.keyword || '',
    });
    const items = data.items || [];
    support.template.items = append ? [...support.template.items, ...items] : items;
    support.template.pagination = {
      page: Number(data.pagination?.page || page),
      page_size: Number(data.pagination?.page_size || support.template.pagination.page_size),
      total: Number(data.pagination?.total || 0),
    };
  } catch (error) {
    support.template.message = error.message;
    showToast(error.message);
  } finally {
    support.template.loading = false;
  }
}

async function openMobileDoc(row) {
  if (!row?.id) {
    return;
  }

  support.doc.detail.visible = true;
  support.doc.detail.loading = true;
  support.doc.detail.message = '';
  support.doc.detail.article = row;
  try {
    const data = await fetchDocDetail(row.id);
    support.doc.detail.article = data.article || row;
  } catch (error) {
    support.doc.detail.message = error.message;
    showToast(error.message);
  } finally {
    support.doc.detail.loading = false;
  }
}

function closeMobileDoc() {
  support.doc.detail.visible = false;
}

async function downloadMobileTemplate(row) {
  if (!row?.id) {
    return;
  }

  support.template.message = '';
  try {
    const data = await downloadTemplateItem(row.id);
    if (data.url) {
      window.open(data.url, '_blank', 'noopener');
    } else {
      support.template.message = '模板文件暂无下载地址';
      showToast(support.template.message);
    }
    await loadMobileTemplates(support.template.pagination.page || 1);
  } catch (error) {
    support.template.message = error.message;
    showToast(error.message);
  }
}

function canLoadMoreSupport(type) {
  const target = type === 'template' ? support.template : support.doc;
  return target.items.length < (target.pagination.total || 0);
}

function flattenSupportCategories(rows, level = 0) {
  const result = [];
  (rows || []).forEach((row) => {
    result.push({
      ...row,
      name: `${'　'.repeat(level)}${row.name || '-'}`,
    });
    if (row.children?.length) {
      result.push(...flattenSupportCategories(row.children, level + 1));
    }
  });
  return result;
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

async function loadMessages(page = 1, append = false) {
  if (!isLoggedIn.value || messageState.loading) {
    return;
  }

  messageState.loading = true;
  messageState.message = '';
  try {
    const data = await fetchMessages({
      page,
      page_size: messageState.pagination.page_size,
      status: messageState.filter,
      type: messageState.type,
    });
    const items = data.items || [];
    messageState.items = append ? [...messageState.items, ...items] : items;
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

function loadMoreMessages() {
  if (messageState.items.length >= messageState.pagination.total) {
    return;
  }
  loadMessages(messageState.pagination.page + 1, true);
}

function setMobileMessageFilter(filter) {
  messageState.filter = filter;
  loadMessages(1);
}

function setMobileMessageType(type) {
  messageState.type = type;
  loadMessages(1);
}

async function handleMobileMessageClick(item) {
  if (!item?.target_id || item.is_read) {
    return;
  }

  try {
    const data = await markMessagesRead({ ids: [item.target_id] });
    item.is_read = true;
    item.read_at = new Date().toLocaleString();
    messageState.summary = data.summary || messageState.summary;
    if (messageState.filter === 'unread') {
      messageState.items = messageState.items.filter(row => row.target_id !== item.target_id);
      messageState.pagination.total = Math.max(0, messageState.pagination.total - 1);
    }
  } catch (error) {
    messageState.message = error.message;
    showToast(error.message);
  }
}

async function markAllMobileMessagesRead() {
  if (messageUnreadCount.value <= 0 || messageState.loading) {
    return;
  }

  messageState.loading = true;
  messageState.message = '';
  try {
    const data = await markMessagesRead({ all: true });
    messageState.summary = data.summary || { unread: 0, by_type: {} };
    messageState.items = messageState.filter === 'unread'
      ? []
      : messageState.items.map(item => ({ ...item, is_read: true, read_at: item.read_at || new Date().toLocaleString() }));
    if (messageState.filter === 'unread') {
      messageState.pagination = { ...messageState.pagination, page: 1, total: 0 };
    }
  } catch (error) {
    messageState.message = error.message;
    showToast(error.message);
  } finally {
    messageState.loading = false;
  }
}

function resetMessageState() {
  messageState.message = '';
  messageState.filter = 'all';
  messageState.type = 'all';
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

function resetSupportState() {
  support.doc.loading = false;
  support.doc.message = '';
  support.doc.categories = [];
  support.doc.items = [];
  support.doc.filters = {
    category_id: '',
    keyword: '',
  };
  support.doc.pagination = {
    page: 1,
    page_size: 20,
    total: 0,
  };
  support.doc.detail = {
    visible: false,
    loading: false,
    message: '',
    article: null,
  };

  support.template.loading = false;
  support.template.message = '';
  support.template.categories = [];
  support.template.items = [];
  support.template.filters = {
    category_id: '',
    keyword: '',
  };
  support.template.pagination = {
    page: 1,
    page_size: 20,
    total: 0,
  };
}

function resetInternshipState() {
  internship.loading = false;
  internship.message = '';
  internship.panel = 'workbench';
  internship.submitSection = '';
  internship.reviewList = 'applications';
  internship.manageList = 'arrangements';
  internship.overview = emptyInternshipOverview();
  internship.options = emptyInternshipOptions();
  Object.keys(internship.lists).forEach((key) => {
    internship.lists[key] = emptyPagedList();
  });
  Object.keys(internship.filters).forEach((key) => {
    internship.filters[key] = emptyInternshipFilters();
  });
  internship.forms.application = { arrangement_id: null, type: 'distributed', remark: '' };
  internship.forms.sign = { arrangement_id: null, location: '', longitude: null, latitude: null, accuracy: null, located_at: '', locating: false, gps_error: '' };
  internship.forms.journal = { id: null, arrangement_id: null, title: '', content: '' };
  internship.forms.report = { id: null, arrangement_id: null, title: '', content: '' };
  internship.forms.delay = { id: null, arrangement_id: null, config_key: 'report_deadline', requested_date: '', reason: '' };
  internship.forms.score = {
    pair_id: null,
    student_id: null,
    arrangement_id: null,
    sign_in_score: '',
    journal_score: '',
    report_score: '',
    enterprise_score: '',
  };
  internship.reviewDialog.visible = false;
  internship.reviewDialog.row = null;
  internship.reviewDialog.reason = '';
  internship.timelineDialog.visible = false;
  internship.timelineDialog.row = null;
  internship.timelineDialog.items = [];
  internship.timelineDialog.cycles = [];
  internship.timelineDialog.message = '';
}

function resetPracticeState() {
  practice.training = createPracticeState();
  practice.lab = createPracticeState();
  practiceReviewDialog.visible = false;
  practiceReviewDialog.row = null;
  practiceReviewDialog.reason = '';
}

function resetMobileLocalState() {
  switchAccountState.items = [];
  switchAccountState.message = '';
  switchAccountState.loading = false;
  resetMessageState();
  resetSupportState();
  resetInternshipState();
  resetPracticeState();
}

function handleAuthExpired(event) {
  const message = event?.detail?.message || '登录已过期，请重新登录';
  state.context = {};
  state.permissions = [];
  state.menus = [];
  state.dataScope = null;
  state.error = message;
  loginState.loading = false;
  loginState.message = message;
  resetMobileNavigationToHome();
  resetMobileLocalState();
  showToast(message);
}

function messageTypeText(type) {
  return messageTypeNames[type] || type || '系统通知';
}

function mobileMessageTypeUnread(type) {
  if (type === 'all') {
    return messageUnreadCount.value;
  }
  return Number(messageState.summary.by_type?.[type] || 0);
}

function messageLevelText(level) {
  return messageLevelNames[level] || level || '普通';
}

function isOwnMobileMessage(item) {
  return Number(item?.sender_id || 0) > 0
    && Number(item.sender_id) === Number(state.context.account_id || 0);
}

async function openMobileMessageLink(item) {
  await handleMobileMessageClick(item);
  const link = String(item?.link_url || '').trim();
  if (!link) {
    return;
  }
  if (link.startsWith('#tab=')) {
    const tab = link.replace('#tab=', '').trim();
    if (tab) {
      activeTab.value = tab;
    }
    return;
  }
  if (link.startsWith('#')) {
    const tab = link.slice(1).split(':')[0].replace('panel=', '').trim();
    if (tab && isMobileModuleVisible(tab)) {
      activeTab.value = tab;
    }
    return;
  }
  window.open(link, '_blank', 'noopener,noreferrer');
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

function mobileNavigationSnapshot() {
  return {
    tab: activeTab.value,
    internshipPanel: internship.panel,
    internshipSubmitSection: internship.submitSection,
    internshipReviewList: internship.reviewList,
    internshipManageList: internship.manageList,
    trainingPanel: practice.training.panel,
    labPanel: practice.lab.panel,
  };
}

function mobileNavigationKey() {
  return JSON.stringify(mobileNavigationSnapshot());
}

async function applyMobileNavigationKey(key) {
  let snapshot = null;
  try {
    snapshot = JSON.parse(key);
  } catch {
    return;
  }

  mobileNavigationState.restoring = true;
  activeTab.value = snapshot.tab || 'home';
  internship.panel = snapshot.internshipPanel || 'workbench';
  internship.submitSection = snapshot.internshipSubmitSection || '';
  internship.reviewList = snapshot.internshipReviewList || 'applications';
  internship.manageList = snapshot.internshipManageList || 'arrangements';
  practice.training.panel = snapshot.trainingPanel || practice.training.panel;
  practice.lab.panel = snapshot.labPanel || practice.lab.panel;
  await nextTick();
  mobileNavigationState.restoring = false;
}

async function goMobileBack() {
  if (!canGoMobileBack.value) {
    return;
  }

  const current = mobileNavigationKey();
  const target = mobileNavigationState.backStack.pop();
  if (!target) {
    return;
  }
  if (current !== target) {
    mobileNavigationState.forwardStack.push(current);
  }
  await applyMobileNavigationKey(target);
}

async function goMobileForward() {
  if (!canGoMobileForward.value) {
    return;
  }

  const current = mobileNavigationKey();
  const target = mobileNavigationState.forwardStack.pop();
  if (!target) {
    return;
  }
  if (current !== target) {
    mobileNavigationState.backStack.push(current);
  }
  await applyMobileNavigationKey(target);
}

function clearMobileNavigation() {
  mobileNavigationState.backStack.splice(0);
  mobileNavigationState.forwardStack.splice(0);
}

function resetMobileNavigationToHome() {
  mobileNavigationState.restoring = true;
  activeTab.value = 'home';
  clearMobileNavigation();
  nextTick(() => {
    mobileNavigationState.restoring = false;
  });
}

async function refreshMobilePage() {
  await load();
  await loadSwitchableAccounts();
  if (activeTab.value === 'message') {
    await loadMessages(1);
    return;
  }
  if (activeTab.value === 'doc') {
    await loadMobileDocs(1);
    return;
  }
  if (activeTab.value === 'templateLib') {
    await loadMobileTemplates(1);
    return;
  }
  if (activeTab.value === 'internship') {
    await loadInternship();
    return;
  }
  if (isPracticeTab(activeTab.value)) {
    await loadPractice(activeTab.value);
    return;
  }
  await loadMobileSupportCategories();
  await loadMessageSummary();
}

async function refreshMobileSession(resetWorkspace = false) {
  if (resetWorkspace) {
    resetMobileLocalState();
  }

  await load();
  if (!isLoggedIn.value) {
    switchAccountState.items = [];
    return;
  }

  await loadSwitchableAccounts();
  await loadMessageSummary();
  await loadMobileSupportCategories();
  await loadInternship();
  if (isPracticeTab(activeTab.value)) {
    await loadPractice(activeTab.value);
  }
}

async function loadSwitchableAccounts() {
  if (!isLoggedIn.value) {
    switchAccountState.items = [];
    switchAccountState.message = '';
    return;
  }

  switchAccountState.loading = true;
  switchAccountState.message = '';
  try {
    const data = await fetchSwitchableAccounts();
    switchAccountState.items = data.accounts || [];
  } catch (error) {
    switchAccountState.items = [];
    switchAccountState.message = error.message;
  } finally {
    switchAccountState.loading = false;
  }
}

async function switchMobileAccount(account) {
  const accountId = Number(account?.id || 0);
  if (!accountId || account?.is_current || switchAccountState.loading) {
    return;
  }

  switchAccountState.loading = true;
  switchAccountState.message = '';
  try {
    await switchAccount({
      account_id: accountId,
      client: 'H5',
    });
    await refreshMobileSession(true);
    showToast('已切换身份');
  } catch (error) {
    switchAccountState.message = error.message;
    showToast(error.message);
  } finally {
    switchAccountState.loading = false;
  }
}

function accountSwitchTitle(account) {
  return account?.name || account?.login_name || '未命名账号';
}

function accountSwitchLabel(account) {
  const parts = [
    account?.role_name || roleNameMap[account?.role_type] || account?.role_type || '未分配角色',
    account?.login_name || '',
  ].filter(Boolean);
  return parts.join(' / ');
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
      client: 'H5',
    });
    params.delete('passkey');
    params.delete('login_key');
    const query = params.toString();
    history.replaceState(null, '', `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`);
    return true;
  } catch (error) {
    loginState.message = error.message;
    showToast(error.message);
    return false;
  } finally {
    loginState.loading = false;
  }
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
      client: 'H5',
    });
    registerState.open = false;
    await refreshMobileSession(true);
    showToast('注册成功');
  } catch (error) {
    registerState.message = error.message;
    showToast(error.message);
  } finally {
    registerState.loading = false;
  }
}

async function submitLogin() {
  loginState.loading = true;
  loginState.message = '';
  try {
    await loginApi({
      login_name: loginForm.login_name,
      password: loginForm.password,
      client: 'H5',
    });
    await refreshMobileSession(true);
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
    resetMobileNavigationToHome();
    resetMobileLocalState();
    await load();
    await loadRegisterOptions();
  } catch (error) {
    loginState.message = error.message;
  } finally {
    loginState.loading = false;
  }
}

watch(activeTab, (tab) => {
  if (tab === 'internship') {
    loadInternship();
  }
  if (isPracticeTab(tab)) {
    loadPractice(tab);
  }
  if (tab === 'message') {
    loadMessages(1);
  }
  if (tab === 'doc') {
    loadMobileDocs(1);
  }
  if (tab === 'templateLib') {
    loadMobileTemplates(1);
  }
});

watch(roleType, () => {
  if (!['home', 'mine', 'message'].includes(activeTab.value) && !visibleMobileModules.value.some(module => module.key === activeTab.value)) {
    activeTab.value = 'home';
  }
  const panels = internshipPanels.value.map(item => item.key);
  if (!panels.includes(internship.panel)) {
    internship.panel = panels[0] || 'workbench';
  }
  normalizeInternshipListViews();
  normalizePracticePanel('training');
  normalizePracticePanel('lab');
});

watch(visibleMobileModules, () => {
  if (!['home', 'mine', 'message'].includes(activeTab.value) && !visibleMobileModules.value.some(module => module.key === activeTab.value)) {
    activeTab.value = 'home';
  }
});

watch(() => mobileNavigationKey(), (current, previous) => {
  if (mobileNavigationState.restoring || !previous || current === previous) {
    return;
  }

  mobileNavigationState.backStack.push(previous);
  if (mobileNavigationState.backStack.length > 40) {
    mobileNavigationState.backStack.shift();
  }
  mobileNavigationState.forwardStack.splice(0);
});

onMounted(async () => {
  window.addEventListener('practical-auth-expired', handleAuthExpired);
  await loadRegisterOptions();
  await consumeUrlPasskey();
  await refreshMobileSession();
});

onBeforeUnmount(() => {
  window.removeEventListener('practical-auth-expired', handleAuthExpired);
});
</script>
