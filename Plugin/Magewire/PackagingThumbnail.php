<?php
/**
 * Copyright © Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magmodules\BoxoHyvaCheckout\Plugin\Magewire;

use Hyva\Checkout\Magewire\Checkout\PriceSummary\CartItems;
use Magmodules\Boxo\Model\Packaging\ImageProvider;

/**
 * Hyvä's order summary resolves thumbnails through the catalog image helper,
 * which the BOXO image plugins for the Luma checkout don't reach; without this
 * the packaging line shows the "no image" placeholder.
 */
class PackagingThumbnail
{
    public function __construct(
        private readonly ImageProvider $imageProvider
    ) {
    }

    public function afterGetQuoteItemData(CartItems $subject, ?array $result): ?array
    {
        if ($result === null) {
            return null;
        }

        foreach ($result as $index => $item) {
            $url = $this->imageProvider->getUrlForSku((string)($item['sku'] ?? ''));
            if ($url !== null) {
                $result[$index]['thumbnail'] = $url;
            }
        }

        return $result;
    }
}
