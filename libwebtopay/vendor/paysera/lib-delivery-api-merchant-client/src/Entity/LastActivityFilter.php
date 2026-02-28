<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Filter;

class LastActivityFilter extends Filter
{
    /**
     * @return string[]|null
     */
    public function getProjects()
    {
        return $this->get('projects');
    }
    /**
     * @param string[] $projects
     * @return $this
     */
    public function setProjects(array $projects)
    {
        $this->set('projects', $projects);
        return $this;
    }
}
