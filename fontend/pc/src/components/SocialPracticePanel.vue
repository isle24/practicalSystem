<template>
  <section class="social-practice-panel">
    <el-alert
      v-if="state.message"
      :title="state.message"
      :type="state.error ? 'error' : 'success'"
      :closable="false"
      show-icon
    />

    <template v-if="panel === 'overview'">
      <header class="social-panel-head">
        <div>
          <h2>{{ panel === 'overview' ? '社会实践总览' : '社会实践统计' }}</h2>
          <p>按当前账号的数据范围汇总计划、参与、材料、成绩和归档进度。</p>
        </div>
        <el-button :icon="RefreshCw" :loading="state.loading" @click="loadOverview">刷新</el-button>
      </header>
      <div class="social-overview-grid">
        <article v-for="item in overviewCards" :key="item.key">
          <span class="social-overview-icon" :class="item.tone"><component :is="item.icon" :size="20" /></span>
          <div><small>{{ item.label }}</small><strong>{{ state.overview[item.key] || 0 }}</strong></div>
        </article>
      </div>
      <section class="social-flow-band">
        <header><Workflow :size="18" /><strong>业务主线</strong></header>
        <div>
          <span>计划发布</span><ChevronRight :size="15" />
          <span>集中分配 / 分散申报</span><ChevronRight :size="15" />
          <span>安全确认与实施</span><ChevronRight :size="15" />
          <span>成果提交与评分</span><ChevronRight :size="15" />
          <span>归档</span>
        </div>
      </section>
    </template>

    <template v-else-if="panel === 'statistics'">
      <header class="social-panel-head compact">
        <div><h2>社会实践统计</h2><p>按当前账号的数据范围统计计划、项目、参与、材料和归档。</p></div>
        <div class="social-panel-actions">
          <el-button v-if="can('social_practice:export')" :icon="Download" :loading="state.saving" @click="exportStatistics">导出</el-button>
          <el-button :icon="RefreshCw" :loading="state.loading" @click="loadStatistics">刷新</el-button>
        </div>
      </header>
      <div class="social-stat-filters">
        <el-select v-model="state.filters.grade_id" clearable filterable placeholder="请选择年级"><el-option v-for="item in state.options.grades" :key="item.grade_id" :label="item.grade_name" :value="item.grade_id" /></el-select>
        <el-select v-model="state.filters.dep_id" clearable filterable placeholder="请选择学院"><el-option v-for="item in state.options.departments" :key="item.dep_id" :label="item.dep_name" :value="item.dep_id" /></el-select>
        <el-button type="primary" @click="loadStatistics">查询</el-button>
      </div>
      <div class="social-overview-grid social-stat-summary"><article v-for="item in statisticCards" :key="item.key"><span class="social-overview-icon" :class="item.tone"><component :is="item.icon" :size="20" /></span><div><small>{{ item.label }}</small><strong>{{ state.statistics.summary?.[item.key] || 0 }}</strong></div></article></div>
      <div class="social-stat-grid">
        <section class="social-stat-card"><header><strong>按年级</strong><small>计划数量</small></header><el-table :data="state.statistics.by_grade || []" size="small" height="250"><el-table-column type="index" label="序号" width="66" /><el-table-column prop="grade_name" label="年级" /><el-table-column prop="plan_count" label="计划数" width="100" /></el-table></section>
        <section class="social-stat-card"><header><strong>实践模式</strong><small>项目数量</small></header><el-table :data="state.statistics.by_mode || []" size="small" height="250"><el-table-column type="index" label="序号" width="66" /><el-table-column prop="practice_mode" label="模式"><template #default="{ row }">{{ modeText(row.practice_mode) }}</template></el-table-column><el-table-column prop="project_count" label="项目数" width="100" /><el-table-column prop="plan_count" label="计划数" width="100" /></el-table></section>
        <section class="social-stat-card social-stat-card-wide"><header><strong>学院参与情况</strong><small>学生参与与项目分配</small></header><el-table :data="state.statistics.by_college || []" size="small" height="280"><el-table-column type="index" label="序号" width="66" /><el-table-column prop="dep_name" label="学院" min-width="180" /><el-table-column prop="student_count" label="学生数" width="100" /><el-table-column prop="assigned_count" label="已分配/已参与" width="130" /></el-table></section>
      </div>
    </template>

    <template v-else>
      <header class="social-panel-head compact">
        <div>
          <h2>{{ panelConfig.title }}</h2>
          <p>{{ panelConfig.description }}</p>
        </div>
        <div class="social-panel-actions">
          <el-button v-if="panelResource === 'plan' && canCreate" :icon="Download" @click="downloadPlanImportTemplate">下载模板</el-button>
          <el-button v-if="panelResource === 'plan' && canCreate" :icon="Upload" @click="openPlanImport">Excel 导入</el-button>
          <el-button v-if="canCreate" type="primary" :icon="Plus" @click="openCreate">{{ createLabel }}</el-button>
          <el-button :icon="RefreshCw" :loading="state.loading" @click="loadList(state.pagination.page)">刷新</el-button>
        </div>
      </header>

      <div v-if="isStudent" class="social-student-list" v-loading="state.loading">
        <article v-for="row in state.rows" :key="row.id" class="social-student-card">
          <header>
            <div><small>{{ row.grade_name || row.plan_title || panelConfig.title }}</small><strong>{{ rowTitle(row) }}</strong></div>
            <el-tag :type="statusTagType(row.status)">{{ statusText(row.status) }}</el-tag>
          </header>
          <dl>
            <template v-for="fact in rowFacts(row)" :key="fact.label">
              <dt>{{ fact.label }}</dt><dd>{{ fact.value || '-' }}</dd>
            </template>
          </dl>
          <footer>
            <el-button v-for="action in rowActions(row)" :key="action.key" size="small" :type="action.type" @click="handleRowAction({ action: action.key, row })">
              {{ action.label }}
            </el-button>
          </footer>
        </article>
        <el-empty v-if="!state.rows.length && !state.loading" description="暂无相关记录" />
        <div class="social-student-pagination">
          <span>共 {{ state.pagination.total }} 条</span>
          <el-pagination
            size="small"
            layout="prev, pager, next"
            :current-page="state.pagination.page"
            :page-size="state.pagination.page_size"
            :total="state.pagination.total"
            @current-change="loadList"
          />
        </div>
      </div>

      <DataListPanel
        v-else
        :actions="tableActions"
        :action-handler="handleRowAction"
        :columns="panelConfig.columns"
        :filters="listFilters"
        :filter-values="state.filters"
        :loading="state.loading"
        :pagination="state.pagination"
        :rows="state.rows"
        :storage-key="`practical:pc:columns:${accountId}:socialPractice:${panel}`"
        @filter-change="setFilter"
        @page-change="loadList"
        @reset="resetFilters"
        @search="loadList(1)"
      />
    </template>

    <OperationDialog
      :visible="state.dialog.type === 'form'"
      :title="state.dialog.title"
      :busy="state.saving"
      dialog-class="social-practice-operation-dialog"
      @close="closeDialog"
    >
      <form class="social-practice-form" @submit.prevent>
        <template v-if="state.form.resource === 'plan'">
          <label class="span-2"><span>计划名称</span><input v-model="state.form.title" maxlength="180" placeholder="请输入社会实践计划名称"></label>
          <label><span>年级</span><el-select v-model="state.form.grade_id" filterable placeholder="请选择年级"><el-option v-for="item in state.options.grades" :key="item.grade_id" :label="item.grade_name" :value="item.grade_id" /></el-select></label>
          <label><span>组织学院</span><el-select v-model="state.form.organizer_dep_id" clearable filterable placeholder="请选择学院"><el-option v-for="item in state.options.departments" :key="item.dep_id" :label="item.dep_name" :value="item.dep_id" /></el-select></label>
          <label><span>审批流程</span><el-select v-model="state.form.approval_flow_id" filterable placeholder="请选择审批流程"><el-option v-for="item in state.options.approval_flows" :key="item.id" :label="item.name" :value="item.id" /></el-select></label>
          <label><span>学分</span><el-input-number v-model="state.form.credit" :min="0" :max="99" :precision="1" /></label>
          <label><span>参与方式</span><el-select v-model="state.form.participation_mode" placeholder="请选择参与方式"><el-option label="必修" value="mandatory" /><el-option label="自愿" value="voluntary" /></el-select></label>
          <label><span>教师匹配</span><el-select v-model="state.form.teacher_match_mode" placeholder="请选择教师匹配方式"><el-option label="学生选择" value="student_choose" /><el-option label="管理员分配" value="admin_assign" /><el-option label="混合匹配" value="mixed" /></el-select></label>
          <label><span>团队提交</span><el-select v-model="state.form.default_team_submit_mode" placeholder="请选择团队提交方式"><el-option label="分别提交" value="individual" /><el-option label="共同提交" value="shared" /></el-select></label>
          <label><span>教师确认时限（小时）</span><el-input-number v-model="state.form.teacher_confirm_hours" :min="1" :max="720" /></label>
          <label><span>可重选教师次数</span><el-input-number v-model="state.form.max_reselect_count" :min="0" :max="20" /></label>
          <label><span>报名开始</span><input v-model="state.form.register_start_at" type="datetime-local"></label>
          <label><span>报名截止</span><input v-model="state.form.register_end_at" type="datetime-local"></label>
          <label><span>实践开始</span><input v-model="state.form.practice_start_at" type="datetime-local"></label>
          <label><span>实践结束</span><input v-model="state.form.practice_end_at" type="datetime-local"></label>
          <label><span>成果截止</span><input v-model="state.form.result_deadline_at" type="datetime-local"></label>
          <label><span>成绩截止</span><input v-model="state.form.score_deadline_at" type="datetime-local"></label>
          <label class="span-2"><span>计划说明</span><textarea v-model="state.form.description" rows="4" maxlength="100000" placeholder="请输入目标、范围和执行要求" /></label>
          <section class="span-2 social-inline-editor">
            <header><strong>适用范围</strong><el-button link type="primary" :icon="Plus" @click="addScope">添加范围</el-button></header>
            <div v-for="(scope, index) in state.form.scopes" :key="index" class="social-inline-row scope-row">
              <el-select v-model="scope.scope_type" placeholder="请选择范围类型"><el-option label="全校" value="school" /><el-option label="学院" value="college" /><el-option label="专业" value="profession" /><el-option label="班级" value="class" /></el-select>
              <el-select v-if="scope.scope_type !== 'school'" v-model="scope.dep_id" clearable filterable placeholder="请选择学院"><el-option v-for="item in state.options.departments" :key="item.dep_id" :label="item.dep_name" :value="item.dep_id" /></el-select>
              <el-select v-if="['profession', 'class'].includes(scope.scope_type)" v-model="scope.profession_id" clearable filterable placeholder="请选择专业"><el-option v-for="item in professionsFor(scope.dep_id)" :key="item.profession_id" :label="item.profession_name" :value="item.profession_id" /></el-select>
              <el-select v-if="scope.scope_type === 'class'" v-model="scope.class_id" clearable filterable placeholder="请选择班级"><el-option v-for="item in classesFor(scope.profession_id)" :key="item.class_id" :label="item.class_name" :value="item.class_id" /></el-select>
              <el-button link type="danger" :disabled="state.form.scopes.length === 1" @click="removeArrayItem('scopes', index)">移除</el-button>
            </div>
          </section>
          <section class="span-2 social-inline-editor">
            <header><strong>材料要求</strong><el-button link type="primary" :icon="Plus" @click="addRequirement">添加材料</el-button></header>
            <div v-for="(item, index) in state.form.requirements" :key="index" class="social-inline-row material-row">
              <el-select v-model="item.practice_mode" placeholder="请选择实践模式"><el-option label="全部模式" value="all" /><el-option label="集中实践" value="centralized" /><el-option label="分散实践" value="distributed" /></el-select>
              <el-select v-model="item.requirement_type" filterable allow-create default-first-option placeholder="请选择材料类型"><el-option v-for="option in materialTypeOptions" :key="option.value" :label="option.label" :value="option.value" /></el-select>
              <el-select v-model="item.submit_scope" placeholder="请选择提交范围"><el-option label="学生" value="student" /><el-option label="团队" value="team" /><el-option label="项目" value="project" /></el-select>
              <el-switch v-model="item.required_flag" :disabled="item.requirement_type === 'parent_notice'" inline-prompt active-text="必交" inactive-text="选交" />
              <el-button link type="danger" @click="removeArrayItem('requirements', index)">移除</el-button>
            </div>
          </section>
          <section class="span-2 social-inline-editor">
            <header><strong>成绩规则</strong><el-button link type="primary" :icon="Plus" @click="addScoreRule">添加评分项</el-button></header>
            <div v-for="(item, index) in state.form.score_rules" :key="index" class="social-inline-row score-row">
              <el-select v-model="item.practice_mode" placeholder="请选择实践模式"><el-option label="集中实践" value="centralized" /><el-option label="分散实践" value="distributed" /></el-select>
              <input v-model="item.item_code" placeholder="评分项编码">
              <input v-model="item.item_name" placeholder="评分项名称">
              <el-input-number v-model="item.weight" :min="0" :max="100" :precision="1" />
              <el-button link type="danger" @click="removeArrayItem('score_rules', index)">移除</el-button>
            </div>
          </section>
        </template>

        <template v-else-if="state.form.resource === 'project'">
          <label><span>所属计划</span><el-select v-model="state.form.plan_id" filterable placeholder="请选择计划" @change="loadPlanStudents"><el-option v-for="item in state.options.plans" :key="item.id" :label="item.title" :value="item.id" /></el-select></label>
          <label><span>项目编号</span><input v-model="state.form.project_code" maxlength="120" placeholder="不填则自动生成"></label>
          <label class="span-2"><span>项目名称</span><input v-model="state.form.title" maxlength="180" placeholder="请输入集中实践项目名称"></label>
          <label><span>开始时间</span><input v-model="state.form.start_at" type="datetime-local"></label>
          <label><span>结束时间</span><input v-model="state.form.end_at" type="datetime-local"></label>
          <label><span>地点</span><input v-model="state.form.location" maxlength="255" placeholder="请输入实践地点"></label>
          <label><span>容量</span><el-input-number v-model="state.form.capacity" :min="0" :max="100000" /></label>
          <label class="span-2"><span>实践目标</span><textarea v-model="state.form.objective" rows="3" maxlength="10000" /></label>
          <label class="span-2"><span>实践内容</span><textarea v-model="state.form.content" rows="5" maxlength="100000" /></label>
        </template>

        <template v-else-if="state.form.resource === 'implementation'">
          <label class="span-2"><span>集中实践项目</span><el-select v-model="state.form.project_id" filterable placeholder="请选择项目"><el-option v-for="item in centralizedProjects" :key="item.id" :label="item.title" :value="item.id" /></el-select></label>
          <label class="span-2"><span>实施申请名称</span><input v-model="state.form.title" maxlength="180" placeholder="请输入实施申请名称"></label>
          <label><span>预算金额</span><el-input-number v-model="state.form.budget_amount" :min="0" :max="99999999" :precision="2" /></label>
          <label><span>场地要求</span><input v-model="state.form.venue_requirement" maxlength="10000"></label>
          <label class="span-2"><span>材料要求</span><textarea v-model="state.form.material_requirement" rows="3" maxlength="10000" /></label>
          <label class="span-2"><span>实施内容</span><textarea v-model="state.form.content" rows="6" maxlength="100000" /></label>
        </template>

        <template v-else-if="state.form.resource === 'declaration'">
          <label><span>所属计划</span><el-select v-model="state.form.plan_id" filterable placeholder="请选择计划"><el-option v-for="item in state.options.plans" :key="item.id" :label="item.title" :value="item.id" /></el-select></label>
          <label><span>申报类型</span><el-select v-model="state.form.declaration_type" placeholder="请选择申报类型"><el-option label="个人申报" value="individual" /><el-option label="团队申报" value="team" /></el-select></label>
          <label v-if="state.form.declaration_type === 'team'"><span>团队提交</span><el-select v-model="state.form.team_submit_mode" placeholder="请选择团队提交方式"><el-option label="分别提交" value="individual" /><el-option label="共同提交" value="shared" /></el-select></label>
          <label><span>指导教师</span><el-select v-model="state.form.selected_teacher_id" filterable placeholder="请选择指导教师"><el-option v-for="item in state.options.teachers" :key="item.teacher_id" :label="`${item.teacher_name} / ${item.teacher_num || '-'}`" :value="item.teacher_id" /></el-select></label>
          <label v-if="state.form.declaration_type === 'team'" class="span-2"><span>团队成员</span><el-select v-model="state.form.member_student_ids" multiple filterable collapse-tags placeholder="请选择团队成员"><el-option v-for="item in state.planStudents" :key="item.student_id" :label="`${item.name} / ${item.student_num}`" :value="item.student_id" /></el-select></label>
          <label class="span-2"><span>申报名称</span><input v-model="state.form.title" maxlength="180"></label>
          <label><span>预计时长</span><input v-model="state.form.expected_duration" maxlength="120" placeholder="例如：5天"></label>
          <label><span>实践地点</span><input v-model="state.form.location" maxlength="255"></label>
          <label class="span-2"><span>实践目标</span><textarea v-model="state.form.objective" rows="3" maxlength="10000" /></label>
          <label class="span-2"><span>预期成果</span><textarea v-model="state.form.expected_result" rows="3" maxlength="10000" /></label>
          <label class="span-2"><span>申报内容</span><textarea v-model="state.form.content" rows="5" maxlength="100000" /></label>
        </template>

        <template v-else-if="state.form.resource === 'material'">
          <label><span>所属计划</span><el-select v-model="state.form.plan_id" filterable placeholder="请选择所属计划" @change="state.form.project_id = null"><el-option v-for="item in state.options.plans" :key="item.id" :label="item.title" :value="item.id" /></el-select></label>
          <label><span>实践项目</span><el-select v-model="state.form.project_id" filterable placeholder="请选择实践项目"><el-option v-for="item in projectsForPlan(state.form.plan_id)" :key="item.id" :label="item.title" :value="item.id" /></el-select></label>
          <label v-if="!isStudent"><span>学生</span><el-select v-model="state.form.student_id" filterable placeholder="请选择学生"><el-option v-for="item in state.options.students" :key="item.student_id" :label="`${item.name} / ${item.student_num}`" :value="item.student_id" /></el-select></label>
          <label><span>材料类型</span><el-select v-model="state.form.material_type" filterable allow-create placeholder="请选择材料类型"><el-option v-for="option in materialTypeOptions" :key="option.value" :label="option.label" :value="option.value" /></el-select></label>
          <label><span>提交范围</span><el-select v-model="state.form.submit_scope" placeholder="请选择提交范围"><el-option label="学生" value="student" /><el-option label="团队" value="team" /><el-option label="项目" value="project" /></el-select></label>
          <label class="span-2"><span>材料标题</span><input v-model="state.form.title" maxlength="180"></label>
          <label class="span-2"><span>材料内容</span><textarea v-model="state.form.content" rows="8" maxlength="500000" /></label>
          <section class="span-2 social-material-files">
            <header><strong>附件</strong><el-button :icon="Upload" :loading="state.uploading" @click="materialFileInput?.click()">上传附件</el-button></header>
            <input ref="materialFileInput" type="file" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt" @change="uploadMaterialFiles">
            <div v-if="state.form.attachments.length" class="social-material-file-list">
              <article v-for="file in state.form.attachments" :key="file.id || file.file_id">
                <a :href="backendUrl(file.url)" target="_blank" rel="noopener noreferrer">{{ file.download_name || file.name || `附件 #${file.id || file.file_id}` }}</a>
                <FilePreviewButton :file="file" />
                <el-button link type="danger" @click="removeMaterialFile(file)">移除</el-button>
              </article>
            </div>
            <small v-else>暂无附件</small>
          </section>
        </template>

        <template v-else-if="state.form.resource === 'patch_sign'">
          <label class="span-2"><span>集中实践项目</span><el-select v-model="state.form.project_id" filterable placeholder="请选择集中实践项目"><el-option v-for="item in centralizedProjects" :key="item.id" :label="item.title" :value="item.id" /></el-select></label>
          <label><span>补签日期</span><input v-model="state.form.sign_date" type="date"></label>
          <label class="span-2"><span>补签原因</span><textarea v-model="state.form.reason" rows="4" maxlength="10000" /></label>
          <label class="span-2"><span>证明说明</span><textarea v-model="state.form.proof" rows="4" maxlength="100000" /></label>
        </template>

        <template v-else-if="state.form.resource === 'score'">
          <label><span>实践项目</span><el-select v-model="state.form.project_id" filterable placeholder="请选择实践项目" @change="prepareScoreRules"><el-option v-for="item in state.options.projects" :key="item.id" :label="item.title" :value="item.id" /></el-select></label>
          <label><span>学生</span><el-select v-model="state.form.student_id" filterable placeholder="请选择学生"><el-option v-for="item in scoreStudentOptions" :key="item.student_id" :label="`${item.name} / ${item.student_num}`" :value="item.student_id" /></el-select></label>
          <section class="span-2 social-inline-editor">
            <header><strong>评分项</strong><small>按计划规则自动计算总评</small></header>
            <div v-for="item in state.form.items" :key="item.item_code" class="social-score-input">
              <span>{{ item.item_name }}（{{ item.weight }}%）</span>
              <el-input-number v-model="item.score_value" :min="0" :max="item.max_score || 100" :precision="1" />
            </div>
          </section>
          <label class="span-2"><span>评语</span><textarea v-model="state.form.comment" rows="4" maxlength="10000" /></label>
        </template>
      </form>
      <template #footer>
        <el-button :disabled="state.saving" @click="closeDialog">取消</el-button>
        <el-button :loading="state.saving" @click="saveForm(false)">{{ state.form.resource === 'project' ? '保存' : '保存草稿' }}</el-button>
        <el-button v-if="state.form.resource !== 'project'" type="primary" :loading="state.saving" @click="saveForm(true)">提交审核</el-button>
      </template>
    </OperationDialog>

    <OperationDialog
      :visible="state.dialog.type === 'detail'"
      :title="state.dialog.title"
      dialog-class="social-practice-detail-dialog"
      @close="closeDialog"
    >
      <div class="social-detail-content">
        <section class="social-detail-grid">
          <article v-for="item in detailFacts" :key="item.label"><span>{{ item.label }}</span><strong>{{ item.value || '-' }}</strong></article>
        </section>
        <section v-if="state.detail.description || state.detail.content" class="social-detail-block">
          <strong>内容</strong><p>{{ state.detail.description || state.detail.content }}</p>
        </section>
        <section v-if="state.detail.attachments?.length" class="social-detail-block">
          <strong>附件</strong>
          <div class="social-material-file-list"><article v-for="file in state.detail.attachments" :key="file.id"><a :href="backendUrl(file.url)" target="_blank" rel="noopener noreferrer">{{ file.download_name || file.name || `附件 #${file.id}` }}</a><FilePreviewButton :file="file" /></article></div>
        </section>
        <section v-if="state.detail.teachers?.length" class="social-detail-block">
          <strong>指导教师</strong>
          <el-table :data="state.detail.teachers" size="small"><el-table-column type="index" label="序号" width="66" /><el-table-column prop="teacher_name" label="教师" /><el-table-column prop="teacher_role" label="角色"><template #default="{ row }">{{ row.teacher_role === 'leader' ? '负责人' : '指导教师' }}</template></el-table-column><el-table-column prop="capacity" label="容量" /></el-table>
        </section>
        <section v-if="state.detail.participants?.length" class="social-detail-block">
          <strong>参与学生</strong>
          <el-table :data="state.detail.participants" size="small"><el-table-column type="index" label="序号" width="66" /><el-table-column prop="student_name" label="学生" /><el-table-column prop="student_num" label="学号" /><el-table-column prop="teacher_name" label="指导教师" /></el-table>
        </section>
        <section v-if="state.detail.members?.length" class="social-detail-block">
          <strong>团队成员</strong>
          <el-table :data="state.detail.members" size="small"><el-table-column type="index" label="序号" width="66" /><el-table-column prop="student_name" label="学生" /><el-table-column prop="student_num" label="学号" /><el-table-column prop="confirm_status" label="确认状态"><template #default="{ row }">{{ confirmStatusText(row.confirm_status) }}</template></el-table-column></el-table>
        </section>
      </div>
    </OperationDialog>

    <OperationDialog
      :visible="state.dialog.type === 'review' || state.dialog.type === 'modify'"
      :title="state.dialog.title"
      :busy="state.saving"
      @close="closeDialog"
    >
      <div class="social-review-form">
        <el-radio-group v-if="state.dialog.type === 'review'" v-model="state.review.status">
          <el-radio-button value="accept">通过</el-radio-button>
          <el-radio-button value="modify">退回修改</el-radio-button>
        </el-radio-group>
        <label>
          <span>{{ state.dialog.type === 'modify' ? '修改理由' : '审核意见' }}</span>
          <textarea v-model="state.review.opinion" :maxlength="reviewLimit.max" rows="7" :placeholder="reviewPlaceholder" />
          <small>{{ state.review.opinion.length }} / {{ reviewLimit.max }} 字<span v-if="reviewLimit.min">，至少 {{ reviewLimit.min }} 字</span></small>
        </label>
      </div>
      <template #footer>
        <el-button :disabled="state.saving" @click="closeDialog">取消</el-button>
        <el-button v-if="state.dialog.type === 'review'" :loading="state.saving" @click="saveReviewDraftOnly">保存草稿</el-button>
        <el-button type="primary" :loading="state.saving" @click="submitReview">提交审核</el-button>
      </template>
    </OperationDialog>

    <OperationDialog :visible="state.dialog.type === 'timeline'" title="流程记录" dialog-class="social-practice-timeline-dialog" @close="closeDialog">
      <div class="social-timeline">
        <article v-for="record in state.timeline.records" :key="record.id">
          <span class="social-timeline-dot" />
          <div class="social-timeline-main">
            <header><strong>{{ actionText(record.action) }}</strong><time>{{ record.created_at }}</time></header>
            <p>{{ statusText(record.from_status) }} → {{ statusText(record.to_status) }}</p>
            <blockquote v-if="record.content">{{ record.content }}</blockquote>
            <section v-for="review in reviewsFor(record.id)" :key="review.id" class="social-review-branch">
              <span /><div><strong>{{ review.reviewer_name || review.reviewer_login || '审核人' }} · {{ statusText(review.status) }}</strong><p>{{ review.opinion || '无审核意见' }}</p></div>
            </section>
          </div>
        </article>
        <el-empty v-if="!state.timeline.records.length" description="暂无流程记录" />
      </div>
    </OperationDialog>

    <OperationDialog :visible="state.dialog.type === 'assignTeachers'" title="分配指导教师" :busy="state.saving" @close="closeDialog">
      <div class="social-assignment-form">
        <div v-for="(item, index) in state.assignment.teachers" :key="index" class="social-inline-row teacher-row">
          <el-select v-model="item.teacher_id" filterable placeholder="请选择教师"><el-option v-for="teacher in state.options.teachers" :key="teacher.teacher_id" :label="`${teacher.teacher_name} / ${teacher.teacher_num || '-'}`" :value="teacher.teacher_id" /></el-select>
          <el-select v-model="item.teacher_role" placeholder="请选择教师角色"><el-option label="负责人" value="leader" /><el-option label="指导教师" value="guide" /></el-select>
          <el-input-number v-model="item.capacity" :min="0" :max="10000" />
          <el-button link type="danger" @click="state.assignment.teachers.splice(index, 1)">移除</el-button>
        </div>
        <el-button :icon="Plus" @click="state.assignment.teachers.push({ teacher_id: null, teacher_role: 'guide', capacity: 0 })">添加教师</el-button>
      </div>
      <template #footer><el-button @click="closeDialog">取消</el-button><el-button type="primary" :loading="state.saving" @click="submitTeacherAssignment">保存分配</el-button></template>
    </OperationDialog>

    <OperationDialog :visible="state.dialog.type === 'assignStudents'" title="分配学生与指导教师" :busy="state.saving" dialog-class="social-practice-assignment-dialog" @close="closeDialog">
      <div class="social-student-assignment">
        <header><el-button :icon="Plus" @click="addParticipant">添加学生</el-button><small>每名学生必须绑定当前项目中的一名指导教师。</small></header>
        <el-table :data="state.assignment.participants" size="small" height="420">
          <el-table-column type="index" label="序号" width="66" />
          <el-table-column label="学生" min-width="220"><template #default="{ row }"><el-select v-model="row.student_id" filterable placeholder="请选择学生"><el-option v-for="student in state.options.students" :key="student.student_id" :label="`${student.name} / ${student.student_num}`" :value="student.student_id" /></el-select></template></el-table-column>
          <el-table-column label="指导教师" min-width="200"><template #default="{ row }"><el-select v-model="row.teacher_id" filterable placeholder="请选择指导教师"><el-option v-for="teacher in state.assignment.teachers" :key="teacher.teacher_id" :label="teacherName(teacher.teacher_id)" :value="teacher.teacher_id" /></el-select></template></el-table-column>
          <el-table-column label="操作" width="80"><template #default="{ $index }"><el-button link type="danger" @click="state.assignment.participants.splice($index, 1)">移除</el-button></template></el-table-column>
        </el-table>
      </div>
      <template #footer><el-button @click="closeDialog">取消</el-button><el-button type="primary" :loading="state.saving" @click="submitStudentAssignment">保存分配</el-button></template>
    </OperationDialog>

    <OperationDialog :visible="state.dialog.type === 'assignDeclarationTeacher'" title="分配分散实践指导教师" :busy="state.saving" @close="closeDialog">
      <div class="social-assignment-form">
        <label class="social-assignment-field"><span>指导教师</span><el-select v-model="state.assignment.declarationTeacherId" filterable placeholder="请选择指导教师"><el-option v-for="teacher in state.options.teachers" :key="teacher.teacher_id" :label="`${teacher.teacher_name} / ${teacher.teacher_num || '-'}`" :value="teacher.teacher_id" /></el-select></label>
        <small class="social-assignment-hint">分配后教师需要在确认期限内确认，申报审核会使用最新的确认结果。</small>
      </div>
      <template #footer><el-button @click="closeDialog">取消</el-button><el-button type="primary" :loading="state.saving" @click="submitDeclarationTeacherAssignment">保存分配</el-button></template>
    </OperationDialog>

    <OperationDialog :visible="state.dialog.type === 'teacherConfirm'" title="确认分散实践指导" :busy="state.saving" @close="closeDialog">
      <el-alert title="确认接受后，申报才会进入项目可行性审核；拒绝后由学生重新选择或管理员重新分配。" type="info" :closable="false" show-icon />
      <template #footer>
        <el-button :disabled="state.saving" @click="closeDialog">取消</el-button>
        <el-button type="danger" plain :loading="state.saving" @click="submitTeacherConfirmation('refused')">拒绝指导</el-button>
        <el-button type="primary" :loading="state.saving" @click="submitTeacherConfirmation('accepted')">接受指导</el-button>
      </template>
    </OperationDialog>

    <OperationDialog :visible="state.dialog.type === 'teacherReselect'" title="重新选择指导教师" :busy="state.saving" @close="closeDialog">
      <div class="social-assignment-form">
        <label class="social-assignment-field"><span>指导教师</span><el-select v-model="state.assignment.declarationTeacherId" filterable placeholder="请选择其他指导教师"><el-option v-for="teacher in state.options.teachers" :key="teacher.teacher_id" :label="`${teacher.teacher_name} / ${teacher.teacher_num || '-'}`" :value="teacher.teacher_id" /></el-select></label>
        <small class="social-assignment-hint">仅在教师拒绝或确认超时后可重新选择，次数受计划配置限制。</small>
      </div>
      <template #footer><el-button @click="closeDialog">取消</el-button><el-button type="primary" :loading="state.saving" @click="submitTeacherReselect">提交选择</el-button></template>
    </OperationDialog>

    <OperationDialog :visible="state.dialog.type === 'planImport'" title="导入社会实践计划" :busy="state.saving || state.uploading" dialog-class="social-practice-import-dialog" @close="closeDialog">
      <div class="social-plan-import">
        <el-alert title="Excel 中的年级、学院、专业和班级必须已在基础档案中启用；导入只生成或更新草稿。" type="info" :closable="false" show-icon />
        <label class="social-import-upload">
          <input type="file" accept=".xls,.xlsx" :disabled="state.uploading" @change="previewPlanImport">
          <span><Upload :size="18" />{{ state.uploading ? '正在解析...' : '选择 Excel 文件' }}</span>
        </label>
        <div v-if="state.planImport.summary" class="social-import-summary">
          <span>共 {{ state.planImport.summary.source_rows || 0 }} 条</span>
          <span>错误 {{ state.planImport.summary.error_rows || 0 }} 条</span>
          <span>重复 {{ state.planImport.summary.duplicate_rows || 0 }} 条</span>
        </div>
        <el-table v-if="state.planImport.items.length" :data="state.planImport.items" size="small" height="390">
          <el-table-column type="index" label="序号" width="66" />
          <el-table-column prop="title" label="计划名称" min-width="180" />
          <el-table-column prop="grade_name" label="年级" width="110" />
          <el-table-column prop="department_name" label="学院" min-width="150" />
          <el-table-column prop="profession_name" label="专业" min-width="150" />
          <el-table-column label="校验结果" min-width="220"><template #default="{ row }"><el-tag :type="row.errors?.length ? 'danger' : (row.duplicate ? 'warning' : 'success')">{{ row.errors?.join('；') || (row.duplicate ? '将更新可编辑草稿' : '可导入') }}</el-tag></template></el-table-column>
        </el-table>
      </div>
      <template #footer>
        <el-button :disabled="state.saving" @click="closeDialog">取消</el-button>
        <el-button type="primary" :loading="state.saving" :disabled="!state.planImport.fileId || !state.planImport.items.length || state.planImport.items.some(item => item.errors?.length)" @click="confirmPlanImport">确认导入</el-button>
      </template>
    </OperationDialog>
  </section>
