<?php

// Helper for IntegrationTest: waits briefly, commits two events below the given subject and prints their IDs.

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GenesisDB\Client;
use GenesisDB\NewEvent;

$subject = $argv[1] ?? throw new InvalidArgumentException('subject argument missing');
usleep(300_000);
$stored = (new Client())->commit([
    new NewEvent('sdk-test', "$subject/1", 'sdk.live', ['n' => 1]),
    new NewEvent('sdk-test', "$subject/2", 'sdk.live', ['n' => 2]),
]);
echo json_encode(array_map(fn ($e) => $e->id, $stored));
