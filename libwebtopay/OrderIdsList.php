<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Filter;

class OrderFilter extends Filter
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
     * @return string[]|null
     */
    public function getOrderStatuses()
    {
        return $this->get('order_statuses');
    }
    /**
     * @param string[] $orderStatuses
     * @return $this
     */
    public function setOrderStatuses(array $orderStatuses)
    {
        $this->set('order_statuses', $orderStatuses);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getReceiverCountryCode()
    {
        return $this->get('receiver_country_code');
    }
    /**
     * @param string $receiverCountryCode
     * @return $this
     */
    public function setReceiverCountryCode($receiverCountryCode)
    {
        $this->set('receiver_country_code', $receiverCountryCode);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getReceiverNamePart()
    {
        return $this->get('receiver_name_part');
    }
    /**
     * @param string $receiverNamePart
     * @return $this
     */
    public function setReceiverNamePart($receiverNamePart)
    {
        $this->set('receiver_name_part', $receiverNamePart);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getReceiverPhonePart()
    {
        return $this->get('receiver_phone_part');
    }
    /**
     * @param string $receiverPhonePart
     * @return $this
     */
    public function setReceiverPhonePart($receiverPhonePart)
    {
        $this->set('receiver_phone_part', $receiverPhonePart);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getReceiverStreetPart()
    {
        return $this->get('receiver_street_part');
    }
    /**
     * @param string $receiverStreetPart
     * @return $this
     */
    public function setReceiverStreetPart($receiverStreetPart)
    {
        $this->set('receiver_street_part', $receiverStreetPart);
        return $this;
    }
    /**
     * @return string
     */
    public function getCreatedDateFrom()
    {
        return $this->get('created_date_from');
    }
    /**
     * @param string $createdDateFrom
     * @return $this
     */
    public function setCreatedDateFrom($createdDateFrom)
    {
        $this->set('created_date_from', $createdDateFrom);
        return $this;
    }
    /**
     * @return string
     */
    public function getCreatedDateTill()
    {
        return $this->get('created_date_till');
    }
    /**
     * @param string $createdDateTill
     * @return $this
     */
    public function setCreatedDateTill($createdDateTill)
    {
        $this->set('created_date_till', $createdDateTill);
        return $this;
    }
    /**
     * @return string|null
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
    public function getTrackingCode()
    {
        return $this->get('tracking_code');
    }
    /**
     * @param string $trackingCode
     * @return $this
     */
    public function setTrackingCode($trackingCode)
    {
        $this->set('tracking_code', $trackingCode);
        return $this;
    }
    /**
     * @return string|null
     */
    public function getShippingGatewayCode()
    {
        return $this->get('shipping_gateway_code');
    }
    /**
     * @param string $shippingGatewayCode
     * @return $this
     */
    public function setShippingGatewayCode($shippingGatewayCode)
    {
        $this->set('shipping_gateway_code', $shippingGatewayCode);
        return $this;
    }
    /**
     * @return boolean|null
     */
    public function isWithManifest()
    {
        return $this->get('with_manifest');
    }
    /**
     * @param boolean $withManifest
     * @return $this
     */
    public function setWithManifest($withManifest)
    {
        $this->set('with_manifest', $withManifest);
        return $this;
    }
    /**
     * @return boolean|null
     */
    public function isWithLabel()
    {
        return $this->get('with_label');
    }
    /**
     * @param boolean $withLabel
     * @return $this
     */
    public function setWithLabel($withLabel)
    {
        $this->set('with_label', $withLabel);
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
}
