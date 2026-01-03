<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request; // ← IMPORTANT: needed for /api/whoami
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\ModuleAccessController;
use App\Http\Controllers\ApplicationSettingsController;
use App\Http\Controllers\PbnEntryController;
use App\Http\Controllers\SugarTypeController;
use App\Http\Controllers\CropYearController;
use App\Http\Controllers\VendorListController;
use App\Http\Controllers\MillListController;
use App\Http\Controllers\MillRateHistoryController;
use App\Http\Controllers\ReceivingController;
use App\Http\Controllers\PbnController;
use App\Http\Controllers\SalesJournalController;
use App\Http\Controllers\CashReceiptController;
use App\Http\Controllers\PurchaseJournalController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\UserProfileController;

use App\Http\Controllers\ProfileController;
use App\Http\Middleware\VerifyCsrfToken;

use App\Http\Controllers\AssignUserModules\ModuleTreeController;
use App\Http\Controllers\AssignUserModules\UserAssignmentController;
use App\Http\Controllers\AssignUserModules\UsersEmployeesController;

use App\Http\Controllers\ReferenceBankController;

// routes/web.php
use App\Http\Controllers\ReferenceCustomerController;
use App\Http\Controllers\TrialBalanceController;

use App\Http\Controllers\GeneralLedgerController;
use App\Http\Controllers\ReferencePlanterController;
use App\Http\Controllers\CompanySettingController;
use App\Http\Controllers\GeneralJournalBookController;
use App\Http\Controllers\CashReceiptBookController;

// routes/web.php

use App\Http\Controllers\AccountsPayableJournalController;

// routes/web.php
use App\Http\Controllers\CashDisbursementBookController;

// routes/web.php
use App\Http\Controllers\AccountsReceivableJournalController;
use App\Http\Controllers\CheckRegisterController;


use App\Http\Controllers\ApprovalController;

use App\Http\Controllers\PlanterController;



use App\Http\Controllers\ReceivingPostingController;

Route::middleware(['web','auth:sanctum'])->prefix('api')->group(function () {
    // Receiving Posting module (Bite A)
    Route::get('/receiving-posting/list', [ReceivingPostingController::class, 'list']);
    Route::get('/receiving-posting/show/{id}', [ReceivingPostingController::class, 'show']);
    Route::get('/receiving-posting/preview-journal/{id}', [ReceivingPostingController::class, 'previewJournal']);
});





Route::get('/planters/lookup', [PlanterController::class, 'lookup']);


// ...

Route::prefix('api')->middleware(['web','auth:sanctum'])->group(function () {
    // Create / reuse approval request for a record
    Route::post('/approvals/request-edit', [ApprovalController::class, 'requestEdit']);

    // Status / token for a specific record (module + record_id)
    Route::get('/approvals/status', [ApprovalController::class, 'statusBySubject']);
    Route::get('/approvals/status-by-subject', [ApprovalController::class, 'statusBySubject']);

    // Mark an approved request as consumed
    Route::post('/approvals/release', [ApprovalController::class, 'releaseBySubject']);
    Route::post('/approvals/release-by-subject', [ApprovalController::class, 'releaseBySubject']);

    // Supervisor inbox / requester outbox
    Route::get('/approvals/inbox',  [ApprovalController::class, 'inbox']);
    Route::get('/approvals/outbox', [ApprovalController::class, 'outbox']);

    // Detail + approve / reject by id
    Route::get ('/approvals/{id}',          [ApprovalController::class, 'show'])->whereNumber('id');
    Route::post('/approvals/{id}/approve',  [ApprovalController::class, 'approve'])->whereNumber('id');
    Route::post('/approvals/{id}/reject',   [ApprovalController::class, 'reject'])->whereNumber('id');
});



Route::prefix('api')->group(function () {
    // ✅ PBN dropdown
    Route::get('/pbn/dropdown-list', [PbnEntryController::class, 'getPbnDropdownList']);

    // ✅ (optional) PBN show/PDF/Excel remain protected if needed
    Route::get('/pbn/form-pdf/{id}', [PbnEntryController::class, 'formPdf']);
    Route::get('/pbn/form-excel/{id}', [PbnEntryController::class, 'formExcel']);
    Route::get('/pbn/{id}', [PbnEntryController::class, 'show']);
    Route::get('/sugar-types', [SugarTypeController::class, 'index']);
    Route::get('/crop-years', [CropYearController::class, 'index']);
    Route::get('/vendors', [VendorListController::class, 'index']);    
});






/* ─── PBN read-only (no auth; safe GETs) ─── */
//Route::prefix('api')->group(function () {
    // Items combobox for a PBN number (if you use it)
    Route::get('/api/pbn/items',         [PbnController::class, 'items']);
//});

