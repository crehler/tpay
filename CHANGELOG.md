# Changelog

## 6.0.2

### Changed

- Now requires `crehler/payment-bundle: ^6.0 >=6.0.1` — the storefront payment poller
  relies on the bundle's 6.0.1 reconcile flow and the shipped pre-compiled storefront
  assets.

### Fixed

- **Admin order "Szczegóły" tab no longer errors for Tpay transactions.**
  `TpayGatewayDetailsProvider::mapLevel()` returned the `GatewayStatusLevel` enum
  instead of its string value, causing a `TypeError` against the `string`-typed
  `GatewayPaymentDetails::$statusLevel` — so the gateway-details panel failed to render
  even though the Tpay API call succeeded.

## 6.0.1

Maintenance release — documentation and release pipeline only, no functional changes.

### Changed

- Documentation site (MkDocs Material) is now published to GitHub Pages from the public
  `crehler/tpay` repository.
- Release workflow hardening: the public mirror ships only the `build.yml` and `docs.yml`
  workflows; internal-only workflows are stripped from the published snapshot.

## 6.0.0

First public release on `crehler/tpay`.

Built on the shared `crehler/payment-bundle` (`^6.0`): the plugin implements only the
Tpay-specific pieces (Open API client, signatures, status mapping) and inherits the
lifecycle, checkout UI, webhooks, sub-methods and transition page from the bundle.

### Features

- **BLIK (layer zero)** — the customer enters the BLIK code in the shop and pays without
  any redirect.
- **Card** — redirect by default, with optional embedded card form in checkout; 3-D Secure
  and saved cards (encrypted tokens, `crehler_tpay_saved_card`).
- **Pay-by-link** — bank selection (sub-methods pulled from the Tpay API), with the option
  to send the customer straight to their bank.
- **Refunds** — full and partial refunds from the Shopware admin order view.
- **Webhook verification** — JWS (`x-jws-signature`) plus MD5 checksum.
- **Admin connection test** and a per-order gateway-details panel ("Szczegóły").

### Requires

- PHP `~8.2 || ~8.3 || ~8.4 || ~8.5`, Shopware `~6.6 || ~6.7`
- `crehler/payment-bundle: ^6.0`
- `tpay-com/tpay-openapi-php: ^2.4`
