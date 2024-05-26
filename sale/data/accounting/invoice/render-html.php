<?php
/*
    This file is part of Symbiose Community Edition <https://github.com/yesbabylon/symbiose>
    Some Rights Reserved, Yesbabylon SRL, 2020-2024
    Licensed under GNU AGPL 3 license <http://www.gnu.org/licenses/>
*/

use core\setting\Setting;
use equal\data\DataFormatter;
use sale\accounting\invoice\Invoice;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader as TwigFilesystemLoader;
use Twig\Extra\Intl\IntlExtension;
use Twig\Extension\ExtensionInterface;

list($params, $providers) = eQual::announce([
    'description'   => 'Generate an html view of given invoice.',
    'params'        => [
        'id' => [
            'description' => 'Identifier of the targeted invoice.',
            'type'        => 'integer',
            'min'         => 1,
            'required'    => true
        ],

        'mode' => [
            'description' => 'Mode in which document has to be rendered: grouped (default) or detailed.',
            'help'        => 'Modes: "simple" displays all lines without groups, "detailed" displays all lines by group and "grouped" displays only groups by vat rate.',
            'type'        => 'string',
            'selection'   => ['simple', 'grouped', 'detailed'],
            'default'     => 'simple'
        ],

        'debug' => [
            'type'        => 'boolean',
            'default'     => false
        ],

        'view_id' => [
            'description' => 'View id of the template to use.',
            'type'        => 'string',
            'default'     => 'print.default'
        ],

        'lang' =>  [
            'description' => 'Language in which labels and multilang field have to be returned (2 letters ISO 639-1).',
            'type'        => 'string',
            'default'     => constant('DEFAULT_LANG')
        ]
    ],
    'access'        => [
        'visibility' => 'protected',
        'groups'     => ['sale.default.users'],
    ],
    'response'      => [
        'content-type'  => 'text/html',
        'charset'       => 'utf-8',
        'accept-origin' => '*'
    ],
    'providers'     => ['context'],
    'constants'     => ['L10N_TIMEZONE', 'L10N_LOCALE']
]);

/** @var \equal\php\Context $context */
$context = $providers['context'];

$generateInvoiceLines = function($invoice, $mode) {
    $lines = [];

    foreach($invoice['invoice_line_groups_ids'] as $group) {
        if(count($group['invoice_lines_ids']) <= 0) {
            continue;
        }

        if($mode !== 'simple') {
            $lines[] = [
                'name'       => $group['name'] ?? '',
                'price'      => null,
                'total'      => null,
                'unit_price' => null,
                'vat_rate'   => null,
                'qty'        => null,
                'free_qty'   => null,
                'is_group'   => true
            ];
        }

        $group_lines = [];
        foreach($group['invoice_lines_ids'] as $line) {
            foreach($invoice['invoice_lines_ids'] as $i => $l) {
                if($l['id'] === $line['id']) {
                    unset($invoice['invoice_lines_ids'][$i]);
                    break;
                }
            }

            $group_lines[] = [
                'name'       => (strlen($line['description']) > 0) ? $line['description'] : $line['name'],
                'price'      => round(($invoice['invoice_type'] == 'credit_note') ? (-$line['price']) : $line['price'], 2),
                'total'      => round(($invoice['invoice_type'] == 'credit_note') ? (-$line['total']) : $line['total'], 2),
                'unit_price' => $line['unit_price'],
                'vat_rate'   => $line['vat_rate'],
                'qty'        => $line['qty'],
                'discount'   => $line['discount'],
                'free_qty'   => $line['free_qty'],
                'is_group'   => false
            ];
        }

        switch($mode) {
            case 'simple':
            case 'detailed':
                $lines = array_merge($lines, $group_lines);
                break;
            case 'grouped':
                $group_tax_lines = [];

                foreach($group_lines as $line) {
                    $vat_rate = strval(round($line['vat_rate'], 2));

                    if(!isset($group_tax_lines[$vat_rate])) {
                        $group_tax_lines[$vat_rate] = [];
                    }

                    $group_tax_lines[$vat_rate][] = $line;
                }

                $nb_taxes = count(array_keys($group_tax_lines));
                if($nb_taxes == 1) {
                    $pos = count($lines) - 1;
                    foreach($group_tax_lines as $vat_rate => $tax_lines) {
                        $lines[$pos]['qty'] = 1;
                        $lines[$pos]['vat_rate'] = $vat_rate;
                        foreach($tax_lines as $tax_line) {
                            $lines[$pos]['total'] += $tax_line['total'];
                            $lines[$pos]['price'] += $tax_line['price'];
                        }
                    }
                }
                elseif($nb_taxes > 1) {
                    foreach($group_tax_lines as $vat_rate => $tax_lines) {
                        $lines[] = [
                            'name'     => 'VAT '.($vat_rate * 100).'%',
                            'qty'      => 1,
                            'vat_rate' => $vat_rate,
                            'price'    => round(array_sum(array_column($tax_lines, 'price')), 2),
                            'total'    => round(array_sum(array_column($tax_lines, 'total')), 2)
                        ];
                    }
                }
                break;
        }
    }

    foreach($invoice['invoice_lines_ids'] as $line) {
        $lines[] = [
            'name'       => (strlen($line['description']) > 0) ? $line['description'] : $line['name'],
            'price'      => round(($invoice['invoice_type'] == 'credit_note') ? (-$line['price']) : $line['price'], 2),
            'total'      => round(($invoice['invoice_type'] == 'credit_note') ? (-$line['total']) : $line['total'], 2),
            'unit_price' => $line['unit_price'],
            'vat_rate'   => $line['vat_rate'],
            'qty'        => $line['qty'],
            'discount'   => $line['discount'],
            'free_qty'   => $line['free_qty'],
            'is_group'   => false
        ];
    }

    return $lines;
};