</template>

<script setup>
import FilePreviewButton from '../../../shared/components/FilePreviewButton.vue';
import { computed, reactive, ref, watch } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Archive,
  CheckCircle2,
  ChevronRight,
  ClipboardCheck,
  Download,
  FileCheck2,
  FolderArchive,
  Plus,
  RefreshCw,
  Route,
  Upload,
  UsersRound,
  Workflow,
} from '@lucide/vue';
import DataListPanel from './DataListPanel.vue';
import OperationDialog from './OperationDialog.vue';
import {
  archiveSocialPractice,
  assignSocialPracticeDeclarationTeacher,
  assignSocialPracticeStudents,
  assignSocialPracticeTeachers,
  confirmSocialPracticePlanImport,
  confirmSocialPracticeTeacher,
  fetchSocialPracticeDetail,
  fetchSocialPracticeEligibleStudents,
  fetchSocialPracticeList,
  fetchSocialPracticeOptions,
  fetchSocialPracticeOverview,
  fetchSocialPracticePlanImportTemplate,
  fetchSocialPracticeReviewDraft,
  fetchSocialPracticeStatistics,
  fetchSocialPracticeTimeline,
  exportSocialPracticeStatistics,
  publishSocialPractice,
  previewSocialPracticePlanImport,
  reselectSocialPracticeTeacher,
  requestSocialPracticeModification,
  reviewSocialPractice,
  saveSocialPractice,
  saveSocialPracticeMaterial,
  saveSocialPracticePatchSign,
  saveSocialPracticeReviewDraft,
  saveSocialPracticeScore,
  submitSocialPractice,
  uploadFile,
} from '../api/system';
import { backendUrl } from '../api/client';

