<?php

namespace app\controller;

use support\Request;
use support\Response;

class IndexController
{
    /**
     * 根据访问环境跳转到 PC 或 H5 入口。
     */
    public function index(Request $request): Response
    {
        $defaultTarget = $this->defaultTarget($request);

        return response($this->redirectPage($defaultTarget), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    /**
     * 根据请求头判断默认前端入口。
     */
    private function defaultTarget(Request $request): string
    {
        $mobileHint = strtolower((string) $request->header('sec-ch-ua-mobile', ''));
        if ($mobileHint === '?1') {
            return '/h5/index.html';
        }

        $userAgent = strtolower((string) $request->header('user-agent', ''));
        if ($userAgent !== '' && preg_match('/android|iphone|ipod|mobile|windows phone|blackberry|mini/i', $userAgent)) {
            return '/h5/index.html';
        }

        return '/pc/index.html';
    }

    /**
     * 生成前端入口跳转页。
     */
    private function redirectPage(string $defaultTarget): string
    {
        $defaultTarget = htmlspecialchars($defaultTarget, ENT_QUOTES, 'UTF-8');

        return <<<EOF
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="referrer" content="same-origin">
  <title>实践管理系统</title>
</head>
<body>
  <noscript>
    <meta http-equiv="refresh" content="0;url={$defaultTarget}">
  </noscript>
  <script>
    (function () {
      var pc = '/pc/index.html';
      var h5 = '/h5/index.html';
      var widths = [
        window.innerWidth || 0,
        document.documentElement ? document.documentElement.clientWidth || 0 : 0,
        screen ? screen.width || 0 : 0
      ].filter(function (value) {
        return value > 0;
      });
      var width = widths.length ? Math.min.apply(Math, widths) : 0;
      var uaMobile = /Android|iPhone|iPod|Mobile|Windows Phone|BlackBerry|Mini/i.test(navigator.userAgent || '');
      var target = (uaMobile || (width > 0 && width < 768)) ? h5 : pc;
      window.location.replace(target + window.location.search + window.location.hash);
    }());
  </script>
</body>
</html>
EOF;
    }

    /**
     * 渲染框架默认视图。
     */
    public function view(Request $request)
    {
        return view('index/view', ['name' => 'webman']);
    }

    /**
     * 返回框架默认 JSON 响应。
     */
    public function json(Request $request)
    {
        return json(['code' => 0, 'msg' => 'ok']);
    }

}
