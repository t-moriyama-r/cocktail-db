<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConvertLegacySearchConditionRequest;
use App\Services\LegacySearchCondition;

class LegacySearchConditionController extends Controller
{
    /**
     * 旧サイトが保存した検索条件(旧のカテゴリ・ミドル・技法の ID)を、新しいノード・技法の ID に直して返す。
     *
     * 直し方は App\Services\LegacySearchCondition。対応の無い ID などは捨て、捨てた数を dropped_count に入れる
     * (画面が「戻せなかった条件がある」と出すため。#175)。検索方法・かんたん検索は画面がそのまま移すので受けない。
     *
     * @return array{category_ids: list<int>, method_ids: list<int>, dropped_count: int}
     */
    public function __invoke(ConvertLegacySearchConditionRequest $request, LegacySearchCondition $condition): array
    {
        return $condition->convert(
            $request->legacyIds('categories'),
            $request->legacyIds('middles'),
            $request->legacyIds('methods'),
        );
    }
}
