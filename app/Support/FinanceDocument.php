<?php

namespace App\Support;

use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolBankAccount;
use App\Models\Term;

/**
 * Renders the print-ready HTML shared by invoices and receipts, laid out
 * like a school's own letter-headed fee invoice: centred school name with
 * address/email/phone, a two-column details block, a DESCRIPTION / AMOUNT
 * table, the school's bank accounts, its numbered NOTE terms, and a
 * "<SCHOOL> MANAGEMENT" sign-off, with the logo above the name and as a
 * faint watermark. Schools without a logo get a generated initial badge,
 * the same fallback the result sheets use.
 */
class FinanceDocument
{
    /**
     * @param array<int, array{0:string,1:string}> $leftDetails  [label, value] pairs
     * @param array<int, array{0:string,1:string}> $rightDetails [label, value] pairs
     * @param array<int, array{description:string, amount:?float, muted?:bool}> $rows
     * $heading is an optional title line under the letterhead (e.g. "Receipt");
     * invoices leave it out, matching the school's own template.
     *
     * @param array<int, array{0:string,1:float,2?:bool}> $totals [label, amount, bold?] — the last one is bold unless flagged otherwise
     */
    public static function render(
        ?School $school,
        string $title,
        array $leftDetails,
        array $rightDetails,
        array $rows,
        array $totals,
        array $extraNotes = [],
        ?string $stamp = null,
        ?string $heading = null,
    ): string {
        $schoolName  = e(strtoupper($school?->name ?? 'School'));
        $contactBits = array_filter([
            e($school?->address ?? ''),
            $school?->email ? 'Email: <a href="mailto:' . e($school->email) . '">' . e($school->email) . '</a>' : '',
            $school?->phone ? 'Tel: ' . e($school->phone) : '',
        ]);
        $contact = implode(' &nbsp;||&nbsp; ', $contactBits);

        $logoSrc   = e(self::logoSrc($school));
        $logo      = '<img class="logo" src="' . $logoSrc . '" alt="">';
        $watermark = '<img class="watermark" src="' . $logoSrc . '" alt="">';

        $detailCol = function (array $pairs): string {
            $html = '';
            foreach ($pairs as $i => [$label, $value]) {
                $cls = $i === 0 ? ' class="lead"' : '';
                $html .= '<div' . $cls . '>' . e(strtoupper($label)) . ': ' . e(strtoupper($value)) . '</div>';
            }
            return $html;
        };

        $rowHtml = '';
        foreach ($rows as $row) {
            $amount = $row['amount'] === null ? '' : self::money($row['amount']);
            $desc   = e(strtoupper($row['description']));
            $rowHtml .= ! empty($row['muted'])
                ? "<tr><td class=\"muted\">{$desc}</td><td class=\"amt\"></td></tr>"
                : "<tr><td>{$desc}</td><td class=\"amt\">{$amount}</td></tr>";
        }
        $lastTotal = count($totals) - 1;
        foreach ($totals as $i => $t) {
            [$label, $value] = $t;
            $cls = ($t[2] ?? $i === $lastTotal) ? ' class="total"' : ' class="subtotal"';
            $rowHtml .= "<tr{$cls}><td>" . e(strtoupper($label)) . '</td><td class="amt">' . self::money($value) . '</td></tr>';
        }

        $bankHtml = '';
        foreach (self::bankAccounts($school) as $acc) {
            $heading = $acc->notes
                ? 'BANK ACCOUNT ' . (preg_match('/^for\b/i', $acc->notes) ? '' : 'FOR ') . strtoupper($acc->notes)
                : 'BANK ACCOUNT';
            $bankHtml .= '<div class="bank"><strong>' . e($heading) . '</strong><br>'
                . e(strtoupper($acc->account_name)) . ', ' . e($acc->account_number) . ',<br>'
                . e(strtoupper($acc->bank_name)) . '</div>';
        }
        if ($bankHtml) {
            $bankHtml = '<div class="banks">' . $bankHtml . '</div>';
        }

        $terms = array_merge(self::terms($school), array_values(array_filter(array_map('trim', $extraNotes))));
        $notesHtml = '';
        if ($terms) {
            $notesHtml = '<div class="notes"><h4>NOTE</h4><ol>';
            foreach ($terms as $t) {
                $notesHtml .= '<li>' . e($t) . '</li>';
            }
            $notesHtml .= '</ol></div>';
        }

        $stampHtml = $stamp ? '<div class="stamp">' . e($stamp) . '</div>' : '';
        $docTitle  = e($title);
        $headHtml  = $heading ? '<div class="doc-title">' . e(strtoupper($heading)) . '</div>' : '';

        return SchoolColor::apply(<<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{$docTitle}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Georgia, 'Times New Roman', serif; font-size: 13px; color: #111; padding: 28px 36px; position: relative; }
  .watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 60%; max-width: 480px; opacity: .08; z-index: -1; }
  .head { text-align: center; margin-bottom: 26px; }
  .head .logo { height: 64px; max-width: 180px; object-fit: contain; margin-bottom: 6px; }
  .head h1 { font-size: 20px; font-weight: bold; letter-spacing: .5px; }
  .head p { font-size: 13px; margin-top: 4px; }
  .head a { color: #1a56db; }
  .doc-title { text-align: center; font-weight: bold; letter-spacing: 2px; margin: -12px 0 18px; font-size: 14px; }
  .details { display: flex; justify-content: space-between; gap: 24px; margin-bottom: 22px; }
  .details > div { flex: 1; }
  .details > div:last-child { flex: 0 0 38%; }
  .details div div { margin-bottom: 6px; }
  .details .lead { font-weight: bold; }
  table { width: 100%; border-collapse: collapse; border: 2px solid #111; margin-bottom: 26px; }
  th { background: #7030a0; color: #fff; text-align: left; padding: 4px 10px; font-size: 13px; }
  th.amt { text-align: center; border-left: 2px solid #111; }
  td { padding: 4px 10px; vertical-align: top; }
  td.amt { text-align: center; border-left: 2px solid #111; width: 32%; }
  td.muted { font-style: italic; font-size: 10px; letter-spacing: .3px; }
  tr.subtotal td { padding-top: 6px; }
  tr.total td { font-weight: bold; padding-top: 10px; padding-bottom: 6px; }
  .banks { display: flex; gap: 40px; margin-bottom: 22px; }
  .bank { flex: 1; line-height: 1.3; }
  .notes h4 { margin-bottom: 8px; }
  .notes ol { padding-left: 24px; }
  .notes li { margin-bottom: 2px; line-height: 1.35; }
  .signoff { margin-top: 26px; }
  .signoff p + p { margin-top: 34px; }
  .stamp { display: inline-block; border: 3px solid #16a34a; color: #16a34a; font-weight: bold; letter-spacing: 3px; padding: 4px 14px; transform: rotate(-8deg); margin-bottom: 14px; }
  @media print { body { padding: 0; } @page { margin: 1.4cm; } a { color: inherit; } }
</style>
</head>
<body>
{$watermark}
<div class="head">
  {$logo}
  <h1>{$schoolName}</h1>
  <p>{$contact}</p>
</div>
{$headHtml}
{$stampHtml}
<div class="details">
  <div>{$detailCol($leftDetails)}</div>
  <div>{$detailCol($rightDetails)}</div>
</div>

<table>
  <thead><tr><th>DESCRIPTION</th><th class="amt">AMOUNT</th></tr></thead>
  <tbody>{$rowHtml}</tbody>
</table>

{$bankHtml}
{$notesHtml}

<div class="signoff">
  <p>Thank you.</p>
  <p>{$schoolName} MANAGEMENT</p>
</div>

<script>window.onload = function() { window.print(); }</script>
</body>
</html>
HTML);
    }

    public static function money(float $amount): string
    {
        $abs = abs($amount);

        return ($amount < 0 ? '-' : '') . '₦' . number_format($abs, $abs == floor($abs) ? 0 : 2);
    }

    /**
     * The school's numbered NOTE terms, one per line in settings.invoice_terms.
     *
     * @return string[]
     */
    public static function terms(?School $school): array
    {
        $raw = $school?->settings['invoice_terms'] ?? '';

        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $raw))));
    }

    /**
     * The school's uploaded logo as a URL, or a generated badge with the
     * school's initial (navy circle, as on result sheets) as an SVG data URI.
     */
    public static function logoSrc(?School $school): string
    {
        $path = $school?->logo;
        if ($path) {
            return str_starts_with($path, 'http') || str_starts_with($path, 'data:')
                ? $path
                : TenantUrl::forPublicDiskKey($path);
        }

        $initial = htmlspecialchars(mb_strtoupper(mb_substr(trim($school?->name ?? 'S'), 0, 1)), ENT_XML1);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<circle cx="50" cy="50" r="48" fill="#1a3a6b"/>'
            . '<text x="50" y="50" dy=".35em" text-anchor="middle" font-family="Arial, sans-serif" font-size="46" font-weight="bold" fill="#fff">'
            . $initial . '</text></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /** Current session/term names, for documents not tied to a specific fee. */
    public static function currentSession(?School $school): array
    {
        $year = AcademicYear::where('is_current', true)->first();
        $term = Term::where('is_current', true)->first();

        return [
            $year?->name ?? ($school?->academic_year ?? ''),
            $term?->name ?? ($school?->term ?? ''),
        ];
    }

    private static function bankAccounts(?School $school)
    {
        if (! $school?->id) {
            return collect();
        }

        return SchoolBankAccount::where('school_id', $school->id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();
    }
}
