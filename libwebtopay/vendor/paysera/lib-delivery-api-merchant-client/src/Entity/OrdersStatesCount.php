<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class OrdersStatesCount extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return string
     */
    public function getState()
    {
        return $this->get('state');
    }
    /**
     * @param string $state
     * @return $this
     */
    public function setState($state)
    {
        $this->set('state', $state);
        return $this;
    }
    /**
     * @return integer
     */
    public function getCount()
    {
        return $this->get('count');
    }
    /**
     * @param integer $count
     * @return $this
     */
    public function setCount($count)
    {
        $this->set('count', $count);
        return $this;
    }
}
