<?php

declare(strict_types=1);

namespace Espego\CliRouter\Examples;

/**
 * Stands in for whatever a real script talks to — a database, an HTTP API, a file.
 *
 * In memory so the example runs anywhere, and so a test can hand the set a store it seeded itself
 * rather than reaching for a mocking library.
 *
 * @phpstan-type NoteRow array{id: int, text: string, priority: string, tags: list<string>, detail: string|null, due: string|null}
 */
final class NoteStore
{
    /** @var array<int, NoteRow> */
    private array $notes = [];

    private int $nextId = 1;

    /** @param list<NoteRow> $notes */
    public function __construct(array $notes = self::SEED)
    {
        foreach ($notes as $note) {
            $this->notes[$note['id']] = $note;
            $this->nextId = max($this->nextId, $note['id'] + 1);
        }
    }

    /**
     * @param list<string> $tags Every one of them must be present, not any.
     * @return list<NoteRow>
     */
    public function find(?Priority $priority, array $tags, int $limit): array
    {
        $matched = array_filter(
            $this->notes,
            static fn (array $n): bool => ($priority === null || $n['priority'] === $priority->value)
                && array_diff($tags, $n['tags']) === []
        );
        krsort($matched);

        return array_slice(array_values($matched), 0, $limit);
    }

    /** @return NoteRow */
    public function get(int $id): array
    {
        return $this->notes[$id] ?? throw new Rejected("there is no note {$id}.");
    }

    /**
     * @param list<string> $tags
     * @return NoteRow
     */
    public function add(string $text, Priority $priority, array $tags): array
    {
        $note = [
            'id' => $this->nextId++,
            'text' => $text,
            'priority' => $priority->value,
            'tags' => $tags,
            'detail' => null,
            'due' => null,
        ];
        $this->notes[$note['id']] = $note;

        return $note;
    }

    /**
     * @param array<string, mixed> $changes Only the keys given are touched.
     * @return NoteRow
     */
    public function edit(int $id, array $changes): array
    {
        $note = array_merge($this->get($id), $changes);
        /** @var NoteRow $note */
        $this->notes[$id] = $note;

        return $note;
    }

    /** @var list<NoteRow> */
    private const array SEED = [
        [
            'id' => 1,
            'text' => 'Renew the domain',
            'priority' => 'high',
            'tags' => ['ops', 'billing'],
            'detail' => null,
            'due' => '2026-10-01',
        ],
        [
            'id' => 2,
            'text' => 'Write the release notes',
            'priority' => 'normal',
            'tags' => ['docs'],
            'detail' => 'Cover the parser change.',
            'due' => null,
        ],
        [
            'id' => 3,
            'text' => 'Archive the old invoices',
            'priority' => 'low',
            'tags' => ['billing'],
            'detail' => null,
            'due' => null,
        ],
    ];
}
