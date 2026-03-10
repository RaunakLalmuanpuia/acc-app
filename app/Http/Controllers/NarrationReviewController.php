<?php

namespace App\Http\Controllers;

use App\Actions\Banking\ReviewNarrationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\NarrationReviewRequest;
use App\Models\BankTransaction;
use Illuminate\Http\RedirectResponse;

class NarrationReviewController extends Controller
{
    public function __construct(private ReviewNarrationAction $action) {}

    public function handle(NarrationReviewRequest $request, BankTransaction $transaction, string $action): RedirectResponse
    {
        match ($action) {
            'approve' => $this->action->approve($transaction),

            'correct' => $this->action->correct(
                transaction:        $transaction,
                narrationHeadId:    (int) $request->narration_head_id, // Pass the new Head ID
                narrationSubHeadId: (int) $request->narration_sub_head_id,
                narrationNote:      $request->narration_note,
                partyName:          $request->party_name,
                saveAsRule:         (bool) $request->input('save_as_rule', false),
            ),

            'reject'  => $this->action->reject($transaction),

            default   => abort(422, 'Invalid action.'),
        };

        // Redirect back so Inertia can seamlessly update the UI
        return back()->with('success', "Transaction {$action}d successfully.");
    }
}
