<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Filter;

class CityFilter extends Filter
{
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
    public function getGatewayCode()
    {
        return $this->get('gateway_code');
    }
    /**
     * @param string $gatewayCode
     * @return $this
     */
    public function setGatewayCode($gatewayCode)
    {
        $this->set('gateway_code', $gatewayCode);
        return $this;
    }
}
