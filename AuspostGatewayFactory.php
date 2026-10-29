<?php

namespace Omnibus\Auspost;

use Omnibus\Auspost\Action\CancelAction;
use Omnibus\Auspost\Action\GetSlipAction;
use Omnibus\Auspost\Action\RatingAction;
use Omnibus\Auspost\Action\ShippingAction;
use Omnibus\Auspost\Action\TrackingAction;
use Omnibus\Config;
use Omnibus\GatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

/**
 *   options:
 *     api_key: '%env(AUSPOST_API_KEY)%'          # Shipping and Tracking API (developers.auspost.com.au)
 *     password: '%env(AUSPOST_PASSWORD)%'
 *     account_number: '%env(AUSPOST_ACCOUNT)%'   # the 10-digit charge account
 *     sandbox: true
 *     rates: [...]                               # optional: configured prices instead of /prices/items
 *
 * No pickup points: parcel lockers and post offices are addressed as recipient addresses.
 */
final class AuspostGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibus.factory_name' => 'auspost',
            'omnibus.factory_title' => 'Australia Post',
            'omnibus.required_options' => ['api_key', 'password', 'account_number'],
            'sandbox' => false,
            'omnibus.api' => function (Config $c) {
                $http = $this->http ?? HttpClient::create();

                return new Api($http, (string) $c['api_key'], (string) $c['password'], (string) $c['account_number'], (bool) $c['sandbox']);
            },
            'omnibus.action.rating' => static fn (Config $c) => $c->get('rates') ? null : new RatingAction(),
            'omnibus.action.shipping' => new ShippingAction(),
            'omnibus.action.tracking' => new TrackingAction(),
            'omnibus.action.cancel' => new CancelAction(),
            'omnibus.action.get_slip' => new GetSlipAction(),
        ]);
    }
}