/* ─── PBN stateful CRUD (session + auth) ─── */
Route::prefix('api')->middleware(['web','auth:sanctum'])->group(function () {
    Route::post('/pbn/save-main',     [PbnEntryController::class, 'storeMain']);
    Route::post('/pbn/save-detail',   [PbnEntryController::class, 'saveDetail']);
    Route::post('/pbn/update-detail', [PbnEntryController::class, 'updateDetail']);
    Route::post('/pbn/delete-detail', [PbnEntryController::class, 'deleteDetailAndLog']);

    // Show a specific PBN (used by handlePbnSelect)
    Route::get('/pbn/{id}', [PbnEntryController::class, 'show'])->whereNumber('id');

    /* ─── PBN Posting (Preview-first + Approval-aligned actions) ─── */
    Route::get('/pbn/posting/list', [\App\Http\Controllers\PbnPostingController::class, 'list']);
    Route::get('/pbn/posting/{id}', [\App\Http\Controllers\PbnPostingController::class, 'show'])->whereNumber('id');
// ===== START ADD: PBN posting approval-request routes =====
Route::post('/pbn/posting/{id}/request-post', [\App\Http\Controllers\PbnPostingController::class, 'requestPost'])->whereNumber('id');
Route::post('/pbn/posting/{id}/request-unpost-unused', [\App\Http\Controllers\PbnPostingController::class, 'requestUnpostUnused'])->whereNumber('id');
Route::post('/pbn/posting/{id}/request-close', [\App\Http\Controllers\PbnPostingController::class, 'requestClose'])->whereNumber('id');
// ===== END ADD: PBN posting approval-request routes =====



    /* ─── Receiving-side helper: remaining qty validation ─── */
    Route::post('/pbn/remaining-check', [\App\Http\Controllers\PbnController::class, 'remainingCheck']);




});

/* ─── PBN helpers & reports (web only; avoid auth redirect/HTML) ─── */
Route::prefix('api')->middleware(['web'])->group(function () {
    Route::get('/pbn/generate-pbn-number', [ApplicationSettingsController::class, 'getNextPbnNumber']);
});




// RECEIPT REGISTER (under /api, stateful)
Route::prefix('api')->middleware(['web','auth:sanctum'])->group(function () {
    Route::get ('/receipt-register/months',   [\App\Http\Controllers\ReceiptRegisterController::class, 'months']);
    Route::get ('/receipt-register/years',    [\App\Http\Controllers\ReceiptRegisterController::class, 'years']);

    Route::post('/receipt-register/report',   [\App\Http\Controllers\ReceiptRegisterController::class, 'start']);
    Route::get ('/receipt-register/report/{ticket}/status',   [\App\Http\Controllers\ReceiptRegisterController::class, 'status'])->whereUuid('ticket');
    Route::get ('/receipt-register/report/{ticket}/download', [\App\Http\Controllers\ReceiptRegisterController::class, 'download'])->whereUuid('ticket');
    Route::get ('/receipt-register/report/{ticket}/view',     [\App\Http\Controllers\ReceiptRegisterController::class, 'view'])->whereUuid('ticket');
});




Route::prefix('api')->middleware(['web', 'auth:sanctum'])->group(function () {

    Route::get ('/check-register/months',  [CheckRegisterController::class, 'months']);
    Route::get ('/check-register/years',   [CheckRegisterController::class, 'years']);

    Route::post('/check-register/report',  [CheckRegisterController::class, 'start']);

    Route::get ('/check-register/report/{ticket}/status',   [CheckRegisterController::class, 'status'])->whereUuid('ticket');
    Route::get ('/check-register/report/{ticket}/download', [CheckRegisterController::class, 'download'])->whereUuid('ticket');
    Route::get ('/check-register/report/{ticket}/view',     [CheckRegisterController::class, 'view'])->whereUuid('ticket');

});




Route::prefix('api')->middleware(['web'])->group(function () {

    Route::prefix('accounts-receivable')->group(function () {
        Route::post('/report',                   [AccountsReceivableJournalController::class, 'start']);
        Route::get ('/report/{ticket}/status',   [AccountsReceivableJournalController::class, 'status'])->whereUuid('ticket');
        Route::get ('/report/{ticket}/download', [AccountsReceivableJournalController::class, 'download'])->whereUuid('ticket');
        Route::get ('/report/{ticket}/view',     [AccountsReceivableJournalController::class, 'view'])->whereUuid('ticket');
    });

});




// Cash Disbursement Book – ticketed report lifecycle (Option A: company_id scoped, no auth)
Route::prefix('api')->middleware(['web'])->group(function () {
    Route::prefix('cash-disbursements')->group(function () {
        Route::post('/report',                   [CashDisbursementBookController::class, 'start']);
        Route::get ('/report/{ticket}/status',   [CashDisbursementBookController::class, 'status'])->whereUuid('ticket');
        Route::get ('/report/{ticket}/download', [CashDisbursementBookController::class, 'download'])->whereUuid('ticket');
        Route::get ('/report/{ticket}/view',     [CashDisbursementBookController::class, 'view'])->whereUuid('ticket');
    });
});





Route::prefix('api')->middleware(['web'])->group(function () {
    Route::prefix('accounts-payable')->group(function () {
        // Start a job
        Route::post('/report', [AccountsPayableJournalController::class, 'start']);
        // Poll job status
        Route::get('/report/{ticket}/status', [AccountsPayableJournalController::class, 'status'])->whereUuid('ticket');
        // Download result (PDF/XLS)
        Route::get('/report/{ticket}/download', [AccountsPayableJournalController::class, 'download'])->whereUuid('ticket');
        // Inline view (PDF only)
        Route::get('/report/{ticket}/view', [AccountsPayableJournalController::class, 'view'])->whereUuid('ticket');
    });
});

Route::prefix('api')->middleware(['web'])->group(function () {
    // Cash Receipt Book – ticketed report lifecycle
    Route::post('/cash-receipts/report',                   [CashReceiptBookController::class, 'start']);
    Route::get ('/cash-receipts/report/{ticket}/status',   [CashReceiptBookController::class, 'status']);
    Route::get ('/cash-receipts/report/{ticket}/view',     [CashReceiptBookController::class, 'view']);
    Route::get ('/cash-receipts/report/{ticket}/download', [CashReceiptBookController::class, 'download']);
});



