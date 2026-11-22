<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveBrandRequest;
use App\Http\Resources\Admin\BrandListItemResource;
use App\Http\Resources\Admin\BrandResource;
use App\Models\Brand;
use App\Services\AdminBrandWriter;
use App\Services\BrandPlacement;
use App\Support\AdminListName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * 管理画面の銘柄(#314。画面は #64)。旧の DetailEditController@brand と、一覧の getBrandListWithCategory の置き換え。
 */
class BrandController extends Controller
{
    /**
     * 1 ページの件数の既定と上限(#284 の 6: 50 件ずつ)。
     */
    public const PER_PAGE = 50;

    public function __construct(private readonly BrandPlacement $placement) {}

    /**
     * 銘柄の一覧。名前の昇順で 1 ページずつ返す。
     *
     * 並びと絞り込みは管理画面のカクテルの一覧(#289)と同じ(旧の一覧の部品 ListComponent.vue を銘柄でも使っていた)。
     * 名前はカタカナをひらがなに、全角の英数字を半角に、大文字を小文字にそろえて比べ、同じなら ID の順。
     * q は名前か英名のどこかに含むものに絞る(英名は大文字・小文字を区別しない)。
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            // 名前か英名のどこかに含む語(#284 の 6。部分一致)
            'q' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::PER_PAGE],
        ]);

        $q = $request->filled('q') ? $request->string('q')->toString() : null;
        $perPage = $request->integer('per_page', self::PER_PAGE);
        $page = $request->integer('page', 1);

        // SQL の照合順序では旧と同じ並びにならないので、一覧に使う列だけを読み、PHP で絞って並べてからページに切る。
        // ID の順に読んで安定な並べ替えをかけ、名前が同じものを ID の順にする
        $brands = Brand::query()
            ->select(['id', 'category_id', 'name', 'name_eng'])
            ->orderBy('id')
            ->get();

        if ($q !== null) {
            $brands = $brands->filter(fn (Brand $b) => AdminListName::matches($q, $b->name, $b->name_eng));
        }

        $brands = $brands
            ->sortBy(fn (Brand $b) => AdminListName::sortKey($b->name), SORT_STRING)
            ->values();

        $pageItems = $brands->forPage($page, $perPage)->values();
        $placements = $this->placement->forBrands($pageItems);
        foreach ($pageItems as $brand) {
            $brand->setAttribute('placement', $placements[$brand->id]);
        }

        $paginator = new LengthAwarePaginator(
            $pageItems,
            $brands->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );

        return BrandListItemResource::collection($paginator->withQueryString());
    }

    /**
     * 名前・英名の重なりの問い合わせ(#632)。銘柄の編集画面で、名前・英名を入れたそばに「同じ名前が存在しています」を出すため。
     * 旧 DetailEditController@duplicate_check の銘柄の分の置き換え。
     *
     * 比べ方は保存の後の warnings と同じ(名前は検索用の名前で、英名はそのまま。所属によらず表の全体で比べる)。
     * exclude_id の銘柄(編集している銘柄)とは比べない。名前・英名は、空なら比べない(どちらか 1 つは要る)。
     *
     * @return array{warnings: list<array{field: string, message: string}>}
     */
    public function duplicates(Request $request, AdminBrandWriter $writer): array
    {
        $request->validate([
            // 名前。英名と、どちらか 1 つは要る
            'name' => ['required_without:name_eng', 'nullable', 'string', 'max:255'],
            // 英名。名前と、どちらか 1 つは要る
            'name_eng' => ['required_without:name', 'nullable', 'string', 'max:255'],
            // 編集している銘柄の ID。新規なら送らない
            'exclude_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return ['warnings' => $writer->nameDuplicates(
            $request->filled('name') ? $request->string('name')->toString() : null,
            $request->filled('name_eng') ? $request->string('name_eng')->toString() : null,
            $request->filled('exclude_id') ? $request->integer('exclude_id') : null,
        )];
    }

    /**
     * 編集で開く銘柄。
     */
    public function show(int $brand): BrandResource
    {
        return $this->resource(Brand::query()->findOrFail($brand));
    }

    /**
     * 銘柄を作る。名前・英名がほかの銘柄と同じなら、保存したうえで warnings に入れて返す。
     */
    public function store(SaveBrandRequest $request, AdminBrandWriter $writer): JsonResponse
    {
        $saved = $writer->save(null, $request);

        return $this->resource($saved)
            ->additional(['warnings' => $writer->duplicateWarnings($saved)])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * 銘柄を書き換える。所属を変えたときは、その銘柄を使う材料・アフィリエイトのノードもそろえる。
     * 名前・英名がほかの銘柄と同じなら、保存したうえで warnings に入れて返す。
     */
    public function update(SaveBrandRequest $request, AdminBrandWriter $writer, int $brand): BrandResource
    {
        $saved = $writer->save(Brand::query()->findOrFail($brand), $request);

        return $this->resource($saved)->additional(['warnings' => $writer->duplicateWarnings($saved)]);
    }

    private function resource(Brand $brand): BrandResource
    {
        $brand->setAttribute('placement', $this->placement->forBrands([$brand])[$brand->id]);

        return BrandResource::make($brand);
    }
}