const props = defineProps({
  panel: { type: String, default: 'overview' },
  roleType: { type: String, default: '' },
  accountId: { type: [Number, String], default: 0 },
  hasPermission: { type: Function, default: () => false },
});

const panelDefinitions = {
  plans: { resource: 'plan', title: '计划管理', description: '按年级维护社会实践计划、适用范围、材料要求和成绩规则。', columns: planColumns() },
  centralized: { resource: 'project', title: '集中实践', description: '维护集中实践项目并完成负责人、指导教师和学生分配。', columns: projectColumns(), practice_mode: 'centralized' },
  implementations: { resource: 'implementation', title: '实施申请', description: '集中实践负责人提交实施内容、预算、材料和场地要求。', columns: implementationColumns() },
  distributed: { resource: 'declaration', title: '分散实践', description: '查看个人与团队申报、成员确认和指导教师确认进度。', columns: declarationColumns() },
  participants: { resource: 'participant', title: '参与学生', description: '查看学生、项目和指导教师的实际绑定关系。', columns: participantColumns() },
  teachers: { resource: 'teacher', title: '指导教师', description: '查看集中实践项目的负责人、指导教师和当前指导人数。', columns: teacherColumns() },
  safety: { resource: 'safety', title: '安全材料', description: '统一管理安全承诺、保险、应急预案和可选家长知情书。', columns: materialColumns() },
  materials: { resource: 'material', title: '成果材料', description: '按计划要求提交、评阅和归集社会实践成果材料。', columns: materialColumns() },
  attendance: { resource: 'attendance', title: '签到记录', description: '查看学生 GPS 签到结果和实际指导教师。', columns: attendanceColumns() },
  patchSigns: { resource: 'patch_sign', title: '补签申请', description: '处理学生缺勤后的补签申请及证明说明。', columns: patchSignColumns() },
  scores: { resource: 'score', title: '成绩管理', description: '指导教师按计划评分规则录入成绩并提交审核。', columns: scoreColumns() },
  archives: { resource: 'archive', title: '归档管理', description: '按学生生成包含材料、成绩和流程记录的版本化档案。', columns: archiveColumns() },
};

