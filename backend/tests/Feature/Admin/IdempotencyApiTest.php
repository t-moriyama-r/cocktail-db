<?php

use App\Http\Middleware\Idempotent;
use App\Models\Brand;
use App\Models\Category;
use App\Models\IdempotencyKey;
use App\Models\User;
use App\Services\CategoryTree;
use App\Support\NameNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

// 管理画面の保存の冪等キー(#657。決まりは docs/decisions/admin-idempotency.md)。今つないでいるのは銘柄の新規
// (POST /api/admin/brands)だけ。旧に冪等キーは無い(旧は再送のたびに作る。旧を動かして確かめてはいない)。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/** 1x1 の PNG と、色だけ違う 1x1 の PNG(中身の違う画像の再送を確かめるため) */
const IDEMPOTENCY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
const IDEMPOTENCY_PNG_OTHER = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==';

const IDEMPOTENCY_KEY = '0b6f3a52-6c1e-4f0e-9a39-3f2d8a1c7e01';

/** 銘柄を置くノード(根の下の 1 段) */
function idempotencyCategory(): Category
{
    $tree = app(CategoryTree::class);
    $make = fn (string $name): array => Category::factory()->make([
        'name' => $name,
        'name_search' => NameNormalizer::normalize($name),
        'name_eng' => $name.'-eng',
    ])->getAttributes();
    $root = $tree->addNode(null, $make('洋酒'));

    return $tree->addNode($root, $make('ラム'));
}