Route::prefix('api')->middleware(['web'])->group(function () {
    // General Journal – ticketed report lifecycle (Option A: company_id scoped)
    Route::post('/general-journal/report',                   [GeneralJournalBookController::class, 'start']);
    Route::get ('/general-journal/report/{ticket}/status',   [GeneralJournalBookController::class, 'status'])->whereUuid('ticket');
    Route::get ('/general-journal/report/{ticket}/view',     [GeneralJournalBookController::class, 'view'])->whereUuid('ticket');
    Route::get ('/general-journal/report/{ticket}/download', [GeneralJournalBookController::class, 'download'])->whereUuid('ticket');
});




/* Public dropdown (no CSRF needed anyway) */
//Route::get('/api/sugar-types', [SugarTypeController::class, 'index'])
//    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

/* Admin CRUD + paginated list (stateful) */
Route::prefix('api')->middleware(['web','auth:sanctum'])->group(function () {
    Route::get   ('/sugar-types/admin',        [SugarTypeController::class, 'adminIndex']);
    Route::post  ('/sugar-types/admin',        [SugarTypeController::class, 'store']);
    Route::put   ('/sugar-types/admin/{id}',   [SugarTypeController::class, 'update']);
    Route::delete('/sugar-types/admin/{id}',   [SugarTypeController::class, 'destroy']);
});




Route::prefix('api')->middleware(['web','auth:sanctum'])->group(function () {
    // Admin endpoints (CRUD + paginated list)
    Route::get   ('/crop-years/admin',        [CropYearController::class, 'adminIndex']);
    Route::post  ('/crop-years/admin',        [CropYearController::class, 'store']);
    Route::put   ('/crop-years/admin/{id}',   [CropYearController::class, 'update']);
    Route::delete('/crop-years/admin/{id}',   [CropYearController::class, 'destroy']);
});

// KEEP your existing public/previous route for dropdowns:
//Route::get('/api/crop-years', [CropYearController::class, 'index'])
//    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]); // optional: match your pattern for public GETs




Route::prefix('api')->middleware(['web','auth:sanctum'])->group(function () {
    Route::get   ('/company-settings',        [CompanySettingController::class, 'index']);
    Route::post  ('/company-settings',        [CompanySettingController::class, 'store']);
    Route::put   ('/company-settings/{id}',   [CompanySettingController::class, 'update']);
    Route::delete('/company-settings/{id}',   [CompanySettingController::class, 'destroy']);
});





Route::prefix('api')->middleware(['web','auth:sanctum'])->group(function () {
    Route::get   ('/references/planters',        [ReferencePlanterController::class, 'index']);
    Route::post  ('/references/planters',        [ReferencePlanterController::class, 'store']);
    Route::put   ('/references/planters/{id}',   [ReferencePlanterController::class, 'update']);
    Route::delete('/references/planters/{id}',   [ReferencePlanterController::class, 'destroy']);
    Route::get   ('/references/planters/export', [ReferencePlanterController::class, 'export']);
});




Route::prefix('api')->middleware(['web','auth:sanctum'])->group(function () {
    // General Ledger – dropdown + report lifecycle
    Route::get ('/general-ledger/accounts',                 [GeneralLedgerController::class, 'accounts']);
    Route::post('/general-ledger/report',                   [GeneralLedgerController::class, 'start']);
    Route::get ('/general-ledger/report/{ticket}/status',   [GeneralLedgerController::class, 'status']);
    Route::get ('/general-ledger/report/{ticket}/view',     [GeneralLedgerController::class, 'view']);
    Route::get ('/general-ledger/report/{ticket}/download', [GeneralLedgerController::class, 'download']);
});



Route::get('/api/debug/storage-check', function () {
    \Illuminate\Support\Facades\Storage::disk('local')->put('reports/_probe.txt', now()->toDateTimeString()."\n");
    return response()->json([
        'exists' => \Illuminate\Support\Facades\Storage::disk('local')->exists('reports/_probe.txt')
    ]);
});




Route::prefix('api')->middleware(['web','auth:sanctum'])->group(function () {
    // Trial Balance – dropdown + report lifecycle
    Route::get ('/trial-balance/accounts',                [TrialBalanceController::class, 'accounts']);
    Route::post('/trial-balance/report',                  [TrialBalanceController::class, 'start']);
    Route::get ('/trial-balance/report/{ticket}/status',  [TrialBalanceController::class, 'status']);
    Route::get ('/trial-balance/report/{ticket}/view',    [TrialBalanceController::class, 'view']);
    Route::get ('/trial-balance/report/{ticket}/download',[TrialBalanceController::class, 'download']);
});





