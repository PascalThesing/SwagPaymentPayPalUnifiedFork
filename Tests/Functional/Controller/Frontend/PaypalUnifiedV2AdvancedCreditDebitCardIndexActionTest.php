<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagPaymentPayPalUnified\Tests\Functional\Controller\Frontend;

use Enlight_Components_Session_Namespace;
use Enlight_Controller_Request_RequestTestCase;
use Enlight_Controller_Response_ResponseTestCase;
use Shopware_Controllers_Frontend_PaypalUnifiedV2AdvancedCreditDebitCard;
use SwagPaymentPayPalUnified\Components\DependencyProvider;
use SwagPaymentPayPalUnified\Components\NumberRangeIncrementerDecorator;
use SwagPaymentPayPalUnified\Controllers\Frontend\AbstractPaypalPaymentController;
use SwagPaymentPayPalUnified\PayPalBundle\V2\Api\Order;
use SwagPaymentPayPalUnified\PayPalBundle\V2\Resource\OrderResource;
use SwagPaymentPayPalUnified\Tests\Functional\Controller\Frontend\_fixtures\SimplePayPalOrderCreator;
use SwagPaymentPayPalUnified\Tests\Functional\ShopRegistrationTrait;
use SwagPaymentPayPalUnified\Tests\Mocks\ConnectionMock;
use SwagPaymentPayPalUnified\Tests\Unit\PaypalPaymentControllerTestCase;

require __DIR__ . '/../../../../Controllers/Frontend/PaypalUnifiedV2AdvancedCreditDebitCard.php';

class PaypalUnifiedV2AdvancedCreditDebitCardIndexActionTest extends PaypalPaymentControllerTestCase
{
    use ShopRegistrationTrait;

    /**
     * @return void
     */
    public function testIndexAction()
    {
        $orderNumber = '777777777';

        $session = $this->getContainer()->get('session');
        $session->offsetSet(NumberRangeIncrementerDecorator::ORDERNUMBER_SESSION_KEY, $orderNumber);
        $session->offsetSet('sUserId', 1);
        $session->offsetSet('sOrderVariables', [
            'sBasket' => require __DIR__ . '/_fixtures/getBasket_result.php',
            'sUserData' => require __DIR__ . '/_fixtures/getUser_result.php',
        ]);

        $request = new Enlight_Controller_Request_RequestTestCase();
        $response = new Enlight_Controller_Response_ResponseTestCase();

        $payPalOrder = $this->createPayPalOrder();

        $sessionMock = $this->createMock(Enlight_Components_Session_Namespace::class);
        $sessionMock->expects(static::exactly(2))->method('offsetUnset');
        $sessionMock->method('offsetGet')->willReturnMap([
            ['paypalOrderId', '123456789'],
            [AbstractPaypalPaymentController::ACDC_SHOPWARE_ORDER_ID_SESSION_KEY, $orderNumber],
        ]);

        $dependencyProviderMock = $this->createMock(DependencyProvider::class);
        $dependencyProviderMock->method('getSession')->willReturn($sessionMock);

        $orderResourceMock = $this->createMock(OrderResource::class);
        $orderResourceMock->method('get')->willReturn($payPalOrder);
        $orderResourceMock->method('capture')->willReturn($payPalOrder);

        $paypalUnifiedV2Controller = $this->getController(
            Shopware_Controllers_Frontend_PaypalUnifiedV2AdvancedCreditDebitCard::class,
            [
                self::SERVICE_DEPENDENCY_PROVIDER => $dependencyProviderMock,
                self::SERVICE_ORDER_RESOURCE => $orderResourceMock,
                self::SERVICE_DBAL_CONNECTION => (new ConnectionMock())->createConnectionMock('1', 'fetch'),
            ],
            $request,
            $response
        );

        $paypalUnifiedV2Controller->indexAction();

        $counter = 0;
        foreach ($response->getHeaders() as $header) {
            if (\strtolower($header['name']) === 'location') {
                static::assertStringEndsWith(
                    '/checkout/finish/sUniqueID/123456789',
                    $header['value']
                );

                ++$counter;
            }
        }

        static::assertGreaterThan(0, $counter);
        static::assertSame(302, $response->getHttpResponseCode());
    }

    /**
     * @return Order
     */
    private function createPayPalOrder()
    {
        return (new SimplePayPalOrderCreator())->createSimplePayPalOrder();
    }
}
