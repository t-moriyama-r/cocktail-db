<?php

namespace Tests\Support;

use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Tests\TestCase;

/**
 * 移行のテストから legacy:import を呼ぶ。
 */
final class LegacyImportCommand
{
    /**
     * legacy:import を、出力と終了コードを確かめられる形で呼ぶ。
     *
     * @param  array<string, string>  $options
     */
    public static function run(TestCase $test, array $options): PendingCommand
    {
        $command = $test->artisan('legacy:import', $options);

        return $command instanceof PendingCommand
            ? $command
            : throw new RuntimeException('コンソールの出力を差し替えずにコマンドが動いた');
    }
}
