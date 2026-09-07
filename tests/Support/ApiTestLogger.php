<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Ghi request/response của feature test ra terminal (STDERR) và storage/logs/api-test.log.
 *
 * Block chỉ được in khi đã biết PASSED/FAILED. PHPUnit gọi tearDown() trước
 * onNotSuccessfulTest(), nên flush được kích bởi ApiTestExtension sau khi test kết thúc.
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

        self::writeOut(PHP_EOL.str_repeat('=', 100).PHP_EOL.$test.PHP_EOL.str_repeat('-', 100).PHP_EOL);
    }

    public static function recordCall(string $method, string $uri, array $payload, TestResponse $response): void
    {
        if (self::$currentTest === null) {
            return;
        }

        $code = $response->getStatusCode();
        $reason = Response::$statusTexts[$code] ?? '';

        $chunk = [
            sprintf('[%d] %s %s  ->  %d %s', ++self::$step, strtoupper($method), $uri, $code, $reason),
        ];

        if ($payload !== []) {
            $chunk[] = '    request:';
            $chunk[] = self::indent(self::pretty(json_encode($payload)));
        }

        $chunk[] = '    response:';
        $chunk[] = self::indent(self::pretty($response->getContent()));
        $chunk[] = '';

        foreach ($chunk as $line) {
            self::$lines[] = $line;
        }

        self::writeOut(implode(PHP_EOL, $chunk).PHP_EOL);
    }

    public static function recordArtisan(string $command, array $parameters = []): void
    {
        if (self::$currentTest === null) {
            return;
        }

        $suffix = $parameters === [] ? '' : ' '.json_encode($parameters);
        $line = sprintf('[%d] artisan %s%s', ++self::$step, $command, $suffix);

        self::$lines[] = $line;
        self::$lines[] = '';
        self::writeOut($line.PHP_EOL.PHP_EOL);
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

        $text = implode(PHP_EOL, $block).PHP_EOL;

        file_put_contents(self::path(), $text, FILE_APPEND);
        self::writeOut(sprintf('%s  %s%s%s', $status, self::$currentTest, PHP_EOL, PHP_EOL));

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

        $header = sprintf(
            'Feature test log - %s%sMoi block la mot test: trang thai, cac request da goi, response tra ve.%s',
            date('Y-m-d H:i:s'),
            PHP_EOL,
            PHP_EOL
        );

        file_put_contents(self::path(), $header);
        self::writeOut($header.PHP_EOL);

        register_shutdown_function([self::class, 'flush']);
    }

    /**
     * In ra terminal ngay khi chạy `php artisan test`. PHPUnit bọc STDOUT trong
     * output buffer nên fwrite thông thường bị giữ đến cuối test (Collision còn in
     * pass/fail trước). Tạm nhấc buffer, ghi ra STDOUT thật, rồi đặt lại buffer.
     */
    private static function writeOut(string $text): void
    {
        $stacked = [];

        while (ob_get_level() > 0) {
            $stacked[] = ob_get_clean();
        }

        fwrite(STDOUT, $text);
        flush();

        foreach (array_reverse($stacked) as $chunk) {
            ob_start();

            if ($chunk !== false && $chunk !== '') {
                echo $chunk;
            }
        }
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