Route::prefix('api')->middleware(['web','auth:sanctum'])->group(function () {
    Route::get   ('/references/customers',         [ReferenceCustomerController::class, 'index']);
    Route::post  ('/references/customers',         [ReferenceCustomerController::class, 'store']);
    Route::put   ('/references/customers/{id}',    [ReferenceCustomerController::class, 'update']);
    Route::delete('/references/customers/{id}',    [ReferenceCustomerController::class, 'destroy']);
    Route::get   ('/references/customers/export',  [ReferenceCustomerController::class, 'export']);

    // routes/web.php (inside your existing Route::prefix('api')->middleware(['web','auth:sanctum'])->group(...))
    Route::get   ('/references/vendors',         [\App\Http\Controllers\ReferenceVendorController::class, 'index']);
    Route::post  ('/references/vendors',         [\App\Http\Controllers\ReferenceVendorController::class, 'store']);
    Route::put   ('/references/vendors/{id}',    [\App\Http\Controllers\ReferenceVendorController::class, 'update']);
    Route::delete('/references/vendors/{id}',    [\App\Http\Controllers\ReferenceVendorController::class, 'destroy']);
    Route::get   ('/references/vendors/export',  [\App\Http\Controllers\ReferenceVendorController::class, 'export']);

    // References: Accounts (account_code)
    Route::get   ('/references/accounts',        [\App\Http\Controllers\ReferenceAccountController::class, 'index']);
    Route::post  ('/references/accounts',        [\App\Http\Controllers\ReferenceAccountController::class, 'store']);
    Route::put   ('/references/accounts/{id}',   [\App\Http\Controllers\ReferenceAccountController::class, 'update']);
    Route::delete('/references/accounts/{id}',   [\App\Http\Controllers\ReferenceAccountController::class, 'destroy']);
    Route::get   ('/references/accounts/meta',   [\App\Http\Controllers\ReferenceAccountController::class, 'meta']);
    Route::get   ('/references/accounts/next-code', [\App\Http\Controllers\ReferenceAccountController::class, 'nextCode']);

    // References: Main Accounts (account_main)
    Route::get   ('/references/account-main',        [\App\Http\Controllers\ReferenceAccountMainController::class, 'index']);
    Route::post  ('/references/account-main',        [\App\Http\Controllers\ReferenceAccountMainController::class, 'store']);
    Route::put   ('/references/account-main/{id}',   [\App\Http\Controllers\ReferenceAccountMainController::class, 'update']);
    Route::delete('/references/account-main/{id}',   [\App\Http\Controllers\ReferenceAccountMainController::class, 'destroy']);

    Route::get   ('/references/mills',       [\App\Http\Controllers\ReferenceMillController::class, 'index']);
    Route::post  ('/references/mills',       [\App\Http\Controllers\ReferenceMillController::class, 'store']);
    Route::put   ('/references/mills/{id}',  [\App\Http\Controllers\ReferenceMillController::class, 'update']);
    Route::delete('/references/mills/{id}',  [\App\Http\Controllers\ReferenceMillController::class, 'destroy']);

    // Mill rates (detail by mill)
    Route::get   ('/references/mills/{millId}/rates',                    [\App\Http\Controllers\ReferenceMillRateController::class, 'index']);
    Route::post  ('/references/mills/{millId}/rates',                    [\App\Http\Controllers\ReferenceMillRateController::class, 'store']);
    Route::put   ('/references/mills/{millId}/rates/{rateId}',           [\App\Http\Controllers\ReferenceMillRateController::class, 'update']);
    Route::delete('/references/mills/{millId}/rates/{rateId}',           [\App\Http\Controllers\ReferenceMillRateController::class, 'destroy']);
    Route::post  ('/references/mills/{millId}/rates/{rateId}/lock',      [\App\Http\Controllers\ReferenceMillRateController::class, 'lock']);
    Route::post  ('/references/mills/{millId}/rates/{rateId}/unlock',    [\App\Http\Controllers\ReferenceMillRateController::class, 'unlock']);


});





Route::prefix('api')->middleware(['web'])->group(function () {
    Route::get('/csrf-cookie', function () {
        // Force a plain, non-encrypted XSRF-TOKEN cookie via raw header
        $token  = csrf_token();
        $domain = config('session.domain'); // e.g., 10.63.10.65

        $parts = [
            'XSRF-TOKEN=' . urlencode($token), // URL-encode
            'Path=/',
            'Max-Age=7200',
            'SameSite=Lax',
        ];
        if (!empty($domain)) {
            $parts[] = 'Domain=' . $domain;
        }
        // IMPORTANT: no HttpOnly so JS can read it

        return response('', 204)->header('Set-Cookie', implode('; ', $parts));
    })->name('api.csrf-cookie');
});



/* --------------------- References (Banks) --------------------- */
Route::prefix('api')->middleware(['web','auth:sanctum'])->group(function () {
    // References: Banks
    Route::get('/references/banks',        [ReferenceBankController::class, 'index']);
    Route::post('/references/banks',       [ReferenceBankController::class, 'store']);
    Route::put('/references/banks/{id}',   [ReferenceBankController::class, 'update']);
    Route::delete('/references/banks/{id}',[ReferenceBankController::class, 'destroy']);

    // Banks export (Excel)
    Route::get('/references/banks/export', [\App\Http\Controllers\ReferenceBankController::class, 'export']);


    // Optional: visibility scaffold (replace later with real permission logic)
    Route::get('/references/visibility', function () {
        return response()->json([
            'accounts' => true,
            'banks'    => true,
            'customers'=> true,
            'mills'    => true,
            'planters' => true,
            'vendors'  => true,
        ]);
    });
});

/* --------------------- AUM (Users / Assignments) --------------------- */
/* NOTE: We REMOVED ->withoutMiddleware([VerifyCsrfToken::class])
   Sanctum now uses your VerifyCsrfToken, so your $except applies automatically. */
