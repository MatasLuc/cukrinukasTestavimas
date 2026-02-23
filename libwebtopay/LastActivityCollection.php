<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class GatewayMethodsFilter extends Entity
{
    /**
     * @return string|null
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
     * @return string|null
     */
    public function getFromCountryCode()
    {
        return $this->get('from_country_code');
    }
    /**
     * @param string $fromCountryCode
     * @return $this
     */
    public function setFromCountryCode($fromCountryCode)
    {
        $this->set('from_country_code', $fromCountryCode);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getToCountryCode()
    {
        return $this->get('to_country_code');
    }
    /**
     * @param string $toCountryCode
     * @return $this
     */
    public function setToCountryCode($toCountryCode)
    {
        $this->set('to_country_code', $toCountryCode);
        return $this;
    }
}
