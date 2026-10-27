<?php

declare(strict_types=1);

namespace GenesisDB\Tests;

use GenesisDB\AuthenticationException;
use GenesisDB\Client;
use GenesisDB\ConflictException;
use GenesisDB\FetchOptions;
use GenesisDB\GenesisDBException;
use GenesisDB\NewEvent;
use GenesisDB\NotFoundException;
use GenesisDB\NotReadyException;
use GenesisDB\Precondition;
use GenesisDB\PreconditionFailedException;
use GenesisDB\UnprocessableException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** Unit tests against Guzzle's MockHandler. See IntegrationTest for the live tests. */
final class ClientTest extends TestCase
{
    private const STORED = '{"source":"app","subject":"/customer/1","type":"customer.created","specversion":"1.0","id":"0199c0e0-0000-7000-8000-000000000001","time":"2026-10-24T10:00:00.123456789Z","datacontenttype":"application/json","predecessorhash":"0000000000000000000000000000000000000000000000000000000000000000","data":{"name":"Bruce"},"hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}';

    private MockHandler $mock;
    /** @var list<array{request: RequestInterface}> */
    private array $history = [];
    private Client $db;

    protected function setUp(): void
    {
        $this->mock = new MockHandler();
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        $this->db = new Client(url: 'http://db.test/', token: 'secret', http: new HttpClient(['handler' => $stack]));
    }

    private function respond(int $status, string $body): void
    {
        $this->mock->append(new Response($status, [], $body));
    }

    private function lastRequest(): RequestInterface
    {
        return $this->history[count($this->history) - 1]['request'];
    }

