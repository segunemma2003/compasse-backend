<?php

namespace Tests\Unit;

use App\Support\SpreadsheetMapping;
use Tests\TestCase;

/** A school's own sheet (title rows, S/N, totals) is understood and rearranged for the importer. */
class SpreadsheetMappingTest extends TestCase
{
    public function test_school_invoice_sheet(): void
    {
        $rows = [
            ['QUIVER SCHOOLS', '', '', '', '', '', ''],
            ['1ST TERM INVOICES 2026/2027', '', '', '', '', '', ''],
            ['S/N', "PUPIL'S NAME", 'ADM NO', 'TUITION', 'BOOKS', 'SIBLING DISCOUNT', 'TOTAL'],
            ['1', 'Ada Obi', 'Q/001', '150,000', '20000', '10000', '160000'],
            ['2', 'Bola Ade', 'Q/002', '150000', '', '', '150000'],
            ['', 'TOTAL', '', '300000', '20000', '10000', '310000'],
        ];
        $p = SpreadsheetMapping::preview('fees', $rows);
        $this->assertSame(2, $p['header_row']);
        $this->assertSame(['ignore', 'name', 'admission', 'line', 'line', 'deduct', 'ignore'], $p['roles']);

        [$out] = SpreadsheetMapping::apply('fees', $rows, $p['header_row'], $p['roles']);
        $this->assertSame(['Student Name', 'Admission Number', 'TUITION', 'BOOKS', 'SIBLING DISCOUNT (deduct)'], $out[0]);
        $this->assertCount(3, $out, 'heading + 2 pupils; the TOTAL row is dropped');
        $this->assertSame('Q/001', $out[1][1]);
    }

    public function test_staff_payment_sheet(): void
    {
        $rows = [
            ['STAFF PAYMENTS — JANUARY 2026'],
            ['S/N', 'NAME', 'STAFF NO', 'BASIC SALARY', 'TRANSPORT ALLOWANCE', 'PAYE', 'COOPERATIVE', 'NET PAY', 'ACCOUNT NO'],
            ['1', 'Mrs Bisi', 'QS/011', '120000', '15000', '3500', '5000', '126500', '0123456789'],
        ];
        $p = SpreadsheetMapping::preview('payroll', $rows);
        $this->assertSame(1, $p['header_row']);
        $this->assertSame(['ignore', 'name', 'staff_id', 'basic', 'earning', 'deduction', 'earning', 'ignore', 'ignore'], $p['roles']);
        // The bursar corrects "COOPERATIVE" to a deduction.
        $p['roles'][6] = 'deduction';
        [$out, $types] = SpreadsheetMapping::apply('payroll', $rows, $p['header_row'], $p['roles']);
        $this->assertSame(['Name', 'Staff ID or Email', 'Basic Salary', 'TRANSPORT ALLOWANCE', 'PAYE', 'COOPERATIVE'], $out[0]);
        $this->assertSame('deduction', $types['cooperative']);
        $this->assertSame('earning', $types['transport allowance']);
    }
}
