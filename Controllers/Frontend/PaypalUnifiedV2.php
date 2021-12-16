<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Shopware\Components\HttpClient\RequestException;
use SwagPaymentPayPalUnified\Components\DependencyProvider;
use SwagPaymentPayPalUnified\Components\ErrorCodes;
use SwagPaymentPayPalUnified\Components\PaymentStatus;
use SwagPaymentPayPalUnified\Components\PayPalOrderParameter\PayPalOrderParameterFacadeInterface;
use SwagPaymentPayPalUnified\Components\PayPalOrderParameter\ShopwareOrderData;
use SwagPaymentPayPalUnified\Components\Services\OrderDataService;
use SwagPaymentPayPalUnified\Components\Services\PaymentControllerHelper;
use SwagPaymentPayPalUnified\Components\Services\PayPalOrderBuilderService;
use SwagPaymentPayPalUnified\Components\Services\Validation\RedirectDataBuilderFactory;
use SwagPaymentPayPalUnified\PayPalBundle\Components\SettingsServiceInterface;
use SwagPaymentPayPalUnified\PayPalBundle\PartnerAttributionId;
use SwagPaymentPayPalUnified\PayPalBundle\PaymentType;
use SwagPaymentPayPalUnified\PayPalBundle\V2\Api\Common\Link;
use SwagPaymentPayPalUnified\PayPalBundle\V2\Api\Order;
use SwagPaymentPayPalUnified\PayPalBundle\V2\Api\Patch;
use SwagPaymentPayPalUnified\PayPalBundle\V2\Api\Patches\OrderAddInvoiceIdPatch;
use SwagPaymentPayPalUnified\PayPalBundle\V2\PaymentIntentV2;
use SwagPaymentPayPalUnified\PayPalBundle\V2\Resource\OrderResource;

class Shopware_Controllers_Frontend_PaypalUnifiedV2 extends Shopware_Controllers_Frontend_Payment
{
    /**
     * @var DependencyProvider
     */
    private $dependencyProvider;

    /**
     * @var PayPalOrderBuilderService
     */
    private $orderBuilderService;

    /**
     * @var OrderResource
     */
    private $orderResource;

    /**
     * @var RedirectDataBuilderFactory
     */
    private $redirectDataBuilderFactory;

    /**
     * @var Shopware_Components_Config
     */
    private $shopwareConfig;

    /**
     * @var SettingsServiceInterface
     */
    private $settingsService;

    /**
     * @var PaymentControllerHelper
     */
    private $paymentControllerHelper;

    /**
     * @var OrderDataService
     */
    private $orderDataService;

    /**
     * @var PayPalOrderParameterFacadeInterface
     */
    private $payPalOrderParameterFacade;

    public function preDispatch()
    {
        $this->dependencyProvider = $this->get('paypal_unified.dependency_provider');
        $this->orderBuilderService = $this->get('paypal_unified.paypal_order_builder_service');
        $this->orderResource = $this->get('paypal_unified.v2.order_resource');
        $this->redirectDataBuilderFactory = $this->get('paypal_unified.redirect_data_builder_factory');
        $this->shopwareConfig = $this->get('config');
        $this->settingsService = $this->get('paypal_unified.settings_service');
        $this->paymentControllerHelper = $this->get('paypal_unified.payment_controller_helper');
        $this->orderDataService = $this->get('paypal_unified.order_data_service');
        $this->payPalOrderParameterFacade = $this->get('paypal_unified.paypal_order_parameter_facade');
    }

