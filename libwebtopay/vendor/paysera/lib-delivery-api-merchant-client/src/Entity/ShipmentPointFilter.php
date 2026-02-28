<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Filter;

class ShipmentPointFilter extends Filter
{
    /**
     * @return string|null
     */
    public function getType()
    {
        return $this->get('type');
    }
    /**
     * @param string $type
     * @return $this
     */
    public function setType($type)
    {
        $this->set('type', $type);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getTitlePart()
    {
        return $this->get('title_part');
    }
    /**
     * @param string $titlePart
     * @return $this
     */
    public function setTitlePart($titlePart)
    {
        $this->set('title_part', $titlePart);
        return $this;
    }
    /**
     * @return boolean|null
     */
    public function isDefaultContact()
    {
        return $this->get('default_contact');
    }
    /**
     * @param boolean $defaultContact
     * @return $this
     */
    public function setDefaultContact($defaultContact)
    {
        $this->set('default_contact', $defaultContact);
        return $this;
    }
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
