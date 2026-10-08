<?php
/**
 * Copyright © Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magmodules\BoxoHyvaCheckout\Observer\HyvaConfigGenerateBefore;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Filesystem\DirectoryList;

class RegisterModuleForHyvaConfig implements ObserverInterface
{
    public function __construct(
        private readonly ComponentRegistrar $componentRegistrar,
        private readonly DirectoryList $directoryList
    ) {
    }

    public function execute(Observer $observer): void
    {
        /** @var DataObject $config */
        $config = $observer->getData('config');
        $extensions = $config->hasData('extensions') ? (array)$config->getData('extensions') : [];

        $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, 'Magmodules_BoxoHyvaCheckout');

        // Only use the path relative to the Magento base dir
        $extensions[] = ['src' => substr((string)$path, strlen($this->directoryList->getRoot()) + 1)];

        $config->setData('extensions', $extensions);
    }
}