    public function indexAction()
    {
        $session = $this->dependencyProvider->getSession();

        $shopwareSessionOrderData = $session->get('sOrderVariables');

        if ($shopwareSessionOrderData === null) {
            $redirectDataBuilder = $this->redirectDataBuilderFactory->createRedirectDataBuilder()
                ->setCode(ErrorCodes::NO_ORDER_TO_PROCESS);

            $this->paymentControllerHelper->handleError($this, $redirectDataBuilder);

            return;
        }

        if ($this->getDispatchNoOrder()) {
            $redirectDataBuilder = $this->redirectDataBuilderFactory->createRedirectDataBuilder()
                ->setCode(ErrorCodes::NO_DISPATCH_FOR_ORDER);

            $this->paymentControllerHelper->handleError($this, $redirectDataBuilder);

            return;
        }

        $shopwareOrderData = new ShopwareOrderData($shopwareSessionOrderData['sUserData'], $shopwareSessionOrderData['sBasket']);
        $orderParams = $this->payPalOrderParameterFacade->createPayPalOrderParameter(PaymentType::PAYPAL_CLASSIC_V2, $shopwareOrderData);

        try {
            $payPalOrderData = $this->orderBuilderService->getOrder($orderParams);
            $payPalOrder = $this->orderResource->create($payPalOrderData, PartnerAttributionId::PAYPAL_ALL_V2, false);
        } catch (RequestException $exception) {
            $redirectDataBuilder = $this->redirectDataBuilderFactory->createRedirectDataBuilder()
                ->setCode(ErrorCodes::COMMUNICATION_FAILURE)
                ->setException($exception);

            $this->paymentControllerHelper->handleError($this, $redirectDataBuilder);

            return;
        } catch (\Exception $exception) {
            $redirectDataBuilder = $this->redirectDataBuilderFactory->createRedirectDataBuilder()
                ->setCode(ErrorCodes::UNKNOWN)
                ->setException($exception);

            $this->paymentControllerHelper->handleError($this, $redirectDataBuilder);

            return;
        }

        $url = null;
        foreach ($payPalOrder->getLinks() as $link) {
            if ($link->getRel() === Link::RELATION_APPROVE) {
                $url = $link->getHref();
            }
        }

        if ($url === null) {
            throw new \RuntimeException('No link for redirect found');
        }

        $this->redirect($url);
    }

    public function returnAction()
    {
        $request = $this->Request();
        $payPalOrderId = $request->getParam('token');

        try {
            $payPalOrder = $this->orderResource->get($payPalOrderId);
        } catch (\Exception $exception) {
            return;
        }

        if (!$this->isCartValid($payPalOrder)) {
            $redirectDataBuilder = $this->redirectDataBuilderFactory->createRedirectDataBuilder()
                ->setCode(ErrorCodes::BASKET_VALIDATION_ERROR);

            $this->paymentControllerHelper->handleError($this, $redirectDataBuilder);

            return;
        }

        // TODO:: Create paymentType attribute for API_clientService PT-12495, PT-12496

        $sendShopwareOrderNumber = $this->getSendOrdernumber();

        if ($sendShopwareOrderNumber) {
            $shopwareOrderNumber = (string) $this->saveOrder($payPalOrderId, $payPalOrderId, PaymentStatus::PAYMENT_STATUS_OPEN);
            $this->orderDataService->applyPaymentTypeAttribute($shopwareOrderNumber, $this->getPaymentType());

            $orderNumberPrefix = $this->settingsService->get(SettingsServiceInterface::SETTING_ORDER_NUMBER_PREFIX);

            $invoiceIdPatch = new OrderAddInvoiceIdPatch();
            $invoiceIdPatch->setOp(Patch::OPERATION_ADD);
            $invoiceIdPatch->setValue(sprintf('%s%s', $orderNumberPrefix, $shopwareOrderNumber));
            $invoiceIdPatch->setPath(OrderAddInvoiceIdPatch::PATH);

            try {
                $this->orderResource->update([$invoiceIdPatch], $payPalOrder->getId(), PaymentType::PAYPAL_CLASSIC_V2);
            } catch (RequestException $exception) {
                $redirectDataBuilder = $this->redirectDataBuilderFactory->createRedirectDataBuilder()
                    ->setCode(ErrorCodes::COMMUNICATION_FAILURE)
                    ->setException($exception);

                $this->paymentControllerHelper->handleError($this, $redirectDataBuilder);

                return;
            }
        }

        try {
            if ($this->settingsService->get(SettingsServiceInterface::SETTING_INTENT) === PaymentIntentV2::CAPTURE) {
                $this->orderResource->capture($payPalOrder->getId(), PartnerAttributionId::PAYPAL_ALL_V2, false);
            } elseif ($this->settingsService->get(SettingsServiceInterface::SETTING_INTENT) === PaymentIntentV2::AUTHORIZE) {
                $this->orderResource->authorize($payPalOrder->getId(), PartnerAttributionId::PAYPAL_ALL_V2, false);
            }
        } catch (RequestException $exception) {
            $redirectDataBuilder = $this->redirectDataBuilderFactory->createRedirectDataBuilder()
                ->setCode(ErrorCodes::COMMUNICATION_FAILURE)
                ->setException($exception);

            $this->paymentControllerHelper->handleError($this, $redirectDataBuilder);

            return;
        } catch (\Exception $exception) {
            $redirectDataBuilder = $this->redirectDataBuilderFactory->createRedirectDataBuilder()
                ->setCode(ErrorCodes::UNKNOWN)
                ->setException($exception);

            $this->paymentControllerHelper->handleError($this, $redirectDataBuilder);

            return;
        }

        if (!$sendShopwareOrderNumber) {
            $shopwareOrderNumber = (string) $this->saveOrder($payPalOrderId, $payPalOrderId, PaymentStatus::PAYMENT_STATUS_OPEN);
            $this->orderDataService->applyPaymentTypeAttribute($shopwareOrderNumber, $this->getPaymentType());
        }

        $this->redirect([
            'module' => 'frontend',
            'controller' => 'checkout',
            'action' => 'finish',
            'sUniqueID' => $payPalOrderId,
        ]);
    }

