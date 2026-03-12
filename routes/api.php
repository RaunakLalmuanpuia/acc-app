<?php

/*
|=============================================================================
| api.php  —  AI Accounting Chat Routes
|=============================================================================
|
| These routes are auto-loaded by Laravel from routes/api.php and are
| prefixed with /api automatically (configured in bootstrap/app.php).
|
| Authentication: Laravel Sanctum (installed via `php artisan install:api`)
|
| To get a Sanctum token, first wire up a login route, e.g.:
|
|   Route::post('/login', function (Request $request) {
|       $request->validate(['email' => 'required', 'password' => 'required']);
|       $user = \App\Models\User::where('email', $request->email)->firstOrFail();
|       if (! \Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
|           return response()->json(['message' => 'Invalid credentials'], 401);
|       }
|       return response()->json([
|           'token' => $user->createToken('api-token')->plainTextToken,
|       ]);
|   });
|
| Then include in every request:
|   Authorization : Bearer <token>
|   Accept        : application/json
|
|=============================================================================
*/
use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\AiChatApiController;
use App\Http\Controllers\Api\Banking\BankTransactionApiController;
use App\Http\Controllers\Api\Banking\EmailIngestApiController;
use App\Http\Controllers\Api\Banking\NarrationReviewApiController;
use App\Http\Controllers\Api\Banking\SmsIngestApiController;
use App\Http\Controllers\Api\Banking\StatementUploadApiController;
use App\Http\Controllers\Api\Banking\UserBankAccountApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|-----------------------------------------------------------------------------
| Sanctum: Authenticated User Info
|-----------------------------------------------------------------------------
| Built-in route provided by `php artisan install:api`.
| GET /api/user  → returns the currently authenticated user as JSON.
|
| Useful to verify your Bearer token is working in Postman before testing
| the chat routes.
|
| Test in Postman:
|   GET /api/user
|   Authorization: Bearer <token>
|   → { "id": 1, "name": "John", "email": "john@example.com", ... }
|-----------------------------------------------------------------------------
*/

Route::group(['prefix' => 'auth'], function () {
    Route::post('login', [RegisterController::class, 'login']);
    Route::delete('logout', [RegisterController::class, 'logout'])->middleware('auth:sanctum');
});



Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});


/*
|=============================================================================
| GROUP: AI Accounting Chat
|=============================================================================
|
| Prefix    : /api/accounting/chat
| Middleware : auth:sanctum  — every request must have a valid Bearer token
|
| Endpoints:
|   POST /api/accounting/chat           → Send a chat message
|   POST /api/accounting/chat/confirm   → Confirm a HITL-pending action
|
|=============================================================================
*/
Route::middleware(['auth:sanctum'])
    ->prefix('accounting/chat')
    ->group(function () {

        /*
        |---------------------------------------------------------------------
        | POST /api/accounting/chat
        |---------------------------------------------------------------------
        | Sends a user message to the AI accounting assistant.
        |
        | Body (JSON — no files):
        |   {
        |     "message":         "Show all unpaid invoices",
        |     "conversation_id": null
        |   }
        |
        | Body (form-data — with files):
        |   message          [Text] "Summarize this document"
        |   conversation_id  [Text] (UUID or leave empty)
        |   attachments[]    [File] pick file  ← brackets required in key name
        |
        | Response 200 (normal):
        |   { "status":"ok", "reply":"...", "conversation_id":"uuid",
        |     "hitl_pending":false, "pending_id":null }
        |
        | Response 200 (HITL triggered — needs confirmation):
        |   { "status":"ok", "reply":"⚠️ Warning...", "conversation_id":"uuid",
        |     "hitl_pending":true, "pending_id":"uuid" }
        |---------------------------------------------------------------------
        */
        Route::post('/', [AiChatApiController::class, 'send'])
            ->name('api.accounting.chat.send');


        /*
        |---------------------------------------------------------------------
        | POST /api/accounting/chat/confirm
        |---------------------------------------------------------------------
        | Confirms and executes a HITL-pending destructive action.
        | Only call this when send() returned { hitl_pending: true }.
        |
        | Body (JSON):
        |   {
        |     "pending_id":      "7c9e6679-7425-40de-944b-e07fc1f90ae7",
        |     "conversation_id": "550e8400-e29b-41d4-a716-446655440000"
        |   }
        |
        | Response 200:
        |   { "status":"ok", "reply":"Done. Deleted 3 invoices.",
        |     "conversation_id":"uuid", "hitl_pending":false, "pending_id":null }
        |
        | To CANCEL instead: discard the pending_id. No API call needed.
        |---------------------------------------------------------------------
        */
        Route::post('/confirm', [AiChatApiController::class, 'confirm'])
            ->name('api.accounting.chat.confirm');

    });

