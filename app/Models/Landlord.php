<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Landlord extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'trading_name',
        'contact_email',
        'contact_phone',
        'address',
        'logo',
        'brand_color',
        'invoice_footer_note',
        'status',
        'subscription_status',
        'current_period_end',
        'grace_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'current_period_end' => 'date',
            'grace_ends_at' => 'date',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function logoUrl(): ?string
    {
        return $this->logo ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->logo) : null;
    }

    /**
     * DomPDF renders offline and doesn't reliably fetch images by URL, so the
     * logo is embedded directly as a base64 data URI when building a PDF.
     */
    public function logoDataUri(): ?string
    {
        if (!$this->logo || !\Illuminate\Support\Facades\Storage::disk('public')->exists($this->logo)) {
            return null;
        }

        $contents = \Illuminate\Support\Facades\Storage::disk('public')->get($this->logo);
        $mime = \Illuminate\Support\Facades\Storage::disk('public')->mimeType($this->logo);

        return "data:{$mime};base64," . base64_encode($contents);
    }
}
