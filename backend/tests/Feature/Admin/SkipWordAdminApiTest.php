<?php

use App\Http\Middleware\Idempotent;
use App\Models\IdempotencyKey;
use App\Models\SkipWord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

// 管理 API のスキップ語(/api/admin/skip-words。#316)。
// 旧は自動取り込みの画面から足すだけ(GET /addSkipDB/{name})で、一覧・編集・削除と 422 は旧に無い。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('TC-316-16: 一覧は、すべての語を ID の順で返す', function () {
    $b = SkipWord::query()->create(['name' => 'Ice']);
    $a = SkipWord::query()->create(['name' => 'Garnish']);

    $this->getJson('/api/admin/skip-words')
        ->assertOk()
        ->assertExactJson(['data' => [['id' => $b->id, 'name' => 'Ice'], ['id' => $a->id, 'name' => 'Garnish']]]);
});

it('TC-316-17: 追加すると 201 で保存した語を返し、行が残る', function () {
    $response = $this->postJson('/api/admin/skip-words', ['name' => 'Ice'])->assertCreated();

    $word = SkipWord::query()->sole();
    $response->assertExactJson(['data' => ['id' => $word->id, 'name' => 'Ice']]);
});

it('TC-316-18: 更新すると 200 で書き換えた語を返し、自分の名前のままでも通る', function () {
    $word = SkipWord::query()->create(['name' => 'Ice']);

    $this->putJson("/api/admin/skip-words/{$word->id}", ['name' => 'Crushed Ice'])
        ->assertOk()
        ->assertExactJson(['data' => ['id' => $word->id, 'name' => 'Crushed Ice']]);
    $this->putJson("/api/admin/skip-words/{$word->id}", ['name' => 'Crushed Ice'])->assertOk();

    expect($word->fresh()?->name)->toBe('Crushed Ice');
});

it('TC-316-19: 削除すると 204 で行が消え、無い ID は 404', function () {
    $word = SkipWord::query()->create(['name' => 'Ice']);
    $kept = SkipWord::query()->create(['name' => 'Garnish']);

    $this->deleteJson("/api/admin/skip-words/{$word->id}")->assertNoContent();
    $this->deleteJson("/api/admin/skip-words/{$word->id}")->assertNotFound();
    $this->putJson('/api/admin/skip-words/'.($kept->id + 1), ['name' => 'x'])->assertNotFound();

    expect(SkipWord::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('TC-316-20: ほかの語と重なると 422', function () {
    SkipWord::query()->create(['name' => 'Ice']);
    $other = SkipWord::query()->create(['name' => 'Garnish']);

    $this->postJson('/api/admin/skip-words', ['name' => 'Ice'])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['name']);
    $this->putJson("/api/admin/skip-words/{$other->id}", ['name' => 'Ice'])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['name']);
});

it('TC-316-21: 名前は必須で 256 文字まで', function (string $name, int $status) {
    $this->postJson('/api/admin/skip-words', ['name' => $name])->assertStatus($status);
})->with([
    '空' => ['', 422],
    '256 文字' => [str_repeat('あ', 256), 201],
    '257 文字' => [str_repeat('あ', 257), 422],
]);

it('TC-316-22: ログインしていなければ、スキップ語の API はどれも 401', function (string $method, string $uri) {
    $word = SkipWord::query()->create(['name' => 'Ice']);
    auth()->logout();
    $this->app['auth']->forgetGuards();

    $this->json($method, str_replace('{id}', (string) $word->id, $uri), ['name' => 'x'])->assertUnauthorized();

    expect(SkipWord::query()->pluck('name')->all())->toBe(['Ice']);
})->with([
    '一覧' => ['GET', '/api/admin/skip-words'],
    '追加' => ['POST', '/api/admin/skip-words'],
    '更新' => ['PUT', '/api/admin/skip-words/{id}'],
    '削除' => ['DELETE', '/api/admin/skip-words/{id}'],
]);

// スキップ語の新規の冪等キー(#762。決まりは docs/decisions/admin-idempotency.md)。仕組み(ミドルウェア idempotent)そのものは
// IdempotencyApiTest(#657)で確かめているので、ここではスキップ語の新規につないだことを確かめる。旧に冪等キーは無い(旧を動かして確かめてはいない)。
// OpenAPI のヘッダは、言い換えと一緒に ParaphraseAdminApiTest の TC-762-09 で確かめる。

const SKIP_WORD_IDEMPOTENCY_KEY = '5f1a2c3d-8e9b-4a7c-b6d5-0e2f4a6c8b1d';

it('TC-762-05: Idempotency-Key を付けなければ、同じ本文の 2 回目は今までどおり重なりの 422 で断り、記録を残さない', function () {
    $this->postJson('/api/admin/skip-words', ['name' => 'Ice'])->assertCreated()
        ->assertHeaderMissing(Idempotent::REPLAYED_HEADER);
    $this->postJson('/api/admin/skip-words', ['name' => 'Ice'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    expect(SkipWord::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->count())->toBe(0);
});

it('TC-762-06: 同じ Idempotency-Key で再送すると、スキップ語を 1 件だけ作り、1 回目と同じ応答を返す', function () {
    $headers = [Idempotent::HEADER => SKIP_WORD_IDEMPOTENCY_KEY];

    $first = $this->postJson('/api/admin/skip-words', ['name' => 'Ice'], $headers)
        ->assertCreated()
        ->assertHeaderMissing(Idempotent::REPLAYED_HEADER);
    $second = $this->postJson('/api/admin/skip-words', ['name' => 'Ice'], $headers)
        ->assertCreated()
        ->assertHeader(Idempotent::REPLAYED_HEADER, 'true');

    $word = SkipWord::query()->sole();
    expect($second->json())->toBe($first->json())
        ->and($second->json())->toBe(['data' => ['id' => $word->id, 'name' => 'Ice']])
        ->and(IdempotencyKey::query()->count())->toBe(1);
});

it('TC-762-07: 同じ Idempotency-Key で名前の違う送信は 422 で、作らず、1 回目の応答も返さない', function () {
    $headers = [Idempotent::HEADER => SKIP_WORD_IDEMPOTENCY_KEY];
    $this->postJson('/api/admin/skip-words', ['name' => 'Ice'], $headers)->assertCreated();

    $this->postJson('/api/admin/skip-words', ['name' => 'Crushed Ice'], $headers)
        ->assertUnprocessable()
        ->assertHeaderMissing(Idempotent::REPLAYED_HEADER)
        ->assertExactJson([
            'message' => '冪等キー(Idempotency-Key)は、別の内容の送信に使われています。',
            'errors' => ['idempotency_key' => ['冪等キー(Idempotency-Key)は、別の内容の送信に使われています。']],
        ]);

    expect(SkipWord::query()->pluck('name')->all())->toBe(['Ice']);
});

it('TC-762-08: 検証に落ちた新規(名前が空)は記録を残さず、直して同じ Idempotency-Key で送ると作る', function () {
    $headers = [Idempotent::HEADER => SKIP_WORD_IDEMPOTENCY_KEY];

    $this->postJson('/api/admin/skip-words', ['name' => ''], $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
    expect(IdempotencyKey::query()->count())->toBe(0)
        ->and(SkipWord::query()->count())->toBe(0);

    $this->postJson('/api/admin/skip-words', ['name' => 'Ice'], $headers)
        ->assertCreated()
        ->assertHeaderMissing(Idempotent::REPLAYED_HEADER);

    expect(SkipWord::query()->pluck('name')->all())->toBe(['Ice'])
        ->and(IdempotencyKey::query()->count())->toBe(1);
});
