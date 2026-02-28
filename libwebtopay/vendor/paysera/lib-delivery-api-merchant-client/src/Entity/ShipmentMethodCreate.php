<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class ShipmentMethodCreate extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return boolean
     */
    public function isEnabled()
    {
        return $this->get('enabled');
    }
    /**
     * @param boolean $enabled
     * @return $this
     */
    public function setEnabled($enabled)
    {
        $this->set('enabled', $enabled);
        return $this;
    }
}
