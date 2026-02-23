<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class ShipmentMethod extends Entity
{
    const CODE_COURIER2COURIER = 'courier2courier';
    const CODE_COURIER2PARCEL_MACHINE = 'courier2parcel-machine';
    const CODE_COURIER2POST_OFFICE = 'courier2post-office';
    const CODE_PARCEL_MACHINE2COURIER = 'parcel-machine2courier';
    const CODE_PARCEL_MACHINE2PARCEL_MACHINE = 'parcel-machine2parcel-machine';
    const CODE_POST_OFFICE2COURIER = 'post-office2courier';
    const CODE_POST_OFFICE2POST_OFFICE = 'post-office2post-office';
    const RECEIVER_CODE_COURIER = 'courier';
    const RECEIVER_CODE_PARCEL_MACHINE = 'parcel-machine';
    const RECEIVER_CODE_POST_OFFICE = 'post-office';

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
    public function getReceiverCode()
    {
        return $this->get('receiver_code');
    }
    /**
     * @param string $receiverCode
     * @return $this
     */
    public function setReceiverCode($receiverCode)
    {
        $this->set('receiver_code', $receiverCode);
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
