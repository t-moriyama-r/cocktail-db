<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // mixhost の LiteSpeed の後ろで https を正しく判定するため
        $middleware->trustProxies(at: '*');

        // 管理 API(/api/admin/*)。管理画面はログインで作ったセッションのクッキーで API を呼ぶので、公開の API と違い
        // セッションと CSRF の確かめ(web のグループ)を通してから、ログインしているかを確かめる
        $middleware->group('admin', ['web', 'auth']);
        // ログインの画面は SPA の中にあり、サーバーに login のルートは無い。既定の route('login') への転送をやめ、401 を返させる
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