Route::prefix('api')->middleware(['web'])->group(function () {
    // Users (left pane)
    Route::get('/aum/users', [UsersEmployeesController::class, 'index']);
    Route::post('/aum/users', [UsersEmployeesController::class, 'store']); // Add user (modal)
    Route::patch('/aum/users/{userId}/active', [UsersEmployeesController::class, 'toggleActive']); // Activate/Deactivate

    // Static tree for Systems → Modules → Sub-modules
    Route::get('/aum/tree', [ModuleTreeController::class, 'tree']);

    // Assignments for a user (COMPANY-SCOPED: via ?company_id= on GET, and in body for POST)
    Route::get('/aum/users/{userId}/assignments', [UserAssignmentController::class, 'getAssignments']);
    Route::post('/aum/users/{userId}/assignments/diff', [UserAssignmentController::class, 'applyDiff']);
    Route::post('/aum/users/{userId}/assignments/clone', [UserAssignmentController::class, 'cloneFrom']);

    Route::get('/aum/roles', [\App\Http\Controllers\AssignUserModules\RolesController::class, 'index']);
});

/* --------------------- AUM (alt set under auth) --------------------- */
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('aum')->group(function () {
        // tree
        Route::get('/tree', [ModuleTreeController::class, 'tree']);

        // users list / create / toggle
        Route::get('/users', [UsersEmployeesController::class, 'index']);
        Route::post('/users', [UsersEmployeesController::class, 'store']);
        Route::patch('/users/{userId}/active', [UsersEmployeesController::class, 'toggleActive']);

        // assignments (company scoped)
        Route::get('/users/{userId}/assignments', [UserAssignmentController::class, 'getAssignments']);
        Route::post('/users/{userId}/assignments/diff', [UserAssignmentController::class, 'applyDiff']);
        Route::post('/users/{userId}/assignments/clone', [UserAssignmentController::class, 'cloneFrom']);
    });
});

/* --------------------- Misc / Profile --------------------- */

Route::get('/user/profile/photo', [ProfileController::class, 'photo'])->name('profile.photo');

Route::prefix('api')->middleware(['web','auth:sanctum'])->group(function () {
    Route::get('/user/profile', [UserProfileController::class, 'show'])->name('user.profile.show');
    Route::put('/user/profile', [UserProfileController::class, 'update'])->name('user.profile.update');
    Route::post('/user/profile/password', [UserProfileController::class, 'updatePassword'])->name('user.profile.password');
    Route::post('/user/profile/photo', [UserProfileController::class, 'uploadPhoto'])->name('user.profile.photo');

    // streams the image
    Route::get('/user/profile/photo', [UserProfileController::class, 'showPhoto'])->name('user.profile.photo.show');
});

/* --------------------- Health --------------------- */
Route::prefix('api')->group(function () {
    Route::get('/health', [HealthController::class, 'show']);
});
Route::get('/api/health', fn () => response()->json(['ok' => true, 'ts' => now()->toISOString()]));

/* --------------------- Public GET (no CSRF needed anyway) --------------------- */
Route::get('/api/companies', [CompanyController::class, 'index'])
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

Route::get('/api/companies/{id}', [CompanyController::class, 'show'])
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

Route::get('/api/roles/list', [RoleController::class, 'list'])
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

/* --------------------- whoami (auth check) --------------------- */
Route::get('/api/whoami', function (Request $request) {
    return response()->json([
        'id'   => optional($request->user())->id,
        'type' => $request->user() ? get_class($request->user()) : null,
    ]);
})->middleware('auth:sanctum');

