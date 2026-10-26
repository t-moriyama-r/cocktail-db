<?php

namespace App\Http\Controllers;

class HelloController extends Controller
{
    /**
     * 疎通確認用のメッセージを返す。
     *
     * @return array{message: string}
     */
    public function __invoke(): array
    {
        return ['message' => 'Hello World from Laravel!'];
    }
}