    /** @return array<string, mixed> */
    private function lastBody(): array
    {
        return json_decode((string) $this->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function event(string $subject = '/customer/1'): NewEvent
    {
        return new NewEvent('app', $subject, 'customer.created', ['name' => 'Bruce']);
    }

    public function testConfigRequiresUrlAndToken(): void
    {
        if (getenv('GENESISDB_URL') && getenv('GENESISDB_AUTH_TOKEN')) {
            $this->markTestSkipped('environment provides configuration');
        }
        $this->expectException(\InvalidArgumentException::class);
        new Client(token: 't');
    }

    public function testPingSendsHeaders(): void
    {
        $this->respond(200, 'Pong');
        self::assertSame('Pong', $this->db->ping());
        $request = $this->lastRequest();
        self::assertSame('http://db.test/api/v2/ping', (string) $request->getUri());
        self::assertSame('Bearer secret', $request->getHeaderLine('Authorization'));
        self::assertSame('genesisdb-php/2', $request->getHeaderLine('User-Agent'));
    }

    public function testCommitSendsBodyAndParsesNdjson(): void
    {
        $this->respond(200, self::STORED . "\n");
        $events = $this->db->commit([self::event()], [Precondition::isSubjectNew('/customer/1')]);
        self::assertSame('/api/v2/commit', $this->lastRequest()->getUri()->getPath());
        self::assertSame([
            'events' => [['source' => 'app', 'subject' => '/customer/1', 'type' => 'customer.created', 'data' => ['name' => 'Bruce']]],
            'preconditions' => [['type' => 'isSubjectNew', 'payload' => ['subject' => '/customer/1']]],
        ], $this->lastBody());
        self::assertCount(1, $events);
        self::assertSame('0199c0e0-0000-7000-8000-000000000001', $events[0]->id);
        self::assertSame(['name' => 'Bruce'], $events[0]->data);
        self::assertSame(str_repeat('a', 64), $events[0]->hash);
        self::assertSame('2026-10-24T10:00:00.123456+00:00', $events[0]->time->format('Y-m-d\TH:i:s.uP'));
    }

    public function testCommitOmitsEmptyPreconditionsAndSerialisesReferenceOption(): void
    {
        $this->respond(200, self::STORED . "\n");
        $this->db->commit([self::event()->asReference()]);
        $body = $this->lastBody();
        self::assertArrayNotHasKey('preconditions', $body);
        self::assertSame(['storeDataAsReference' => true], $body['events'][0]['options']);
    }

    public function testCommitEncodesEmptyDataAsObject(): void
    {
        $this->respond(200, self::STORED . "\n");
        $this->db->commit([new NewEvent('app', '/x', 't', [])]);
        self::assertStringContainsString('"data":{}', (string) $this->lastRequest()->getBody());
    }

    public function testCommitRejectsEmptyList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->commit([]);
    }

    public function testPreconditionHelpers(): void
    {
        self::assertSame(['type' => 'isSubjectExisting', 'payload' => ['subject' => '/a']], Precondition::isSubjectExisting('/a')->jsonSerialize());
        self::assertSame(['type' => 'isQueryResultTrue', 'payload' => ['query' => 'STREAM e FROM events']], Precondition::isQueryResultTrue('STREAM e FROM events')->jsonSerialize());
    }

    public function testPreconditionFailedIsParsed(): void
    {
        $this->respond(412, '{"type":"isSubjectNew","message":"subject /customer/1 already exists"}');
        try {
            $this->db->commit([self::event()]);
            self::fail('expected exception');
        } catch (PreconditionFailedException $e) {
            self::assertSame(412, $e->status);
            self::assertSame('commit', $e->operation);
            self::assertSame('isSubjectNew', $e->preconditionType);
            self::assertSame('subject /customer/1 already exists', $e->reason);
        }
    }

    public function testFetchWithAndWithoutOptions(): void
    {
        $this->respond(200, self::STORED . "\n" . self::STORED . "\n");
        self::assertCount(2, $this->db->fetch('/customer', new FetchOptions(lowerBound: 'x', includeLowerBoundEvent: true)));
        self::assertSame(['subject' => '/customer', 'options' => ['lowerBound' => 'x', 'includeLowerBoundEvent' => true]], $this->lastBody());
        $this->respond(200, '');
        self::assertSame([], $this->db->fetch('/customer'));
        self::assertSame(['subject' => '/customer'], $this->lastBody());
        $this->respond(200, '');
        $this->db->fetch('/customer', new FetchOptions());
        self::assertSame(['subject' => '/customer'], $this->lastBody());
    }

    public function testQuerySubjectsTypes(): void
    {
        $this->respond(200, "{\"name\":\"Bruce\"}\n{\"name\":\"Selina\"}\n");
        self::assertSame([['name' => 'Bruce'], ['name' => 'Selina']], $this->db->query('STREAM e FROM events MAP { name: e.data.name }'));
        self::assertSame(['query' => 'STREAM e FROM events MAP { name: e.data.name }'], $this->lastBody());
        $this->respond(200, "2\n");
        self::assertSame([2], $this->db->query('x'));
        $this->respond(200, "{\"subject\":\"/customer\"}\n{\"subject\":\"/order\"}\n");
        self::assertSame(['/customer', '/order'], $this->db->subjects());
        $this->respond(200, "{\"type\":\"customer.created\"}\n");
        self::assertSame(['customer.created'], $this->db->types());
    }

    public function testStatus(): void
    {
        $this->respond(200, '{"engine":{"version":"2.0.0","channel":"stable"},"host":{"hostname":"db"},"events":{"count":3,"subjects":1,"types":2},"storage":{"storageFormat":"v2","ready":true}}');
        $status = $this->db->status();
        self::assertSame('2.0.0', $status->version);
        self::assertSame(3, $status->eventCount);
        self::assertTrue($status->ready);
        self::assertSame('db', $status->raw['host']['hostname']);
    }

    public function testSchemasAndAdmin(): void
    {
        $this->respond(201, '');
        $this->db->registerSchema('customer.created', ['type' => 'object']);
        self::assertSame(['type' => 'customer.created', 'schema' => ['type' => 'object']], $this->lastBody());

        $this->respond(200, "{\"type\":\"customer.created\",\"schema\":{\"type\":\"object\"}}\n");
        $schemas = $this->db->schemas();
        self::assertSame('customer.created', $schemas[0]->type);
        self::assertSame(['type' => 'object'], $schemas[0]->schema);

        $this->respond(200, 'Audit passed');
        self::assertSame('Audit passed', $this->db->audit());
        self::assertSame('/api/v2/admin/audit', $this->lastRequest()->getUri()->getPath());

        $this->respond(200, 'Erased');
        self::assertSame('Erased', $this->db->erase('/customer/1'));
        self::assertSame(['subject' => '/customer/1'], $this->lastBody());

    }

    /** @return iterable<string, array{int, class-string<GenesisDBException>}> */
    public static function errorStatuses(): iterable
    {
        yield '401' => [401, AuthenticationException::class];
        yield '403' => [403, AuthenticationException::class];
        yield '404' => [404, NotFoundException::class];
        yield '409' => [409, ConflictException::class];
        yield '422' => [422, UnprocessableException::class];
        yield '503' => [503, NotReadyException::class];
        yield '500' => [500, GenesisDBException::class];
    }

    #[DataProvider('errorStatuses')]
    public function testErrorClasses(int $status, string $class): void
    {
        $this->respond($status, 'nope');
        try {
            $this->db->fetch('/x');
            self::fail('expected exception');
        } catch (GenesisDBException $e) {
            self::assertSame($class, $e::class);
            self::assertSame($status, $e->status);
            self::assertSame('nope', $e->body);
            self::assertSame('fetch', $e->operation);
            self::assertStringContainsString('nope', $e->getMessage());
        }
    }

    public function testSubscribeSkipsHeartbeatAndStopsOnError(): void
    {
        $stream = 'data: ' . self::STORED . "\n\ndata: {\"payload\":\"\"}\n\ndata: " . self::STORED . "\n\nevent: error\ndata: {\"error\":\"store closed\"}\n\n";
        $this->respond(200, $stream);
        $received = [];
        try {
            foreach ($this->db->subscribe('/customer', interval: 0.5) as $event) {
                $received[] = $event;
            }
            self::fail('expected exception');
        } catch (GenesisDBException $e) {
            self::assertStringContainsString('store closed', $e->getMessage());
        }
        self::assertCount(2, $received);
        self::assertSame(['subject' => '/customer', 'interval' => 0.5], $this->lastBody());
    }

    public function testSubscribeThrowsOnHttpError(): void
    {
        $this->respond(401, 'denied');
        $this->expectException(AuthenticationException::class);
        foreach ($this->db->subscribe('/customer') as $event) {
            self::fail('no event expected');
        }
    }
}
