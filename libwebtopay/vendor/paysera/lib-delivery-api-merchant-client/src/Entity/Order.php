<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Evp\Component\Money\Money;
use Paysera\Component\RestClientCommon\Entity\Entity;

class Order extends Entity
{
    const ORDER_CREATION_TYPE_ESHOP = 'eshop';
    const ORDER_CREATION_TYPE_MANUALLY = 'manually';

    public function __construct(array $data = [])
    {
        parent::__construct($data);
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
    public function getCheckoutProjectId()
    {
        return $this->get('checkout_project_id');
    }
    /**
     * @param string $checkoutProjectId
     * @return $this
     */
    public function setCheckoutProjectId($checkoutProjectId)
    {
        $this->set('checkout_project_id', $checkoutProjectId);
        return $this;
    }
    /**
     * @return ShipmentGateway|null
     */
    public function getShipmentGateway()
    {
        if ($this->get('shipment_gateway') === null) {
            return null;
        }
        return (new ShipmentGateway())->setDataByReference($this->getByReference('shipment_gateway'));
    }
    /**
     * @param ShipmentGateway $shipmentGateway
     * @return $this
     */
    public function setShipmentGateway(ShipmentGateway $shipmentGateway)
    {
        $this->setByReference('shipment_gateway', $shipmentGateway->getDataByReference());
        return $this;
    }
    /**
     * @return ShipmentMethod|null
     */
    public function getShipmentMethod()
    {
        if ($this->get('shipment_method') === null) {
            return null;
        }
        return (new ShipmentMethod())->setDataByReference($this->getByReference('shipment_method'));
    }
    /**
     * @param ShipmentMethod $shipmentMethod
     * @return $this
     */
    public function setShipmentMethod(ShipmentMethod $shipmentMethod)
    {
        $this->setByReference('shipment_method', $shipmentMethod->getDataByReference());
        return $this;
    }
    /**
     * @return ShipmentPoint|null
     */
    public function getSender()
    {
        if ($this->get('sender') === null) {
            return null;
        }
        return (new ShipmentPoint())->setDataByReference($this->getByReference('sender'));
    }
    /**
     * @param ShipmentPoint $sender
     * @return $this
     */
    public function setSender(ShipmentPoint $sender)
    {
        $this->setByReference('sender', $sender->getDataByReference());
        return $this;
    }
    /**
     * @return ShipmentPoint|null
     */
    public function getReceiver()
    {
        if ($this->get('receiver') === null) {
            return null;
        }
        return (new ShipmentPoint())->setDataByReference($this->getByReference('receiver'));
    }
    /**
     * @param ShipmentPoint $receiver
     * @return $this
     */
    public function setReceiver(ShipmentPoint $receiver)
    {
        $this->setByReference('receiver', $receiver->getDataByReference());
        return $this;
    }
    /**
     * @return ShipmentCollection
     */
    public function getShipments()
    {
        return (new ShipmentCollection())->setDataByReference($this->getByReference('shipments'));
    }
    /**
     * @param ShipmentCollection $shipments
     * @return $this
     */
    public function setShipments(ShipmentCollection $shipments)
    {
        $this->setByReference('shipments', $shipments->getDataByReference());
        return $this;
    }
    /**
     * @return boolean|null
     */
    public function isManifestExists()
    {
        return $this->get('manifest_exists');
    }
    /**
     * @param boolean $manifestExists
     * @return $this
     */
    public function setManifestExists($manifestExists)
    {
        $this->set('manifest_exists', $manifestExists);
        return $this;
    }
    /**
     * @return boolean
     */
    public function isLabelExists()
    {
        return $this->get('label_exists');
    }
    /**
     * @param boolean $labelExists
     * @return $this
     */
    public function setLabelExists($labelExists)
    {
        $this->set('label_exists', $labelExists);
        return $this;
    }
    /**
     * @return string
     */
    public function getNumber()
    {
        return $this->get('number');
    }
    /**
     * @param string $number
     * @return $this
     */
    public function setNumber($number)
    {
        $this->set('number', $number);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getNotes()
    {
        return $this->get('notes');
    }
    /**
     * @param string $notes
     * @return $this
     */
    public function setNotes($notes)
    {
        $this->set('notes', $notes);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getEshopOrderId()
    {
        return $this->get('eshop_order_id');
    }
    /**
     * @param string $eshopOrderId
     * @return $this
     */
    public function setEshopOrderId($eshopOrderId)
    {
        $this->set('eshop_order_id', $eshopOrderId);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getOrderCreationType()
    {
        return $this->get('order_creation_type');
    }
    /**
     * @param string $orderCreationType
     * @return $this
     */
    public function setOrderCreationType($orderCreationType)
    {
        $this->set('order_creation_type', $orderCreationType);
        return $this;
    }
    /**
     * @return string
     */
    public function getStatus()
    {
        return $this->get('status');
    }
    /**
     * @param string $status
     * @return $this
     */
    public function setStatus($status)
    {
        $this->set('status', $status);
        return $this;
    }
    /**
     * @return string
     */
    public function getStatusDescription()
    {
        return $this->get('status_description');
    }
    /**
     * @param string $statusDescription
     * @return $this
     */
    public function setStatusDescription($statusDescription)
    {
        $this->set('status_description', $statusDescription);
        return $this;
    }
    /**
     * @return Money|null
     */
    public function getPrice()
    {
        if (!isset($this->get('price')['amount']) || !isset($this->get('price')['currency'])) {
            return null;
        }
        return new Money($this->get('price')['amount'], $this->get('price')['currency']);
    }
    /**
     * @param Money $price
     * @return $this
     */
    public function setPrice(Money $price)
    {
        $this->set('price', ['amount' => $price->getAmount(), 'currency' => $price->getCurrency()]);
        return $this;
    }
    /**
     * @return integer
     */
    public function getCreatedAt()
    {
        return $this->get('created_at');
    }
    /**
     * @param integer $createdAt
     * @return $this
     */
    public function setCreatedAt($createdAt)
    {
        $this->set('created_at', $createdAt);
        return $this;
    }
    /**
     * @return OrderHistoryCollection
     */
    public function getHistory()
    {
        return (new OrderHistoryCollection())->setDataByReference($this->getByReference('history'));
    }
    /**
     * @param OrderHistoryCollection $history
     * @return $this
     */
    public function setHistory(OrderHistoryCollection $history)
    {
        $this->setByReference('history', $history->getDataByReference());
        return $this;
    }
    /**
     * @return OrderConfirmationErrorCollection|null
     */
    public function getConfirmationErrors()
    {
        if ($this->get('confirmation_errors') === null) {
            return null;
        }
        return (new OrderConfirmationErrorCollection())->setDataByReference($this->getByReference('confirmation_errors'));
    }
    /**
     * @param OrderConfirmationErrorCollection $confirmationErrors
     * @return $this
     */
    public function setConfirmationErrors(OrderConfirmationErrorCollection $confirmationErrors)
    {
        $this->setByReference('confirmation_errors', $confirmationErrors->getDataByReference());
        return $this;
    }
    /**
     * @return OrderNotification|null
     */
    public function getOrderNotification()
    {
        if ($this->get('order_notification') === null) {
            return null;
        }
        return (new OrderNotification())->setDataByReference($this->getByReference('order_notification'));
    }
    /**
     * @param OrderNotification $orderNotification
     * @return $this
     */
    public function setOrderNotification(OrderNotification $orderNotification)
    {
        $this->setByReference('order_notification', $orderNotification->getDataByReference());
        return $this;
    }

    /**
     * @param bool|null $testMode
     * @return $this
     */
    public function setTestMode($testMode)
    {
        $this->set('test_mode', $testMode);
        return $this;
    }

    /**
     * @return bool|null
     */
    public function getTestMode()
    {
        return $this->get('test_mode');
    }
}
