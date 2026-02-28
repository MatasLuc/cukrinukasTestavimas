<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class ProjectCredentials extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return string
     */
    public function getProjectId()
    {
        return $this->get('project_id');
    }

    /**
     * @param string $projectId
     * @return $this
     */
    public function setProjectId($projectId)
    {
        $this->set('project_id', $projectId);
        return $this;
    }

    /**
     * @return string
     */
    public function getPassword()
    {
        return $this->get('password');
    }

    /**
     * @param string $password
     * @return $this
     */
    public function setPassword($password)
    {
        $this->set('password', $password);
        return $this;
    }
}