<template>
  <main class="mobile-shell">
    <header class="mobile-top">
      <div>
        <span>实践管理系统</span>
        <strong>{{ currentPage.title }}</strong>
      </div>
      <div class="mobile-actions">
        <button :disabled="state.loading" @click="load">
          <RefreshCw :size="18" />
        </button>
        <button v-if="isLoggedIn" :disabled="loginState.loading" @click="submitLogout">
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
            v-for="module in modules"
            :key="module.key"
            :disabled="!hasPermission(module.permission)"
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
        </van-cell-group>
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
          <section class="mobile-card form-card">
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

          <section class="mobile-card">
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

          <section class="mobile-card form-card">
            <header>
              <FileClock :size="20" />
              <strong>实习日志</strong>
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
              提交日志
            </van-button>
          </section>

          <section class="mobile-card form-card">
            <header>
              <FileText :size="20" />
              <strong>实习报告</strong>
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
              提交报告
            </van-button>
          </section>

          <section class="mobile-card">
            <header>
              <FileClock :size="20" />
              <strong>日志记录</strong>
            </header>
            <van-cell
              v-for="row in internship.lists.journals.items"
              :key="row.id"
              :title="row.title"
              :label="row.date || row.created_at || '-'"
              :value="statusText(row.status)"
            >
              <template #right-icon>
                <div class="cell-actions">
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

          <section class="mobile-card">
            <header>
              <FileText :size="20" />
              <strong>报告记录</strong>
            </header>
            <van-cell
              v-for="row in internship.lists.reports.items"
              :key="row.id"
              :title="row.title"
              :label="row.created_at || '-'"
              :value="statusText(row.status)"
            >
              <template #right-icon>
                <div class="cell-actions">
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
        </template>

        <template v-if="canReviewInternship && internship.panel === 'review'">
          <section class="mobile-card">
            <header>
              <ClipboardList :size="20" />
              <strong>实习申请</strong>
            </header>
            <section class="mobile-list-tools">
              <input v-model="internship.filters.applications.keyword" placeholder="学生、学号、实习安排" @keyup.enter="reloadInternshipList('applications')">
              <select v-model="internship.filters.applications.status" @change="reloadInternshipList('applications')">
                <option value="">全部状态</option>
                <option value="wait">待审核</option>
                <option value="accept">已通过</option>
                <option value="modify">需修改</option>
              </select>
              <button type="button" :disabled="internship.loading" @click="reloadInternshipList('applications')">查询</button>
            </section>
            <van-cell
              v-for="row in internship.lists.applications.items"
              :key="row.id"
              :title="row.student_name || row.student_num"
              :label="row.arrangement_title"
              :value="statusText(row.status)"
            >
              <template #right-icon>
                <div class="cell-actions">
                  <button @click.stop="openTimelineDialog('application', row)">记录</button>
                  <button v-if="canReviewRow(row)" @click.stop="openReviewDialog('application', row, 'accept')">通过</button>
                  <button v-if="canReviewRow(row)" @click.stop="openReviewDialog('application', row, 'modify')">退回</button>
                  <button v-if="row.status === 'accept'" @click.stop="openReopenDialog('application', row)">通过后修改</button>
                </div>
              </template>
            </van-cell>
            <div v-if="!internship.lists.applications.items.length" class="mobile-empty">暂无实习申请</div>
            <div class="mobile-list-footer">
              <span>共 {{ internship.lists.applications.pagination.total || 0 }} 条</span>
              <button v-if="canLoadMore('applications')" :disabled="internship.loading" @click="loadMoreInternshipList('applications')">
                加载更多
              </button>
            </div>
          </section>

          <section v-if="isTeacherRole" class="mobile-card">
            <header>
              <FileClock :size="20" />
              <strong>日志评阅</strong>
            </header>
            <section class="mobile-list-tools">
              <input v-model="internship.filters.journals.keyword" placeholder="学生、标题、内容" @keyup.enter="reloadInternshipList('journals')">
              <select v-model="internship.filters.journals.status" @change="reloadInternshipList('journals')">
                <option value="">全部状态</option>
                <option value="wait">待审核</option>
                <option value="accept">已通过</option>
                <option value="modify">需修改</option>
              </select>
              <button type="button" :disabled="internship.loading" @click="reloadInternshipList('journals')">查询</button>
            </section>
            <van-cell
              v-for="row in internship.lists.journals.items"
              :key="row.id"
              :title="row.title"
              :label="`${row.student_name || '-'} / ${row.date || '-'}`"
              :value="statusText(row.status)"
            >
              <template #right-icon>
                <div class="cell-actions">
                  <button @click.stop="openTimelineDialog('journal', row)">记录</button>
                  <button v-if="canReviewRow(row)" @click.stop="openReviewDialog('journal', row, 'accept')">通过</button>
                  <button v-if="canReviewRow(row)" @click.stop="openReviewDialog('journal', row, 'modify')">退回</button>
                  <button v-if="row.status === 'accept'" @click.stop="openReopenDialog('journal', row)">通过后修改</button>
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

          <section v-if="isTeacherRole" class="mobile-card">
            <header>
              <FileText :size="20" />
              <strong>报告评阅</strong>
            </header>
            <section class="mobile-list-tools">
              <input v-model="internship.filters.reports.keyword" placeholder="学生、标题、内容" @keyup.enter="reloadInternshipList('reports')">
              <select v-model="internship.filters.reports.status" @change="reloadInternshipList('reports')">
                <option value="">全部状态</option>
                <option value="wait">待审核</option>
                <option value="accept">已通过</option>
                <option value="modify">需修改</option>
              </select>
              <button type="button" :disabled="internship.loading" @click="reloadInternshipList('reports')">查询</button>
            </section>
            <van-cell
              v-for="row in internship.lists.reports.items"
              :key="row.id"
              :title="row.title"
              :label="row.student_name || '-'"
              :value="statusText(row.status)"
            >
              <template #right-icon>
                <div class="cell-actions">
                  <button @click.stop="openTimelineDialog('report', row)">记录</button>
                  <button v-if="canReviewRow(row)" @click.stop="openReviewDialog('report', row, 'accept')">通过</button>
                  <button v-if="canReviewRow(row)" @click.stop="openReviewDialog('report', row, 'modify')">退回</button>
                  <button v-if="row.status === 'accept'" @click.stop="openReopenDialog('report', row)">通过后修改</button>
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
            <section class="mobile-list-tools">
              <input v-model="internship.filters.scores.keyword" placeholder="学生、学号、安排" @keyup.enter="reloadInternshipList('scores')">
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
          <section class="mobile-card">
            <header>
              <CalendarCheck :size="20" />
              <strong>实习安排</strong>
            </header>
            <section class="mobile-list-tools">
              <input v-model="internship.filters.arrangements.keyword" placeholder="安排、学院、专业" @keyup.enter="reloadInternshipList('arrangements')">
              <select v-model="internship.filters.arrangements.status" @change="reloadInternshipList('arrangements')">
                <option value="">全部状态</option>
                <option value="enabled">启用</option>
                <option value="disabled">停用</option>
              </select>
              <button type="button" :disabled="internship.loading" @click="reloadInternshipList('arrangements')">查询</button>
            </section>
            <van-cell
              v-for="row in internship.lists.arrangements.items"
              :key="row.id"
              :title="row.title"
              :label="`${row.semester || '-'} / ${row.dep_name || '全校'}`"
              :value="statusText(row.status)"
            />
            <div v-if="!internship.lists.arrangements.items.length" class="mobile-empty">暂无实习安排</div>
            <div class="mobile-list-footer">
              <span>共 {{ internship.lists.arrangements.pagination.total || 0 }} 条</span>
              <button v-if="canLoadMore('arrangements')" :disabled="internship.loading" @click="loadMoreInternshipList('arrangements')">
                加载更多
              </button>
            </div>
          </section>
          <section class="mobile-card">
            <header>
              <UsersRound :size="20" />
              <strong>指导关系</strong>
            </header>
            <section class="mobile-list-tools">
              <input v-model="internship.filters.pairs.keyword" placeholder="学生、学号、教师、安排" @keyup.enter="reloadInternshipList('pairs')">
              <select v-model="internship.filters.pairs.status" @change="reloadInternshipList('pairs')">
                <option value="">全部状态</option>
                <option value="active">有效</option>
                <option value="removed">已移除</option>
              </select>
              <button type="button" :disabled="internship.loading" @click="reloadInternshipList('pairs')">查询</button>
            </section>
            <van-cell
              v-for="row in internship.lists.pairs.items"
              :key="row.id"
              :title="row.student_name"
              :label="`${row.teacher_name || '-'} / ${row.arrangement_title || '-'}`"
              :value="statusText(row.status)"
            />
            <div v-if="!internship.lists.pairs.items.length" class="mobile-empty">暂无指导关系</div>
            <div class="mobile-list-footer">
              <span>共 {{ internship.lists.pairs.pagination.total || 0 }} 条</span>
              <button v-if="canLoadMore('pairs')" :disabled="internship.loading" @click="loadMoreInternshipList('pairs')">
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

        <section class="action-grid">
          <van-button
            v-for="action in moduleActions"
            :key="action.code"
            block
            :disabled="!hasPermission(action.code)"
          >
            <component :is="action.icon" :size="18" />
            <span>{{ action.name }}</span>
          </van-button>
        </section>

        <van-cell-group inset>
          <van-cell title="模块权限" :value="hasPermission(currentPage.permission) ? '允许访问' : '无权限'" />
          <van-cell title="业务流程" :value="currentPage.flow" />
          <van-cell title="数据来源" value="学校数据" />
          <van-cell title="范围策略" :value="scopeText" />
        </van-cell-group>
      </template>
    </section>

    <van-tabbar v-model="activeTab" safe-area-inset-bottom>
      <van-tabbar-item name="home">
        <template #icon><Home :size="20" /></template>
        首页
      </van-tabbar-item>
      <van-tabbar-item name="internship">
        <template #icon><BriefcaseBusiness :size="20" /></template>
        实习
      </van-tabbar-item>
      <van-tabbar-item name="training">
        <template #icon><Workflow :size="20" /></template>
        实训
      </van-tabbar-item>
      <van-tabbar-item name="lab">
        <template #icon><FlaskConical :size="20" /></template>
        实验
      </van-tabbar-item>
      <van-tabbar-item name="mine">
        <template #icon><UserRound :size="20" /></template>
        我的
      </van-tabbar-item>
    </van-tabbar>

    <van-popup
      v-model:show="internship.reviewDialog.visible"
      round
      position="bottom"
      safe-area-inset-bottom
    >
      <section class="review-sheet">
        <header>
          <strong>{{ reviewDialogTitle }}</strong>
          <p v-if="reviewDialogTargetText">{{ reviewDialogTargetText }}</p>
          <span>{{ reviewDialogRuleText }}</span>
        </header>
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
          <template v-else-if="internship.timelineDialog.items.length">
            <section
              v-for="(item, index) in internship.timelineDialog.items"
              :key="timelineItemKey(item, index)"
              class="timeline-entry"
            >
              <span />
              <div>
                <strong>{{ timelineTitle(item) }}</strong>
                <small>{{ timelineTime(item) }}</small>
                <p>{{ timelineContent(item) }}</p>
                <p v-for="review in item.reviews || []" :key="review.id" class="timeline-review">
                  审核意见：{{ review.opinion || '-' }}<template v-if="review.score !== null && review.score !== undefined">，评分：{{ review.score }}</template>
                </p>
                <p v-if="item.review" class="timeline-review">
                  审核意见：{{ item.review.opinion || '-' }}<template v-if="item.review.score !== null && item.review.score !== undefined">，评分：{{ item.review.score }}</template>
                </p>
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
  RefreshCw,
  Send,
  UsersRound,
  UserRound,
  Workflow,
} from '@lucide/vue';
import { useMobilePermissions } from './composables/useMobilePermissions';
import {
  fetchInternshipApplications,
  fetchInternshipArrangements,
  fetchInternshipJournals,
  fetchInternshipOptions,
  fetchInternshipOverview,
  fetchInternshipPairs,
  fetchInternshipReports,
  fetchInternshipScores,
  fetchInternshipSignIns,
  fetchInternshipTimeline,
  requestInternshipModification,
  reviewInternshipApplication,
  reviewInternshipJournal,
  reviewInternshipReport,
  saveInternshipApplication,
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
};

