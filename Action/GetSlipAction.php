<?php

namespace Omnibus\Auspost\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Auspost\Api;
use Omnibus\Auspost\Mapping;
use Omnibus\Exception\CarrierException;
use Omnibus\Model\Label;
use Omnibus\Request\GetSlip;
use Omnibus\Request\Request;

/**
 * The label of a shipment created earlier: GET /shipping/v1/shipments by tracking id gives its
 * shipment id and product (as cancel() finds them), POST /shipping/v1/labels its PDF, as ship()
 * asks for it.
 */
final class GetSlipAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof GetSlip;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof GetSlip);
        $found = $this->api->call('GET', '/shipping/v1/shipments', null, ['tracking_ids' => $request->trackingNumber]);
        $shipment = $found['shipments'][0] ?? [];
        $id = (string) ($shipment['shipment_id'] ?? '');
        if ('' === $id) {
            throw new CarrierException('auspost', sprintf('Australia Post knows no shipment for %s.', $request->trackingNumber));
        }
        $group = Mapping::labelGroup((string) ($shipment['items'][0]['product_id'] ?? 'PP'), (string) ($shipment['to']['country'] ?? 'AU'));
        [$url, $content] = self::label($this->api, $id, $group, 'A4-1pp');
        if (null === $url) {
            throw new CarrierException('auspost', sprintf('Australia Post gave no label for %s.', $request->trackingNumber));
        }
        $request->setResult(new Label('auspost', $request->trackingNumber, $content, Label::PDF, $url, ShippingAction::trackingUrl($request->trackingNumber)));
    }

    /**
     * POST /shipping/v1/labels for a shipment id, waiting for the label's URL; the PDF is fetched
     * when Australia Post says it is AVAILABLE.
     *
     * @return array{?string, ?string} the label's URL and its content
     */
    public static function label(Api $api, string $shipmentId, string $group, string $layout): array
    {
        $labels = $api->call('POST', '/shipping/v1/labels', [
            'wait_for_label_url' => true,
            'preferences' => [['type' => 'PRINT', 'format' => 'PDF', 'groups' => [['group' => $group, 'layout' => $layout, 'branded' => false, 'left_offset' => 0, 'top_offset' => 0]]]],
            'shipments' => [['shipment_id' => $shipmentId]],
        ]);
        $url = $labels['labels'][0]['url'] ?? null;
        $content = null;
        if (\is_string($url) && 'AVAILABLE' === ($labels['labels'][0]['status'] ?? 'AVAILABLE')) {
            $content = $api->call('GET', $url)['content'] ?? null;
        }

        return [\is_string($url) ? $url : null, \is_string($content) ? $content : null];
    }
}
