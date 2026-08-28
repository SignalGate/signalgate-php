<?php

declare(strict_types=1);

namespace SignalGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignalGate\Client;
use SignalGate\CurlTransport;
use SignalGate\Errors\NetworkError;
use SignalGate\Errors\ServerError;
use SignalGate\Errors\SignalGateError;
use SignalGate\Errors\TimeoutError;
use SignalGate\PostOptions;
use SignalGate\Tests\Support\CapturingLogger;
use SignalGate\Tests\Support\FakeTransport;
use SignalGate\Tests\Support\Fixtures;
use SignalGate\Tests\Support\RecordedRequest;
use SignalGate\Tests\Support\RecordingSleeper;
use SignalGate\UserAgent;

/**
 * Group K (security half) — the API key must never reach ANY output
 * (§8.4, §A4 "Redaction (security-critical)").
 *
 * Covers AC51, AC52, AC53, AC54.
 */
final class RedactionTest extends TestCase
{
    private const SECRET = 'super-secret-pk-value-xyz-9f8e7d6c';

    /**
     * AC51 (§9 row 12, §A14 row 15) — the key never appears in a log line.
     * The timeout drives the fail-open path, which is contractually required
     * to emit a `warn` (§A5), so the logger is guaranteed non-empty: no
     * vacuous pass.
     */
    public function testAc51ApiKeyIsNeverWrittenToALogLine(): void
    {
        $logger = new CapturingLogger();
        $fake = new FakeTransport(static function (RecordedRequest $r): never {
            throw new TimeoutError('simulated timeout');
        });
        $client = new Client(Fixtures::options([
            'api_key' => self::SECRET,
            'transport' => $fake,
            'logger' => $logger,
            // Injected so the close()-time retry ladder never really sleeps.
            'sleeper' => new RecordingSleeper(),
        ]));

        $client->check(Fixtures::sampleEvent());
        $client->log(Fixtures::sampleEvent());
        $client->close();

        self::assertNotSame([], $logger->entries, 'the fail-open path must log (teeth)');
        self::assertStringNotContainsString(self::SECRET, $logger->serialized());

        foreach ($logger->entries as $entry) {
            $line = (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (str_contains($line, 'Authorization') || str_contains($line, 'Bearer ')) {
                self::assertStringContainsString('Bearer ***REDACTED***', $line);
                self::assertStringNotContainsString(self::SECRET, $line);
            }
        }
    }

    /**
     * AC52 (§8.4) — the key never appears in an exception message, nor in the
     * exception's full string form, on ANY of the five error paths.
     */
    #[DataProvider('provideErrorPaths')]
    public function testAc52ApiKeyIsNeverInAnExceptionMessage(string $path): void
    {
        $error = $this->triggerErrorPath($path);

        self::assertInstanceOf(SignalGateError::class, $error, "{$path}: expected a SignalGateError");
        self::assertStringNotContainsString(self::SECRET, $error->getMessage(), "{$path}: getMessage()");
        self::assertStringNotContainsString(self::SECRET, (string) $error, "{$path}: (string) \$e");
    }

    /** @return iterable<string, array{string}> */
    public static function provideErrorPaths(): iterable
    {
        yield 'server error 401' => ['server_401'];
        yield 'timeout, fail_open off' => ['timeout'];
        yield 'network error, fail_open off' => ['network'];
        yield 'config error from a bad option' => ['config_option'];
        yield 'config error from a malformed event' => ['config_event'];
    }

    /**
     * AC53 (§8.4) — the User-Agent takes no key (it has no key parameter at
     * all), and the raw key appears in exactly one composed header.
     */
    public function testAc53KeyAppearsOnlyInTheAuthorizationHeader(): void
    {
        self::assertSame(
            0,
            (new \ReflectionMethod(UserAgent::class, 'build'))->getNumberOfParameters(),
            'UserAgent::build() must take no arguments - it can never see the key',
        );
        self::assertStringNotContainsString(self::SECRET, UserAgent::build());

        $headers = CurlTransport::buildHeaders(
            self::SECRET,
            new PostOptions(3000, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'ffffffff-1111-4222-9333-444444444444'),
        );

        $carrying = [];
        foreach ($headers as $name => $value) {
            if (str_contains((string) $value, self::SECRET)) {
                $carrying[] = $name;
            }
        }

        self::assertSame(['Authorization'], $carrying);
        self::assertSame('Bearer ' . self::SECRET, $headers['Authorization']);
    }

    /**
     * AC54 (addendum §7) — `CURLOPT_VERBOSE` writes request headers to stderr,
     * API key included. It must appear nowhere in the shipped sources.
     */
    public function testAc54ShippedSourcesNeverEnableCurlVerbose(): void
    {
        $sources = Fixtures::shippedSources();
        self::assertNotSame([], $sources, 'src/ must contain PHP sources to scan');

        foreach ($sources as $file) {
            self::assertStringNotContainsString(
                'CURLOPT_' . 'VERBOSE',
                (string) file_get_contents($file),
                "{$file} must never enable cURL verbose output (it leaks the Authorization header)",
            );
        }
    }

    private function triggerErrorPath(string $path): \Throwable
    {
        $options = ['api_key' => self::SECRET, 'register_shutdown' => false];

        try {
            switch ($path) {
                case 'server_401':
                    $fake = new FakeTransport(static function (RecordedRequest $r): never {
                        throw new ServerError(401, 'UNAUTHORIZED', 'bad key', 'req_9');
                    });
                    (new Client($options + ['transport' => $fake]))->check(Fixtures::sampleEvent());
                    break;

                case 'timeout':
                    $fake = new FakeTransport(static function (RecordedRequest $r): never {
                        throw new TimeoutError('deadline exceeded');
                    });
                    (new Client($options + ['transport' => $fake, 'fail_open' => false]))
                        ->check(Fixtures::sampleEvent());
                    break;

                case 'network':
                    $fake = new FakeTransport(static function (RecordedRequest $r): never {
                        throw new NetworkError('connection refused');
                    });
                    (new Client($options + ['transport' => $fake, 'fail_open' => false]))
                        ->check(Fixtures::sampleEvent());
                    break;

                case 'config_option':
                    new Client($options + ['check_timeout_ms' => -1]);
                    break;

                case 'config_event':
                    $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::checkSuccessResponse($r));
                    (new Client($options + ['transport' => $fake]))->check(['user_id' => 'u']);
                    break;

                default:
                    self::fail("unknown error path {$path}");
            }
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail("error path {$path} did not raise");
    }
}
