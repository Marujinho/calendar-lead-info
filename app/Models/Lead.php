<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An external meeting attendee (PRD FR-6). Identity is (tenant_id, email).
 * Profile fields are overwritten by each sync (latest provider data wins).
 * Purged when no meeting links remain (FR-17).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $email
 * @property string $full_name
 * @property string|null $company_name
 * @property string|null $linkedin_url
 */
final class Lead extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    protected $fillable = ['tenant_id', 'email', 'full_name', 'company_name', 'linkedin_url'];

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = mb_strtolower(trim($value));
    }

    /**
     * @return BelongsToMany<Meeting, $this, MeetingLead>
     */
    public function meetings(): BelongsToMany
    {
        return $this->belongsToMany(Meeting::class, 'meeting_lead')
            ->using(MeetingLead::class)
            ->withPivot(['tenant_id', 'response_status']);
    }
}
