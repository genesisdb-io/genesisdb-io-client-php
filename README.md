# Genesis DB PHP SDK

A PHP SDK for working with Genesis DB

## Installation

Just run:

```
composer require genesisdb/client-sdk
```

## Usage
```php
use GenesisDB\GenesisDB\Client;

final class AcmeClass
{

    /**
     * @return Client
     */
    private function genesisDbClient(): Client
    {
        return new Client($this->apiUrl, $this->apiVersion, $this->authToken);
    }

    /**
     * @param string $subject
     * @param string|null $lowerBound
     * @param bool|null $includeLowerBoundEvent
     * @param string|null $latestByEventType
     * @return array
     */
    public function streamEvents(string $subject, ?string $lowerBound = null, ?bool $includeLowerBoundEvent = null, ?string $latestByEventType = null): array
    {
        return $this->genesisDbClient()->streamEvents($subject, $lowerBound, $includeLowerBoundEvent, $latestByEventType);
    }

     /**
     * @param string $subject
     * @param string|null $lowerBound
     * @param bool|null $includeLowerBoundEvent
     * @param string|null $latestByEventType
     * @return \Generator
     */
    public function observeEvents(string $subject, ?string $lowerBound = null, ?bool $includeLowerBoundEvent = null, ?string $latestByEventType = null): \Generator
    {
        return $this->genesisDbClient()->observeEvents($subject, $lowerBound, $includeLowerBoundEvent, $latestByEventType);
    }

    /**
     * @param array $events Array of events with subject, type, and data
     * @param array|null $preconditions Optional preconditions for the commit
     * @return void
     */
    public function commitEvents(array $events, ?array $preconditions = null): void
    {
        $this->genesisDbClient()->commitEvents($events, $preconditions);
    }

    /**
     * @param string $subject
     * @return void
     */
    public function eraseData(string $subject): void
    {
        $this->genesisDbClient()->eraseData($subject);
    }

    /**
     * @param string $query
     * @return array
     */
    public function q(string $query): array
    {
        return $this->genesisDbClient()->q($query);
    }

    /**
     * @return string
     */
    public function audit(): string
    {
        return $this->genesisDbClient()->audit();
    }

    /**
     * @return string
     */
    public function ping(): string
    {
        return $this->genesisDbClient()->ping();
    }

}
```

## Examples

### Basic Event Committing

```php
// Commit events without preconditions
$events = [
    [
        'source' => 'io.genesisdb.test',
        'subject' => '/user/123',
        'type' => 'io.genesisdb.test.user-created',
        'data' => ['email' => 'user@example.com', 'name' => 'John Doe']
    ]
];

$client->commitEvents($events);

// Commit events with preconditions
$preconditions = [
    [
        'type' => 'isSubjectNew',
        'payload' => [
            'subject' => '/user/123'
        ]
    ]
];

$client->commitEvents($events, $preconditions);
```

### Enhanced Streaming

```php
// Stream events from a specific lower bound
$events = $client->streamEvents('/user/123', 'event-id-123', true);

// Get latest events by event type
$latestEvents = $client->streamEvents('/user/123', null, null, 'io.genesisdb.test.user-updated');

// Observe events with lower bound
foreach ($client->observeEvents('/user/123', 'event-id-123', true) as $event) {
    echo "Received event: " . $event->getType() . "\n";
}
```

## Preconditions

Preconditions allow you to enforce certain checks on the server before committing events. Genesis DB supports multiple precondition types:

### isSubjectNew
Ensures that a subject is new (has no existing events):

```php
$events = [
    [
        'source' => 'io.genesisdb.test',
        'subject' => '/bar/1caaf703-cdbe-449e-880c-8309acfafa46/foo2',
        'type' => 'io.genesisdb.test.article-recorded',
        'data' => [
            'value' => 'Should not2',
            'foo' => null
        ]
    ]
];

$preconditions = [
    [
        'type' => 'isSubjectNew',
        'payload' => [
            'subject' => '/bar/1caaf703-cdbe-449e-880c-8309acfafa46/foo'
        ]
    ]
];

$client->commitEvents($events, $preconditions);
```

### isQueryResultTrue
Evaluates a query and ensures the result is truthy:

```php
$events = [
    [
        'source' => 'io.genesisdb.app',
        'subject' => '/event/conf-2024',
        'type' => 'io.genesisdb.app.registration-added',
        'data' => [
            'attendeeName' => 'Alice',
            'eventId' => 'conf-2024'
        ]
    ]
];

$preconditions = [
    [
        'type' => 'isQueryResultTrue',
        'payload' => [
            'query' => "FROM e IN events WHERE e.data.eventId == 'conf-2024' PROJECT INTO COUNT() < 500"
        ]
    ]
];

$client->commitEvents($events, $preconditions);
```

## License

MIT

## Author

* E-Mail: mail@genesisdb.io
* URL: https://www.genesisdb.io
* Docs: https://docs.genesisdb.io
