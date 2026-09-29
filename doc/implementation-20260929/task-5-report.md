# Task 5 壁纸库实现交接

## 文件

- `server/app/model/channel/WallpaperRecord.php`、`WallpaperSchema.php`：壁纸记录、分页与增量迁移。
- `server/database/migrations/20260929_wallpaper.php`：按学校数据库编号执行迁移。
- `server/app/server/wallpaper/WallpaperService.php`、`server/app/controller/Api/WallpaperController.php`：上传、导入、分享、选择及读取。
- `server/app/controller/Api/ProfileController.php`：个人资料保存保留壁纸选择，旧上传入口转入壁纸服务，旧壁纸 URL 仅按本人文件匹配。
- `server/app/model/channel/FileAccessRecord.php`、`server/app/middleware/ProtectedFiles.php`：壁纸按本人、共享或学校默认引用读取，同 URL 的壁纸引用优先鉴权，响应禁止公开缓存。
- `server/app/server/config/SchoolAppearanceService.php`、`server/app/controller/Api/SchoolAppearanceController.php`：学校默认壁纸上传和选择。
- `fontend/pc/src/components/WallpaperLibrary.vue`：独立个人壁纸组件。
- `fontend/pc/src/components/SchoolSettingsPanel.vue`：学校默认壁纸设置。

## 路由

主执行者在 `server/config/route.php` 注册：

| 方法 | 地址 | Controller 方法 |
| --- | --- | --- |
| GET | `/api/wallpaper/list` | `WallpaperController::list` |
| GET | `/api/wallpaper/importable` | `WallpaperController::importable` |
| POST | `/api/wallpaper/upload` | `WallpaperController::upload` |
| POST | `/api/wallpaper/import` | `WallpaperController::import` |
| POST | `/api/wallpaper/share` | `WallpaperController::share` |
| POST | `/api/wallpaper/apply` | `WallpaperController::apply` |
| POST | `/api/school-appearance/wallpaper` | `SchoolAppearanceController::setWallpaper` |
| POST | `/api/school-appearance/upload-wallpaper` | `SchoolAppearanceController::uploadWallpaper` |

## App.vue 接入

在个人设置的壁纸区域挂载 `WallpaperLibrary`，传入 `selection`（个人设置返回的 `desktop` 对象）和 `schoolDefaultUrl`（学校外观返回的 `default_wallpaper_url` 字符串）。组件在应用完成时发出 `applied` 事件，参数与 `desktop` 结构相同：`{ wallpaper_mode: 'school'|'item', wallpaper_id: number|null, wallpaper: string, wallpaper_url: string }`。使用该参数更新桌面状态与本地缓存。`wallpaper_mode: 'school'` 会在每次加载设置时读取最新学校默认文件；无有效文件时 `wallpaper_url` 为 `''`。桌面图片继续以 `center / cover` 显示。学校设置组件已自行调用学校默认壁纸接口，`updated` 事件继续刷新学校外观。

## 权限与迁移

- 所有壁纸记录位于当前学校数据库；上传默认私有，列表只返回本人或本校共享记录。分享、应用、导入均校验当前账号和文件归属。学校默认修改沿用管理员角色及 `config:manage` 校验。
- 默认文件以 `system.default_wallpaper_file_id` 和 `school_appearance` 文件关联保存。原上传者关闭分享后，学校默认仍可访问。其他用户原先选中的壁纸撤回分享后，下次读取个人设置返回学校默认。
- `WallpaperSchema::apply()` 可重复执行。迁移只处理每个账号当前有效桌面配置，且仅处理本人上传的有效图片；历史 `profile` 图片通过 `FileService::copyExistingImage()` 建立独立的 `wallpaper` 文件引用，原头像文件保留。更早未被当前桌面使用的 `profile` 图片不自动公开，可在“历史图片”选择并导入。
- `FileService` 的 `wallpaper` 独立存储范围由签名任务执行者提供。先执行 `php server/database/migrations/20260929_file_storage.php <学校数据库编号>`，再执行 `php server/database/migrations/20260929_wallpaper.php <学校数据库编号>`（均从仓库根目录）。本任务未修改生产数据库。

## 验证

- 壁纸相关 PHP 文件均通过 `php -l`。
- `WallpaperLibrary.vue`、`SchoolSettingsPanel.vue` 均通过 Vue SFC 编译。
- PC Vite 构建输出到 `/tmp/practical-task5-pc-build`，构建成功，未覆盖正式静态目录。
- `git diff --check` 通过。
- 两账号范围、默认图变更及撤回共享的实际界面核对待主执行者完成路由、App.vue 接入和学校数据库迁移后进行。
