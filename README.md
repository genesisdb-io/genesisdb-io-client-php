# Genesis DB PHP SDK

This is the official PHP SDK for Genesis DB, an awesome and production ready event store database system for building event-driven apps.

## Genesis DB Advantages

* Incredibly fast when reading, fast when writing 🚀
* Easy backup creation and recovery
* [CloudEvents](https://cloudevents.io/) compatible
* GDPR-ready
* Easily accessible via the HTTP interface
* Auditable. Guarantee database consistency
* Logging and metrics for Prometheus
* SQL like query language called Genesis DB Query Language (GDBQL)

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
    public function streamEvents(
        string $subject,
        ?string $lowerBound = null,
        ?bool $includeLowerBoundEvent = null,
        ?string $latestByEventType = null
    ): array
    {
        return $this->genesisDbClient()->streamEvents(
            $subject,
            $lowerBound,
            $includeLowerBoundEvent,
            $latestByEventType
        );
    }

     /**
     * @param string $subject
     * @param string|null $lowerBound
     * @param bool|null $includeLowerBoundEvent
     * @param string|null $latestByEventType
     * @return \Generator
     */
    public function observeEvents(
        string $subject,
        ?string $lowerBound = null,
        ?bool $includeLowerBoundEvent = null,
        ?string $latestByEventType = null
    ): \Generator
    {
        return $this->genesisDbClient()->observeEvents(
            $subject,
            $lowerBound,
            $includeLowerBoundEvent,
            $latestByEventType
        );
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
    public function queryEvents(string $query): array
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

### Basic Event Streaming

```php
// Stream all events for a subject
$events = $client->streamEvents('/customer');

foreach ($events as $event) {
    echo "Event Type: " . $event['type'] . ", Data: " . json_encode($event['data']) . "\n";
}
```

### Stream Events from Lower Bound

```php
// Stream events from a specific lower bound
$events = $client->streamEvents(
    '/',
    '2d6d4141-6107-4fb2-905f-445730f4f2a9',
    true
);

foreach ($events as $event) {
    echo "Event Type: " . $event['type'] . ", Data: " . json_encode($event['data']) . "\n";
}
```


### Stream Latest Events by Event Type

```php
// Get latest events by event type
$latestEvents = $client->streamEvents(
    '/',
    null,
    null,
    'io.genesisdb.app.customer-updated'
);

foreach ($latestEvents as $event) {
    echo "Latest Event: " . $event['type'] . "\n";
}
```

### Observe Events in Real-Time

```php
// Observe events as they occur
foreach ($client->observeEvents('/customer') as $event) {
    echo "Received event: " . $event->getType() . "\n";
    echo "Data: " . json_encode($event->getData()) . "\n";
}
```

### Observe Events from Lower Bound (Message Queue)

```php
// Observe events from a specific point
foreach ($client->observeEvents('/customer', '2d6d4141-6107-4fb2-905f-445730f4f2a9', true) as $event) {
    echo "Received event: " . $event->getType() . "\n";
}
```


### Observe Latest Events by Event Type (Message Queue)

```php
// Observe only latest events of a specific type
foreach ($client->observeEvents('/customer', null, null, 'io.genesisdb.app.customer-updated') as $event) {
    echo "Latest event: " . $event->getType() . "\n";
}
```

### Committing Events

```php
// Commit events without preconditions
$events = [
    [
        'source' => 'io.genesisdb.app',
        'subject' => '/customer',
        'type' => 'io.genesisdb.app.customer-added',
        'data' => [
            'firstName' => 'Bruce',
            'lastName' => 'Wayne',
            'emailAddress' => 'bruce.wayne@enterprise.wayne'
        ]
    ],
    [
        'source' => 'io.genesisdb.app',
        'subject' => '/customer',
        'type' => 'io.genesisdb.app.customer-added',
        'data' => [
            'firstName' => 'Alfred',
            'lastName' => 'Pennyworth',
            'emailAddress' => 'alfred.pennyworth@enterprise.wayne'
        ]
    ],
    [
        'source' => 'io.genesisdb.store',
        'subject' => '/article',
        'type' => 'io.genesisdb.store.article-added',
        'data' => [
            'name' => 'Tumbler',
            'color' => 'black',
            'price' => 2990000.00
        ]
    ],
    [
        'source' => 'io.genesisdb.app',
        'subject' => '/customer/fed2902d-0135-460d-8605-263a06308448',
        'type' => 'io.genesisdb.app.customer-personaldata-changed',
        'data' => [
            'firstName' => 'Angus',
            'lastName' => 'MacGyver',
            'emailAddress' => 'angus.macgyer@phoenix.foundation'
        ]
    ]
];

$client->commitEvents($events);
```

## Preconditions

Preconditions allow you to enforce certain checks on the server before committing events. Genesis DB supports multiple precondition types:

### isSubjectNew
Ensures that a subject is new (has no existing events):

```php
$events = [
    [
        'source' => 'io.genesisdb.app',
        'subject' => '/user/456',
        'type' => 'io.genesisdb.app.user-created',
        'data' => [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john.doe@example.com'
        ]
    ]
];

$preconditions = [
    [
        'type' => 'isSubjectNew',
        'payload' => [
            'subject' => '/user/456'
        ]
    ]
];

$client->commitEvents($events, $preconditions);
```

### isQueryResultTrue
Evaluates a query and ensures the result is truthy. Supports the full GDBQL feature set including complex WHERE clauses, aggregations, and calculated fields.

**Basic uniqueness check:**
```php
$events = [
    [
        'source' => 'io.genesisdb.app',
        'subject' => '/user/456',
        'type' => 'io.genesisdb.app.user-created',
        'data' => [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john.doe@example.com'
        ]
    ]
];

$preconditions = [
    [
        'type' => 'isQueryResultTrue',
        'payload' => [
            'query' => "STREAM e FROM events WHERE e.data.email == 'john.doe@example.com' MAP COUNT() == 0"
        ]
    ]
];

$client->commitEvents($events, $preconditions);
```

**Business rule enforcement (transaction limits):**
```php
$events = [
    [
        'source' => 'io.genesisdb.banking',
        'subject' => '/user/123/transactions',
        'type' => 'io.genesisdb.banking.transaction-processed',
        'data' => [
            'amount' => 500.00,
            'currency' => 'EUR'
        ]
    ]
];

$preconditions = [
    [
        'type' => 'isQueryResultTrue',
        'payload' => [
            'query' => "STREAM e FROM events WHERE e.subject UNDER '/user/123' AND e.type == 'transaction-processed' AND e.time >= '2024-01-01T00:00:00Z' MAP SUM(e.data.amount) + 500 <= 10000"
        ]
    ]
];

$client->commitEvents($events, $preconditions);
```

**Complex validation with aggregations:**
```php
$events = [
    [
        'source' => 'io.genesisdb.events',
        'subject' => '/conference/2024/registrations',
        'type' => 'io.genesisdb.events.registration-created',
        'data' => [
            'attendeeId' => 'att-789',
            'ticketType' => 'premium'
        ]
    ]
];

$preconditions = [
    [
        'type' => 'isQueryResultTrue',
        'payload' => [
            'query' => "STREAM e FROM events WHERE e.subject UNDER '/conference/2024/registrations' AND e.type == 'registration-created' GROUP BY e.data.ticketType HAVING e.data.ticketType == 'premium' MAP COUNT() < 50"
        ]
    ]
];

$client->commitEvents($events, $preconditions);
```

**Supported GDBQL Features in Preconditions:**
- WHERE conditions with AND/OR/IN/BETWEEN operators
- Hierarchical subject queries (UNDER, DESCENDANTS)
- Aggregation functions (COUNT, SUM, AVG, MIN, MAX)
- GROUP BY with HAVING clauses
- ORDER BY and LIMIT clauses
- Calculated fields and expressions
- Nested field access (e.data.address.city)
- String concatenation and arithmetic operations

If a precondition fails, the commit returns HTTP 412 (Precondition Failed) with details about which condition failed.

## GDPR Compliance

### Store Data as Reference

```php
$events = [
    [
        'source' => 'io.genesisdb.app',
        'subject' => '/user/456',
        'type' => 'io.genesisdb.app.user-created',
        'data' => [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john.doe@example.com'
        ],
        'options' => [
            'storeDataAsReference' => true
        ]
    ]
];

$client->commitEvents($events);
```

### Delete Referenced Data

```php
// Erase all data for a specific subject (GDPR compliance)
$client->eraseData('/user/456');
```

## Querying Events

```php
// Query using GDBQL
$query = 'STREAM e FROM events WHERE e.type == "io.genesisdb.app.customer-added" ORDER BY e.time DESC LIMIT 20 MAP { subject: e.subject, firstName: e.data.firstName }';

$results = $client->q($query);

foreach ($results as $result) {
    echo "Result: " . json_encode($result) . "\n";
}
```

## Health Checks

```php
// Check API status
$pingResponse = $client->ping();
echo "Ping response: " . $pingResponse . "\n";

// Run audit to check event consistency
$auditResponse = $client->audit();
echo "Audit response: " . $auditResponse . "\n";
```

## License

MIT

## Author

* E-Mail: mail@genesisdb.io
* URL: https://www.genesisdb.io
* Docs: https://docs.genesisdb.io
