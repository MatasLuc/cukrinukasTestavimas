<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class CourierApiCredentials extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return string
     */
    public function getProject()
    {
        return $this->get('project');
    }
    /**
     * @param string $project
     * @return $this
     */
    public function setProject($project)
    {
        $this->set('project', $project);
        return $this;
    }
    /**
     * @return string
     */
    public function getGateway()
    {
        return $this->get('gateway');
    }
    /**
     * @param string $gateway
     * @return $this
     */
    public function setGateway($gateway)
    {
        $this->set('gateway', $gateway);
        return $this;
    }
    /**
     * @return string
     */
    public function getUsername()
    {
        return $this->get('username');
    }
    /**
     * @param string $username
     * @return $this
     */
    public function setUsername($username)
    {
        $this->set('username', $username);
        return $this;
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
     * @return string|null
     */
    public function getClientId()
    {
        return $this->get('client_id');
    }
    /**
     * @param string $clientId
     * @return $this
     */
    public function setClientId($clientId)
    {
        $this->set('client_id', $clientId);
        return $this;
    }
}
