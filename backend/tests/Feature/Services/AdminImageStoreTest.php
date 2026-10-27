<?php

use App\Services\AdminImageStore;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

// 管理画面の画像のアップロード(#288)。旧の StorageCommon::saveFromDetailAdmin と、編集のリクエストの
// input_image の規則(nullable|image)の置き換え。
// 画像を上げる管理 API はまだ無い(呼び出しは各 API の issue)ので、管理 API と同じように規則で確かめてから
// AdminImageStore を呼ぶルートをテストの中で置き、HTTP で確かめる。DB は使わない。

/** 1x1 の画像。中身から種類を推定させるため、本物の画像のバイト列にする */
const ADMIN_IMAGE_FIXTURES = [
    'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
    'jpeg' => '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/yQALCAABAAEBAREA/8wABgAQEAX/2gAIAQEAAD8A0s8g/9k=',
    'gif' => 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7',
    'webp' => 'UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==',
];

const ADMIN_IMAGE_FIVE_MEGABYTES = 5 * 1024 * 1024;

beforeEach(function () {
    Storage::fake('public');

    Route::middleware('api')->post('api/admin/__image_probe', function (Request $request, AdminImageStore $store) {
        $request->validate(AdminImageStore::rules());

        return ['image' => $store->update($request->file('image'), $request->boolean('remove_image'), $request->filled('current') ? $request->string('current')->value() : null)];
    });
});

function adminImageBytes(string $type): string
{
    return base64_decode(ADMIN_IMAGE_FIXTURES[$type], true) ?: throw new RuntimeException("{$type} のフィクスチャを読めない");
}

/** 1x1 の 24 ビットの BMP */
function adminBmpBytes(): string
{
    return 'BM'.pack('V', 58)."\0\0\0\0".pack('V', 54)
        .pack('V', 40).pack('V', 1).pack('V', 1).pack('v', 1).pack('v', 24)
        .pack('V', 0).pack('V', 4).pack('V', 2835).pack('V', 2835).pack('V', 0).pack('V', 0)
        ."\0\0\xff\0";
}

/**
 * 上げたファイル。UploadedFile::fake() は種類を名前の拡張子から決めるので、本番と同じく中身から推定させるため、
 * 一時ファイルに書いた本物の UploadedFile にする。
 */
function adminUpload(string $name, string $bytes): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'admin-image-');
    if ($path === false || file_put_contents($path, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('一時ファイルを書けない');
    }
    register_shutdown_function(fn () => @unlink($path));

    return new UploadedFile($path, $name, null, null, true);
}

/** 画像の後ろを詰め物で埋めて、ちょうど $size バイトにする(種類の推定は先頭のバイト列で決まる) */
function adminPaddedPng(int $size): UploadedFile
{
    $bytes = str_pad(adminImageBytes('png'), $size, "\0");
    expect(strlen($bytes))->toBe($size);

    return adminUpload('large.png', $bytes);
}

/**
 * 置いたファイルの一覧
 *
 * @return array<string>
 */
function adminStoredImages(): array
{
    return Storage::disk('public')->allFiles();
}

/**
 * 返事の画像の列の値(置いたファイル名)
 *
 * @param  TestResponse<Response>  $response
 */
function adminStoredName(TestResponse $response): string
{
    $name = $response->json('image');

    return is_string($name) ? $name : throw new RuntimeException('ファイル名が返っていない');
}

it('TC-288-01: PNG を上げると public ディスクの直下にサーバーで付けた名前で置き、そのファイル名を返す', function () {
    $response = $this->post('/api/admin/__image_probe', [
        'image' => adminUpload('my photo.png', adminImageBytes('png')),
    ])->assertOk();

    $name = adminStoredName($response);
    expect($name)->toMatch('/\A[A-Za-z0-9]{40}\.png\z/')
        ->and(adminStoredImages())->toBe([$name])
        ->and(Storage::disk('public')->get($name))->toBe(adminImageBytes('png'));
});

it('TC-288-02: JPEG・GIF・WebP も置ける', function (string $type, string $name) {
    $response = $this->post('/api/admin/__image_probe', [
        'image' => adminUpload($name, adminImageBytes($type)),
    ])->assertOk();

    expect(adminStoredImages())->toBe([$response->json('image')]);
})->with([
    'jpeg' => ['jpeg', 'a.jpg'],
    'gif' => ['gif', 'a.gif'],
    'webp' => ['webp', 'a.webp'],
]);

it('TC-288-03: 差し替えると新しいファイルを置き、前のファイルを消す', function () {
    $old = $this->post('/api/admin/__image_probe', [
        'image' => adminUpload('a.png', adminImageBytes('png')),
    ])->json('image');

    $new = $this->post('/api/admin/__image_probe', [
        'image' => adminUpload('b.jpg', adminImageBytes('jpeg')),
        'current' => $old,
    ])->assertOk()->json('image');

    expect($new)->not->toBe($old)
        ->and(adminStoredImages())->toBe([$new]);
});

