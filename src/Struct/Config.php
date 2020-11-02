<?php

declare(strict_types=1);

namespace FINDOLOGIC\FinSearch\Struct;

use FINDOLOGIC\FinSearch\Findologic\FilterPosition;
use FINDOLOGIC\FinSearch\Findologic\IntegrationType;
use FINDOLOGIC\FinSearch\Findologic\Resource\ServiceConfigResource;
use GuzzleHttp\Exception\ClientException;
use Psr\Cache\InvalidArgumentException;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class Config extends Struct
{
    public const DEFAULT_SEARCH_RESULT_CONTAINER = 'fl-result';
    public const DEFAULT_NAVIGATION_RESULT_CONTAINER = 'fl-navigation-result';

    public const ALLOW_FOR_SERIALIZATION = [
        'shopkey',
        'active',
        'staging',
        'activeOnCategoryPages',
        'crossSellingCategories',
        'searchResultContainer',
        'navigationResultContainer',
        'integrationType',
        'initialized',
        'filterPosition'
    ];

    /** @var SystemConfigService */
    private $systemConfigService;

    /** @var string|null */
    private $shopkey;

    /** @var bool */
    private $active;

    /** @var bool */
    private $activeOnCategoryPages;

    /** @var string */
    private $searchResultContainer;

    /** @var string */
    private $navigationResultContainer;

    /** @var string|null */
    private $integrationType;

    /** @var ServiceConfigResource */
    private $serviceConfigResource;

    /** @var bool */
    private $staging;

    /** @var bool */
    private $initialized = false;

    /** @var string */
    private $filterPosition;

    /** @var array */
    private $crossSellingCategories = [];

    public function __construct(SystemConfigService $systemConfigService, ServiceConfigResource $serviceConfigResource)
    {
        $this->systemConfigService = $systemConfigService;
        $this->serviceConfigResource = $serviceConfigResource;
    }

    public function __sleep(): array
    {
        // Only return instances that are actually serializable. For example the SystemConfigService is not
        // serializable, as it has an PDO instance associated to it.
        return self::ALLOW_FOR_SERIALIZATION;
    }

    public function getShopkey(): ?string
    {
        return $this->shopkey;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isStaging(): bool
    {
        return $this->staging;
    }

    public function isActiveOnCategoryPages(): bool
    {
        return $this->activeOnCategoryPages;
    }

    public function getSearchResultContainer(): string
    {
        return $this->searchResultContainer;
    }

    public function getNavigationResultContainer(): string
    {
        return $this->navigationResultContainer;
    }

    public function getIntegrationType(): ?string
    {
        return $this->integrationType;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function initializeBySalesChannel(?string $salesChannelId): void
    {
        $this->active = $this->getConfig($salesChannelId, 'FinSearch.config.active', false);
        $this->shopkey = $this->getConfig($salesChannelId, 'FinSearch.config.shopkey');
        $this->activeOnCategoryPages = $this->getConfig(
            $salesChannelId,
            'FinSearch.config.activeOnCategoryPages',
            false
        );
        $this->crossSellingCategories = $this->getConfig(
            $salesChannelId,
            'FinSearch.config.crossSellingCategories',
            []
        );
        $this->searchResultContainer = $this->getConfig(
            $salesChannelId,
            'FinSearch.config.searchResultContainer',
            self::DEFAULT_SEARCH_RESULT_CONTAINER
        );
        $this->navigationResultContainer = $this->getConfig(
            $salesChannelId,
            'FinSearch.config.navigationResultContainer',
            self::DEFAULT_NAVIGATION_RESULT_CONTAINER
        );
        $this->filterPosition = $this->getConfig(
            $salesChannelId,
            'FinSearch.config.filterPosition',
            FilterPosition::TOP
        );

        $this->initializeReadonlyConfig($salesChannelId);

        $this->initialized = true;
    }

    public function getCrossSellingCategories(): array
    {
        return $this->crossSellingCategories;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function initializeReadonlyConfig(?string $salesChannelId): void
    {
        try {
            // Only set read-only configurations if the plugin is active
            if ($this->active) {
                $isDirectIntegration = $this->serviceConfigResource->isDirectIntegration($this->shopkey);
                $this->integrationType = $isDirectIntegration ? IntegrationType::DI : IntegrationType::API;
                $integrationType = $this->systemConfigService->get('FinSearch.config.integrationType', $salesChannelId);

                if ($this->integrationType !== $integrationType) {
                    $this->systemConfigService->set(
                        'FinSearch.config.integrationType',
                        $this->integrationType,
                        $salesChannelId
                    );
                }

                $this->staging = $this->systemConfigService->get('FinSearch.config.isStaging', $salesChannelId);
                $isStaging = $this->serviceConfigResource->isStaging($this->shopkey);

                if ($this->staging !== $isStaging) {
                    $this->staging = $isStaging;
                    $this->systemConfigService->set(
                        'FinSearch.config.isStaging',
                        $this->staging,
                        $salesChannelId
                    );
                }
            }
        } catch (ClientException $e) {
            $this->staging = false;
            $this->integrationType = null;
        }
    }

    /**
     * @return string|bool|null
     */
    private function getConfig(?string $salesChannelId, string $configKey, $default = null)
    {
        $configValue = $this->systemConfigService->get($configKey, $salesChannelId);
        if ($configValue === null || (is_string($configValue) && trim($configValue) === '')) {
            return $default;
        }

        return $configValue;
    }

    /**
     * @return string
     */
    public function getFilterPosition(): string
    {
        return $this->filterPosition;
    }
}