/* --------------------- Protected (stateful) API --------------------- */
Route::middleware(['web'])->group(function () {
    Route::post('/api/register', [AuthController::class, 'register']);

    Route::get('/api/roles', [RoleController::class, 'index']);
    Route::post('/api/roles', [RoleController::class, 'store']);
    Route::put('/api/roles/{id}', [RoleController::class, 'update']);
    Route::delete('/api/roles/{id}', [RoleController::class, 'destroy']);

    // PBN
    Route::get('/api/pbn-entries', [PbnEntryController::class, 'index']);
    Route::post('/api/pbn-entry', [PbnEntryController::class, 'store']);
    //Route::post('/api/pbn/save-main', [PbnEntryController::class, 'storeMain']);
    //Route::post('/api/pbn/save-detail', [PbnEntryController::class, 'saveDetail']);
    //Route::post('/api/pbn/update-detail', [PbnEntryController::class, 'updateDetail']);
    //Route::post('/api/pbn/delete-detail', [PbnEntryController::class, 'deleteDetailAndLog']);
    //Route::get('/api/pbn/dropdown-list', [PbnEntryController::class, 'getPbnDropdownList']);
    Route::get('/api/id/{id}', [PbnEntryController::class, 'show']);

    // Mills
    Route::get('/api/mills', [MillListController::class, 'index']);
    Route::get('/api/mills/effective', [MillListController::class, 'effective']);
    Route::post('/api/mills', [MillListController::class, 'store']);
    Route::put('/api/mills/{id}', [MillListController::class, 'update']);
    Route::delete('/api/mills/{id}', [MillRateHistoryController::class, 'destroy']);

    // Rate history
    Route::get('/api/mill-rates', [MillRateHistoryController::class, 'index']);
    Route::post('/api/mill-rates', [MillRateHistoryController::class, 'store']);
    Route::put('/api/mill-rates/{id}', [MillRateHistoryController::class, 'update']);
    Route::delete('/api/mill-rates/{id}', [MillRateHistoryController::class, 'destroy']);

    // Dropdowns
    //Route::get('/api/sugar-types', [SugarTypeController::class, 'index']);
    //Route::get('/api/crop-years', [CropYearController::class, 'index']);
    //Route::get('/api/vendors', [VendorListController::class, 'index']);

    // Auto-generated PBN Number
    //Route::get('/api/pbn/generate-pbn-number', [ApplicationSettingsController::class, 'getNextPbnNumber']);

    // Forms
    Route::get('/api/pbn/form-pdf/{id?}', [PbnEntryController::class, 'formPdf']);
    //Route::get('/api/pbn/form-excel/{id}', [PbnEntryController::class, 'formExcel']);

    // Token-protected endpoint
    Route::get('/api/user/modules', [ModuleAccessController::class, 'userModules'])
        ->middleware('auth:sanctum');

    Route::post('/api/logout', [AuthController::class, 'logout']);
    Route::get('/api/settings/{code}', [ApplicationSettingsController::class, 'getSetting']);

    // Receiving
    Route::get('/api/receiving/rr-list', [ReceivingController::class, 'rrList']);
    Route::get('/api/receiving/entry', [ReceivingController::class, 'getReceivingEntry']);
    Route::get('/api/receiving/details', [ReceivingController::class, 'getReceivingDetails']);
    Route::post('/api/receiving/batch-insert', [ReceivingController::class, 'batchInsertDetails']);
    Route::post('/api/receiving/update-flag', [ReceivingController::class, 'updateFlag']);
    Route::post('/api/receiving/update-date', [ReceivingController::class, 'updateDate']);
    Route::post('/api/receiving/update-gl', [ReceivingController::class, 'updateGL']);
    Route::post('/api/receiving/update-assoc-others', [ReceivingController::class, 'updateAssocOthers']);
    Route::post('/api/receiving/update-mill', [ReceivingController::class, 'updateMillName']);

    // helpers reused from PBN & mills
    Route::get('/receiving/pbn-item', [ReceivingController::class, 'pbnItemForReceiving']);
    Route::get('/mills/rate', [ReceivingController::class, 'millRateAsOf']);

    Route::get('/api/pbn/list', [PbnController::class, 'list']);
    //Route::get('/api/pbn/items', [PbnController::class, 'items']);
    Route::post('/api/receiving/create-entry', [ReceivingController::class, 'createEntry']);

Route::get('/api/receiving/pricing-context', [ReceivingController::class, 'pricingContext']);
Route::get('/api/receiving/mills', [ReceivingController::class, 'millList']);
Route::get('/api/receiving/receiving-report-pdf/{receiptNo}', [\App\Http\Controllers\ReceivingController::class, 'receivingReportPdf']);
Route::get('/api/receiving/quedan-listing-pdf/{receiptNo}', [ReceivingController::class, 'quedanListingPdf']);
Route::get('/api/receiving/quedan-listing-inssto-pdf/{receiptNo}', [ReceivingController::class, 'quedanListingInsStoPdf']);
Route::get('/api/receiving/quedan-listing-excel/{receiptNo}', [ReceivingController::class, 'quedanListingExcel']);
Route::get('/api/receiving/quedan-listing-insurance-storage-excel/{receiptNo?}', [ReceivingController::class, 'quedanListingInsuranceStorageExcel']);

// Sales Journal
    Route::get('/api/sales/generate-cs-number', [SalesJournalController::class, 'generateCsNumber']);
    Route::post('/api/sales/save-main', [SalesJournalController::class, 'storeMain']);
    Route::post('/api/sales/save-detail', [SalesJournalController::class, 'saveDetail']);
    Route::post('/api/sales/update-detail', [SalesJournalController::class, 'updateDetail']);
    Route::post('/api/sales/delete-detail', [SalesJournalController::class, 'deleteDetail']);
    Route::get('/api/sales/list', [SalesJournalController::class, 'list']);
    Route::get('/api/sales/{id}', [SalesJournalController::class, 'show'])->whereNumber('id');
    Route::post('/api/sales/cancel', [SalesJournalController::class, 'updateCancel']);

    Route::post('/api/sales/delete-main', [SalesJournalController::class, 'softDeleteMain']);

    
    // Dropdowns
    Route::get('/api/customers', [SalesJournalController::class, 'customers']);
    Route::get('/api/accounts', [SalesJournalController::class, 'accounts']);

    // Print/download
    Route::get('/api/sales/form-pdf/{id}', [SalesJournalController::class, 'formPdf']);
    Route::get('/api/sales/check-pdf/{id}', [SalesJournalController::class, 'checkPdf']);
    Route::get('/api/sales/form-excel/{id}', [SalesJournalController::class, 'formExcel']);

    Route::get('/api/sales/unbalanced-exists', [SalesJournalController::class, 'unbalancedExists']);
    Route::get('/api/sales/unbalanced', [SalesJournalController::class, 'unbalanced']);

    // ----------------------------
    // Sales Journal – Approval Flow
    // ----------------------------

    // Request approval for editing a Sales Journal record
    Route::post('/api/sales/request-edit', [SalesJournalController::class, 'requestEdit']);

    // Get approval status for a specific record
    Route::get('/api/sales/approval-status', [SalesJournalController::class, 'approvalStatus']);

    // Release an active approval token after successful save
    Route::post('/api/sales/release-edit', [SalesJournalController::class, 'releaseEdit']);
    Route::post('/api/sales/save-main',   [SalesJournalController::class, 'storeMain']);
    Route::post('/api/sales/update-main', [SalesJournalController::class, 'updateMain']); // 👈 add this
    Route::post('/api/sales/update-main-no-approval', [SalesJournalController::class, 'updateMainNoApproval']
    );



    // Cash Receipts
    Route::get('/api/cash-receipt/generate-cr-number', [CashReceiptController::class, 'generateCrNumber']);
    Route::post('/api/cash-receipt/save-main', [CashReceiptController::class, 'storeMain']);
    Route::post('/api/cash-receipt/save-detail', [CashReceiptController::class, 'saveDetail']);
    Route::post('/api/cash-receipt/update-detail', [CashReceiptController::class, 'updateDetail']);
    Route::post('/api/cash-receipt/delete-detail', [CashReceiptController::class, 'deleteDetail']);
    Route::get('/api/cash-receipt/list', [CashReceiptController::class, 'list']);
    Route::get('/api/cash-receipt/{id}', [CashReceiptController::class, 'show'])->whereNumber('id');
    Route::post('/api/cash-receipt/cancel', [CashReceiptController::class, 'updateCancel']);

    // Dropdowns
    Route::get('/api/cr/customers', [CashReceiptController::class, 'customers']);
    Route::get('/api/cr/accounts', [CashReceiptController::class, 'accounts']);
    Route::get('/api/cr/banks', [CashReceiptController::class, 'banks']);
    Route::get('/api/cr/payment-methods', [CashReceiptController::class, 'paymentMethods']);

    // Print/download
    Route::get('/api/cash-receipt/form-pdf/{id}', [CashReceiptController::class, 'formPdf']);
    Route::get('/api/cash-receipt/form-excel/{id}', [CashReceiptController::class, 'formExcel']);

    // Unbalanced helpers
    Route::get('/api/cash-receipt/unbalanced-exists', [CashReceiptController::class, 'unbalancedExists']);
    Route::get('/api/cash-receipt/unbalanced', [CashReceiptController::class, 'unbalanced']);

// ----------------------------
// Cash Receipt – Approval Flow
// ----------------------------

Route::post('/api/cash-receipt/update-main', [CashReceiptController::class, 'updateMain']);
Route::post('/api/cash-receipt/update-main-no-approval', [CashReceiptController::class, 'updateMainNoApproval']);



    // Purchase Journal
    Route::get('/api/purchase/generate-cp-number', [PurchaseJournalController::class, 'generateCpNumber']);
    Route::post('/api/purchase/save-main', [PurchaseJournalController::class, 'storeMain']);
    Route::post('/api/purchase/save-detail', [PurchaseJournalController::class, 'saveDetail']);
    Route::post('/api/purchase/update-detail', [PurchaseJournalController::class, 'updateDetail']);
    Route::post('/api/purchase/delete-detail', [PurchaseJournalController::class, 'deleteDetail']);
    Route::delete('/api/purchase/{id}', [PurchaseJournalController::class, 'destroy']);
    Route::get('/api/purchase/list', [PurchaseJournalController::class, 'list']);
    Route::get('/api/purchase/{id}', [PurchaseJournalController::class, 'show'])->whereNumber('id');
    Route::post('/api/purchase/cancel', [PurchaseJournalController::class, 'updateCancel']);

    // Dropdowns
    Route::get('/api/pj/vendors', [PurchaseJournalController::class, 'vendors']);
    Route::get('/api/pj/accounts', [PurchaseJournalController::class, 'accounts']);
    Route::get('/api/pj/mills', [PurchaseJournalController::class, 'mills']);

    // Print/download
    Route::get('/api/purchase/form-pdf/{id}', [PurchaseJournalController::class, 'formPdf']);
    Route::get('/api/purchase/check-pdf/{id}', [PurchaseJournalController::class, 'checkPdf']);
    Route::get('/api/purchase/form-excel/{id}', [PurchaseJournalController::class, 'formExcel']);

    // Unbalanced helpers
    Route::get('/api/purchase/unbalanced-exists', [PurchaseJournalController::class, 'unbalancedExists']);
    Route::get('/api/purchase/unbalanced', [PurchaseJournalController::class, 'unbalanced']);
    Route::post('/api/purchase/update-main', [PurchaseJournalController::class, 'updateMain']);
Route::post('/api/purchase/update-main-no-approval', [PurchaseJournalController::class, 'updateMainNoApproval']);

// ✅ ROUTE EXAMPLE (routes/web.php or routes/api.php depending on your current setup)
Route::get('/api/purchase-journal/check-pdf/{id}', [PurchaseJournalController::class, 'checkPdf']);

    // Cash Disbursement
    Route::get('/api/cash-disbursement/generate-cd-number', [\App\Http\Controllers\CashDisbursementController::class, 'generateCdNumber']);
    Route::post('/api/cash-disbursement/save-main', [\App\Http\Controllers\CashDisbursementController::class, 'storeMain']);
    Route::post('/api/cash-disbursement/save-detail', [\App\Http\Controllers\CashDisbursementController::class, 'saveDetail']);
    Route::post('/api/cash-disbursement/update-detail', [\App\Http\Controllers\CashDisbursementController::class, 'updateDetail']);
    Route::post('/api/cash-disbursement/delete-detail', [\App\Http\Controllers\CashDisbursementController::class, 'deleteDetail']);
    Route::delete('/api/cash-disbursement/{id}', [\App\Http\Controllers\CashDisbursementController::class, 'destroy']);
    Route::get('/api/cash-disbursement/list', [\App\Http\Controllers\CashDisbursementController::class, 'list']);
    Route::get('/api/cash-disbursement/{id}', [\App\Http\Controllers\CashDisbursementController::class, 'show'])->whereNumber('id');
    //Route::post('/api/cash-disbursement/cancel', [\App\Http\Controllers\CashDisbursementController::class, 'updateCancel']);

    // Dropdowns
    Route::get('/api/cd/vendors', [\App\Http\Controllers\CashDisbursementController::class, 'vendors']);
    Route::get('/api/cd/accounts', [\App\Http\Controllers\CashDisbursementController::class, 'accounts']);
    Route::get('/api/cd/banks', [\App\Http\Controllers\CashDisbursementController::class, 'banks']);
    Route::get('/api/cd/payment-methods', [\App\Http\Controllers\CashDisbursementController::class, 'paymentMethods']);

    // Print/download
    Route::get('/api/cash-disbursement/form-pdf/{id}', [\App\Http\Controllers\CashDisbursementController::class, 'formPdf']);
    Route::get('/api/cash-disbursement/form-excel/{id}', [\App\Http\Controllers\CashDisbursementController::class, 'formExcel']);

    // Unbalanced helpers
    Route::get('/api/cash-disbursement/unbalanced-exists', [\App\Http\Controllers\CashDisbursementController::class, 'unbalancedExists']);
    Route::get('/api/cash-disbursement/unbalanced', [\App\Http\Controllers\CashDisbursementController::class, 'unbalanced']);
Route::post('/api/cash-disbursement/update-main', [\App\Http\Controllers\CashDisbursementController::class, 'updateMain']);
Route::post('/api/cash-disbursement/update-main-no-approval', [\App\Http\Controllers\CashDisbursementController::class, 'updateMainNoApproval']);


});