const internship = reactive({
  loading: false,
  message: '',
  panel: 'workbench',
  overview: emptyInternshipOverview(),
  options: emptyInternshipOptions(),
  lists: {
    arrangements: emptyPagedList(),
    applications: emptyPagedList(),
    pairs: emptyPagedList(),
    signIns: emptyPagedList(),
    journals: emptyPagedList(),
    reports: emptyPagedList(),
    scores: emptyPagedList(),
  },
  filters: {
    arrangements: emptyInternshipFilters(),
    applications: emptyInternshipFilters(),
    pairs: emptyInternshipFilters(),
    signIns: emptyInternshipFilters(),
    journals: emptyInternshipFilters(),
    reports: emptyInternshipFilters(),
    scores: emptyInternshipFilters(),
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
      title: '',
      content: '',
    },
    report: {
      title: '',
      content: '',
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
    title: '',
    subtitle: '',
    items: [],
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
    desc: '保留模块框架，流程待确认',
    icon: Workflow,
    theme: 'teal',
    permission: 'training:view',
    flow: '待确认',
  },
  {
    key: 'lab',
    title: '实验管理',
    desc: '保留模块框架，流程待确认',
    icon: FlaskConical,
    theme: 'green',
    permission: 'lab:view',
    flow: '待确认',
  },
];

const currentPage = computed(() => {
  if (activeTab.value === 'home') {
    return { title: '首页', desc: '移动端工作台', theme: 'blue', icon: Home, permission: '', flow: '-' };
  }
  if (activeTab.value === 'mine') {
    return { title: '我的', desc: '个人信息', theme: 'gray', icon: UserRound, permission: '', flow: '-' };
  }
  return modules.find(item => item.key === activeTab.value) || modules[0];
});

const summaries = computed(() => [
  { name: '学校', value: schoolShortText.value },
  { name: '角色', value: roleDisplayText.value },
  { name: '范围', value: scopeText.value },
]);

const moduleActions = computed(() => [
  { name: '查看', code: currentPage.value.permission, icon: ClipboardList },
  { name: '提交', code: `${currentPage.value.key}:submit`, icon: Send },
  { name: '确认', code: `${currentPage.value.key}:confirm`, icon: CheckCircle2 },
]);

const isLoggedIn = computed(() => Boolean(state.context.account_id));
const roleType = computed(() => state.context.role_type || '');
const isStudentRole = computed(() => roleType.value === 'student');
const isTeacherRole = computed(() => roleType.value === 'teacher');
const isAdminRole = computed(() => ['super_admin', 'school_admin', 'college_admin', 'profession_admin'].includes(roleType.value));
const canReviewInternship = computed(() => hasPermission('internship:approve'));
const roleNameMap = {
  super_admin: '系统管理员',
  school_admin: '学校管理员',
  college_admin: '学院管理员',
  profession_admin: '专业管理员',
  teacher: '指导老师',
  student: '学生',
  enterprise: '企业导师',
};
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
const internshipSummaries = computed(() => [
  { name: '安排', value: internship.overview.arrangements || 0 },
  { name: '待审', value: internship.overview.applications_waiting || 0 },
  { name: '关系', value: internship.overview.active_pairs || 0 },
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
    teachers: [],
    report_templates: [],
    review_rules: defaultInternshipReviewRules,
  };
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
    applications: fetchInternshipApplications,
    pairs: fetchInternshipPairs,
    signIns: fetchInternshipSignIns,
    journals: fetchInternshipJournals,
    reports: fetchInternshipReports,
    scores: fetchInternshipScores,
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
    ]);
    return;
  }
  if (internship.panel === 'review') {
    await Promise.all([
      loadInternshipList('applications'),
      loadInternshipList('journals'),
      loadInternshipList('reports'),
    ]);
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
    await Promise.all([
      loadInternshipList('arrangements'),
      loadInternshipList('applications'),
      loadInternshipList('pairs'),
    ]);
  }
}

