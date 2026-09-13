<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\BufferedOutput;
use Espego\CliRouter\Cli;
use Espego\CliRouter\Command;
use Espego\CliRouter\CommandResult;
use Espego\CliRouter\Commands;
use Espego\CliRouter\DeclarationError;
use Espego\CliRouter\Introspector;
use Espego\CliRouter\Opt;
use Tester\Assert;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class TraitOperation
{
}

trait ComposedCommands
{
    #[TraitOperation]
    #[Command('Composed command.', group: 'Trait')]
    public function commandComposed(#[Opt('Refuse this invocation.')] bool $refuse = false): CommandResult
    {
        $this->output()->err('running ' . $this->invocation()->name . "\n");
        if ($refuse) {
            $this->fail('trait refusal', 3);
        }

        return CommandResult::json(['confirm' => $this->confirm]);
    }
}

trait ReadCommands
{
    #[Command('Read from the trait.')]
    public function read(): CommandResult
    {
        return CommandResult::text("trait\n");
    }
}

trait NestedReadCommands
{
    use ReadCommands;
}

// Trait methods are complete set members: their command and marker attributes, group, access to a
// set global and the protected invocation/output/fail helpers all behave as if written on the set.
$composed = new #[Cli('x', groups: ['Trait'])] class extends Commands {
    use ComposedCommands;

    #[Opt('Confirm the operation.', onlyWhen: TraitOperation::class)]
    public bool $confirm = false;
};
Assert::same(['composed'], $composed->commandNames());
Assert::contains("Trait:\n", $composed->helpPages('x.php')['overview']);
Assert::same(
    [0, "{\n    \"confirm\": true\n}\n", "running composed\n"],
    $composed->handleBuffered(['x.php', 'composed', '--confirm']),
);
Assert::same(
    [3, '', "running composed\nerror: trait refusal\n"],
    $composed->handleBuffered(['x.php', 'composed', '--refuse']),
);

// Imported trait methods keep their attributes and are ordinary, dispatchable set methods.
$imported = new #[Cli('x')] class extends Commands {
    use ReadCommands;
};
Assert::same(['read'], (new Introspector())->set($imported)->names());
$out = new BufferedOutput();
Assert::same(0, $imported->handle(['x.php', 'read'], $out));
Assert::same("trait\n", $out->out);

// A class method wins silently, even when the trait method carries #[Command]. With another
// command present, this used to pass introspection and hide the vanished trait command.
$unmarkedShadow = new #[Cli('x')] class extends Commands {
    use ReadCommands;

    #[Command('Another command.')]
    public function other(): CommandResult
    {
        return CommandResult::nothing();
    }

    public function read(): CommandResult
    {
        return CommandResult::text("class\n");
    }
};
Assert::exception(
    static fn() => (new Introspector())->set($unmarkedShadow),
    DeclarationError::class,
    '~read\(\) shadows #\[Command\] declared in trait ReadCommands::read\(\)~',
);

// An annotated replacement is still two declarations of one command, not a specialisation.
$markedShadow = new #[Cli('x')] class extends Commands {
    use ReadCommands;

    #[Command('Class replacement.')]
    public function read(): CommandResult
    {
        return CommandResult::text("class\n");
    }
};
Assert::exception(
    static fn() => (new Introspector())->set($markedShadow),
    DeclarationError::class,
    '~read\(\) shadows #\[Command\] declared in trait ReadCommands::read\(\)~',
);

// The source method survives under an alias, so the class may deliberately override its old name.
$aliased = new #[Cli('x')] class extends Commands {
    use ReadCommands {
        read as commandRead;
    }

    public function read(): CommandResult
    {
        return CommandResult::text("class\n");
    }
};
Assert::same(['read'], (new Introspector())->set($aliased)->names());
$out = new BufferedOutput();
Assert::same(0, $aliased->handle(['x.php', 'read'], $out));
Assert::same("trait\n", $out->out);

// A trait used through another trait has the same shadowing rule.
$nestedShadow = new #[Cli('x')] class extends Commands {
    use NestedReadCommands;

    #[Command('Another command.')]
    public function other(): CommandResult
    {
        return CommandResult::nothing();
    }

    public function read(): CommandResult
    {
        return CommandResult::text("class\n");
    }
};
Assert::exception(
    static fn() => (new Introspector())->set($nestedShadow),
    DeclarationError::class,
    '~trait ReadCommands::read\(\)~',
);
