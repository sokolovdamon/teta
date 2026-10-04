<?php

namespace App\Support\StateMachine;

use App\Support\Events\Outbox;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * State machine for an Eloquent model (sequences_states.md, part B).
 *
 * The model declares allowed transitions:
 *   protected static function transitions(): array { return ['status' => ['booked' => ['paid', ...], ...]]; }
 * and optionally an event prefix for domain events: protected static string $eventPrefix = 'book.session';
 *
 * Every transition is validated, written to state_transitions and published as a domain event
 * "{prefix}.{to}" in the same transaction.
 */
trait HasStateMachine
{
    /** @return array<string, array<string, list<string>>> field => from => [to...] */
    abstract protected static function transitions(): array;

    public function canTransition(string $to, string $field = 'status'): bool
    {
        $from = $this->getAttribute($field);
        $map = static::transitions()[$field] ?? [];

        return in_array($to, $map[$from] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $attributes  extra attributes saved together with the transition
     * @param  array<string, mixed>  $context  stored in history and in the domain event payload
     */
    public function transitionTo(
        string $to,
        ?string $actorId = null,
        ?string $reason = null,
        array $attributes = [],
        array $context = [],
        string $field = 'status',
        ?string $event = null,
    ): static {
        $from = $this->getAttribute($field);
        if (! $this->canTransition($to, $field)) {
            throw new InvalidTransition(class_basename($this), $from, $to);
        }

        DB::transaction(function () use ($to, $from, $actorId, $reason, $attributes, $context, $field, $event) {
            $this->forceFill([$field => $to, ...$attributes])->save();

            $eventName = $event ?? (static::eventPrefix().'.'.$to);
            StateTransition::create([
                'model_type' => $this->getMorphClass(),
                'model_id' => $this->getKey(),
                'field' => $field,
                'from' => $from,
                'to' => $to,
                'event' => $eventName,
                'actor_id' => $actorId,
                'reason' => $reason,
                'context' => $context ?: null,
                'created_at' => now(),
            ]);

            Outbox::record($eventName, $this, ['from' => $from, 'to' => $to, 'reason' => $reason, ...$context], $actorId);
        });

        return $this;
    }

    /** Record the initial state of a freshly created entity. */
    public function recordInitialState(?string $actorId = null, array $context = [], string $field = 'status', ?string $event = null): void
    {
        $to = $this->getAttribute($field);
        $eventName = $event ?? (static::eventPrefix().'.'.$to);
        StateTransition::create([
            'model_type' => $this->getMorphClass(),
            'model_id' => $this->getKey(),
            'field' => $field,
            'from' => null,
            'to' => $to,
            'event' => $eventName,
            'actor_id' => $actorId,
            'context' => $context ?: null,
            'created_at' => now(),
        ]);
        Outbox::record($eventName, $this, ['from' => null, 'to' => $to, ...$context], $actorId);
    }

    public function stateHistory(): MorphMany
    {
        return $this->morphMany(StateTransition::class, 'model')->orderBy('created_at');
    }

    protected static function eventPrefix(): string
    {
        return property_exists(static::class, 'eventPrefix') ? static::$eventPrefix : strtolower(class_basename(static::class));
    }
}
