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
        static::saving(function (Personnel $p) {
            foreach (['rank', 'surname', 'first_name', 'middle', 'full_name', 'serial', 'squadron', 'office', 'psr_status'] as $f) {
                // 191 = MySQL string column length; a long spreadsheet cell must not break the import.
                $p->{$f} = mb_substr(trim(preg_replace('/\s+/', ' ', (string) $p->{$f})), 0, 191);
            }
            if ($p->surname === '' && str_contains($p->full_name, ',')) {
                $p->surname = trim(explode(',', $p->full_name, 2)[0]);   // "SANTOS, JUAN MIGUEL R"
            }
            if ($p->full_name === '') {
                $mi = strlen($p->middle) > 2 ? substr($p->middle, 0, 1).'.' : $p->middle;
                $p->full_name = trim(implode(' ', array_filter([$p->first_name, $mi, $p->surname], 'strlen')));
            }
            $p->surname_key = NameMatcher::surnameKey($p->surname);
            $p->name_key = NameMatcher::norm($p->full_name);
            $p->squadron_key = NameMatcher::squash($p->squadron);
            $p->office_key = NameMatcher::squash($p->office);
        });
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
