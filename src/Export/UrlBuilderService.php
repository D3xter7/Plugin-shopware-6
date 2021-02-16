<?php

declare(strict_types=1);

namespace FINDOLOGIC\FinSearch\Export;

use FINDOLOGIC\FinSearch\Utils\Utils;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlCollection;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Collection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\RouterInterface;

class UrlBuilderService
{
    /** @var CacheItemPoolInterface */
    private $cache;

    /** @var SalesChannelContext */
    private $salesChannelContext;

    /** @var RouterInterface */
    private $router;

    /** @var EntityRepository */
    private $categoryRepository;

    public function __construct()
    {

    }

    public function initialize(
        SalesChannelContext $salesChannelContext,
        RouterInterface $router,
        EntityRepository $categoryRepository
    ): void {
        $this->salesChannelContext = $salesChannelContext;
        $this->router = $router;
        $this->categoryRepository = $categoryRepository;
    }

    /**
     * Builds the URL of the given product for the currently used language. Automatically fallbacks to the
     * normal URL in case the product does not have a SEO URL configured.
     * E.g.
     * * http://localhost:8000/Lightweight-Paper-Prior-IT/7562a1140f7f4abd8c6a4a4b6d050b77
     * * https://your-shop.com/detail/032c79962b3f4fb4bd1e9117005b42c1
     * * https://your-shop.com/de/Cooles-Produkt/c0421a8d8af840ecad60971ec5280476
     */
    public function buildProductUrl(ProductEntity $product): string
    {
        $seoPath = $this->getProductSeoPath($product);
        if (!$seoPath) {
            return $this->buildNonSeoUrl($product);
        }

        $domain = $this->getSalesChannelDomain();
        if (!$domain) {
            return $this->buildNonSeoUrl($product);
        }

        return $this->buildSeoUrl($domain, $seoPath);
    }

    /**
     * Builds `cat_url`s for Direct Integrations. Based on the given category, all
     * paths until the root category are generated.
     * E.g. Category Structure "Something > Root Category > Men > Shirts > T-Shirts" exports
     * * /Men/Shirts/T-Shirts/
     * * /Men/Shirts/
     * * /Men/
     * * /navigation/4e43b925d5ec43339d2b3414a91151ab
     *
     * In case there is a language prefix assigned to the Sales Channel, this would also be included.
     * E.g.
     * * /de/Men/Shirts/T-Shirts/
     * * /de/navigation/4e43b925d5ec43339d2b3414a91151ab
     *
     * @return string[]
     */
    public function buildCatUrls(CategoryEntity $category): array
    {
        $tree = $this->getCategoriesFromHierarchy($category);
        $categories = array_merge(Utils::flat($tree), [$category]);

        $catUrls = array_map(function (CategoryEntity $category) {
            return $this->buildNonSeoCatUrl($category);
        }, $categories);

        $seoCatUrls = array_map(function (CategoryEntity $category) {
            return $this->buildSingleCategoryCatUrls($category);
        }, $categories);

        return array_merge($catUrls, Utils::flat($seoCatUrls));
    }

    /**
     * Gets the domain of the sales channel for the currently used language. Suffixed slashes are removed.
     * E.g.
     * * http://localhost:8000
     * * https://your-domain.com
     * * https://your-domain.com/de
     *
     * @return string|null
     */
    protected function getSalesChannelDomain(): ?string
    {
        $allDomains = $this->salesChannelContext->getSalesChannel()->getDomains();
        $domains = $this->getTranslatedEntities($allDomains);

        if (!$domains->first()) {
            return null;
        }

        return rtrim($domains->first()->getUrl(), '/');
    }

    /**
     * Gets the SEO path of the given product for the currently used language. Prefixed slashes are removed.
     * E.g.
     * * Lightweight-Paper-Prior-IT/7562a1140f7f4abd8c6a4a4b6d050b77
     * * Sony-Alpha-7-III-Sigma-AF-24-70mm-1-2-8-DG-DN-ART/145055000510
     *
     * @return string|null
     */
    protected function getProductSeoPath(ProductEntity $product): ?string
    {
        $allSeoUrls = $product->getSeoUrls();
        if (!$allSeoUrls) {
            return null;
        }

        $applicableSeoUrls = $this->getApplicableSeoUrls($allSeoUrls);
        $seoUrls = $this->getTranslatedEntities($applicableSeoUrls);
        if (!$seoUrls || !$seoUrls->first()) {
            return null;
        }

        $canonicalSeoUrl = $seoUrls->filter(function (SeoUrlEntity $entity) {
            return $entity->getIsCanonical();
        })->first();
        $seoUrl = $canonicalSeoUrl ?? $canonicalSeoUrl->first();

        return ltrim($seoUrl->getSeoPathInfo(), '/');
    }

