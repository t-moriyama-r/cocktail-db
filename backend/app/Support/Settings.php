<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

/**
 * 画面から変える設定(#69)の読み書き。
 *
 * 外部サービスの鍵などを、サーバーの .env を触らずに管理画面から変えられるようにするためのもの。
 * 画面で一度も保存していない項目は、これまでどおり .env に書いた値で動かす(画面を作る前の
 * サーバーや、画面を使わない環境でも同じ設定で動くように)。
 *
 * .env の値は config/settings.php を通して読む。デプロイで `php artisan optimize` が設定を
 * キャッシュすると、config/ の外の env() は null を返すため。
 */
final class Settings
{
    /**
     * 設定の値を返す。画面で保存した値を優先し、無ければ .env の値、どちらにも無ければ $default。
     *
     * 保存した値が null の項目は、画面で値を消した(.env の値に戻した)ものとして .env を見る。
     *
     * 保存した値を復号できないとき(APP_KEY を変えた、など)は、保存した値が無いものとして .env を見る。
     * 設定の 1 項目が読めないだけで画面の表示ごと落とさないため。読めなかったことはログに残す。
     */
    public function get(string $key, ?string $default = null): ?string
    {
        return $this->stored($key) ?? $this->fromEnv($key) ?? $default;
    }

    /**
     * 値を暗号化して保存し、変えた人を記録する。null を保存すると、.env の値に戻る。
     */
    public function set(string $key, ?string $value, ?User $updatedBy = null): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $updatedBy?->id],
        );
    }

    private function stored(string $key): ?string
    {
        $setting = Setting::query()->where('key', $key)->first();
        if ($setting === null) {
            return null;
        }

        try {
            return $setting->value;
        } catch (DecryptException) {
            Log::warning('設定の値を復号できないので、.env の値を使う', ['key' => $key]);

            return null;
        }
    }

    /**
     * キーに「.」を含めても入れ子の配列として辿らないように、config('settings.<key>') ではなく
     * 配列から直接引く。
     */
    private function fromEnv(string $key): ?string
    {
        $value = config('settings', [])[$key] ?? null;

        return $value === null ? null : (string) $value;
    }
}
