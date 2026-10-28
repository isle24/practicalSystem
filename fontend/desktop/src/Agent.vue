<template>
  <main class="agent-shell" :aria-busy="loading">
    <aside class="navigation" aria-label="Agent 导航">
      <div class="brand">
        <span class="brand-mark">A</span>
        <div>
          <strong>Agent</strong>
          <small>实践系统客户端</small>
        </div>
      </div>
      <nav>
        <button
          v-for="entry in navigation"
          :key="entry.id"
          type="button"
          :class="{ selected: section === entry.id }"
          :aria-current="section === entry.id ? 'page' : undefined"
          @click="section = entry.id"
        >
          <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.6"
            aria-hidden="true"
          >
            <path :d="entry.path" />
          </svg>
          {{ entry.label }}
          <span v-if="entry.id === 'tasks' && missedItems" class="count">
            {{ missedItems }}
          </span>
        </button>
      </nav>
      <div class="navigation-footer">
        <span class="status-dot" :class="{ working: busy }"></span>
        {{ statusText }}
        <small v-if="scope">
          学校 {{ scope.school_id }} · 账号 {{ scope.account_id }}
        </small>
        <button
          v-if="!scope"
          type="button"
          :disabled="loading || pending"
          @click="bootstrap"
        >
          重新加载
        </button>
        <button
          v-else
          type="button"
          :disabled="blocked || editorDialog?.open"
          @click="syncProfiles()"
        >
          {{ syncing ? '同步中' : '同步配置' }}
        </button>
      </div>
    </aside>

    <aside
      v-if="section === 'chat'"
      class="conversation-sidebar"
      aria-label="会话列表"
    >
      <div class="sidebar-heading">
        <h2>聊天</h2>
        <button
          type="button"
          class="icon-button"
          aria-label="新建会话"
          title="新建会话"
          :disabled="blocked"
          @click="newConversation"
        >
          ＋
        </button>
      </div>
      <label class="sr-only" for="conversation-search">搜索会话</label>
      <input
        id="conversation-search"
        v-model="search"
        class="conversation-search"
        placeholder="搜索会话"
        type="search"
      />
      <div class="conversation-items">
        <button
          v-for="item in filteredConversations"
          :key="item.id"
          type="button"
          class="conversation-item"
          :class="{ active: item.id === workspace.active_conversation_id }"
          :disabled="blocked"
          @click="selectConversation(item)"
        >
          <strong>{{ item.title || '新会话' }}</strong>
          <span>{{ lastMessage(item) }}</span>
          <small>{{ formatTime(item.updated_at || item.created_at) }}</small>
        </button>
        <p v-if="!filteredConversations.length" class="list-empty">
          {{ search ? '未找到会话' : '新建会话，开始处理任务。' }}
        </p>
      </div>
      <p class="local-label">会话仅加密保存在本机</p>
    </aside>

    <section class="main-panel" :class="{ 'chat-panel': section === 'chat' }">
      <header class="panel-heading">
        <div>
          <span class="eyebrow">独立工作区</span>
          <h1>
            {{
              section === 'chat'
                ? activeConversation?.title || '新会话'
                : sectionLabel
            }}
          </h1>
        </div>
        <div v-if="section === 'chat'" class="header-actions">
          <button
            type="button"
            :disabled="blocked || !activeConversation"
            @click="openEditor('conversation', activeConversation)"
          >
            重命名
          </button>
          <button
            type="button"
            :disabled="blocked || !activeConversation"
            @click="confirmDelete('conversations', activeConversation)"
          >
            删除会话
          </button>
        </div>
        <button
          v-else-if="section === 'models'"
          type="button"
          class="primary"
          :disabled="blocked"
          @click="openEditor('profile')"
        >
          添加提供方
        </button>
        <div v-else-if="section === 'plugins'" class="header-actions">
          <button type="button" :disabled="blocked" @click="importSkill">
            导入 Skill
          </button>
          <button
            type="button"
            class="primary"
            :disabled="blocked"
            @click="openEditor('plugin')"
          >
            添加 MCP
          </button>
        </div>
        <button
          v-else-if="['memories', 'tasks', 'reminders'].includes(section)"
          type="button"
          class="primary"
          :disabled="blocked"
          @click="openEditor(section)"
        >
          {{
            section === 'memories'
              ? '添加记忆'
              : section === 'tasks'
                ? '创建任务'
                : '添加提醒'
          }}
        </button>
        <button type="button" :disabled="!menuGuide" @click="menuGuideDialog?.showModal()">操作说明</button>
      </header>
      <div class="feedback" aria-live="polite">
        <p v-if="error" class="notice error" role="alert">{{ error }}</p>
        <p v-if="cloudError" class="notice" role="status">
          云端同步暂不可用：{{ cloudError }} 本机配置仍可保存和使用。
        </p>
        <p v-if="notice" class="notice" role="status">{{ notice }}</p>
      </div>

      <template v-if="section === 'chat'">
        <div class="model-toolbar">
          <label for="chat-provider">模型来源</label>
          <select
            id="chat-provider"
            v-model="source"
            :disabled="blocked"
            @change="sourceChanged"
          >
            <option value="school" :disabled="!school.enabled">
              学校统一模型{{ school.enabled ? '' : '（未启用）' }}
            </option>
            <option
              v-if="
                source !== 'school' &&
                !enabledProfiles.some((item) => item.id === source)
              "
              :value="source"
              disabled
            >
              当前提供方（已停用或移除）
            </option>
            <option
              v-for="item in enabledProfiles"
              :key="item.id"
              :value="item.id"
            >
              {{ item.name || '个人提供方' }}
            </option>
          </select>
          <label class="sr-only" for="chat-model">当前模型</label>
          <input
            id="chat-model"
            v-model="model"
            :list="source === 'school' ? undefined : 'chat-model-options'"
            :readonly="source === 'school'"
            :disabled="blocked"
            placeholder="选择或输入模型"
          />
          <datalist id="chat-model-options">
            <option
              v-for="item in chatModels"
              :key="item.id"
              :value="item.id"
            />
          </datalist>
          <button
            v-if="source !== 'school'"
            type="button"
            :disabled="blocked || listingChatModels"
            @click="loadChatModels"
          >
            {{ listingChatModels ? '获取中' : '获取模型' }}
          </button>
        </div>
        <p v-if="providerChanged" class="context-note">
          切换在下一轮发送时生效；当前可见会话将作为上下文发送给所选提供方。
        </p>
        <div
          ref="messageList"
          class="message-list"
          tabindex="0"
          aria-label="会话消息"
        >
          <div
            v-if="!history.length && !activeText && !busy"
            class="empty-state"
          >
            <div class="empty-mark">A</div>
            <h2>有什么需要处理？</h2>
            <p>选择模型，开始对话或使用已授权的学校功能。</p>
            <div class="capability-chips">
              <span>本机加密会话</span>
              <span>个人模型直连</span>
              <span>写入单独确认</span>
            </div>
          </div>
          <article
            v-for="(message, index) in history"
            :key="index"
            class="message"
            :class="message.role"
          >
            <h3>{{ message.role === 'user' ? '你' : 'Agent' }}</h3>
            <p>{{ message.text }}</p>
          </article>
          <article v-if="busy || activeText" class="message assistant">
            <h3>
              Agent
              <span v-if="busy" class="muted">处理中</span>
            </h3>
            <p>{{ activeText || '正在处理任务…' }}</p>
          </article>
          <details v-if="operations.length" class="operation-receipts" open>
            <summary>操作回执（{{ operations.length }}）</summary>
            <p>本机记录最后已知状态；请在学校页面核对最新结果。浏览器需登录对应账号。</p>
            <p v-if="operationStorageError" role="alert">{{ operationStorageError }}</p>
            <div class="operation-receipt-list">
              <article v-for="item in operations" :key="item.operation_id">
                <strong>{{ item.label || item.capability_id }}</strong>
                <span>{{ operationStatus(item.state) }} · {{ formatTime(item.updated_at) }}</span>
                <small>{{ item.origin }} · 账号 ID：{{ item.account_id }}</small>
                <code>{{ item.operation_id }}</code>
                <template v-if="receiptUrl(item)">
                  <input :value="receiptUrl(item)" readonly aria-label="完整回执地址" />
                  <div class="operation-receipt-actions">
                    <button type="button" @click="openOperation(item)">查看回执</button>
                    <button type="button" @click="copyOperation(item)">复制地址</button>
                  </div>
                </template>
                <small v-else>请切换到此操作所属的学校账号后查阅回执。</small>
              </article>
            </div>
          </details>
        </div>
        <div class="chat-bottom">
          <div class="privacy-strip">
            <span>
              本机记忆 {{ enabledMemories }} 条 · 已启用插件
              {{ enabledPlugins }} 个
            </span>
            <button type="button" @click="section = 'memories'">
              管理记忆
            </button>
          </div>
          <form class="composer" @submit.prevent="send">
            <label class="sr-only" for="agent-prompt">任务内容</label>
            <textarea
              id="agent-prompt"
              ref="promptInput"
              v-model="prompt"
              rows="3"
              maxlength="12000"
              placeholder="输入任务，Enter 发送，Shift + Enter 换行"
              :disabled="!scope || loading || pending || busy"
              @keydown="handlePromptKeydown"
            ></textarea>
            <div class="composer-footer">
              <span>
                {{ prompt.length }} / 12000
                <span v-if="toolStatus">· {{ toolStatus }}</span>
              </span>
              <button
                v-if="busy"
                type="button"
                :disabled="pending || !runId"
                @click="stop"
              >
                {{ stopping ? '正在停止' : '停止' }}
              </button>
              <button v-else type="submit" class="primary" :disabled="!canSend">
                发送
              </button>
            </div>
          </form>
          <div class="chat-footnote">
            <span>会话、记忆和插件凭据仅加密保存在本机。</span>
            <button type="button" :disabled="blocked" @click="reconnect">
              连接业务工具
            </button>
          </div>
        </div>
      </template>

      <div v-else class="page-content">
        <template v-if="section === 'models'">
          <p class="section-description">
            学校模型由管理员配置。个人提供方配置先加密保存在本机，点击“同步配置”后双向同步云端；模型请求由本机直连。会话和记忆不参与配置同步。
          </p>
          <section
            v-if="conflicts.length"
            class="sync-conflicts"
            aria-label="配置同步冲突"
          >
            <div class="item-heading">
              <h2>选择需要保留的配置</h2>
              <span class="muted">{{ conflicts.length }} 项冲突</span>
            </div>
            <p class="muted">
              两端配置均有变更，尚未覆盖。选择保留的一侧后再提交；未选择的冲突继续保留。
            </p>
            <article
              v-for="conflict in conflicts"
              :key="conflict.id"
              class="item-card"
            >
              <h3>{{ conflict.local?.name || conflict.cloud?.name || '个人提供方' }}</h3>
              <div class="conflict-columns">
                <div
                  v-for="side in conflictSides"
                  :key="side.id"
                  class="conflict-side"
                >
                  <label class="check-label">
                    <input
                      v-model="conflictChoices[conflict.id]"
                      type="radio"
                      :name="`conflict-${conflict.id}`"
                      :value="side.id"
                      :disabled="blocked"
                    />
                    保留{{ side.label }}
                  </label>
                  <dl v-if="conflict[side.id]" class="conflict-details">
                    <dt>名称</dt>
                    <dd>{{ conflict[side.id].name || '未命名' }}</dd>
                    <dt>地址</dt>
                    <dd>{{ conflict[side.id].endpoint || '未配置' }}</dd>
                    <dt>模型</dt>
                    <dd>{{ conflict[side.id].model || '未选择' }}</dd>
                    <dt>状态</dt>
                    <dd>{{ conflict[side.id].enabled ? '已启用' : '已停用' }}</dd>
                    <dt>密钥</dt>
                    <dd>{{ conflict[side.id].has_key ? '已保存，不回显' : '未保存' }}</dd>
                  </dl>
                  <span v-else class="muted">
                    此侧已删除，保留此侧会同步删除另一侧配置。
                  </span>
                </div>
              </div>
            </article>
            <div class="item-actions">
              <button
                type="button"
                :disabled="blocked || !selectedConflictCount"
                @click="syncProfiles(true)"
              >
                {{ syncing ? '同步中' : `同步已选 ${selectedConflictCount} 项` }}
              </button>
            </div>
          </section>
          <article class="item-card">
            <div class="item-heading">
              <div>
                <h2>学校统一模型</h2>
                <p>{{ school.model || '尚未配置' }}</p>
              </div>
              <span class="badge">
                {{ school.enabled ? '已启用' : '未启用' }}
              </span>
            </div>
          </article>
          <article v-for="item in profiles" :key="item.id" class="item-card">
            <div class="item-heading">
              <div>
                <h2>
                  {{ item.name || '个人模型' }}
                  <span v-if="item.id === 'legacy'" class="badge">
                    旧版兼容
                  </span>
                  <span v-if="item.pending_sync" class="badge">待同步</span>
                </h2>
                <p>{{ item.model || '未选择模型' }}</p>
              </div>
              <span class="badge">
                {{ item.enabled ? '已启用' : '已停用' }}
              </span>
            </div>
            <p class="endpoint">{{ item.endpoint }}</p>
            <div class="item-footer">
              <span class="muted">
                {{ item.has_key ? '密钥已在本机加密保存' : '未保存密钥' }}
              </span>
              <div class="item-actions">
                <button
                  type="button"
                  :disabled="blocked"
                  @click="probeProfile(item)"
                >
                  检查连接
                </button>
                <button
                  type="button"
                  :disabled="blocked"
                  @click="openEditor('profile', item)"
                >
                  {{ item.id === 'legacy' ? '复制为独立配置' : '编辑' }}
                </button>
                <button
                  v-if="item.id !== 'legacy'"
                  type="button"
                  :disabled="blocked"
                  @click="confirmDelete('profiles', item)"
                >
                  删除
                </button>
              </div>
            </div>
          </article>
          <p v-if="!profiles.length" class="list-empty">
            添加个人提供方后，可在聊天中选择不同的模型。
          </p>
        </template>

        <template v-if="section === 'plugins'">
          <p class="section-description">
            Skill 提供指令与文本资源。MCP
            提供外部工具，每次调用由原生窗口确认。本机命令以当前用户权限运行。
          </p>
          <article
            v-for="item in workspace.plugins"
            :key="item.id"
            class="item-card"
          >
            <div class="item-heading">
              <div>
                <h2>
                  {{ item.name }}
                  <span class="badge">
                    {{ item.type === 'skill' ? 'Skill' : 'MCP' }}
                  </span>
                  <span v-if="item.builtin" class="badge">内置</span>
                </h2>
                <p>
                  {{
                    item.description ||
                    (item.transport === 'stdio' ? '本机 stdio' : item.url)
                  }}
                </p>
              </div>
              <label class="check-label">
                <input
                  type="checkbox"
                  :checked="item.enabled"
                  :disabled="blocked"
                  @change="toggleItem('plugins', item, $event.target.checked)"
                />
                启用
              </label>
            </div>
            <div class="item-footer">
              <span class="muted">
                {{
                  item.type === 'skill'
                    ? `${Object.keys(item.resources || {}).length} 项文本资源`
                    : item.has_token
                      ? '访问凭据已保存'
                      : '外部工具服务'
                }}
              </span>
              <div class="item-actions">
                <button
                  v-if="item.type === 'mcp'"
                  type="button"
                  :disabled="blocked"
                  @click="probeMcp(item)"
                >
                  连接并列出工具
                </button>
                <button
                  type="button"
                  :disabled="blocked"
                  @click="
                    openEditor(item.type === 'skill' ? 'skill' : 'plugin', item)
                  "
                >
                  {{ item.type === 'skill' ? '查看指令' : '编辑' }}
                </button>
                <button
                  v-if="!item.builtin"
                  type="button"
                  :disabled="blocked"
                  @click="confirmDelete('plugins', item)"
                >
                  删除
                </button>
              </div>
            </div>
            <div v-if="mcpResults[item.id]" class="probe-results">
              <p>{{ mcpResults[item.id].message }}</p>
              <ul v-if="mcpResults[item.id].tools?.length">
                <li v-for="tool in mcpResults[item.id].tools" :key="tool.name">
                  <strong>{{ tool.name }}</strong>
                  <span>{{ tool.description }}</span>
                </li>
              </ul>
            </div>
          </article>
          <p v-if="!workspace.plugins.length" class="list-empty">
            导入 SKILL.md 或文本资源包，或添加一个 MCP 工具服务。
          </p>
        </template>

        <template v-if="section === 'memories'">
          <p class="section-description">
            启用的记忆会加入后续对话上下文，并发送给当前选择的模型提供方。仅在当前学校与账号的本机工作区保存。
          </p>
          <article
            v-for="item in workspace.memories"
            :key="item.id"
            class="item-card"
          >
            <div class="item-heading">
              <h2>{{ item.title }}</h2>
              <label class="check-label">
                <input
                  type="checkbox"
                  :checked="item.enabled"
                  :disabled="blocked"
                  @change="toggleItem('memories', item, $event.target.checked)"
                />
                启用
              </label>
            </div>
            <p class="preserve-text">{{ item.content }}</p>
            <div class="item-footer">
              <span class="muted">{{ formatTime(item.updated_at) }}</span>
              <div class="item-actions">
                <button
                  type="button"
                  :disabled="blocked"
                  @click="openEditor('memories', item)"
                >
                  编辑
                </button>
                <button
                  type="button"
                  :disabled="blocked"
                  @click="confirmDelete('memories', item)"
                >
                  删除
                </button>
              </div>
            </div>
          </article>
          <p v-if="!workspace.memories.length" class="list-empty">
            添加常用背景或偏好，供后续对话使用。
          </p>
        </template>

        <template v-if="section === 'tasks' || section === 'reminders'">
          <p class="section-description">
            仅在客户端已登录且解锁时运行。关闭、休眠或锁定期间的到期项目会记为错过，由你决定补跑或跳过。定时
            Agent 仅执行只读任务，不启用 MCP。
          </p>
          <article
            v-for="item in workspace[section]"
            :key="item.id"
            class="item-card"
          >
            <div class="item-heading">
              <div>
                <h2>{{ item.name }}</h2>
                <p>
                  {{
                    item.schedule === 'interval'
                      ? `每 ${item.interval_minutes} 分钟`
                      : '单次'
                  }}
                  · 下次 {{ formatTime(item.next_run_at) }}
                </p>
              </div>
              <label class="check-label">
                <input
                  type="checkbox"
                  :checked="item.enabled"
                  :disabled="blocked"
                  @change="toggleItem(section, item, $event.target.checked)"
                />
                启用
              </label>
            </div>
            <p class="preserve-text">{{ item.prompt || item.content }}</p>
            <div class="item-footer">
              <span class="muted">{{ scheduleStatus(item) }}</span>
              <div class="item-actions">
                <button
                  v-if="item.missed_count > 0 || item.last_status === 'missed'"
                  type="button"
                  :disabled="blocked"
                  @click="runScheduled(section, item)"
                >
                  补跑一次
                </button>
                <button
                  v-if="item.missed_count > 0 || item.last_status === 'missed'"
                  type="button"
                  :disabled="blocked"
                  @click="
                    workspaceEdit(
                      section,
                      'skip',
                      null,
                      item.id,
                      '已跳过错过项目。'
                    )
                  "
                >
                  跳过
                </button>
                <button
                  type="button"
                  :disabled="blocked"
                  @click="openEditor(section, item)"
                >
                  编辑
                </button>
                <button
                  type="button"
                  :disabled="blocked"
                  @click="confirmDelete(section, item)"
                >
                  删除
                </button>
              </div>
            </div>
          </article>
          <p v-if="!workspace[section].length" class="list-empty">
            {{
              section === 'tasks'
                ? '创建本机只读 Agent 定时任务。'
                : '创建只在本机提示的提醒。'
            }}
          </p>
        </template>

        <template v-if="section === 'status'">
          <p class="section-description">
            当前账号的本机工作区状态与最近运行记录。
          </p>
          <div class="status-grid">
            <article class="item-card">
              <h2>客户端</h2>
              <p>{{ statusText }}</p>
              <p v-if="scope" class="muted">{{ scope.origin }}</p>
            </article>
            <article class="item-card">
              <h2>存储范围</h2>
              <p>本机加密 · 学校与账号隔离</p>
              <p class="muted">
                {{ workspace.conversations.length }} 个会话 ·
                {{ workspace.memories.length }} 条记忆
              </p>
            </article>
          </div>
          <h2 class="list-title">最近事件</h2>
          <ol class="event-list">
            <li
              v-for="(event, index) in [...workspace.events].reverse()"
              :key="event.id || index"
            >
              <time>
                {{ formatTime(event.created_at || event.at || event.time) }}
              </time>
              <div>
                <strong>{{ event.name || event.type || '工作区事件' }}</strong>
                <span class="badge">{{ statusLabel(event.status) }}</span>
                <p>
                  {{
                    event.message ||
                    event.detail ||
                    event.error ||
                    event.content ||
                    ''
                  }}
                </p>
              </div>
            </li>
          </ol>
          <p v-if="!workspace.events.length" class="list-empty">
            暂无运行记录。
          </p>
        </template>
      </div>
    </section>

    <dialog
      ref="editorDialog"
      class="editor-dialog"
      :aria-labelledby="'editor-title'"
      @close="editorClosed"
    >
      <form @submit.prevent="saveEditor">
        <header class="dialog-heading">
          <h2 id="editor-title">{{ editorTitle }}</h2>
          <button
            type="button"
            aria-label="关闭"
            :disabled="pending"
            @click="closeEditor"
          >
            关闭
          </button>
        </header>
        <div class="editor-body">
          <p v-if="editorError" class="notice error" role="alert">
            {{ editorError }}
          </p>
          <template v-if="editorType === 'profile'">
            <p class="muted">
              保存仅更新本机加密配置，点击“同步配置”后才上传或下载云端变更。
            </p>
            <label>
              提供方名称
              <input
                v-model="draft.name"
                required
                maxlength="80"
                autocomplete="off"
              />
            </label>
            <label>
              基础 URL
              <input
                v-model="draft.endpoint"
                type="url"
                required
                maxlength="500"
                placeholder="https://api.example.com/v1"
                autocomplete="off"
                spellcheck="false"
                @input="modelOptions = []"
              />
            </label>
            <p class="muted">
              兼容完整 /chat/completions 地址。HTTP 仅用于可信内网。
            </p>
            <p v-if="endpointChanged" class="inline-warning">
              地址已变更，需要重新输入密钥，避免将原密钥发送给其他主机。
            </p>
            <label>
              API Key
              <span class="muted">
                {{
                  editingItem?.has_key
                    ? '已保存，地址不变时留空保留'
                    : '尚未保存'
                }}
              </span>
              <input
                v-model="draft.api_key"
                type="password"
                maxlength="2048"
                autocomplete="new-password"
                spellcheck="false"
                :disabled="draft.clear_key"
              />
            </label>
            <label v-if="editingItem?.has_key" class="check-label">
              <input v-model="draft.clear_key" type="checkbox" />
              清除已保存密钥
            </label>
            <label>
              默认模型
              <div class="input-action">
                <input
                  v-model="draft.model"
                  list="profile-model-options"
                  required
                  maxlength="120"
                  placeholder="选择或手动输入模型"
                  autocomplete="off"
                />
                <button
                  type="button"
                  :disabled="pending || modelLoading"
                  @click="loadEditorModels"
                >
                  {{ modelLoading ? '获取中' : '获取模型' }}
                </button>
              </div>
            </label>
            <datalist id="profile-model-options">
              <option
                v-for="item in modelOptions"
                :key="item.id"
                :value="item.id"
              />
            </datalist>
            <p class="muted">接口未实现模型列表时，可手动填写模型名称。</p>
            <label class="check-label">
              <input v-model="draft.enabled" type="checkbox" />
              启用提供方
            </label>
          </template>
          <template v-else-if="editorType === 'conversation'">
            <label>
              会话名称
              <input v-model="draft.title" required maxlength="120" />
            </label>
          </template>
          <template v-else-if="editorType === 'memories'">
            <label>
              标题
              <input v-model="draft.title" required maxlength="120" />
            </label>
            <label>
              记忆内容
              <textarea
                v-model="draft.content"
                required
                rows="8"
                maxlength="12000"
              ></textarea>
            </label>
            <label class="check-label">
              <input v-model="draft.enabled" type="checkbox" />
              用于后续会话
            </label>
          </template>
          <template v-else-if="editorType === 'skill'">
            <p class="muted">
              {{
                editingItem?.description ||
                'Skill 指令只作为文本上下文，不执行包内脚本。'
              }}
            </p>
            <pre class="skill-content">{{ editingItem?.content }}</pre>
            <details
              v-for="resource in editingItem?.resources || []"
              :key="resource.path"
            >
              <summary>{{ resource.path }}</summary>
              <pre class="skill-content">{{ resource.content }}</pre>
            </details>
          </template>
          <template v-else-if="editorType === 'plugin'">
            <label>
              服务名称
              <input v-model="draft.name" required maxlength="80" />
            </label>
            <label>
              连接方式
              <select v-model="draft.transport">
                <option value="http">Streamable HTTP</option>
                <option value="stdio">本机 stdio</option>
              </select>
            </label>
            <template v-if="draft.transport === 'http'">
              <label>
                服务 URL
                <input
                  v-model="draft.url"
                  type="url"
                  required
                  autocomplete="off"
                  spellcheck="false"
                  placeholder="https://example.com/mcp"
                />
              </label>
              <label>
                访问令牌
                <span v-if="editingItem?.has_token" class="muted">
                  已保存，留空保留
                </span>
                <input
                  v-model="draft.token"
                  type="password"
                  autocomplete="new-password"
                  spellcheck="false"
                />
              </label>
            </template>
            <template v-else>
              <label>
                可执行程序
                <input
                  v-model="draft.command"
                  required
                  autocomplete="off"
                  spellcheck="false"
                  placeholder="/absolute/path/to/program"
                />
              </label>
              <label>
                参数（JSON 字符串数组）
                <textarea
                  v-model="draft.args_text"
                  rows="3"
                  spellcheck="false"
                  placeholder='["--option", "value"]'
                ></textarea>
              </label>
              <label>
                环境变量（JSON 对象）
                <textarea
                  v-model="draft.env_text"
                  rows="3"
                  autocomplete="off"
                  spellcheck="false"
                  placeholder='{"NAME":"value"}'
                ></textarea>
              </label>
              <p class="muted">
                已保存的环境变量不回显，命令和参数不变时留空保留，填写 {}
                可清空。更改命令或参数后需重新填写。请勿在命令参数中填写凭据。添加或启用命令时由原生窗口显示完整命令并确认。
              </p>
              <div class="command-preview">
                <strong>命令预览</strong>
                <code>{{ draft.command }} {{ draft.args_text }}</code>
              </div>
            </template>
            <label class="check-label">
              <input v-model="draft.enabled" type="checkbox" />
              启用服务
            </label>
          </template>
          <template
            v-else-if="editorType === 'tasks' || editorType === 'reminders'"
          >
            <label>
              名称
              <input v-model="draft.name" required maxlength="120" />
            </label>
            <label>
              {{ editorType === 'tasks' ? '只读任务内容' : '提醒内容' }}
              <textarea
                v-model="draft.content"
                required
                rows="5"
                maxlength="12000"
              ></textarea>
            </label>
            <template v-if="editorType === 'tasks'">
              <label>
                模型来源
                <select
                  v-model="draft.source"
                  @change="draft.model = defaultModel(draft.source)"
                >
                  <option value="school" :disabled="!school.enabled">
                    学校统一模型
                  </option>
                  <option
                    v-for="item in enabledProfiles"
                    :key="item.id"
                    :value="item.id"
                  >
                    {{ item.name }}
                  </option>
                </select>
              </label>
              <label>
                模型
                <input
                  v-model="draft.model"
                  required
                  :readonly="draft.source === 'school'"
                  maxlength="120"
                />
              </label>
            </template>
            <div class="form-grid">
              <label>
                计划
                <select v-model="draft.schedule">
                  <option value="once">单次</option>
                  <option value="interval">按间隔</option>
                </select>
              </label>
              <label v-if="draft.schedule === 'interval'">
                间隔（分钟）
                <input
                  v-model.number="draft.interval_minutes"
                  required
                  type="number"
                  min="5"
                  step="1"
                />
              </label>
            </div>
            <label>
              {{ draft.schedule === 'interval' ? '首次运行时间' : '运行时间' }}
              <input
                v-model="draft.local_time"
                required
                type="datetime-local"
              />
            </label>
            <p class="muted">
              使用本机时区；间隔至少 5 分钟。客户端关闭、锁定或休眠时暂停执行。
            </p>
            <label class="check-label">
              <input v-model="draft.enabled" type="checkbox" />
              启用
            </label>
          </template>
        </div>
        <footer class="dialog-actions">
          <button type="button" :disabled="pending" @click="closeEditor">
            {{ editorType === 'skill' ? '关闭' : '取消' }}
          </button>
          <button
            v-if="editorType !== 'skill'"
            type="submit"
            class="primary"
            :disabled="pending || modelLoading"
          >
            {{
              pending ? '保存中' : editorType === 'profile' ? '保存到本机' : '保存'
            }}
          </button>
        </footer>
      </form>
    </dialog>
    <dialog
      ref="confirmDialog"
      class="confirm-dialog"
      aria-labelledby="confirm-title"
    >
      <h2 id="confirm-title">{{ confirmation.title }}</h2>
      <p>{{ confirmation.description }}</p>
      <div class="dialog-actions">
        <button type="button" autofocus @click="confirmDialog.close()">
          取消
        </button>
        <button type="button" class="primary" @click="executeConfirmation">
          确认
        </button>
      </div>
    </dialog>
    <dialog ref="menuGuideDialog" class="menu-guide-dialog" aria-labelledby="menu-guide-title">
      <template v-if="menuGuide">
        <header><h2 id="menu-guide-title">{{ menuGuide.title }}操作说明</h2><button type="button" autofocus @click="menuGuideDialog.close()">关闭</button></header>
        <p>{{ menuGuide.purpose }}</p>
        <h3>开始前</h3><ul><li v-for="(item, index) in menuGuide.prerequisites" :key="index">{{ item }}</li></ul>
        <h3>当前怎么操作</h3><ol><li v-for="(step, index) in menuGuide.steps" :key="index"><strong>{{ step.action }}</strong><p>完成标志：{{ step.expected }}</p><p>下一步：{{ step.next }}</p></li></ol>
        <h3>完成后做什么</h3><ul><li v-for="(item, index) in menuGuide.next" :key="index">{{ item }}</li></ul>
        <h3>遇到问题</h3><ul><li v-for="(item, index) in menuGuide.issues" :key="index">{{ item }}</li></ul>
      </template>
    </dialog>
  </main>
