<?php

namespace Bernskiold\LaravelImportEngine\Support;

use Illuminate\Support\Str;

/**
 * One column that an importer can read.
 *
 * The key is the name of the field in each ImportRow. The label is the name
 * that a person sees. The aliases are other column headings that mean the
 * same field. The engine uses the key, the label and the aliases to guess
 * the column map.
 *
 *     Column::make('email', __('Email'))
 *         ->required()
 *         ->aliases('email address', 'e-mail')
 *         ->description(__('The work email of the person.'));
 */
final class Column
{
    private bool $required = false;

    private ?string $description = null;

    private ?string $heading = null;

    /**
     * @var array<int, string>
     */
    private array $aliases = [];

    public function __construct(
        private readonly string $key,
        private readonly ?string $label = null,
    ) {}

    public static function make(string $key, ?string $label = null): self
    {
        return new self($key, $label);
    }

    /**
     * Make the run stop when the file, or the column map, does not supply
     * this column.
     */
    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    public function description(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Other column headings that mean this field.
     *
     * The engine compares headings without case, spaces and punctuation.
     * Thus "Client Name", "client_name" and "CLIENT-NAME" are the same
     * heading. Give only the headings that use different words.
     */
    public function aliases(string ...$aliases): self
    {
        $this->aliases = array_values(array_merge($this->aliases, $aliases));

        return $this;
    }

    /**
     * The heading of this column in a blank template.
     *
     * If you do not set it, the engine uses the label in snake case.
     */
    public function heading(string $heading): self
    {
        $this->heading = $heading;

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label ?? $this->key;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @return array<int, string>
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    /**
     * The heading for a blank template.
     *
     * It is in snake case, for example "bfc_identifier". A heading goes
     * through Excel, CSV files and scripts, and each step can change spaces
     * or capitals. Snake case does not change. The engine compares headings
     * without case and punctuation, so the template still maps itself.
     *
     * This does not use Str::snake(), which changes "BFC" to "b_f_c".
     */
    public function getHeading(): string
    {
        if ($this->heading !== null) {
            return $this->heading;
        }

        return Str::of($this->label())
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();
    }

    /**
     * @return array{key: string, label: string, heading: string, description: string|null, required: bool, aliases: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'heading' => $this->getHeading(),
            'description' => $this->getDescription(),
            'required' => $this->isRequired(),
            'aliases' => $this->getAliases(),
        ];
    }
}
