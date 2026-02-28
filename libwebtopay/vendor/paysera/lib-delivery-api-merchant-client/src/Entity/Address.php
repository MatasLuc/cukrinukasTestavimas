<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class Address extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return string
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
    public function getState()
    {
        return $this->get('state');
    }
    /**
     * @param string $state
     * @return $this
     */
    public function setState($state)
    {
        $this->set('state', $state);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getMunicipality()
    {
        return $this->get('municipality');
    }
    /**
     * @param string $municipality
     * @return $this
     */
    public function setMunicipality($municipality)
    {
        $this->set('municipality', $municipality);
        return $this;
    }
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
    public function getStreet()
    {
        return $this->get('street');
    }
    /**
     * @param string $street
     * @return $this
     */
    public function setStreet($street)
    {
        $this->set('street', $street);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getHouseNumber()
    {
        return $this->get('house_number');
    }
    /**
     * @param string $houseNumber
     * @return $this
     */
    public function setHouseNumber($houseNumber)
    {
        $this->set('house_number', $houseNumber);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getApartmentNumber()
    {
        return $this->get('apartment_number');
    }
    /**
     * @param string $apartmentNumber
     * @return $this
     */
    public function setApartmentNumber($apartmentNumber)
    {
        $this->set('apartment_number', $apartmentNumber);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getPostalCode()
    {
        return $this->get('postal_code');
    }
    /**
     * @param string $postalCode
     * @return $this
     */
    public function setPostalCode($postalCode)
    {
        $this->set('postal_code', $postalCode);
        return $this;
    }
}
