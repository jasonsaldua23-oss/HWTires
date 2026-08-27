<?php
/**
 * Quotation receipt PDF
 */

require_once __DIR__ . '/../includes/config.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$quotation_id = intval($_GET['id'] ?? 0);
if ($quotation_id <= 0) {
    http_response_code(400);
    die('Invalid quotation ID');
}

require_once __DIR__ . '/../includes/line-item-display.php';
require_once __DIR__ . '/../tcpdf/tcpdf.php';

if (!function_exists('quote_pdf_text')) {
    function quote_pdf_text($value) {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('quote_pdf_money')) {
    function quote_pdf_money($amount) {
        return '&#8369;' . number_format((float) $amount, 2);
    }
}

if (!function_exists('quote_pdf_date')) {
    function quote_pdf_date($date) {
        return !empty($date) ? date('F d, Y', strtotime($date)) : '-';
    }
}

if (!function_exists('quote_pdf_branch_label')) {
    function quote_pdf_branch_label($name) {
        return !empty($name) ? preg_replace('/\s*-\s*.*/', '', $name) : '-';
    }
}

if (!function_exists('quote_pdf_note_field')) {
    function quote_pdf_note_field($notes, $label) {
        $notes = (string) $notes;
        $label = preg_quote((string) $label, '/');

        if (preg_match('/(?:^|;\s*)' . $label . '\s*:\s*([^;]+)/i', $notes, $matches)) {
            return trim((string) $matches[1]);
        }

        return '';
    }
}

try {
    $settings = [
        'company_name' => 'Highway Tires',
        'system_title' => 'Branch Data Management System',
        'contact_email' => 'info@highwaytires.com',
        'contact_phone' => '(02) 8123-4567',
        'company_logo' => 'assets/images/logo.png',
        'primary_color' => '#06B6D4',
    ];

    try {
        $settings_rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
        foreach ($settings_rows as $row) {
            if (array_key_exists($row['setting_key'], $settings) && trim((string) $row['setting_value']) !== '') {
                $settings[$row['setting_key']] = (string) $row['setting_value'];
            }
        }
    } catch (Exception $settings_error) {
        error_log('Quotation PDF settings load error: ' . $settings_error->getMessage());
    }

    $stmt = $pdo->prepare("
        SELECT q.*, c.name AS customer_name, c.email AS customer_email, c.phone_mobile, c.contact, c.address,
               v.make, v.model, v.year, v.color, v.vin, v.plate_number,
               u.name AS created_by_name, b.name AS branch_name, b.location AS branch_location, b.contact_number AS branch_contact,
               jo.assigned_technician_name
        FROM quotations q
        LEFT JOIN customers c ON q.customer_id = c.id
        LEFT JOIN vehicles v ON q.vehicle_id = v.id
        LEFT JOIN users u ON q.created_by = u.id
        LEFT JOIN branches b ON q.branch_id = b.id
        LEFT JOIN (
            SELECT quotation_id, MAX(assigned_technician_name) AS assigned_technician_name
            FROM job_orders
            WHERE quotation_id IS NOT NULL
            GROUP BY quotation_id
        ) jo ON jo.quotation_id = q.id
        WHERE q.id = ?
    ");
    $stmt->execute([$quotation_id]);
    $quotation = $stmt->fetch();

    if (!$quotation) {
        http_response_code(404);
        die('Quotation not found');
    }

    $items_stmt = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY id ASC");
    $items_stmt->execute([$quotation_id]);
    $items = $items_stmt->fetchAll();
    $inventory_items_by_id = app_line_item_load_inventory_items($pdo, $items);

    $items_total = 0;
    foreach ($items as $item) {
        if (app_line_item_is_service($item)) {
            continue;
        }

        $items_total += max(1, (int) ($item['quantity'] ?? 1)) * (float) ($item['unit_price'] ?? 0);
    }
    $labor_cost = (float) ($quotation['labor_cost'] ?? 0);
    $total_amount = $labor_cost + $items_total;

    $notes_text = trim((string) ($quotation['notes'] ?? ''));
    $formatted_notes_text = function_exists('app_format_record_notes') ? app_format_record_notes($notes_text) : $notes_text;
    $sales_in_charge = quote_pdf_note_field($notes_text, 'Sales in charge') ?: ($quotation['created_by_name'] ?? '-');
    $technician_names = trim((string) ($quotation['assigned_technician_name'] ?? ''));

    if ($technician_names === '') {
        $technician_names = quote_pdf_note_field($notes_text, 'Technician assigned') ?: quote_pdf_note_field($notes_text, 'Technician');
    }

    if ($technician_names === '') {
        $technician_names = '-';
    }

    $receipt_height = 154;
    if (empty($items)) {
        $receipt_height += 12;
    } else {
        foreach ($items as $item) {
            $name_length = strlen((string) ($item['item_name'] ?? ''));
            $detail_length = strlen(app_line_item_detail_text($item, $inventory_items_by_id, ['include_type' => true]));
            $name_length += $detail_length;
            $receipt_height += 11 + (max(0, (int) ceil($name_length / 30) - 1) * 4);
            if ($detail_length > 0) {
                $receipt_height += 4;
            }
        }
    }
    if ($labor_cost > 0) {
        $receipt_height += 8;
    }
    $receipt_height += 10;
    if ($formatted_notes_text !== '') {
        $receipt_height += 16 + ((int) ceil(strlen($formatted_notes_text) / 34) * 4);
    }
    $receipt_height = max(190, $receipt_height);

    $pdf = new TCPDF('P', 'mm', [80, $receipt_height], true, 'UTF-8', false);
    $pdf->SetCreator($settings['company_name']);
    $pdf->SetAuthor($settings['company_name']);
    $pdf->SetTitle('Service Operation ' . ($quotation['quotation_number'] ?? $quotation_id));
    $pdf->SetSubject('Service Operation Receipt');
    $pdf->SetMargins(5, 5, 5);
    $pdf->SetAutoPageBreak(false, 0);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 8);

    $primary_color = preg_match('/^#[0-9A-Fa-f]{6}$/', $settings['primary_color'])
        ? strtoupper($settings['primary_color'])
        : '#06B6D4';

    $customer_phone = ($quotation['phone_mobile'] ?? '') ?: (($quotation['contact'] ?? '') ?: '-');
    $vehicle_name = trim(($quotation['make'] ?? '') . ' ' . ($quotation['model'] ?? ''));
    $vehicle_name = $vehicle_name !== '' ? $vehicle_name : 'Vehicle';
    $status = ucfirst((string) ($quotation['status'] ?? 'pending'));
    $branch_label = quote_pdf_branch_label($quotation['branch_name'] ?? '');
    $receipt_number = $quotation['quotation_number'] ?? ('Q-' . $quotation_id);

    $rows_html = '';
    if (empty($items)) {
        $rows_html = '<tr><td colspan="2" style="color:#64748b;padding:5px 0;text-align:center;">No services or items listed.</td></tr>';
    } else {
        foreach ($items as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unit_price = (float) ($item['unit_price'] ?? 0);
            $line_total = $quantity * $unit_price;
            $is_service_line = app_line_item_is_service($item);
            $line_amount = $is_service_line ? 'Included in labor' : quote_pdf_money($line_total);
            $type_label = app_line_item_type_label($item);
            $detail_text = app_line_item_detail_text($item, $inventory_items_by_id, ['include_type' => true]);
            $detail_html = $detail_text !== ''
                ? '<br><span style="font-weight:normal;color:#64748B;font-size:7px;">' . quote_pdf_text($detail_text) . '</span>'
                : '';
            $rows_html .= '
                <tr>
                    <td colspan="2" style="padding-top:4px;font-weight:bold;">' . quote_pdf_text($item['item_name'] ?? '-') . $detail_html . '</td>
                </tr>
                <tr>
                    <td style="padding-bottom:4px;border-bottom:1px dashed #CBD5E1;color:#475569;">
                        ' . quote_pdf_text($type_label) . ' - ' . $quantity . ' x ' . quote_pdf_money($unit_price) . '
                    </td>
                    <td align="right" style="padding-bottom:4px;border-bottom:1px dashed #CBD5E1;font-weight:bold;">
                        ' . $line_amount . '
                    </td>
                </tr>
            ';
        }
    }

    $notes_html = '';
    if ($formatted_notes_text !== '') {
        $notes_html = '
            <div class="dash"></div>
            <div class="section-title">Notes</div>
            <div style="font-style:italic;color:#334155;">' . nl2br(quote_pdf_text($formatted_notes_text)) . '</div>
        ';
    }

    $html = '
        <style>
            body { color:#00183A; font-size:8px; }
            h1, h2, h3, p { margin:0; padding:0; }
            .muted { color:#53627A; }
            .center { text-align:center; }
            .company { font-size:15px; font-weight:bold; color:#00183A; }
            .title { font-size:10px; font-weight:bold; letter-spacing:0; }
            .dash { border-top:1px dashed #94A3B8; height:5px; margin-top:5px; }
            .section-title { font-weight:bold; color:#00183A; margin-top:3px; }
            .receipt-box { background:#E8FDFF; border:1px solid #BAF3FB; padding:5px; }
            table { border-collapse:collapse; }
        </style>

        <div class="center">
            <div class="company">' . quote_pdf_text($settings['company_name']) . '</div>
            <div class="muted">' . quote_pdf_text($settings['system_title']) . '</div>
            <div class="muted">' . quote_pdf_text($settings['contact_phone']) . '</div>
            <div class="muted">' . quote_pdf_text($settings['contact_email']) . '</div>
            <div class="muted">' . quote_pdf_text($branch_label) . '</div>
            <div class="dash"></div>
            <div class="title">SERVICE OPERATION RECEIPT</div>
            <div class="dash"></div>
        </div>

        <table width="100%" cellpadding="1" cellspacing="0">
            <tr>
                <td width="34%">Receipt No.</td>
                <td width="66%" align="right"><strong>' . quote_pdf_text($receipt_number) . '</strong></td>
            </tr>
            <tr>
                <td>Date</td>
                <td align="right">' . quote_pdf_text(quote_pdf_date($quotation['quotation_date'] ?? '')) . '</td>
            </tr>
            <tr>
                <td>Status</td>
                <td align="right"><strong>' . quote_pdf_text($status) . '</strong></td>
            </tr>
            <tr>
                <td>Printed</td>
                <td align="right">' . date('Y-m-d h:i A') . '</td>
            </tr>
        </table>

        <div class="dash"></div>

        <div class="receipt-box">
            <table width="100%" cellpadding="1" cellspacing="0">
                <tr>
                    <td width="38%" class="muted">Customer</td>
                    <td width="62%" align="right"><strong>' . quote_pdf_text($quotation['customer_name'] ?? '-') . '</strong></td>
                </tr>
                <tr>
                    <td class="muted">Contact</td>
                    <td align="right">' . quote_pdf_text($customer_phone) . '</td>
                </tr>
                <tr>
                    <td class="muted">Vehicle</td>
                    <td align="right"><strong>' . quote_pdf_text($vehicle_name) . '</strong></td>
                </tr>
                <tr>
                    <td class="muted">Plate</td>
                    <td align="right">' . quote_pdf_text($quotation['plate_number'] ?? '-') . '</td>
                </tr>
            </table>
        </div>

        <div class="dash"></div>
        <div class="section-title">Services / Items</div>

        <table width="100%" cellpadding="1" cellspacing="0">
            ' . $rows_html . '
        </table>

        <div class="dash"></div>

        <table width="100%" cellpadding="2" cellspacing="0">
            <tr>
                <td>Items Subtotal</td>
                <td align="right">' . quote_pdf_money($items_total) . '</td>
            </tr>
            <tr>
                <td>Labor</td>
                <td align="right">' . quote_pdf_money($labor_cost) . '</td>
            </tr>
            <tr>
                <td style="border-top:1px dashed #94A3B8;font-size:11px;font-weight:bold;padding-top:5px;">TOTAL</td>
                <td align="right" style="border-top:1px dashed #94A3B8;color:' . $primary_color . ';font-size:12px;font-weight:bold;padding-top:5px;">' . quote_pdf_money($total_amount) . '</td>
            </tr>
        </table>

        ' . $notes_html . '

        <div class="dash"></div>

        <table width="100%" cellpadding="1" cellspacing="0">
            <tr>
                <td width="38%" class="muted">Prepared By</td>
                <td width="62%" align="right">' . quote_pdf_text($quotation['created_by_name'] ?? '-') . '</td>
            </tr>
            <tr>
                <td class="muted">Sales In Charge</td>
                <td align="right">' . quote_pdf_text($sales_in_charge) . '</td>
            </tr>
            <tr>
                <td class="muted">Technician(s)</td>
                <td align="right">' . quote_pdf_text($technician_names) . '</td>
            </tr>
            <tr>
                <td class="muted">Branch</td>
                <td align="right">' . quote_pdf_text($branch_label) . '</td>
            </tr>
        </table>

        <br>
        <br>

        <table width="100%" cellpadding="1" cellspacing="0">
            <tr>
                <td style="border-top:1px solid #94A3B8;text-align:center;padding-top:3px;">Customer Signature</td>
            </tr>
        </table>

        <div class="dash"></div>
        <div class="center muted">
            This is a service operation summary, not an official receipt.<br>
            Thank you.
        </div>
    ';

    $pdf->writeHTML($html, true, false, true, false, '');
    $file_name = 'service-operation-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($quotation['quotation_number'] ?? $quotation_id)) . '.pdf';
    $pdf->Output($file_name, 'I');
} catch (Exception $e) {
    error_log('Quotation PDF error: ' . $e->getMessage());
    http_response_code(500);
    die('Unable to generate quotation PDF');
}
?>
