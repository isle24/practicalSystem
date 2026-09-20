# 常用文本预览扩展

## 已确认范围

TXT、LOG、Markdown、CSV、JSON、XML 和常见代码文件使用共享预览入口。旧 DOC/XLS 和 PPT/PPTX 转换暂缓，不修改业务流程。

## 实现约束

- 格式清单由 Vue 与 Rust 共用，客户端沿用权限校验、内容版本校验及默认 8 GiB 缓存。
- 文本按 UTF-8/BOM 识别，兼容 GB18030、UTF-16，提供编码切换、分页搜索、自动换行。
- Markdown 复用 markdown-it 和 DOMPurify，在禁止脚本及外部资源的沙箱显示。
- CSV 使用 Papa Parse 解析引号、逗号、跨行字段，限制预览行列数量。
- 文本读取上限 5 MiB，显示上限 50 万字符，保留下载完整原文件入口。
- 所有文本只读，代码、HTML、XML 不执行；缓存响应使用受控 MIME 和 sandbox CSP。

## 执行步骤

- [x] 共用格式注册表，新增文本及 CSV/Markdown 查看组件。
- [x] 接入 PC/H5 共享入口与 Rust 缓存，限制主动内容。
- [x] 完成前端构建、Rust 编译检查和页面操作核验。
- [ ] 提交源码及前端产物，GitHub Actions 构建 v0.3.3 并核对发布资产。
