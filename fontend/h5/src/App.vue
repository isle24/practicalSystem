<template>
  <main class="mobile-shell">
    <header class="mobile-top">
      <div>
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
        <small v-if="loginState.message">{{ loginState.message }}</small>
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
          <van-cell title="学校代码" :value="schoolCodeText" />
          <van-cell title="数据范围" :label="scopeDetailText" :value="scopeText" />
          <van-cell v-if="hasPermission('doc:view')" title="文档中心" value="查看" is-link @click="activeTab = 'doc'" />
          <van-cell v-if="hasPermission('template:view')" title="模板库" value="下载" is-link @click="activeTab = 'templateLib'" />
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
                  <small>{{ messageTimeText(item.created_at) }}</small>
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
          <section class="mobile-card form-card">
            <header>
              <ClipboardList :size="20" />
              <strong>实习申请</strong>
            </header>
            <label>
              <span>实习安排</span>
              <select v-model.number="internship.forms.application.arrangement_id">
                <option v-for="item in internship.options.arrangements" :key="item.id" :value="item.id">
                  {{ item.title }}
                </option>
              </select>
            </label>
            <label>
              <span>指导教师</span>
              <select v-model.number="internship.forms.application.teacher_id">
                <option v-for="teacher in internship.options.teachers" :key="teacher.teacher_id" :value="teacher.teacher_id">
                  {{ teacher.teacher_name }}
                </option>
              </select>
            </label>
            <label>
              <span>备注</span>
              <textarea v-model="internship.forms.application.remark" rows="3" />
            </label>
            <van-button block type="primary" :loading="internship.loading" @click="submitApplication">
              提交申请
            </van-button>
          </section>
          <section class="mobile-card">
            <header>
              <FileClock :size="20" />
              <strong>申请记录</strong>
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
              :class="{ active: internship.submitSection === item.key }"
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
              <span>实习安排</span>
              <select v-model.number="internship.forms.sign.arrangement_id">
                <option v-for="item in internship.options.arrangements" :key="item.id" :value="item.id">
                  {{ item.title }}
                </option>
              </select>
            </label>
            <label>
              <span>位置</span>
              <input v-model="internship.forms.sign.location" placeholder="当前位置或实习单位">
            </label>
            <van-button block type="primary" :loading="internship.loading" @click="submitSignIn">
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
              :title="row.arrangement_title || '实习签到'"
              :label="`${row.date || '-'} / ${row.sign_time || '-'} / ${row.location || '-'}`"
              :value="signTypeText(row.sign_type)"
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
              <small>{{ stageDeadlineText('journal_deadline') }}</small>
            </header>
            <label>
              <span>标题</span>
              <input v-model="internship.forms.journal.title">
            </label>
            <label>
              <span>内容</span>
              <textarea v-model="internship.forms.journal.content" rows="4" />
            </label>
            <van-button block type="primary" :loading="internship.loading" @click="submitJournal">
              {{ internship.forms.journal.id ? '重新提交日志' : '提交日志' }}
            </van-button>
          </section>

          <section v-if="internship.submitSection === 'report'" class="mobile-card form-card">
            <header>
              <FileText :size="20" />
              <strong>实习报告</strong>
              <small>{{ stageDeadlineText('report_deadline') }}</small>
            </header>
            <label>
              <span>标题</span>
              <input v-model="internship.forms.report.title">
            </label>
            <label>
              <span>内容</span>
              <textarea v-model="internship.forms.report.content" rows="4" />
            </label>
            <van-button block type="primary" :loading="internship.loading" @click="submitReport">
              {{ internship.forms.report.id ? '重新提交报告' : '提交报告' }}
            </van-button>
          </section>

          <section v-if="internship.submitSection === 'delay'" class="mobile-card form-card">
            <header>
              <FileClock :size="20" />
              <strong>延期申请</strong>
            </header>
            <label>
              <span>实习安排</span>
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
                <div class="cell-actions">
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

        <template v-if="isTeacherRole && internship.panel === 'score'">
          <section class="mobile-card form-card">
            <header>
              <GraduationCap :size="20" />
              <strong>成绩录入</strong>
            </header>
            <label>
              <span>学生</span>
              <select v-model.number="internship.forms.score.pair_id" @change="selectScorePair">
                <option v-for="pair in internship.lists.pairs.items" :key="pair.id" :value="pair.id">
                  {{ pair.student_name }} / {{ pair.arrangement_title }}
                </option>
              </select>
            </label>
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
              <strong>成绩记录</strong>
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
              :title="row.student_name || row.student_num"
              :label="`${row.arrangement_title || '-'} / 总评 ${row.final_score ?? '-'}`"
              :value="row.teacher_name || '-'"
            />
            <div v-if="!internship.lists.scores.items.length" class="mobile-empty">暂无成绩记录</div>
            <div class="mobile-list-footer">
              <span>共 {{ internship.lists.scores.pagination.total || 0 }} 条</span>
              <button v-if="canLoadMore('scores')" :disabled="internship.loading" @click="loadMoreInternshipList('scores')">
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
                v-model="internship.filters[currentManageListConfig.key].status"
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
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { showToast } from 'vant';
import {
  BriefcaseBusiness,
  BookOpen,
  CalendarCheck,
  CheckCircle2,
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
  fetchInternshipTimeline,
  fetchMessages,
  fetchMessageSummary,
  fetchTemplateCategories,
  fetchTemplateList,
  markMessagesRead,
  requestInternshipModification,
  reviewInternshipApplication,
  reviewInternshipDelay,
  reviewInternshipJournal,
  reviewInternshipPlan,
  reviewInternshipReport,
  saveInternshipApplication,
  saveInternshipDelay,
  saveInternshipJournal,
  saveInternshipReport,
  saveInternshipScore,
  saveInternshipSignIn,
  login as loginApi,
  logout as logoutApi,
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
const messageState = reactive({
  loading: false,
  message: '',
  filter: 'all',
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
  forms: {
    application: {
      arrangement_id: null,
      teacher_id: null,
      remark: '',
    },
    sign: {
      arrangement_id: null,
      location: '',
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

const modules = [
  {
    key: 'internship',
    title: '实习管理',
    desc: '申请、签到、日志和指导关系入口',
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

const isLoggedIn = computed(() => Boolean(state.context.account_id));
const roleType = computed(() => state.context.role_type || '');
const isStudentRole = computed(() => roleType.value === 'student');
const isTeacherRole = computed(() => roleType.value === 'teacher');
const isAdminRole = computed(() => ['super_admin', 'school_admin', 'college_admin', 'profession_admin'].includes(roleType.value));
const visibleMobileModules = computed(() => modules.filter(canShowMobileModule));
const canReviewInternship = computed(() => hasPermission('internship:approve'));
const canReviewInternshipPlan = computed(() => hasPermission('internship:plan') && isAdminRole.value);
const roleNameMap = {
  super_admin: '系统管理员',
  school_admin: '学校管理员',
  college_admin: '学院管理员',
  profession_admin: '专业管理员',
  teacher: '指导老师',
  student: '学生',
  enterprise: '企业导师',
};

function canShowMobileModule(module) {
  if (!hasPermission(module.permission)) {
    return false;
  }
  if (['training', 'lab'].includes(module.key)) {
    return isAdminRole.value;
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
  teacher_user_id: '本人指导学生',
  student_user_id: '本人实习数据',
};
const roleDisplayText = computed(() => state.context.role_name || roleNameMap[roleType.value] || state.context.role_id || '未登录');
const roleText = computed(() => roleDisplayText.value);
const userText = computed(() => state.context.user_name || state.context.name || state.context.login_name || '未登录');
const accountText = computed(() => state.context.login_name || '-');
const schoolText = computed(() => state.context.school_name || '成都锦城学院');
const schoolCodeText = computed(() => state.context.school_code || '2184');
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
    return '教师指导';
  }
  if (isAdminRole.value) {
    return '实习管理';
  }
  return '实习';
});
const internshipRoleDesc = computed(() => {
  if (isStudentRole.value) {
    return '申请、签到、日志和报告提交';
  }
  if (isTeacherRole.value) {
    return '审核申请、评阅材料和录入成绩';
  }
  if (isAdminRole.value) {
    return '查看安排、申请、配对和数据状态';
  }
  return '按当前角色展示可用实习功能';
});
const internshipPanels = computed(() => {
  if (isStudentRole.value) {
    return [
      { key: 'workbench', name: '概况', icon: Home },
      { key: 'apply', name: '申请', icon: ClipboardList },
      { key: 'submit', name: '提交', icon: Send },
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
const delayStatusOptions = [
  { value: 'wait', label: '待审核' },
  { value: 'accept', label: '已通过' },
  { value: 'refuse', label: '已退回' },
];
const mobileListConfigs = computed(() => ({
  arrangements: {
    key: 'arrangements',
    entity: '',
    title: '实习安排',
    shortTitle: '安排',
    icon: CalendarCheck,
    keywordPlaceholder: '安排、届次、学院、专业',
    gradeFilter: true,
    statusOptions: [
      { value: 'enabled', label: '启用' },
      { value: 'disabled', label: '停用' },
    ],
    emptyText: '暂无实习安排',
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
  applications: {
    key: 'applications',
    entity: 'application',
    title: '实习申请',
    shortTitle: '申请',
    icon: ClipboardList,
    keywordPlaceholder: '学生、学号、实习安排、教师',
    gradeFilter: true,
    statusOptions: reviewStatusOptions,
    emptyText: '暂无实习申请',
  },
  pairs: {
    key: 'pairs',
    entity: '',
    title: '指导关系',
    shortTitle: '关系',
    icon: UsersRound,
    keywordPlaceholder: '学生、学号、教师、安排',
    gradeFilter: true,
    statusOptions: [
      { value: 'active', label: '有效' },
      { value: 'removed', label: '已移除' },
    ],
    emptyText: '暂无指导关系',
  },
  signIns: {
    key: 'signIns',
    entity: '',
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
    entity: '',
    title: '实习成绩',
    shortTitle: '成绩',
    icon: GraduationCap,
    keywordPlaceholder: '学生、学号、安排、教师',
    gradeFilter: true,
    statusOptions: [],
    emptyText: '暂无成绩记录',
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
    entity: '',
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
    entity: '',
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
  const keys = isTeacherRole.value ? ['applications', 'journals', 'reports', 'delays'] : ['applications', 'delays'];
  if (canReviewInternshipPlan.value) {
    keys.push('plans');
  }
  return keys.map(getMobileListConfig).filter(Boolean);
});
const manageListTabs = computed(() => [
  'arrangements',
  'plans',
  'pairs',
  'signIns',
  'journals',
  'reports',
  'delays',
  'scores',
  'archiveMaterials',
].map(getMobileListConfig).filter(Boolean));
const currentReviewListConfig = computed(() => getMobileListConfig(internship.reviewList) || reviewListTabs.value[0] || null);
const currentManageListConfig = computed(() => getMobileListConfig(internship.manageList) || manageListTabs.value[0] || null);
const internshipSummaries = computed(() => [
  { name: '安排', value: internship.overview.arrangements || 0 },
  { name: '待审', value: internship.overview.applications_waiting || 0 },
  { name: '关系', value: internship.overview.active_pairs || 0 },
]);
const studentFlowSteps = [
  '选择实习安排并提交申请',
  '教师和管理员审核',
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
  },
  {
    key: 'report',
    title: '实习报告',
    desc: '提交阶段或总结报告',
    icon: FileText,
    meta: stageDeadlineText('report_deadline'),
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
      { title: '可申请安排', label: '当前角色可见的实习安排', value: internship.options.arrangements.length || '-' },
      { title: '我的申请', label: '申请记录', value: internship.lists.applications.pagination.total || 0 },
      { title: '指导关系', label: '通过后生成', value: internship.lists.pairs.pagination.total || 0 },
    ];
  }
  if (isTeacherRole.value) {
    return [
      { title: '待审申请', label: '学生选择当前教师后的申请', value: internship.lists.applications.pagination.total || 0 },
      { title: '待评日志', label: '学生提交的实习日志', value: internship.overview.journals_waiting || 0 },
      { title: '待评报告', label: '学生提交的实习报告', value: internship.overview.reports_waiting || 0 },
    ];
  }
  return [
    { title: '实习安排', label: '全校实习安排', value: internship.overview.arrangements || 0 },
    { title: '待审申请', label: '需要管理员审核', value: internship.overview.applications_waiting || 0 },
    { title: '有效配对', label: '学生与指导教师关系', value: internship.overview.active_pairs || 0 },
  ];
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

function emptyInternshipFilters() {
  return {
    semester: '',
    grade_id: '',
    dep_id: '',
    profession_id: '',
    class_id: '',
    arrangement_id: '',
    status: '',
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
  const filters = internship.filters[key] || {};
  const gradeId = Number(filters.grade_id || 0);
  const grade = internship.options.grades.find(item => Number(item.grade_id) === gradeId);
  if (grade?.dep_id) {
    return internship.options.departments.filter(item => Number(item.dep_id) === Number(grade.dep_id));
  }
  return internship.options.departments;
}

function mobileProfessionOptions(key) {
  const filters = internship.filters[key] || {};
  const gradeId = Number(filters.grade_id || 0);
  const depId = Number(filters.dep_id || 0);
  return internship.options.professions.filter((item) => {
    const matchGrade = !gradeId || Number(item.grade_id || 0) === gradeId;
    const matchDepartment = !depId || Number(item.dep_id || 0) === depId;
    return matchGrade && matchDepartment;
  });
}

function mobileClassOptions(key) {
  const filters = internship.filters[key] || {};
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
  if (isAdminRole.value && (studentScopedListKeys.has(key) || ['arrangements', 'plans'].includes(key))) {
    filters.push({
      key: 'dep_id',
      label: '学院',
      placeholder: '全部学院',
      options: selectFilterItems(mobileDepartmentOptions(key), 'dep_id', 'dep_name'),
    });
  }
  if (isAdminRole.value && (studentScopedListKeys.has(key) || key === 'arrangements')) {
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
  const arrangement = row.arrangement_title || (row.arrangement_id ? `安排ID ${row.arrangement_id}` : '');
  const titles = {
    arrangements: row.title || row.name || `安排ID ${row.id}`,
    // 暂时隐藏学期展示，后续需要时恢复 row.semester。
    plans: row.dep_name || `计划ID ${row.id}`,
    applications: student || arrangement || `申请ID ${row.id}`,
    pairs: student || `关系ID ${row.id}`,
    signIns: student || arrangement || `签到ID ${row.id}`,
    journals: row.title || student || `日志ID ${row.id}`,
    reports: row.title || student || `报告ID ${row.id}`,
    delays: joinFact([student, delayConfigText(row.config_key)]) || `延期ID ${row.id}`,
    scores: student || `成绩ID ${row.id}`,
    archiveMaterials: student || arrangement || `归档ID ${row.id}`,
    insurances: student || row.insurance_company || `保险ID ${row.id}`,
    safetyLetters: student || `承诺ID ${row.id}`,
  };
  return titles[key] || row.title || row.name || `记录ID ${row.id}`;
}

function mobileListValue(key, row) {
  if (key === 'signIns') {
    return signTypeText(row.sign_type);
  }
  if (key === 'scores') {
    return row.final_score !== null && row.final_score !== undefined ? `总评 ${row.final_score}` : '-';
  }
  if (key === 'insurances') {
    return row.policy_number || statusText(row.status);
  }
  if (key === 'archiveMaterials') {
    return row.archive_status_text || row.material_progress || statusText(row.archive_status);
  }
  return statusText(row.status);
}

function mobileListFacts(key, row) {
  const student = joinFact([row.student_name, row.student_num]);
  const arrangement = row.arrangement_title || (row.arrangement_id ? `安排ID ${row.arrangement_id}` : '');
  const facts = {
    arrangements: [
      // 暂时隐藏学期字段，后续需要时恢复。
      // namedFact('学期', row.semester),
      namedFact('类型', arrangementTypeText(row.type)),
      namedFact('方式', organizeModeText(row.organize_mode)),
      namedFact('时间', dateRangeText(row.start_date, row.end_date)),
      namedFact('范围', joinFact([row.dep_name || '全校', row.profession_name || '全部专业', row.grade_name])),
    ],
    plans: [
      // 暂时隐藏学期字段，后续需要时恢复。
      // namedFact('学期', row.semester),
      namedFact('学院', row.dep_name),
      namedFact('提交人', row.submitter_name),
      namedFact('内容', planContentText(row.plan_content, 48)),
    ],
    applications: [
      namedFact('学号', row.student_num),
      namedFact('届次', row.grade_name),
      namedFact('安排', arrangement),
      namedFact('学院专业', joinFact([row.dep_name, row.profession_name])),
      namedFact('教师审核', statusText(row.teacher_status)),
      namedFact('管理审核', statusText(row.admin_status)),
      namedFact('提交', row.created_at),
    ],
    pairs: [
      namedFact('学号', row.student_num),
      namedFact('届次', row.grade_name),
      namedFact('教师', row.teacher_name || row.teacher_num),
      namedFact('安排', arrangement),
      namedFact('创建', row.created_at),
    ],
    signIns: [
      namedFact('学生', student),
      namedFact('届次', row.grade_name),
      namedFact('安排', arrangement),
      namedFact('时间', joinFact([row.date, row.sign_time])),
      namedFact('地点', row.location),
    ],
    journals: [
      namedFact('学生', student),
      namedFact('届次', row.grade_name),
      namedFact('日期', row.date || row.created_at),
      namedFact('安排', arrangement),
      namedFact('内容', previewText(row.content, 42)),
    ],
    reports: [
      namedFact('学生', student),
      namedFact('届次', row.grade_name),
      namedFact('安排', arrangement),
      namedFact('提交', row.submitted_at || row.created_at),
      namedFact('内容', previewText(row.content, 42)),
    ],
    delays: [
      namedFact('学生', student),
      namedFact('届次', row.grade_name),
      namedFact('安排', arrangement),
      namedFact('延期类型', delayConfigText(row.config_key)),
      namedFact('延期至', row.requested_date),
      namedFact('原因', previewText(row.reason, 42)),
    ],
    scores: [
      namedFact('届次', row.grade_name),
      namedFact('安排', arrangement),
      namedFact('评分人', row.teacher_name || row.teacher_num),
      namedFact('分项', scoreBreakdownText(row)),
    ],
    archiveMaterials: [
      namedFact('学生', student || (row.student_id ? `学生ID ${row.student_id}` : '')),
      namedFact('届次', row.grade_name),
      namedFact('安排', arrangement),
      namedFact('类型', row.arrangement_type_text),
      namedFact('进度', row.material_progress),
      namedFact('缺失', row.missing_materials),
    ],
    insurances: [
      namedFact('学生', student || (row.student_id ? `学生ID ${row.student_id}` : '')),
      namedFact('届次', row.grade_name),
      namedFact('安排', arrangement),
      namedFact('保险公司', row.insurance_company),
      namedFact('时间', dateRangeText(row.start_date, row.end_date)),
    ],
    safetyLetters: [
      namedFact('学生', student || (row.student_id ? `学生ID ${row.student_id}` : '')),
      namedFact('届次', row.grade_name),
      namedFact('安排', arrangement),
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
  if (context === 'review' && canReviewEntity(config.entity)) {
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
    plans: fetchInternshipPlans,
    applications: fetchInternshipApplications,
    pairs: fetchInternshipPairs,
    signIns: fetchInternshipSignIns,
    journals: fetchInternshipJournals,
    reports: fetchInternshipReports,
    delays: fetchInternshipDelays,
    scores: fetchInternshipScores,
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

function applyDefaultInternshipSelection() {
  const firstArrangement = internship.options.arrangements[0];
  const firstTeacher = internship.options.teachers[0];
  if (firstArrangement) {
    internship.forms.application.arrangement_id ||= firstArrangement.id;
    internship.forms.sign.arrangement_id ||= firstArrangement.id;
    internship.forms.delay.arrangement_id ||= firstArrangement.id;
  }
  if (firstTeacher) {
    internship.forms.application.teacher_id ||= firstTeacher.teacher_id;
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
      loadInternshipList('pairs'),
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
    const [pairs] = await Promise.all([
      fetchInternshipPairs({ page: 1, page_size: 100 }),
      loadInternshipList('scores'),
    ]);
    setPagedList('pairs', pairs);
    const firstPair = internship.lists.pairs.items[0];
    if (firstPair && !internship.forms.score.pair_id) {
      internship.forms.score.pair_id = firstPair.id;
      selectScorePair();
    }
    return;
  }
  if (internship.panel === 'manage') {
    await loadInternshipList(currentManageListConfig.value?.key || 'arrangements');
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
  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipApplication({
      arrangement_id: internship.forms.application.arrangement_id,
      type: 'centralized',
      status: 'wait',
      teacher_ids: internship.forms.application.teacher_id ? [internship.forms.application.teacher_id] : [],
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
  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipSignIn({
      arrangement_id: internship.forms.sign.arrangement_id,
      location: internship.forms.sign.location || '移动端签到',
    });
    internship.forms.sign.location = '';
    internship.message = '签到已提交';
    await loadInternship();
  } catch (error) {
    internship.message = error.message;
  } finally {
    internship.loading = false;
  }
}

async function submitJournal() {
  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipJournal({
      id: internship.forms.journal.id || undefined,
      arrangement_id: internship.forms.journal.arrangement_id || internship.forms.sign.arrangement_id,
      title: internship.forms.journal.title,
      content: internship.forms.journal.content,
      status: 'wait',
    });
    internship.forms.journal.id = null;
    internship.forms.journal.arrangement_id = null;
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
  internship.loading = true;
  internship.message = '';
  try {
    await saveInternshipReport({
      id: internship.forms.report.id || undefined,
      arrangement_id: internship.forms.report.arrangement_id || internship.forms.sign.arrangement_id,
      template_id: internship.options.report_templates[0]?.id || null,
      title: internship.forms.report.title,
      content: internship.forms.report.content,
      status: 'wait',
    });
    internship.forms.report.id = null;
    internship.forms.report.arrangement_id = null;
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
    internship.message = '请选择实习安排';
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
      entity_type: 'internship',
      entity_id: internship.forms.delay.arrangement_id,
      config_key: internship.forms.delay.config_key,
      requested_date: internship.forms.delay.requested_date,
      reason: internship.forms.delay.reason,
    });
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

function closeTimelineDialog() {
  internship.timelineDialog.visible = false;
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

function canReviewRow(row, entity) {
  if (!row || row.status !== 'wait') {
    return false;
  }
  if (entity === 'plan') {
    return canReviewInternshipPlan.value;
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

function canRequestModification(row, entity) {
  if (!row || row.status !== 'accept') {
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
  const arrangement = row.arrangement_title || (row.arrangement_id ? `安排ID ${row.arrangement_id}` : '');
  const base = [
    detailItem('审核模块', reviewEntityName(entity)),
    detailItem('学生', student || (row.student_id ? `学生ID ${row.student_id}` : '')),
    detailItem('实习安排', arrangement),
  ];

  const details = {
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
      return submissionSnapshotText(internship.timelineDialog.entity, internship.timelineDialog.row);
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

function signTypeText(value) {
  const names = {
    gps: '定位',
    qrcode: '扫码',
    manual: '补录',
  };
  return names[value] || value || '-';
}

function openStudentSubmitSection(section) {
  internship.submitSection = internship.submitSection === section ? '' : section;
}

function currentArrangement() {
  const arrangementId = Number(
    internship.forms.sign.arrangement_id
    || internship.forms.application.arrangement_id
    || internship.forms.delay.arrangement_id
    || 0,
  );
  return internship.options.arrangements.find(item => Number(item.id) === arrangementId) || internship.options.arrangements[0] || null;
}

function stageDeadlineText(key) {
  const configured = internship.options.deadline_configs?.[key] || '';
  const arrangementEnd = currentArrangement()?.end_date || '';
  const value = configured || arrangementEnd;
  return value ? `截止 ${value}` : '截止时间未配置';
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

function statusText(value) {
  const names = {
    draft: '草稿',
    wait: '待审核',
    accept: '已通过',
    modify: '需修改',
    refuse: '已退回',
    skipped: '跳过',
    enabled: '启用',
    disabled: '停用',
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
      type: 'all',
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

function messageTypeText(type) {
  return messageTypeNames[type] || type || '系统通知';
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

async function refreshMobilePage() {
  await load();
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
  await loadMobileSupportCategories();
  await loadMessageSummary();
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
    await load();
    await loadMessageSummary();
    await loadMobileSupportCategories();
    await loadInternship();
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
    activeTab.value = 'home';
    internship.panel = 'workbench';
    internship.message = '';
    resetMessageState();
    support.doc.items = [];
    support.template.items = [];
    support.doc.detail.visible = false;
    await load();
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
});

watch(visibleMobileModules, () => {
  if (!['home', 'mine', 'message'].includes(activeTab.value) && !visibleMobileModules.value.some(module => module.key === activeTab.value)) {
    activeTab.value = 'home';
  }
});

onMounted(async () => {
  await load();
  await loadMessageSummary();
  await loadMobileSupportCategories();
  await loadInternship();
});
</script>
