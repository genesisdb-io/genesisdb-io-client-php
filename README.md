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
     * @return array
     */
    public function streamEvents(string $subject): array
    {
        return $this->genesisDbClient()->streamEvents($subject);
    }
    
     /**
     * @param string $subject
     * @return \Generator
     */
    public function observeEvents(string $subject): \Generator
    {
        return $this->genesisDbClient()->observeEvents($subject);
    }

    /**
     * @param array $events
     * @return void
     */
    public function commitEvents(array $events): void
    {
        $this->genesisDbClient()->commitEvents($events);
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
     * @return bool
     */
    public function ping(): bool
    {
        return $this->genesisDbClient()->ping() === 'pong';
    }
    
}
```


## Author

* E-Mail: mail@genesisdb.io
* URL: https://www.genesisdb.io
