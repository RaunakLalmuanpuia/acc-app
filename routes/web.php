<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\AiChatController;
use App\Http\Controllers\BankTransactionController;
use App\Http\Controllers\NarrationReviewController;
use App\Http\Controllers\SmsIngestController;
use App\Http\Controllers\EmailIngestController;
use App\Http\Controllers\StatementUploadController;

use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;


Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});


Route::middleware(['auth', 'verified'])->group(function () {

    // Render the chat UI
    Route::get('/accounting/chat', [AiChatController::class, 'index'])
        ->name('accounting.chat');

    // Handle each message (Inertia router.post)
    Route::post('/accounting/chat', [AiChatController::class, 'send'])
        ->name('accounting.chat.send');

    Route::post('/accounting/chat/confirm', [AiChatController::class, 'confirm'])->name('accounting.chat.confirm');
});

Route::get('/invoices/{invoice}/pdf', function (Invoice $invoice) {
    $companyId = auth()->user()->companies()->value('id');

    abort_if($invoice->company_id !== (int) $companyId, 403, 'Access denied.');
    abort_if(! $invoice->pdf_path, 404, 'PDF has not been generated yet.');
    abort_if(! Storage::disk('local')->exists($invoice->pdf_path), 404, 'PDF file not found. Try regenerating.');

    return Storage::disk('local')->response(
        $invoice->pdf_path,
        basename($invoice->pdf_path),
        ['Content-Type' => 'application/pdf'],
        'inline', // opens in browser tab, not a forced download
    );
})->name('invoices.pdf.download');



Route::middleware(['auth', 'verified'])->prefix('banking')->group(function () {

    // Display pending transactions view
    Route::get('/transactions/pending', [BankTransactionController::class, 'pending'])
        ->name('banking.transactions.pending');

    // Handle narration review (Inertia will submit to this)
    Route::post('/transactions/{transaction}/review/{action}', [NarrationReviewController::class, 'handle'])
        ->where('action', 'approve|correct|reject')
        ->name('banking.transactions.review');

    // SMS Ingest
    Route::post('/transactions/sms', SmsIngestController::class)
        ->name('banking.transactions.sms.ingest');

    // Email Ingest
    Route::post('/transactions/email', EmailIngestController::class)
        ->name('banking.transactions.email.ingest');

    // Statement Upload
    Route::post('/transactions/statement', StatementUploadController::class)
        ->name('banking.transactions.statement.upload');

});



require __DIR__.'/auth.php';
