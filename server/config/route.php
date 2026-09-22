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
use Webman\Route;

Route::add(['GET', 'POST'], '/api/wechat/callback', [WechatCallbackController::class, 'callback']);
Route::disableDefaultRoute(WechatCallbackController::class);

Route::post('/api/wechat/sync-menu', [WechatMenuController::class, 'sync']);
Route::disableDefaultRoute(WechatMenuController::class);
