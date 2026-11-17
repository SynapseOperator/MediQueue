<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DemoProxyTest extends TestCase
{
    public static function proxyModes(): array
    {
        return [
            'explicit demo forwarding' => ['demo', 'true', true],
            'ordinary demo' => ['demo', 'false', false],
            'production ignores demo option' => ['production', 'true', false],
        ];
    }

    #[DataProvider('proxyModes')]
    public function test_forwarded_links_and_signatures_require_explicit_demo_configuration(
        string $environment,
        string $enabled,
        bool $trusted,
    ): void {
        $previous = [];
        foreach (['APP_ENV' => $environment, 'MEDIQUEUE_TRUST_DEMO_PROXY' => $enabled] as $name => $value) {
            $previous[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }
        try {
            $configuration = require config_path('trustedproxy.php');
        } finally {
            foreach ($previous as $name => [$env, $server, $process]) {
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
                putenv($process === false ? $name : $name.'='.$process);
            }
        }
        config(['trustedproxy' => $configuration]);
        $this->assertSame($trusted ? '*' : null, $configuration['proxies']);

        Route::get('/__demo-proxy-link', fn (Request $request) => [
            'secure' => $request->isSecure(),
            'signed' => URL::signedRoute('demo.proxy.signed'),
        ]);
        Route::get('/__demo-proxy-signed', fn (Request $request) => [
            'valid' => $request->hasValidSignature(),
        ])->name('demo.proxy.signed');
        Route::getRoutes()->refreshNameLookups();

        $response = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'demo-8000.app.github.dev',
            'X-Forwarded-Port' => '443',
        ])->get('/__demo-proxy-link')->assertOk()->assertJsonPath('secure', $trusted);
        $signed = $response->json('signed');
        $this->assertStringStartsWith($trusted ? 'https://demo-8000.app.github.dev/' : 'http://localhost/', $signed);
        $this->get(parse_url($signed, PHP_URL_PATH).'?'.parse_url($signed, PHP_URL_QUERY))
            ->assertOk()->assertJsonPath('valid', true);
    }
}
