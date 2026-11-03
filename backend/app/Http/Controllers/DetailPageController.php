<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\Dictionary;
use App\Support\MetaDescription;
use App\Support\PageTitle;
use App\Support\SocialCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;

/**
 * 詳細ページ(カクテル・カテゴリ・銘柄・用語)の SPA の HTML に、ページごとの title・description・canonical を入れる(#111)。
 * JS を動かさないクローラー・SNS に、ページの名前と中身を渡すため。
 *
 * 無い ID・下書きのカクテルは、SPA の HTML を 404 の状態で返す(画面は #97 の「ページが見つかりません」)。
 * 旧は画面の HTML を 200 で返していた(#111 の旧との差分)。
 *
 * OGP・Twitter カード(#112)も、ここでページごとの値を渡す。og:description は description と同じ文にする。
 */
class DetailPageController extends Controller
{
    public function __construct(private readonly MetaDescription $description) {}

    public function cocktail(string $id): Response
    {
        return $this->page($this->find(Cocktail::query()->published(), $id), '/detail/cocktail/');
    }

    public function category(string $id): Response
    {
        return $this->page($this->find(Category::query(), $id), '/category/');
    }

    public function brand(string $id): Response
    {
        return $this->page($this->find(Brand::query(), $id), '/detail/brand/');
    }

    public function dictionary(string $id): Response
    {
        return $this->page($this->find(Dictionary::query(), $id), '/dictionary/');
    }

    /**
     * ID(数字だけの文字列)で引く。int に収まらない桁の ID は、int にすると上限に丸まるので、どの行にも当たらない。
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel|null
     */
    private function find(Builder $query, string $id): ?Model
    {
        return $query->find((int) $id);
    }

    /**
     * canonical は APP_URL と、そのページのパス(クエリを付けない)。og:url も同じ。
     */
    private function page(Cocktail|Category|Brand|Dictionary|null $model, string $pathPrefix): Response
    {
        if ($model === null) {
            return response()->view('spa', [], 404);
        }

        $description = $this->description->make($model->description);
        $canonical = rtrim(config()->string('app.url'), '/').$pathPrefix.$model->id;

        return response()->view('spa', [
            'head' => [
                'title' => PageTitle::forDetail($model->name, $model->name_eng),
                'description' => $description,
                'canonical' => $canonical,
            ],
            'social' => SocialCard::detail($model->name, $description, $model->image, $canonical),
        ]);
    }
}