</template>

<script setup>
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  reactive,
  ref,
  watch
} from 'vue';
import menuGuides from '../../shared/operation-guides.json';
const navigation = [
  {
    id: 'chat',
    label: '聊天',
    path: 'M21 11a8 8 0 0 1-8 8H5l-3 3V11a9 9 0 0 1 18 0Z M7 10h8M7 14h5'
  },
  {
    id: 'models',
    label: '模型',
    path: 'm12 2 9 5v10l-9 5-9-5V7l9-5Zm0 0v10m9-5-9 5-9-5m9 5v10'
  },
  {
    id: 'plugins',
    label: '插件',
    path: 'M8 3H3v5h3a3 3 0 1 1 0 6H3v7h7v-3a3 3 0 1 1 6 0v3h5v-7h-3a3 3 0 1 1 0-6h3V3h-7v3a3 3 0 1 1-6 0V3Z'
  },
  {
    id: 'memories',
    label: '记忆',
    path: 'M8 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-3M8 2h8v4H8V2Zm-1 9h10M7 15h10'
  },
  {
    id: 'tasks',
    label: '定时任务',
    path: 'M12 8v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'
  },
  {
    id: 'reminders',
    label: '提醒',
    path: 'M5 9a7 7 0 0 1 14 0v7l2 3H3l2-3V9Zm5 13h4'
  },
  { id: 'status', label: '状态', path: 'M3 12h4l3-8 4 16 3-8h4' }
];
const emptyWorkspace = () => ({
  active_conversation_id: '',
  conversations: [],
  memories: [],
  plugins: [],
  tasks: [],
  reminders: [],
  events: []
});
const section = ref('chat'),
  scope = ref(null),
  profiles = ref([]),
  school = ref({ enabled: false, model: '' });
