<?php

namespace Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * テストでブラウザと認証器(端末の指紋・PIN など)の代わりをする、ソフトウェアのパスキー 1 つ。
 *
 * パスキーの API(#311)が受け取るのは、ブラウザの navigator.credentials.create()・get() が返したものを
 * JSON にした形。それを本物と同じ手順で作る(P-256 の鍵を作り、チャレンジと送り先のオリジンを入れた
 * clientDataJSON に署名する)。自分たちのモジュールは差し替えず、ブラウザという境界の外側だけを置き換える。
 */
final class SoftwareAuthenticator
{
    private readonly OpenSSLAsymmetricKey $key;

    private readonly string $credentialId;

    private int $signCount = 0;

    public function __construct(private readonly string $rpId = 'localhost')
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->key = $key !== false ? $key : throw new RuntimeException('EC の鍵を作れない');
        $this->credentialId = random_bytes(32);
    }

    /**
     * 登録の開始で受け取った options に答える(navigator.credentials.create() の結果)。
     *
     * @param  mixed  $options  登録の開始が返した options(JSON を読んだまま)
     * @return array{id: string, rawId: string, type: string, response: array{clientDataJSON: string, attestationObject: string, transports: list<string>}}
     */
    public function register(mixed $options, string $origin): array
    {
        $clientData = $this->clientData('webauthn.create', self::challengeOf($options), $origin);

        $attestedCredentialData = str_repeat("\0", 16) // AAGUID(認証器の機種)。none の証明では 0 でよい
            .pack('n', strlen($this->credentialId)).$this->credentialId
            .$this->coseKey();
        // UP(触れた)・UV(本人確認した)・AT(資格情報が付いている)
        $authData = $this->authData(0x01 | 0x04 | 0x40).$attestedCredentialData;

        $attestationObject = (string) MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return [
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'attestationObject' => self::b64($attestationObject),
                'transports' => ['internal'],
            ],
        ];
    }

    /**
     * ログインの開始で受け取った options に答える(navigator.credentials.get() の結果)。
     *
     * @param  mixed  $options  ログインの開始が返した options(JSON を読んだまま)
     * @param  string  $userHandle  登録のときに BE が渡したユーザーの ID(バイト列)。認証器はこれを覚えていて返す
     * @return array{id: string, rawId: string, type: string, response: array{clientDataJSON: string, authenticatorData: string, signature: string, userHandle: string}}
     */
    public function login(mixed $options, string $origin, string $userHandle): array
    {
        $clientData = $this->clientData('webauthn.get', self::challengeOf($options), $origin);
        $this->signCount++;
        $authData = $this->authData(0x01 | 0x04);

        $signature = '';
        if (! openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256) || ! is_string($signature)) {
            throw new RuntimeException('署名できない');
        }

        return [
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'authenticatorData' => self::b64($authData),
                'signature' => self::b64($signature),
                'userHandle' => self::b64($userHandle),
            ],
        ];
    }

    /**
     * BE が覚える形(base64url・詰め物なし)の資格情報の ID。
     */
    public function credentialId(): string
    {
        return self::b64($this->credentialId);
    }

    private function clientData(string $type, string $challenge, string $origin): string
    {
        return json_encode(
            ['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    private function authData(int $flags): string
    {
        return hash('sha256', $this->rpId, true).chr($flags).pack('N', $this->signCount);
    }

    /**
     * 公開鍵を COSE の形(EC2・P-256・ES256)で表す。
     */
    private function coseKey(): string
    {
        $details = openssl_pkey_get_details($this->key);
        $ec = is_array($details) && is_array($details['ec'] ?? null) ? $details['ec'] : [];
        $x = $ec['x'] ?? null;
        $y = $ec['y'] ?? null;
        if (! is_string($x) || ! is_string($y)) {
            throw new RuntimeException('鍵の中身を読めない');
        }

        return (string) MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2)) // kty: EC2
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7)) // alg: ES256
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1)) // crv: P-256
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad($x, 32, "\0", STR_PAD_LEFT)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad($y, 32, "\0", STR_PAD_LEFT)));
    }

    private static function challengeOf(mixed $options): string
    {
        $challenge = is_array($options) ? ($options['challenge'] ?? null) : null;

        return is_string($challenge) ? $challenge : throw new RuntimeException('options に challenge が無い');
    }

    /**
     * base64url(詰め物なし)。WebAuthn の JSON でバイト列を表す形。
     */
    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
