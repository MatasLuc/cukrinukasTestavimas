<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Evp\Component\Money\Money;
use Paysera\Component\RestClientCommon\Entity\Entity;

class CustomDeclaration extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return Money
     */
    public function getPrice()
    {
        return new Money(
            $this->get('price')['amount'],
            $this->get('price')['currency']
        );
    }

    /**
     * @param Money $price
     *
     * @return $this
     */
    public function setPrice(Money $price)
    {
        $this->set(
            'price',
            [
                'amount' => $price->getAmount(),
                'currency' => $price->getCurrency(),
            ]
        );

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
     *
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
    public function getManufacturedCountry()
    {
        return $this->get('manufactured_country');
    }

    /**
     * @param string $manufacturedCountry
     *
     * @return $this
     */
    public function setManufacturedCountry($manufacturedCountry)
    {
        $this->set('manufactured_country', $manufacturedCountry);

        return $this;
    }
}
