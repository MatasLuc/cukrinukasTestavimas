<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Filter;

class CountryFilter extends Filter
{
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