const materialTypeOptions = [
  { label: '安全承诺书', value: 'safety_agreement' }, { label: '保险材料', value: 'insurance' }, { label: '应急预案', value: 'emergency_plan' }, { label: '家长知情书', value: 'parent_notice' },
  { label: '实践报告', value: 'practice_report' }, { label: '实践照片', value: 'practice_photo' }, { label: '实践证明', value: 'practice_proof' }, { label: '社会实践报告', value: 'social_practice_report' },
];

const state = reactive({
  loading: false,
  saving: false,
  uploading: false,
  error: false,
  message: '',
  overview: {},
  statistics: { summary: {}, by_grade: [], by_mode: [], by_college: [] },
  options: emptyOptions(),
  planStudents: [],
  rows: [],
  pagination: { page: 1, page_size: 20, total: 0 },
  filters: emptyFilters(),
  dialog: { type: '', title: '', row: null },
  detail: {},
  timeline: { records: [], reviews: [] },
  review: { status: 'accept', opinion: '' },
  form: emptyForm('plan'),
  assignment: { projectId: null, declarationId: null, declarationTeacherId: null, teachers: [], participants: [] },
  planImport: { fileId: null, items: [], summary: null },
});
const materialFileInput = ref(null);

const panelConfig = computed(() => panelDefinitions[props.panel] || panelDefinitions.plans);
const isStudent = computed(() => props.roleType === 'student');
const isTeacher = computed(() => props.roleType === 'teacher');
const isAdmin = computed(() => ['super_admin', 'school_admin', 'college_admin', 'profession_admin'].includes(props.roleType));
const centralizedProjects = computed(() => state.options.projects.filter(item => item.practice_mode === 'centralized'));
const scoreStudentOptions = computed(() => {
  const projectId = Number(state.form.project_id || 0);
  const assignedIds = state.rows.filter(row => Number(row.project_id) === projectId).map(row => Number(row.student_id));
  return assignedIds.length ? state.options.students.filter(item => assignedIds.includes(Number(item.student_id))) : state.options.students;
});
const panelResource = computed(() => panelConfig.value.resource);
const createLabel = computed(() => ({
  plan: '新增计划', project: '新增集中项目', implementation: '新增实施申请', declaration: '发起分散申报',
  material: props.panel === 'safety' ? '提交安全材料' : '提交成果材料', patch_sign: '申请补签', score: '录入成绩',
}[panelResource.value] || '新增'));
const canCreate = computed(() => {
  const resource = panelResource.value;
  if (resource === 'plan') return isAdmin.value && can('social_practice:plan:manage');
  if (resource === 'project') return isAdmin.value && can('social_practice:project:manage');
  if (resource === 'implementation') return (isAdmin.value || isTeacher.value) && can('social_practice:project:manage');
  if (resource === 'declaration') return isStudent.value && can('social_practice:declare');
  if (resource === 'material') return can('social_practice:material:manage');
  if (resource === 'patch_sign') return isStudent.value && can('social_practice:attendance:manage');
  if (resource === 'score') return (isTeacher.value || isAdmin.value) && can('social_practice:score:manage');
  return false;
});
const overviewCards = computed(() => [
  { key: 'active_plans', label: '进行中计划', icon: Route, tone: 'blue' },
  { key: 'centralized_projects', label: '集中实践项目', icon: UsersRound, tone: 'green' },
  { key: 'distributed_projects', label: '分散实践项目', icon: Workflow, tone: 'teal' },
  { key: 'active_participants', label: '参与学生', icon: ClipboardCheck, tone: 'amber' },
  { key: 'pending_materials', label: '待审材料', icon: FileCheck2, tone: 'red' },
  { key: 'pending_scores', label: '待审成绩', icon: CheckCircle2, tone: 'purple' },
  { key: 'archived_records', label: '已归档', icon: FolderArchive, tone: 'gray' },
]);
const statisticCards = [{ key: 'plan_count', label: '计划总数', icon: Route, tone: 'blue' }, { key: 'published_plan_count', label: '已发布计划', icon: ClipboardCheck, tone: 'green' }, { key: 'project_count', label: '项目总数', icon: UsersRound, tone: 'teal' }, { key: 'participant_count', label: '参与记录', icon: UsersRound, tone: 'amber' }, { key: 'material_accept_count', label: '已通过材料', icon: FileCheck2, tone: 'red' }, { key: 'score_accept_count', label: '已通过成绩', icon: CheckCircle2, tone: 'purple' }, { key: 'archive_count', label: '归档记录', icon: FolderArchive, tone: 'gray' }];
const listFilters = computed(() => [
  { key: 'grade_id', label: '年级', type: 'select', options: state.options.grades.map(item => ({ label: item.grade_name, value: item.grade_id })) },
  { key: 'dep_id', label: '学院', type: 'select', options: state.options.departments.map(item => ({ label: item.dep_name, value: item.dep_id })) },
  { key: 'profession_id', label: '专业', type: 'select', options: state.options.professions.filter(item => !state.filters.dep_id || Number(item.dep_id) === Number(state.filters.dep_id)).map(item => ({ label: item.profession_name, value: item.profession_id })) },
  { key: 'status', label: '状态', type: 'select', options: statusOptions },
  { key: 'keyword', label: '关键词', type: 'input', placeholder: '名称、学生、教师或内容' },
]);
const tableActions = computed(() => uniqueActions(state.rows.flatMap(rowActions)));
const detailFacts = computed(() => detailFields(state.dialog.row?.resource || panelResource.value).map(item => ({ label: item.label, value: item.format ? item.format(state.detail) : state.detail[item.key] })));
const reviewLimit = computed(() => state.review.status === 'modify' || state.dialog.type === 'modify' ? { min: 8, max: 800 } : { min: 0, max: 300 });
const reviewPlaceholder = computed(() => reviewLimit.value.min ? `请填写不少于 ${reviewLimit.value.min} 字的具体原因` : '可填写审核意见');

watch(() => props.panel, async () => {
  state.filters = defaultFilters();
  if (props.panel === 'overview') await loadOverview();
  else if (props.panel === 'statistics') await loadStatistics();
  else await loadList(1);
}, { immediate: true });

/** 读取社会实践基础选项。 */
async function ensureOptions(force = false) {
  if (!force && state.options.loaded) return;
  const data = await fetchSocialPracticeOptions();
  state.options = { ...emptyOptions(), ...data, loaded: true };
  if (!state.filters.grade_id) state.filters.grade_id = data.current_grade_id || null;
}

/** 读取所选计划范围内的团队成员。 */
async function loadPlanStudents() {
  if (!state.form.plan_id) {
    state.planStudents = [];
    return;
  }
  const data = await fetchSocialPracticeEligibleStudents(state.form.plan_id);
  state.planStudents = (data.items || []).filter(item => Number(item.student_id) !== Number(state.options.current_student_id || 0));
}

/** 应用管理员默认组织范围。 */
function applyPlanScopeDefaults(form) {
  if (form.resource !== 'plan') return;
  const professionId = Number(state.options.default_profession_id || 0);
  const profession = state.options.professions.find(item => Number(item.profession_id) === professionId);
  const depId = Number(state.options.default_dep_id || profession?.dep_id || 0);
  if (professionId) {
    form.organizer_dep_id = depId || '';
    form.scopes = [{ scope_type: 'profession', dep_id: depId || '', profession_id: professionId, class_id: '' }];
  } else if (depId) {
    form.organizer_dep_id = depId;
    form.scopes = [{ scope_type: 'college', dep_id: depId, profession_id: '', class_id: '' }];
  }
  form.approval_flow_id = state.options.approval_flows[0]?.id || '';
}

/** 读取社会实践总览数据。 */
async function loadOverview() {
  await runLoading(async () => {
    const data = await fetchSocialPracticeOverview();
    state.overview = data.statistics || {};
  });
}

/** 读取社会实践统计报表。 */
async function loadStatistics() {
  await runLoading(async () => {
    await ensureOptions();
    state.statistics = await fetchSocialPracticeStatistics({ grade_id: state.filters.grade_id, dep_id: state.filters.dep_id });
  });
}

/** 创建社会实践统计导出任务。 */
async function exportStatistics() {
  if (state.saving) return;
  state.saving = true;
  try {
    await exportSocialPracticeStatistics({ grade_id: state.filters.grade_id, dep_id: state.filters.dep_id });
    ElMessage.success('导出任务已创建，请到导出任务中心下载');
  } catch (error) {
    ElMessage.error(error.message);
  } finally {
    state.saving = false;
  }
}

