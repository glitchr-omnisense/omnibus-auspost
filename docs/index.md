# omnibus/auspost

Australia Post for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): prices,
shipments with their PDF labels, tracking and cancellations, through Australia Post's Shipping and
Tracking API (HTTP basic authentication with the API key and password, the charge account in a
header). The gateway calls:

| Operation | Request |
|---|---|
| rate | `POST /shipping/v1/prices/items` |
| ship | `POST /shipping/v1/shipments`, then `POST /shipping/v1/labels` and `GET` the label's URL |
| track | `GET /shipping/v1/track?tracking_ids={number}` |
| cancel | `GET /shipping/v1/shipments?tracking_ids={number}`, then `DELETE /shipping/v1/shipments?shipment_ids={id}` |

on `https://digitalapi.auspost.com.au` (live) or `https://digitalapi.auspost.com.au/test` (the test
environment, `sandbox: true`). Every call sends `Account-Number` and has a 20-second timeout.

## Installation

```sh
composer require omnibus/auspost
```

Requires PHP 8.2, `omnisense/omnibus` (2.x) and `symfony/http-client` (6.4, 7 or 8); given no
HTTP client, the factory makes one with `HttpClient::create()`. In a Symfony application with
`Omnibus\Bridge\Symfony\OmnibusBundle`, the factory is registered as soon as the package is
installed.

## Credentials

