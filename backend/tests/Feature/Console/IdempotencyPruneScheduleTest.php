<?php

use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\Artisan;

// 管理画面の保存の冪等キーの古い記録(idempotency_keys)を、Laravel の scheduler で毎日消す(#763。
// docs/decisions/admin-idempotency.md の 10)。旧に冪等キーは無い。

it('TC-763-04: scheduler に、IdempotencyKey の model:prune が毎日 19:05(UTC。日本時間の 4:05)で 1 つだけ登録される', function () {
    $exitCode = Artisan::call('schedule:list');

    $lines = array_values(array_filter(
        explode("\n", Artisan::output()),
        fn (string $line) => str_contains($line, 'model:prune'),
    ));

    expect($exitCode)->toBe(0)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0])->toContain(class_basename(IdempotencyKey::class))
        ->and($lines[0])->toMatch('/5\s+19\s+\*\s+\*\s+\*/');
});
