<?php

namespace Omnibus\Auspost\Tests;

use Omnibus\Auspost\AuspostGatewayFactory;
use Omnibus\GatewayFactoryInterface;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Testing\GatewayContractTests;
use Omnibus\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The conformance suite on Australia Post's recorded answers (Shipping and Tracking API, test). */
final class AuspostContractTest extends TestCase
{
    use GatewayContractTests;

    protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new AuspostGatewayFactory($http);
    }

    protected function gatewayOptions(): array
    {
        return ['api_key' => 'key', 'password' => 'pass', 'account_number' => '0000000000', 'sandbox' => true];
    }

    protected function contractShipment(): Shipment
    {
        return new Shipment(new Address('Glitch Art', ['1 Collins St'], '3000', 'Melbourne', 'AU', phone: '0390000000'), new Address('Alex Martin', ['200 George St'], '2000', 'Sydney', 'AU', email: 'alex@example.org'), [new Parcel(1500, 30, 20, 10)], reference: 'ORDER-1042', options: ['sender_state' => 'VIC', 'recipient_state' => 'NSW']);
    }

    protected function contractTrackingNumber(): string
    {
        return 'ABC000128B03';
    }

    protected function recordedAnswers(): array
    {
        return [
            'POST /prices/items' => ['items' => [['prices' => [['product_id' => 'EXP', 'product_type' => 'Express Post', 'calculated_price' => 18.65], ['product_id' => 'PP', 'product_type' => 'Parcel Post', 'calculated_price' => 11.95]]]]],
            'POST /shipments' => ['shipments' => [['shipment_id' => 'yAEpzyoPQmGvGdDEzLsVng', 'shipment_reference' => 'ORDER-1042', 'items' => [['item_id' => 'xQpUHhqvSuyS6Lj_ChgpQg', 'tracking_details' => ['article_id' => 'ABC000128B03', 'consignment_id' => 'ABC000128']]]]]],
            'POST /labels' => ['labels' => [['request_id' => 'r1', 'status' => 'AVAILABLE', 'url' => 'https://digitalapi.auspost.com.au/test/shipping/v1/labels/r1.pdf']]],
            '/labels/r1.pdf' => RecordedHttpClient::answer('%PDF-1.4 ap', 200, ['content-type' => 'application/pdf']),
            '/track' => ['tracking_results' => [['tracking_id' => 'ABC000128B03', 'status' => 'Delivered', 'trackable_items' => [['events' => [['location' => 'SYDNEY NSW', 'description' => 'Delivered', 'date' => '2026-10-02T10:15:00+10:00'], ['location' => 'MELBOURNE VIC', 'description' => 'Item processed at facility', 'date' => '2026-10-01T09:00:00+10:00']]]]]]],
            'GET /shipments' => ['shipments' => [['shipment_id' => 'yAEpzyoPQmGvGdDEzLsVng']]],
            'DELETE /shipments' => '',
        ];
    }
}