$getTwigCurrency = function($equal_currency) {
    $equal_twig_currency_map = [
        '€'   => 'EUR',
        '£'   => 'GBP',
        'CHF' => 'CHF',
        '$'   => 'USD'
    ];

    return $equal_twig_currency_map[$equal_currency] ?? $equal_currency;
};

$getOrganisationLogo = function($invoice) {
    $result = '';
    try {
        if(!isset($invoice['organisation_id']['image_document_id']['type'], $invoice['organisation_id']['image_document_id']['data'])) {
            throw new Exception('invalid_image', EQ_ERROR_INVALID_PARAM);
        }
        if(stripos($invoice['organisation_id']['image_document_id']['type'], 'image/') !== 0) {
            throw new Exception('invalid_image_type', EQ_ERROR_INVALID_PARAM);
        }
        if(strlen( $invoice['organisation_id']['image_document_id']['data']) <= 0) {
            throw new Exception('empty_image', EQ_ERROR_INVALID_PARAM);
        }
        $result = sprintf('data:%s;base64,%s',
            $invoice['organisation_id']['image_document_id']['type'],
            base64_encode($invoice['organisation_id']['image_document_id']['data'])
        );
    }
    catch(Exception $e) {
        // ignore
    }
    return $result;
};

$getLabels = function($lang) {
    return [
        'invoice'                        => Setting::get_value('sale', 'locale', 'label_invoice', 'Invoice', 0, $lang),
        'credit_note'                    => Setting::get_value('sale', 'locale', 'label_credit-note', 'Credit note', 0, $lang),
        'customer_name'                  => Setting::get_value('sale', 'locale', 'label_customer-name', 'Name', 0, $lang),
        'customer_address'               => Setting::get_value('sale', 'locale', 'label_customer-address', 'Address', 0, $lang),
        'registration_number'            => Setting::get_value('sale', 'locale', 'label_registration-number', 'Registration n°', 0, $lang),
        'vat_number'                     => Setting::get_value('sale', 'locale', 'label_vat-number', 'VAT n°', 0, $lang),
        'number'                         => Setting::get_value('sale', 'locale', 'label_number', 'N°', 0, $lang),
        'date'                           => Setting::get_value('sale', 'locale', 'label_date', 'Date', 0, $lang),
        'status'                         => Setting::get_value('sale', 'locale', 'label_status', 'Status', 0, $lang),
        'status_paid'                    => Setting::get_value('sale', 'locale', 'label_status-paid', 'Paid', 0, $lang),
        'status_to_pay'                  => Setting::get_value('sale', 'locale', 'label_status-to-pay', 'To pay', 0, $lang),
        'status_to_refund'               => Setting::get_value('sale', 'locale', 'label_status-to-refund', 'To refund', 0, $lang),
        'total_ex_vat'                   => Setting::get_value('sale', 'locale', 'label_total-ex-vat', 'Total ex. VAT', 0, $lang),
        'total_inc_vat'                  => Setting::get_value('sale', 'locale', 'label_total-inc-vat', 'Total inc. VAT', 0, $lang),
        'balance_of_must_be_paid_before' => Setting::get_value('sale', 'locale', 'label_balance-of-must-be-paid-before', 'Balance of %price% to be paid before %due_date%', 0, $lang),
        'communication'                  => Setting::get_value('sale', 'locale', 'label_communication', 'Communication', 0, $lang),
        'columns' => [
            'product'                    => Setting::get_value('sale', 'locale', 'label_product-column', 'Product label', 0, $lang),
            'qty'                        => Setting::get_value('sale', 'locale', 'label_qty-column', 'Qty', 0, $lang),
            'free'                       => Setting::get_value('sale', 'locale', 'label_free-column', 'Free', 0, $lang),
            'unit_price'                 => Setting::get_value('sale', 'locale', 'label_unit-price-column', 'U. price', 0, $lang),
            'discount'                   => Setting::get_value('sale', 'locale', 'label_discount-column', 'Disc.', 0, $lang),
            'vat'                        => Setting::get_value('sale', 'locale', 'label_vat-column', 'VAT', 0, $lang),
            'taxes'                      => Setting::get_value('sale', 'locale', 'label_taxes-column', 'Taxes', 0, $lang),
            'price_ex_vat'               => Setting::get_value('sale', 'locale', 'label_price-ex-vat-column', 'Price ex. VAT', 0, $lang),
            'price'                      => Setting::get_value('sale', 'locale', 'label_price-column', 'Price', 0, $lang)
        ],
        'footer' => [
            'registration_number'        => Setting::get_value('sale', 'locale', 'label_footer-registration-number', 'Registration number', 0, $lang),
            'iban'                       => Setting::get_value('sale', 'locale', 'label_footer-iban', 'IBAN', 0, $lang),
            'email'                      => Setting::get_value('sale', 'locale', 'label_footer-email', 'Email', 0, $lang),
            'web'                        => Setting::get_value('sale', 'locale', 'label_footer-web', 'Web', 0, $lang),
            'tel'                        => Setting::get_value('sale', 'locale', 'label_footer-tel', 'Tel', 0, $lang),
            'fax'                        => Setting::get_value('sale', 'locale', 'label_footer-fax', 'Fax', 0, $lang),
        ]
    ];
};

