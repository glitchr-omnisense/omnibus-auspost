<?php

namespace Omnibus\Auspost\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Auspost\Api;
use Omnibus\Auspost\Mapping;
use Omnibus\Model\Rate;
use Omnibus\Request\Rating;
use Omnibus\Request\Request;

/**
 * POST /shipping/v1/prices/items: every product the account can send the items with, priced.
 * Australia Post prices each item on its own: a product's rate is the sum over the parcels, and
 * a product that does not carry every parcel is not offered.
 */
final class RatingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Rating;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Rating);
        $s = $request->shipment;
        $data = $this->api->call('POST', '/shipping/v1/prices/items', [
            'from' => Mapping::party($s->sender, $s->option('sender_state')),
            'to' => Mapping::party($s->recipient, $s->option('recipient_state')),
            'items' => array_map(static fn ($p) => array_filter(['length' => $p->length, 'width' => $p->width, 'height' => $p->height, 'weight' => round(max(0.1, $p->weight / 1000), 2)]), $s->parcels),
        ]);
        $items = array_values($data['items'] ?? []);
        $totals = $labels = $carried = [];
        foreach ($items as $item) {
            foreach ($item['prices'] ?? [] as $price) {
                $code = (string) ($price['product_id'] ?? '');
                $totals[$code] = ($totals[$code] ?? 0) + (int) round(((float) ($price['calculated_price'] ?? $price['calculated_price_ex_gst'] ?? 0)) * 100);
                $labels[$code] ??= (string) ($price['product_type'] ?? Mapping::PRODUCTS[$code] ?? $code);
                $carried[$code] = ($carried[$code] ?? 0) + 1;
            }
        }
        $rates = [];
        foreach ($totals as $code => $amount) {
            if ($carried[$code] === \count($s->parcels)) {
                $rates[] = new Rate('auspost', $code, $labels[$code], $amount, 'AUD');
            }
        }
        usort($rates, static fn (Rate $a, Rate $b) => $a->amount <=> $b->amount);
        $request->setResult($rates);
    }
}
