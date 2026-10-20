<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\SchoolBankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Settings for the bank account(s) a school wants fee/invoice payments made
 * into. Surfaced on invoices/receipts and fee reminder emails.
 */
class SchoolBankAccountController extends Controller
{
    private function school(Request $request): ?School
    {
        return School::find($request->school_id) ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $school = $this->school($request);

        $accounts = SchoolBankAccount::where('school_id', $school?->id ?? 0)
            ->orderByDesc('is_primary')
            ->orderBy('bank_name')
            ->get();

        return response()->json(['bank_accounts' => $accounts]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $school = $this->school($request);

        $validator = Validator::make($request->all(), [
            'bank_name'      => 'required|string|max:150',
            'account_name'   => 'required|string|max:150',
            'account_number' => 'required|string|max:50',
            'notes'          => 'nullable|string|max:255',
            'is_primary'     => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $account = DB::transaction(function () use ($request, $school) {
            if ($request->boolean('is_primary')) {
                SchoolBankAccount::where('school_id', $school?->id ?? 0)->update(['is_primary' => false]);
            }

            return SchoolBankAccount::create([
                'school_id'      => $school?->id ?? 1,
                'bank_name'      => $request->bank_name,
                'account_name'   => $request->account_name,
                'account_number' => $request->account_number,
                'notes'          => $request->notes,
                // The first account a school adds is the primary one by
                // default, so reminder emails always have somewhere to point.
                'is_primary'     => $request->boolean('is_primary') || SchoolBankAccount::where('school_id', $school?->id ?? 0)->count() === 0,
            ]);
        });

        return response()->json(['message' => 'Bank account added', 'bank_account' => $account], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $account = SchoolBankAccount::find($id);
        if (! $account) {
            return response()->json(['error' => 'Bank account not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'bank_name'      => 'sometimes|string|max:150',
            'account_name'   => 'sometimes|string|max:150',
            'account_number' => 'sometimes|string|max:50',
            'notes'          => 'nullable|string|max:255',
            'is_primary'     => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($request, $account) {
            if ($request->boolean('is_primary')) {
                SchoolBankAccount::where('school_id', $account->school_id)
                    ->where('id', '!=', $account->id)
                    ->update(['is_primary' => false]);
            }

            $account->update($request->only(['bank_name', 'account_name', 'account_number', 'notes', 'is_primary']));
        });

        return response()->json(['message' => 'Bank account updated', 'bank_account' => $account->fresh()]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $account = SchoolBankAccount::find($id);
        if (! $account) {
            return response()->json(['error' => 'Bank account not found'], 404);
        }

        $wasPrimary = $account->is_primary;
        $schoolId = $account->school_id;
        $account->delete();

        // Promote another account to primary so reminders/invoices don't go
        // out with no payment destination at all.
        if ($wasPrimary) {
            SchoolBankAccount::where('school_id', $schoolId)->oldest()->first()?->update(['is_primary' => true]);
        }

        return response()->json(['message' => 'Bank account removed']);
    }

    /**
     * The numbered NOTE terms printed on every invoice/receipt (one per line).
     */
    public function invoiceSettings(Request $request): JsonResponse
    {
        $school = $this->school($request);

        return response()->json([
            'invoice_terms' => $school?->settings['invoice_terms'] ?? '',
            'document_color' => \App\Support\ReceiptDocument::color($school),
        ]);
    }

    public function updateInvoiceSettings(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'invoice_terms' => 'nullable|string|max:5000',
            'document_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $school = $this->school($request);
        if (! $school) {
            return response()->json(['error' => 'School not found'], 404);
        }

        $changes = [];
        if ($request->has('invoice_terms')) {
            $changes['invoice_terms'] = trim((string) $request->input('invoice_terms', ''));
        }
        if ($request->filled('document_color')) {
            $changes['document_color'] = strtolower($request->input('document_color'));    // the receipt's band colour
        }
        $school->update(['settings' => array_merge($school->settings ?? [], $changes)]);

        return response()->json(['message' => 'Invoice notes saved', 'invoice_terms' => $school->settings['invoice_terms'] ?? '', 'document_color' => \App\Support\ReceiptDocument::color($school)]);
    }
}
