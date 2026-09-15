<?php

namespace App\Models;

use App\Support\NameMatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Personnel extends Model
{
    protected $table = 'personnel';

    protected $guarded = [];

    protected $casts = ['is_walk_in' => 'boolean'];

    /** PSR statuses that mean the person is expected to be present. */
    public const ON_DUTY_STATUSES = ['', 'ON DUTY', 'DUTY'];

    protected static function booted(): void
    {
        static::saving(fn (Personnel $p) => $p->fillDerived());
    }

    /** Tidies the fields and computes the full name and the matching keys (also usable before saving). */
    public function fillDerived(): static
    {
        foreach (['rank', 'surname', 'first_name', 'middle', 'full_name', 'serial', 'squadron', 'office', 'psr_status'] as $f) {
            // 191 = MySQL string column length; a long spreadsheet cell must not break the import.
            $this->{$f} = mb_substr(trim(preg_replace('/\s+/', ' ', (string) $this->{$f})), 0, 191);
        }
        if ($this->surname === '' && str_contains($this->full_name, ',')) {
            $this->surname = trim(explode(',', $this->full_name, 2)[0]);   // "SANTOS, JUAN MIGUEL R"
        }
        if ($this->full_name === '') {
            $mi = strlen($this->middle) > 2 ? substr($this->middle, 0, 1).'.' : $this->middle;
            $this->full_name = trim(implode(' ', array_filter([$this->first_name, $mi, $this->surname], 'strlen')));
        }
        $this->surname_key = NameMatcher::surnameKey($this->surname);
        $this->name_key = NameMatcher::norm($this->full_name);
        $this->squadron_key = NameMatcher::squash($this->squadron);
        $this->office_key = NameMatcher::squash($this->office);

        return $this;
    }

    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    public static function serialKey(?string $serial): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $serial));
    }

    public function isExpectedPresent(): bool
    {
        return in_array(strtoupper($this->psr_status), self::ON_DUTY_STATUSES, true);
    }

    public function card(): array
    {
        return [
            'id' => $this->id,
            'rank' => $this->rank,
            'name' => $this->full_name ?: $this->surname,
            'surname' => $this->surname,
            'squadron' => $this->squadron,
            'office' => $this->office,
            'serial' => $this->serial,
            'psr_status' => $this->psr_status,
            'walk_in' => $this->is_walk_in,
        ];
    }
}
