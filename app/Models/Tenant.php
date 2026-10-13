<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\ProviderCredentials;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer company. The only model without BelongsToTenant: it is the root.
 *
 * `provider_credentials` is encrypted at rest with APP_KEY (A04) and only ever
 * exposed as an opaque ProviderCredentials object.
 *
 * @property int $id
 * @property string $name
 * @property list<string> $internal_domains
 * @property string $default_timezone
 * @property array<string, string>|null $provider_credentials
 */
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $fillable = ['name', 'internal_domains', 'default_timezone', 'provider_credentials'];

    protected $hidden = ['provider_credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'internal_domains' => 'array',
            'provider_credentials' => 'encrypted:array',
        ];
    }

    /**
     * Lower-cased internal domains, for case-insensitive lead classification (FR-6).
     *
     * @return list<string>
     */
    public function internalDomains(): array
    {
        return array_map(
            static fn (string $d): string => mb_strtolower(trim($d)),
            $this->internal_domains,
        );
    }

    public function credentials(): ProviderCredentials
    {
        return new ProviderCredentials($this->provider_credentials ?? []);
    }

    /**
     * @return HasMany<Rep, $this>
     */
    public function reps(): HasMany
    {
        return $this->hasMany(Rep::class);
    }
}
