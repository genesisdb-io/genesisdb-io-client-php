# GenesisDB PHP SDK

This is the official PHP SDK for GenesisDB, an awesome and production ready event store database system for building event-driven apps.

## GenesisDB Advantages

* Incredibly fast when reading, fast when writing 🚀
* Easy backup creation and recovery
* [CloudEvents](https://cloudevents.io/) compatible
* GDPR-ready
* Easily accessible via the HTTP interface
* Auditable. Guarantee database consistency
* Logging and metrics for Prometheus
* SQL like query language called GenesisDB Query Language (GDBQL)
* ...

## Installation

```
composer require genesisdb/client-sdk
```

## Configuration

### Basic Setup

```php
use GenesisDB\GenesisDB\Client;

$client = new Client(
    apiUrl: 'http://localhost:8080',
    apiVersion: 'v1',
    authToken: '<secret>'
);
```

## Streaming Events

### Basic Event Streaming

```php
// Stream all events for a subject
$events = $client->streamEvents('/customer');
```

### Stream Events from Lower Bound

```php
use GenesisDB\GenesisDB\StreamOptions;

$events = $client->streamEvents('/', new StreamOptions(
    lowerBound: '2d6d4141-6107-4fb2-905f-445730f4f2a9',
    includeLowerBoundEvent: true
));
```

### Stream Events with Upper Bound

```php
$events = $client->streamEvents('/', new StreamOptions(
    upperBound: '9f3e4141-7208-4fb2-905f-445730f4f3b1',
    includeUpperBoundEvent: false
));
```

### Stream Events with Both Lower and Upper Bounds

```php
$events = $client->streamEvents('/', new StreamOptions(
    lowerBound: '2d6d4141-6107-4fb2-905f-445730f4f2a9',
    includeLowerBoundEvent: true,
    upperBound: '9f3e4141-7208-4fb2-905f-445730f4f3b1',
    includeUpperBoundEvent: false
));
```

### Stream Latest Events by Event Type

```php
$events = $client->streamEvents('/', new StreamOptions(
    latestByEventType: 'io.genesisdb.app.customer-updated'
));
```

This feature allows you to stream only the latest event of a specific type for each subject. Useful for getting the current state of entities.

## Committing Events

### Basic Event Committing

```php
use GenesisDB\GenesisDB\CommitEvent;

$client->commitEvents([
    new CommitEvent(
        source: 'io.genesisdb.app',
        subject: '/customer',
        type: 'io.genesisdb.app.customer-added',
        data: [
            'firstName' => 'Bruce',
            'lastName' => 'Wayne',
            'emailAddress' => 'bruce.wayne@enterprise.wayne'
        ]
    ),
    new CommitEvent(
        source: 'io.genesisdb.app',
        subject: '/customer',
        type: 'io.genesisdb.app.customer-added',
        data: [
            'firstName' => 'Alfred',
            'lastName' => 'Pennyworth',
            'emailAddress' => 'alfred.pennyworth@enterprise.wayne'
        ]
    ),
    new CommitEvent(
        source: 'io.genesisdb.store',
        subject: '/article',
        type: 'io.genesisdb.store.article-added',
        data: [
            'name' => 'Tumbler',
            'color' => 'black',
            'price' => 2990000.00
        ]
    ),
    new CommitEvent(
        source: 'io.genesisdb.app',
        subject: '/customer/fed2902d-0135-460d-8605-263a06308448',
        type: 'io.genesisdb.app.customer-personaldata-changed',
        data: [
            'firstName' => 'Angus',
            'lastName' => 'MacGyver',
            'emailAddress' => 'angus.macgyer@phoenix.foundation'
        ]
    )
]);
```

## Preconditions

Preconditions allow you to enforce certain checks on the server before committing events. GenesisDB supports multiple precondition types:

### isSubjectNew
Ensures that a subject is new (has no existing events):

```php
use GenesisDB\GenesisDB\Precondition;

$client->commitEvents([
    new CommitEvent(
        source: 'io.genesisdb.app',
        subject: '/user/456',
        type: 'io.genesisdb.app.user-created',
        data: [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john.doe@example.com'
        ]
    )
], [
    Precondition::isSubjectNew('/user/456')
]);
```

### isSubjectExisting
Ensures that events exist for the specified subject:

```php
$client->commitEvents([
    new CommitEvent(
        source: 'io.genesisdb.app',
        subject: '/user/456',
        type: 'io.genesisdb.app.user-updated',
        data: [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john.doe@example.com'
        ]
    )
], [
    Precondition::isSubjectExisting('/user/456')
]);
```

### isQueryResultTrue
Evaluates a query and ensures the result is truthy. Supports the full GDBQL feature set including complex WHERE clauses, aggregations, and calculated fields.

**Basic uniqueness check:**
```php
$client->commitEvents([
    new CommitEvent(
        source: 'io.genesisdb.app',
        subject: '/user/456',
        type: 'io.genesisdb.app.user-created',
        data: [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john.doe@example.com'
        ]
    )
], [
    Precondition::isQueryResultTrue(
        "STREAM e FROM events WHERE e.data.email == 'john.doe@example.com' MAP COUNT() == 0"
    )
]);
```

**Business rule enforcement (transaction limits):**
```php
$client->commitEvents([
    new CommitEvent(
        source: 'io.genesisdb.banking',
        subject: '/user/123/transactions',
        type: 'io.genesisdb.banking.transaction-processed',
        data: [
            'amount' => 500.00,
            'currency' => 'EUR'
        ]
    )
], [
    Precondition::isQueryResultTrue(
        "STREAM e FROM events WHERE e.subject UNDER '/user/123' AND e.type == 'transaction-processed' AND e.time >= '2024-01-01T00:00:00Z' MAP SUM(e.data.amount) + 500 <= 10000"
    )
]);
```

**Complex validation with aggregations:**
```php
$client->commitEvents([
    new CommitEvent(
        source: 'io.genesisdb.events',
        subject: '/conference/2024/registrations',
        type: 'io.genesisdb.events.registration-created',
        data: [
            'attendeeId' => 'att-789',
            'ticketType' => 'premium'
        ]
    )
], [
    Precondition::isQueryResultTrue(
        "STREAM e FROM events WHERE e.subject UNDER '/conference/2024/registrations' AND e.type == 'registration-created' GROUP BY e.data.ticketType HAVING e.data.ticketType == 'premium' MAP COUNT() < 50"
    )
]);
```

**Generic precondition (forward-compatible with future server releases):**
```php
$client->commitEvents([
    new CommitEvent(
        source: 'io.genesisdb.app',
        subject: '/test',
        type: 'io.genesisdb.app.test-created',
        data: ['key' => 'value']
    )
], [
    Precondition::generic('someCustomFuturePrecondition', ['foo' => 'bar', 'baz' => 123])
]);
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
use GenesisDB\GenesisDB\CommitEventOptions;

$client->commitEvents([
    new CommitEvent(
        source: 'io.genesisdb.app',
        subject: '/user/456',
        type: 'io.genesisdb.app.user-created',
        data: [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john.doe@example.com'
        ],
        options: new CommitEventOptions(storeDataAsReference: true)
    )
]);
```

### Delete Referenced Data

```php
$client->eraseData('/user/456');
```

## Observing Events

### Basic Event Observation

```php
foreach ($client->observeEvents('/customer') as $event) {
    echo "Received event: " . $event->getType() . "\n";
    echo "Data: " . json_encode($event->getData()) . "\n";
}
```

### Observe Events from Lower Bound (Message Queue)

```php
foreach ($client->observeEvents('/customer', new StreamOptions(
    lowerBound: '2d6d4141-6107-4fb2-905f-445730f4f2a9',
    includeLowerBoundEvent: true
)) as $event) {
    echo "Received event: " . $event->getType() . "\n";
}
```

### Observe Events with Upper Bound (Message Queue)

```php
foreach ($client->observeEvents('/customer', new StreamOptions(
    upperBound: '9f3e4141-7208-4fb2-905f-445730f4f3b1',
    includeUpperBoundEvent: false
)) as $event) {
    echo "Received event: " . $event->getType() . "\n";
}
```

### Observe Events with Both Bounds (Message Queue)

```php
foreach ($client->observeEvents('/customer', new StreamOptions(
    lowerBound: '2d6d4141-6107-4fb2-905f-445730f4f2a9',
    includeLowerBoundEvent: true,
    upperBound: '9f3e4141-7208-4fb2-905f-445730f4f3b1',
    includeUpperBoundEvent: false
)) as $event) {
    echo "Received event: " . $event->getType() . "\n";
}
```

### Observe Latest Events by Event Type (Message Queue)

```php
foreach ($client->observeEvents('/customer', new StreamOptions(
    latestByEventType: 'io.genesisdb.app.customer-updated'
)) as $event) {
    echo "Received latest event: " . $event->getType() . "\n";
}
```

## Querying Events

```php
$results = $client->queryEvents(
    'STREAM e FROM events WHERE e.type == "io.genesisdb.app.customer-added" ORDER BY e.time DESC LIMIT 20 MAP { subject: e.subject, firstName: e.data.firstName }'
);

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
