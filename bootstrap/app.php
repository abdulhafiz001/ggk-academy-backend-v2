<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// 1. Assign the application instance to the $app variable
$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Apply security headers to all API responses
        $middleware->api(append: [
            \App\Http\Middleware\SecurityHeadersMiddleware::class,
        ]);
        
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'teacher' => \App\Http\Middleware\TeacherMiddleware::class,
            'student' => \App\Http\Middleware\StudentMiddleware::class,
            'form.teacher' => \App\Http\Middleware\FormTeacherMiddleware::class,
            'throttle.login' => \App\Http\Middleware\CustomThrottleMiddleware::class,
        ]);

        $middleware->trustProxies('*',
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create(); 

    // Vercel/Serverless Specific Fix: Use the writable /tmp directory.
    if (isset($_ENV['VERCEL_ENV']) || isset($_ENV['VERCEL'])) {
        // 1. Set the primary storage path to the writable /tmp directory (Fixes Read-Only errors)
        $app->useStoragePath('/tmp/storage');
    
        // 2. CRITICAL FIX: Explicitly set the view compiled path using stable array access.
        // This prevents dependency injection errors when Artisan commands (like optimize:clear)
        // run during the Vercel build, ensuring the path is correctly set to a writable location.
        if ($app->has('config')) {
            $app['config']->set('view.compiled', '/tmp/storage/framework/views');
        }
    }
    
    return $app;