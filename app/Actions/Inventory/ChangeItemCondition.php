<?php

namespace App\Actions\Inventory;

use App\Models\Item;
use App\Models\ItemCondition;
use App\Models\ItemConditionChange;
use App\Models\ItemPhoto;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change the condition of an item. A reason is required, photos are optional.
 *
 * A loan can be linked to the change (e.g. something came back broken); the link is used by the
 * loan module from phase 3 on.
 */
class ChangeItemCondition
{
    /**
     * @param  list<UploadedFile>  $photos
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Item $item, ItemCondition $condition, string $reason, array $photos = [], ?int $loanId = null): ItemConditionChange
    {
        if ($condition->id === $item->condition_id) {
            throw ValidationException::withMessages(['condition_id' => "Der Zustand ist schon „{$condition->name}“."]);
        }

        return DB::transaction(function () use ($actor, $item, $condition, $reason, $photos, $loanId) {
            $change = $item->conditionChanges()->create([
                'from_condition' => $item->condition->name,
                'to_condition' => $condition->name,
                'reason' => $reason,
                'user_id' => $actor->id,
                'loan_id' => $loanId,
            ]);

            // throws if S3 can not store the photos; the transaction then also drops the change
            ItemPhoto::uploadAll($item, $photos, $change);

            $oldName = $item->condition->name;
            $item->condition()->associate($condition);
            $item->save();
            AuditRecorder::record($item, 'conditionChanged', ['condition' => $oldName], ['condition' => $condition->name]);

            return $change;
        });
    }
}
