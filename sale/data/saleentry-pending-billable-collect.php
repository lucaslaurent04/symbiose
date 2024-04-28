<?php
/*
    This file is part of the Discope property management software.
    Author: Yesbabylon SRL, 2020-2024
    License: GNU AGPL 3 license <http://www.gnu.org/licenses/>
*/

use equal\orm\Domain;

list($params, $providers) = eQual::announce([
    'description'   => 'Advanced search for pending billable Sale Entry: returns a collection of pending billable Sale Entries according to extra parameters.',
    'extends'       => 'core_model_collect',
    'params'        => [

        'entity' =>  [
            'type'              => 'string',
            'description'       => 'Full name (including namespace) of the class to return.',
            'default'           => 'sale\SaleEntry'
        ],

        'customer_id'=> [
            'type'              => 'many2one',
            'foreign_object'    => 'sale\customer\Customer',
            'description'       => 'The Customer to who refers the item.',
            'min'               => 1
        ],

        'product_id'=> [
            'type'              => 'many2one',
            'foreign_object'    => 'sale\catalog\Product',
            'description'       => 'Product of the catalog sale.',
            'min'               => 1
        ]

    ],
    'response'      => [
        'content-type'  => 'application/json',
        'charset'       => 'utf-8',
        'accept-origin' => '*'
    ],
    'providers'     => ['context']
]);

/** @var \equal\php\Context $context */
$context = $providers['context'];

$domain = [
    ['is_billable', '=', true],
    ['has_receivable', '=', false]
];

if(isset($params['customer_id'])) {
    $domain[] = ['customer_id', '=', $params['customer_id']];
}

if(isset($params['product_id'])) {
    $domain[] = ['product_id', '=', $params['product_id']];
}

$params['domain'] = (new Domain($params['domain']))
    ->merge(new Domain($domain))
    ->toArray();

$result = eQual::run('get', 'model_collect', $params, true);

$context->httpResponse()
        ->body($result)
        ->send();