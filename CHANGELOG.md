# Changelog

## [Unreleased]

### Fixed
- A shipment of several parcels is priced whole: Australia Post's Get Item Prices answers one entry
  per item, and only the first was read, so the rates were the first parcel's prices. A product's
  rate is now the sum of its prices over every parcel, and a product that does not carry every
  parcel is not offered.
- The label group follows the product: Express Post for an Express product, Parcel Post otherwise,
  International abroad; it was Parcel Post in Australia whatever the product. A new shipment
  option, `label_group`, names it for a contract product the table does not know.
- A label not ready at booking can be asked for again: `slip()` (GetSlip) finds the shipment by
  its tracking id and asks Australia Post for its label, as ship() does; a comment claimed it
  before, while GetSlip was not supported.
