<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Result;

class OrdersStatesCountCollection extends Result
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return OrdersStatesCount[]
     */
    public function getList()
    {
        $items = $this->getByReference('list');
        if ($items === null) {
            return [];
        }

        $list = [];
        foreach($items as &$item) {
            $list[] = (new OrdersStatesCount())->setDataByReference($item);
        }

        return $list;
    }
    /**
     * @param OrdersStatesCount[] $list
     * @return $this
     */
    public function setList(array $list)
    {
        $data = [];
        foreach($list as $item) {
            $data[] = $item->getDataByReference();
        }
        $this->setByReference('list', $data);
        return $this;
    }
}
