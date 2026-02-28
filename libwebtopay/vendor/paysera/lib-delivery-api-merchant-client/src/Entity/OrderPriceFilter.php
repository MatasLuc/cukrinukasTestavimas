<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class OrderPriceFilter extends Entity
{
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
     * @return string|null
     */
    public function getSenderId()
    {
        return $this->get('sender_id');
    }
    /**
     * @param string $senderId
     * @return $this
     */
    public function setSenderId($senderId)
    {
        $this->set('sender_id', $senderId);
        return $this;
    }
    /**
     * @return ShipmentPointCreate|null
     */
    public function getSender()
    {
        if ($this->get('sender') === null) {
            return null;
        }
        return (new ShipmentPointCreate())->setDataByReference($this->getByReference('sender'));
    }
    /**
     * @param ShipmentPointCreate $sender
     * @return $this
     */
    public function setSender(ShipmentPointCreate $sender)
    {
        $this->setByReference('sender', $sender->getDataByReference());
        return $this;
    }
    /**
     * @return string|null
     */
    public function getReceiverId()
    {
        return $this->get('receiver_id');
    }
    /**
     * @param string $receiverId
     * @return $this
     */
    public function setReceiverId($receiverId)
    {
        $this->set('receiver_id', $receiverId);
        return $this;
    }
    /**
     * @return ShipmentPointCreate|null
     */
    public function getReceiver()
    {
        if ($this->get('receiver') === null) {
            return null;
        }
        return (new ShipmentPointCreate())->setDataByReference($this->getByReference('receiver'));
    }
    /**
     * @param ShipmentPointCreate $receiver
     * @return $this
     */
    public function setReceiver(ShipmentPointCreate $receiver)
    {
        $this->setByReference('receiver', $receiver->getDataByReference());
        return $this;
    }
    /**
     * @return ShipmentCreate[]
     */
    public function getShipments()
    {
        $items = $this->getByReference('shipments');
        if ($items === null) {
            return [];
        }

        $list = [];
        foreach($items as &$item) {
            $list[] = (new ShipmentCreate())->setDataByReference($item);
        }

        return $list;
    }
    /**
     * @param ShipmentCreate[] $shipments
     * @return $this
     */
    public function setShipments(array $shipments)
    {
        $data = [];
        foreach($shipments as $item) {
            $data[] = $item->getDataByReference();
        }
        $this->setByReference('shipments', $data);
        return $this;
    }
}
