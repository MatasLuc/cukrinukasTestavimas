<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class OrderIdsList extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return string[]
     */
    public function getOrderIds()
    {
        return $this->get('order_ids');
    }
    /**
     * @param string[] $orderIds
     * @return $this
     */
    public function setOrderIds(array $orderIds)
    {
        $this->set('order_ids', $orderIds);
        return $this;
    }
}
