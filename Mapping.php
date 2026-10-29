<?php

namespace Omnibus\Auspost;

use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Model\TrackingStatus;

/** Australia Post's shapes for ours. */
final class Mapping
{
    /**
     * The label group of POST /labels for a product: International abroad, Express Post for an
     * Express product (EXP, or a contract product named so), Parcel Post otherwise.
     */
    public static function labelGroup(string $product, string $country): string
    {
        return match (true) {
            'AU' !== strtoupper($country) => 'International',
            str_starts_with(strtoupper($product), 'EXP') || str_contains(self::PRODUCTS[$product] ?? '', 'Express') => 'Express Post',
            default => 'Parcel Post',
        };
    }

    public const PRODUCTS = ['PP' => 'Parcel Post', 'EXP' => 'Express Post', 'PTI8' => 'International Standard', 'ECM8' => 'International Express', 'AIR8' => 'International Economy Air'];

    public static function party(Address $a, ?string $state = null): array
    {
        return array_filter([
            'name' => mb_substr($a->name, 0, 40),
            'business_name' => $a->company ? mb_substr($a->company, 0, 40) : null,
            'lines' => array_values(array_filter($a->street)),
            'suburb' => $a->city,
            'state' => $state,
            'postcode' => $a->postcode,
            'country' => strtoupper($a->country),
            'phone' => $a->phone,
            'email' => $a->email,
        ], static fn ($v) => null !== $v && '' !== $v);
    }

    public static function item(Parcel $p, Shipment $s, string $product): array
    {
        return array_filter([
            'item_reference' => $p->reference ?? $s->reference,
            'product_id' => $product,
            'length' => $p->length, 'width' => $p->width, 'height' => $p->height,
            'weight' => round(max(0.1, $p->weight / 1000), 2),
            'authority_to_leave' => (bool) $s->option('authority_to_leave', false),
            'allow_partial_delivery' => false,
            'item_contents' => 'AU' === strtoupper($s->recipient->country) ? null : [['description' => (string) $s->option('description', 'Merchandise'), 'quantity' => 1, 'value' => ($p->value ?? 100) / 100, 'weight' => round(max(0.1, $p->weight / 1000), 2), 'country_of_origin' => strtoupper($s->sender->country)]],
            'classification_type' => 'AU' === strtoupper($s->recipient->country) ? null : $s->option('classification', 'SOLD'),
        ], static fn ($v) => null !== $v);
    }

    public static function status(?string $status): TrackingStatus
    {
        $s = strtolower((string) $status);

        return match (true) {
            str_contains($s, 'delivered') => TrackingStatus::DELIVERED,
            str_contains($s, 'on board') || str_contains($s, 'out for delivery') || str_contains($s, 'with driver') => TrackingStatus::OUT_FOR_DELIVERY,
            str_contains($s, 'awaiting collection') || str_contains($s, 'ready for pickup') => TrackingStatus::AVAILABLE_FOR_PICKUP,
            str_contains($s, 'return') => TrackingStatus::RETURNED,
            str_contains($s, 'unsuccessful') || str_contains($s, 'delayed') || str_contains($s, 'unable') => TrackingStatus::EXCEPTION,
            str_contains($s, 'created') || str_contains($s, 'sealed') || str_contains($s, 'pending') => TrackingStatus::PENDING,
            str_contains($s, 'transit') || str_contains($s, 'received') || str_contains($s, 'processed') || str_contains($s, 'initiated') => TrackingStatus::IN_TRANSIT,
            default => TrackingStatus::UNKNOWN,
        };
    }
}
