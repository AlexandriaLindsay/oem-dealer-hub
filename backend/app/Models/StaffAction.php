<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change made in the staff console: who, what, to which account, and the
 * reason they gave. Written by App\Platform\AccountTools, never edited.
 */
class StaffAction extends Model
{
    public const UPDATED_AT = null;

    public const LABELS = [
        'extend_trial' => 'Extended trial',
        'set_plan' => 'Set plan',
        'reset_two_factor' => 'Reset two-factor',
        'send_password_reset' => 'Sent password reset',
    ];

    protected $fillable = ['user_id', 'staff_name', 'action', 'subject_type', 'subject_id', 'subject_label', 'details', 'note'];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    public static function record(User $staff, string $action, Model $subject, string $label, string $note, array $details = []): self
    {
        return self::create([
            'user_id' => $staff->id,
            'staff_name' => $staff->name,
            'action' => $action,
            'subject_type' => $subject instanceof Manufacturer ? 'manufacturer' : 'user',
            'subject_id' => $subject->getKey(),
            'subject_label' => $label,
            'details' => $details ?: null,
            'note' => $note,
        ]);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