const menuGuideDialog = ref(null);
const menuGuide = computed(() => menuGuides.find(item => item.key === `agent.${section.value}`));
const conflicts = ref([]),
  conflictChoices = reactive({}),
  syncing = ref(false),
  cloudError = ref('');
const conflictSides = [
  { id: 'local', label: '本机' },
  { id: 'cloud', label: '云端' }
];
const workspace = reactive(emptyWorkspace());
const history = ref([]),
  prompt = ref(''),
  source = ref('school'),
  model = ref(''),
  search = ref('');
const activeText = ref(''),
  runId = ref(''),
  busy = ref(false),
  loading = ref(true),
  pending = ref(false),
  stopping = ref(false);
const error = ref(''),
  notice = ref(''),
  toolStatus = ref('');
const editorDialog = ref(null),
  confirmDialog = ref(null),
  messageList = ref(null),
  promptInput = ref(null);
const editorType = ref(''),
  editingItem = ref(null),
  draft = reactive({}),
  editorError = ref(''),
  modelOptions = ref([]),
  modelLoading = ref(false),
  chatModels = ref([]),
  listingChatModels = ref(false);
const mcpResults = reactive({}),
  confirmation = reactive({ title: '', description: '', action: null });
let generation = 0,
  modelGeneration = 0,
  chatModelGeneration = 0,
  disposed = false,
  bootstrapping = false,
  polling = false,
  pollTimer,
  statusTimer;
