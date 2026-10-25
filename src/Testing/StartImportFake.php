<?php

namespace Bernskiold\LaravelImportEngine\Testing;

use BackedEnum;
use Bernskiold\LaravelImportEngine\Actions\StartImport;
use Bernskiold\LaravelImportEngine\Models\Import;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;

/**
 * StartImport for tests: it keeps the file and makes the record, but it does
 * not send the job to the queue.
 *
 *     $imports = StartImport::fake();
 *
 *     Livewire::test(ImportContacts::class)->set('file', $file)->call('start');
 *
 *     $imports->assertStarted(ImportType::Contacts, fn (Import $import) => $import->options['list'] === 4);
 */
class StartImportFake extends StartImport
{
    /**
     * @var array<int, Import>
     */
    private array $started = [];

    /**
     * The imports that were started, in the order that they were started.
     *
     * @return Collection<int, Import>
     */
    public function started(?BackedEnum $type = null): Collection
    {
        return collect($this->started)
            ->when($type !== null, fn (Collection $imports): Collection => $imports->filter(
                fn (Import $import): bool => $import->importType() === $type,
            ))
            ->values();
    }

    /**
     * @param  (callable(Import): bool)|null  $callback
     */
    public function assertStarted(BackedEnum $type, ?callable $callback = null): void
    {
        $matching = $this->started($type)->filter(fn (Import $import): bool => $callback === null || $callback($import));

        Assert::assertTrue($matching->isNotEmpty(), "No [{$type->value}] import was started that agrees with the callback.");
    }

    public function assertStartedTimes(BackedEnum $type, int $times): void
    {
        $count = $this->started($type)->count();

        Assert::assertSame($times, $count, "The [{$type->value}] import was started {$count} times, not {$times} times.");
    }

    public function assertNotStarted(BackedEnum $type): void
    {
        Assert::assertTrue($this->started($type)->isEmpty(), "An [{$type->value}] import was started.");
    }

    public function assertNothingStarted(): void
    {
        Assert::assertEmpty($this->started, 'An import was started.');
    }

    protected function dispatch(Import $import): void
    {
        $this->started[] = $import;
    }
}
