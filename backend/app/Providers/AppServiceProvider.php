<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // laravel/passkeys が置くルート(/passkeys/login・/user/passkeys など)は使わない。パスキーの API は
        // 管理 API の決まり(/api の下・401 と CSRF・OpenAPI に出す)に合わせて routes/api.php に置く(#311)
        Passkeys::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