let mutationRevision = 0;
const requests = new Set(),
  channels = new Map(),
  promptDrafts = new Map();
function cancelChannel(name) {
  channels.get(name)?.abort();
  channels.delete(name);
}
watch(
  () => [draft.endpoint, draft.api_key],
  () => {
    modelGeneration++;
    modelLoading.value = false;
    modelOptions.value = [];
    cancelChannel('editor-models');
  }
);
const sectionLabel = computed(
  () => navigation.find((item) => item.id === section.value)?.label || 'Agent'
);
const activeConversation = computed(() =>
  workspace.conversations.find(
    (item) => item.id === workspace.active_conversation_id
  )
);
const operations = computed(() => (Array.isArray(activeConversation.value?.operations)
  ? activeConversation.value.operations : []).slice(-50).reverse());
const operationStorageError = ref('');

function receiptUrl(item) {
  if (!scope.value || item.origin !== scope.value.origin
    || item.school_id !== scope.value.school_id || item.account_id !== scope.value.account_id
    || !/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(item.operation_id || '')) return '';
  try {
    const url = new URL(item.receipt_url);
    if (url.protocol !== 'https:' || url.origin !== scope.value.origin || url.username || url.password
      || url.pathname !== '/pc/index.html' || url.hash || url.searchParams.size !== 1
      || url.searchParams.get('cli_operation') !== item.operation_id) return '';
    return url.href;
  } catch { return ''; }
}

