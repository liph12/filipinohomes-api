<?php

namespace App\Auditing;

use OwenIt\Auditing\Auditable as AuditableTrait;

/**
 * Layer over owen-it's Auditable trait. Adds:
 *   - category   (UI filter bucket, declared on the model via $auditCategory)
 *   - source     (where the change came from — request header X-FH-Source
 *                 first, otherwise the named route)
 *   - subject_label  (human-readable identity for the audited record)
 *   - user_role / user_name  (snapshots so role/name renames don't rewrite
 *                             history)
 *   - description (free-form text the controller can set per save())
 *
 * Models opt in by `use App\Auditing\LogsActivity;` and (optionally) set
 *   protected string $auditCategory = 'listings';
 *   protected array  $auditLabelAttributes = ['name', 'code'];
 *
 * Controllers can override per request:
 *   $listing->auditSource      = 'audit_modal';
 *   $listing->auditDescription = 'Listing flagged';
 *   $listing->save();
 */
trait LogsActivity
{
    use AuditableTrait;

    /**
     * Per-request overrides settable from controllers. Declared on the trait
     * (not the using model) so that `$model->auditSource = 'x'` goes through
     * normal PHP property access instead of falling into Laravel's __set →
     * attributes pipeline (which would try to persist them as DB columns).
     */
    public ?string $auditSource = null;
    public ?string $auditDescription = null;
    public ?string $auditCategoryOverride = null;

    /**
     * Owen-it calls this immediately before the audit row is written.
     * We use it to inject our custom columns.
     */
    public function transformAudit(array $data): array
    {
        $request = request();

        $data['category']      = $this->resolveAuditCategory();
        $data['source']        = $this->resolveAuditSource($request);
        $data['subject_label'] = $this->resolveAuditLabel();
        $data['description']   = $this->auditDescription ?? null;

        // Snapshot user identity (so renames/role changes don't rewrite history)
        $user = $request ? $request->user() : null;
        if ($user) {
            $data['user_role'] = optional($user->role)->name;
            $data['user_name'] = $user->name;
        }

        return $data;
    }

    protected function resolveAuditCategory(): ?string
    {
        if (property_exists($this, 'auditCategoryOverride') && $this->auditCategoryOverride) {
            return $this->auditCategoryOverride;
        }
        return $this->auditCategory ?? null;
    }

    protected function resolveAuditSource($request): ?string
    {
        if (property_exists($this, 'auditSource') && $this->auditSource) {
            return substr((string) $this->auditSource, 0, 64);
        }

        if ($request) {
            $header = $request->header('X-FH-Source');
            if (is_string($header) && $header !== '') {
                return substr($header, 0, 64);
            }

            $routeName = optional($request->route())->getName();
            if ($routeName) return substr($routeName, 0, 64);
        }

        return null;
    }

    protected function resolveAuditLabel(): ?string
    {
        $attrs = $this->auditLabelAttributes ?? ['name', 'title', 'code', 'email', 'slug'];
        foreach ($attrs as $attr) {
            if (!empty($this->{$attr})) return (string) $this->{$attr};
        }
        return class_basename(static::class) . ' #' . $this->getKey();
    }
}
