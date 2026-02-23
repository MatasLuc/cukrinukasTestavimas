<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Evp\Component\Money\Money;
use Paysera\Component\RestClientCommon\Entity\Entity;

class OrderPrice extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return ShipmentGateway
     */
    public function getShipmentGateway()
    {
        return (new ShipmentGateway())->setDataByReference($this->getByReference('shipment_gateway'));
    }
    /**
     * @param ShipmentGateway $shipmentGateway
     * @return $this
     */
    public function setShipmentGateway(ShipmentGateway $shipmentGateway)
    {
        $this->setByReference('shipment_gateway', $shipmentGateway->getDataByReference());
        return $this;
    }
    /**
     * @return ShipmentMethod
     */
    public function getShipmentMethod()
    {
        return (new ShipmentMethod())->setDataByReference($this->getByReference('shipment_method'));
    }
    /**
     * @param ShipmentMethod $shipmentMethod
     * @return $this
     */
    public function setShipmentMethod(ShipmentMethod $shipmentMethod)
    {
        $this->setByReference('shipment_method', $shipmentMethod->getDataByReference());
        return $this;
    }
    /**
     * @return Money
     */
    public function getPrice()
    {
        return new Money($this->get('price')['amount'], $this->get('price')['currency']);
    }
    /**
     * @param Money $price
     * @return $this
     */
    public function setPrice(Money $price)
    {
        $this->set('price', ['amount' => $price->getAmount(), 'currency' => $price->getCurrency()]);
        return $this;
    }
}
