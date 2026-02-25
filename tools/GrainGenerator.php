#!/usr/bin/env php
<?php

/**
 * Grain RPC コード生成ツール
 *
 * GrainBase を継承した Actor クラスと GrainClient を継承した Client クラスを生成する。
 *
 * Usage:
 *   php tools/GrainGenerator.php --definition=path/to/grain_definition.php --output=src/Generated/
 *
 * Definition file format: see examples/grain_definition.example.php
 */

declare(strict_types=1);

final class GrainGenerator
{
    public function __construct(
        private readonly string $definitionPath,
        private readonly string $outputDir,
    ) {
    }

    public function generate(): void
    {
        $def = $this->loadDefinition();
        $this->validateDefinition($def);

        if (!is_dir($this->outputDir) && !mkdir($this->outputDir, 0755, true)) {
            throw new \RuntimeException("Failed to create output directory: {$this->outputDir}");
        }

        $actorCode = $this->renderActor($def);
        $clientCode = $this->renderClient($def);

        $kind = $def['kind'];
        $actorFile = rtrim($this->outputDir, '/') . "/{$kind}Actor.php";
        $clientFile = rtrim($this->outputDir, '/') . "/{$kind}Client.php";

        file_put_contents($actorFile, $actorCode);
        file_put_contents($clientFile, $clientCode);

        echo "Generated: {$actorFile}\n";
        echo "Generated: {$clientFile}\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function loadDefinition(): array
    {
        if (!file_exists($this->definitionPath)) {
            throw new \InvalidArgumentException("Definition file not found: {$this->definitionPath}");
        }

        /** @var array<string, mixed> $def */
        $def = require $this->definitionPath;

        if (!is_array($def)) {
            throw new \InvalidArgumentException("Definition file must return an array.");
        }

        return $def;
    }

    /**
     * @param array<string, mixed> $def
     */
    private function validateDefinition(array $def): void
    {
        foreach (['namespace', 'proto_namespace', 'kind', 'methods'] as $key) {
            if (!isset($def[$key])) {
                throw new \InvalidArgumentException("Missing required key '{$key}' in definition.");
            }
        }

        if (!is_array($def['methods']) || $def['methods'] === []) {
            throw new \InvalidArgumentException("'methods' must be a non-empty array.");
        }
    }

    /**
     * @param array<string, mixed> $def
     */
    private function renderActor(array $def): string
    {
        $namespace     = (string) $def['namespace'];
        $protoNs       = (string) $def['proto_namespace'];
        $kind          = (string) $def['kind'];
        /** @var array<int, array<string, string>> $methods */
        $methods       = $def['methods'];

        $uses          = $this->buildActorUses($protoNs, $methods);
        $dispatchCases = $this->buildMatchCases($methods);
        $dispatchers   = $this->buildDispatchMethods($methods);
        $abstracts     = $this->buildAbstractMethods($methods);

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            "namespace {$namespace};",
            '',
            'use Phluxor\ActorSystem\Context\ContextInterface;',
            'use Phluxor\Cluster\Grain\GrainBase;',
            'use Phluxor\Cluster\ProtoBuf\GrainRequest;',
            $uses,
            "abstract class {$kind}Actor extends GrainBase",
            '{',
            '    final protected function onGrainRequest(GrainRequest $request, ContextInterface $context): void',
            '    {',
            '        try {',
            '            match ($request->getMethodIndex()) {',
            $dispatchCases,
            "                default => \$this->respondError(\$context, 'unknown method index: ' . \$request->getMethodIndex()),",
            '            };',
            '        } catch (\Throwable $e) {',
            '            $this->respondError($context, $e->getMessage());',
            '        }',
            '    }',
            '',
            $dispatchers,
            '',
            $abstracts,
            '}',
        ];

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $def
     */
    private function renderClient(array $def): string
    {
        $namespace  = (string) $def['namespace'];
        $protoNs    = (string) $def['proto_namespace'];
        $kind       = (string) $def['kind'];
        /** @var array<int, array<string, string>> $methods */
        $methods    = $def['methods'];

        $uses        = $this->buildClientUses($protoNs, $methods);
        $clientMethods = $this->buildClientMethods($methods);

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Phluxor\Cluster\Cluster;
        use Phluxor\Cluster\Exception\GrainCallException;
        use Phluxor\Cluster\Grain\GrainClient;
        use Phluxor\Cluster\Grain\GrainResponseDecoder;
        {$uses}
        final class {$kind}Client extends GrainClient
        {
            private readonly GrainResponseDecoder \$decoder;

            public function __construct(Cluster \$cluster, string \$identity, string \$kind = '{$kind}')
            {
                parent::__construct(\$cluster, \$identity, \$kind);
                \$this->decoder = new GrainResponseDecoder();
            }

        {$clientMethods}
        }
        PHP;
    }

    /**
     * @param array<int, array<string, string>> $methods
     */
    private function buildActorUses(string $protoNs, array $methods): string
    {
        $classes = [];
        foreach ($methods as $m) {
            $classes[] = $protoNs . '\\' . $m['request'];
            $classes[] = $protoNs . '\\' . $m['response'];
        }
        $classes = array_unique($classes);
        sort($classes);

        return implode("\n", array_map(static fn(string $c) => "use {$c};", $classes));
    }

    /**
     * @param array<int, array<string, string>> $methods
     */
    private function buildClientUses(string $protoNs, array $methods): string
    {
        return $this->buildActorUses($protoNs, $methods);
    }

    /**
     * @param array<int, array<string, string>> $methods
     */
    private function buildMatchCases(array $methods): string
    {
        $lines = [];
        foreach ($methods as $i => $m) {
            $dispatch = 'dispatch' . ucfirst($m['name']);
            $lines[]  = "                    {$i} => \$this->{$dispatch}(\$request, \$context),";
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<int, array<string, string>> $methods
     */
    private function buildDispatchMethods(array $methods): string
    {
        $parts = [];
        foreach ($methods as $m) {
            $dispatch  = 'dispatch' . ucfirst($m['name']);
            $reqClass  = $m['request'];
            $parts[]   = <<<PHP
                private function {$dispatch}(GrainRequest \$grainRequest, ContextInterface \$context): void
                {
                    \$request = new {$reqClass}();
                    \$request->mergeFromString(\$grainRequest->getMessageData());
                    \$this->respondGrain(\$context, \$this->{$m['name']}(\$request));
                }
            PHP;
        }
        return implode("\n\n", $parts);
    }

    /**
     * @param array<int, array<string, string>> $methods
     */
    private function buildAbstractMethods(array $methods): string
    {
        $parts = [];
        foreach ($methods as $m) {
            $parts[] = "    abstract protected function {$m['name']}({$m['request']} \$request): {$m['response']};";
        }
        return implode("\n\n", $parts);
    }

    /**
     * @param array<int, array<string, string>> $methods
     */
    private function buildClientMethods(array $methods): string
    {
        $parts = [];
        foreach ($methods as $i => $m) {
            $resClass = $m['response'];
            $parts[]  = <<<PHP
                /**
                 * @throws GrainCallException
                 */
                public function {$m['name']}({$m['request']} \$request): ?{$resClass}
                {
                    \$grainResponse = \$this->callGrain({$i}, \$request);
                    if (\$grainResponse === null) {
                        return null;
                    }

                    \$decoded = \$this->decoder->decode(\$grainResponse);
                    return \$decoded instanceof {$resClass} ? \$decoded : null;
                }
            PHP;
        }
        return implode("\n\n", $parts);
    }
}

// --- CLI エントリポイント ---

$opts = getopt('', ['definition:', 'output:']);

$definition = $opts['definition'] ?? null;
$output     = $opts['output'] ?? null;

if ($definition === null || $output === null) {
    fwrite(STDERR, "Usage: php tools/GrainGenerator.php --definition=<path> --output=<dir>\n");
    exit(1);
}

try {
    (new GrainGenerator((string) $definition, (string) $output))->generate();
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
