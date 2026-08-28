<?php

declare(strict_types=1);

namespace SignalGate\Tests;

use PHPUnit\Framework\TestCase;
use SignalGate\Constants;
use SignalGate\UserAgent;

/**
 * Group A — constants and identity (BACKEND_SDK_SPEC §2, §8.6; PORTING_SPEC §A2).
 *
 * Covers AC1, AC2, AC3, AC4.
 */
final class ConstantsTest extends TestCase
{
    /** AC1 — the shipped identity triple (resolved-constants table). */
    public function testAc1IdentityConstants(): void
    {
        self::assertSame('signalgate-backend-sdk', Constants::SDK_NAME);
        self::assertSame('0.1.0', Constants::SDK_VERSION);
        self::assertSame('v0', Constants::API_VERSION);
    }

    /**
     * AC1 (amended at Gate A) — version-parity guard, mirroring Python's
     * `test_version.py`.
     *
     * The pin is the TOP `## [x.y.z]` entry of CHANGELOG.md, NOT a
     * `composer.json` "version" field: `composer validate --strict` (mandated
     * by the Brief's CI) rejects a `version` field on a Packagist library, so
     * the CHANGELOG is the only in-repo declaration of the released version.
     */
    public function testAc1SdkVersionMatchesTopChangelogEntry(): void
    {
        $path = dirname(__DIR__) . '/CHANGELOG.md';
        self::assertFileExists($path, 'CHANGELOG.md must ship with the package.');

        $changelog = (string) file_get_contents($path);
        $matched = preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $m);

        self::assertSame(
            1,
            $matched,
            'CHANGELOG.md must have a Keep-a-Changelog `## [x.y.z]` release heading.',
        );
        self::assertSame(
            $m[1],
            Constants::SDK_VERSION,
            'Constants::SDK_VERSION must equal the top CHANGELOG.md release entry.',
        );
    }

    /** AC2 — User-Agent format (§8.6 + PHP addendum §2). */
    public function testAc2UserAgentFormat(): void
    {
        $ua = UserAgent::build();

        // §A4 / §A14 row 16: the family prefix is the contract — every port
        // (go, node, python, java, php) begins with this literal so the server
        // can group traffic by SDK version.
        self::assertMatchesRegularExpression(
            '/^signalgate-backend-sdk\/\d+\.\d+\.\d+ \(php\/\d+\.\d+\.\d+; [a-z]+\)$/',
            $ua,
        );
        self::assertStringStartsWith('signalgate-backend-sdk/0.1.0 (php/', $ua);
        // Teeth: the runtime version must be the REAL one, not a baked-in literal.
        self::assertStringContainsString(
            'php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            $ua,
        );
    }

    /**
     * AC3 (first half) — with `SIGNALGATE_BASE_URL` unset the endpoints resolve
     * to the production host (Brief R1: `.ai`, never `.io`).
     *
     * DEVIATION (see report): the manifest phrased this as
     * `Constants::CHECK_URL === '...'`. PHP class constants must be constant
     * expressions, so a constant literally CANNOT consult `getenv()` — the
     * §A2 "read once at load, hidden escape hatch" requirement and a `const`
     * are mutually exclusive in PHP. The criterion is therefore asserted on
     * the OBSERVABLE consequence (the URL the SDK actually POSTs to), which
     * holds for any resolver shape the implementer picks.
     */
    public function testAc3DefaultEndpointsResolveToProductionHost(): void
    {
        $urls = self::runBaseUrlProbe(null);

        self::assertSame(
            ['https://api.signalgate.ai/v0/check', 'https://api.signalgate.ai/v0/log'],
            $urls,
        );
    }

    /**
     * AC3 (second half) — the dev-only `SIGNALGATE_BASE_URL` escape hatch
     * (§A2, mandatory, "every port must implement it the same hidden way"),
     * with trailing slashes stripped.
     */
    public function testAc3BaseUrlEnvOverrideStripsTrailingSlashes(): void
    {
        $urls = self::runBaseUrlProbe('http://localhost:8080///');

        self::assertSame(
            ['http://localhost:8080/v0/check', 'http://localhost:8080/v0/log'],
            $urls,
        );
    }

    /** AC4 — the §2 default configuration values. */
    public function testAc4Defaults(): void
    {
        self::assertSame(3000, Constants::DEFAULT_CHECK_TIMEOUT_MS);
        self::assertSame(1000, Constants::DEFAULT_LOG_TIMEOUT_MS);
        self::assertSame(10000, Constants::DEFAULT_LOG_QUEUE_CAPACITY);
        self::assertSame(3, Constants::DEFAULT_LOG_MAX_RETRIES);
        self::assertSame(200, Constants::DEFAULT_LOG_RETRY_BASE_MS);
    }

    /**
     * Runs the hermetic child-process probe with an explicit environment.
     *
     * @return list<string> the [checkUrl, logUrl] the SDK POSTed to
     */
    private static function runBaseUrlProbe(?string $baseUrl): array
    {
        $root = dirname(__DIR__);
        $probe = $root . '/tests/Support/base_url_probe.php';

        $env = ['PATH' => getenv('PATH') !== false ? (string) getenv('PATH') : '/usr/bin:/bin'];
        if ($baseUrl !== null) {
            $env['SIGNALGATE_BASE_URL'] = $baseUrl;
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, $probe], $descriptors, $pipes, $root, $env);
        self::assertIsResource($process, 'could not spawn the base-url probe');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode($stdout, true);
        self::assertIsArray(
            $decoded,
            "base-url probe did not emit a URL list.\nstdout: {$stdout}\nstderr: {$stderr}",
        );

        /** @var list<string> $decoded */
        return $decoded;
    }
}
