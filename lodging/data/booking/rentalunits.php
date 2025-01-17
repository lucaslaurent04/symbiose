<?php
/*
    This file is part of Symbiose Community Edition <https://github.com/yesbabylon/symbiose>
    Some Rights Reserved, Yesbabylon SRL, 2020-2021
    Licensed under GNU AGPL 3 license <http://www.gnu.org/licenses/>
*/
use equal\orm\Domain;
use lodging\sale\booking\Consumption;
use lodging\sale\booking\Booking;
use lodging\realestate\RentalUnit;
use lodging\sale\booking\BookingLineGroup;
use lodging\sale\booking\SojournProductModel;
use lodging\sale\booking\SojournProductModelRentalUnitAssignement;


list($params, $providers) = announce([
    'description'   => "Retrieve the list of available rental units for a given sojourn, during a specific timerange.",
    'params'        => [
        'booking_line_group_id' =>  [
            'description'   => 'Specific sojourn for which is made the request.',
            'type'          => 'integer'
        ],
        'product_model_id' =>  [
            'description'   => 'Specific product model for which a matching rental unit list is requested.',
            'type'          => 'integer'
        ],
        'domain' =>  [
            'description'   => 'Dommain for additional filtering.',
            'type'          => 'array',
            'default'       => []
        ],
    ],
    'access' => [
        'groups'            => ['booking.default.user']
    ],
    'response'      => [
        'content-type'  => 'application/json',
        'charset'       => 'utf-8',
        'accept-origin' => '*'
    ],
    'providers'     => ['context', 'orm']
]);


list($context, $orm) = [$providers['context'], $providers['orm']];

$result = [];

// retrieve sojourn data
$sojourn = BookingLineGroup::id($params['booking_line_group_id'])
    ->read([
        'booking_id' => [
            'id',
            'center_id'
        ],
        'date_from',
        'date_to',
        'time_from',
        'time_to'
    ])
    ->first();

if($sojourn) {
    $date_from = $sojourn['date_from'] + $sojourn['time_from'];
    $date_to = $sojourn['date_to'] + $sojourn['time_to'];


    $booking_assigned_rental_units_ids = [];

    // look for existing SPMs defined within the targeted group, and:
    // * exclude targeted rental-units from the same SPM
    // * exclude fully assigned rental-units from other SPM
    $spms = SojournProductModel::search([
            ['booking_line_group_id', '=', $params['booking_line_group_id']]
        ])
        ->read(['product_model_id', 'rental_unit_assignments_ids' => ['qty', 'rental_unit_id' => ['id', 'capacity']]])
        ->get();

    foreach($spms as $spm) {
        if($spm['rental_unit_assignments_ids'] > 0) {
            if($spm['product_model_id'] == $params['product_model_id']) {
                foreach($spm['rental_unit_assignments_ids'] as $assignment) {
                    if(isset($assignment['rental_unit_id']) && $assignment['rental_unit_id']['id'] > 0) {
                        $booking_assigned_rental_units_ids[] = $assignment['rental_unit_id']['id'];
                    }
                }
            }
            else {
                foreach($spm['rental_unit_assignments_ids'] as $assignment) {
                    if(isset($assignment['rental_unit_id']) && $assignment['rental_unit_id']['capacity'] <= $assignment['qty']) {
                        $booking_assigned_rental_units_ids[] = $assignment['rental_unit_id']['id'];
                    }
                }
            }
        }
    }

    // retrieve rental units that are already assigned by other groups within same time range, if any (independantly from consumptions)
    // (we need to withdraw those from available units)
    $booking = Booking::id($sojourn['booking_id']['id'])->read(['booking_lines_groups_ids', 'rental_unit_assignments_ids'])->first();
    if($booking) {
        $groups = BookingLineGroup::ids($booking['booking_lines_groups_ids'])->read(['id', 'date_from', 'date_to', 'time_from', 'time_to'])->get();
        $assignments = SojournProductModelRentalUnitAssignement::ids($booking['rental_unit_assignments_ids'])->read(['rental_unit_id', 'booking_line_group_id'])->get();
        foreach($assignments as $oid => $assignment) {
            // process rental units from other groups
            if($assignment['booking_line_group_id'] != $params['booking_line_group_id']) {
                $group_id = $assignment['booking_line_group_id'];
                $group_date_from = $groups[$group_id]['date_from'] + $groups[$group_id]['time_from'];
                $group_date_to = $groups[$group_id]['date_to'] + $groups[$group_id]['time_to'];
                // if groups have a time range intersection, mark the rental unit as assigned
                if(max($date_from, $group_date_from) < min($date_to, $group_date_to)) {
                    $booking_assigned_rental_units_ids[] = $assignment['rental_unit_id'];
                }
            }
        }
    }

    /* remove parent and children units of assigned rental units */

    // fetch 2 levels of rental units identifiers
    for($i = 0; $i < 2; ++$i) {
        $units = RentalUnit::ids($booking_assigned_rental_units_ids)->read(['parent_id', 'children_ids', 'can_partial_rent'])->get();
        if($units > 0) {
            foreach($units as $uid => $unit) {
                if($unit['parent_id'] > 0) {
                    if(!in_array($unit['parent_id'], $booking_assigned_rental_units_ids)) {
                        $booking_assigned_rental_units_ids[] = $unit['parent_id'];
                    }
                }
                if(count($unit['children_ids'])) {
                    foreach($unit['children_ids'] as $uid) {
                        if(!in_array($uid, $booking_assigned_rental_units_ids)) {
                            $booking_assigned_rental_units_ids[] = $uid;
                        }
                    }
                }
            }
        }
    }


    // retrieve available rental units based on schedule and product_id
    $rental_units_ids = Consumption::getAvailableRentalUnits($orm, $sojourn['booking_id']['center_id'], $params['product_model_id'], $date_from, $date_to);

    // remove rental units from other groups of same booking
    $rental_units_ids = array_diff($rental_units_ids, $booking_assigned_rental_units_ids);

    // #memo - we cannot remove units already assigned in same group in other SPM, since the allocation of an accomodation might be split on several age ranges (ex: room for 5 pers. with 2 adults and 3 children)

    // #memo - we cannot append rental units from own booking consumptions :
    // It was first implemented to cover the use case: "come and go between 'draft' and 'option'", where units are already attached to consumptions
    // but this leads to an edge case: quote -> option -> quote (without releasing the consumptions)
    // 1) update nb_pers or time_from (list is not accurate and might return units that are not free)
    // 2) if another booking has booked the units in the meanwhile
    // In order to resolve that situation, user has to manually release the rental units (through action release-rentalunits.php)


    $rental_units = RentalUnit::ids($rental_units_ids)
        ->read(['id', 'name', 'capacity', 'order', 'is_accomodation'])
        ->adapt('txt')
        ->get(true);

    $domain = new Domain($params['domain']);

    // filter results
    foreach($rental_units as $index => $rental_unit) {
        if($domain->evaluate($rental_unit)) {
            $result[] = $rental_unit;
        }
    }
    usort($result, function($a, $b) {return $a['order'] > $b['order'];});
}

$context->httpResponse()
        ->body($result)
        ->send();