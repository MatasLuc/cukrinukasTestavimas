<?php

namespace Paysera\DeliveryApi\MerchantClient\Entity;

use Paysera\Component\RestClientCommon\Entity\Result;

class LastActivityCollection extends Result
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return LastActivity[]
     */
    public function getItems()
    {
        $items = $this->getByReference('items');
        if ($items === null) {
            return [];
        }

        $list = [];
        foreach($items as &$item) {
            $list[] = (new LastActivity())->setDataByReference($item);
        }

        return $list;
    }
    /**
     * @param LastActivity[] $items
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
