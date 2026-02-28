<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class ShipmentGateway extends Entity
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
     * @return string
     */
    public function getDescription()
    {
        return $this->get('description');
    }
    /**
     * @param string $description
     * @return $this
     */
    public function setDescription($description)
    {
        $this->set('description', $description);
        return $this;
    }
    /**
     * @return string
     */
    public function getLogo()
    {
        return $this->get('logo');
    }
    /**
     * @param string $logo
     * @return $this
     */
    public function setLogo($logo)
    {
        $this->set('logo', $logo);
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
