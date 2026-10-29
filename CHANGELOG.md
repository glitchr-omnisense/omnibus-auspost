# Changelog

## [Unreleased]

### Fixed
- A `CarrierException` no longer carries the API key and password. The message quoted the HTTP
  client's (the whole URL), the previous was the client's own exception, and every trace of the
  chain kept the arguments of the calls under it (the request's options, the `Api` with its
  credentials) when `zend.exception_ignore_args` is off: a log of the whole chain wrote them. The
  message masks them as `***`, the previous is a copy of the client's exception (the same class and
  code), and no exception `Api` throws keeps its trace's arguments (`Omnibus\Http\Redaction`).
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
