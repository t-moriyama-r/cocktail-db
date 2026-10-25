<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * E2E テスト(e2e/)が前提にするデータを入れる。
 * 本番のデータは使わず、画面の E2E が要るデータをここに足していく。
 * 今の画面(トップの Hello World)は DB のデータを使わないので、まだ何も入れない。
 */
class E2eSeeder extends Seeder
{
    public function run(): void {}
}