    /**
     * Filters the given collection to only return entities for the current language.
     */
    protected function getTranslatedEntities(?EntityCollection $collection): ?Collection
    {
        if (!$collection) {
            return null;
        }

        $translatedEntities = $collection->filterByProperty(
            'languageId',
            $this->salesChannelContext->getSalesChannel()->getLanguageId()
        );

        if ($translatedEntities->count() === 0) {
            return null;
        }

        return $translatedEntities;
    }

    /**
     * Filters out non-applicable SEO URLs based on the current context.
     */
    protected function getApplicableSeoUrls(SeoUrlCollection $collection): SeoUrlCollection
    {
        $salesChannelId = $this->salesChannelContext->getSalesChannel()->getId();
        return $collection->filter(static function (SeoUrlEntity $seoUrl) use ($salesChannelId) {
            return $seoUrl->getSalesChannelId() === $salesChannelId && !$seoUrl->getIsDeleted();
        });
    }

    protected function buildNonSeoUrl(ProductEntity $product): string
    {
        return $this->router->generate(
            'frontend.detail.page',
            ['productId' => $product->getId()],
            RouterInterface::ABSOLUTE_URL
        );
    }

    protected function buildSeoUrl(string $domain, string $seoPath): string
    {
        return sprintf('%s/%s', $domain, $seoPath);
    }

    /**
     * Returns all parent categories in a recursive array. The recursive array will not include the given category.
     * The main navigation category (aka. root category) won't be added to the recursive array.
     *
     * @param CategoryEntity $category
     * @return CategoryEntity[]
     */
    protected function getCategoriesFromHierarchy(CategoryEntity $category): array
    {
        $parent = $this->getParentCategory($category);

        $categories = [];
        if ($parent && $parent->getId() !== $this->salesChannelContext->getSalesChannel()->getNavigationCategoryId()) {
            $categories[] = $category;
            $categories[] = $this->getCategoriesFromHierarchy($parent);
        } else {
            $categories[] = $category;
        }

        return $categories;
    }

    protected function getParentCategory(CategoryEntity $category): ?CategoryEntity
    {
        if (!$category->getParentId()) {
            return null;
        }

        $criteria = new Criteria([$category->getParentId()]);
        $criteria->addAssociation('seoUrls');

        $result = $this->categoryRepository->search($criteria, $this->salesChannelContext->getContext());

        return $result->first();
    }

    /**
     * Returns all SEO paths for the given category.
     *
     * @return string[]
     */
    protected function buildSingleCategoryCatUrls(CategoryEntity $categoryEntity): array
    {
        $allSeoUrls = $categoryEntity->getSeoUrls();
        $salesChannelSeoUrls = $allSeoUrls->filterBySalesChannelId($this->salesChannelContext->getSalesChannelId());
        if ($salesChannelSeoUrls->count() === 0) {
            return [];
        }

        $seoUrls = [];
        foreach ($salesChannelSeoUrls as $seoUrl) {
            $pathInfo = $seoUrl->getSeoPathInfo();
            if (Utils::isEmpty($pathInfo)) {
                continue;
            }

            $seoUrls[] = $this->getCatUrlPrefix() . sprintf('/%s', ltrim($pathInfo, '/'));
        }

        return $seoUrls;
    }

    protected function getCatUrlPrefix(): string
    {
        $url = $this->getSalesChannelDomain();
        if (!$url) {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return '';
        }

        return rtrim($path, '/');
    }

    protected function buildNonSeoCatUrl(CategoryEntity $category): string
    {
        return sprintf(
            '/%s',
            ltrim(
                $this->router->generate(
                    'frontend.navigation.page',
                    ['navigationId' => $category->getId()],
                    RouterInterface::ABSOLUTE_PATH
                ),
                '/'
            )
        );
    }
}
