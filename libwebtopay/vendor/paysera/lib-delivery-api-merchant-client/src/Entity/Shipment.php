<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class Shipment extends Entity
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
    public function getPackageId()
    {
        return $this->get('package_id');
    }
    /**
     * @param string $packageId
     * @return $this
     */
    public function setPackageId($packageId)
    {
        $this->set('package_id', $packageId);
        return $this;
    }
    /**
     * @return string
     */
    public function getStatus()
    {
        return $this->get('status');
    }
    /**
     * @param string $status
     * @return $this
     */
    public function setStatus($status)
    {
        $this->set('status', $status);
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
    /**
     * @return string|null
     */
    public function getTrackingCode()
    {
        return $this->get('tracking_code');
    }
    /**
     * @param string $trackingCode
     * @return $this
     */
    public function setTrackingCode($trackingCode)
    {
        $this->set('tracking_code', $trackingCode);
        return $this;
    }
}
