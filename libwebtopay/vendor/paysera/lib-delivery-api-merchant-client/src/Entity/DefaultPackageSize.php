<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class DefaultPackageSize extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
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
     * @return integer
     */
    public function getWeight()
    {
        return $this->get('weight');
    }
    /**
     * @param integer $weight
     * @return $this
     */
    public function setWeight($weight)
    {
        $this->set('weight', $weight);
        return $this;
    }
    /**
     * @return integer
     */
    public function getWidth()
    {
        return $this->get('width');
    }
    /**
     * @param integer $width
     * @return $this
     */
    public function setWidth($width)
    {
        $this->set('width', $width);
        return $this;
    }
    /**
     * @return integer
     */
    public function getLength()
    {
        return $this->get('length');
    }
    /**
     * @param integer $length
     * @return $this
     */
    public function setLength($length)
    {
        $this->set('length', $length);
        return $this;
    }
    /**
     * @return integer
     */
    public function getHeight()
    {
        return $this->get('height');
    }
    /**
     * @param integer $height
     * @return $this
     */
    public function setHeight($height)
    {
        $this->set('height', $height);
        return $this;
    }
}
