<?php

namespace App\Http\Requests\Admin;

use App\Models\Paraphrase;
use App\Services\ParaphraseTargets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * 言い換え辞書の 1 語の追加・更新(POST /api/admin/paraphrases・PUT /api/admin/paraphrases/{id}。#316)。
 */
class SaveParaphraseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $paraphrase = $this->route('paraphrase');

        return [
            // 変換前の名前(取り込んだ材料の名前)。ほかの語と重ならない
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('paraphrases', 'name')->ignore($paraphrase instanceof Paraphrase ? $paraphrase->id : null),
            ],
            // 変換後の名前。ノードか銘柄の名前で、解決できないと 422
            'converted' => ['required', 'string', 'max:255'],
            // converted のノードの配下のノードの名前
            'middle' => ['nullable', 'string', 'max:255'],
            // null: 指定なし / hot: 強制ホット(旧の画面の選択肢)
            'command' => ['nullable', 'string', 'in:hot'],
            'note' => ['nullable', 'string'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(ParaphraseTargets $targets): array
    {
        return [
            function (Validator $validator) use ($targets): void {
                if ($validator->errors()->hasAny(['converted', 'middle'])) {
                    return;
                }

                $converted = $this->string('converted')->toString();
                $middle = $this->filled('middle') ? $this->string('middle')->toString() : null;
                if ($targets->resolve($converted, $middle) !== null) {
                    return;
                }

                if ($middle !== null && $targets->convertedNode($converted) !== null) {
                    $validator->errors()->add('middle', '変換後のノードの配下に、この名前のノードがありません。');
                } elseif ($middle !== null) {
                    $validator->errors()->add('converted', 'この名前のノードがありません(ミドルを指定するときは、変換後はノードの名前にします)。');
                } else {
                    $validator->errors()->add('converted', 'この名前のノード・銘柄がありません。');
                }
            },
        ];
    }
}
