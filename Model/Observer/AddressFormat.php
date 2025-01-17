<?php
/**
 * Astound
 * NOTICE OF LICENSE
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to codemaster@astoundcommerce.com so we can send you a copy immediately.
 *
 * @category  Affirm
 * @package   Astound_Affirm
 * @copyright Copyright (c) 2016 Astound, Inc. (http://www.astoundcommerce.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Astound\Affirm\Model\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Directory\Model\RegionFactory;
use Magento\Directory\Model\ResourceModel\Region as RegionResource;

class AddressFormat implements ObserverInterface
{
    public RegionFactory $regionFactory;
    public RegionResource $regionResource;

    public function __construct(
        RegionFactory $regionFactory,
        RegionResource $regionResource
    )
    {
        $this->regionFactory = $regionFactory;
        $this->regionResource = $regionResource;
    }
    /**
     * Save region if address object has region_id but not region name
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return $this
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $address = $observer->getEvent()->getAddress();

        if(!$address->getAddressType()) {
            return $this;
        }

        if (!$address->getRegion() && $address->getRegionId()) {
            $regionId = $address->getRegionId();

            /** @var \Magento\Directory\Model\Region $region */
            $region = $this->regionFactory->create();

            $this->regionResource->load($region, $regionId);

            if ($region->isEmpty()) {
                return $this;
            }

            $address->setRegion($region->getName())
                ->setRegionCode($region->getCode())
                ->save();
        }

        return $this;
    }
}