function operationStatus(state) {
  return ({ preparing: '预览请求已准备', pending: '等待确认', approved: '已批准，等待执行',
    executing: '执行中，结果待核对', completed: '执行完成', failed: '执行失败', unknown: '结果待核对',
    denied: '已拒绝', cancelled: '已取消', expired: '已过期' })[state] || '状态待核对';
}

function applyOperationState(value) {
  operationStorageError.value = text(value?.operation_storage_error);
  const item = value?.operation;
  const conversation = workspace.conversations.find(entry => entry.id === value?.conversation_id);
  if (!item?.operation_id || !conversation) return;
  conversation.operations = [...(Array.isArray(conversation.operations) ? conversation.operations : [])
    .filter(entry => entry.operation_id !== item.operation_id), item].slice(-50);
}

async function openOperation(item) {
  if (!receiptUrl(item)) return;
  try { await request('open-operation', { operation_id: item.operation_id }); }
  catch (reason) { error.value = reason.message; }
}

async function copyOperation(item) {
  const url = receiptUrl(item);
  if (!url) return;
  try { await navigator.clipboard.writeText(url); notice.value = '回执地址已复制。'; }
  catch { notice.value = '未能访问剪贴板，请选择上方回执地址手动复制。'; }
}
const filteredConversations = computed(() =>
  workspace.conversations
    .filter((item) =>
      (item.title || '新会话')
        .toLowerCase()
        .includes(search.value.toLowerCase())
    )
    .slice()
    .sort((a, b) => (b.updated_at || 0) - (a.updated_at || 0))
);
const enabledProfiles = computed(() =>
  profiles.value.filter((item) => item.enabled)
);
const selectedConflictCount = computed(
  () =>
    conflicts.value.filter((item) =>
      ['local', 'cloud'].includes(conflictChoices[item.id])
    ).length
);
const enabledMemories = computed(
  () => workspace.memories.filter((item) => item.enabled).length
);
const enabledPlugins = computed(
  () => workspace.plugins.filter((item) => item.enabled).length
);
const missedItems = computed(
  () =>
    workspace.tasks.filter(
      (item) => item.missed_count > 0 || item.last_status === 'missed'
    ).length
);
const blocked = computed(
  () => !scope.value || loading.value || pending.value || busy.value
);
const canSend = computed(
  () =>
    !blocked.value &&
    prompt.value.trim() &&
    model.value.trim() &&
    (source.value === 'school'
      ? school.value.enabled
      : enabledProfiles.value.some((item) => item.id === source.value))
);
const statusText = computed(() =>
  loading.value
    ? '正在加载'
    : syncing.value
      ? '正在同步配置'
      : busy.value
        ? '任务运行中'
        : scope.value
          ? '已连接'
          : '等待登录解锁'
);
const providerChanged = computed(
  () =>
    history.value.length &&
    activeConversation.value &&
    (activeConversation.value.mode !==
      (source.value === 'school' ? 'school' : 'personal') ||
      (source.value !== 'school' &&
        activeConversation.value.profile_id !== source.value))
);
const endpointChanged = computed(
  () =>
    editorType.value === 'profile' &&
    editingItem.value?.has_key &&
    draft.endpoint?.trim() !== editingItem.value.endpoint
);
const editorTitle = computed(
  () =>
    ({
      profile:
        editingItem.value?.id && editingItem.value.id !== 'legacy'
          ? '编辑提供方'
          : '添加提供方',
      conversation: '重命名会话',
      memories: editingItem.value ? '编辑记忆' : '添加记忆',
      plugin: editingItem.value ? '编辑 MCP 服务' : '添加 MCP 服务',
      skill: editingItem.value?.name || 'Skill 指令',
      tasks: editingItem.value ? '编辑定时任务' : '创建定时任务',
      reminders: editingItem.value ? '编辑提醒' : '添加提醒'
    })[editorType.value]
);
const text = (value) => (typeof value === 'string' ? value : '');
function readHistory(value) {
  return Array.isArray(value)
    ? value
        .filter(
          (item) =>
            ['user', 'assistant'].includes(item?.role) &&
            typeof item.text === 'string'
        )
        .slice(-60)
    : [];
}
function defaultModel(id) {
  return id === 'school'
    ? school.value.model
    : profiles.value.find((item) => item.id === id)?.model || '';
}
function formatTime(value) {
  const n = Number(value);
  return n > 0
    ? new Date(n < 1e12 ? n * 1000 : n).toLocaleString('zh-CN', {
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
      })
    : '暂无';
}
function lastMessage(item) {
  const messages = readHistory(item.history);
  return (
    messages.at(-1)?.text?.slice(0, 48) ||
    (item.model ? item.model : '等待开始')
  );
}
function statusLabel(status) {
  return (
    {
      pending: '等待运行',
      running: '运行中',
      done: '已完成',
      completed: '已完成',
      error: '失败',
      cancelled: '已停止',
      missed: '已错过',
      skipped: '已跳过'
    }[status] ||
    status ||
    '尚未运行'
  );
}
function scheduleStatus(item) {
  return `${statusLabel(item.last_status)}${item.last_run_at ? ` · 上次 ${formatTime(item.last_run_at)}` : ''}${item.missed_count > 0 ? ` · 错过 ${item.missed_count} 次` : ''}`;
}
function localDate(value) {
  const date = new Date(
    (Number(value) || Math.floor(Date.now() / 1000) + 600) * 1000
  );
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 16);
}
function clearDraft() {
  for (const key of Object.keys(draft)) delete draft[key];
  modelOptions.value = [];
  modelGeneration++;
  cancelChannel('editor-models');
  editorError.value = '';
  editingItem.value = null;
}
function wipe() {
  generation++;
  modelGeneration++;
  chatModelGeneration++;
  clearTimeout(pollTimer);
  for (const controller of requests) controller.abort();
  requests.clear();
  channels.clear();
  promptDrafts.clear();
  scope.value = null;
  profiles.value = [];
  resetConflicts();
  syncing.value = false;
  cloudError.value = '';
  school.value = { enabled: false, model: '' };
  Object.assign(workspace, emptyWorkspace());
  history.value = [];
  prompt.value = '';
  activeText.value = '';
  runId.value = '';
  busy.value = false;
  pending.value = false;
  loading.value = false;
  toolStatus.value = '';
  operationStorageError.value = '';
  source.value = 'school';
  model.value = '';
  chatModels.value = [];
  for (const key of Object.keys(mcpResults)) delete mcpResults[key];
  editorDialog.value?.close();
  confirmDialog.value?.close();
  clearDraft();
  notice.value = '';
}
async function request(action, payload = {}, channel = '') {
  const version = generation,
    controller = new AbortController();
  requests.add(controller);
  if (channel) {
    cancelChannel(channel);
    channels.set(channel, controller);
  }
  const timer = setTimeout(
    () => controller.abort(),
    action === 'sync'
      ? 180000
      : ['models', 'probe', 'probe-mcp'].includes(action)
        ? 45000
        : 30000
  );
  try {
    const response = await fetch('/_desktop/agent', {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, ...payload }),
      signal: controller.signal
    });
    if (disposed || version !== generation) return null;
    const result = await response.json().catch(() => null);
    if (disposed || version !== generation) return null;
    if ([401, 403, 423].includes(response.status)) {
      wipe();
      error.value =
        response.status === 423
          ? '客户端已锁定，请解锁后重试。'
          : response.status === 403
            ? text(result?.message) || '当前学校或账号无法访问，请重新读取登录状态。'
            : '登录已失效，请重新登录。';
      return null;
    }
    if (!response.ok)
      throw new Error(
        text(result?.message) || `请求失败（${response.status}）`
      );
    if (!result || typeof result !== 'object')
      throw new Error('客户端返回的数据无效。');
    return result;
  } catch (reason) {
    if (disposed || version !== generation) return null;
    throw new Error(
      reason.name === 'AbortError'
        ? action === 'sync'
          ? '同步超时，本机配置仍已保存，请重新点击“同步配置”。'
          : '请求超时，请重试。'
        : reason.message || '无法连接客户端。'
    );
  } finally {
    clearTimeout(timer);
    requests.delete(controller);
    if (channels.get(channel) === controller) channels.delete(channel);
  }
}
function applyWorkspace(value, select = false) {
  const data = value?.workspace || value;
  if (!data || !Array.isArray(data.conversations)) return false;
  const previousSource = source.value,
    previousModel = model.value;
  const previousId = workspace.active_conversation_id;
  const nextId = text(data.active_conversation_id);
  const transitioned = nextId !== previousId;
  if (transitioned && previousId) promptDrafts.set(previousId, prompt.value);
  Object.assign(workspace, {
    active_conversation_id: nextId,
    ...Object.fromEntries(
      [
        'conversations',
        'memories',
        'plugins',
        'tasks',
        'reminders',
        'events'
      ].map((key) => [key, Array.isArray(data[key]) ? data[key] : []])
    )
  });
  const retainedIds = new Set(workspace.conversations.map((item) => item.id));
  for (const id of promptDrafts.keys()) {
    if (!retainedIds.has(id)) promptDrafts.delete(id);
  }
  if (transitioned) prompt.value = promptDrafts.get(nextId) || '';
  if (select || transitioned) {
    history.value = readHistory(activeConversation.value?.history);
    if (activeConversation.value) {
      source.value =
        activeConversation.value.mode === 'personal'
          ? activeConversation.value.profile_id || 'legacy'
          : 'school';
      model.value =
        source.value === 'school'
          ? school.value.model
          : activeConversation.value.model || defaultModel(source.value);
    }
    activeText.value = '';
    chatModels.value = [];
    chatModelGeneration++;
    listingChatModels.value = false;
    cancelChannel('chat-models');
  }
  reconcileSource(previousSource, previousModel);
  return true;
}
function sourceAvailable(id) {
  return id === 'school'
    ? school.value.enabled
    : enabledProfiles.value.some((item) => item.id === id);
}
function reconcileSource(fallbackSource = '', fallbackModel = '') {
  if (!sourceAvailable(source.value)) {
    if (sourceAvailable(fallbackSource)) {
      source.value = fallbackSource;
      model.value = fallbackModel || defaultModel(fallbackSource);
    } else {
      source.value = school.value.enabled
        ? 'school'
        : enabledProfiles.value[0]?.id || 'school';
      model.value = defaultModel(source.value);
    }
  }
  if (source.value === 'school' || !model.value)
    model.value = defaultModel(source.value);
}
function resetConflicts() {
  conflicts.value = [];
  for (const id of Object.keys(conflictChoices)) delete conflictChoices[id];
}
function applyProfiles(value) {
  if (!Array.isArray(value)) throw new Error('本机提供方配置数据无效。');
  const previousSource = source.value,
    previousDefault = defaultModel(previousSource);
  profiles.value = value;
  reconcileSource();
  if (source.value === previousSource && model.value === previousDefault) {
    model.value = defaultModel(source.value);
  }
  if (
    previousSource !== source.value ||
    previousDefault !== defaultModel(source.value)
  ) {
    chatModels.value = [];
    chatModelGeneration++;
    listingChatModels.value = false;
    cancelChannel('chat-models');
  }
}
function applySchool(value) {
  if (!value || typeof value !== 'object') return;
  school.value = { enabled: value.enabled === true, model: text(value.model) };
  if (source.value === 'school') model.value = school.value.model;
}
async function syncProfiles(resolveConflicts = false) {
  if (blocked.value || editorDialog.value?.open) return;
  const resolutions = resolveConflicts
    ? conflicts.value
        .filter((item) => ['local', 'cloud'].includes(conflictChoices[item.id]))
        .map((item) => ({
          id: item.id,
          choice: conflictChoices[item.id],
          local_revision: item.local_revision,
          cloud_revision: item.cloud_revision
        }))
    : [];
  if (resolveConflicts && !resolutions.length) return;
  mutationRevision++;
  pending.value = true;
  syncing.value = true;
  error.value = '';
  notice.value = '';
  try {
    const value = await request(
      'sync',
      resolutions.length ? { resolutions } : {}
    );
    if (!value) return;
    applySchool(value.school);
    applyProfiles(value.profiles);
    resetConflicts();
    conflicts.value = Array.isArray(value.conflicts) ? value.conflicts : [];
    cloudError.value = text(value.cloud_error);
    notice.value = text(value.sync_summary) || '配置同步已结束。';
    if (conflicts.value.length) section.value = 'models';
  } catch (reason) {
    error.value = reason.message;
  } finally {
    syncing.value = false;
    pending.value = false;
  }
}
function applyBootstrap(value) {
  if (!value.scope || typeof value.scope !== 'object')
    throw new Error('当前学校与账号信息不可用。');
  const changed =
    scope.value && JSON.stringify(scope.value) !== JSON.stringify(value.scope);
  if (changed) {
    wipe();
    error.value = '';
  }
  scope.value = value.scope;
  applySchool(value.school);
  cloudError.value = text(value.cloud_error);
  let nextProfiles = Array.isArray(value.profiles)
    ? value.profiles
    : profiles.value;
  if (
    !nextProfiles.length &&
    (value.personal?.endpoint || value.personal?.has_key)
  )
    nextProfiles = [
      { ...value.personal, id: 'legacy', name: '旧版个人配置' }
    ];
  const firstLoad = !workspace.active_conversation_id;
  applyProfiles(nextProfiles);
  applyWorkspace(value.workspace, firstLoad || changed);
  history.value = readHistory(value.history);
  if (
    !model.value &&
    !activeConversation.value?.model &&
    !history.value.length
  ) {
    source.value = school.value.enabled
      ? 'school'
      : enabledProfiles.value[0]?.id || 'school';
    model.value = defaultModel(source.value);
  }
  if (source.value === 'school') model.value = school.value.model;
  const run = value.run;
  applyOperationState(run || value);
  busy.value = run ? run.status === 'running' : value.busy === true;
  runId.value = text(run?.run_id || value.run_id);
  toolStatus.value = text(
    run?.tools_status ||
      run?.tool_status ||
      value.tools_status ||
      value.tool_status
  );
  activeText.value =
    run && ['running', 'error', 'cancelled'].includes(run.status)
      ? text(run.text)
      : '';
  if (value.memory_error) error.value = text(value.memory_error);
  else if (run?.status === 'error')
    error.value = text(run.error) || '任务执行失败。';
  if (busy.value && runId.value) schedulePoll();
  scrollMessages();
}
async function refreshWorkspace(select = false) {
  const value = await request('workspace');
  if (value && !applyWorkspace(value, select))
    throw new Error('工作区数据无效。');
}
async function bootstrap() {
  if (
    disposed ||
    bootstrapping ||
    pending.value ||
    modelLoading.value ||
    listingChatModels.value
  )
    return;
  bootstrapping = true;
  loading.value = true;
  error.value = '';
  try {
    const value = await request('bootstrap');
    if (value) {
      applyBootstrap(value);
      if (!value.workspace && !busy.value)
        await refreshWorkspace(!workspace.active_conversation_id);
    }
  } catch (reason) {
    error.value = reason.message;
  } finally {
    loading.value = false;
    bootstrapping = false;
  }
}
async function perform(
  action,
  payload,
  success,
  { select = false, refresh = false } = {}
) {
  if (blocked.value) return null;
  mutationRevision++;
  pending.value = true;
  error.value = '';
  notice.value = '';
  try {
    const result = await request(action, payload);
    if (!result) return null;
    if (refresh && !applyWorkspace(result, select))
      await refreshWorkspace(select);
    if (success) notice.value = success;
    return result;
  } catch (reason) {
    error.value = reason.message;
    return null;
  } finally {
    pending.value = false;
  }
}
async function workspaceEdit(
  target,
  operation,
  item,
  id,
  success,
  select = false
) {
  return perform(
    'workspace-edit',
    {
      section: target,
      operation,
      ...(item ? { item } : {}),
      ...(id ? { id } : {})
    },
    success,
    { refresh: true, select }
  );
}
async function newConversation() {
  const previousSource = source.value,
    previousModel = model.value;
  const value = await workspaceEdit(
    'conversations',
    'save',
    {
      title: '新会话',
      mode: source.value === 'school' ? 'school' : 'personal',
      profile_id: source.value === 'school' ? '' : source.value,
      model: model.value
    },
    null,
    '已创建会话。',
    true
  );
  if (value) {
    source.value = previousSource;
    model.value =
      previousSource === 'school' ? school.value.model : previousModel;
    section.value = 'chat';
    prompt.value = '';
    await nextTick();
    promptInput.value?.focus();
  }
}
async function selectConversation(item) {
  if (item.id === workspace.active_conversation_id) return;
  const value = await workspaceEdit(
    'conversations',
    'select',
    null,
    item.id,
    '',
    true
  );
  if (value) scrollMessages();
}
function sourceChanged() {
  model.value = defaultModel(source.value);
  chatModels.value = [];
  chatModelGeneration++;
  listingChatModels.value = false;
  cancelChannel('chat-models');
}
async function loadChatModels() {
  if (blocked.value || source.value === 'school') return;
  const version = ++chatModelGeneration,
    selected = source.value;
  listingChatModels.value = true;
  error.value = '';
  try {
    const value = await request(
      'models',
      { profile_id: selected },
      'chat-models'
    );
    if (value && version === chatModelGeneration && selected === source.value) {
      chatModels.value = (value.models || []).filter(
        (item) => typeof item.id === 'string'
      );
      notice.value =
        value.message ||
        (chatModels.value.length
          ? `已获取 ${chatModels.value.length} 个模型。`
          : '未返回模型列表，可手动输入。');
    }
  } catch (reason) {
    if (version === chatModelGeneration)
      error.value = `${reason.message} 可手动输入模型。`;
  } finally {
    if (version === chatModelGeneration) listingChatModels.value = false;
  }
}
function openEditor(type, item = null) {
  if (blocked.value) return;
  clearDraft();
  editorType.value = type;
  editingItem.value = item;
  const common = { enabled: item?.enabled ?? true };
  if (type === 'profile')
    Object.assign(draft, common, {
      name: item?.name || '',
      endpoint: item?.endpoint || '',
      model: item?.model || '',
      api_key: '',
      clear_key: false
    });
  else if (type === 'memories' || type === 'conversation')
    Object.assign(draft, common, {
      title: item?.title || '',
      content: item?.content || ''
    });
  else if (type === 'plugin')
    Object.assign(draft, common, {
      name: item?.name || '',
      transport: item?.transport || 'http',
      url: item?.url || '',
      token: '',
      command: item?.command || '',
      args_text: JSON.stringify(item?.args || []),
      env_text: ''
    });
  else if (['tasks', 'reminders'].includes(type))
    Object.assign(draft, common, {
      name: item?.name || '',
      content: item?.prompt || item?.content || '',
      source: item
        ? item.mode === 'personal'
          ? item.profile_id || 'legacy'
          : 'school'
        : source.value,
      model:
        item?.mode === 'school' || (!item && source.value === 'school')
          ? school.value.model
          : item?.model || defaultModel(source.value),
      schedule: item?.schedule || 'once',
      interval_minutes: item?.interval_minutes || 5,
      local_time: localDate(item?.next_run_at)
    });
  editorDialog.value?.showModal();
  nextTick(() =>
    editorDialog.value?.querySelector('input, textarea, select')?.focus()
  );
}
function closeEditor() {
  if (!pending.value) editorDialog.value?.close();
}
function editorClosed() {
  clearDraft();
  modelLoading.value = false;
}
function profilePayload() {
  if (endpointChanged.value && !draft.api_key?.trim())
    throw new Error('修改接口地址后必须重新输入 API Key。');
  return {
    id: editingItem.value?.id || '',
    ...(editingItem.value?.revision
      ? { revision: editingItem.value.revision }
      : {}),
    name: draft.name.trim(),
    enabled: draft.enabled,
    endpoint: draft.endpoint.trim(),
    model: draft.model.trim(),
    api_key: draft.api_key || '',
    clear_key: draft.clear_key === true
  };
}
async function loadEditorModels() {
  if (pending.value || modelLoading.value) return;
  const version = ++modelGeneration,
    endpoint = draft.endpoint?.trim(),
    key = draft.api_key;
  modelLoading.value = true;
  editorError.value = '';
  try {
    if (!endpoint) throw new Error('请先填写基础 URL。');
    if (endpointChanged.value && !key?.trim())
      throw new Error('接口地址已更改，请先重新输入 API Key。');
    const value = await request(
      'models',
      {
        ...(editingItem.value?.id ? { profile_id: editingItem.value.id } : {}),
        endpoint,
        api_key: key || ''
      },
      'editor-models'
    );
    if (
      value &&
      version === modelGeneration &&
      endpoint === draft.endpoint?.trim() &&
      key === draft.api_key
    ) {
      modelOptions.value = (value.models || []).filter(
        (item) => typeof item.id === 'string'
      );
      if (!modelOptions.value.length)
        editorError.value =
          value.message || '未返回模型列表，请手动填写模型名称。';
    }
  } catch (reason) {
    if (version === modelGeneration)
      editorError.value = `${reason.message} 可手动填写模型名称。`;
  } finally {
    if (version === modelGeneration) modelLoading.value = false;
  }
}
async function saveEditor() {
  if (pending.value || busy.value || loading.value) return;
  editorError.value = '';
  const type = editorType.value,
    item = editingItem.value;
  let payload;
  try {
    if (type === 'profile') payload = profilePayload();
    else if (type === 'conversation')
      payload = { id: item.id, title: draft.title.trim() };
    else if (type === 'memories')
      payload = {
        ...(item ? { id: item.id } : {}),
        title: draft.title.trim(),
        content: draft.content.trim(),
        enabled: draft.enabled
      };
    else if (type === 'plugin') {
      payload = {
        ...(item ? { id: item.id } : {}),
        type: 'mcp',
        name: draft.name.trim(),
        enabled: draft.enabled,
        transport: draft.transport
      };
      if (draft.transport === 'http') {
        if (
          item?.url &&
          item.url !== draft.url.trim() &&
          item.has_token &&
          !draft.token.trim()
        )
          throw new Error('修改服务地址后请重新输入访问令牌。');
        payload.url = draft.url.trim();
        if (draft.token) payload.token = draft.token;
      } else {
        const args = JSON.parse(draft.args_text || '[]');
        if (!Array.isArray(args) || args.some((arg) => typeof arg !== 'string'))
          throw new Error('参数必须是 JSON 字符串数组。');
        payload.command = draft.command.trim();
        payload.args = args;
        if (draft.env_text.trim()) {
          const env = JSON.parse(draft.env_text);
          if (
            !env ||
            Array.isArray(env) ||
            typeof env !== 'object' ||
            Object.values(env).some((value) => typeof value !== 'string')
          )
            throw new Error('环境变量必须是字符串值的 JSON 对象。');
          payload.env = env;
        }
      }
    } else if (['tasks', 'reminders'].includes(type)) {
      const timestamp = Math.floor(new Date(draft.local_time).getTime() / 1000);
      if (!Number.isFinite(timestamp))
        throw new Error('请选择有效的运行时间。');
      if (timestamp <= Date.now() / 1000)
        throw new Error('运行时间必须晚于当前时间。');
      if (
        draft.schedule === 'interval' &&
        (!Number.isInteger(draft.interval_minutes) ||
          draft.interval_minutes < 5)
      )
        throw new Error('运行间隔至少为 5 分钟。');
      payload = {
        ...(item ? { id: item.id } : {}),
        name: draft.name.trim(),
        enabled: draft.enabled,
        schedule: draft.schedule,
        next_run_at: timestamp,
        interval_minutes:
          draft.schedule === 'interval' ? draft.interval_minutes : 0,
        ...(type === 'tasks'
          ? {
              prompt: draft.content.trim(),
              mode: draft.source === 'school' ? 'school' : 'personal',
              profile_id: draft.source === 'school' ? '' : draft.source,
              model: draft.model.trim()
            }
          : { content: draft.content.trim() })
      };
    } else return;
  } catch (reason) {
    editorError.value =
      reason instanceof SyntaxError
        ? 'JSON 格式无效，请检查参数与环境变量。'
        : reason.message;
    return;
  }
  mutationRevision++;
  pending.value = true;
  error.value = '';
  notice.value = '';
  try {
    const value = await request(
      type === 'profile' ? 'save' : 'workspace-edit',
      type === 'profile'
        ? payload
        : {
            section:
              type === 'plugin'
                ? 'plugins'
                : type === 'conversation'
                  ? 'conversations'
                  : type,
            operation: 'save',
            item: payload
          }
    );
    if (!value) return;
    if (type === 'profile') {
      applyProfiles(value.profiles);
      resetConflicts();
    } else if (!applyWorkspace(value)) await refreshWorkspace();
    notice.value = type === 'profile'
      ? '已加密保存到本机，待手动同步云端。'
      : '已保存。';
    editorDialog.value?.close();
    clearDraft();
  } catch (reason) {
    editorError.value = reason.message;
  } finally {
    draft.api_key = '';
    draft.token = '';
    draft.env_text = '';
    pending.value = false;
  }
}
function confirmDelete(target, item) {
  if (blocked.value) return;
  Object.assign(confirmation, {
    title: target === 'conversations' ? '删除会话' : '删除项目',
    description: `确认删除“${item.name || item.title || '此项目'}”？${target === 'conversations' ? '会话文本与上下文将从当前工作区移除。' : target === 'profiles' ? '先从本机移除，点击“同步配置”后再同步云端删除。' : '此操作无法撤销。'}`,
    action: async () => {
      const value =
        target === 'profiles'
          ? await perform(
              'delete-profile',
              { id: item.id, revision: item.revision },
              '提供方已从本机删除，待手动同步云端。'
            )
          : await workspaceEdit(
              target,
              'delete',
              null,
              item.id,
              '已删除。',
              target === 'conversations'
            );
      if (value && target === 'profiles') {
        applyProfiles(value.profiles);
        resetConflicts();
      }
    }
  });
  confirmDialog.value?.showModal();
}
function executeConfirmation() {
  const action = confirmation.action;
  confirmDialog.value?.close();
  confirmation.action = null;
  action?.();
}
async function toggleItem(target, item, enabled) {
  const value = await workspaceEdit(
    target,
    'save',
    item.builtin ? { id: item.id, enabled } : { ...item, enabled },
    null,
    enabled ? '已启用。' : '已停用。'
  );
  if (!value) await refreshWorkspace().catch(() => {});
}
async function importSkill() {
  await perform('import-skill', {}, 'Skill 已导入。', { refresh: true });
}
async function probeProfile(item) {
  const value = await perform('probe', { profile_id: item.id }, '');
  if (value) {
    if (value.ok === false) error.value = value.message || '连接失败。';
    else notice.value = value.message || '连接正常。';
  }
}
async function probeMcp(item) {
  const value = await perform('probe-mcp', { id: item.id }, '');
  if (value)
    mcpResults[item.id] = {
      message:
        value.message || `已连接，返回 ${(value.tools || []).length} 个工具。`,
      tools: value.tools || []
    };
}
async function runScheduled(target, item) {
  const value = await workspaceEdit(
    target,
    'run',
    null,
    item.id,
    '已提交补跑。'
  );
  if (value) await bootstrap();
}
async function send() {
  if (!canSend.value) return;
  const content = prompt.value.trim();
  mutationRevision++;
  if (source.value === 'school') model.value = school.value.model;
  pending.value = true;
  error.value = '';
  notice.value = '';
  activeText.value = '';
  try {
    const value = await request('start', {
      mode: source.value === 'school' ? 'school' : 'personal',
      ...(source.value !== 'school' ? { profile_id: source.value } : {}),
      model: model.value.trim(),
      conversation_id: workspace.active_conversation_id || undefined,
      prompt: content
    });
    if (!value) return;
    if (!text(value.run_id))
      throw new Error('客户端未返回任务编号，请同步配置。');
    history.value = [...history.value, { role: 'user', text: content }].slice(
      -60
    );
    prompt.value = '';
    runId.value = value.run_id;
    busy.value = true;
    if (value.workspace) applyWorkspace(value.workspace);
    schedulePoll();
    scrollMessages();
  } catch (reason) {
    error.value = reason.message;
  } finally {
    pending.value = false;
  }
}
function schedulePoll() {
  clearTimeout(pollTimer);
  if (!disposed && busy.value && runId.value) pollTimer = setTimeout(poll, 600);
}
async function poll() {
  if (!busy.value || !runId.value || disposed) return;
  if (polling) {
    schedulePoll();
    return;
  }
  polling = true;
  const id = runId.value;
  try {
    const value = await request('poll', { run_id: id });
    if (!value || id !== runId.value) return;
    applyWorkspace(value);
    applyOperationState(value);
    activeText.value = text(value.text);
    toolStatus.value = text(value.tools_status || value.tool_status);
    if (value.status === 'running') schedulePoll();
    else if (['done', 'error', 'cancelled'].includes(value.status)) {
      busy.value = false;
      if (Array.isArray(value.history))
        history.value = readHistory(value.history);
      else if (activeText.value)
        history.value = [
          ...history.value,
          { role: 'assistant', text: activeText.value }
        ].slice(-60);
      activeText.value = '';
      if (value.status === 'error')
        error.value = text(value.error) || '任务执行失败。';
      if (value.status === 'cancelled')
        notice.value = text(value.error) || '任务已停止。';
      if (!applyWorkspace(value)) await refreshWorkspace();
    } else throw new Error('任务状态无效，请同步配置。');
    scrollMessages();
  } catch (reason) {
    error.value = reason.message;
    notice.value = '状态同步已暂停，可停止任务或重新同步配置。';
  } finally {
    polling = false;
  }
}
async function stop() {
  if (!runId.value || pending.value) return;
  pending.value = true;
  stopping.value = true;
  error.value = '';
  try {
    const value = await request('cancel', { run_id: runId.value });
    if (value) {
      notice.value = '已请求停止任务。';
      schedulePoll();
    }
  } catch (reason) {
    error.value = reason.message;
  } finally {
    pending.value = false;
    stopping.value = false;
  }
}
async function reconnect() {
  const value = await perform('reconnect', {}, '业务工具将在下次任务连接。');
  if (value) await bootstrap();
}
function handlePromptKeydown(event) {
  if (
    event.key === 'Enter' &&
    !event.shiftKey &&
    !event.isComposing &&
    event.keyCode !== 229
  ) {
    event.preventDefault();
    send();
  }
}
async function scrollMessages() {
  await nextTick();
  if (messageList.value)
    messageList.value.scrollTop = messageList.value.scrollHeight;
}
async function refreshStatus() {
  if (
    disposed ||
    document.hidden ||
    !scope.value ||
    loading.value ||
    bootstrapping ||
    busy.value ||
    pending.value ||
    editorDialog.value?.open ||
    modelLoading.value ||
    listingChatModels.value
  )
    return;
  const revision = mutationRevision,
    selectedSource = source.value,
    selectedModel = model.value,
    conversationId = workspace.active_conversation_id;
  bootstrapping = true;
  try {
    const value = await request('bootstrap');
    if (
      !value ||
      revision !== mutationRevision ||
      document.hidden ||
      busy.value ||
      pending.value ||
      editorDialog.value?.open ||
      selectedSource !== source.value ||
      selectedModel !== model.value ||
      conversationId !== workspace.active_conversation_id
    )
      return;
    applyBootstrap(value);
  } catch (reason) {
    if (revision === mutationRevision) error.value = reason.message;
  } finally {
    bootstrapping = false;
  }
}
function restoreVisibility() {
  if (!document.hidden) bootstrap();
}
onMounted(() => {
  bootstrap();
  statusTimer = setInterval(refreshStatus, 5000);
  document.addEventListener('visibilitychange', restoreVisibility);
});
onBeforeUnmount(() => {
  disposed = true;
  clearInterval(statusTimer);
  wipe();
  document.removeEventListener('visibilitychange', restoreVisibility);
});
</script>