/** 下载社会实践计划导入模板。 */
async function downloadPlanImportTemplate() {
  try {
    const data = await fetchSocialPracticePlanImportTemplate();
    window.open(backendUrl(data.url), '_blank', 'noopener,noreferrer');
  } catch (error) {
    ElMessage.error(error.message);
  }
}

/** 打开社会实践计划导入窗口。 */
function openPlanImport() {
  state.planImport = { fileId: null, items: [], summary: null };
  state.dialog = { type: 'planImport', title: '导入社会实践计划', row: null };
}

/** 上传并预览社会实践计划 Excel。 */
async function previewPlanImport(event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file || state.uploading) return;
  state.uploading = true;
  try {
    const data = await previewSocialPracticePlanImport(file);
    state.planImport = {
      fileId: Number(data.file?.id || data.file?.file_id || 0),
      items: data.items || [],
      summary: data.summary || null,
    };
  } catch (error) {
    ElMessage.error(error.message);
  } finally {
    state.uploading = false;
  }
}

/** 确认写入社会实践计划草稿。 */
async function confirmPlanImport() {
  if (state.saving || !state.planImport.fileId) return;
  state.saving = true;
  try {
    const data = await confirmSocialPracticePlanImport({ import_file_id: state.planImport.fileId, rows: state.planImport.items });
    ElMessage.success(`导入完成：新增 ${data.created || 0} 条，更新 ${data.updated || 0} 条`);
    closeDialog();
    await loadList(1);
  } catch (error) {
    ElMessage.error(error.message);
  } finally {
    state.saving = false;
  }
}

/** 读取当前业务列表。 */
async function loadList(page = 1) {
  await runLoading(async () => {
    await ensureOptions();
    const params = { ...state.filters, page, page_size: state.pagination.page_size };
    if (panelConfig.value.practice_mode) params.practice_mode = panelConfig.value.practice_mode;
    const data = await fetchSocialPracticeList(panelResource.value, params);
    state.rows = data.items || [];
    const pagination = data.pagination || data;
    state.pagination = { page: Number(pagination.page || page), page_size: Number(pagination.page_size || 20), total: Number(pagination.total || 0) };
  });
}

/** 更新列表筛选值。 */
function setFilter({ key, value }) {
  state.filters[key] = value ?? '';
  if (key === 'dep_id') state.filters.profession_id = '';
}

/** 恢复角色默认筛选值。 */
async function resetFilters() {
  state.filters = defaultFilters();
  await loadList(1);
}

/** 打开当前面板的新建表单。 */
async function openCreate() {
  await ensureOptions();
  const resource = ['safety', 'material'].includes(panelResource.value) ? 'material' : panelResource.value;
  state.form = emptyForm(resource);
  applyPlanScopeDefaults(state.form);
  if (resource === 'material' && props.panel === 'safety') state.form.material_type = 'safety_agreement';
  state.dialog = { type: 'form', title: createLabel.value, row: { resource } };
}

/** 打开业务记录编辑表单。 */
async function openEdit(row) {
  const resource = actionResource(row);
  let detail = row;
  if (workflowResources.includes(resource)) detail = await fetchSocialPracticeDetail(resource, row.id);
  state.form = formFromRow(resource, detail);
  if (resource === 'declaration' && state.form.plan_id) await loadPlanStudents();
  state.dialog = { type: 'form', title: `编辑${panelConfig.value.title}`, row: { ...row, resource } };
}

/** 上传社会实践材料附件。 */
async function uploadMaterialFiles(event) {
  const files = Array.from(event.target.files || []);
  if (!files.length || state.uploading) return;
  state.uploading = true;
  try {
    for (const file of files) {
      const uploaded = await uploadFile(file, { category: 'social_practice_material', is_temporary: 'false' });
      if (!state.form.attachments.some(item => Number(item.id || item.file_id) === Number(uploaded.file_id))) {
        state.form.attachments.push({ id: uploaded.file_id, file_id: uploaded.file_id, name: uploaded.name || file.name, url: uploaded.url || '' });
      }
    }
  } catch (error) {
    ElMessage.error(error.message);
  } finally {
    state.uploading = false;
    event.target.value = '';
  }
}

/** 移除社会实践材料附件。 */
function removeMaterialFile(file) {
  const fileId = Number(file.id || file.file_id);
  state.form.attachments = state.form.attachments.filter(item => Number(item.id || item.file_id) !== fileId);
}

/** 保存业务表单并按需提交审核。 */
async function saveForm(submit) {
  if (state.saving || state.uploading) return;
  state.saving = true;
  try {
    const payload = normalizeFormPayload(state.form);
    let result;
    if (state.form.resource === 'material') result = await saveSocialPracticeMaterial({ ...payload, submit });
    else if (state.form.resource === 'patch_sign') result = await saveSocialPracticePatchSign({ ...payload, submit });
    else if (state.form.resource === 'score') result = await saveSocialPracticeScore({ ...payload, submit });
    else {
      result = await saveSocialPractice(payload);
      if (submit && state.form.resource !== 'project') await submitSocialPractice({ resource: state.form.resource, id: result.id });
    }
    ElMessage.success(state.form.resource === 'project' ? '项目已保存' : (submit ? '已提交审核' : '草稿已保存'));
    closeDialog();
    await loadList(1);
  } catch (error) {
    ElMessage.error(error.message);
  } finally {
    state.saving = false;
  }
}

/** 执行列表记录操作。 */
async function handleRowAction({ action, row }) {
  if (action === 'view') return openDetail(row);
  if (action === 'timeline') return openTimeline(row);
  if (action === 'edit') return openEdit(row);
  if (action === 'review') return openReview(row, 'review');
  if (action === 'modify') return openReview(row, 'modify');
  if (action === 'submit') return confirmSubmit(row);
  if (action === 'publish') return confirmPublish(row);
  if (action === 'assignTeachers') return openTeacherAssignment(row);
  if (action === 'assignStudents') return openStudentAssignment(row);
  if (action === 'assignDeclarationTeacher') return openDeclarationTeacherAssignment(row);
  if (action === 'teacherConfirm') return openTeacherConfirmation(row);
  if (action === 'teacherReselect') return openTeacherReselect(row);
  if (action === 'archive') return confirmArchive(row);
}

/** 打开业务详情。 */
async function openDetail(row) {
  await runLoading(async () => {
    const resource = actionResource(row);
    state.detail = workflowResources.includes(resource) ? await fetchSocialPracticeDetail(resource, row.id) : { ...row };
    state.dialog = { type: 'detail', title: `${panelConfig.value.title}详情`, row: { ...row, resource } };
  });
}

/** 打开流程记录。 */
async function openTimeline(row) {
  await runLoading(async () => {
    const resource = actionResource(row);
    state.timeline = await fetchSocialPracticeTimeline(resource, row.id);
    state.dialog = { type: 'timeline', title: '流程记录', row: { ...row, resource } };
  });
}

/** 打开审核或通过后修改表单。 */
async function openReview(row, type) {
  const resource = actionResource(row);
  state.review = { status: type === 'modify' ? 'modify' : 'accept', opinion: '' };
  if (type === 'review') {
    try {
      const data = await fetchSocialPracticeReviewDraft(resource, row.id);
      if (data.draft) state.review = { status: data.draft.review_status || 'accept', opinion: data.draft.opinion || '' };
    } catch {
      state.review = { status: 'accept', opinion: '' };
    }
  }
  state.dialog = { type, title: type === 'modify' ? '通过后修改' : `审核${panelConfig.value.title}`, row: { ...row, resource } };
}

/** 保存审核意见草稿。 */
async function saveReviewDraftOnly() {
  if (state.saving) return;
  state.saving = true;
  try {
    await saveSocialPracticeReviewDraft({ resource: state.dialog.row.resource, id: state.dialog.row.id, ...state.review });
    ElMessage.success('审核草稿已保存');
  } catch (error) {
    ElMessage.error(error.message);
  } finally {
    state.saving = false;
  }
}

/** 提交审核结果或通过后修改请求。 */
async function submitReview() {
  if (state.saving) return;
  if (state.review.opinion.length < reviewLimit.value.min) {
    ElMessage.warning(`意见至少填写 ${reviewLimit.value.min} 字`);
    return;
  }
  state.saving = true;
  try {
    const payload = { resource: state.dialog.row.resource, id: state.dialog.row.id, ...state.review };
    if (state.dialog.type === 'modify') await requestSocialPracticeModification(payload);
    else await reviewSocialPractice(payload);
    ElMessage.success(state.dialog.type === 'modify' ? '已发起修改' : '审核已提交');
    closeDialog();
    await loadList(state.pagination.page);
  } catch (error) {
    ElMessage.error(error.message);
  } finally {
    state.saving = false;
  }
}

/** 二次确认提交草稿。 */
async function confirmSubmit(row) {
  try {
    await ElMessageBox.confirm('提交后将进入审核流程，并向审核人发送待办和消息。', '提交审核', { type: 'warning', confirmButtonText: '提交审核' });
    await runSaving(async () => submitSocialPractice({ resource: actionResource(row), id: row.id }), '已提交审核');
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') ElMessage.error(error.message || String(error));
  }
}

/** 二次确认发布计划。 */
async function confirmPublish(row) {
  try {
    await ElMessageBox.confirm('发布后，计划范围内的学生将收到通知。', '发布计划', { type: 'warning', confirmButtonText: '确认发布' });
    await runSaving(async () => publishSocialPractice({ id: row.id }), '计划已发布');
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') ElMessage.error(error.message || String(error));
  }
}

/** 打开项目教师分配。 */
async function openTeacherAssignment(row) {
  const detail = await fetchSocialPracticeDetail('project', row.id);
  state.assignment = { projectId: row.id, teachers: detail.teachers?.length ? detail.teachers.map(item => ({ teacher_id: item.teacher_id, teacher_role: item.teacher_role, capacity: Number(item.capacity || 0) })) : [{ teacher_id: null, teacher_role: 'leader', capacity: Number(row.capacity || 0) }], participants: detail.participants || [] };
  state.dialog = { type: 'assignTeachers', title: '分配指导教师', row: { ...row, resource: 'project' } };
}

/** 保存项目教师分配。 */
async function submitTeacherAssignment() {
  await runAssignment(async () => assignSocialPracticeTeachers({ project_id: state.assignment.projectId, teachers: state.assignment.teachers }), '教师分配已保存');
}

/** 打开项目学生分配。 */
async function openStudentAssignment(row) {
  const detail = await fetchSocialPracticeDetail('project', row.id);
  state.assignment = {
    projectId: row.id,
    teachers: detail.teachers || [],
    participants: (detail.participants || []).map(item => ({ student_id: item.student_id, teacher_id: item.teacher_id })),
  };
  state.dialog = { type: 'assignStudents', title: '分配学生与指导教师', row: { ...row, resource: 'project' } };
}

/** 添加学生分配行。 */
function addParticipant() {
  state.assignment.participants.push({ student_id: null, teacher_id: state.assignment.teachers[0]?.teacher_id || null });
}

/** 保存项目学生分配。 */
async function submitStudentAssignment() {
  await runAssignment(async () => assignSocialPracticeStudents({ project_id: state.assignment.projectId, participants: state.assignment.participants }), '学生分配已保存');
}

/** 打开分散实践指导教师分配。 */
function openDeclarationTeacherAssignment(row) {
  state.assignment = { projectId: null, declarationId: row.id, declarationTeacherId: row.assigned_teacher_id || row.selected_teacher_id || null, teachers: [], participants: [] };
  state.dialog = { type: 'assignDeclarationTeacher', title: '分配分散实践指导教师', row: { ...row, resource: 'declaration' } };
}

