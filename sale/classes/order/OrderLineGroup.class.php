<?php
/*
    This file is part of Symbiose Community Edition <https://github.com/yesbabylon/symbiose>
    Some Rights Reserved, Yesbabylon SRL, 2020-2021
    Licensed under GNU AGPL 3 license <http://www.gnu.org/licenses/>
*/
namespace sale\order;
use equal\orm\Model;

class OrderLineGroup extends Model {

    public static function getName() {
        return "Order line group";
    }

    public static function getDescription() {
        return "Order line groups are related to a order and describe one or more sojourns and their related consumptions.";
    }

    public static function getColumns() {
        return [
            'name' => [
                'type'              => 'string',
                'description'       => 'Memo for the group.',
                'default'           => ''
            ],

            'order' => [
                'type'              => 'integer',
                'description'       => 'Order of the group in the list.',
                'default'           => 1
            ],

            'is_extra' => [
                'type'              => 'boolean',
                'description'       => 'Group relates to sales made off-contract. (ex. point of sale)',
                'default'           => false
            ],

            'order_lines_ids' => [
                'type'              => 'one2many',
                'foreign_object'    => 'sale\order\OrderLine',
                'foreign_field'     => 'order_line_group_id',
                'description'       => 'Order lines that belong to the group.',
                'ondetach'          => 'delete',
                'onupdate'          => 'onupdateOrderLinesIds'
            ],

            'order_id' => [
                'type'              => 'many2one',
                'foreign_object'    => 'sale\order\Order',
                'description'       => 'Order the line relates to (for consistency, lines should be accessed using the group they belong to).',
                'required'          => true,
                'ondelete'          => 'cascade'        // delete group when parent order is deleted
            ],

            'total' => [
                'type'              => 'computed',
                'result_type'       => 'float',
                'usage'             => 'amount/money:4',
                'description'       => 'Total tax-excluded price for all lines (computed).',
                'function'          => 'calcTotal',
                'store'             => true
            ],

            'price' => [
                'type'              => 'computed',
                'result_type'       => 'float',
                'usage'             => 'amount/money:2',
                'description'       => 'Final tax-included price for all lines (computed).',
                'function'          => 'calcPrice',
                'store'             => true
            ],

            'fare_benefit' => [
                'type'              => 'computed',
                'result_type'       => 'float',
                'usage'             => 'amount/money:2',
                'description'       => 'Total amount of the fare banefit VAT incl.',
                'function'          => 'calcFareBenefit',
                'store'             => true
            ]

        ];
    }

    public static function onupdateOrderLinesIds($om, $oids, $values, $lang) {
        $om->callonce(__CLASS__, '_resetPrices', $oids, [], $lang);
    }

    /**
     * In case prices of a group are impacted, we need to resett parent order and children lines as well.
     */
    public static function _resetPrices($om, $oids, $values, $lang) {
        // reset computed fields related to price
        $om->update(__CLASS__, $oids, ['total' => null, 'price' => null, 'fare_benefit' => null]);
        $groups = $om->read(__CLASS__, $oids, ['order_id', 'order_lines_ids', 'is_extra'], $lang);
        if($groups > 0) {
            $orders_ids = array_map(function ($a) { return $a['order_id']; }, $groups);
            // reset fields in parent orders
            $om->callonce('sale\order\Order', '_resetPrices', $orders_ids, [], $lang);
            // reset fields in children lines
            foreach($groups as $gid => $group) {
                // do not reset lines for extra-consumptions groups
                if(!$group['is_extra']) {
                    $om->callonce('sale\order\OrderLine', '_resetPrices', $group['order_lines_ids'], [], $lang);
                }
            }
        }
    }

    /**
     * Get total tax-excluded price of the group, with discount applied.
     *
     */
    public static function calcTotal($om, $oids, $lang) {
        $result = [];

        $groups = $om->read(get_called_class(), $oids, ['order_id', 'order_lines_ids.total']);

        $orders_ids = [];

        foreach($groups as $oid => $group) {
            $orders_ids[] = $group['order_id'];
            $result[$oid] = array_reduce($group['order_lines_ids.total'], function ($c, $a) {
                return $c + $a['total'];
            }, 0.0);
        }

        // reset parent order total price
        $om->write('sale\order\Order', array_unique($orders_ids), ['total' => null, 'price' => null]);

        return $result;
    }

    /**
     * Get final tax-included price of the group.
     *
     */
    public static function calcPrice($om, $oids, $lang) {
        $result = [];

        $groups = $om->read(get_called_class(), $oids, ['order_lines_ids.price']);

        foreach($groups as $oid => $group) {
            $result[$oid] = array_reduce($group['order_lines_ids.price'], function ($c, $a) {
                return $c + $a['price'];
            }, 0.0);
        }

        return $result;
    }

    /**
     * Retrieve sum of fare benefits granted on order lines.
     *
     */
    public static function calcFareBenefit($om, $oids, $lang) {
        $result = [];

        $groups = $om->read(get_called_class(), $oids, ['order_lines_ids.fare_benefit']);

        foreach($groups as $oid => $group) {
            $result[$oid] = array_reduce($group['order_lines_ids.fare_benefit'], function ($c, $a) {
                return $c + $a['fare_benefit'];
            }, 0.0);
        }

        return $result;
    }

    public static function candelete($om, $oids) {
        $groups = $om->read(get_called_class(), $oids, ['order_id']);

        if($groups) {
            foreach($groups as $gid => $group) {
                $om->update('sale\order\Order', $group['order_id'], ['price' => null, 'total' => null]);
            }
        }

        return parent::candelete($om, $oids);
    }

}