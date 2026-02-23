<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class Activity extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return string
     */
    public function getDay()
    {
        return $this->get('day');
    }
    /**
     * @param string $day
     * @return $this
     */
    public function setDay($day)
    {
        $this->set('day', $day);
        return $this;
    }
    /**
     * @return integer
     */
    public function getDelivered()
    {
        return $this->get('delivered');
    }
    /**
     * @param integer $delivered
     * @return $this
     */
    public function setDelivered($delivered)
    {
        $this->set('delivered', $delivered);
        return $this;
    }
    /**
     * @return integer
     */
    public function getOnRoad()
    {
        return $this->get('on_road');
    }
    /**
     * @param integer $onRoad
     * @return $this
     */
    public function setOnRoad($onRoad)
    {
        $this->set('on_road', $onRoad);
        return $this;
    }
    /**
     * @return integer
     */
    public function getTotal()
    {
        return $this->get('total');
    }
    /**
     * @param integer $total
     * @return $this
     */
    public function setTotal($total)
    {
        $this->set('total', $total);
        return $this;
    }
}
