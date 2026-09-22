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
Route::disableDefaultRoute(WechatBindingController::class);

Route::post('/api/admin/unbind-wechat', [\app\controller\Api\AdminController::class, 'unbindWechat']);

Route::post('/api/profile/save-notifications', [\app\controller\Api\ProfileController::class, 'saveNotifications']);

Route::disableDefaultRoute([\app\controller\Api\AdminController::class, 'unbindWechat']);
Route::disableDefaultRoute([\app\controller\Api\ProfileController::class, 'saveNotifications']);

Route::get('/api/edu-data/period-options', [\app\controller\Api\EduDataController::class, 'periodOptions']);
Route::disableDefaultRoute([\app\controller\Api\EduDataController::class, 'periodOptions']);

Route::get('/api/config/database-schema/options', [\app\controller\Api\DatabaseSchemaController::class, 'options']);
Route::post('/api/config/database-schema/check', [\app\controller\Api\DatabaseSchemaController::class, 'check']);
Route::disableDefaultRoute(\app\controller\Api\DatabaseSchemaController::class);
