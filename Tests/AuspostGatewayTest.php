<?php

namespace Omnibus\Auspost\Tests;

use Omnibus\Auspost\AuspostGatewayFactory;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Model\TrackingStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AuspostGatewayTest extends TestCase
{
    private array $calls = [];

    private ?array $prices = null;

    private static function shipment(): Shipment
    {
        return new Shipment(new Address('Glitch Art', ['1 Collins St'], '3000', 'Melbourne', 'AU', phone: '0390000000'), new Address('Alex Martin', ['200 George St'], '2000', 'Sydney', 'AU', email: 'alex@example.org'), [new Parcel(1500, 30, 20, 10)], reference: 'ORDER-1042', options: ['sender_state' => 'VIC', 'recipient_state' => 'NSW']);
    }

    private function gateway(): \Omnibus\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $query = (string) parse_url($url, \PHP_URL_QUERY);
            $this->calls[] = [$method, $path, \is_string($options['body'] ?? null) && str_starts_with($options['body'], '{') ? json_decode($options['body'], true) : [], $options['headers'], $query];

            return match (true) {
                'POST' === $method && str_ends_with($path, '/prices/items') => new MockResponse(json_encode($this->prices ?? ['items' => [['prices' => [['product_id' => 'EXP', 'product_type' => 'Express Post', 'calculated_price' => 18.65], ['product_id' => 'PP', 'product_type' => 'Parcel Post', 'calculated_price' => 11.95]]]]])),
                'POST' === $method && str_ends_with($path, '/shipments') => new MockResponse(json_encode(['shipments' => [['shipment_id' => 'yAEpzyoPQmGvGdDEzLsVng', 'shipment_reference' => 'ORDER-1042', 'items' => [['item_id' => 'xQpUHhqvSuyS6Lj_ChgpQg', 'tracking_details' => ['article_id' => 'ABC000128B03', 'consignment_id' => 'ABC000128']]]]]])),
                'POST' === $method && str_ends_with($path, '/labels') => new MockResponse(json_encode(['labels' => [['request_id' => 'r1', 'status' => 'AVAILABLE', 'url' => 'https://digitalapi.auspost.com.au/test/shipping/v1/labels/r1.pdf']]])),
                str_ends_with($path, '/labels/r1.pdf') => new MockResponse('%PDF-1.4 ap', ['response_headers' => ['content-type' => 'application/pdf']]),
                str_ends_with($path, '/track') => new MockResponse(json_encode(['tracking_results' => [['tracking_id' => 'ABC000128B03', 'status' => 'Delivered', 'trackable_items' => [['events' => [['location' => 'SYDNEY NSW', 'description' => 'Delivered', 'date' => '2026-10-02T10:15:00+10:00'], ['location' => 'MELBOURNE VIC', 'description' => 'Item processed at facility', 'date' => '2026-10-01T09:00:00+10:00']]]]]]])),
                'GET' === $method && str_ends_with($path, '/shipments') => new MockResponse(json_encode(['shipments' => [['shipment_id' => 'yAEpzyoPQmGvGdDEzLsVng']]])),
                'DELETE' === $method => new MockResponse('', ['http_code' => 200]),
                default => new MockResponse(json_encode(['errors' => [['code' => '404', 'message' => 'No such resource '.$path]]]), ['http_code' => 404]),
            };
        });

        return (new AuspostGatewayFactory($http))->create(['api_key' => 'key', 'password' => 'pass', 'account_number' => '0000000000', 'sandbox' => true]);
    }

    public function testPricesComeCheapestFirstInAud(): void
    {
        $rates = $this->gateway()->rate(self::shipment());
        self::assertSame(['PP', 'EXP'], array_map(fn ($r) => $r->service, $rates));
        self::assertSame(1195, $rates[0]->amount);
        self::assertSame('AUD', $rates[0]->currency);
        self::assertContains('Account-Number: 0000000000', $this->calls[0][3]);
        self::assertSame('NSW', $this->calls[0][2]['to']['state']);
        self::assertSame(1.5, $this->calls[0][2]['items'][0]['weight']);
    }

    public function testAShipmentIsCreatedAndItsLabelFetched(): void
    {
        $label = $this->gateway()->ship(self::shipment());
        self::assertSame('ABC000128B03', $label->trackingNumber);
        self::assertSame('%PDF-1.4 ap', $label->content);
        self::assertStringEndsWith('/labels/r1.pdf', $label->url);
        self::assertSame('PP', $this->calls[0][2]['shipments'][0]['items'][0]['product_id']);
        self::assertTrue($this->calls[0][2]['shipments'][0]['email_tracking_enabled']);
        self::assertSame('yAEpzyoPQmGvGdDEzLsVng', $this->calls[1][2]['shipments'][0]['shipment_id']);
    }

    public function testTrackingAndCancel(): void
    {
        $gateway = $this->gateway();
        $tracking = $gateway->track('ABC000128B03');
        self::assertSame(TrackingStatus::DELIVERED, $tracking->status);
        self::assertSame('Item processed at facility', $tracking->events[0]->description);
        self::assertSame('SYDNEY NSW', $tracking->latest()->location);

        self::assertTrue($gateway->cancel('ABC000128B03'));
        self::assertSame('DELETE', end($this->calls)[0]);
        self::assertStringContainsString('shipment_ids=yAEpzyoPQmGvGdDEzLsVng', end($this->calls)[4]);
    }

    /**
     * Australia Post's Get Item Prices (POST /shipping/v1/prices/items) prices each item of the
     * request on its own, one entry of `items` per item: a shipment of several parcels costs
     * the sum, and a product is offered only when it carries every parcel.
     */
    public function testAShipmentOfSeveralParcelsIsPricedWhole(): void
    {
        $s = self::shipment();
        $two = new Shipment($s->sender, $s->recipient, [new Parcel(1500, 30, 20, 10), new Parcel(4000, 40, 30, 20)], reference: 'ORDER-1042', options: $s->options);
        $this->prices = ['items' => [
            ['prices' => [['product_id' => 'EXP', 'product_type' => 'Express Post', 'calculated_price' => 18.65], ['product_id' => 'PP', 'product_type' => 'Parcel Post', 'calculated_price' => 11.95]]],
            ['prices' => [['product_id' => 'EXP', 'product_type' => 'Express Post', 'calculated_price' => 24.10], ['product_id' => 'PP', 'product_type' => 'Parcel Post', 'calculated_price' => 16.40]]],
        ]];
        $rates = $this->gateway()->rate($two);
        self::assertSame(['PP', 'EXP'], array_map(fn ($r) => $r->service, $rates));
        self::assertSame([2835, 4275], array_map(fn ($r) => $r->amount, $rates));
        self::assertCount(2, $this->calls[0][2]['items']);

        $this->prices = ['items' => [
            ['prices' => [['product_id' => 'EXP', 'product_type' => 'Express Post', 'calculated_price' => 18.65], ['product_id' => 'PP', 'product_type' => 'Parcel Post', 'calculated_price' => 11.95]]],
            ['prices' => [['product_id' => 'PP', 'product_type' => 'Parcel Post', 'calculated_price' => 16.40]], 'errors' => [['code' => '44011', 'message' => 'Express Post is not available for this item.']]],
        ]];
        $rates = $this->gateway()->rate($two);
        self::assertSame(['PP'], array_map(fn ($r) => $r->service, $rates), 'EXP does not carry the second parcel');
        self::assertSame(2835, $rates[0]->amount);
    }
}
