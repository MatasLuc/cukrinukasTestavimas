<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class DefaultPackageSizeSet extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return DefaultPackageSize|null
     */
    public function getDefaultPackageSize()
    {
        if ($this->get('default_package_size') === null) {
            return null;
        }
        return (new DefaultPackageSize())->setDataByReference($this->getByReference('default_package_size'));
    }
    /**
     * @param DefaultPackageSize $defaultPackageSize
     * @return $this
     */
    public function setDefaultPackageSize(DefaultPackageSize $defaultPackageSize)
    {
        $this->setByReference('default_package_size', $defaultPackageSize->getDataByReference());
        return $this;
    }
}
