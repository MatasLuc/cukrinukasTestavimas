<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class LastActivity extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return integer
     */
    public function getDate()
    {
        return $this->get('date');
    }
    /**
     * @param integer $date
     * @return $this
     */
    public function setDate($date)
    {
        $this->set('date', $date);
        return $this;
    }
    /**
     * @return string
     */
    public function getStatus()
    {
        return $this->get('status');
    }
    /**
     * @param string $status
     * @return $this
     */
    public function setStatus($status)
    {
        $this->set('status', $status);
        return $this;
    }
    /**
     * @return string
     */
    public function getStatusDescription()
    {
        return $this->get('status_description');
    }
    /**
     * @param string $statusDescription
     * @return $this
     */
    public function setStatusDescription($statusDescription)
    {
        $this->set('status_description', $statusDescription);
        return $this;
    }
    /**
     * @return Order
     */
    public function getOrder()
    {
        return (new Order())->setDataByReference($this->getByReference('order'));
    }
    /**
     * @param Order $order
     * @return $this
     */
    public function setOrder(Order $order)
    {
        $this->setByReference('order', $order->getDataByReference());
        return $this;
    }
}
