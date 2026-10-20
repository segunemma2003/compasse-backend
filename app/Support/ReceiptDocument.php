<?php

namespace App\Support;

use App\Models\School;

/**
 * The payment receipt, laid out like a school's printed receipt book: logo and contacts at the
 * top, the receipt number and date, a coloured RECEIPT band, then "Received from", "The sum of
 * (in words)", "Being payment for", Deposit / Balance, the amount in naira and kobo boxes, and the
 * cashier's signature. Tables only (no flexbox) so dompdf draws the PDF the same as the browser.
 */
class ReceiptDocument
{
    public const DEFAULT_COLOR = '#5b2a6e';

    /**
     * @param array{number:string, date:string, received_from:string, words:string, payment_for:string,
     *              deposit:float, balance:?float, amount:float, cashier:?string, method:?string,
     *              reference:?string, paid_into:?string, stamp:?string, notes:?string} $r
     */
    public static function render(?School $school, array $r): string
    {
        $color = self::color($school);
        $logo = e(FinanceDocument::logoSrc($school));
        $name = e($school?->name ?? 'School');
        $contact = '';
        foreach ([
            ['', $school?->address],
            ['Tel', $school?->phone],
            ['Email', $school?->email],
            ['Web', $school?->website ?? null],
        ] as [$label, $value]) {
            if ($value) {
                $contact .= '<div class="c">' . ($label ? '<b>' . $label . ':</b> ' : '') . e($value) . '</div>';
            }
        }
        [$naira, $kobo] = self::split($r['amount']);
        $balance = $r['balance'] === null ? '' : self::money($r['balance']);
        $stamp = $r['stamp'] ? '<div class="stamp">' . e($r['stamp']) . '</div>' : '';
        $extra = array_filter([
            $r['method'] ? 'Paid by ' . e($r['method']) : null,
            $r['reference'] ? 'Ref: ' . e($r['reference']) : null,
            $r['paid_into'] ? 'Into: ' . e($r['paid_into']) : null,
        ]);
        $extraHtml = $extra ? '<div class="meta">' . implode(' &nbsp;·&nbsp; ', $extra) . '</div>' : '';
        $notes = $r['notes'] ? '<div class="line-v small">' . e($r['notes']) . '</div>' : '';
        $cashier = $r['cashier'] ? e($r['cashier']) : '&nbsp;';
        $title = e('Receipt ' . $r['number']);
        $number = e($r['number']);
        $date = e($r['date']);
        $from = e($r['received_from']);
        $words = e($r['words']);
        $for = e($r['payment_for']);
        $deposit = self::money($r['deposit']);

        return <<<HTML
<!DOCTYPE html>
<html lang="en" data-paper="a5l" data-font="sans">
<head>
<meta charset="UTF-8">
<title>{$title}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Poppins', 'Segoe UI', Arial, sans-serif; color: #2b2b2b; font-size: 12.5px; padding: 22px 26px; }
  .sheet { max-width: 820px; margin: 0 auto; position: relative; }
  table { border-collapse: collapse; width: 100%; }
  .top td { vertical-align: top; }
  .logo { height: 72px; max-width: 200px; }
  .school { font-size: 18px; font-weight: bold; color: {$color}; margin-top: 4px; }
  .c { font-size: 11px; color: #444; line-height: 1.45; }
  .noBox { border: 1.5px solid #555; padding: 6px 10px; margin-bottom: 6px; }
  .noBox .lbl { font-size: 10px; color: #555; text-transform: uppercase; }
  .no { font-size: 20px; font-weight: bold; color: #b91c1c; letter-spacing: 2px; font-family: 'Courier New', monospace; }
  .band { background: {$color}; color: #fff; font-size: 26px; font-weight: bold; letter-spacing: 3px; padding: 6px 18px; margin: 14px 0 10px; }
  .row { border: 1.5px solid #666; margin-top: 8px; }
  .row td { padding: 7px 10px; vertical-align: top; }
  .lbl2 { width: 170px; font-weight: bold; color: #333; white-space: nowrap; }
  .val { font-size: 13px; }
  .line-v { border-bottom: 1px solid #888; min-height: 20px; padding: 2px 0 3px; }
  .small { font-size: 11px; color: #555; }
  .split td { padding: 0; }
  .amtBox td { border: 1.5px solid #666; padding: 7px 10px; }
  .amt { font-size: 18px; font-weight: bold; }
  .sig { border-top: 1px solid #333; width: 230px; padding-top: 4px; font-weight: bold; }
  .meta { font-size: 10.5px; color: #555; margin-top: 10px; }
  .stamp { display: inline-block; border: 3px solid #16a34a; color: #16a34a; font-weight: bold; font-size: 18px; letter-spacing: 4px; padding: 4px 16px; transform: rotate(-10deg); opacity: .85; }
  @media print { body { padding: 0; } @page { size: A5 landscape; margin: 1cm; } }
</style>
</head>
<body>
<div class="sheet">
<table class="top"><tr>
  <td style="width:42%"><img class="logo" src="{$logo}" alt=""><div class="school">{$name}</div></td>
  <td style="width:30%; padding-top:6px">{$contact}</td>
  <td style="width:28%">
    <div class="noBox"><span class="lbl">Receipt No.</span><br><span class="no">{$number}</span></div>
    <div class="noBox"><span class="lbl">Date:</span> <b>{$date}</b></div>
  </td>
</tr></table>

<div class="band">RECEIPT</div>

<table class="row"><tr><td class="lbl2">Received from</td><td class="val"><div class="line-v">{$from}</div></td></tr></table>
<table class="row"><tr><td class="lbl2">The sum of (in words)</td><td class="val"><div class="line-v">{$words}</div></td></tr></table>
<table class="row"><tr><td class="lbl2">Being payment for</td><td class="val"><div class="line-v">{$for}</div>{$notes}</td></tr></table>

<table class="split" style="margin-top:8px"><tr>
  <td style="width:49%; padding-right:8px">
    <table class="row" style="margin-top:0"><tr><td class="lbl2" style="width:90px; padding:7px 10px">Deposit</td><td class="val amt" style="padding:7px 10px">{$deposit}</td></tr></table>
  </td>
  <td style="width:51%">
    <table class="row" style="margin-top:0"><tr><td class="lbl2" style="width:90px; padding:7px 10px">Balance</td><td class="val amt" style="padding:7px 10px">{$balance}</td></tr></table>
  </td>
</tr></table>

<table style="margin-top:16px"><tr>
  <td style="vertical-align:bottom; width:38%">
    <div style="height:34px; font-size:12px">{$cashier}</div>
    <div class="sig">Cashier's signature</div>
  </td>
  <td style="vertical-align:middle; text-align:center; width:18%">{$stamp}</td>
  <td style="vertical-align:bottom; text-align:right; width:44%">
    <table class="amtBox" style="width:auto; margin-left:auto"><tr>
      <td style="border:none; font-weight:bold; padding-right:8px">Amount</td>
      <td style="min-width:170px"><span style="font-weight:bold">&#8358;</span> <span class="amt">{$naira}</span></td>
      <td style="min-width:56px; text-align:center"><span class="small">K</span> <span class="amt">{$kobo}</span></td>
    </tr></table>
  </td>
</tr></table>
{$extraHtml}
</div>
<script>window.onload = function() { window.print(); }</script>
</body>
</html>
HTML;
    }

    public static function color(?School $school): string
    {
        $c = (string) ($school?->settings['document_color'] ?? '');

        return preg_match('/^#[0-9a-f]{6}$/i', $c) ? $c : self::DEFAULT_COLOR;
    }

    public static function money(float $amount): string
    {
        return '₦' . number_format($amount, 2);
    }

    /** [naira with thousands separators, two-digit kobo] */
    public static function split(float $amount): array
    {
        $kobo = (int) round($amount * 100);

        return [number_format(intdiv($kobo, 100)), str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT)];
    }

    /** "Fifty Thousand Naira, Twenty Kobo Only" */
    public static function words(float $amount): string
    {
        $kobo = (int) round($amount * 100);
        $n = intdiv($kobo, 100);
        $k = $kobo % 100;
        $text = ($n ? self::spell($n) : 'Zero') . ' Naira';
        if ($k) {
            $text .= ', ' . self::spell($k) . ' Kobo';
        }

        return $text . ' Only';
    }

    private static function spell(int $n): string
    {
        static $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
            'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        static $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $under1000 = function (int $x) use ($ones, $tens): string {
            $parts = [];
            if ($x >= 100) {
                $parts[] = $ones[intdiv($x, 100)] . ' Hundred';
                $x %= 100;
                if ($x) {
                    $parts[] = 'and';
                }
            }
            if ($x >= 20) {
                $parts[] = $tens[intdiv($x, 10)] . ($x % 10 ? '-' . $ones[$x % 10] : '');
            } elseif ($x > 0) {
                $parts[] = $ones[$x];
            }

            return implode(' ', $parts);
        };
        $out = [];
        foreach ([1_000_000_000_000 => 'Trillion', 1_000_000_000 => 'Billion', 1_000_000 => 'Million', 1000 => 'Thousand', 1 => ''] as $size => $word) {
            if ($n >= $size) {
                $chunk = intdiv($n, $size);
                $n %= $size;
                $out[] = trim($under1000($chunk) . ' ' . $word);
            }
        }

        return implode(', ', $out);
    }
}
