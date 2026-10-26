<?php

use App\Models\Affiliate;
use App\Models\Category;
use App\Models\Dictionary;
use App\Models\DictionaryCategory;
use App\Models\Method;
use App\Models\Paraphrase;
use App\Models\Setting;
use App\Models\SkipWord;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

// 辞書・言い換え・スキップ語・技法・氷・アフィリエイト・設定の表(#23)に、行を入れて制約と値の持ち方を確かめる。

uses(RefreshDatabase::class)->group('db');

it('TC-23-13: 技法は旧 ID のまま入れられ、その後の自動採番は旧の最大値の次から始まる', function () {
    DB::table('methods')->insert([
        ['id' => 3, 'name' => 'シェイク', 'name_eng' => 'Shake', 'description' => ''],
        ['id' => 6, 'name' => 'ブレンド', 'name_eng' => 'Blend', 'description' => ''],
    ]);

    $added = Method::create(['name' => '新しい技法', 'name_eng' => 'New', 'description' => '説明']);

    expect($added->id)->toBe(7)
        ->and(Method::query()->orderBy('id')->pluck('id')->all())->toBe([3, 6, 7]);
});

it('TC-23-14: 設定の値は DB に暗号化して保存し、モデルからは元の値で読める', function () {
    $setting = Setting::factory()->create(['key' => 'amazon.secret', 'value' => 'ひみつ-🔑-value']);

    $stored = DB::table('settings')->where('id', $setting->id)->value('value');

    expect($stored)->not->toBe('ひみつ-🔑-value')
        ->and($stored)->not->toContain('ひみつ')
        ->and(is_string($stored) ? Crypt::decryptString($stored) : $stored)->toBe('ひみつ-🔑-value')
        ->and(Setting::findOrFail($setting->id)->value)->toBe('ひみつ-🔑-value');
});

it('TC-23-15: 設定の値が null のときは null のまま保存する', function () {
    $setting = Setting::factory()->create(['value' => null]);

    expect(DB::table('settings')->where('id', $setting->id)->value('value'))->toBeNull()
        ->and(Setting::findOrFail($setting->id)->value)->toBeNull();
});

it('TC-23-16: 同じ key の設定は 2 行入れられない', function () {
    Setting::factory()->create(['key' => 'rakuten.app_id']);

    expect(fn () => Setting::factory()->create(['key' => 'rakuten.app_id']))
        ->toThrow(QueryException::class);
});

it('TC-23-17: 設定を変えた人を消すと、設定は残って updated_by が null になる', function () {
    $user = User::factory()->create();
    $setting = Setting::factory()->create(['updated_by' => $user->id]);

    expect($setting->updatedBy?->is($user))->toBeTrue();

    $user->delete();

    expect(Setting::findOrFail($setting->id)->updated_by)->toBeNull();
});

it('TC-23-18: 同じ name の言い換え・スキップ語は 2 行入れられない', function () {
    Paraphrase::create(['name' => 'ホワイト・ラム', 'converted' => 'ラム']);
    SkipWord::create(['name' => '氷']);

    expect(fn () => Paraphrase::create(['name' => 'ホワイト・ラム', 'converted' => 'ホワイトラム']))
        ->toThrow(QueryException::class)
        ->and(fn () => SkipWord::create(['name' => '氷']))
        ->toThrow(QueryException::class)
        ->and(Paraphrase::query()->count())->toBe(1)
        ->and(SkipWord::query()->count())->toBe(1);
});

it('TC-23-19: 項目のある用語辞典の分類は消せない', function () {
    $dictionary = Dictionary::factory()->create();

    expect(fn () => DictionaryCategory::findOrFail($dictionary->dictionary_category_id)->delete())->toThrow(QueryException::class)
        ->and(Dictionary::query()->count())->toBe(1);
});

it('TC-23-20: 1 つのノードに、ノードの行(brand_id が null)は 2 行入れられない', function () {
    $category = Category::factory()->create();
    Affiliate::factory()->create(['category_id' => $category->id]);

    expect(fn () => Affiliate::factory()->create(['category_id' => $category->id]))
        ->toThrow(QueryException::class);
});

it('TC-23-21: 1 つのノードに、ノードの行と銘柄の行を 1 行ずつ、別の銘柄の行もそれぞれ入れられる', function () {
    $category = Category::factory()->create();

    Affiliate::factory()->create(['category_id' => $category->id]);
    Affiliate::factory()->create(['category_id' => $category->id, 'brand_id' => 10]);
    Affiliate::factory()->create(['category_id' => $category->id, 'brand_id' => 11]);

    expect(Affiliate::query()->orderBy('brand_key')->pluck('brand_key')->all())->toBe([0, 10, 11]);
});

it('TC-23-22: 同じノードの同じ銘柄の行は 2 行入れられない', function () {
    $category = Category::factory()->create();
    Affiliate::factory()->create(['category_id' => $category->id, 'brand_id' => 10]);

    expect(fn () => Affiliate::factory()->create(['category_id' => $category->id, 'brand_id' => 10]))
        ->toThrow(QueryException::class);
});

it('TC-23-23: 別のノードなら、それぞれにノードの行を入れられる', function () {
    Affiliate::factory()->count(2)->create();

    expect(Affiliate::query()->count())->toBe(2)
        ->and(Affiliate::query()->pluck('brand_key')->all())->toBe([0, 0]);
});

it('TC-23-24: brand_id を後から null にすると、brand_key も 0 に変わる', function () {
    $affiliate = Affiliate::factory()->create(['brand_id' => 10]);

    $affiliate->update(['brand_id' => null]);

    expect(Affiliate::findOrFail($affiliate->id)->brand_key)->toBe(0);
});

it('TC-23-25: アフィリエイトのあるノードは消せない', function () {
    $affiliate = Affiliate::factory()->create();

    expect(fn () => Category::findOrFail($affiliate->category_id)->delete())->toThrow(QueryException::class)
        ->and(Affiliate::query()->count())->toBe(1);
});