/*
|--------------------------------------------------------------------------
| Banking Utility APIs
|--------------------------------------------------------------------------
|
| These endpoints provide helper utilities required by banking workflows.
| The most common use case is obtaining a valid bank_account_id that can
| be used in other banking APIs such as:
|
|   • Importing SMS transactions
|   • Uploading bank statements
|   • Reviewing AI narration suggestions
|   • Reconciling invoices
|
*/

Route::middleware(['auth:sanctum'])
    ->prefix('banking')
    ->group(function () {

        // =========================================================================
        // GET /api/banking/user/first-bank-account
        // =========================================================================
        /*
         | Returns the authenticated user's FIRST company and FIRST bank account.
         |
         | This endpoint is mainly used by:
         |
         |   • Postman collections
         |   • Frontend initialization
         |   • AI agents
         |   • Automated banking workflows
         |
         | so they can retrieve a valid `bank_account_id` before calling
         | other banking APIs.
         |
         | -------------------------------------------------------------------------
         | AUTHENTICATION
         | -------------------------------------------------------------------------
         | Authorization : Bearer <sanctum-token>
         | Accept        : application/json
         |
         | -------------------------------------------------------------------------
         | RESPONSE
         | -------------------------------------------------------------------------
         |
         | 200 — Success
         |
         | {
         |   "status": "ok",
         |   "company_id": 1,
         |   "bank_account_id": 3
         | }
         |
         | 404 — User has no company
         | 404 — Company has no bank account
         |
         | -------------------------------------------------------------------------
         | POSTMAN USAGE
         | -------------------------------------------------------------------------
         |
         | Method : GET
         | URL    : {{base_url}}/api/banking/user/first-bank-account
         |
         | Tests script:
         |
         | let response = pm.response.json();
         |
         | pm.environment.set("company_id", response.company_id);
         | pm.environment.set("bank_account_id", response.bank_account_id);
         |
         | After this request you can use:
         |
         |   {{bank_account_id}}
         |
         | in all other banking API requests.
         |
         */
        Route::get(
            '/user/first-bank-account',
            [UserBankAccountApiController::class, 'firstBankAccount']
        );

    });