- An Australia Post business account with a **charge account** (10 digits).
- The Shipping and Tracking API at [developers.auspost.com.au](https://developers.auspost.com.au)
  gives the **API key** and **password** (test credentials first).

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `api_key` | string | - | yes | the API key (basic authentication user) |
| `password` | string | - | yes | its password |
| `account_number` | string | - | yes | the charge account, sent as `Account-Number` |
| `sandbox` | bool | `false` | no | `true`: the test environment |
| `rates` | list | - | no | prices from configuration instead of `/prices/items` ([the rates option](https://github.com/glitchr-omnisense/omnibus/blob/2.x/docs/configuration.md#the-rates-option)): with it, `/prices/items` is not called |

Shipment options (`Shipment::$options`):

| Option | Read by | Default | |
|---|---|---|---|
| `sender_state` | rate, ship | - | the sender's state (`VIC`, `NSW`...) |
| `recipient_state` | rate, ship | - | the recipient's state |
| `authority_to_leave` | ship | `false` | the parcel may be left without a signature |
| `layout` | ship | `A4-1pp` | the label sheet: `A4-1pp`, `A4-4pp`, `THERMAL-LABEL-A6-1PP` (always a PDF) |
| `description` | ship | `Merchandise` | the customs description, outside Australia |
| `classification` | ship | `SOLD` | the customs classification type, outside Australia |

The address model has no state: it comes from the two options.

## Capabilities

| Request | Supported |
|---|---|
| `Rating` | yes (`/prices/items`), or configured rates with `rates` |
| `Shipping` | yes |
| `Tracking` | yes |
| `Pickup` | no: parcel lockers and post offices are given as recipient addresses |
| `GetSlip` | no |
| `Cancel` | yes, before the shipment is lodged |
| `Paperless` | no |

## Behaviour

**Addresses and parcels.** A party is its name and business name (40 characters each), its
street lines, the city as `suburb`, the state option, postcode, country, phone and email. A parcel
is an item with its dimensions in centimetres (those given) and its weight in kilograms rounded to
two decimals (at least 0.1).

**Products.** The service is Australia Post's product id; labels come from
`Omnibus\Auspost\Mapping::PRODUCTS` when Australia Post gives no product type:

| Product id | Product |
|---|---|
| `PP` | Parcel Post (the default in Australia) |
| `EXP` | Express Post |
| `PTI8` | International Standard (the default elsewhere) |
| `ECM8` | International Express |
| `AIR8` | International Economy Air |

**Rate.** `POST /prices/items` with both parties and the items, one per parcel. Australia Post
prices each item on its own: a product's rate is the sum of its prices over the parcels, and a
product that does not carry every parcel is left out. Each price: its `product_id`, its
`product_type` (else the table) as label, `calculated_price` (else `calculated_price_ex_gst`) in
`AUD`; `days` is `null`. `Shipment::$service` does not filter them. Rates come cheapest first;
`toPickupPoint` is `false`.

**Ship.** `POST /shipments` with one shipment: the reference (50 characters), tracking emails when
the recipient has an email, both parties, one item per parcel with the product
(`Shipment::$service`, else `PP` in Australia and `PTI8` elsewhere), the parcel's `reference`
(else the shipment's), `authority_to_leave`, no partial delivery, and outside Australia the
customs contents (the `description`, quantity 1, the parcel's `value` or 1.00, its weight, the
sender's country as origin) and the `classification`. The tracking number is the first item's
`article_id` (else its `consignment_id`). Then `POST /labels`, waiting for the label's URL, PDF
format, group `Parcel Post` in Australia and `International` elsewhere (whatever the product),
the `layout`, unbranded, no offsets; when the label is `AVAILABLE`, its URL is fetched into
`content`. The label's `url` is kept either way. When the label request fails, the booking still
succeeds without a label (a comment in `ShippingAction` says `GetSlip` asks again; `GetSlip` is
not supported). The format is `application/pdf`; `trackingUrl` is
`https://auspost.com.au/mypost/track/#/details/<number>`. A response without a shipment id or a
number is a `CarrierException` (`Australia Post booked no shipment.`).

> [!WARNING]
> Known defect: the label group is `Parcel Post` in Australia whatever the product (Express Post included), where it should follow the product.

**Track.** `GET /track` for the number; the locale is not sent. The events of the first trackable
item (else of the result): their date, description and location; no event code is kept. Events
are sorted oldest first. The status is read from the result's `status` (else the latest event's
description); each event's from its description, with these words tried in order:

| The text contains | `TrackingStatus` |
|---|---|
| `delivered` | `DELIVERED` |
| `on board`, `out for delivery`, `with driver` | `OUT_FOR_DELIVERY` |
| `awaiting collection`, `ready for pickup` | `AVAILABLE_FOR_PICKUP` |
| `return` | `RETURNED` |
| `unsuccessful`, `delayed`, `unable` | `EXCEPTION` |
| `created`, `sealed`, `pending` | `PENDING` |
| `transit`, `received`, `processed`, `initiated` | `IN_TRANSIT` |
| anything else | `UNKNOWN` |

**Cancel.** The shipment is found by the tracking number (`GET /shipments?tracking_ids=`); none
found gives `false`. Then `DELETE /shipments?shipment_ids=<id>`: `true` when it does not fail.
Australia Post deletes only shipments not yet lodged in an order.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnibus\Auspost\AuspostGatewayFactory;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Symfony\Component\HttpClient\HttpClient;

$auspost = (new AuspostGatewayFactory(HttpClient::create()))->create([
    'api_key' => getenv('AUSPOST_API_KEY'),
    'password' => getenv('AUSPOST_PASSWORD'),
    'account_number' => getenv('AUSPOST_ACCOUNT'),
    'sandbox' => true,
]);

$shipment = new Shipment(
    new Address('Glitch Art', ['1 Collins St'], '3000', 'Melbourne', 'AU', phone: '0391234567'),
    new Address('Ada Lovelace', ['1 Macquarie St'], '2000', 'Sydney', 'AU', email: 'ada@example.org'),
    [new Parcel(1500, 30, 20, 10, reference: 'BOX-1')],
    service: 'PP',
    reference: 'CMD-1042',
    options: ['sender_state' => 'VIC', 'recipient_state' => 'NSW', 'authority_to_leave' => true, 'layout' => 'A4-1pp'],
);

foreach ($auspost->rate($shipment) as $rate) {
    printf("%-4s %-14s %6.2f %s\n", $rate->service, $rate->label, $rate->amount / 100, $rate->currency);
}

$label = $auspost->ship($shipment);
if (null !== $label->content) {
    file_put_contents($label->trackingNumber.'.pdf', $label->content);
}
echo $label->trackingNumber, ' ', $label->trackingUrl, "\n";

$tracking = $auspost->track($label->trackingNumber);
echo $tracking->status->value, ' ', $tracking->latest()?->location, "\n";

var_dump($auspost->cancel($label->trackingNumber));
```

In Symfony:

```yaml
omnibus:
    gateways:
        auspost:
            factory: auspost
            options:
                api_key: '%env(AUSPOST_API_KEY)%'
                password: '%env(AUSPOST_PASSWORD)%'
                account_number: '%env(AUSPOST_ACCOUNT)%'
                sandbox: '%env(bool:AUSPOST_SANDBOX)%'
```

## Errors

Every failure is an `Omnibus\Exception\CarrierException` whose `carrier` is `auspost`:

- an HTTP status of 400 or more, or an answer with `errors`: the first error's `message` (else
  `error_message`, else `HTTP <status>`), its `code` (else `error_code`) in `carrierCode`;
- a body that is neither JSON nor a document: `Australia Post answered HTTP <status> with a body
  that is not JSON.`; a network error: `Australia Post request failed: ...`;
- `Australia Post booked no shipment.` when no shipment id or number comes back.

A failed label request after the shipment is created is not raised (above). A missing `api_key`,
`password` or `account_number` is an `InvalidConfigException` when the gateway is built; `Pickup`,
`GetSlip` and `Paperless` raise `RequestNotSupportedException` before any call.

## Verified, and not

Tested offline: `Tests/AuspostGatewayTest.php` (prices cheapest first in `AUD`, the
`Account-Number` header, the recipient's state, the weight in kilograms; two parcels priced as
the sum of their prices, a product that carries only one of them left out; a shipment created with
product `PP` and tracking emails, its label requested by shipment id and fetched; tracking with its
location; the cancellation through the shipment id) and `Tests/AuspostContractTest.php`, the
family's conformance suite on recorded answers. The example above was run in the core's harness on
those recorded answers.

Not verified: the README says the package is built from Australia Post's published API
documentation and was not yet run against the test environment; that needs the credentials above.
Unchecked: whether the label group should follow the product (an Express Post label is asked for
in the `Parcel Post` group), and rating several parcels, of which only the first is priced.

## Responsibility

An independent, unofficial client, not affiliated with Australia Post (the Australian Postal
Corporation). Whoever runs it complies with Australia Post's terms ([API Licence Terms of
Use](https://auspost.com.au/terms-conditions/api-licence-terms-and-conditions)): see the README's
Responsibility section.
