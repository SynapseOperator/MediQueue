<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DemoConfigurationTest extends TestCase
{
    #[DataProvider('environmentCases')]
    public function test_mock_payments_require_an_explicit_demo_setting_in_an_allowed_environment(
        string $environment,
        string|false $enabled,
        bool $expected,
    ): void {
        $process = new Process([
            PHP_BINARY,
            '-r',
            "require 'vendor/autoload.php'; echo json_encode(require 'config/mediqueue.php');",
        ], dirname(__DIR__, 2), [
            'APP_ENV' => $environment,
            'MOCK_PAYMENTS_ENABLED' => $enabled,
        ]);

        $process->mustRun();
        $configuration = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($expected, $configuration['mock_payments_enabled']);
        $this->assertSame('MOCK_WECHAT', $configuration['payment_channel']);
    }

    public static function environmentCases(): array
    {
        return [
            'production fails closed' => ['production', 'true', false],
            'unrecognized environment fails closed' => ['staging', 'true', false],
            'local explicit opt-in' => ['local', 'true', true],
            'demo explicit opt-in' => ['demo', 'true', true],
            'testing explicit opt-in' => ['testing', 'true', true],
            'explicitly disabled' => ['local', 'false', false],
            'disabled by default' => ['local', false, false],
        ];
    }
}