/*
|=============================================================================
| GROUP: Banking / Narration
|=============================================================================
|
| Prefix    : /api/banking/transactions
| Middleware : auth:sanctum
|
| Endpoints:
|   GET  /api/banking/transactions/pending                          → List transactions
|   POST /api/banking/transactions/{transaction}/review/{action}    → Review a transaction
|   POST /api/banking/transactions/sms                             → Ingest SMS
|   POST /api/banking/transactions/email                           → Ingest email
|   POST /api/banking/transactions/statement                       → Upload statement
|
| IMPORTANT — route order matters:
|   The static routes (sms, email, statement) are declared BEFORE the
|   dynamic {transaction} route so Laravel does not try to match "sms"
|   as a transaction ID.
|
|=============================================================================
*/
Route::middleware(['auth:sanctum'])
    ->prefix('banking/transactions')
    ->group(function () {

        /*
        |---------------------------------------------------------------------
        | 1. GET /api/banking/transactions/pending
        |---------------------------------------------------------------------
        | Returns all pending + recently-reviewed transactions for the
        | authenticated user's company, along with narration heads and
        | bank accounts — everything needed to render the Narration page.
        |
        | Query params:
        |   page   integer   Pagination page. Default: 1
        |
        | Response 200:
        |   {
        |     "status":        "ok",
        |     "has_company":   true,
        |     "bank_accounts": [...],
        |     "heads":         [...],
        |     "transactions":  { "data": [...], "current_page": 1, ... }
        |   }
        |
        | Postman:
        |   GET {{base_url}}/api/banking/transactions/pending
        |   Tests tab → auto-save bank_account_id and transaction_id
        |---------------------------------------------------------------------
        */
        Route::get('/pending', [BankTransactionApiController::class, 'pending'])
            ->name('api.banking.transactions.pending');


        /*
        |---------------------------------------------------------------------
        | 2. POST /api/banking/transactions/sms
        |---------------------------------------------------------------------
        | Parses a raw bank SMS alert and creates a BankTransaction record.
        |
        | Body (JSON):
        |   {
        |     "bank_account_id": {{bank_account_id}},
        |     "raw_sms": "INR 15,000.00 credited to A/c XX1234 on 15-Jun-24..."
        |   }
        |
        | Response 200:
        |   { "status": "ok", "message": "SMS ingested successfully." }
        |---------------------------------------------------------------------
        */
        Route::post('/sms', SmsIngestApiController::class)
            ->name('api.banking.transactions.sms.ingest');


        /*
        |---------------------------------------------------------------------
        | 3. POST /api/banking/transactions/email
        |---------------------------------------------------------------------
        | Parses a bank notification email and creates a BankTransaction record.
        |
        | Body (JSON):
        |   {
        |     "bank_account_id": {{bank_account_id}},
        |     "email_subject":   "Credit Alert - HDFC Bank",
        |     "email_body":      "Dear Customer, INR 25,000 credited..."
        |   }
        |
        | Response 200:
        |   { "status": "ok", "message": "Email transaction ingested successfully." }
        |---------------------------------------------------------------------
        */
        Route::post('/email', EmailIngestApiController::class)
            ->name('api.banking.transactions.email.ingest');


        /*
        |---------------------------------------------------------------------
        | 4. POST /api/banking/transactions/statement
        |---------------------------------------------------------------------
        | Uploads and parses a bank statement. Returns import summary.
        | Content-Type MUST be multipart/form-data (file upload).
        |
        | Body (form-data):
        |   bank_account_id   Text   {{bank_account_id}}
        |   statement         File   (select PDF/CSV/Excel/image file)
        |
        | Response 200:
        |   {
        |     "status":  "ok",
        |     "message": "Statement processed: 45 imported, 3 duplicates...",
        |     "result":  { "imported": 45, "duplicates": 3, "failed": 2, "total": 50 }
        |   }
        |---------------------------------------------------------------------
        */
        Route::post('/statement', StatementUploadApiController::class)
            ->name('api.banking.transactions.statement.upload');


        /*
        |---------------------------------------------------------------------
        | 5. POST /api/banking/transactions/{transaction}/review/{action}
        |---------------------------------------------------------------------
        | Review a single transaction. {action} must be one of:
        |   approve  — accept AI suggestion as-is        body: {}
        |   correct  — override with narration details   body: see below
        |   reject   — mark as rejected                  body: {}
        |
        | Body for "correct" (JSON):
        |   {
        |     "narration_head_id":     3,
        |     "narration_sub_head_id": 12,
        |     "party_name":            "Acme Supplies",
        |     "narration_note":        "Office stationery - June",
        |     "save_as_rule":          true,
        |     "invoice_id":            7,
        |     "unreconcile":           false
        |   }
        |
        | Response 200:
        |   { "status": "ok", "message": "Transaction corrected successfully." }
        |
        | NOTE: declared LAST so "sms", "email", "statement" are not captured
        | by the {transaction} wildcard segment.
        |---------------------------------------------------------------------
        */
        Route::post('/{transaction}/review/{action}', [NarrationReviewApiController::class, 'handle'])
            ->where('action', 'approve|correct|reject')
            ->name('api.banking.transactions.review');

    });