/** 保存分散实践指导教师分配。 */
async function submitDeclarationTeacherAssignment() {
  if (!state.assignment.declarationTeacherId) {
    ElMessage.warning('请选择指导教师');
    return;
  }
  await runAssignment(async () => assignSocialPracticeDeclarationTeacher({ declaration_id: state.assignment.declarationId, teacher_id: state.assignment.declarationTeacherId }), '指导教师分配已保存');
}

/** 打开教师确认窗口。 */
function openTeacherConfirmation(row) {
  state.dialog = { type: 'teacherConfirm', title: '确认分散实践指导', row: { ...row, resource: 'declaration' } };
}

/** 打开学生重新选择指导教师窗口。 */
function openTeacherReselect(row) {
  state.assignment = { projectId: null, declarationId: row.id, declarationTeacherId: null, teachers: [], participants: [] };
  state.dialog = { type: 'teacherReselect', title: '重新选择指导教师', row: { ...row, resource: 'declaration' } };
}

/** 提交学生重新选择的指导教师。 */
async function submitTeacherReselect() {
  if (!state.assignment.declarationTeacherId) {
    ElMessage.warning('请选择指导教师');
    return;
  }
  await runAssignment(() => reselectSocialPracticeTeacher({ declaration_id: state.assignment.declarationId, teacher_id: state.assignment.declarationTeacherId }), '已重新选择指导教师');
}

/** 提交教师确认结果。 */
async function submitTeacherConfirmation(status) {
  await runAssignment(
    () => confirmSocialPracticeTeacher({ declaration_id: state.dialog.row.id, status }),
    status === 'accepted' ? '已接受指导' : '已拒绝指导'
  );
}

/** 归档当前学生实践记录。 */
async function confirmArchive(row) {
  try {
    await ElMessageBox.confirm('归档会生成不可覆盖的版本化快照，请确认材料和成绩已完整。', '确认归档', { type: 'warning', confirmButtonText: '确认归档' });
    await runSaving(() => archiveSocialPractice({ plan_id: row.plan_id, project_id: row.project_id, student_id: row.student_id }), '归档完成');
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') ElMessage.error(error.message || String(error));
  }
}

/** 执行分配保存并刷新。 */
async function runAssignment(callback, message) {
  if (state.saving) return;
  state.saving = true;
  try {
    await callback();
    ElMessage.success(message);
    closeDialog();
    await loadList(state.pagination.page);
  } catch (error) {
    ElMessage.error(error.message);
  } finally {
    state.saving = false;
  }
}

/** 执行普通写操作并刷新。 */
async function runSaving(callback, message) {
  if (state.saving) return;
  state.saving = true;
  try {
    await callback();
    ElMessage.success(message);
    await loadList(state.pagination.page);
  } finally {
    state.saving = false;
  }
}

/** 执行读取并统一显示错误。 */
async function runLoading(callback) {
  if (state.loading) return;
  state.loading = true;
  state.error = false;
  state.message = '';
  try {
    await callback();
  } catch (error) {
    state.error = true;
    const detail = [
      error.message || '请求失败',
      error.code ? `业务码 ${error.code}` : '',
      error.apiPath ? `接口 ${error.apiPath}` : '',
    ].filter(Boolean);
    state.message = detail.join('，');
  } finally {
    state.loading = false;
  }
}

/** 关闭当前弹窗。 */
function closeDialog() {
  if (state.saving) return;
  state.dialog = { type: '', title: '', row: null };
  state.detail = {};
  state.timeline = { records: [], reviews: [] };
  state.planImport = { fileId: null, items: [], summary: null };
}

/** 返回当前记录允许执行的操作。 */
function rowActions(row) {
  const resource = actionResource(row);
  const actions = [{ key: 'view', label: '查看', type: 'primary' }];
  if (workflowResources.includes(resource)) actions.push({ key: 'timeline', label: '记录', type: 'primary' });
  if ((['draft', 'modify'].includes(row.status) || resource === 'project') && canEditResource(resource, row)) {
    actions.push({ key: 'edit', label: '编辑', type: 'primary' });
    if (resource !== 'project' && !['material', 'patch_sign', 'score'].includes(resource)) actions.push({ key: 'submit', label: '提交审核', type: 'success' });
  }
  if (row.status === 'wait' && canReviewResource(resource) && (resource !== 'declaration' || row.teacher_confirm_status === 'accepted')) actions.push({ key: 'review', label: '审核', type: 'warning' });
  if (row.status === 'accept' && canReviewResource(resource)) actions.push({ key: 'modify', label: '通过后修改', type: 'warning' });
  if (resource === 'plan' && row.status === 'accept' && row.phase === 'ready' && can('social_practice:plan:manage')) actions.push({ key: 'publish', label: '发布', type: 'success' });
  if (resource === 'project' && can('social_practice:project:manage')) {
    actions.push({ key: 'assignTeachers', label: '分配教师', type: 'primary' }, { key: 'assignStudents', label: '分配学生', type: 'primary' });
  }
  if (resource === 'declaration' && isAdmin.value && can('social_practice:project:manage') && ['draft', 'wait', 'modify'].includes(row.status)) {
    actions.push({ key: 'assignDeclarationTeacher', label: '分配教师', type: 'primary' });
  }
  if (resource === 'declaration' && isTeacher.value && row.teacher_confirm_status === 'pending' && Number(row.assigned_teacher_id || row.selected_teacher_id) > 0) {
    actions.push({ key: 'teacherConfirm', label: '确认指导', type: 'success' });
  }
  if (resource === 'declaration' && isStudent.value && row.status === 'wait' && row.teacher_confirm_status === 'refused') {
    actions.push({ key: 'teacherReselect', label: '重新选导师', type: 'warning' });
  }
  if (resource === 'participant' && can('social_practice:archive')) actions.push({ key: 'archive', label: '归档', type: 'success' });
  return actions;
}

/** 判断资源是否允许当前账号编辑。 */
function canEditResource(resource, row) {
  if (resource === 'plan') return isAdmin.value && can('social_practice:plan:manage');
  if (resource === 'project') return isAdmin.value && can('social_practice:project:manage');
  if (resource === 'implementation') return can('social_practice:project:manage');
  if (resource === 'declaration') return isStudent.value && can('social_practice:declare');
  if (resource === 'material') return can('social_practice:material:manage');
  if (resource === 'patch_sign') return isStudent.value && can('social_practice:attendance:manage');
  if (resource === 'score') return can('social_practice:score:manage');
  return false;
}

/** 判断资源是否允许当前账号审核。 */
function canReviewResource(resource) {
  return resource === 'score' ? can('social_practice:score:approve') : can('social_practice:approve');
}

/** 返回当前面板的实体资源。 */
function actionResource(row) {
  return row.resource || (['safety', 'material'].includes(panelResource.value) ? 'material' : panelResource.value);
}

/** 返回权限检查结果。 */
function can(code) {
  return props.hasPermission(code);
}

/** 返回记录主标题。 */
function rowTitle(row) {
  return row.title || row.project_title || row.plan_title || row.student_name || row.teacher_name || `记录 ${row.id}`;
}

/** 返回学生卡片摘要字段。 */
function rowFacts(row) {
  return panelConfig.value.columns.slice(0, 5).map(column => ({ label: column.label, value: column.formatter ? column.formatter(row) : row[column.prop] })).filter(item => item.value !== undefined);
}

/** 返回状态中文名。 */
function statusText(status) {
  return statusMap[status] || status || '-';
}

/** 返回状态标签类型。 */
function statusTagType(status) {
  return { accept: 'success', wait: 'warning', modify: 'danger', draft: 'info', enabled: 'success', active: 'success', archived: 'success' }[status] || 'info';
}

/** 返回成员确认状态。 */
function confirmStatusText(status) {
  return { pending: '待确认', accepted: '已确认', rejected: '已拒绝' }[status] || status || '-';
}

/** 返回流程动作中文名。 */
function actionText(action) {
  return { submit: '提交审核', review: '审核处理', modify_after_accept: '通过后修改', publish: '发布计划', auto_filing: '自动备案', archive: '完成归档', assign_teachers: '分配教师', assign_students: '分配学生', confirm_teacher: '教师确认', confirm_member: '成员确认' }[action] || action || '流程处理';
}

/** 返回流程记录对应的审核支线。 */
function reviewsFor(recordingId) {
  return state.timeline.reviews.filter(item => Number(item.recording_id) === Number(recordingId));
}

/** 返回指定项目教师名称。 */
function teacherName(id) {
  return state.options.teachers.find(item => Number(item.teacher_id) === Number(id))?.teacher_name || `教师 ${id}`;
}

/** 返回指定学院的专业。 */
function professionsFor(depId) {
  return state.options.professions.filter(item => !depId || Number(item.dep_id) === Number(depId));
}

/** 返回指定专业的班级。 */
function classesFor(professionId) {
  return state.options.classes.filter(item => !professionId || Number(item.profession_id) === Number(professionId));
}

/** 返回指定计划的项目。 */
function projectsForPlan(planId) {
  return state.options.projects.filter(item => !planId || Number(item.plan_id) === Number(planId));
}

/** 添加计划适用范围。 */
function addScope() {
  state.form.scopes.push({ scope_type: 'college', dep_id: null, profession_id: null, class_id: null });
}

/** 添加计划材料要求。 */
function addRequirement() {
  state.form.requirements.push({ practice_mode: 'all', requirement_type: 'practice_report', required_flag: true, submit_scope: 'student' });
}

/** 添加计划评分项。 */
function addScoreRule() {
  state.form.score_rules.push({ practice_mode: 'centralized', item_code: '', item_name: '', weight: 0, max_score: 100, sort: state.form.score_rules.length + 1 });
}

/** 移除动态表单项。 */
function removeArrayItem(key, index) {
  state.form[key].splice(index, 1);
}

/** 按项目读取评分规则。 */
async function prepareScoreRules(projectId) {
  const project = state.options.projects.find(item => Number(item.id) === Number(projectId));
  if (!project) return;
  try {
    const plan = await fetchSocialPracticeDetail('plan', project.plan_id);
    state.form.items = (plan.score_rules || []).filter(item => item.practice_mode === project.practice_mode).map(item => ({ ...item, score_value: 0 }));
  } catch (error) {
    ElMessage.error(error.message);
  }
}

/** 将详情数据转换为编辑表单。 */
function formFromRow(resource, row) {
  const form = emptyForm(resource);
  Object.keys(form).forEach((key) => {
    if (row[key] !== undefined && row[key] !== null) form[key] = row[key];
  });
  if (resource === 'plan') {
    form.scopes = (row.scopes || []).map(item => ({ ...item }));
    form.requirements = (row.requirements || []).map(item => ({ ...item, required_flag: item.required_flag === 'true' }));
    form.score_rules = (row.score_rules || []).map(item => ({ ...item, weight: Number(item.weight), max_score: Number(item.max_score) }));
  }
  if (resource === 'declaration') form.member_student_ids = (row.members || []).map(item => item.student_id);
  if (resource === 'material') {
    form.attachments = (row.attachments || []).map(item => ({ ...item, file_id: item.file_id || item.id }));
    form.file_ids = form.attachments.map(item => item.file_id || item.id);
  }
  if (resource === 'score') prepareScoreRules(row.project_id);
  return normalizeDateFields(form);
}

/** 规范化提交表单字段。 */
function normalizeFormPayload(form) {
  const payload = JSON.parse(JSON.stringify(form));
  dateFields.forEach((field) => {
    if (payload[field]) payload[field] = payload[field].replace('T', ' ') + (payload[field].length === 16 ? ':00' : '');
  });
  if (payload.resource === 'plan') payload.plan_code = payload.code || payload.plan_code || '';
  if (payload.resource === 'material') payload.file_ids = (payload.attachments || []).map(item => item.file_id || item.id).filter(Boolean);
  delete payload.attachments;
  return payload;
}

