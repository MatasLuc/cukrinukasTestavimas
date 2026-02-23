<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class PostOffice extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->get('id');
    }
    /**
     * @param string $id
     * @return $this
     */
    public function setId($id)
    {
        $this->set('id', $id);
        return $this;
    }
    /**
     * @return string
     */
    public function getShipmentGatewayCode()
    {
        return $this->get('shipment_gateway_code');
    }
    /**
     * @param string $shipmentGatewayCode
     * @return $this
     */
    public function setShipmentGatewayCode($shipmentGatewayCode)
    {
        $this->set('shipment_gateway_code', $shipmentGatewayCode);
        return $this;
    }
    /**
     * @return string
     */
    public function getName()
    {
        return $this->get('name');
    }
    /**
     * @param string $name
     * @return $this
     */
    public function setName($name)
    {
        $this->set('name', $name);
        return $this;
    }
    /**
     * @return Address
     */
    public function getAddress()
    {
        return (new Address())->setDataByReference($this->getByReference('address'));
    }
    /**
     * @param Address $address
     * @return $this
     */
    public function setAddress(Address $address)
    {
        $this->setByReference('address', $address->getDataByReference());
        return $this;
    }
}
