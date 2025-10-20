<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Response;
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
use App\Http\Controllers\ReceivingController;
use App\Http\Controllers\PbnController;
use App\Http\Controllers\SalesJournalController;
use App\Http\Controllers\CashReceiptController;
use App\Http\Controllers\PurchaseJournalController;
// ✅ No need to override Sanctum’s built-in CSRF route
// ❌ Remove this:
// Route::get('/sanctum/csrf-cookie', function () {
//     return response()->noContent();
// });

use App\Http\Controllers\HealthController;

// --- API health (JSON) ---
Route::prefix('api')->group(function () {
    Route::get('/health', [HealthController::class, 'show']);
});

// ✅ Public GET routes that don’t need CSRF protection
Route::get('/api/companies', [CompanyController::class, 'index'])->withoutMiddleware([
    \App\Http\Middleware\VerifyCsrfToken::class,
]);

Route::get('/api/companies/{id}', [CompanyController::class, 'show'])->withoutMiddleware([
    \App\Http\Middleware\VerifyCsrfToken::class,
]);


Route::get('/api/roles/list', [RoleController::class, 'list'])->withoutMiddleware([
    \App\Http\Middleware\VerifyCsrfToken::class,
]);



// ✅ Protected routes for POST (with CSRF + Sanctum stateful)
Route::middleware(['web'])->group(function () {
    Route::post('/api/register', [AuthController::class, 'register']);
    
    Route::get('/api/roles', [RoleController::class, 'index']);
    Route::post('/api/roles', [RoleController::class, 'store']);
    Route::put('/api/roles/{id}', [RoleController::class, 'update']);
    Route::delete('/api/roles/{id}', [RoleController::class, 'destroy']);    


    // 🟢 Purchase Book Note Entry CRUD
    Route::get('/api/pbn-entries', [PbnEntryController::class, 'index']);
    Route::post('/api/pbn-entry', [PbnEntryController::class, 'store']);
    Route::post('/api/pbn/save-main', [PbnEntryController::class, 'storeMain']);
    
    Route::post('/api/pbn/save-detail', [PbnEntryController::class, 'saveDetail']);
    Route::post('/api/pbn/update-detail', [PbnEntryController::class, 'updateDetail']);    
    Route::post('/api/pbn/delete-detail', [PbnEntryController::class, 'deleteDetailAndLog']);
    Route::get('/api/pbn/dropdown-list', [PbnEntryController::class, 'getPbnDropdownList']);
    Route::get('/api/id/{id}', [PbnEntryController::class, 'show']);

   
    
    Route::get('/api/mills', [MillListController::class, 'index']);

    Route::get('/api/mills/effective',  [MillListController::class, 'effective']);

    // Optional CRUD for mills
    Route::post('/api/mills',           [MillListController::class, 'store']);
    Route::put('/api/mills/{id}',       [MillListController::class, 'update']);
    Route::delete('/api/mills/{id}',    [MillListController::class, 'destroy']);

    // Rate history CRUD + query
    Route::get('/api/mill-rates',            [MillRateHistoryController::class, 'index']);
    Route::post('/api/mill-rates',           [MillRateHistoryController::class, 'store']);
    Route::put('/api/mill-rates/{id}',       [MillRateHistoryController::class, 'update']);
    Route::delete('/api/mill-rates/{id}',    [MillRateHistoryController::class, 'destroy']);

    // 🟢 Sugar Types Dropdown
    Route::get('/api/sugar-types', [SugarTypeController::class, 'index']);

    // 🟢 Crop Years Dropdown
    Route::get('/api/crop-years', [CropYearController::class, 'index']);

    // 🟢 Vendor List Dropdown
    Route::get('/api/vendors', [VendorListController::class, 'index']);

    // 🟢 Auto-generated PBN Number from settings
    //Route::get('/api/settings/PBNNO', [ApplicationSettingsController::class, 'getPbnNumber']);
    Route::get('/api/pbn/generate-pbn-number', [ApplicationSettingsController::class, 'getNextPbnNumber']);


    Route::get('/api/pbn/form-pdf/{id?}', [\App\Http\Controllers\PbnEntryController::class, 'formPdf']);
    Route::get('/api/pbn/form-excel/{id}', [PbnEntryController::class, 'formExcel']);



    Route::post('/api/login', [AuthController::class, 'login']);
    Route::get('/api/user/modules', [ModuleAccessController::class, 'userModules']);

    Route::post('/api/logout', [AuthController::class, 'logout']);

    Route::get('/api/settings/{code}', [ApplicationSettingsController::class, 'getSetting']);


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
    Route::get('/api/receiving/pbn-item', [ReceivingController::class, 'pbnItemForReceiving']);
    Route::get('/api/mills/rate', [ReceivingController::class, 'millRateAsOf']);

    Route::get('/api/pbn/list', [PbnController::class, 'list']);   // PBN dropdown
    Route::get('/api/pbn/items', [PbnController::class, 'items']); // Item# dropdown, depends on pbn_number

    Route::post('/api/receiving/create-entry', [ReceivingController::class, 'createEntry']);


    // Sales Journal (Cash Sales)
    Route::get('/api/sales/generate-cs-number', [SalesJournalController::class, 'generateCsNumber']);
    Route::post('/api/sales/save-main',          [SalesJournalController::class, 'storeMain']);
    Route::post('/api/sales/save-detail',        [SalesJournalController::class, 'saveDetail']);
    Route::post('/api/sales/update-detail',      [SalesJournalController::class, 'updateDetail']);
    Route::post('/api/sales/delete-detail',      [SalesJournalController::class, 'deleteDetail']);
    Route::get('/api/sales/list',                [SalesJournalController::class, 'list']);
    Route::get('/api/sales/{id}',                [SalesJournalController::class, 'show'])
        ->whereNumber('id');
    Route::post('/api/sales/cancel',             [SalesJournalController::class, 'updateCancel']);

    // dropdowns
    Route::get('/api/customers', [SalesJournalController::class, 'customers']);
    Route::get('/api/accounts',  [SalesJournalController::class, 'accounts']);

    // print/download
    Route::get('/api/sales/form-pdf/{id}',  [SalesJournalController::class, 'formPdf']);
    Route::get('/api/sales/check-pdf/{id}', [SalesJournalController::class, 'checkPdf']);
    Route::get('/api/sales/form-excel/{id}',[SalesJournalController::class, 'formExcel']);

    Route::get('/api/sales/unbalanced-exists', [SalesJournalController::class, 'unbalancedExists']);
    Route::get('/api/sales/unbalanced',       [SalesJournalController::class, 'unbalanced']);

    // Cash Receipts
    Route::get('/api/cash-receipt/generate-cr-number', [CashReceiptController::class, 'generateCrNumber']);
    Route::post('/api/cash-receipt/save-main',          [CashReceiptController::class, 'storeMain']);
    Route::post('/api/cash-receipt/save-detail',        [CashReceiptController::class, 'saveDetail']);
    Route::post('/api/cash-receipt/update-detail',      [CashReceiptController::class, 'updateDetail']);
    Route::post('/api/cash-receipt/delete-detail',      [CashReceiptController::class, 'deleteDetail']);
    Route::get('/api/cash-receipt/list',                [CashReceiptController::class, 'list']);
    Route::get('/api/cash-receipt/{id}',                [CashReceiptController::class, 'show'])->whereNumber('id');
    Route::post('/api/cash-receipt/cancel',             [CashReceiptController::class, 'updateCancel']);

    // dropdowns
    Route::get('/api/cr/customers',        [CashReceiptController::class, 'customers']);
    Route::get('/api/cr/accounts',         [CashReceiptController::class, 'accounts']);
    Route::get('/api/cr/banks',            [CashReceiptController::class, 'banks']);
    Route::get('/api/cr/payment-methods',  [CashReceiptController::class, 'paymentMethods']);

    // print/download
    Route::get('/api/cash-receipt/form-pdf/{id}',  [CashReceiptController::class, 'formPdf']);
    Route::get('/api/cash-receipt/form-excel/{id}',[CashReceiptController::class, 'formExcel']);

    // unbalanced helpers (optional)
    Route::get('/api/cash-receipt/unbalanced-exists', [CashReceiptController::class, 'unbalancedExists']);
    Route::get('/api/cash-receipt/unbalanced',        [CashReceiptController::class, 'unbalanced']);


    // Purchase Journal (Cash Purchase)
    Route::get('/api/purchase/generate-cp-number', [PurchaseJournalController::class, 'generateCpNumber']);
    Route::post('/api/purchase/save-main',          [PurchaseJournalController::class, 'storeMain']);
    Route::post('/api/purchase/save-detail',        [PurchaseJournalController::class, 'saveDetail']);
    Route::post('/api/purchase/update-detail',      [PurchaseJournalController::class, 'updateDetail']);
    Route::post('/api/purchase/delete-detail',      [PurchaseJournalController::class, 'deleteDetail']);
    Route::delete('/api/purchase/{id}',             [PurchaseJournalController::class, 'destroy']);
    Route::get('/api/purchase/list',                [PurchaseJournalController::class, 'list']);
    Route::get('/api/purchase/{id}',                [PurchaseJournalController::class, 'show'])->whereNumber('id');
    Route::post('/api/purchase/cancel',             [PurchaseJournalController::class, 'updateCancel']);

    // dropdowns
    Route::get('/api/pj/vendors',  [PurchaseJournalController::class, 'vendors']);
    Route::get('/api/pj/accounts', [PurchaseJournalController::class, 'accounts']);

    // print/download
    Route::get('/api/purchase/form-pdf/{id}',  [PurchaseJournalController::class, 'formPdf']);
    Route::get('/api/purchase/check-pdf/{id}', [PurchaseJournalController::class, 'checkPdf']);
    Route::get('/api/purchase/form-excel/{id}',[PurchaseJournalController::class, 'formExcel']);

    // unbalanced helpers
    Route::get('/api/purchase/unbalanced-exists', [PurchaseJournalController::class, 'unbalancedExists']);
    Route::get('/api/purchase/unbalanced',        [PurchaseJournalController::class, 'unbalanced']);

    // Cash Disbursement
    Route::get('/api/cash-disbursement/generate-cd-number', [\App\Http\Controllers\CashDisbursementController::class, 'generateCdNumber']);
    Route::post('/api/cash-disbursement/save-main',          [\App\Http\Controllers\CashDisbursementController::class, 'storeMain']);
    Route::post('/api/cash-disbursement/save-detail',        [\App\Http\Controllers\CashDisbursementController::class, 'saveDetail']);
    Route::post('/api/cash-disbursement/update-detail',      [\App\Http\Controllers\CashDisbursementController::class, 'updateDetail']);
    Route::post('/api/cash-disbursement/delete-detail',      [\App\Http\Controllers\CashDisbursementController::class, 'deleteDetail']);
    Route::delete('/api/cash-disbursement/{id}',             [\App\Http\Controllers\CashDisbursementController::class, 'destroy']);
    Route::get('/api/cash-disbursement/list',                [\App\Http\Controllers\CashDisbursementController::class, 'list']);
    Route::get('/api/cash-disbursement/{id}',                [\App\Http\Controllers\CashDisbursementController::class, 'show'])->whereNumber('id');
    Route::post('/api/cash-disbursement/cancel',             [\App\Http\Controllers\CashDisbursementController::class, 'updateCancel']);

    // dropdowns
    Route::get('/api/cd/vendors',         [\App\Http\Controllers\CashDisbursementController::class, 'vendors']);
    Route::get('/api/cd/accounts',        [\App\Http\Controllers\CashDisbursementController::class, 'accounts']);
    Route::get('/api/cd/banks',           [\App\Http\Controllers\CashDisbursementController::class, 'banks']);
    Route::get('/api/cd/payment-methods', [\App\Http\Controllers\CashDisbursementController::class, 'paymentMethods']);

    // print/download
    Route::get('/api/cash-disbursement/form-pdf/{id}',   [\App\Http\Controllers\CashDisbursementController::class, 'formPdf']);
    Route::get('/api/cash-disbursement/form-excel/{id}', [\App\Http\Controllers\CashDisbursementController::class, 'formExcel']);

    // unbalanced helpers
    Route::get('/api/cash-disbursement/unbalanced-exists', [\App\Http\Controllers\CashDisbursementController::class, 'unbalancedExists']);
    Route::get('/api/cash-disbursement/unbalanced',        [\App\Http\Controllers\CashDisbursementController::class, 'unbalanced']);


    // General Accounting (Journal Entry)
    Route::get('/api/ga/generate-ga-number', [\App\Http\Controllers\GeneralAccountingController::class, 'generateGaNumber']);
    Route::post('/api/ga/save-main',          [\App\Http\Controllers\GeneralAccountingController::class, 'storeMain']);

    Route::post('/api/ga/save-detail',        [\App\Http\Controllers\GeneralAccountingController::class, 'saveDetail']);
    Route::post('/api/ga/update-detail',      [\App\Http\Controllers\GeneralAccountingController::class, 'updateDetail']);
    Route::post('/api/ga/delete-detail',      [\App\Http\Controllers\GeneralAccountingController::class, 'deleteDetail']);

    Route::delete('/api/ga/{id}',             [\App\Http\Controllers\GeneralAccountingController::class, 'destroy']);
    Route::get('/api/ga/list',                [\App\Http\Controllers\GeneralAccountingController::class, 'list']);
    Route::get('/api/ga/{id}',                [\App\Http\Controllers\GeneralAccountingController::class, 'show'])->whereNumber('id');
    Route::post('/api/ga/cancel',             [\App\Http\Controllers\GeneralAccountingController::class, 'updateCancel']);

    // dropdowns
    Route::get('/api/ga/accounts',  [\App\Http\Controllers\GeneralAccountingController::class, 'accounts']);

    // print/download
    Route::get('/api/ga/form-pdf/{id}',   [\App\Http\Controllers\GeneralAccountingController::class, 'formPdf']);
    Route::get('/api/ga/form-excel/{id}', [\App\Http\Controllers\GeneralAccountingController::class, 'formExcel']);

    // unbalanced helpers
    Route::get('/api/ga/unbalanced-exists', [\App\Http\Controllers\GeneralAccountingController::class, 'unbalancedExists']);
    Route::get('/api/ga/unbalanced',        [\App\Http\Controllers\GeneralAccountingController::class, 'unbalanced']);





});

Route::get('{any}', function () {
    return File::get(public_path('index.html'));
})->where('any', '.*');