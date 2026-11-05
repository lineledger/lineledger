<?php

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| Release channels
|--------------------------------------------------------------------------
|
| Stable installs follow final releases; the next channel also takes release
| candidates (1.5.0-rc.1, ...). Three scripts decide what "newest" means: the
| Docker workflow's tag step, the Droplet installer and `lineledger upgrade`.
| A slip here moves `latest` onto a release candidate, or a stable patch
| onto `next`, and installs upgrade into the wrong release on their own.
*/

beforeEach(function () {
    if ((new ExecutableFinder)->find('jq') === null) {
        $this->markTestSkipped('jq is not installed.');
    }

    $this->scripts = [
        '.github/scripts/image-channels.sh',
        'docker/digitalocean/droplet/install.sh',
        'docker/digitalocean/droplet/lineledger',
    ];

    $source = (string) file_get_contents(base_path($this->scripts[0]));
    preg_match("/^SEMVER_JQ='(.*?)'$/ms", $source, $match);
    $this->semverJq = $match[1] ?? '';

    $this->channels = function (string $tag, array $tags): array {
        $process = new Process([base_path('.github/scripts/image-channels.sh'), $tag, '--tags-from-stdin']);
        $process->setInput(implode("\n", $tags)."\n")->run();

        parse_str(str_replace("\n", '&', trim($process->getOutput())), $outputs);

        return ['exit' => $process->getExitCode(), 'outputs' => $outputs];
    };

    $this->jq = function (string $program, string $input = 'null'): string {
        $process = new Process(['jq', '-r', $this->semverJq.$program]);
        $process->setInput($input)->mustRun();

        return trim($process->getOutput());
    };
});

it('moves latest and next by version, not by push order', function (string $tag, array $existing, string $latest, string $next) {
    $result = ($this->channels)($tag, $existing);

    expect($result['exit'])->toBe(0)
        ->and($result['outputs'])->toBe(['latest' => $latest, 'next' => $next]);
})->with([
    'a release candidate moves next only' => ['v1.5.0-rc.1', ['v1.4.2', 'v1.4.3'], 'false', 'true'],
    'a stable patch after an RC moves latest only' => ['v1.4.4', ['v1.4.3', 'v1.5.0-rc.1'], 'true', 'false'],
    'the final release moves both' => ['v1.5.0', ['v1.4.4', 'v1.5.0-rc.1', 'v1.5.0-rc.2'], 'true', 'true'],
    'a patch on the newest line moves both' => ['v1.4.4', ['v1.4.2', 'v1.4.3'], 'true', 'true'],
    'rc.10 comes after rc.9' => ['v1.5.0-rc.10', ['v1.5.0-rc.9'], 'false', 'true'],
    'a beta comes before an rc' => ['v1.5.0-beta.2', ['v1.5.0-rc.1'], 'false', 'false'],
    'rebuilding an old tag moves neither' => ['v1.4.2', ['v1.4.2', 'v1.4.3'], 'false', 'false'],
    'non-release tags are ignored' => ['v1.4.3', ['v1.4.2', 'v2', 'vnext'], 'true', 'true'],
]);

it('refuses a tag that is not a release version', function () {
    expect(($this->channels)('v1.5', ['v1.4.3'])['exit'])->not->toBe(0);
});

it('keeps one semver rule across the workflow and the Droplet scripts', function () {
    expect($this->semverJq)->toContain('def semver');

    foreach ($this->scripts as $script) {
        $source = (string) file_get_contents(base_path($script));
        preg_match("/^SEMVER_JQ='(.*?)'$/ms", $source, $match);

        expect($match[1] ?? null)->toBe($this->semverJq, "{$script} has a different SEMVER_JQ");
    }
});

it('orders versions by semver precedence', function () {
    $sorted = ($this->jq)('map(select(valid)) | sort_by(semver) | join(" ")', json_encode([
        '1.10.0', 'v1.5.0', '1.5.0-rc.10', '1.5.0-rc.2', '1.9.0', '1.5.0-beta.1', 'not-a-version', '1.4.3',
    ]));

    expect($sorted)->toBe('1.4.3 1.5.0-beta.1 1.5.0-rc.2 1.5.0-rc.10 v1.5.0 1.9.0 1.10.0');
});

it('picks the newest published release for the next channel, skipping drafts', function () {
    $releases = json_encode([
        ['tag_name' => 'v1.4.4', 'draft' => false, 'prerelease' => false],
        ['tag_name' => 'v1.5.0-rc.2', 'draft' => true, 'prerelease' => true],
        ['tag_name' => 'v1.5.0-rc.1', 'draft' => false, 'prerelease' => true],
        ['tag_name' => 'v1.4.3', 'draft' => false, 'prerelease' => false],
    ]);

    // The selection install.sh and `lineledger upgrade` both run on GitHub's release list.
    $selection = '[.[] | select(.draft | not) | .tag_name | select(valid)] | max_by(semver) // empty';

    foreach (array_slice($this->scripts, 1) as $script) {
        expect((string) file_get_contents(base_path($script)))->toContain($selection);
    }

    expect(($this->jq)($selection, $releases))->toBe('v1.5.0-rc.1');
});

it('tags images only through the channels step', function () {
    $workflow = Yaml::parseFile(base_path('.github/workflows/docker.yml'));
    $steps = collect($workflow['jobs']['build']['steps'])->keyBy(fn (array $step) => $step['id'] ?? $step['uses'] ?? $step['name'] ?? '');

    $meta = $steps['meta']['with'];
    $tags = collect(explode("\n", trim($meta['tags'])));

    // metadata-action adds latest to any final semver tag unless told not to.
    expect($meta['flavor'])->toContain('latest=false')
        ->and($steps['channels']['run'])->toContain('.github/scripts/image-channels.sh')
        ->and($tags->first(fn (string $line) => str_contains($line, 'value=latest')))
        ->toContain("steps.channels.outputs.latest == 'true'")
        ->and($tags->first(fn (string $line) => str_contains($line, 'value=next')))
        ->toContain("steps.channels.outputs.next == 'true'");
});

it('runs CI on the next branch as well as main', function (string $workflow) {
    $on = Yaml::parseFile(base_path(".github/workflows/{$workflow}.yml"))['on'];

    expect($on['push']['branches'])->toContain('main', 'next')
        ->and($on['pull_request']['branches'])->toContain('main', 'next');
})->with(['tests', 'lint', 'security']);

it('ships channel scripts that parse', function (string $script) {
    $process = new Process(['bash', '-n', base_path($script)]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
})->with([
    '.github/scripts/image-channels.sh',
    'docker/digitalocean/droplet/install.sh',
    'docker/digitalocean/droplet/lineledger',
]);
