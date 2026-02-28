<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class OrderNotificationCreate extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->get('url');
    }
    /**
     * @param string $url
     * @return $this
     */
    public function setUrl($url)
    {
        $this->set('url', $url);
        return $this;
    }
    /**
     * @return string[]
     */
    public function getEvents()
    {
        return $this->get('events');
    }
    /**
     * @param string[] $events
     * @return $this
     */
    public function setEvents(array $events)
    {
        $this->set('events', $events);
        return $this;
    }
}