/**
 * 銘柄の新規の正しい入力。
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function idempotencyBrandInput(Category $category, array $overrides = []): array
{
    return array_merge([
        'category_id' => $category->id,
        'name' => 'ハバナクラブ',
        'name_eng' => 'Havana Club',
        'strength' => 40,
        'description' => 'キューバの酒',
        'is_major' => true,
        'is_like' => false,
    ], $overrides);
}

function idempotencyPng(string $base64): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'idempotency-') ?: throw new RuntimeException('一時ファイルを作れない');
    file_put_contents($path, base64_decode($base64, true));

    return new UploadedFile($path, 'a.png', null, null, true);
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('TC-657-01: Idempotency-Key を付けなければ、同じ本文を 2 回送ると 2 件作る(今までどおり)', function () {
    $category = idempotencyCategory();

    $this->postJson('/api/admin/brands', idempotencyBrandInput($category))->assertCreated()
        ->assertHeaderMissing(Idempotent::REPLAYED_HEADER);
    $this->postJson('/api/admin/brands', idempotencyBrandInput($category))->assertCreated();

    expect(Brand::query()->where('name', 'ハバナクラブ')->count())->toBe(2)
        ->and(IdempotencyKey::query()->count())->toBe(0);
});

it('TC-657-02: 同じ Idempotency-Key で 2 回送ると 1 件だけ作り、2 回目は 1 回目と同じ応答を返す', function () {
    $category = idempotencyCategory();
    $headers = [Idempotent::HEADER => IDEMPOTENCY_KEY];

    $first = $this->postJson('/api/admin/brands', idempotencyBrandInput($category), $headers)
        ->assertCreated()
        ->assertHeaderMissing(Idempotent::REPLAYED_HEADER);
    $second = $this->postJson('/api/admin/brands', idempotencyBrandInput($category), $headers)
        ->assertCreated()
        ->assertHeader(Idempotent::REPLAYED_HEADER, 'true')
        ->assertHeader('Content-Type', 'application/json');

    expect($second->json())->toBe($first->json())
        ->and(Brand::query()->where('name', 'ハバナクラブ')->count())->toBe(1)
        ->and($second->json('data.id'))->toBe(Brand::query()->where('name', 'ハバナクラブ')->value('id'));
});

it('TC-657-03: 違う Idempotency-Key なら、同じ本文でも別に作る', function () {
    $category = idempotencyCategory();

    $this->postJson('/api/admin/brands', idempotencyBrandInput($category), [Idempotent::HEADER => IDEMPOTENCY_KEY])->assertCreated();
    $this->postJson('/api/admin/brands', idempotencyBrandInput($category), [Idempotent::HEADER => '7d0c2a8e-1b4f-4c3a-8e5d-2f6a9b0c1d23'])
        ->assertCreated()
        ->assertHeaderMissing(Idempotent::REPLAYED_HEADER);

    expect(Brand::query()->where('name', 'ハバナクラブ')->count())->toBe(2);
});

it('TC-657-04: 同じ Idempotency-Key で中身の違う送信は 422 で、作らず、1 回目の応答も返さない', function () {
    $category = idempotencyCategory();
    $headers = [Idempotent::HEADER => IDEMPOTENCY_KEY];

    $this->postJson('/api/admin/brands', idempotencyBrandInput($category), $headers)->assertCreated();
    $this->postJson('/api/admin/brands', idempotencyBrandInput($category, ['name' => 'バカルディ']), $headers)
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => '冪等キー(Idempotency-Key)は、別の内容の送信に使われています。',
            'errors' => ['idempotency_key' => ['冪等キー(Idempotency-Key)は、別の内容の送信に使われています。']],
        ]);

    expect(Brand::query()->count())->toBe(1)
        ->and(Brand::query()->where('name', 'バカルディ')->exists())->toBeFalse();
});

it('TC-657-05: 検証に落ちた送信は記録を残さず、直して同じ Idempotency-Key で送ると作る', function () {
    $category = idempotencyCategory();
    $headers = [Idempotent::HEADER => IDEMPOTENCY_KEY];

    $this->postJson('/api/admin/brands', idempotencyBrandInput($category, ['name' => '']), $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
    expect(IdempotencyKey::query()->count())->toBe(0);

    $this->postJson('/api/admin/brands', idempotencyBrandInput($category), $headers)
        ->assertCreated()
        ->assertHeaderMissing(Idempotent::REPLAYED_HEADER);

    expect(Brand::query()->where('name', 'ハバナクラブ')->count())->toBe(1)
        ->and(IdempotencyKey::query()->count())->toBe(1);
});

it('TC-657-06: Idempotency-Key は管理者ごとに数え、別の管理者が同じ値を使っても別に作る', function () {
    $category = idempotencyCategory();
    $headers = [Idempotent::HEADER => IDEMPOTENCY_KEY];

    $first = $this->postJson('/api/admin/brands', idempotencyBrandInput($category), $headers)->assertCreated();

    $this->actingAs(User::factory()->create());
    $second = $this->postJson('/api/admin/brands', idempotencyBrandInput($category), $headers)
        ->assertCreated()
        ->assertHeaderMissing(Idempotent::REPLAYED_HEADER);

    expect($second->json('data.id'))->not->toBe($first->json('data.id'))
        ->and(Brand::query()->where('name', 'ハバナクラブ')->count())->toBe(2);
});

it('TC-657-07: Idempotency-Key が UUID でない・空なら 422 で、作らない', function (string $key, string $message) {
    $category = idempotencyCategory();

    $this->postJson('/api/admin/brands', idempotencyBrandInput($category), [Idempotent::HEADER => $key])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => $message,
            'errors' => ['idempotency_key' => [$message]],
        ]);

    expect(Brand::query()->count())->toBe(0)
        ->and(IdempotencyKey::query()->count())->toBe(0);
})->with([
    'UUID の形でない' => ['not-a-uuid', '冪等キー(Idempotency-Key)は正しい UUID にしてください。'],
    '空' => ['', '冪等キー(Idempotency-Key)を入力してください。'],
]);

it('TC-657-08: 1 回目の後に銘柄を書き換えても、再送は 1 回目の応答を返し、書き換えた値を戻さない', function () {
    $category = idempotencyCategory();
    $headers = [Idempotent::HEADER => IDEMPOTENCY_KEY];

    $first = $this->postJson('/api/admin/brands', idempotencyBrandInput($category), $headers)->assertCreated();
    $brand = Brand::query()->where('id', $first->json('data.id'))->firstOrFail();
    $brand->update(['name' => '書き換えた名前']);

    $this->postJson('/api/admin/brands', idempotencyBrandInput($category), $headers)
        ->assertCreated()
        ->assertHeader(Idempotent::REPLAYED_HEADER, 'true')
        ->assertJsonPath('data.name', 'ハバナクラブ');

    expect($brand->refresh()->name)->toBe('書き換えた名前')
        ->and(Brand::query()->count())->toBe(1);
});

it('TC-657-09: 画像つき(multipart)の再送は 1 件だけ作ってファイルも 1 つだけ置き、別の画像なら 422', function () {
    Storage::fake('public');
    $category = idempotencyCategory();
    $headers = [Idempotent::HEADER => IDEMPOTENCY_KEY, 'Accept' => 'application/json'];
    // multipart では真偽値を文字列で送る(FE と同じ)
    $input = fn (string $png): array => idempotencyBrandInput($category, [
        'is_major' => 'true',
        'is_like' => 'false',
        'image' => idempotencyPng($png),
    ]);

    $first = $this->post('/api/admin/brands', $input(IDEMPOTENCY_PNG), $headers)->assertCreated();
    $this->post('/api/admin/brands', $input(IDEMPOTENCY_PNG), $headers)
        ->assertCreated()
        ->assertHeader(Idempotent::REPLAYED_HEADER, 'true')
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(Brand::query()->count())->toBe(1)
        ->and(Storage::disk('public')->allFiles())->toBe([Brand::query()->value('image')]);

    $this->post('/api/admin/brands', $input(IDEMPOTENCY_PNG_OTHER), $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('idempotency_key');

    expect(Brand::query()->count())->toBe(1)
        ->and(Storage::disk('public')->allFiles())->toHaveCount(1);
});

it('TC-657-10: ログインしていなければ、Idempotency-Key を付けても 401 で、記録を残さない', function () {
    auth()->logout();
    $category = idempotencyCategory();

    $this->postJson('/api/admin/brands', idempotencyBrandInput($category), [Idempotent::HEADER => IDEMPOTENCY_KEY])
        ->assertUnauthorized();

    expect(Brand::query()->count())->toBe(0)
        ->and(IdempotencyKey::query()->count())->toBe(0);
});

it('TC-657-11: 同じ管理者・同じキーの記録は 2 つ作れず、管理者を消すと記録も消える', function () {
    $user = User::factory()->create();
    $row = ['user_id' => $user->id, 'key' => IDEMPOTENCY_KEY, 'fingerprint' => str_repeat('a', 64)];
    IdempotencyKey::query()->create($row);

    expect(fn () => IdempotencyKey::query()->create($row))->toThrow(UniqueConstraintViolationException::class);

    $user->delete();
    expect(IdempotencyKey::query()->count())->toBe(0);
});

it('TC-657-12: OpenAPI では、銘柄の新規に任意の Idempotency-Key ヘッダ(UUID)があり、更新には無い', function () {
    $file = tempnam(sys_get_temp_dir(), 'openapi');
    if ($file === false) {
        throw new RuntimeException('一時ファイルを作れない');
    }
    try {
        expect(Artisan::call('scramble:export', ['--path' => $file]))->toBe(0);
        $document = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        @unlink($file);
    }
    $headers = function (string $path, string $method) use ($document): array {
        $parameters = data_get($document, "paths.{$path}.{$method}.parameters", []);

        return is_array($parameters)
            ? array_values(array_filter($parameters, fn (mixed $parameter): bool => data_get($parameter, 'in') === 'header'))
            : [];
    };

    $store = $headers('/admin/brands', 'post');
    expect($store)->toHaveCount(1)
        ->and(data_get($store[0], 'name'))->toBe('Idempotency-Key')
        ->and(data_get($store[0], 'required', false))->toBeFalse()
        ->and(data_get($store[0], 'schema'))->toBe(['type' => 'string', 'format' => 'uuid'])
        ->and($headers('/admin/brands/{brand}', 'put'))->toBe([]);
});
