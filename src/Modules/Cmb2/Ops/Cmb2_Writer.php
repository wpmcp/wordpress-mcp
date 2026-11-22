<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Ops;

use WPMCP\Integrations\Support\Undo_Points;
use WPMCP\Modules\Cmb2\Adapter\Cmb2_Values;
use WPMCP\Modules\Cmb2\Value\Cmb2_Refusal;
use WPMCP\Modules\Cmb2\Value\Cmb2_Request;
use WPMCP\Modules\Cmb2\Value\Cmb2_Scalar;
use WPMCP\Modules\Cmb2\Value\Cmb2_Secret_Policy;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The CMB2 write (issue #1089): one allowlisted field saved through CMB2's
 * own field save. When CMB2's sanitization throws a non-empty value away
 * (a site's sanitization_cb rejecting it, say), what was stored before is
 * put back, the undo point is voided and the call is refused.
 */
final class Cmb2_Writer
{
    public function __construct(
        private readonly Cmb2_Values $store,
        private readonly Cmb2_Secret_Policy $secrets,
        private readonly Undo_Points $undo_points
    ) {
    }

    /**
     * @param array<mixed> $context the dispatcher's context (operation_id).
     * @return array<string, mixed>
     */
    public function write(Cmb2_Request $request, array $context): array
    {
        $field = $request->field;
        if (null === $field) {
            $refusal = new Cmb2_Refusal('unknown_field', 'No CMB2 field was named.');
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw $refusal->to_exception();
        }
        $box      = $request->box;
        $target   = $request->target;
        $image    = $this->store->before_image($box, $target, $field);
        $previous = $this->store->value($box, $target, $field);

        $this->store->save($box, $target, $field, $request->value);

        $after = $this->store->value($box, $target, $field);
        if (! $this->is_empty($request->value) && (! $after->exists || $this->is_empty($after->value))) {
            $this->store->put_back($target, $field, $image);
            $operation_id = Cmb2_Scalar::text($context['operation_id'] ?? '');
            if ('' !== $operation_id && '0' !== $operation_id) {
                $this->undo_points->void($operation_id);
            }
            $refusal = new Cmb2_Refusal('cmb2_rejected_value', sprintf('CMB2\'s own sanitization for the field "%s" did not keep the value, so the field was put back and nothing changed.', $field->id), [ 'field' => $field->id ]);
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw $refusal->to_exception();
        }

        $out = [
            'box'      => $box->id,
            'field'    => $field->id,
            'previous' => $previous->exists ? $this->secrets->mask($field, $previous->value) : null,
            'value'    => $after->exists ? $this->secrets->mask($field, $after->value) : null,
            'removed'  => ! $after->exists,
            'undo'     => 'rollback-operation puts the value back as it was before this write.',
        ];
        return ($target->is_post() ? [ 'post_id' => $target->post_id ] : [ 'option_key' => $target->option_key ]) + $out;
    }

    private function is_empty(mixed $value): bool
    {
        return null === $value || '' === $value || false === $value || [] === $value;
    }
}
