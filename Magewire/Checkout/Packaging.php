<?php
/**
 * Copyright © Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magmodules\BoxoHyvaCheckout\Magewire\Checkout;

use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magewirephp\Magewire\Component;
use Magmodules\Boxo\Api\Config\RepositoryInterface as ConfigRepository;
use Magmodules\Boxo\Api\Log\RepositoryInterface as LogRepository;
use Magmodules\Boxo\Exception\PackagingUnavailableException;
use Magmodules\Boxo\Model\Api\Client as BoxoApiClient;
use Magmodules\Boxo\Model\Checkout\ConfigProvider;
use Magmodules\Boxo\Model\Packaging as PackagingModel;
use Magmodules\Boxo\Model\Packaging\ReusableEligibility;
use Magmodules\Boxo\Model\Quote\InStorePickupResolver;
use Magmodules\Boxo\Model\Quote\PackagingCartManager;

/**
 * Hyvä Checkout counterpart of the Luma boxo-packaging UI component.
 *
 * Where the Luma checkout probes availability and saves the choice through the
 * boxo/ajax controllers, this component does both server-side as the checkout
 * emits its address and shipping-method events, using the same BOXO services
 * so the rules (country, postcode, eligibility, in-store pickup) stay in one
 * place.
 */
class Packaging extends Component implements EvaluationInterface
{
    /**
     * Dutch postcode: 4 digits (never leading zero) followed by 2 letters.
     */
    private const POSTCODE_PATTERN = '/^[1-9][0-9]{3}\s*[A-Za-z]{2}$/';

    private const VALID_SELECTIONS = [
        PackagingModel::SELECTION_REUSABLE,
        PackagingModel::SELECTION_DISPOSABLE,
    ];

    /**
     * Price summary components that list the packaging line and its amount.
     */
    private const SUMMARY_COMPONENTS = [
        'price-summary.cart-items',
        'price-summary.total-segments',
    ];

    public ?string $selection = null;

    /**
     * Whether the options are offered: BOXO serves the address, the cart is
     * eligible and the order is not collected in store.
     */
    public bool $available = false;

    /**
     * Last address probed against the BOXO API ("NL|1234AB") and its answer,
     * so re-rendering the step doesn't spend API quota on an unchanged address.
     */
    public ?string $checkedAddress = null;
    public bool $addressAvailable = false;

    /**
     * @var array<string, string>
     */
    protected $listeners = [
        'shipping_address_saved' => 'syncWithQuote',
        'shipping_address_activated' => 'syncWithQuote',
        'shipping_method_selected' => 'syncWithQuote',
    ];

    /**
     * @var string[]
     */
    protected array $uncallables = ['getViewConfig'];