Route::prefix('api')->middleware(['web','auth:sanctum'])->group(function () {
    // --- General Accounting (Journal Entry) ---

    // combobox + search
    Route::get('/ga/accounts', [\App\Http\Controllers\GeneralAccountingController::class, 'accounts']);
    Route::get('/ga/list',     [\App\Http\Controllers\GeneralAccountingController::class, 'list']);

    // create header + validations/helpers
    Route::get ('/ga/generate-ga-number', [\App\Http\Controllers\GeneralAccountingController::class, 'generateGaNumber']);
    Route::post('/ga/save-main',          [\App\Http\Controllers\GeneralAccountingController::class, 'storeMain']);
    Route::get ('/ga/unbalanced-exists',  [\App\Http\Controllers\GeneralAccountingController::class, 'unbalancedExists']);
    Route::get ('/ga/unbalanced',         [\App\Http\Controllers\GeneralAccountingController::class, 'unbalanced']);

    // details CRUD
    Route::post('/ga/save-detail',   [\App\Http\Controllers\GeneralAccountingController::class, 'saveDetail']);
    Route::post('/ga/update-detail', [\App\Http\Controllers\GeneralAccountingController::class, 'updateDetail']);
    Route::post('/ga/delete-detail', [\App\Http\Controllers\GeneralAccountingController::class, 'deleteDetail']);

    // cancel/uncancel, delete main
    Route::post  ('/ga/cancel', [\App\Http\Controllers\GeneralAccountingController::class, 'updateCancel']);
    Route::delete('/ga/{id}',   [\App\Http\Controllers\GeneralAccountingController::class, 'destroy'])->whereNumber('id');

    // reports
    //Route::get('/ga/form-pdf/{id}',   [\App\Http\Controllers\GeneralAccountingController::class, 'formPdf'])->whereNumber('id');
    //Route::get('/ga/form-excel/{id}', [\App\Http\Controllers\GeneralAccountingController::class, 'formExcel'])->whereNumber('id');

    // show must come AFTER the literal routes above to avoid collisions
    Route::get('/ga/{id}', [\App\Http\Controllers\GeneralAccountingController::class, 'show'])->whereNumber('id');

    Route::post('/ga/update-main', [\App\Http\Controllers\GeneralAccountingController::class, 'updateMain']);
    Route::post('/ga/update-main-no-approval', [\App\Http\Controllers\GeneralAccountingController::class, 'updateMainNoApproval']);


});

