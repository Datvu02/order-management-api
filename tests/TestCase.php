<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiTestLogger;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ApiTestLogger::startTest(static::class.'::'.$this->name());
    }

    /**
     * Mọi helper getJson/postJson/putJson/deleteJson đều đi qua đây, nên chỉ cần
     * override một chỗ là log được toàn bộ lời gọi API của feature test.
     */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0): TestResponse
    {
        $response = parent::json($method, $uri, $data, $headers, $options);

        ApiTestLogger::recordCall($method, $uri, $data, $response);

        return $response;
    }

    public function artisan($command, $parameters = [])
    {
        ApiTestLogger::recordArtisan((string) $command, $parameters);

        return parent::artisan($command, $parameters);
    }

    protected function onNotSuccessfulTest(Throwable $t): never
    {
        ApiTestLogger::markFailed($t);

        parent::onNotSuccessfulTest($t);
    }
}