function switchInternshipPanel(panel) {
  internship.panel = panel;
  loadInternship();
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
      arrangement_id: internship.forms.sign.arrangement_id,
      title: internship.forms.journal.title,
      content: internship.forms.journal.content,
      status: 'wait',
    });
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
      arrangement_id: internship.forms.sign.arrangement_id,
      template_id: internship.options.report_templates[0]?.id || null,
      title: internship.forms.report.title,
      content: internship.forms.report.content,
      status: 'wait',
    });
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

function openReviewDialog(entity, row, status) {
  internship.reviewDialog.mode = 'review';
  internship.reviewDialog.entity = entity;
  internship.reviewDialog.status = status;
  internship.reviewDialog.row = row;
  internship.reviewDialog.reason = status === 'accept' ? defaultReviewOpinion(entity, status) : '';
  trimReviewDialogMax();
  internship.reviewDialog.visible = true;
}

function openReopenDialog(entity, row) {
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
  internship.timelineDialog.title = `${reviewEntityName(entity)}流程记录`;
  internship.timelineDialog.subtitle = row.title || row.arrangement_title || row.student_name || String(row.id);
  internship.timelineDialog.items = [];
  internship.timelineDialog.message = '';
  try {
    const data = await fetchInternshipTimeline({ entity, id: row.id });
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
    await requestModification(entity, row, internship.reviewDialog.reason);
    return;
  }

  if (entity === 'application') {
    await reviewApplication(row, status, internship.reviewDialog.reason);
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
  };
  return names[entity] || '审核事项';
}