/** 规范化浏览器日期字段。 */
function normalizeDateFields(form) {
  dateFields.forEach((field) => {
    if (form[field]) form[field] = String(form[field]).slice(0, 16).replace(' ', 'T');
  });
  return form;
}

/** 创建资源空表单。 */
function emptyForm(resource) {
  const common = { resource, id: null };
  if (resource === 'plan') return { ...common, title: '', code: '', grade_id: null, organizer_dep_id: null, credit: 0, source_type: 'manual', participation_mode: 'mandatory', teacher_match_mode: 'mixed', teacher_confirm_hours: 48, max_reselect_count: 2, default_team_submit_mode: 'individual', register_start_at: '', register_end_at: '', practice_start_at: '', practice_end_at: '', result_deadline_at: '', score_deadline_at: '', approval_flow_id: 1, description: '', scopes: [{ scope_type: 'school', dep_id: null, profession_id: null, class_id: null }], requirements: defaultRequirements(), score_rules: defaultScoreRules() };
  if (resource === 'project') return { ...common, plan_id: null, practice_mode: 'centralized', project_code: '', title: '', content: '', objective: '', location: '', start_at: '', end_at: '', capacity: 0 };
  if (resource === 'implementation') return { ...common, project_id: null, title: '', content: '', budget_amount: 0, material_requirement: '', venue_requirement: '' };
  if (resource === 'declaration') return { ...common, plan_id: null, declaration_type: 'individual', team_submit_mode: 'individual', title: '', content: '', objective: '', expected_duration: '', expected_result: '', location: '', selected_teacher_id: null, member_student_ids: [] };
  if (resource === 'material') return { ...common, plan_id: null, project_id: null, declaration_id: null, student_id: state.options.students[0]?.student_id || null, material_type: 'practice_report', submit_scope: 'student', title: '', content: '', file_ids: [], attachments: [] };
  if (resource === 'patch_sign') return { ...common, project_id: null, sign_date: '', reason: '', proof: '' };
  if (resource === 'score') return { ...common, project_id: null, student_id: null, teacher_id: null, items: [], comment: '' };
  return common;
}

/** 返回默认筛选条件。 */
function defaultFilters() {
  return { ...emptyFilters(), grade_id: state.options.current_grade_id || null };
}

/** 返回空筛选条件。 */
function emptyFilters() {
  return { grade_id: '', dep_id: '', profession_id: '', status: '', keyword: '' };
}

/** 返回空选项集合。 */
function emptyOptions() {
  return { loaded: false, grades: [], departments: [], professions: [], classes: [], teachers: [], students: [], plans: [], projects: [], approval_flows: [], current_grade_id: null, current_student_id: null, default_dep_id: null, default_profession_id: null };
}

/** 去重列表动作配置。 */
function uniqueActions(actions) {
  const map = new Map();
  actions.forEach(action => map.set(action.key, { key: action.key, label: action.label, theme: action.type === 'danger' ? 'danger' : action.type === 'warning' ? 'warning' : '' }));
  return [...map.values()];
}

function planColumns() { return [{ prop: 'title', label: '计划名称', minWidth: 220, required: true }, { prop: 'grade_name', label: '年级', width: 110 }, { prop: 'organizer_dep_name', label: '组织学院', minWidth: 150 }, { prop: 'credit', label: '学分', width: 80 }, { prop: 'participation_mode', label: '参与方式', width: 100, formatter: row => row.participation_mode === 'mandatory' ? '必修' : '自愿' }, { prop: 'phase', label: '阶段', width: 100, formatter: row => phaseText(row.phase) }, { prop: 'status', label: '状态', width: 100, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) }, { prop: 'practice_start_at', label: '实践开始', width: 160 }, { prop: 'practice_end_at', label: '实践结束', width: 160 }]; }
function projectColumns() { return [{ prop: 'title', label: '项目名称', minWidth: 220, required: true }, { prop: 'plan_title', label: '所属计划', minWidth: 180 }, { prop: 'grade_name', label: '年级', width: 110 }, { prop: 'organizer_dep_name', label: '组织学院', minWidth: 140 }, { prop: 'location', label: '地点', minWidth: 160 }, { prop: 'teacher_count', label: '教师数', width: 90 }, { prop: 'participant_count', label: '学生数', width: 90 }, { prop: 'capacity', label: '容量', width: 80 }, { prop: 'phase', label: '阶段', width: 100, formatter: row => phaseText(row.phase) }, { prop: 'status', label: '状态', width: 100, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) }]; }
function implementationColumns() { return [{ prop: 'title', label: '实施申请', minWidth: 220, required: true }, { prop: 'project_title', label: '集中项目', minWidth: 200 }, { prop: 'plan_title', label: '所属计划', minWidth: 180 }, { prop: 'grade_name', label: '年级', width: 110 }, { prop: 'budget_amount', label: '预算', width: 110 }, { prop: 'status', label: '状态', width: 100, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) }, { prop: 'submitted_at', label: '提交时间', width: 160 }]; }
function declarationColumns() { return [{ prop: 'title', label: '申报名称', minWidth: 220, required: true }, { prop: 'applicant_name', label: '申报学生', width: 120 }, { prop: 'student_num', label: '学号', width: 130 }, { prop: 'declaration_type', label: '类型', width: 100, formatter: row => row.declaration_type === 'team' ? '团队' : '个人' }, { prop: 'selected_teacher_name', label: '选择教师', width: 120 }, { prop: 'assigned_teacher_name', label: '分配教师', width: 120 }, { prop: 'teacher_confirm_status', label: '教师确认', width: 110, formatter: row => confirmStatusText(row.teacher_confirm_status) }, { prop: 'status', label: '状态', width: 100, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) }, { prop: 'submitted_at', label: '提交时间', width: 160 }]; }
function participantColumns() { return [{ prop: 'student_name', label: '学生', width: 120, required: true }, { prop: 'student_num', label: '学号', width: 130 }, { prop: 'grade_name', label: '年级', width: 110 }, { prop: 'dep_name', label: '学院', minWidth: 150 }, { prop: 'profession_name', label: '专业', minWidth: 150 }, { prop: 'class_name', label: '班级', minWidth: 140 }, { prop: 'project_title', label: '实践项目', minWidth: 200 }, { prop: 'teacher_name', label: '指导教师', width: 120 }, { prop: 'practice_mode', label: '实践模式', width: 100, formatter: row => modeText(row.practice_mode) }, { prop: 'status', label: '状态', width: 90, formatter: row => statusText(row.status) }]; }
function teacherColumns() { return [{ prop: 'teacher_name', label: '教师', width: 120, required: true }, { prop: 'teacher_num', label: '工号', width: 130 }, { prop: 'dep_name', label: '学院', minWidth: 150 }, { prop: 'profession_name', label: '专业', minWidth: 150 }, { prop: 'project_title', label: '集中项目', minWidth: 210 }, { prop: 'teacher_role', label: '项目角色', width: 100, formatter: row => row.teacher_role === 'leader' ? '负责人' : '指导教师' }, { prop: 'assigned_count', label: '指导人数', width: 100 }, { prop: 'capacity', label: '容量', width: 80 }]; }
function materialColumns() { return [{ prop: 'title', label: '材料标题', minWidth: 220, required: true }, { prop: 'material_type', label: '材料类型', width: 140, formatter: row => materialTypeText(row.material_type) }, { prop: 'student_name', label: '学生', width: 120 }, { prop: 'student_num', label: '学号', width: 130 }, { prop: 'project_title', label: '实践项目', minWidth: 190 }, { prop: 'plan_title', label: '所属计划', minWidth: 180 }, { prop: 'version', label: '版本', width: 80 }, { prop: 'status', label: '状态', width: 100, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) }, { prop: 'submitted_at', label: '提交时间', width: 160 }]; }
function attendanceColumns() { return [{ prop: 'student_name', label: '学生', width: 120, required: true }, { prop: 'student_num', label: '学号', width: 130 }, { prop: 'grade_name', label: '年级', width: 110 }, { prop: 'project_title', label: '实践项目', minWidth: 200 }, { prop: 'date', label: '签到日期', width: 120 }, { prop: 'sign_time', label: '签到时间', width: 160 }, { prop: 'location', label: 'GPS 位置', minWidth: 180 }, { prop: 'teacher_name', label: '指导教师', width: 120 }]; }
function patchSignColumns() { return [{ prop: 'student_name', label: '学生', width: 120, required: true }, { prop: 'student_num', label: '学号', width: 130 }, { prop: 'project_title', label: '实践项目', minWidth: 200 }, { prop: 'sign_date', label: '补签日期', width: 120 }, { prop: 'reason', label: '补签原因', minWidth: 220 }, { prop: 'status', label: '状态', width: 100, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) }, { prop: 'submitted_at', label: '提交时间', width: 160 }]; }
function scoreColumns() { return [{ prop: 'student_name', label: '学生', width: 120, required: true }, { prop: 'student_num', label: '学号', width: 130 }, { prop: 'project_title', label: '实践项目', minWidth: 200 }, { prop: 'teacher_name', label: '评分教师', width: 120 }, { prop: 'final_score', label: '总评', width: 90 }, { prop: 'credit_recognized', label: '学分认定', width: 100, formatter: row => row.credit_recognized === 'true' ? '已认定' : '未认定' }, { prop: 'status', label: '状态', width: 100, tag: true, tagType: row => statusTagType(row.status), formatter: row => statusText(row.status) }, { prop: 'reviewed_at', label: '审核时间', width: 160 }]; }
function archiveColumns() { return [{ prop: 'student_name', label: '学生', width: 120, required: true }, { prop: 'student_num', label: '学号', width: 130 }, { prop: 'plan_title', label: '所属计划', minWidth: 190 }, { prop: 'project_title', label: '实践项目', minWidth: 190 }, { prop: 'teacher_name', label: '指导教师', width: 120 }, { prop: 'version', label: '归档版本', width: 100 }, { prop: 'status', label: '状态', width: 100, formatter: row => statusText(row.status) }, { prop: 'archived_at', label: '归档时间', width: 160 }]; }

/** 返回详情字段。 */
function detailFields(resource) {
  const common = [{ key: 'title', label: '名称' }, { key: 'plan_title', label: '所属计划' }, { key: 'project_title', label: '实践项目' }, { key: 'grade_name', label: '年级' }, { key: 'student_name', label: '学生' }, { key: 'teacher_name', label: '指导教师' }, { key: 'location', label: '地点' }, { key: 'status', label: '状态', format: row => statusText(row.status) }, { key: 'created_at', label: '创建时间' }, { key: 'updated_at', label: '更新时间' }];
  if (resource === 'plan') return [{ key: 'title', label: '计划名称' }, { key: 'grade_name', label: '年级' }, { key: 'organizer_dep_name', label: '组织学院' }, { key: 'credit', label: '学分' }, { key: 'participation_mode', label: '参与方式', format: row => row.participation_mode === 'mandatory' ? '必修' : '自愿' }, { key: 'teacher_match_mode', label: '教师匹配', format: row => matchModeText(row.teacher_match_mode) }, { key: 'phase', label: '阶段', format: row => phaseText(row.phase) }, { key: 'status', label: '状态', format: row => statusText(row.status) }, { key: 'practice_start_at', label: '实践开始' }, { key: 'practice_end_at', label: '实践结束' }];
  return common;
}

