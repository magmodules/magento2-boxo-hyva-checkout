<?php
/**
 * Copyright © Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magmodules\BoxoHyvaCheckout\Setup\Patch\Data;

use Magento\Framework\Module\Manager;
use Magento\Framework\Module\Status;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Switches this module off on installs without Hyvä Checkout, so shipping the
 * package with every BOXO install never breaks a Luma-only store.
 */
class DisableIfCheckoutModuleIsNotInstalled implements DataPatchInterface
{
    public function __construct(
        private readonly Manager $moduleManager,
        private readonly Status $moduleStatus
    ) {
    }

    public function apply(): self
    {
        if (!$this->moduleManager->isEnabled('Hyva_Checkout')) {
            $this->moduleStatus->setIsEnabled(false, ['Magmodules_BoxoHyvaCheckout']);
        }

        return $this;
    }

    public function getAliases(): array
    {
        return [];
    }

    public static function getDependencies(): array
    {
        return [];
    }
}