function canReviewRow(row) {
  return row?.status === 'wait';
}

function reviewTargetText(row) {
  if (!row) {
    return '';
  }
  const parts = [
    row.student_name || row.student_num || '',
    row.arrangement_title || row.title || '',
    row.date || '',
    `状态：${statusText(row.status)}`,
  ].filter(Boolean);
  return parts.join(' / ');
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

function timelineTitle(item) {
  if (item.record) {
    return `${workflowActionText(item.record.action)}：${statusText(item.record.from_status)} -> ${statusText(item.record.to_status)}`;
  }
  return `审核：${statusText(item.review?.status)}`;
}

function timelineContent(item) {
  if (item.record) {
    return item.record.content || item.record.opinion || '-';
  }
  return item.review?.opinion || '-';
}

function timelineTime(item) {
  return item.created_at || item.record?.created_at || item.review?.created_at || '-';
}

function timelineItemKey(item, index) {
  return `${item.kind || 'timeline'}-${item.record?.id || item.review?.id || index}`;
}

const reviewDialogTitle = computed(() => {
  if (internship.reviewDialog.mode === 'reopen') {
    return `通过后修改${reviewEntityName(internship.reviewDialog.entity)}`;
  }
  const action = internship.reviewDialog.status === 'modify' ? '退回' : '通过';
  return `${action}${reviewEntityName(internship.reviewDialog.entity)}`;
});

const reviewDialogTargetText = computed(() => reviewTargetText(internship.reviewDialog.row));

const reviewDialogReasonLabel = computed(() => {
  if (internship.reviewDialog.mode === 'reopen') {
    return '修改理由';
  }
  return internship.reviewDialog.status === 'modify' ? '退回原因' : '审核意见';
});

const reviewDialogRuleText = computed(() => (
  reviewRuleText(internship.reviewDialog.entity, internship.reviewDialog.status, reviewDialogReasonLabel.value)
));

const reviewDialogConfirmText = computed(() => {
  if (internship.reviewDialog.mode === 'reopen') {
    return '确认修改';
  }
  return internship.reviewDialog.status === 'modify' ? '确认退回' : '确认通过';
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
  const fieldLabel = label || (status === 'modify' ? '退回原因' : '审核意见');
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
    enabled: '启用',
    disabled: '停用',
    pending: '待处理',
    active: '有效',
    removed: '已移除',
    signed: '已签署',
  };
  return names[value] || value || '-';
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
});

watch(roleType, () => {
  const panels = internshipPanels.value.map(item => item.key);
  if (!panels.includes(internship.panel)) {
    internship.panel = panels[0] || 'workbench';
  }
});

onMounted(async () => {
  await load();
  await loadInternship();
});
</script>
