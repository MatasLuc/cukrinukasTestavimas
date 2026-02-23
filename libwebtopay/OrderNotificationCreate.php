<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class OrderHistoryCollection extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return OrderHistory[]
     */
    public function getItems()
    {
        $items = $this->getByReference('items');
        if ($items === null) {
            return [];
        }

        $list = [];
        foreach($items as &$item) {
            $list[] = (new OrderHistory())->setDataByReference($item);
        }

        return $list;
    }
    /**
     * @param OrderHistory[] $items
     * @return $this
     */
    public function setItems(array $items)
    {
        $data = [];
        foreach($items as $item) {
            $data[] = $item->getDataByReference();
        }
        $this->setByReference('items', $data);
        return $this;
    }
}
