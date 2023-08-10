<?php
/*
    This file is part of Symbiose Community Edition <https://github.com/yesbabylon/symbiose>
    Some Rights Reserved, Yesbabylon SRL, 2020-2021
    Licensed under GNU AGPL 3 license <http://www.gnu.org/licenses/>
*/

use finance\accounting\Invoice;
use sale\receivable\Receivable;

list($params, $providers) = announce([
    'description'   => "Create a invoice.",
    'params'        => [

        'ids' =>  [
            'description'       => 'Identifier of the targeted reports.',
            'type'              => 'one2many',
            'foreign_object'    => 'sale\receivable\Receivable',
            'required'          => true
        ],

        'is_receivables_pending' => [
            'type'              => 'boolean',
            'description'       => 'The receivables be will pending',
            'default'           => true
        ],

    ],
    'response'      => [
        'content-type'  => 'application/json',
        'charset'       => 'utf-8',
        'accept-origin' => '*'
    ],
    'providers'     => [ 'context', 'orm' ]
]);

list($context, $orm) = [$providers['context'], $providers['orm']];


$invoices = Invoice::ids($params['ids'])
    ->read([
        'id',
        'status',
        'customer_id'
    ]);

if(!$invoices) {
    throw new Exception('unknown_invoice', QN_ERROR_UNKNOWN_OBJECT);
}

foreach($invoices as $id => $invoice) {

    if ($invoice['status'] != 'invoice') {
        continue;
    }

    $receivables= Receivable::search([
        ['status', "=", "invoiced"],
        ['customer_id', "=", $invoice['customer_id']],
        ['invoice_id', "=", $invoice['id']]
    ])->ids();

    if(!$receivables) {
        throw new Exception('unknown_receivable', QN_ERROR_UNKNOWN_OBJECT);
    }
    print_r($receivables);
    die();

    foreach($receivables as $id => $receivable) {

        if($is_receivables_pending){

            Receivable::ids($receivable['id'])
            ->update([
                'status'               => 'pending',
                'invoice_id'           => null,
                'invoice_line_id'      => null,
            ]);
        }else{

            Receivable::ids($receivable['id'])
            ->update([
                'status'               => 'cancelled'
            ]);
        }

    }

    Invoice::ids($invoice['id'])
    ->update([
        'status'      => 'cancelled'
    ]);


}


$context->httpResponse()
->status(204)
->send();