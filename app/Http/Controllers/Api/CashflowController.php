<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashflowTransaction;
use App\Models\FinancialAccount;
use App\Models\FinancialObligation;
use App\Services\CashflowService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashflowController extends Controller
{
    public function index(Request $request, CashflowService $cashflowService)
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
        ]);

        $timezone = config('app.timezone', 'Asia/Jakarta');
        $end = Carbon::createFromFormat(
            'Y-m-d',
            (string) ($request->query('end_date') ?: now($timezone)->toDateString()),
            $timezone,
        )->endOfDay();
        $start = Carbon::createFromFormat(
            'Y-m-d',
            (string) ($request->query('start_date') ?: $end->copy()->subDays(29)->toDateString()),
            $timezone,
        )->startOfDay();

        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages([
                'end_date' => 'Tanggal akhir harus sama atau setelah tanggal mulai.',
            ]);
        }
        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages([
                'end_date' => 'Rentang arus kas maksimal 366 hari.',
            ]);
        }

        $user = Auth::user();
        $accounts = FinancialAccount::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();
        $escrowAccount = $accounts->firstWhere('source_type', 'marketplace_escrow');
        if ($escrowAccount) {
            $marketplaceEscrow = $cashflowService->marketplaceEscrow($user);
            $escrowAccount->setAttribute('balance', $marketplaceEscrow['total']);
            $escrowAccount->setAttribute('synced_at', now());
            $escrowAccount->setAttribute('integration_details', [
                'excludes_seller_balance' => true,
                'stores' => $marketplaceEscrow['stores']->values(),
            ]);
        }
        $obligations = FinancialObligation::query()
            ->where('user_id', $user->id)
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderBy('due_date')
            ->orderByDesc('created_at')
            ->get();
        $supplierPayables = $cashflowService->supplierPayables($user->id);
        $manualTransactions = CashflowTransaction::query()
            ->with('account:id,name,category,subtype')
            ->where('user_id', $user->id)
            ->whereBetween('occurred_at', [$start, $end])
            ->get()
            ->map(fn (CashflowTransaction $transaction) => [
                'id' => $transaction->id,
                'source' => 'manual',
                'direction' => $transaction->direction,
                'category' => $transaction->category,
                'description' => $transaction->description,
                'amount' => (float) $transaction->amount,
                'occurred_at' => $transaction->occurred_at?->toIso8601String(),
                'notes' => $transaction->notes,
                'financial_account_id' => $transaction->financial_account_id,
                'account' => $transaction->account,
                'is_read_only' => false,
            ]);
        $profitTransactions = $cashflowService->dailyProfit($user, $start, $end);
        $transactions = $manualTransactions
            ->concat($profitTransactions)
            ->sortByDesc('occurred_at')
            ->values();

        $cashPosition = (float) $accounts->where('category', 'cash')->sum('balance');
        $assets = (float) $accounts->where('category', 'asset')->sum('balance');
        $manualDebt = (float) $obligations
            ->where('kind', 'debt')
            ->where('status', 'open')
            ->sum('amount');
        $receivables = (float) $obligations
            ->where('kind', 'receivable')
            ->where('status', 'open')
            ->sum('amount');
        $supplierDebt = (float) $supplierPayables->sum('amount');
        $totalDebt = $manualDebt + $supplierDebt;

        $chart = $transactions
            ->groupBy(fn (array $transaction) => Carbon::parse($transaction['occurred_at'])->setTimezone($timezone)->toDateString())
            ->map(function ($items, $date) {
                $income = (float) $items->where('direction', 'income')->sum('amount');
                $expense = (float) $items->where('direction', 'expense')->sum('amount');

                return [
                    'date' => $date,
                    'income' => $income,
                    'expense' => $expense,
                    'net' => $income - $expense,
                ];
            })->sortBy('date')->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => [
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                ],
                'summary' => [
                    'cash_position' => $cashPosition,
                    'assets' => $assets,
                    'supplier_payable' => $supplierDebt,
                    'manual_debt' => $manualDebt,
                    'total_debt' => $totalDebt,
                    'receivables' => $receivables,
                    'net_worth' => $cashPosition + $assets + $receivables - $totalDebt,
                ],
                'accounts' => $accounts,
                'obligations' => $obligations,
                'supplier_payables' => $supplierPayables,
                'transactions' => $transactions,
                'chart' => $chart,
                'cashflow_totals' => [
                    'income' => (float) $transactions->where('direction', 'income')->sum('amount'),
                    'expense' => (float) $transactions->where('direction', 'expense')->sum('amount'),
                    'net' => (float) $transactions->sum(fn (array $item) => $item['direction'] === 'income' ? $item['amount'] : -$item['amount']),
                ],
            ],
        ]);
    }

    public function storeAccount(Request $request)
    {
        $validated = $this->validateAccount($request);
        $account = FinancialAccount::create([
            ...$validated,
            'user_id' => Auth::id(),
            'is_active' => true,
        ]);

        return response()->json(['status' => 'success', 'data' => $account], 201);
    }

    public function integrateMarketplaceEscrow(CashflowService $cashflowService)
    {
        $user = Auth::user();
        if (! $user->stores()->exists()) {
            throw ValidationException::withMessages([
                'integration' => 'Hubungkan setidaknya satu toko sebelum mengintegrasikan escrow.',
            ]);
        }

        $account = FinancialAccount::firstOrCreate(
            [
                'user_id' => $user->id,
                'source_type' => 'marketplace_escrow',
            ],
            [
                'name' => 'Escrow Marketplace',
                'category' => 'cash',
                'subtype' => 'marketplace',
                'balance' => 0,
                'notes' => 'Escrow pesanan aktif marketplace. Tidak termasuk saldo penjual.',
                'is_active' => true,
            ],
        );
        $marketplaceEscrow = $cashflowService->marketplaceEscrow($user);
        $account->update([
            'balance' => $marketplaceEscrow['total'],
            'synced_at' => now(),
            'is_active' => true,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $account->fresh(),
            'created' => $account->wasRecentlyCreated,
        ], $account->wasRecentlyCreated ? 201 : 200);
    }

    public function updateAccount(Request $request, int $id)
    {
        $account = $this->account($id);
        if ($account->source_type) {
            throw ValidationException::withMessages([
                'account' => 'Portofolio terintegrasi diperbarui otomatis dan tidak dapat diedit manual.',
            ]);
        }
        $account->update($this->validateAccount($request));

        return response()->json(['status' => 'success', 'data' => $account->fresh()]);
    }

    public function destroyAccount(int $id)
    {
        $this->account($id)->delete();

        return response()->json(['status' => 'success']);
    }

    public function storeObligation(Request $request)
    {
        $obligation = FinancialObligation::create([
            ...$this->validateObligation($request),
            'user_id' => Auth::id(),
        ]);

        return response()->json(['status' => 'success', 'data' => $obligation], 201);
    }

    public function updateObligation(Request $request, int $id)
    {
        $obligation = $this->obligation($id);
        $obligation->update($this->validateObligation($request));

        return response()->json(['status' => 'success', 'data' => $obligation->fresh()]);
    }

    public function destroyObligation(int $id)
    {
        $this->obligation($id)->delete();

        return response()->json(['status' => 'success']);
    }

    public function storeTransaction(Request $request)
    {
        $validated = $this->validateTransaction($request);
        $transaction = DB::transaction(function () use ($validated) {
            $account = $this->transactionAccount($validated['financial_account_id'] ?? null, true);
            $transaction = CashflowTransaction::create([
                ...$validated,
                'user_id' => Auth::id(),
            ]);
            $this->applyToBalance($account, $transaction->direction, (float) $transaction->amount);

            return $transaction;
        });

        return response()->json(['status' => 'success', 'data' => $transaction->load('account')], 201);
    }

    public function updateTransaction(Request $request, int $id)
    {
        $validated = $this->validateTransaction($request);
        $transaction = DB::transaction(function () use ($validated, $id) {
            $transaction = CashflowTransaction::query()
                ->where('user_id', Auth::id())
                ->lockForUpdate()
                ->findOrFail($id);
            $oldAccount = $this->transactionAccount($transaction->financial_account_id, true);
            $this->applyToBalance($oldAccount, $transaction->direction, -(float) $transaction->amount);

            $newAccount = $this->transactionAccount($validated['financial_account_id'] ?? null, true);
            $transaction->update($validated);
            $this->applyToBalance($newAccount, $transaction->direction, (float) $transaction->amount);

            return $transaction;
        });

        return response()->json(['status' => 'success', 'data' => $transaction->load('account')]);
    }

    public function destroyTransaction(int $id)
    {
        DB::transaction(function () use ($id) {
            $transaction = CashflowTransaction::query()
                ->where('user_id', Auth::id())
                ->lockForUpdate()
                ->findOrFail($id);
            $account = $this->transactionAccount($transaction->financial_account_id, true);
            $this->applyToBalance($account, $transaction->direction, -(float) $transaction->amount);
            $transaction->delete();
        });

        return response()->json(['status' => 'success']);
    }

    private function validateAccount(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'category' => 'required|in:cash,asset',
            'subtype' => 'required|in:cash,bank,ewallet,marketplace,investment,property,other_asset',
            'balance' => 'required|numeric|min:0|max:999999999999999999',
            'notes' => 'nullable|string|max:1000',
        ]);
    }

    private function validateObligation(Request $request): array
    {
        return $request->validate([
            'kind' => 'required|in:debt,receivable',
            'title' => 'required|string|max:150',
            'counterparty' => 'nullable|string|max:150',
            'amount' => 'required|numeric|min:0.01|max:999999999999999999',
            'due_date' => 'nullable|date_format:Y-m-d',
            'status' => 'required|in:open,settled',
            'notes' => 'nullable|string|max:1000',
        ]);
    }

    private function validateTransaction(Request $request): array
    {
        return $request->validate([
            'financial_account_id' => 'nullable|integer',
            'direction' => 'required|in:income,expense',
            'category' => 'nullable|string|max:100',
            'description' => 'required|string|max:180',
            'amount' => 'required|numeric|min:0.01|max:999999999999999999',
            'occurred_at' => 'required|date',
            'notes' => 'nullable|string|max:1000',
        ]);
    }

    private function account(int $id): FinancialAccount
    {
        return FinancialAccount::where('user_id', Auth::id())->findOrFail($id);
    }

    private function obligation(int $id): FinancialObligation
    {
        return FinancialObligation::where('user_id', Auth::id())->findOrFail($id);
    }

    private function transactionAccount(?int $id, bool $lock = false): ?FinancialAccount
    {
        if (! $id) {
            return null;
        }

        $query = FinancialAccount::query()->where('user_id', Auth::id());
        if ($lock) {
            $query->lockForUpdate();
        }

        $account = $query->findOrFail($id);
        if ($account->source_type) {
            throw ValidationException::withMessages([
                'financial_account_id' => 'Portofolio terintegrasi tidak dapat digunakan untuk transaksi manual.',
            ]);
        }

        return $account;
    }

    private function applyToBalance(?FinancialAccount $account, string $direction, float $amount): void
    {
        if (! $account) {
            return;
        }

        $delta = $direction === 'income' ? $amount : -$amount;
        $account->balance = (float) $account->balance + $delta;
        $account->save();
    }
}
