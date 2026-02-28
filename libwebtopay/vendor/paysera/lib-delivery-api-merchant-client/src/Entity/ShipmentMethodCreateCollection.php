<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Entity;

class ShipmentMethodCreateCollection extends Entity
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return ShipmentMethodUpdate[]
     */
    public function getList()
    {
        $items = $this->getByReference('list');
        if ($items === null) {
            return [];
        }

        $list = [];
        foreach($items as &$item) {
            $list[] = (new ShipmentMethodUpdate())->setDataByReference($item);
        }

        return $list;
    }
    /**
     * @param ShipmentMethodUpdate[] $list
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