it('TC-288-04: 画像でないファイルは、名前が .png でも 422 で、何も置かず前のファイルも消さない', function () {
    Storage::disk('public')->put('old.png', adminImageBytes('png'));

    $this->postJson('/api/admin/__image_probe', [
        'image' => adminUpload('not-image.png', "hello\n"),
        'current' => 'old.png',
    ])->assertStatus(422)->assertJsonValidationErrors('image');

    expect(adminStoredImages())->toBe(['old.png']);
});

it('TC-288-05: 受け付ける種類でない画像(BMP)は 422', function () {
    $this->postJson('/api/admin/__image_probe', [
        'image' => adminUpload('a.bmp', adminBmpBytes()),
    ])->assertStatus(422)->assertJsonValidationErrors('image');

    expect(adminStoredImages())->toBe([]);
});

it('TC-288-06: ちょうど 5MB(5,242,880 バイト)の画像は置ける', function () {
    $name = adminStoredName($this->post('/api/admin/__image_probe', ['image' => adminPaddedPng(ADMIN_IMAGE_FIVE_MEGABYTES)])->assertOk());

    expect(Storage::disk('public')->size($name))->toBe(ADMIN_IMAGE_FIVE_MEGABYTES);
});

it('TC-288-07: 5MB + 1 バイトの画像は 422 で、何も置かない', function () {
    $this->postJson('/api/admin/__image_probe', ['image' => adminPaddedPng(ADMIN_IMAGE_FIVE_MEGABYTES + 1)])
        ->assertStatus(422)->assertJsonValidationErrors('image');

    expect(adminStoredImages())->toBe([]);
});

it('TC-288-08: remove_image を指定すると、画像のファイルを消して null を返す', function (bool $json, mixed $flag) {
    Storage::disk('public')->put('old.png', adminImageBytes('png'));
    Storage::disk('public')->put('other.png', adminImageBytes('png'));
    $payload = ['remove_image' => $flag, 'current' => 'old.png'];

    $response = $json ? $this->postJson('/api/admin/__image_probe', $payload) : $this->post('/api/admin/__image_probe', $payload);

    $response->assertOk()->assertExactJson(['image' => null]);
    expect(adminStoredImages())->toBe(['other.png']);
})->with([
    'JSON の true' => [true, true],
    'フォームの "1"' => [false, '1'],
]);

it('TC-288-09: remove_image が true と読めない値("true" など)は 422 で、ファイルを消さない', function () {
    Storage::disk('public')->put('old.png', adminImageBytes('png'));

    $this->postJson('/api/admin/__image_probe', ['remove_image' => 'true', 'current' => 'old.png'])
        ->assertStatus(422)->assertJsonValidationErrors('remove_image');

    expect(adminStoredImages())->toBe(['old.png']);
});

it('TC-288-10: 新しい画像と remove_image を同時に送ると 422 で、何も置かず前のファイルも消さない', function () {
    Storage::disk('public')->put('old.png', adminImageBytes('png'));

    $this->postJson('/api/admin/__image_probe', [
        'image' => adminUpload('a.png', adminImageBytes('png')),
        'remove_image' => '1',
        'current' => 'old.png',
    ])->assertStatus(422)->assertJsonValidationErrors('image');

    expect(adminStoredImages())->toBe(['old.png']);
});

it('TC-288-11: 画像も remove_image も無ければ、今の値のままでファイルも消さない', function (mixed $flag) {
    Storage::disk('public')->put('old.png', adminImageBytes('png'));
    $payload = ['current' => 'old.png'];
    if ($flag !== null) {
        $payload['remove_image'] = $flag;
    }

    $this->post('/api/admin/__image_probe', $payload)->assertOk()->assertExactJson(['image' => 'old.png']);

    expect(adminStoredImages())->toBe(['old.png']);
})->with([
    'remove_image なし' => [null],
    'remove_image が "0"' => ['0'],
]);

it('TC-288-12: 今の値が外部の URL・パスを含む値なら、差し替え・削除でディスクのファイルを消さない', function (string $current) {
    Storage::disk('public')->put('keep.png', adminImageBytes('png'));
    Storage::disk('public')->put('dir/keep.png', adminImageBytes('png'));

    $new = $this->post('/api/admin/__image_probe', [
        'image' => adminUpload('a.png', adminImageBytes('png')),
        'current' => $current,
    ])->assertOk()->json('image');
    $this->post('/api/admin/__image_probe', ['remove_image' => '1', 'current' => $current])
        ->assertOk()->assertExactJson(['image' => null]);

    expect(adminStoredImages())->toEqualCanonicalizing(['keep.png', 'dir/keep.png', $new]);
})->with([
    '外部の URL' => ['https://example.com/keep.png'],
    'ディレクトリ付き' => ['dir/keep.png'],
    '上の階層' => ['../keep.png'],
]);

it('TC-288-13: 画像が無い行で remove_image を指定しても、null を返してエラーにしない', function () {
    $this->post('/api/admin/__image_probe', ['remove_image' => '1'])->assertOk()->assertExactJson(['image' => null]);

    expect(adminStoredImages())->toBe([]);
});