function phaseText(value) { return { ready: '准备中', enrolling: '报名中', active: '实施中', scoring: '评分中', archived: '已归档' }[value] || value || '-'; }
function modeText(value) { return value === 'distributed' ? '分散实践' : '集中实践'; }
function matchModeText(value) { return { student_choose: '学生选择', admin_assign: '管理员分配', mixed: '混合匹配' }[value] || value || '-'; }
function materialTypeText(value) { return materialTypeOptions.find(item => item.value === value)?.label || value || '-'; }

const workflowResources = ['plan', 'project', 'implementation', 'declaration', 'material', 'patch_sign', 'score'];
const dateFields = ['register_start_at', 'register_end_at', 'practice_start_at', 'practice_end_at', 'result_deadline_at', 'score_deadline_at', 'start_at', 'end_at'];
const statusMap = { draft: '草稿', wait: '待审核', accept: '已通过', modify: '需修改', enabled: '正常', active: '进行中', archived: '已归档', pending: '待确认', accepted: '已确认', rejected: '已拒绝' };
const statusOptions = [{ label: '草稿', value: 'draft' }, { label: '待审核', value: 'wait' }, { label: '已通过', value: 'accept' }, { label: '需修改', value: 'modify' }, { label: '进行中', value: 'active' }, { label: '已归档', value: 'archived' }];
function defaultRequirements() { return materialTypeOptions.map(item => ({ practice_mode: ['practice_proof', 'social_practice_report'].includes(item.value) ? 'distributed' : ['practice_report', 'practice_photo'].includes(item.value) ? 'centralized' : 'all', requirement_type: item.value, required_flag: item.value !== 'parent_notice', submit_scope: item.value === 'emergency_plan' ? 'project' : 'student' })); }
function defaultScoreRules() { return [{ practice_mode: 'centralized', item_code: 'attendance', item_name: '签到', weight: 20, max_score: 100, sort: 10 }, { practice_mode: 'centralized', item_code: 'performance', item_name: '实践表现', weight: 30, max_score: 100, sort: 20 }, { practice_mode: 'centralized', item_code: 'report', item_name: '实践报告', weight: 50, max_score: 100, sort: 30 }, { practice_mode: 'distributed', item_code: 'attitude', item_name: '实践态度', weight: 20, max_score: 100, sort: 10 }, { practice_mode: 'distributed', item_code: 'result', item_name: '实践成果', weight: 30, max_score: 100, sort: 20 }, { practice_mode: 'distributed', item_code: 'report', item_name: '社会实践报告', weight: 50, max_score: 100, sort: 30 }]; }
</script>

<style scoped>
.social-practice-panel { height: 100%; min-height: 0; display: flex; flex-direction: column; gap: 12px; }
.social-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 18px; padding: 4px 2px 10px; }
.social-panel-head.compact { padding-bottom: 2px; }
.social-panel-head h2 { margin: 0 0 4px; font-size: 20px; letter-spacing: 0; }
.social-panel-head p { margin: 0; color: var(--muted); font-size: 13px; }
.social-panel-actions { display: flex; gap: 8px; }
.social-overview-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
.social-overview-grid article { min-height: 86px; display: flex; align-items: center; gap: 13px; padding: 16px; border: 1px solid var(--line); border-radius: 8px; background: rgba(255,255,255,.92); }
.social-overview-grid small { display: block; color: var(--muted); font-size: 12px; }
.social-overview-grid strong { display: block; margin-top: 4px; font-size: 24px; }
.social-overview-icon { width: 40px; height: 40px; display: grid; place-items: center; border-radius: 8px; background: #edf4ff; color: #1769c2; }
.social-overview-icon.green { background: #eaf7ef; color: #23834d; }.social-overview-icon.teal { background: #e8f8f7; color: #12807d; }.social-overview-icon.amber { background: #fff5df; color: #a56800; }.social-overview-icon.red { background: #fff0f0; color: #bd3a3a; }.social-overview-icon.purple { background: #f4efff; color: #7652b8; }.social-overview-icon.gray { background: #f0f2f5; color: #5c6674; }
.social-flow-band { margin-top: 4px; padding: 18px; border: 1px solid var(--line); border-radius: 8px; background: rgba(255,255,255,.9); }
.social-flow-band header { display: flex; align-items: center; gap: 8px; margin-bottom: 18px; }.social-flow-band > div { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }.social-flow-band > div span { flex: 1 1 120px; min-height: 42px; display: grid; place-items: center; border: 1px solid #cdddf0; border-radius: 7px; color: #245b90; background: #f4f8fd; font-size: 13px; }
.social-practice-panel :deep(.data-list-panel) { flex: 1; min-height: 0; }
.social-plan-import { display: grid; gap: 14px; padding: 18px 20px; }.social-import-upload { position: relative; min-height: 76px; display: grid; place-items: center; border: 1px dashed var(--primary); border-radius: 6px; color: var(--primary); background: color-mix(in srgb, var(--primary) 5%, #fff); cursor: pointer; }.social-import-upload input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }.social-import-upload span { display: flex; align-items: center; gap: 8px; }.social-import-summary { display: flex; align-items: center; gap: 18px; color: var(--muted); font-size: 13px; }
.social-stat-filters { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 10px 0 2px; }.social-stat-filters .el-select { width: 190px; }.social-stat-summary { margin-top: 4px; }.social-stat-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; min-height: 0; overflow: auto; }.social-stat-card { min-width: 0; padding: 14px; border: 1px solid var(--line); border-radius: 8px; background: #fff; }.social-stat-card-wide { grid-column: 1 / -1; }.social-stat-card header { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin-bottom: 10px; }.social-stat-card header small { color: var(--muted); }
.social-practice-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; padding: 18px 20px; overflow: auto; }
.social-practice-form > label { display: grid; gap: 6px; }.social-practice-form > label > span { color: var(--muted); font-size: 12px; }.social-practice-form input:not(.el-select__input):not(.el-input__inner):not([type="checkbox"]):not([type="radio"]):not([type="file"]),.social-practice-form textarea { width: 100%; box-sizing: border-box; border: 1px solid var(--line); border-radius: 6px; background: #fff; font: inherit; }.social-practice-form input:not(.el-select__input):not(.el-input__inner):not([type="checkbox"]):not([type="radio"]):not([type="file"]) { height: var(--control-height); padding: 0 10px; }.social-practice-form textarea { padding: 9px 11px; resize: vertical; line-height: 1.6; }.social-practice-form :deep(.el-input__wrapper),.social-practice-form :deep(.el-select__wrapper),.social-practice-form :deep(.el-input-number) { width: 100%; min-height: var(--control-height); height: var(--control-height); border-radius: var(--control-radius); }.span-2 { grid-column: 1 / -1; }
.social-inline-editor { display: grid; gap: 8px; padding: 12px; border: 1px solid var(--line); border-radius: 7px; background: #fafbfd; }.social-inline-editor > header { display: flex; align-items: center; justify-content: space-between; }.social-inline-editor > header small { color: var(--muted); }
.social-material-files { display: grid; gap: 10px; padding: 12px; border: 1px solid var(--line); border-radius: 7px; background: #fafbfd; }.social-material-files > header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }.social-material-files > input { display: none; }.social-material-files > small { color: var(--muted); }.social-material-file-list { display: grid; gap: 7px; }.social-material-file-list article { min-height: 36px; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 7px 10px; border: 1px solid var(--line); border-radius: 6px; background: #fff; }.social-material-file-list a { min-width: 0; color: #1769c2; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.social-inline-row { display: grid; gap: 8px; align-items: center; }.scope-row { grid-template-columns: 120px repeat(3, minmax(130px, 1fr)) 54px; }.material-row { grid-template-columns: 120px minmax(170px, 1fr) 110px 80px 54px; }.score-row { grid-template-columns: 120px 1fr 1fr 130px 54px; }.teacher-row { grid-template-columns: minmax(220px, 1fr) 130px 130px 54px; }
.social-detail-content { display: grid; gap: 16px; padding: 18px 20px; overflow: auto; }.social-detail-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1px; background: var(--line); border: 1px solid var(--line); }.social-detail-grid article { min-height: 64px; display: grid; align-content: center; gap: 5px; padding: 10px 13px; background: #fff; }.social-detail-grid span { color: var(--muted); font-size: 12px; }.social-detail-grid strong { font-size: 14px; overflow-wrap: anywhere; }.social-detail-block { display: grid; gap: 10px; }.social-detail-block p { margin: 0; padding: 13px; border: 1px solid var(--line); border-radius: 6px; line-height: 1.7; white-space: pre-wrap; }
.social-review-form { display: grid; gap: 16px; padding: 20px; }.social-review-form label { display: grid; gap: 7px; }.social-review-form textarea { border: 1px solid var(--line); border-radius: 6px; padding: 11px; resize: vertical; font: inherit; line-height: 1.6; }.social-review-form small { justify-self: end; color: var(--muted); }
.social-timeline { padding: 20px 24px; overflow: auto; }.social-timeline > article { position: relative; display: grid; grid-template-columns: 18px 1fr; gap: 11px; padding-bottom: 18px; }.social-timeline > article::before { content: ''; position: absolute; left: 8px; top: 17px; bottom: -2px; width: 1px; background: #cbd7e6; }.social-timeline > article:last-child::before { display: none; }.social-timeline-dot { z-index: 1; width: 17px; height: 17px; border: 4px solid #dceaff; border-radius: 50%; background: #2b74c8; box-sizing: border-box; }.social-timeline-main { display: grid; gap: 6px; }.social-timeline-main > header { display: flex; justify-content: space-between; gap: 12px; }.social-timeline-main time,.social-timeline-main > p { color: var(--muted); font-size: 12px; }.social-timeline-main > p { margin: 0; }.social-timeline-main blockquote { margin: 2px 0 0; padding: 9px 11px; border-left: 3px solid #94b8e5; background: #f5f8fc; line-height: 1.55; }.social-review-branch { display: grid; grid-template-columns: 18px 1fr; gap: 7px; margin: 8px 0 0 15px; padding: 10px 12px; border: 1px solid #f0d3a5; border-radius: 6px; background: #fffaf1; }.social-review-branch > span { width: 8px; height: 8px; margin-top: 5px; border-radius: 50%; background: #d28a15; }.social-review-branch p { margin: 4px 0 0; color: #66543a; }
.social-assignment-form,.social-student-assignment { display: grid; gap: 12px; padding: 18px 20px; overflow: auto; }.social-student-assignment > header { display: flex; align-items: center; justify-content: space-between; }.social-student-assignment > header small { color: var(--muted); }.social-score-input { display: flex; align-items: center; justify-content: space-between; gap: 18px; min-height: 40px; padding: 4px 0; border-top: 1px solid var(--line); }
.social-student-list { flex: 1; min-height: 0; overflow: auto; display: grid; align-content: start; gap: 12px; }.social-student-card { padding: 16px; border: 1px solid var(--line); border-radius: 8px; background: rgba(255,255,255,.95); }.social-student-card > header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }.social-student-card > header div { display: grid; gap: 4px; }.social-student-card small { color: var(--muted); }.social-student-card strong { font-size: 16px; }.social-student-card dl { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 14px 0; }.social-student-card dt { color: var(--muted); font-size: 11px; }.social-student-card dd { margin: 3px 0 0; font-size: 13px; }.social-student-card footer { display: flex; justify-content: flex-end; gap: 8px; border-top: 1px solid var(--line); padding-top: 12px; }.social-student-pagination { display: flex; align-items: center; justify-content: space-between; padding: 8px 2px; color: var(--muted); font-size: 12px; }
@media (max-width: 1100px) { .social-overview-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }.social-stat-grid { grid-template-columns: 1fr; }.social-stat-card-wide { grid-column: auto; }.social-detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }.scope-row,.material-row,.score-row,.teacher-row { grid-template-columns: 1fr 1fr; }.social-inline-row > .el-button { justify-self: end; }.social-student-card dl { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