<style scoped>
.operation-receipts { margin: 20px 0; padding: 14px; border: 1px solid var(--line, #d9dce3); border-radius: 8px; }
.operation-receipts summary { cursor: pointer; font-weight: 600; }
.operation-receipts > p { font-size: 12px; line-height: 1.6; }
.operation-receipt-list { max-height: 300px; overflow-y: auto; }
.operation-receipt-list article { display: flex; flex-direction: column; gap: 8px; padding: 14px 0; border-top: 1px solid var(--line, #d9dce3); }
.operation-receipt-list span, .operation-receipt-list small, .operation-receipt-list code { font-size: 12px; overflow-wrap: anywhere; }
.operation-receipt-list input { box-sizing: border-box; width: 100%; min-width: 0; font-size: 12px; }
.operation-receipt-actions { display: flex; flex-wrap: wrap; gap: 8px; }
</style>

<style scoped>
.menu-guide-dialog { box-sizing: border-box; width: min(760px, calc(100vw - 32px)); max-height: 85vh; overflow: auto; padding: 24px; }
.menu-guide-dialog header { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.menu-guide-dialog h2 { margin: 0; font-size: 20px; }
.menu-guide-dialog h3 { margin: 24px 0 8px; font-size: 15px; }
.menu-guide-dialog p, .menu-guide-dialog li { font-size: 14px; line-height: 1.75; overflow-wrap: anywhere; }
.menu-guide-dialog li { margin: 10px 0; }
.menu-guide-dialog li p { margin: 4px 0; }
</style>
