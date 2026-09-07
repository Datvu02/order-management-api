<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Ghi lại request/response của mọi lời gọi API trong feature test kèm kết quả pass/fail
 * vào storage/logs/api-test.log.
 *
 * Mỗi test được gom thành một block và chỉ ghi ra file khi đã biết kết quả, vì PHPUnit
 * gọi tearDown() trước onNotSuccessfulTest() nên không thể biết pass/fail ngay lúc test kết thúc.
 */
final class ApiTestLogger
{
    private const MAX_BODY = 8000;

    private static bool $booted = false;

    private static ?string $currentTest = null;

    private static ?string $failure = null;

    private static int $step = 0;

    /** @var list<string> */
    private static array $lines = [];

    public static function path(): string
    {
        return dirname(__DIR__, 2).'/storage/logs/api-test.log';
    }

    public static function startTest(string $test): void
    {
        self::flush();
        self::boot();

        self::$currentTest = $test;
        self::$failure = null;
        self::$step = 0;
        self::$lines = [];
    }

    public static function recordCall(string $method, string $uri, array $payload, TestResponse $response): void
    {
        if (self::$currentTest === null) {
            return;
        }

        $code = $response->getStatusCode();
        $reason = Response::$statusTexts[$code] ?? '';

        self::$lines[] = sprintf('[%d] %s %s  ->  %d %s', ++self::$step, strtoupper($method), $uri, $code, $reason);

        if ($payload !== []) {
            self::$lines[] = '    request:';
            self::$lines[] = self::indent(self::pretty(json_encode($payload)));
        }

        self::$lines[] = '    response:';
        self::$lines[] = self::indent(self::pretty($response->getContent()));
        self::$lines[] = '';
    }

    public static function recordArtisan(string $command, array $parameters = []): void
    {
        if (self::$currentTest === null) {
            return;
        }

        $suffix = $parameters === [] ? '' : ' '.json_encode($parameters);
        self::$lines[] = sprintf('[%d] artisan %s%s', ++self::$step, $command, $suffix);
        self::$lines[] = '';
    }

    public static function markFailed(Throwable $e): void
    {
        self::$failure = trim($e->getMessage()) !== ''
            ? $e->getMessage()
            : $e::class;
    }

    public static function flush(): void
    {
        if (self::$currentTest === null) {
            return;
        }

        $status = self::$failure === null ? 'PASSED' : 'FAILED';

        $block = [
            str_repeat('=', 100),
            sprintf('%-7s %s', $status, self::$currentTest),
        ];

        if (self::$failure !== null) {
            $block[] = str_repeat('-', 100);
            $block[] = 'reason: '.self::collapse(self::$failure);
        }

        if (self::$lines !== []) {
            $block[] = str_repeat('-', 100);
            $block = array_merge($block, self::$lines);
        } else {
            $block[] = '';
        }

        file_put_contents(self::path(), implode(PHP_EOL, $block).PHP_EOL, FILE_APPEND);

        self::$currentTest = null;
        self::$lines = [];
        self::$failure = null;
    }

    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        $dir = dirname(self::path());
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(self::path(), sprintf(
            'Feature test log - %s%s%s%s',
            date('Y-m-d H:i:s'),
            PHP_EOL,
            'Moi block la mot test: trang thai, cac request da goi, response tra ve.',
            PHP_EOL
        ));

        register_shutdown_function([self::class, 'flush']);
    }

    private static function pretty(string|false|null $content): string
    {
        if ($content === false || $content === null || $content === '') {
            return '(empty)';
        }

        $decoded = json_decode($content, true);

        $output = json_last_error() === JSON_ERROR_NONE
            ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $content;

        if ($output === false) {
            return '(khong doc duoc)';
        }

        return strlen($output) > self::MAX_BODY
            ? substr($output, 0, self::MAX_BODY).PHP_EOL.'... (da cat bot)'
            : $output;
    }

    /**
     * json_encode ngắt dòng bằng "\n" trong khi PHP_EOL trên Windows là "\r\n",
     * nên phải tách theo cả hai kiểu, không dùng explode(PHP_EOL, ...).
     */
    private static function indent(string $text): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [$text];

        return implode(PHP_EOL, array_map(
            fn (string $line) => '      '.$line,
            $lines
        ));
    }

    private static function collapse(string $message): string
    {
        return trim(preg_replace('/\s+/', ' ', $message) ?? $message);
    }
}
