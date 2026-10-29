# Changelog

## [Unreleased]

### Fixed
- A shipment of several parcels is priced whole: Australia Post's Get Item Prices answers one entry
  per item, and only the first was read, so the rates were the first parcel's prices. A product's
  rate is now the sum of its prices over every parcel, and a product that does not carry every
  parcel is not offered.
