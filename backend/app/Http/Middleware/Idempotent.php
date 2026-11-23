<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 管理画面の保存の冪等キー(#657。決まりは docs/decisions/admin-idempotency.md)。
 *
 * 送信に Idempotency-Key ヘッダ(UUID)が付いていれば、管理者とキーの組で最初に成功した応答を idempotency_keys に残し、
 * 同じキーの再送には、保存し直さずに残した応答をそのまま返す(ヘッダ Idempotent-Replayed: true を足す)。
 * ヘッダが無ければ何もしない(今までどおり送るたびに保存する)。
 *
 * - 記録の行は、保存と同じトランザクションの中で先に作る。同じキーの送信が同時に来ると、後のほうは一意の制約の行ロックで
 *   先のほうが終わるのを待ち、先が成功してコミットすれば、その応答を返す。先が失敗してロールバックすれば、後のほうが保存する
 * - 成功(2xx)しなかった送信は、保存ごとロールバックして記録を残さない。入力を直して同じキーで送り直せる
 * - 同じキーで中身(メソッド・経路・入力。ファイルは中身のハッシュ)の違う送信が来たら、422 で返す(別の保存の応答を返さない)
 *
 * ログインを確かめた後(admin のグループの後)に置く。キーを管理者ごとに数えるため。
 */
final class Idempotent
{
    /** FE が送信ごとに付けるヘッダ */
    public const HEADER = 'Idempotency-Key';

    /** 残した応答を返したときに足すヘッダ */
    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    /** 422 の errors の項目名 */
    private const FIELD = 'idempotency_key';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get(self::HEADER);
        if ($key === null) {
            return $next($request);
        }
        $this->validateKey($key);

        $user = $request->user();
        if (! $user instanceof User) {
            // admin のグループの後に置くので、ここには来ない。置き方を誤ったときに、キーを管理者の区別なく数えないよう止める
            abort(401);
        }
        $userId = $user->id;
        $fingerprint = $this->fingerprint($request);

        $connection = DB::connection();
        $level = $connection->transactionLevel();
        $connection->beginTransaction();
        try {
            try {
                $record = IdempotencyKey::query()->create([
                    'user_id' => $userId,
                    'key' => $key,
                    'fingerprint' => $fingerprint,
                ]);
            } catch (UniqueConstraintViolationException) {
                // 同じキーで成功した送信がもうある(同時に来たときは、先の送信のコミットを待ってからここに来る)
                $connection->rollBack($level);

                return $this->replay($userId, $key, $fingerprint);
            }

            $response = $next($request);
            $body = $response->getContent();
            if (! $response->isSuccessful() || $body === false) {
                $connection->rollBack($level);

                return $response;
            }

            $record->fill([
                'response_status' => $response->getStatusCode(),
                'response_content_type' => $response->headers->get('Content-Type'),
                'response_body' => $body,
            ])->save();
            $connection->commit();

            return $response;
        } catch (Throwable $e) {
            if ($connection->transactionLevel() > $level) {
                $connection->rollBack($level);
            }
            throw $e;
        }
    }

    /**
     * @throws ValidationException キーが UUID でないとき
     */
    private function validateKey(string $key): void
    {
        Validator::make(
            [self::FIELD => $key],
            [self::FIELD => ['required', 'uuid']],
            [],
            [self::FIELD => '冪等キー(Idempotency-Key)'],
        )->validate();
    }

    /**
     * 最初に成功した送信の応答を返す。
     *
     * @throws ValidationException 同じキーで中身の違う送信だったとき
     */
    private function replay(int $userId, string $key, string $fingerprint): Response
    {
        $record = IdempotencyKey::query()->where('user_id', $userId)->where('key', $key)->first();
        if ($record === null || $record->response_status === null) {
            // 記録は応答と同じトランザクションでコミットするので、ふつうは来ない(先の送信が終わる前に読めたとき)
            abort(409, '同じ冪等キーの送信を処理しています。');
        }
        if (! hash_equals($record->fingerprint, $fingerprint)) {
            throw ValidationException::withMessages([
                self::FIELD => ['冪等キー(Idempotency-Key)は、別の内容の送信に使われています。'],
            ]);
        }

        $headers = [self::REPLAYED_HEADER => 'true'];
        if ($record->response_content_type !== null) {
            $headers['Content-Type'] = $record->response_content_type;
        }

        return response((string) $record->response_body, $record->response_status, $headers);
    }

    /**
     * 送信の中身のハッシュ。メソッド(_method で上書きした後)・経路・入力で決める。ファイルは中身と名前のハッシュにする
     * (multipart の境界の文字列は送るたびに変わるので、本文のバイト列は使わない)。
     */
    private function fingerprint(Request $request): string
    {
        return hash('sha256', json_encode(
            [$request->method(), $request->path(), $this->normalize($request->all())],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    private function normalize(array $input): array
    {
        ksort($input);

        return array_map(function (mixed $value): mixed {
            if ($value instanceof UploadedFile) {
                $path = $value->getRealPath();

                return [
                    'file' => $path === false ? null : hash_file('sha256', $path),
                    'name' => $value->getClientOriginalName(),
                ];
            }

            return is_array($value) ? $this->normalize($value) : $value;
        }, $input);
    }
}
