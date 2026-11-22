<?php

namespace App\OpenApi;

use App\Http\Middleware\Idempotent;
use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * 冪等キーを受ける保存の経路(ミドルウェア idempotent を付けた経路。#657)に、任意の Idempotency-Key ヘッダを OpenAPI で足す。
 * FE が生成した型でヘッダを付けて送れるようにするため。Scramble はミドルウェアが読むヘッダを推論しない。
 *
 * dedoc/scramble は require-dev なので、本番(composer install --no-dev)ではこのクラスを読み込まない(AppServiceProvider)。
 */
class IdempotencyKeyHeader implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if (! in_array('idempotent', $routeInfo->route->gatherMiddleware(), true)) {
            return;
        }

        $schema = new Schema;
        $schema->type = (new StringType)->format('uuid');
        $parameter = Parameter::make(Idempotent::HEADER, 'header')->setSchema($schema);
        $parameter->description = '送信ごとの識別子(UUID)。同じ値の再送には、保存し直さずに最初に成功した応答を返す(docs/decisions/admin-idempotency.md)';

        $operation->addParameters([$parameter]);
    }
}
