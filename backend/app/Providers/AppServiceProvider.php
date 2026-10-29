<?php

namespace App\Providers;

use App\Http\Requests\CocktailSearchRequest;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkeys;
use ReflectionNamedType;

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
        // 条件検索の本文は、mode によって category_ids か category_groups のどちらかが要る(条件付きの必須だけ)ので、
        // Scramble は本文を任意と判断する。本文が無いと必ず 422 になるので、OpenAPI では必須にする(#164)
        Scramble::configure()->withOperationTransformers(function (Operation $operation, RouteInfo $routeInfo): void {
            if (! $operation->requestBodyObject instanceof RequestBodyObject) {
                return;
            }
            foreach ($routeInfo->reflectionAction()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType && $type->getName() === CocktailSearchRequest::class) {
                    $operation->requestBodyObject->required();

                    return;
                }
            }
        });
    }
}
