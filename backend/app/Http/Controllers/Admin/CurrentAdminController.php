<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class CurrentAdminController extends Controller
{
    /**
     * ログインしている管理者を返す。
     *
     * @return array{id: int, name: string}
     */
    public function __invoke(Request $request): array
    {
        /** @var User $admin */
        $admin = $request->user();

        return ['id' => $admin->id, 'name' => $admin->name];
    }
}