    public function cancelAction()
    {
        $redirectDataBuilder = $this->redirectDataBuilderFactory->createRedirectDataBuilder()
            ->setCode(ErrorCodes::CANCELED_BY_USER);

        $this->paymentControllerHelper->handleError($this, $redirectDataBuilder);
    }

    private function getPaymentType()
    {
        if ($this->request->getParam('spbCheckout', false)) {
            return PaymentType::PAYPAL_SMART_PAYMENT_BUTTONS_V2;
        }

        return PaymentType::PAYPAL_CLASSIC_V2;
    }

    /**
     * @return bool
     */
    private function getDispatchNoOrder()
    {
        $session = $this->dependencyProvider->getSession();

        return !empty($this->shopwareConfig->get('premiumShippingNoOrder')) && (empty($session->get('sDispatch')) || empty($session->get('sCountry')));
    }

    /**
     * @param string|null $cartId
     *
     * @return bool
     */
    private function shouldUseExtendedBasketValidator($cartId = null)
    {
        if (!$cartId || $cartId === 'null') {
            return false;
        }

        if ($cartId === 'express') {
            return false;
        }

        if ($this->container->has('basket_signature_generator')) {
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    private function isCartValid(Order $payPalOrder)
    {
        $cartId = $this->Request()->getParam('basketId');

        if ($this->shouldUseExtendedBasketValidator($cartId)) {
            return $this->validateBasketExtended($cartId);
        }

        return $this->validateBasketSimple($payPalOrder);
    }

    /**
     * @return bool
     */
    private function getSendOrdernumber()
    {
        return $this->settingsService->get(SettingsServiceInterface::SETTING_SEND_ORDER_NUMBER);
    }

    /**
     * @param string $cartId
     *
     * @return bool
     */
    private function validateBasketExtended($cartId)
    {
        try {
            $cart = $this->loadBasketFromSignature($cartId);
            $this->verifyBasketSignature($cartId, $cart);

            return true;
        } catch (RuntimeException $ex) {
            return false;
        }
    }

    /**
     * @return bool
     */
    private function validateBasketSimple(Order $payPalOrder)
    {
        $legacyValidator = $this->get('paypal_unified.simple_basket_validator');

        $cart = $this->getBasket();
        $customer = $this->getUser();

        if ($cart === null || $customer === null) {
            return false;
        }

        foreach ($payPalOrder->getPurchaseUnits() as $purchaseUnit) {
            if (!$legacyValidator->validate($cart, $customer, (float) $purchaseUnit->getAmount()->getValue())) {
                return false;
            }
        }

        return true;
    }
}
