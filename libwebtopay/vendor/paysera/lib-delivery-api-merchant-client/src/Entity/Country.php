<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class Country extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return string
     */
    public function getCountryCode()
    {
        return $this->get('country_code');
    }
    /**
     * @param string $countryCode
     * @return $this
     */
    public function setCountryCode($countryCode)
    {
        $this->set('country_code', $countryCode);
        return $this;
    }
    /**
     * @return string
     */
    public function getCountryName()
    {
        return $this->get('country_name');
    }
    /**
     * @param string $countryName
     * @return $this
     */
    public function setCountryName($countryName)
    {
        $this->set('country_name', $countryName);
        return $this;
    }
}
