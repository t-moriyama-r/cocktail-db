<?php

namespace App\Enums;

/**
 * カテゴリ木の根の役割(categories.role。docs/decisions/category-root-role.md。#153)。
 *
 * 旧は種別の ID(m_category.kind)を数字で決め打ちして判定していた。新しい木では種別が根のノードになり ID も
 * 振り直すので、役割の名前で持つ。役割の無い根(旧の蒸留酒・醸造酒・混成酒)は null で、ここには現れない。
 */
enum CategoryRole: string
{
    /** 旧の kind 4 */
    case SoftDrink = 'soft_drink';

    /** 旧の kind 5(ガーニッシュなど) */
    case Other = 'other';
}
