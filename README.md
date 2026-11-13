# omnibus/auspost

Australia Post for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): prices,
shipments with their labels, tracking and cancellations - the Shipping and Tracking API
(basic auth with the API key, the charge account in a header).

## Install and a first call

```sh
composer require omnibus/auspost
```

No account for this first call: Australia Post's answer is recorded
(`Omnibus\Testing\RecordedHttpClient` answers in place of the API), so nothing leaves the process.
With your own credentials, give the factory your HTTP client (or none: it makes its own) and drop
the recorded one.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnibus\Auspost\AuspostGatewayFactory;
use Omnibus\Testing\RecordedHttpClient;

// Australia Post's answer, recorded: no account, and nothing leaves the process.
$http = new RecordedHttpClient([
    '/track' => ['tracking_results' => [['tracking_id' => 'ABC000128B03', 'status' => 'Delivered', 'trackable_items' => [['events' => [
        ['location' => 'SYDNEY NSW', 'description' => 'Delivered', 'date' => '2026-10-02T10:15:00+10:00'],
    ]]]]]],
]);
$gateway = (new AuspostGatewayFactory($http))->create(['api_key' => 'key', 'password' => 'pass', 'account_number' => '0000000000', 'sandbox' => true]);

$tracking = $gateway->track('ABC000128B03');
echo $tracking->status->value, ' - ', $tracking->latest()?->description, "\n";   // delivered - Delivered
```

A real call needs a Australia Post account (the credentials below). The answer is shaped after
Australia Post's published documentation; the package has not been run against its service yet.

## Configuration

```php
$gateway = (new AuspostGatewayFactory($http))->create($options);   // $http: the application's HTTP client - none given, the factory makes its own; the options below
```

No framework needed: the package requires `omnisense/omnibus` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

```yaml
omnibus:
    gateways:
        auspost:
            factory: auspost
            options:
                api_key: '%env(AUSPOST_API_KEY)%'
                password: '%env(AUSPOST_PASSWORD)%'
                account_number: '%env(AUSPOST_ACCOUNT)%'   # the 10-digit charge account
                sandbox: true
                rates: [...]                               # optional: configured prices instead of /prices/items
```

The service is the product id (PP Parcel Post, EXP Express Post, PTI8 International Standard,
ECM8 International Express...). Shipment options: `sender_state` and `recipient_state`
(VIC, NSW...), `authority_to_leave`, `layout` (A4-1pp, A4-4pp, THERMAL-LABEL-A6-1PP),
`description` and `classification` (customs). No pickup points: parcel lockers and post offices
are addressed as recipient addresses.

Credentials: a business account with a charge account, then the Shipping and Tracking API at
[developers.auspost.com.au](https://developers.auspost.com.au) gives the key and password
(test credentials first).

Built from Australia Post's published API documentation and tested on recorded answers; not yet
run against the test environment: that needs the credentials above.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Australia Post (the Australian Postal Corporation). It reaches their service on behalf
of whoever runs it, and it does not redistribute their data beyond the short excerpts recorded as
test fixtures. Its MIT licence covers this package's code only and grants no right to the service or
to its data.

Whoever installs and runs this package is responsible for complying with Australia Post's terms
([API Licence Terms of
Use](https://auspost.com.au/terms-conditions/api-licence-terms-and-conditions)), for obtaining
whatever they require (an account, a key, a licence or subscription, an attribution or a citation, a
rate limit to respect), and for any use made of what it returns. The authors publish it free of
charge, make no commercial use of it, and accept no liability for how it is used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
