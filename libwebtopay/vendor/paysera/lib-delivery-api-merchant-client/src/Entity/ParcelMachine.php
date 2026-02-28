<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class ParcelMachine extends Entity
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
    public function getCode()
    {
        return $this->get('code');
    }
    /**
     * @param string $code
     * @return $this
     */
    public function setCode($code)
    {
        $this->set('code', $code);
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
    /**
     * @return Coordinates|null
     */
    public function getCoordinates()
    {
        if ($this->get('coordinates') === null) {
            return null;
        }
        return (new Coordinates())->setDataByReference($this->getByReference('coordinates'));
    }
    /**
     * @param Coordinates $coordinates
     * @return $this
     */
    public function setCoordinates(Coordinates $coordinates)
    {
        $this->setByReference('coordinates', $coordinates->getDataByReference());
        return $this;
    }
    /**
     * @return string|null
     */
    public function getLocationName()
    {
        return $this->get('location_name');
    }
    /**
     * @param string $locationName
     * @return $this
     */
    public function setLocationName($locationName)
    {
        $this->set('location_name', $locationName);
        return $this;
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
