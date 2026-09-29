# 实施进度

- 设计：已获用户确认；提交 aa6fd8b。
- 工作区：/Users/isle/.codex/worktrees/school-workflow-upgrade/practicalSystem。
- 分支：codex/school-workflow-upgrade；起点 f227935；改动尚未提交。
- Task 1—9：实现及 PC/H5 路由、组件、权限和数据库初始化接线已完成；实习、实验实训、社会实践继续使用固定兼容适配器及原审批规则。
- 基地计划软删除与关联实习任务处理、壁纸/签名文件授权、审批通知 outbox、经费导出当前轮次快照已整合。
- 独立审查问题已修复：FileService 重复上传改为随机物理文件名；无经费查看权限的审批人可按当前有效任务读取申请附件；H5 入口权限判断顺序已调整。
- 验证：全部改动 PHP 文件 `php -l` 通过；PC、H5、desktop 生产构建通过；`cargo check --locked` 通过；结构基准来源哈希与当前结构源匹配；`git diff --check` 通过。未生成或运行测试内容。
- 版本准备：客户端版本为 0.3.7，版本说明已写入 `fontend/desktop/releases/0.3.7.md`；当前 GitHub 最新标签为 v0.3.6。
- 发布未执行：本机 `gh auth status` 未登录；GitHub Actions 还要求配置 `TAURI_SIGNING_PRIVATE_KEY` 和 `TAURI_SIGNING_PRIVATE_KEY_PASSWORD`；尚无三平台签名安装包。
- 服务器升级未执行：未运行学校生产数据库迁移、未访问生产数据；部署 SSH 未认证成功。
- 原工作区用户文档 `44-ONLYOFFICE在线编辑对接设计.md`、`工作周报.md` 未改动。
