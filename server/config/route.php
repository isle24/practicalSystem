<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use app\controller\Api\WechatCallbackController;
use app\controller\Api\WechatMenuController;
use app\controller\Api\WechatBindingController;
use Webman\Route;

Route::add(['GET', 'POST'], '/api/wechat/callback', [WechatCallbackController::class, 'callback']);
Route::disableDefaultRoute(WechatCallbackController::class);

Route::post('/api/wechat/sync-menu', [WechatMenuController::class, 'sync']);
Route::disableDefaultRoute(WechatMenuController::class);

Route::get('/api/wechat/binding-status', [WechatBindingController::class, 'status']);
Route::get('/api/wechat/oauth/start', [WechatBindingController::class, 'start']);
Route::get('/api/wechat/oauth/callback', [WechatBindingController::class, 'callback']);
Route::post('/api/wechat/bind', [WechatBindingController::class, 'bind']);
Route::post('/api/wechat/scan-session', [WechatBindingController::class, 'scanSession']);
Route::get('/api/wechat/scan-status', [WechatBindingController::class, 'scanStatus']);
Route::post('/api/wechat/scan-cancel', [WechatBindingController::class, 'scanCancel']);
Route::get('/api/wechat/scan/start', [WechatBindingController::class, 'scanStart']);
Route::get('/api/wechat/scan/callback', [WechatBindingController::class, 'scanCallback']);
Route::get('/api/wechat/scan/context', [WechatBindingController::class, 'scanContext']);
Route::post('/api/wechat/scan/confirm', [WechatBindingController::class, 'scanConfirm']);
Route::disableDefaultRoute(WechatBindingController::class);

Route::post('/api/admin/unbind-wechat', [\app\controller\Api\AdminController::class, 'unbindWechat']);

Route::post('/api/profile/save-notifications', [\app\controller\Api\ProfileController::class, 'saveNotifications']);

Route::get('/api/config/login-page', [\app\controller\Api\ConfigController::class, 'loginPage']);

Route::disableDefaultRoute([\app\controller\Api\AdminController::class, 'unbindWechat']);
Route::disableDefaultRoute([\app\controller\Api\ProfileController::class, 'saveNotifications']);
Route::disableDefaultRoute([\app\controller\Api\ConfigController::class, 'loginPage']);

Route::get('/api/edu-data/period-options', [\app\controller\Api\EduDataController::class, 'periodOptions']);
Route::disableDefaultRoute([\app\controller\Api\EduDataController::class, 'periodOptions']);

Route::get('/api/config/database-schema/options', [\app\controller\Api\DatabaseSchemaController::class, 'options']);
Route::post('/api/config/database-schema/check', [\app\controller\Api\DatabaseSchemaController::class, 'check']);
Route::disableDefaultRoute(\app\controller\Api\DatabaseSchemaController::class);

Route::get('/api/base-visit/options', [\app\controller\Api\BaseVisitController::class, 'options']);
Route::get('/api/base-visit/list', [\app\controller\Api\BaseVisitController::class, 'list']);
Route::get('/api/base-visit/detail', [\app\controller\Api\BaseVisitController::class, 'detail']);
Route::post('/api/base-visit/assign', [\app\controller\Api\BaseVisitController::class, 'assign']);
Route::post('/api/base-visit/schedule', [\app\controller\Api\BaseVisitController::class, 'schedule']);
Route::post('/api/base-visit/record', [\app\controller\Api\BaseVisitController::class, 'record']);
Route::post('/api/base-visit/cancel', [\app\controller\Api\BaseVisitController::class, 'cancel']);
Route::disableDefaultRoute(\app\controller\Api\BaseVisitController::class);

Route::post('/api/internship/remove-base', [\app\controller\Api\InternshipController::class, 'removeBase']);

Route::get('/api/account-import/tasks', [\app\controller\Api\AccountImportController::class, 'tasks']);
Route::get('/api/account-import/detail', [\app\controller\Api\AccountImportController::class, 'detail']);
Route::get('/api/account-import/teacher-template', [\app\controller\Api\AccountImportController::class, 'teacherTemplate']);
Route::post('/api/account-import/teacher-preview', [\app\controller\Api\AccountImportController::class, 'teacherPreview']);
Route::post('/api/account-import/teacher-start', [\app\controller\Api\AccountImportController::class, 'teacherStart']);
Route::post('/api/account-import/student-preview', [\app\controller\Api\AccountImportController::class, 'studentPreview']);
Route::post('/api/account-import/student-start', [\app\controller\Api\AccountImportController::class, 'studentStart']);
Route::post('/api/account-import/retry', [\app\controller\Api\AccountImportController::class, 'retry']);
Route::disableDefaultRoute(\app\controller\Api\AccountImportController::class);

Route::get('/api/archive/profession-template', [\app\controller\Api\ArchiveController::class, 'professionTemplate']);
Route::post('/api/archive/profession-preview', [\app\controller\Api\ArchiveController::class, 'previewProfessionImport']);
Route::post('/api/archive/profession-confirm', [\app\controller\Api\ArchiveController::class, 'confirmProfessionImport']);

