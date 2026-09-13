<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\Cli;
use Espego\CliRouter\Command;
use Espego\CliRouter\CommandResult;
use Espego\CliRouter\Commands;
use Espego\CliRouter\Tests\DemoSet;
use Espego\CliRouter\Tests\EdgeSet;
use Espego\CliRouter\Tests\FileSet;
use Espego\CliRouter\Tests\Mutates;
use Tester\Assert;

$names = ['env', 'contracts', 'events', 'typed', 'report', 'save', 'rename', 'nope', 'fault'];
$demo = new DemoSet();
Assert::same($names, $demo->commandNames());

// One call renders the complete help contract, including pages hidden from the overview.
$pages = $demo->helpPages('demo.php');
Assert::matchFile(__DIR__ . '/DemoHelp.expect', $pages['overview']);
Assert::same(['overview', ...$names], array_keys($pages));
foreach (array_slice($pages, 1, preserve_keys: true) as $name => $page) {
    Assert::same($page, $demo->handleBuffered(['demo.php', 'help', $name])[1]);
}
Assert::same((string) file_get_contents(__DIR__ . '/DemoHelpPages.expect'), implode('', $pages));

$hidden = new #[Cli('Hidden example.')] class extends Commands {
    #[Command('Visible.')]
    public function visible(): CommandResult
    {
        return CommandResult::nothing();
    }

    #[Command('Hidden.', hidden: true)]
    public function hidden(): CommandResult
    {
        return CommandResult::nothing();
    }
};
Assert::same(['visible', 'hidden'], $hidden->commandNames());
Assert::same(['overview', 'visible', 'hidden'], array_keys($hidden->helpPages('hidden.php')));
Assert::same(['visible', 'hidden'], array_keys($hidden->helpData()['commands']));

// A single set has only its overview; that page already includes its sole command's prose.
$singlePages = (new FileSet())->helpPages('files.php');
Assert::same(['overview'], array_keys($singlePages));
Assert::same((new FileSet())->handleBuffered(['files.php', '--help'])[1], $singlePages['overview']);

// The common whole-CLI test operation is one public call and retains both streams.
Assert::same(
    [1, '', "error: unknown command 'missing'. Accepted: " . implode(', ', $names) . ". Run: php demo.php help\n"],
    (new DemoSet())->handleBuffered(['demo.php', 'missing']),
);

// PHP and CLI expose one canonical machine-readable model.
[$code, $json, $err] = (new DemoSet())->handleBuffered(['demo.php', 'help', '--json']);
Assert::same(0, $code);
Assert::same('', $err);
Assert::same((string) file_get_contents(__DIR__ . '/DemoHelpData.expect'), $json);
Assert::same((new DemoSet())->helpData(), json_decode($json, true, flags: JSON_THROW_ON_ERROR));

$data = (new DemoSet())->helpData();
Assert::same(['red', 'green', 'blue'], $data['commands']['typed']['options'][0]['allowedValues']);
Assert::same(1, $data['commands']['events']['options'][0]['minCount']);
Assert::same('global', $data['commands']['save']['options'][2]['source']);
Assert::same(Mutates::class, $data['commands']['save']['options'][2]['onlyWhen']);

// A topic returns exactly that command's object.
[$code, $json, $err] = (new DemoSet())->handleBuffered(['demo.php', 'help', 'events', '--json']);
Assert::same(0, $code);
Assert::same('', $err);
Assert::same($data['commands']['events'], json_decode($json, true, flags: JSON_THROW_ON_ERROR));

// Backing values keep their native JSON type.
$edgeData = (new EdgeSet())->helpData();
Assert::same([1, 2], $edgeData['commands']['levels']['options'][0]['allowedValues']);
Assert::contains('May be empty.', (new EdgeSet())->helpPages('edge.php')['loose']);

// --json belongs to built-in help only. It remains available as an ordinary command option.
Assert::true(json_decode((new EdgeSet())->handleBuffered(['edge.php', 'files', '--json'])[1], true)['json']);
Assert::same(
    [1, '', "error: --json is a flag and takes no value\n"],
    (new DemoSet())->handleBuffered(['demo.php', 'help', '--json=false']),
);