// ... your existing group:
Route::prefix('api')->middleware(['web','auth:sanctum'])->group(function () {
    // GA routes (keep all the CRUD/list/etc. here)
    // ...
    // DO NOT put form-pdf/form-excel here
});

// --- reports: web only (no auth:sanctum redirect) ---
Route::prefix('api')->middleware(['web'])->group(function () {
    Route::get('/ga/form-pdf/{id}',   [\App\Http\Controllers\GeneralAccountingController::class, 'formPdf'])->whereNumber('id');
    Route::get('/ga/form-excel/{id}', [\App\Http\Controllers\GeneralAccountingController::class, 'formExcel'])->whereNumber('id');
});





/* --------------------- Stateless login --------------------- */
Route::post('/api/login', [AuthController::class, 'login'])->withoutMiddleware([
    \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
]);

/* --------------------- Canary --------------------- */
Route::get('/api/ping', fn () => response()->json(['ok' => true, 'at' => now()->toISOString()]));

/* --------------------- SPA catch-all --------------------- */
Route::get('{any}', function () {
    return File::get(public_path('index.html'));
})->where('any', '.*');

Route::get('/login', function (\Illuminate\Http\Request $r) {
    return response()->json(['message' => 'Login required'], 401);
})->name('login');

/* --------------------- whoami (duplicate short) --------------------- */
Route::prefix("api")->middleware(["web","auth:sanctum"])->get("/whoami", function () {
    return response()->json(auth()->user());
});
