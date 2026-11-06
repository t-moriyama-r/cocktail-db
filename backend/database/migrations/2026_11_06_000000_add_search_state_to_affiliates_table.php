<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商品の ID を持たない提供元を、名前で探し直すための列(docs/schema.md の「affiliates」。#696)。
 *
 * - *_searched_at: その提供元を最後に名前で探した時刻。探し直す(affiliates:collect --new)のは、ここから 7 日たったもの。
 *   null は、まだ名前で探していない(移した行・ID で取れずに ID を消した行)
 * - *_not_found_reason: その提供元で商品が取れなかった理由(App\Enums\AffiliateNotFoundReason)。取れたら null
 *
 * 今ある行は、どちらも null で入る(まだ探していない扱いで、次の --new から探し直される)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dateTime('amazon_searched_at')->nullable()->after('amazon_fetched_at');
            $table->string('amazon_not_found_reason', 32)->nullable()->after('amazon_searched_at');
            $table->dateTime('rakuten_searched_at')->nullable()->after('rakuten_fetched_at');
            $table->string('rakuten_not_found_reason', 32)->nullable()->after('rakuten_searched_at');
        });
    }

    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropColumn(['amazon_searched_at', 'amazon_not_found_reason', 'rakuten_searched_at', 'rakuten_not_found_reason']);
        });
    }
};
