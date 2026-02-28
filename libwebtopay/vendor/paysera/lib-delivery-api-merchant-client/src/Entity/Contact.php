<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class Contact extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return Party
     */
    public function getParty()
    {
        return (new Party())->setDataByReference($this->getByReference('party'));
    }
    /**
     * @param Party $party
     * @return $this
     */
    public function setParty(Party $party)
    {
        $this->setByReference('party', $party->getDataByReference());
        return $this;
    }
    /**
     * @return Address
     */
    public function getAddress()
    {
        return (new Address())->setDataByReference($this->getByReference('address'));
    }
    /**
     * @param Address $address
     * @return $this
     */
    public function setAddress(Address $address)
    {
        $this->setByReference('address', $address->getDataByReference());
        return $this;
    }
}
