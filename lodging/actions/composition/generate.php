<?php
/*
    This file is part of Symbiose Community Edition <https://github.com/yesbabylon/symbiose>
    Some Rights Reserved, Yesbabylon SRL, 2020-2021
    Licensed under GNU AGPL 3 license <http://www.gnu.org/licenses/>
*/

use lodging\realestate\RentalUnit;
use sale\booking\Composition;
use sale\booking\CompositionItem;
use lodging\sale\booking\Booking;

list($params, $providers) = announce([
    'description'   => "Generate the composition (hosts listing) for a given booking. If a composition already exists, it is reset.",
    'params'        => [
        'booking_id' =>  [
            'description'   => 'Identifier of the booking for which the composition has to be generated.',
            'type'          => 'integer',
            'min'           => 1,
            'required'      => true
        ],
        'data' => [
            'description'   => 'Raw data to be used for filling in the hosts details.',
            'type'          => 'array'
        ]
    ],
    'response'      => [
        'content-type'  => 'application/json',
        'charset'       => 'utf-8',
        'accept-origin' => '*'
    ],
    'providers'     => ['context', 'orm', 'auth']
]);


list($context, $orm, $auth) = [$providers['context'], $providers['orm'], $providers['auth']];

$user_id = $auth->userId();


// read groups and nb_pers from the targeted booking object, and subsequent lines (make sure user has access to it)
$booking = Booking::id($params['booking_id'])
                  ->read([
                        'booking_lines_groups_ids' => [
                            'nb_pers',
                            'booking_lines_ids' => [
                                'id', 'is_accomodation', 'rental_unit_id',
                                'rental_unit_assignments_ids' => ['qty', 'rental_unit_id']
                            ]
                        ]
                   ])
                   ->first();

if(!$booking) {
    throw new Exception('unknown_booking', QN_ERROR_INVALID_PARAM);
}


$auth->su();
    // remove any existing composition (and related composition items with cascade deletion)
    Composition::search(['booking_id', '=', $booking['id']])->delete(true);
    // create a new composition attached to current booking
    $composition_id = (Composition::create(['booking_id' => $booking['id']])->ids())[0];
    // update booking accordingly (o2o relation)
    Booking::id($booking['id'])->update(['composition_id' => $composition_id]);
$auth->su($user_id);


foreach($booking['booking_lines_groups_ids'] as $group) {
    $nb_pers = $group['nb_pers'];
    $remainder = $nb_pers;

    /*
        first pass : list all involved rental units on involved booking_lines.
        If a rental unit has children, we only add the children (not the UL itself)
    */

    $rental_units_map = [];
    foreach($group['booking_lines_ids'] as $line) {
        if($line['is_accomodation']) {
            foreach($line['rental_unit_assignments_ids'] as $assignment) {

                $rental_unit_id = $assignment['rental_unit_id'];
                $rental_unit = RentalUnit::id($rental_unit_id)->read(['capacity', 'has_children', 'children_ids'])->first();
                if($rental_unit) {
                    if($rental_unit['has_children'] && $rental_unit['capacity'] > 10) {
                        foreach($rental_unit['children_ids'] as $child_id) {
                            $rental_units_map[$child_id] = true;
                        }
                    }
                    else {
                        $rental_units_map[$rental_unit_id] = true;
                    }
                }
            }
        }
    }
    // get unique ids of involved rental units
    $rental_units_ids = array_keys($rental_units_map);
    // retrieve rental units capacities
    $rental_units = RentalUnit::ids($rental_units_ids)
                              ->read(['id', 'capacity'])
                              ->get();

    // sort rental units by ascending capacities
    usort($rental_units, function($a, $b) {
        return $a['capacity'] - $b['capacity'];
    });


    /*
        second pass : assign qty to rental units
    */

    $total_capacity = array_reduce($rental_units, function($total, $unit) {return $total + $unit['capacity'];});
    $last_index = count($rental_units) - 1;

    // to be used is data was received
    $item_index = 0;

    foreach($rental_units as $index => $unit) {
        // to each UL, assign ceil(nb_pers*cap/cap_total)
        if($index < $last_index) {
            $capacity = $unit['capacity'];
            $assigned = ceil($nb_pers*$capacity/$total_capacity);
            if($assigned > $remainder) {
                $assigned = $remainder;
            }
            $remainder -= $assigned;
        }
        //and assign the remainder to the last UL
        else {
            $assigned = $remainder;
        }
        for($i = 0; $i < $assigned; ++$i) {

            $item = [
                'composition_id' => $composition_id,
                'rental_unit_id' => $unit['id']
            ];

            if(isset($params['data']) && isset($params['data'][$item_index])) {
                $item = array_merge($item, $params['data'][$item_index]);
                ++$item_index;
            }

            CompositionItem::create($item);
        }
        if($remainder <= 0) break;
    }
}


$context->httpResponse()
        // ->status(204)
        ->status(200)
        ->body([])
        ->send();