Route::get('/api/school-appearance/settings', [\app\controller\Api\SchoolAppearanceController::class, 'settings']);
Route::post('/api/school-appearance/upload-logo', [\app\controller\Api\SchoolAppearanceController::class, 'uploadLogo']);
Route::post('/api/school-appearance/upload-background', [\app\controller\Api\SchoolAppearanceController::class, 'uploadBackground']);
Route::disableDefaultRoute(\app\controller\Api\SchoolAppearanceController::class);

Route::get('/api/internship/plan-delete-impact', [\app\controller\Api\InternshipController::class, 'planDeleteImpact']);
Route::post('/api/internship/remove-plan', [\app\controller\Api\InternshipController::class, 'removePlan']);
Route::disableDefaultRoute([\app\controller\Api\InternshipController::class, 'planDeleteImpact']);
Route::disableDefaultRoute([\app\controller\Api\InternshipController::class, 'removePlan']);

Route::get('/api/workflow/options', [\app\controller\Api\WorkflowController::class, 'options']);
Route::get('/api/workflow/definitions', [\app\controller\Api\WorkflowController::class, 'definitions']);
Route::get('/api/workflow/definition', [\app\controller\Api\WorkflowController::class, 'definition']);
Route::post('/api/workflow/save', [\app\controller\Api\WorkflowController::class, 'save']);
Route::post('/api/workflow/publish', [\app\controller\Api\WorkflowController::class, 'publish']);
Route::get('/api/workflow/preview', [\app\controller\Api\WorkflowController::class, 'preview']);
Route::post('/api/workflow/start', [\app\controller\Api\WorkflowController::class, 'start']);
Route::post('/api/workflow/review', [\app\controller\Api\WorkflowController::class, 'review']);
Route::post('/api/workflow/cancel', [\app\controller\Api\WorkflowController::class, 'cancel']);
Route::get('/api/workflow/history', [\app\controller\Api\WorkflowController::class, 'history']);
Route::get('/api/workflow/inbox', [\app\controller\Api\WorkflowController::class, 'inbox']);
Route::disableDefaultRoute(\app\controller\Api\WorkflowController::class);

Route::get('/api/wallpaper/list', [\app\controller\Api\WallpaperController::class, 'list']);
Route::get('/api/wallpaper/importable', [\app\controller\Api\WallpaperController::class, 'importable']);
Route::post('/api/wallpaper/upload', [\app\controller\Api\WallpaperController::class, 'upload']);
Route::post('/api/wallpaper/import', [\app\controller\Api\WallpaperController::class, 'import']);
Route::post('/api/wallpaper/share', [\app\controller\Api\WallpaperController::class, 'share']);
Route::post('/api/wallpaper/apply', [\app\controller\Api\WallpaperController::class, 'apply']);
Route::disableDefaultRoute(\app\controller\Api\WallpaperController::class);
Route::post('/api/school-appearance/wallpaper', [\app\controller\Api\SchoolAppearanceController::class, 'setWallpaper']);
Route::post('/api/school-appearance/upload-wallpaper', [\app\controller\Api\SchoolAppearanceController::class, 'uploadWallpaper']);

Route::get('/api/signature/current', [\app\controller\Api\SignatureController::class, 'current']);
Route::post('/api/signature/save', [\app\controller\Api\SignatureController::class, 'save']);
Route::post('/api/signature/session', [\app\controller\Api\SignatureController::class, 'session']);
Route::get('/api/signature/session-status', [\app\controller\Api\SignatureController::class, 'sessionStatus']);
Route::post('/api/signature/session-cancel', [\app\controller\Api\SignatureController::class, 'cancelSession']);
Route::disableDefaultRoute(\app\controller\Api\SignatureController::class);

Route::get('/api/workflow-message/settings', [\app\controller\Api\MessageChannelSettingsController::class, 'settings']);
Route::post('/api/workflow-message/settings', [\app\controller\Api\MessageChannelSettingsController::class, 'save']);
Route::disableDefaultRoute(\app\controller\Api\MessageChannelSettingsController::class);

Route::get('/api/expense/options', [\app\controller\Api\ExpenseController::class, 'options']);
Route::get('/api/expense/list', [\app\controller\Api\ExpenseController::class, 'list']);
Route::get('/api/expense/detail', [\app\controller\Api\ExpenseController::class, 'detail']);
Route::post('/api/expense/save', [\app\controller\Api\ExpenseController::class, 'save']);
Route::post('/api/expense/submit', [\app\controller\Api\ExpenseController::class, 'submit']);
Route::post('/api/expense/export', [\app\controller\Api\ExpenseController::class, 'export']);
Route::disableDefaultRoute(\app\controller\Api\ExpenseController::class);

Route::get('/api/export/list', [\app\controller\Api\ExportController::class, 'list']);
Route::get('/api/export/detail', [\app\controller\Api\ExportController::class, 'detail']);
Route::post('/api/export/create', [\app\controller\Api\ExportController::class, 'create']);
Route::post('/api/export/retry', [\app\controller\Api\ExportController::class, 'retry']);
Route::disableDefaultRoute(\app\controller\Api\ExportController::class);
