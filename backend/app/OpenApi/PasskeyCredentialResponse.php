<?php

namespace App\OpenApi;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;

/**
 * パスキーのログイン・登録の完了の本文の credential.response を、OpenAPI でオブジェクトにする(#608)。
 *
 * laravel/passkeys のルールは 'credential.response' => ['required', 'array'] で、Scramble はこれを string[] と読む。
 * 実際に送るのは PublicKeyCredential.toJSON() の response(キーを持つオブジェクト)なので、BE(web-auth/webauthn-lib)が
 * 読むキーを書く。必須にするのは、BE が読めないと 422 にするキーだけ。
 *
 * dedoc/scramble は require-dev なので、本番(composer install --no-dev)ではこのクラスを読み込まない(AppServiceProvider)。
 */
class PasskeyCredentialResponse implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $this->replace($document, PasskeyVerificationRequest::class, $this->assertion());
        $this->replace($document, PasskeyRegistrationRequest::class, $this->attestation());
    }

    private function replace(OpenApi $document, string $schemaName, ObjectType $response): void
    {
        if (! $document->components->hasSchema($schemaName)) {
            return;
        }
        $schema = $document->components->getSchema($schemaName);
        if (! $schema instanceof Schema || ! $schema->type instanceof ObjectType || ! $schema->type->hasProperty('credential')) {
            return;
        }
        $credential = $schema->type->getProperty('credential');
        if (! $credential instanceof ObjectType || ! $credential->hasProperty('response')) {
            return;
        }
        $credential->addProperty('response', $response);
    }

    /**
     * ログイン(navigator.credentials.get())の応答。AuthenticatorAssertionResponseJSON。
     */
    private function assertion(): ObjectType
    {
        $response = new ObjectType;
        $response->addProperty('clientDataJSON', new StringType);
        $response->addProperty('authenticatorData', new StringType);
        $response->addProperty('signature', new StringType);
        $response->addProperty('userHandle', (new StringType)->nullable(true));
        $response->setRequired(['clientDataJSON', 'authenticatorData', 'signature']);

        return $response;
    }

    /**
     * 登録(navigator.credentials.create())の応答。AuthenticatorAttestationResponseJSON。
     */
    private function attestation(): ObjectType
    {
        $transports = new ArrayType;
        $transports->setItems(new StringType);

        $response = new ObjectType;
        $response->addProperty('clientDataJSON', new StringType);
        $response->addProperty('attestationObject', new StringType);
        $response->addProperty('transports', $transports);
        $response->setRequired(['clientDataJSON', 'attestationObject']);

        return $response;
    }
}
