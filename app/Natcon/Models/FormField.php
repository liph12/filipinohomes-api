<?php

namespace App\Natcon\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One question on the NATCON form.
 *
 * Choices live in a JSON column on this row rather than a child table. See the
 * migration docblock for why — the short version is that the child table's only
 * read was a COUNT, and per-row JSON doesn't worsen concurrency the way a
 * whole-form blob would.
 */
class FormField extends Model implements Auditable
{
    // The class name drops the module prefix, so Eloquent would otherwise
    // infer `form_fields` from it. The table keeps the natcon_ prefix because it
    // shares a schema with the rest of the product.
    protected $table = 'natcon_form_fields';

    use LogsActivity;

    protected string $auditCategory = 'natcon';
    protected array $auditLabelAttributes = ['label'];

    public const TYPE_SECTION      = 'section';
    public const TYPE_SHORT_TEXT   = 'short_text';
    public const TYPE_LONG_TEXT    = 'long_text';
    public const TYPE_EMAIL        = 'email';
    public const TYPE_PHONE        = 'phone';
    public const TYPE_NUMBER       = 'number';
    public const TYPE_DATE         = 'date';
    public const TYPE_SELECT       = 'select';
    public const TYPE_RADIO        = 'radio';
    public const TYPE_CHECKBOX     = 'checkbox';
    public const TYPE_IMAGE_UPLOAD = 'image_upload';

    public const TYPES = [
        self::TYPE_SECTION, self::TYPE_SHORT_TEXT, self::TYPE_LONG_TEXT,
        self::TYPE_EMAIL, self::TYPE_PHONE, self::TYPE_NUMBER, self::TYPE_DATE,
        self::TYPE_SELECT, self::TYPE_RADIO, self::TYPE_CHECKBOX,
        self::TYPE_IMAGE_UPLOAD,
    ];

    /** Types whose answer is one of a fixed choice list. */
    public const CHOICE_TYPES = [self::TYPE_SELECT, self::TYPE_RADIO, self::TYPE_CHECKBOX];

    /** Types that accept more than one value. */
    public const MULTI_TYPES = [self::TYPE_CHECKBOX, self::TYPE_IMAGE_UPLOAD];

    protected $fillable = [
        'natcon_event_id', 'key', 'label', 'help_text', 'type',
        'is_required', 'is_active', 'sort_order', 'illustration_url',
        'choices', 'config', 'section',
    ];

    protected $casts = [
        'choices'     => 'array',
        'config'      => 'array',
        'is_required' => 'boolean',
        'is_active'   => 'boolean',
        'sort_order'  => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(NatconEvent::class, 'natcon_event_id');
    }

    /**
     * Choices the awardee should see. Retired options are kept in the JSON with
     * is_active=false so an already-recorded answer can still resolve its label.
     *
     * @return array<int,array<string,mixed>>
     */
    public function activeChoices(): array
    {
        return array_values(array_filter(
            $this->allChoices(),
            fn ($c) => ($c['is_active'] ?? true) !== false,
        ));
    }

    /** @return array<int,array<string,mixed>> */
    public function allChoices(): array
    {
        return array_values(array_filter(
            (array) ($this->choices ?? []),
            fn ($c) => is_array($c) && isset($c['value']),
        ));
    }

    /** value => label, active only. Used to validate and to render a submission. */
    public function choiceLabels(): array
    {
        return collect($this->activeChoices())
            ->pluck('label', 'value')
            ->all();
    }

    public function isChoiceType(): bool
    {
        return in_array($this->type, self::CHOICE_TYPES, true);
    }

    public function isMulti(): bool
    {
        return in_array($this->type, self::MULTI_TYPES, true);
    }

    /** A section header is a layout element, never an input — it can't be required. */
    public function isInput(): bool
    {
        return $this->type !== self::TYPE_SECTION;
    }

    public function config(string $key, $default = null)
    {
        return data_get($this->config, $key, $default);
    }
}
