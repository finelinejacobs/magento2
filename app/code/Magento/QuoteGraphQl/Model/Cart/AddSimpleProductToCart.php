<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\QuoteGraphQl\Model\Cart;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\Stdlib\ArrayManager;
use Magento\Quote\Model\Quote;

/**
 * Add simple product to cart
 */
class AddSimpleProductToCart
{
    /**
     * @var ArrayManager
     */
    private $arrayManager;

    /**
     * @var DataObjectFactory
     */
    private $dataObjectFactory;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @param ArrayManager $arrayManager
     * @param DataObjectFactory $dataObjectFactory
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        ArrayManager $arrayManager,
        DataObjectFactory $dataObjectFactory,
        ProductRepositoryInterface $productRepository
    ) {
        $this->arrayManager = $arrayManager;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->productRepository = $productRepository;
    }

    /**
     * Add simple product to cart
     *
     * @param Quote $cart
     * @param array $cartItemData
     * @return void
     * @throws GraphQlNoSuchEntityException
     * @throws GraphQlInputException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute(Quote $cart, array $cartItemData): void
    {
        $sku = $this->extractSku($cartItemData);
        $quantity = $this->extractQuantity($cartItemData);
        $customizableOptions = $this->extractCustomizableOptions($cartItemData);

        try {
            $product = $this->productRepository->get($sku);
        } catch (NoSuchEntityException $e) {
            throw new GraphQlNoSuchEntityException(__('Could not find a product with SKU "%sku"', ['sku' => $sku]));
        }

        try {
            $result = $cart->addProduct($product, $this->createBuyRequest($quantity, $customizableOptions, $this->extractDownloadableLinks($product, $cartItemData)));
        } catch (\Exception $e) {
            throw new GraphQlInputException(
                __(
                    'Could not add the product with SKU %sku to the shopping cart: %message',
                    ['sku' => $sku, 'message' => $e->getMessage()]
                )
            );
        }

        if (is_string($result)) {
            throw new GraphQlInputException(__($result));
        }
    }

    /**
     * Extract SKU from cart item data
     *
     * @param array $cartItemData
     * @return string
     * @throws GraphQlInputException
     */
    private function extractSku(array $cartItemData): string
    {
        if (!isset($cartItemData['data']['sku']) || empty($cartItemData['data']['sku'])) {
            throw new GraphQlInputException(__('Missed "sku" in cart item data'));
        }
        return (string)$cartItemData['data']['sku'];
    }

    /**
     * Extract quantity from cart item data
     *
     * @param array $cartItemData
     * @return float
     * @throws GraphQlInputException
     */
    private function extractQuantity(array $cartItemData): float
    {
        if (!isset($cartItemData['data']['quantity'])) {
            throw new GraphQlInputException(__('Missed "qty" in cart item data'));
        }
        $quantity = (float)$cartItemData['data']['quantity'];

        if ($quantity <= 0) {
            throw new GraphQlInputException(
                __('Please enter a number greater than 0 in this field.')
            );
        }
        return $quantity;
    }

    /**
     * Extract Customizable Options from cart item data
     *
     * @param array $cartItemData
     * @return array
     */
    private function extractCustomizableOptions(array $cartItemData): array
    {
        if (!isset($cartItemData['customizable_options']) || empty($cartItemData['customizable_options'])) {
            return [];
        }

        $customizableOptionsData = [];
        foreach ($cartItemData['customizable_options'] as $customizableOption) {
            if (isset($customizableOption['value_string'])) {
                $customizableOptionsData[$customizableOption['id']] = $this->convertCustomOptionValue(
                    $customizableOption['value_string']
                );
            }
        }
        return $customizableOptionsData;
    }

    /**
     * @param $product
     * @param array $cartItemData
     * @return array
     */
    private function extractDownloadableLinks($product, array $cartItemData): array
    {
        $linksData = [];

        if ($product->getLinksPurchasedSeparately()) {
            $downloadableLinks = $this->arrayManager->get('downloadable_product_links', $cartItemData, []);
            $linksData = array_unique(array_column($downloadableLinks, 'link_id'));
        }

        return $linksData;
    }

    /**
     * Format GraphQl input data to a shape that buy request has
     *
     * @param float $quantity
     * @param array $customOptions
     * @param array $downloadableLinks
     * @return DataObject
     */
    private function createBuyRequest(float $quantity, array $customOptions, array $downloadableLinks): DataObject
    {
        $dataArray = [
            'data' => [
                'qty' => $quantity,
                'options' => $customOptions,
            ],
        ];

        if ($downloadableLinks > 0) {
            $dataArray['data']['links'] = $downloadableLinks;
        }

        return $this->dataObjectFactory->create($dataArray);
    }

    /**
     * Convert custom options vakue
     *
     * @param string $value
     * @return string|array
     */
    private function convertCustomOptionValue(string $value)
    {
        $value = trim($value);
        if (substr($value, 0, 1) === "[" &&
            substr($value, strlen($value) - 1, 1) === "]") {
            return explode(',', substr($value, 1, -1));
        }
        return $value;
    }
}