    private ?array $viewConfig = null;

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly ConfigRepository $configRepository,
        private readonly ConfigProvider $configProvider,
        private readonly BoxoApiClient $apiClient,
        private readonly LogRepository $logRepository,
        private readonly PackagingCartManager $cartManager,
        private readonly InStorePickupResolver $pickupResolver,
        private readonly ReusableEligibility $reusableEligibility
    ) {
    }

    public function mount(): void
    {
        $this->syncWithQuote();
    }

    /**
     * Re-evaluate the options against the current quote and bring the cart in
     * line: drop the packaging when BOXO no longer applies, pre-select the
     * configured default when it newly does.
     *
     * Variadic because Magewire passes the emitted event params as named
     * arguments.
     *
     * @param mixed ...$params
     */
    public function syncWithQuote(...$params): void
    {
        $quote = $this->getQuote();
        if ($quote === null || !$this->configRepository->isEnabled((int)$quote->getStoreId())) {
            $this->available = false;
            $this->selection = null;
            return;
        }

        $current = $this->cartManager->getCurrentSelection($quote);
        $this->available = $this->isOfferedFor($quote);

        if (!$this->available) {
            // Never leave a deposit or fee on an order BOXO will not carry.
            if ($current !== null) {
                $this->persist($quote, null);
            }
            $this->selection = null;
            return;
        }

        $this->selection = $current;

        $default = $this->configRepository->getDefaultSelection((int)$quote->getStoreId());
        if ($current === null && in_array($default, self::VALID_SELECTIONS, true)
            && $this->persist($quote, $default)
        ) {
            $this->selection = $default;
        }
    }

    /**
     * Save the customer's pick. The returned value is what the radio shows, so
     * a rejected choice falls back to what the cart actually holds.
     */
    public function updatingSelection(mixed $value): ?string
    {
        $quote = $this->getQuote();
        if ($quote === null) {
            return null;
        }

        $previous = $this->cartManager->getCurrentSelection($quote);
        $value = strtolower(trim((string)$value));

        if (!in_array($value, self::VALID_SELECTIONS, true)) {
            return $previous;
        }

        // Re-verify against the live API like the Luma controller does: the
        // public component state comes from the browser and can't be trusted.
        [$country, $postcode] = $this->getAddress($quote);
        $offered = $this->configRepository->isEnabled((int)$quote->getStoreId())
            && !$this->pickupResolver->isPickup($quote)
            && $this->reusableEligibility->isAllowed($quote)
            && $country === ConfigRepository::SUPPORTED_COUNTRY_CODE
            && $postcode !== ''
            && $this->apiClient->checkServiceAvailable($postcode, (int)$quote->getStoreId()) === true;

        if (!$offered) {
            $this->logRepository->addDebugLog('hyva setSelection rejected: not offered', [
                'selection' => $value,
                'country' => $country,
            ]);
            $this->syncWithQuote();
            return $this->selection;
        }

        return $this->persist($quote, $value) ? $value : $previous;
    }

    /**
     * Holds the customer on the shipping step until a packaging is chosen,
     * the Hyvä equivalent of the Luma selection-validator.
     */
    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        if (!$this->available || in_array($this->selection, self::VALID_SELECTIONS, true)) {
            return $resultFactory->createSuccess();
        }

        return $resultFactory->createErrorMessageEvent()
            ->withCustomEvent('boxo:packaging:error')
            ->withMessage((string)__('Please select a packaging option to continue.'));
    }

    /**
     * Labels, prices and links for the template, from the same provider that
     * feeds window.checkoutConfig.boxo in the Luma checkout.
     */
    public function getViewConfig(): array
    {
        if ($this->viewConfig === null) {
            $this->viewConfig = $this->configProvider->getConfig()['boxo'] ?? ['enabled' => false];
        }

        return $this->viewConfig;
    }

    private function isOfferedFor(Quote $quote): bool
    {
        if ($this->pickupResolver->isPickup($quote) || !$this->reusableEligibility->isAllowed($quote)) {
            return false;
        }

        [$country, $postcode] = $this->getAddress($quote);
        if ($country !== ConfigRepository::SUPPORTED_COUNTRY_CODE || !preg_match(self::POSTCODE_PATTERN, $postcode)) {
            return false;
        }

        $key = $country . '|' . strtoupper((string)preg_replace('/\s+/', '', $postcode));
        if ($key === $this->checkedAddress) {
            return $this->addressAvailable;
        }

        $available = $this->apiClient->checkServiceAvailable($postcode, (int)$quote->getStoreId());
        if ($available === null) {
            // API error: treat as unavailable, but don't cache it so the next
            // address event tries again.
            $this->checkedAddress = null;
            return false;
        }

        $this->checkedAddress = $key;
        $this->addressAvailable = $available;

        return $available;
    }

    /**
     * Add/remove the packaging line item and refresh the order summary.
     */
    private function persist(Quote $quote, ?string $selection): bool
    {
        try {
            $this->cartManager->applySelection($quote, $selection);
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
            $this->cartRepository->save($quote);
        } catch (PackagingUnavailableException $e) {
            // Already logged by the cart manager; a configuration fault the
            // retailer has to fix, so keep the details out of the storefront.
            $this->dispatchErrorMessage((string)__('The packaging could not be saved. Please try again.'));
            return false;
        } catch (\Exception $e) {
            $this->logRepository->addErrorLog('hyva setSelection exception', [
                'message' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
            $this->dispatchErrorMessage((string)__('The packaging could not be saved. Please try again.'));
            return false;
        }

        foreach (self::SUMMARY_COMPONENTS as $name) {
            $this->emitToRefresh($name);
        }
        $this->emit('boxo_packaging_updated', ['selection' => $selection]);

        return true;
    }

    /**
     * @return array{0: string, 1: string} country code and postcode
     */
    private function getAddress(Quote $quote): array
    {
        $address = $quote->getShippingAddress();

        return [
            strtoupper(trim((string)$address->getCountryId())),
            trim((string)$address->getPostcode()),
        ];
    }

    private function getQuote(): ?Quote
    {
        try {
            return $this->checkoutSession->getQuote();
        } catch (\Exception $e) {
            return null;
        }
    }
}
