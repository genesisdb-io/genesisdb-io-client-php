<?php

declare(strict_types=1);

namespace GenesisDB\Tests;

use GenesisDB\Client;
use GenesisDB\ConflictException;
use GenesisDB\FetchOptions;
use GenesisDB\GenesisDBException;
use GenesisDB\NewEvent;
use GenesisDB\Precondition;
use GenesisDB\PreconditionFailedException;
use GenesisDB\UnprocessableException;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a live GenesisDB. Skipped unless GENESISDB_URL and GENESISDB_AUTH_TOKEN (an administrator token)
 * are set. Writes under /sdk-test-php/<run id>.
 */
final class IntegrationTest extends TestCase
{
    private Client $db;
    private string $run;
    private string $root;

    protected function setUp(): void
    {
        if (!getenv('GENESISDB_URL') || !getenv('GENESISDB_AUTH_TOKEN')) {
            $this->markTestSkipped('GENESISDB_URL and GENESISDB_AUTH_TOKEN not set');
        }
        $this->db = new Client();
        $this->run = dechex((int) (microtime(true) * 1000));
        $this->root = '/sdk-test-php/' . $this->run;
    }

    /** @param array<string, mixed> $data */
    private static function event(string $subject, string $type, array $data): NewEvent
    {
        return new NewEvent('sdk-test', $subject, $type, $data);
    }

    public function testFullRoundTrip(): void
    {
        self::assertSame('Pong', $this->db->ping());

        // commit, fetch, query
        $stored = $this->db->commit([
            self::event("{$this->root}/customer/1", 'sdk.customer-added', ['name' => 'Bruce', 'age' => 40]),
            self::event("{$this->root}/customer/2", 'sdk.customer-added', ['name' => 'Selina', 'age' => 35]),
        ]);
        self::assertCount(2, $stored);
        self::assertSame(36, strlen($stored[0]->id));
        self::assertSame(64, strlen($stored[0]->hash));
        self::assertSame($stored[0]->hash, $stored[1]->predecessorhash);

        $fetched = $this->db->fetch($this->root);
        self::assertSame(['Bruce', 'Selina'], array_map(fn ($e) => $e->data['name'], $fetched));

        $bounded = $this->db->fetch($this->root, new FetchOptions(lowerBound: $stored[0]->id, includeLowerBoundEvent: false));
        self::assertSame([$stored[1]->id], array_map(fn ($e) => $e->id, $bounded));

        $rows = $this->db->query("STREAM e FROM events WHERE e.subject DESCENDANTS '{$this->root}' ORDER BY e.data.age MAP { name: e.data.name }");
        self::assertSame(['Selina', 'Bruce'], array_column($rows, 'name'));
        self::assertSame([2], $this->db->query("STREAM e FROM events WHERE e.subject DESCENDANTS '{$this->root}' MAP COUNT()"));

        // preconditions
        try {
            $this->db->commit([self::event("{$this->root}/customer/1", 'sdk.noop', [])], [Precondition::isSubjectNew("{$this->root}/customer/1")]);
            self::fail('expected precondition failure');
        } catch (PreconditionFailedException $e) {
            self::assertSame('isSubjectNew', $e->preconditionType);
        }

        // catalog
        self::assertContains('/sdk-test-php', $this->db->subjects());
        self::assertContains('sdk.customer-added', $this->db->types());

        // reference payloads and erase
        $user = "{$this->root}/user/1";
        $committed = $this->db->commit([self::event($user, 'sdk.user-added', ['email' => 'bruce@example.com'])->asReference()]);
        self::assertSame(['email' => 'bruce@example.com'], $committed[0]->data);
        self::assertSame('bruce@example.com', $this->db->fetch($user)[0]->data['email']);
        self::assertStringContainsString('Erased 1 ref data', $this->db->erase($user));
        self::assertSame([], $this->db->fetch($user)[0]->data);
        try {
            $this->db->erase("{$this->root}/customer/1");
            self::fail('expected 422');
        } catch (UnprocessableException) {
        }

        // schemas
        $schemaType = "sdk.schema-test-{$this->run}";
        $this->db->registerSchema($schemaType, ['type' => 'object', 'properties' => ['n' => ['type' => 'number']], 'required' => ['n']]);
        try {
            $this->db->registerSchema($schemaType, ['type' => 'object']);
            self::fail('expected 409');
        } catch (ConflictException) {
        }
        self::assertTrue((bool) array_filter($this->db->schemas(), fn ($s) => $s->type === $schemaType));
        try {
            $this->db->commit([self::event("{$this->root}/s/1", $schemaType, ['n' => 'not a number'])]);
            self::fail('expected 400');
        } catch (GenesisDBException $e) {
            self::assertSame(400, $e->status);
        }

        // subscribe: a helper process commits two events 300 ms after we start listening, so the
        // subscription has to deliver them live, not from history.
        $live = "{$this->root}/live";
        $writer = proc_open(
            [PHP_BINARY, __DIR__ . '/live_writer.php', $live],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['GENESISDB_URL' => getenv('GENESISDB_URL'), 'GENESISDB_AUTH_TOKEN' => getenv('GENESISDB_AUTH_TOKEN')],
        );
        self::assertIsResource($writer);
        $received = [];
        foreach ($this->db->subscribe($live, interval: 0.1) as $event) {
            $received[] = $event->id;
            if (count($received) === 2) {
                break;
            }
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($writer), (string) $stderr);
        self::assertSame(json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR), $received);

        // status, audit
        $status = $this->db->status();
        self::assertTrue($status->ready);
        self::assertGreaterThanOrEqual(5, $status->eventCount);
        self::assertStringStartsWith('Audit passed', $this->db->audit());
    }
}
