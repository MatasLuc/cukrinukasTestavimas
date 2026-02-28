<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class ActivityFilter extends Entity
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
    /**
     * @return string
     */
    public function getDateFrom()
    {
        return $this->get('date_from');
    }
    /**
     * @param string $dateFrom
     * @return $this
     */
    public function setDateFrom($dateFrom)
    {
        $this->set('date_from', $dateFrom);
        return $this;
    }
    /**
     * @return string
     */
    public function getDateTill()
    {
        return $this->get('date_till');
    }
    /**
     * @param string $dateTill
     * @return $this
     */
    public function setDateTill($dateTill)
    {
        $this->set('date_till', $dateTill);
        return $this;
    }
}
