<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\PasskeyResource;
use App\Models\User;
use App\Support\PasskeyCeremony;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;

/**
 * ログインしている管理者の、自分のパスキー(#311)。登録・一覧・削除。旧は X でのログインだけで、パスキーは無かった。
 */
class PasskeyController extends Controller
{
    /**
     * 自分のパスキーを、登録した順で返す。
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return PasskeyResource::collection($this->admin($request)->passkeys()->orderBy('id')->get());
    }

    /**
     * 登録を始める。ブラウザの navigator.credentials.create() に渡す options を返す。
     *
     * 登録済みのパスキーは options の excludeCredentials に載せ、同じ端末を 2 回登録させない。
     *
     * @return array{options: array<array-key, mixed>}
     */
    public function registrationOptions(Request $request, GenerateRegistrationOptions $generate): array
    {
        $options = $generate($this->admin($request));
        $request->session()->put('passkey.registration_options', WebAuthn::toJson($options));

        return ['options' => WebAuthn::toBrowserArray($options)];
    }

    /**
     * 登録を終える。navigator.credentials.create() の結果を確かめて、名前を付けて保存する。
     *
     * 登録を始めていない・チャレンジやオリジンの合わない応答、登録済みの資格情報は 422(credential のエラー)。
     */
    public function store(PasskeyRegistrationRequest $request, StorePasskey $store): PasskeyResource
    {
        $options = $request->registrationOptions();

        return PasskeyResource::make(PasskeyCeremony::run(fn () => $store(
            $this->admin($request),
            $request->string('name')->toString(),
            $request->credential(),
            $options,
        )));
    }

    /**
     * 自分のパスキーを 1 つ消す。ほかの人のパスキーは、無いものと同じく 404。
     *
     * 最後の 1 つも消せる(docs/decisions/passkey.md)。消すと、非常用のログイン URL(#115)でしか入れなくなる。
     */
    public function destroy(Request $request, Passkey $passkey, DeletePasskey $delete): Response
    {
        $admin = $this->admin($request);
        abort_unless($passkey->user_id === $admin->id, 404);

        $delete($admin, $passkey);

        return response()->noContent();
    }

    private function admin(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
