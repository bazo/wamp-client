# WAMP Client

## About

This library has been tested with Ratchet WAMP server. It can only send messages to the server, listening for replies is not implemented.
Supported functions:
 - prefix
 - call
 - publish
 - event

## Usage

```php
$client = new \WAMP\WAMPClient('http://localhost:8080');
$sessionId = $client->connect();

//establish a prefix on server
$client->prefix("calc", "http://example.com/simple/calc#");

//you can send arbitrary number of arguments
$client->call('calc', 12,14,15);

$data = [0, 1, 2];

//or array
$client->call('calc', $data);

publish an event

//$payload can be scalar or array
$exclude = [$sessionId]; //no sense in sending the payload to ourselves
$eligible = [...] //list of other clients ids that are eligible to receive this payload
$client->publish('topic', $payload, $exclude, $eligible);

$client->event('topic', $payload);

```

## License

This software is distributed under MIT License. See LICENSE for more info.

## Author

Martin Bažík <martin@bazo.sk>

## Thanks

Thanks to Elephant.IO authors for the websocket communication part.
