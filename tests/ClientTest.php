<?php

namespace GenesisDB\GenesisDB\Tests;

use CloudEvents\V1\CloudEvent;
use GenesisDB\GenesisDB\Client;
use GenesisDB\GenesisDB\CommitEvent;
use GenesisDB\GenesisDB\CommitEventOptions;
use GenesisDB\GenesisDB\Precondition;
use GenesisDB\GenesisDB\StreamOptions;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    private Client $client;
    private MockHandler $mockHandler;

    protected function setUp(): void
    {
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);

        $this->client = new Client('https://api.example.com', 'v1', 'test-token');

        // Use reflection to replace the HTTP client with our mock
        $reflection = new \ReflectionClass($this->client);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->client, new HttpClient(['handler' => $handlerStack]));
    }

    public function testConstructorWithValidParameters(): void
    {
        $client = new Client('https://api.example.com', 'v1', 'test-token');
        $this->assertInstanceOf(Client::class, $client);
    }

    public function testConstructorThrowsExceptionWithEmptyApiUrl(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required variables: apiUrl, apiVersion, authToken');

        new Client('', 'v1', 'test-token');
    }

    public function testConstructorThrowsExceptionWithEmptyApiVersion(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required variables: apiUrl, apiVersion, authToken');

        new Client('https://api.example.com', '', 'test-token');
    }

    public function testConstructorThrowsExceptionWithEmptyAuthToken(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required variables: apiUrl, apiVersion, authToken');

        new Client('https://api.example.com', 'v1', '');
    }

    public function testStreamEventsReturnsEmptyArrayForEmptyResponse(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/x-ndjson'], '')
        );

        $events = $this->client->streamEvents('test-subject');
        $this->assertEmpty($events);
    }

    public function testStreamEventsReturnsParsedCloudEvents(): void
    {
        $eventJson = json_encode([
            'id' => 'event-1',
            'source' => 'test-source',
            'type' => 'test.event',
            'data' => ['key' => 'value'],
            'subject' => 'test-subject',
            'time' => '2023-01-01T00:00:00Z'
        ]);

        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/x-ndjson'], $eventJson)
        );

        $events = $this->client->streamEvents('test-subject');

        $this->assertCount(1, $events);
        $this->assertInstanceOf(CloudEvent::class, $events[0]);
        $this->assertEquals('event-1', $events[0]->getId());
        $this->assertEquals('test-source', $events[0]->getSource());
        $this->assertEquals('test.event', $events[0]->getType());
    }

    public function testStreamEventsWithOptions(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/x-ndjson'], '')
        );

        $this->client->streamEvents(
            'test-subject',
            new StreamOptions(
                lowerBound: 'lower-bound-id',
                includeLowerBoundEvent: true,
                latestByEventType: 'latest-event-type'
            )
        );

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertEquals('test-subject', $requestBody['subject']);
        $this->assertEquals('lower-bound-id', $requestBody['options']['lowerBound']);
        $this->assertTrue($requestBody['options']['includeLowerBoundEvent']);
        $this->assertEquals('latest-event-type', $requestBody['options']['latestByEventType']);
    }

    public function testStreamEventsWithUpperBound(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/x-ndjson'], '')
        );

        $this->client->streamEvents(
            'test-subject',
            new StreamOptions(
                upperBound: 'upper-bound-id',
                includeUpperBoundEvent: false
            )
        );

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertEquals('test-subject', $requestBody['subject']);
        $this->assertEquals('upper-bound-id', $requestBody['options']['upperBound']);
        $this->assertFalse($requestBody['options']['includeUpperBoundEvent']);
    }

    public function testStreamEventsWithBothBounds(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/x-ndjson'], '')
        );

        $this->client->streamEvents(
            'test-subject',
            new StreamOptions(
                lowerBound: 'lower-bound-id',
                includeLowerBoundEvent: true,
                upperBound: 'upper-bound-id',
                includeUpperBoundEvent: true
            )
        );

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertEquals('test-subject', $requestBody['subject']);
        $this->assertEquals('lower-bound-id', $requestBody['options']['lowerBound']);
        $this->assertTrue($requestBody['options']['includeLowerBoundEvent']);
        $this->assertEquals('upper-bound-id', $requestBody['options']['upperBound']);
        $this->assertTrue($requestBody['options']['includeUpperBoundEvent']);
    }

    public function testStreamEventsWithoutOptions(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/x-ndjson'], '')
        );

        $this->client->streamEvents('test-subject');

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertEquals('test-subject', $requestBody['subject']);
        $this->assertArrayNotHasKey('options', $requestBody);
    }

    public function testCommitEvents(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], '{"success": true}')
        );

        $events = [
            new CommitEvent(
                source: 'test-source',
                subject: 'test-subject',
                type: 'test.event',
                data: ['key' => 'value']
            )
        ];

        $this->client->commitEvents($events);

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertArrayHasKey('events', $requestBody);
        $this->assertCount(1, $requestBody['events']);
        $this->assertEquals('test-source', $requestBody['events'][0]['source']);
        $this->assertEquals('test-subject', $requestBody['events'][0]['subject']);
        $this->assertEquals('test.event', $requestBody['events'][0]['type']);
        $this->assertEquals(['key' => 'value'], $requestBody['events'][0]['data']);
    }

    public function testCommitEventsWithIsSubjectNewPrecondition(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], '{"success": true}')
        );

        $events = [
            new CommitEvent(
                source: 'test-source',
                subject: '/test/subject',
                type: 'test.event.created',
                data: ['name' => 'Test Event']
            )
        ];

        $preconditions = [
            Precondition::isSubjectNew('/test/subject')
        ];

        $this->client->commitEvents($events, $preconditions);

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertArrayHasKey('preconditions', $requestBody);
        $this->assertCount(1, $requestBody['preconditions']);
        $this->assertEquals('isSubjectNew', $requestBody['preconditions'][0]['type']);
        $this->assertEquals('/test/subject', $requestBody['preconditions'][0]['payload']['subject']);
    }

    public function testCommitEventsWithIsSubjectExistingPrecondition(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], '{"success": true}')
        );

        $events = [
            new CommitEvent(
                source: 'test',
                subject: '/test/subject',
                type: 'test.updated',
                data: ['v' => 2]
            )
        ];

        $preconditions = [
            Precondition::isSubjectExisting('/test/subject')
        ];

        $this->client->commitEvents($events, $preconditions);

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertArrayHasKey('preconditions', $requestBody);
        $this->assertEquals('isSubjectExisting', $requestBody['preconditions'][0]['type']);
        $this->assertEquals('/test/subject', $requestBody['preconditions'][0]['payload']['subject']);
    }

    public function testCommitEventsWithIsQueryResultTruePrecondition(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], '{"success": true}')
        );

        $query = "STREAM e FROM events WHERE e.subject == '/test' MAP COUNT() < 100";

        $events = [
            new CommitEvent(
                source: 'test',
                subject: '/test',
                type: 'test.created',
                data: []
            )
        ];

        $preconditions = [
            Precondition::isQueryResultTrue($query)
        ];

        $this->client->commitEvents($events, $preconditions);

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertArrayHasKey('preconditions', $requestBody);
        $this->assertEquals('isQueryResultTrue', $requestBody['preconditions'][0]['type']);
        $this->assertEquals($query, $requestBody['preconditions'][0]['payload']['query']);
    }

    public function testCommitEventsWithMultipleMixedPreconditions(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], '{"success": true}')
        );

        $events = [
            new CommitEvent(
                source: 'test',
                subject: '/user/456',
                type: 'test.user-updated',
                data: ['name' => 'Jane']
            )
        ];

        $preconditions = [
            Precondition::isSubjectExisting('/user/456'),
            Precondition::isQueryResultTrue("STREAM e FROM events WHERE e.data.email == 'john.doe@example.com' MAP COUNT() == 0"),
        ];

        $this->client->commitEvents($events, $preconditions);

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertCount(2, $requestBody['preconditions']);
        $this->assertEquals('isSubjectExisting', $requestBody['preconditions'][0]['type']);
        $this->assertEquals('isQueryResultTrue', $requestBody['preconditions'][1]['type']);
    }

    public function testCommitEventsWithGenericPrecondition(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], '{"success": true}')
        );

        $events = [
            new CommitEvent(
                source: 'test',
                subject: '/test',
                type: 'test.created',
                data: []
            )
        ];

        $preconditions = [
            Precondition::generic('someCustomFuturePrecondition', ['foo' => 'bar', 'baz' => 123])
        ];

        $this->client->commitEvents($events, $preconditions);

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertEquals('someCustomFuturePrecondition', $requestBody['preconditions'][0]['type']);
        $this->assertEquals(['foo' => 'bar', 'baz' => 123], $requestBody['preconditions'][0]['payload']);
    }

    public function testCommitEventsWithoutPreconditions(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], '{"success": true}')
        );

        $events = [
            new CommitEvent(
                source: 'test-source',
                subject: 'test-subject',
                type: 'test.event',
                data: ['key' => 'value']
            )
        ];

        $this->client->commitEvents($events);

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertArrayNotHasKey('preconditions', $requestBody);
    }

    public function testCommitEventsWithOptions(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], '{"success": true}')
        );

        $events = [
            new CommitEvent(
                source: 'test-source',
                subject: 'test-subject',
                type: 'test.event',
                data: ['key' => 'value'],
                options: new CommitEventOptions(storeDataAsReference: true)
            )
        ];

        $this->client->commitEvents($events);

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertArrayHasKey('options', $requestBody['events'][0]);
        $this->assertTrue($requestBody['events'][0]['options']['storeDataAsReference']);
    }

    public function testEraseData(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], '{"success": true}')
        );

        $this->client->eraseData('test-subject');

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertEquals('test-subject', $requestBody['subject']);
    }

    public function testAudit(): void
    {
        $auditResponse = 'Audit log content';
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'text/plain'], $auditResponse)
        );

        $result = $this->client->audit();

        $this->assertEquals($auditResponse, $result);
    }

    public function testPing(): void
    {
        $pingResponse = 'pong';
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'text/plain'], $pingResponse)
        );

        $result = $this->client->ping();

        $this->assertEquals($pingResponse, $result);
    }

    public function testQueryMethod(): void
    {
        $queryResult = json_encode(['result' => 'data']);
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/x-ndjson'], $queryResult)
        );

        $results = $this->client->q('SELECT * FROM events');

        $this->assertCount(1, $results);
        $this->assertEquals('data', $results[0]['result']);

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);
        $this->assertEquals('SELECT * FROM events', $requestBody['query']);
    }

    public function testQueryEventsMethod(): void
    {
        $queryResult = json_encode(['event' => 'data']);
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/x-ndjson'], $queryResult)
        );

        $results = $this->client->queryEvents('SELECT * FROM events WHERE type = "test"');

        $this->assertCount(1, $results);
        $this->assertEquals('data', $results[0]['event']);

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);
        $this->assertEquals('SELECT * FROM events WHERE type = "test"', $requestBody['query']);
    }

    public function testStreamEventsHandlesMultipleEvents(): void
    {
        $event1 = json_encode([
            'id' => 'event-1',
            'source' => 'test-source',
            'type' => 'test.event',
            'data' => ['key' => 'value1'],
            'subject' => 'test-subject',
            'time' => '2023-01-01T00:00:00Z'
        ]);

        $event2 = json_encode([
            'id' => 'event-2',
            'source' => 'test-source',
            'type' => 'test.event',
            'data' => ['key' => 'value2'],
            'subject' => 'test-subject',
            'time' => '2023-01-01T01:00:00Z'
        ]);

        $responseBody = $event1 . "\n" . $event2;

        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/x-ndjson'], $responseBody)
        );

        $events = $this->client->streamEvents('test-subject');

        $this->assertCount(2, $events);
        $this->assertEquals('event-1', $events[0]->getId());
        $this->assertEquals('event-2', $events[1]->getId());
    }

    public function testObserveEventsWithOptions(): void
    {
        $eventJson = json_encode([
            'id' => 'event-1',
            'source' => 'test-source',
            'type' => 'test.event',
            'data' => ['key' => 'value'],
            'subject' => 'test-subject',
            'time' => '2023-01-01T00:00:00Z'
        ]);

        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/x-ndjson'], $eventJson . "\n")
        );

        $generator = $this->client->observeEvents(
            'test-subject',
            new StreamOptions(
                lowerBound: '123',
                includeLowerBoundEvent: true
            )
        );

        $events = iterator_to_array($generator);

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertEquals('test-subject', $requestBody['subject']);
        $this->assertEquals('123', $requestBody['options']['lowerBound']);
        $this->assertTrue($requestBody['options']['includeLowerBoundEvent']);
    }

    public function testObserveEventsWithUpperBound(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/x-ndjson'], '')
        );

        $generator = $this->client->observeEvents(
            'test-subject',
            new StreamOptions(
                lowerBound: '123',
                includeLowerBoundEvent: true,
                upperBound: '456',
                includeUpperBoundEvent: false
            )
        );

        iterator_to_array($generator);

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertEquals('123', $requestBody['options']['lowerBound']);
        $this->assertTrue($requestBody['options']['includeLowerBoundEvent']);
        $this->assertEquals('456', $requestBody['options']['upperBound']);
        $this->assertFalse($requestBody['options']['includeUpperBoundEvent']);
    }

    public function testObserveEventsWithoutOptions(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/x-ndjson'], '')
        );

        $generator = $this->client->observeEvents('test-subject');
        iterator_to_array($generator);

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertEquals('test-subject', $requestBody['subject']);
        $this->assertArrayNotHasKey('options', $requestBody);
    }

    // --- StreamOptions unit tests ---

    public function testStreamOptionsToArrayOmitsNulls(): void
    {
        $options = new StreamOptions();
        $this->assertEquals([], $options->toArray());
    }

    public function testStreamOptionsToArrayIncludesSetValues(): void
    {
        $options = new StreamOptions(
            lowerBound: 'lb',
            includeLowerBoundEvent: true,
            upperBound: 'ub',
            includeUpperBoundEvent: false,
            latestByEventType: 'some.type'
        );

        $this->assertEquals([
            'lowerBound' => 'lb',
            'includeLowerBoundEvent' => true,
            'upperBound' => 'ub',
            'includeUpperBoundEvent' => false,
            'latestByEventType' => 'some.type',
        ], $options->toArray());
    }

    // --- CommitEvent unit tests ---

    public function testCommitEventToArray(): void
    {
        $event = new CommitEvent(
            source: 'io.genesisdb.app',
            subject: '/customer',
            type: 'io.genesisdb.app.customer-added',
            data: ['firstName' => 'Bruce']
        );

        $this->assertEquals([
            'source' => 'io.genesisdb.app',
            'subject' => '/customer',
            'type' => 'io.genesisdb.app.customer-added',
            'data' => ['firstName' => 'Bruce'],
        ], $event->toArray());
    }

    public function testCommitEventToArrayWithOptions(): void
    {
        $event = new CommitEvent(
            source: 'io.genesisdb.app',
            subject: '/customer',
            type: 'io.genesisdb.app.customer-added',
            data: ['firstName' => 'Bruce'],
            options: new CommitEventOptions(storeDataAsReference: true)
        );

        $array = $event->toArray();
        $this->assertArrayHasKey('options', $array);
        $this->assertTrue($array['options']['storeDataAsReference']);
    }

    // --- Precondition unit tests ---

    public function testPreconditionIsSubjectNew(): void
    {
        $p = Precondition::isSubjectNew('/user/123');

        $this->assertEquals('isSubjectNew', $p->type);
        $this->assertEquals(['subject' => '/user/123'], $p->payload);
        $this->assertEquals([
            'type' => 'isSubjectNew',
            'payload' => ['subject' => '/user/123'],
        ], $p->toArray());
    }

    public function testPreconditionIsSubjectExisting(): void
    {
        $p = Precondition::isSubjectExisting('/user/123');

        $this->assertEquals('isSubjectExisting', $p->type);
        $this->assertEquals(['subject' => '/user/123'], $p->payload);
        $this->assertEquals([
            'type' => 'isSubjectExisting',
            'payload' => ['subject' => '/user/123'],
        ], $p->toArray());
    }

    public function testPreconditionIsQueryResultTrue(): void
    {
        $query = "STREAM e FROM events WHERE e.data.email == 'test@example.com' MAP COUNT() == 0";
        $p = Precondition::isQueryResultTrue($query);

        $this->assertEquals('isQueryResultTrue', $p->type);
        $this->assertEquals(['query' => $query], $p->payload);
        $this->assertEquals([
            'type' => 'isQueryResultTrue',
            'payload' => ['query' => $query],
        ], $p->toArray());
    }

    public function testPreconditionGeneric(): void
    {
        $p = Precondition::generic('customType', ['foo' => 'bar']);

        $this->assertEquals('customType', $p->type);
        $this->assertEquals(['foo' => 'bar'], $p->payload);
        $this->assertEquals([
            'type' => 'customType',
            'payload' => ['foo' => 'bar'],
        ], $p->toArray());
    }
}
