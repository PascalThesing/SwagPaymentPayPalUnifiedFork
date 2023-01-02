<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagPaymentPayPalUnified\Tests\Functional;

use Shopware_Components_Translation;

trait TranslationTestCaseTrait
{
    use ContainerTrait;

    /**
     * @return Shopware_Components_Translation
     */
    public function getTranslationService()
    {
        $translation = new Shopware_Components_Translation($this->getContainer()->get('dbal_connection'), $this->getContainer());

        if ($this->getContainer()->initialized('translation')) {
            $translation = $this->getContainer()->get('translation');
        }

        return $translation;
    }
}
