# BOXO Reusable Packaging for Hyvä Checkout

Hyvä Checkout compatibility module for the [BOXO Reusable Packaging](https://www.magmodules.eu/magento2-boxo-reusable-packaging.html) extension (`Magmodules_Boxo`).

The main BOXO module ships its checkout UI as a Knockout component for the Luma checkout. Hyvä Checkout doesn't
use Knockout or `window.checkoutConfig`, so this separate module adds the same packaging choice as a Magewire
component. All business rules stay in the main module and are reused as is.

## Requirements

| Package                              | Version  |
|--------------------------------------|----------|
| `magmodules/magento2-boxo`           | ^1.0     |
| `hyva-themes/magento2-hyva-checkout` | ^1.3.6   |
| PHP                                  | 8.1+     |

## Installation

### Composer

```bash
composer require magmodules/magento2-boxo-hyva-checkout
bin/magento module:enable Magmodules_BoxoHyvaCheckout
bin/magento setup:upgrade
bin/magento cache:flush
```

### Manual (app/code)

Copy the module to `app/code/Magmodules/BoxoHyvaCheckout`, then run:

```bash
bin/magento module:enable Magmodules_BoxoHyvaCheckout
bin/magento setup:upgrade
bin/magento cache:flush
```

In production mode, also run `bin/magento setup:di:compile` and `bin/magento setup:static-content:deploy`.

Use `cache:flush`, not just `cache:clean`: the frontend plugin this module adds is only picked up after a full flush.

On installs without Hyvä Checkout, a data patch disables this module automatically, so it's safe to ship it with
every BOXO install.

## Configuration

There is nothing to configure in this module. Everything comes from the main BOXO configuration under
**Stores → Configuration → BOXO → Reusable Packaging**:

- **Enabled**: the packaging block only renders when BOXO is enabled (`magmodules_boxo/general/active`).
- **Default selection**: pre-selected as soon as BOXO turns out to be available for the address.
- **Disposable surcharge**: shown next to the single-use option; "Free" when no surcharge is set.
- **Allow mode / max qty**: product eligibility; when the cart isn't eligible, the block is hidden.
- **Info URL**: the link behind the "i" icon (defaults to the BOXO return-point page).

Hyvä Checkout itself must be the active checkout (**Stores → Configuration → Hyvä Themes → Checkout → General**).

## How it works

| Luma (main module)                               | Hyvä Checkout (this module)                                                  |
|--------------------------------------------------|------------------------------------------------------------------------------|
| `boxo-packaging` UI component in `shippingAdditional` | `checkout.boxo.packaging` Magewire block in `checkout.shipping.methods.after` |
| `boxo/ajax/checkAvailability` from JS on postcode input | Server-side check when the shipping address or method changes               |
| `boxo/ajax/setSelection`                          | `updatingSelection()` on the component (`wire:model="selection"`)            |
| `selection-validator` (place-order validator)     | `evaluateCompletion()`: blocks leaving the shipping step without a choice    |
| `RemovePackagingForPickup` plugin on `ShippingInformationManagement` | Removal on `shipping_method_selected` (Hyvä saves the method through `ShippingMethodManagement`, so that plugin doesn't run) |
| Luma image plugins for the packaging line         | `PackagingThumbnail` plugin on Hyvä's `PriceSummary\CartItems`               |

Behaviour matches the Luma checkout:

- Options appear only for Dutch (`NL`) addresses with a complete postcode that the BOXO API reports as served, for
  eligible carts, and not for in-store pickup.
- The configured default is pre-selected and added to the cart straight away.
- Switching options adds or removes the packaging line and refreshes the order summary (items and totals) without
  a page reload.
- When BOXO stops applying (another country, unsupported postcode, pickup, ineligible cart), the packaging line is
  removed.
- A selection is re-verified against the BOXO API on the server before it's saved, so it can't be forced from the
  browser.
- Availability answers are cached per address inside the component, to save API quota while the step re-renders.

The component emits `boxo_packaging_updated` (with `selection`) after every change, for other Magewire components
that need to react. Validation errors are dispatched on the `boxo:packaging:error` event and shown by the component
messenger above the options.

## Styling

The options use plain CSS (`view/frontend/web/css/boxo-packaging.css`), so they render correctly without rebuilding
the Hyvä theme. Override the CSS variables on `.boxo-packaging` to match your theme:

```css
.boxo-packaging {
    --boxo-accent: #c00;
    --boxo-accent-bg: #fff0f0;
    --boxo-radius: 0;
}
```

The module also registers itself for the Hyvä Tailwind build (`hyva_config_generate_before`), like the Mollie Hyvä
Checkout module, so any Tailwind classes you add in a template override are compiled into your theme.

To change the markup, override the template in your theme:
`app/design/frontend/<Vendor>/<theme>/Magmodules_BoxoHyvaCheckout/templates/checkout/packaging.phtml`

## Troubleshooting

- **Block doesn't appear**: check that BOXO is enabled, the address is in the Netherlands with a full postcode, and
  the cart holds no excluded product. Enable BOXO debug logging and check `var/log/boxo-debug.log`.
- **"The packaging could not be saved"**: the BOXO packaging products (`boxo-deposit`, `boxo-disposable`) must be
  enabled, assigned to the website and salable. The exact cause is in `var/log/boxo-error.log`.