/* #memo - empty 1x1 data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAoMBgDTD2qgAAAAASUVORK5CYII= */
$createInvoicePaymentQrCodeUri = function($invoice) {
    $result = '';
    try {
        if(!isset($invoice['payment_reference'])) {
            throw new Exception('missing_payment_reference', EQ_ERROR_INVALID_PARAM);
        }
        $payment_reference = DataFormatter::format($invoice['payment_reference'], 'scor');
        $result = eQual::run('get', 'finance_payment_generate-qr-code', [
            'recipient_name'    => $invoice['organisation_id']['legal_name'],
            'recipient_iban'    => $invoice['organisation_id']['bank_account_iban'],
            'recipient_bic'     => $invoice['organisation_id']['bank_account_bic'],
            'payment_reference' => $payment_reference,
            'payment_amount'    => $invoice['price']
        ]);
    }
    catch(Exception $e) {
        // ignore
    }
    return $result;
};

$formatInvoice = function(&$invoice) {
    $invoice['payment_reference'] = DataFormatter::format($invoice['payment_reference'], 'scor');
    $invoice['organisation_id']['bank_account_iban'] = DataFormatter::format($invoice['organisation_id']['bank_account_iban'], 'iban');
    $invoice['organisation_id']['phone'] = DataFormatter::format($invoice['organisation_id']['phone'], 'phone');
    $invoice['organisation_id']['fax'] = DataFormatter::format($invoice['organisation_id']['fax'], 'phone');
};

$invoice = Invoice::id($params['id'])
    ->read([
        'invoice_number', 'date', 'due_date', 'status', 'invoice_type', 'payment_reference', 'total', 'price', 'payment_status',
        'organisation_id' => [
            'name', 'address_street', 'address_dispatch', 'address_zip',
            'address_city', 'address_country', 'has_vat', 'vat_number',
            'legal_name', 'registration_number', 'bank_account_iban', 'bank_account_bic',
            'website', 'email', 'phone', 'fax', 'has_vat', 'vat_number',
            'image_document_id' => [
                'type', 'data'
            ]
        ],
        'customer_id' => [
            'name', 'address_street', 'address_dispatch', 'address_zip',
            'address_city', 'address_country', 'has_vat', 'vat_number'
        ],
        'invoice_lines_ids' => [
            'name', 'product_id', 'description', 'qty', 'unit_price',
            'discount', 'free_qty', 'vat_rate', 'total', 'price'
        ],
        'invoice_line_groups_ids' => [
            'name',
            'invoice_lines_ids' => [
                'name', 'product_id', 'description', 'qty', 'unit_price',
                'discount', 'free_qty', 'vat_rate', 'total', 'price'
            ]
        ]
    ], $params['lang'])
    ->first(true);


if(empty($invoice)) {
    throw new Exception('invoice_unknown', EQ_ERROR_UNKNOWN_OBJECT);
}

// adapt specific properties to TXT output
$formatInvoice($invoice);

$loader = new TwigFilesystemLoader(EQ_BASEDIR.'/packages/sale/views/accounting/invoice');
$twig = new TwigEnvironment($loader);

/** @var ExtensionInterface $extension **/
$extension  = new IntlExtension();
$twig->addExtension($extension);

$template = $twig->load('invoice.'.$params['view_id'].'.html');


$html = $template->render([
        'invoice'             => $invoice,
        'organisation'        => $invoice['organisation_id'],
        'customer'            => $invoice['customer_id'],
        'lines'               => $generateInvoiceLines($invoice, $params['mode']),
        'organisation_logo'   => $getOrganisationLogo($invoice),
        'payment_qr_code_uri' => $createInvoicePaymentQrCodeUri($invoice),
        'timezone'            => constant('L10N_TIMEZONE'),
        'locale'              => constant('L10N_LOCALE'),
        'date_format'         => Setting::get_value('core', 'locale', 'date_format', 'm/d/Y'),
        'currency'            => $getTwigCurrency(Setting::get_value('core', 'units', 'currency', '€')),
        'labels'              => $getLabels($params['lang']),
        'debug'               => $params['debug']
    ]);

$context->httpResponse()
    ->body($html)
    ->send();