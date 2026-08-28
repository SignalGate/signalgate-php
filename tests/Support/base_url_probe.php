<?php

/**
 * AC3 probe — runs in a CHILD PROCESS so the `SIGNALGATE_BASE_URL` dev-only
 * escape hatch (§A2: "read once at class-load", so it cannot be re-read
 * in-process) is observable both set and unset.
 *
 * Prints a JSON array of the URLs the SDK actually POSTed to: index 0 is the
 * `check` URL, index 1 the `log` URL. Fully hermetic — the transport is a fake.
 *
 * NOT a PHPUnit test file (no `Test.php` suffix), so the suite never collects
 * it; `ConstantsTest` invokes it via `proc_open`.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use SignalGate\Client;
use SignalGate\Tests\Support\FakeTransport;
use SignalGate\Tests\Support\Fixtures;
use SignalGate\Tests\Support\RecordedRequest;

$fake = new FakeTransport(static function (RecordedRequest $req) {
    return str_ends_with($req->url, '/check')
        ? Fixtures::checkSuccessResponse($req)
        : Fixtures::logSuccessResponse($req);
});

$client = new Client(Fixtures::options(['transport' => $fake]));
$client->check(Fixtures::sampleEvent());
$client->log(Fixtures::sampleEvent());
$client->flush();

echo json_encode($fake->urls(), JSON_UNESCAPED_SLASHES);
