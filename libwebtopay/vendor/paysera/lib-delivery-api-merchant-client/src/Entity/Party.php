<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class Party extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->get('title');
    }
    /**
     * @param string $title
     * @return $this
     */
    public function setTitle($title)
    {
        $this->set('title', $title);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getCompanyCode()
    {
        return $this->get('company_code');
    }
    /**
     * @param string $companyCode
     * @return $this
     */
    public function setCompanyCode($companyCode)
    {
        $this->set('company_code', $companyCode);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getEmail()
    {
        return $this->get('email');
    }
    /**
     * @param string $email
     * @return $this
     */
    public function setEmail($email)
    {
        $this->set('email', $email);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getPhone()
    {
        return $this->get('phone');
    }
    /**
     * @param string $phone
     * @return $this
     */
    public function setPhone($phone)
    {
        $this->set('phone', $phone);
        return $this;
    }
}
