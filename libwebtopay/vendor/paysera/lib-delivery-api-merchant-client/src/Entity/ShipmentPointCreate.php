<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class ShipmentPointCreate extends Entity
{
    const TYPE_SENDER = 'sender';
    const TYPE_RECEIVER = 'receiver';

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
     * @return boolean
     */
    public function isSaved()
    {
        return $this->get('saved');
    }
    /**
     * @param boolean $saved
     * @return $this
     */
    public function setSaved($saved)
    {
        $this->set('saved', $saved);
        return $this;
    }
    /**
     * @return Contact|null
     */
    public function getContact()
    {
        if ($this->get('contact') === null) {
            return null;
        }
        return (new Contact())->setDataByReference($this->getByReference('contact'));
    }
    /**
     * @param Contact $contact
     * @return $this
     */
    public function setContact(Contact $contact)
    {
        $this->setByReference('contact', $contact->getDataByReference());
        return $this;
    }
    /**
     * @return string|null
     */
    public function getPostOfficeId()
    {
        return $this->get('post_office_id');
    }
    /**
     * @param string $postOfficeId
     * @return $this
     */
    public function setPostOfficeId($postOfficeId)
    {
        $this->set('post_office_id', $postOfficeId);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getParcelMachineId()
    {
        return $this->get('parcel_machine_id');
    }
    /**
     * @param string $parcelMachineId
     * @return $this
     */
    public function setParcelMachineId($parcelMachineId)
    {
        $this->set('parcel_machine_id', $parcelMachineId);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getAdditionalInfo()
    {
        return $this->get('additional_info');
    }
    /**
     * @param string $additionalInfo
     * @return $this
     */
    public function setAdditionalInfo($additionalInfo)
    {
        $this->set('additional_info', $additionalInfo);
        return $this;
    }
    /**
     * @return boolean
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
}
