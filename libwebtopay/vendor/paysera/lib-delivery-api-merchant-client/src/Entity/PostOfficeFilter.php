<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Filter;

class PostOfficeFilter extends Filter
{
    /**
     * @return string|null
     */
    public function getCity()
    {
        return $this->get('city');
    }
    /**
     * @param string $city
     * @return $this
     */
    public function setCity($city)
    {
        $this->set('city', $city);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getCountry()
    {
        return $this->get('country');
    }
    /**
     * @param string $country
     * @return $this
     */
    public function setCountry($country)
    {
        $this->set('country', $country);
        return $this;
    }
    /**
     * @return string|null
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
}
