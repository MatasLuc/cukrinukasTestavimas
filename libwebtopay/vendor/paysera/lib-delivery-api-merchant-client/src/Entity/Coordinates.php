<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class Coordinates extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return string|null
     */
    public function getLongitude()
    {
        return $this->get('longitude');
    }
    /**
     * @param string $longitude
     * @return $this
     */
    public function setLongitude($longitude)
    {
        $this->set('longitude', $longitude);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getLatitude()
    {
        return $this->get('latitude');
    }
    /**
     * @param string $latitude
     * @return $this
     */
    public function setLatitude($latitude)
    {
        $this->set('latitude', $latitude);
        return $this;
    }
}
