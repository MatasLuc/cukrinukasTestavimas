<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class OrderConfirmationError extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return string
     */
    public function getDescriptionToken()
    {
        return $this->get('description_token');
    }
    /**
     * @param string $descriptionToken
     * @return $this
     */
    public function setDescriptionToken($descriptionToken)
    {
        $this->set('description_token', $descriptionToken);
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
}
