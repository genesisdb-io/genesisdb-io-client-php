<?php

namespace GenesisDB\GenesisDB\Tests;

use CloudEvents\V1\CloudEvent;
use GenesisDB\GenesisDB\Client;
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

    public function testStreamEventsWithParameters(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/x-ndjson'], '')
        );

        $this->client->streamEvents(
            'test-subject',
            'lower-bound-id',
            true,
            'latest-event-type'
        );

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertEquals('test-subject', $requestBody['subject']);
        $this->assertEquals('lower-bound-id', $requestBody['lowerBound']);
        $this->assertTrue($requestBody['includeLowerBoundEvent']);
        $this->assertEquals('latest-event-type', $requestBody['latestByEventType']);
    }

    public function testCommitEvents(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], '{"success": true}')
        );

        $events = [
            [
                'source' => 'test-source',
                'subject' => 'test-subject',
                'type' => 'test.event',
                'data' => ['key' => 'value']
            ]
        ];

        $this->client->commitEvents($events);

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertArrayHasKey('events', $requestBody);
        $this->assertCount(1, $requestBody['events']);
        $this->assertEquals('test-source', $requestBody['events'][0]['source']);
    }

    public function testCommitEventsWithPreconditions(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], '{"success": true}')
        );

        $events = [
            [
                'source' => 'test-source',
                'subject' => 'test-subject',
                'type' => 'test.event',
                'data' => ['key' => 'value']
            ]
        ];

        $preconditions = ['expectedVersion' => 5];

        $this->client->commitEvents($events, $preconditions);

        $request = $this->mockHandler->getLastRequest();
        $requestBody = json_decode($request->getBody()->getContents(), true);

        $this->assertArrayHasKey('preconditions', $requestBody);
        $this->assertEquals(5, $requestBody['preconditions']['expectedVersion']);
    }

    public function testCommitEventsWithOptions(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], '{"success": true}')
        );

        $events = [
            [
                'source' => 'test-source',
                'subject' => 'test-subject',
                'type' => 'test.event',
                'data' => ['key' => 'value'],
                'options' => ['storeDataAsReference' => true]
            ]
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
}