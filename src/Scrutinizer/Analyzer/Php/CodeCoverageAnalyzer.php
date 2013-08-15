<?php

namespace Scrutinizer\Analyzer\Php;

use JMS\PhpManipulator\TokenStream;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Scrutinizer\Analyzer\AnalyzerInterface;
use Scrutinizer\Analyzer\Php\Util\ImpactAnalyzer;
use Scrutinizer\Config\ConfigBuilder;
use Scrutinizer\Model\File;
use Scrutinizer\Model\Project;
use Scrutinizer\Util\XmlUtils;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * @display-name PHP Code Coverage
 * @doc-path tools/php/code-coverage/
 */
class CodeCoverageAnalyzer implements AnalyzerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private $tokenStream;

    public function __construct()
    {
        $this->tokenStream = new TokenStream();
    }

    public function scrutinize(Project $project)
    {
        if ( ! extension_loaded('xdebug')) {
            throw new \LogicException('The xdebug extension must be loaded for generating code coverage.');
        }

        if ($project->getGlobalConfig('only_changesets')) {
            $this->logger->info('The "only_changesets" option for "php_code_coverage" was deprecated.'."\n");
        }

        $outputFile = tempnam(sys_get_temp_dir(), 'php-code-coverage');
        $testCommand = $project->getGlobalConfig('test_command').' --coverage-clover '.escapeshellarg($outputFile);
        $this->logger->info(sprintf('Running command "%s"...'."\n", $testCommand));
        $proc = new Process($testCommand, $project->getDir());
        $proc->setTimeout(1800);
        $proc->setIdleTimeout(300);
        $proc->setPty(true);
        $proc->run(function($_, $data) {
            $this->logger->info($data);
        });

        $output = file_get_contents($outputFile);
        unlink($outputFile);

        if (empty($output)) {
            if ($proc->getExitCode() > 0) {
                throw new ProcessFailedException($proc);
            }

            return;
        }

        $this->processCloverFile($project, $output);
    }

    public function buildConfig(ConfigBuilder $builder)
    {
        $builder
            ->info('Collects code coverage information about the changeset.')
            ->globalConfig()
                ->scalarNode('config_path')->defaultNull()->end()
                ->scalarNode('test_command')->defaultValue('phpunit')->end()
                ->booleanNode('only_changesets')
                    ->info('(deprecated) Whether code coverage information should only be generated for changesets.')
                    ->defaultFalse()
                ->end()
            ->end()
        ;
    }

    public function getName()
    {
        return 'php_code_coverage';
    }

    private function processCloverFile(Project $project, $content)
    {
        $doc = XmlUtils::safeParse($content);
        $rootDir = $project->getDir().'/';
        $prefixLength = strlen($rootDir);

        foreach ($doc->xpath('//file') as $xmlFile) {
            if ( ! isset($xmlFile->line)) {
                continue;
            }

            $filename = substr((string) $xmlFile->attributes()->name, $prefixLength);
            $project->getFile($filename)->forAll(
                function(File $modelFile) use ($xmlFile) {
                    foreach ($xmlFile->line as $line) {
                        $attrs = $line->attributes();
                        $modelFile->setLineAttribute((integer) $attrs->num, 'coverage_count', (integer) $attrs->count);
                    }
                }
            );
        }

        // files="3" loc="114" ncloc="114" classes="3" methods="16" coveredmethods="3" conditionals="0" coveredconditionals="0"
        // statements="38" coveredstatements="5" elements="54" coveredelements="8"
        foreach ($doc->xpath('descendant-or-self::project/metrics') as $metricsNode) {
            $metricsAttrs = $metricsNode->attributes();

            $project->setSimpleValuedMetric('php_code_coverage.files', (integer) $metricsAttrs->files);
            $project->setSimpleValuedMetric('php_code_coverage.lines_of_code', (integer) $metricsAttrs->loc);
            $project->setSimpleValuedMetric('php_code_coverage.non_comment_lines_of_code', (integer) $metricsAttrs->ncloc);
            $project->setSimpleValuedMetric('php_code_coverage.classes', (integer) $metricsAttrs->classes);
            $project->setSimpleValuedMetric('php_code_coverage.methods', (integer) $metricsAttrs->methods);
            $project->setSimpleValuedMetric('php_code_coverage.covered_methods', (integer) $metricsAttrs->coveredmethods);
            $project->setSimpleValuedMetric('php_code_coverage.conditionals', (integer) $metricsAttrs->conditionals);
            $project->setSimpleValuedMetric('php_code_coverage.covered_conditionals', (integer) $metricsAttrs->coveredconditionals);
            $project->setSimpleValuedMetric('php_code_coverage.statements', (integer) $metricsAttrs->statements);
            $project->setSimpleValuedMetric('php_code_coverage.covered_statements', (integer) $metricsAttrs->coveredstatements);
            $project->setSimpleValuedMetric('php_code_coverage.elements', (integer) $metricsAttrs->elements);
            $project->setSimpleValuedMetric('php_code_coverage.covered_elements', (integer) $metricsAttrs->coveredelements);
        }

        /**
         *     <package name="Foo">
                <file name="/tmp/scrtnzerI2LxkB/src/Bar.php">
                <class name="Bar" namespace="Foo">
                <metrics methods="2" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="3"
         *              coveredstatements="2" elements="5" coveredelements="3"/>
                </class>
                <line num="9" type="method" name="__construct" crap="1" count="1"/>
                <line num="11" type="stmt" count="1"/>
                <line num="12" type="stmt" count="1"/>
                <line num="14" type="method" name="getName" crap="2" count="0"/>
                <line num="16" type="stmt" count="0"/>
                <metrics loc="17" ncloc="17" classes="1" methods="2" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="3" coveredstatements="2" elements="5" coveredelements="3"/>
                </file>
         */
        foreach ($doc->xpath('//package') as $packageNode) {
            $packageName = (string) $packageNode->attributes()->name;

            $package = $project->getOrCreateCodeElement('package', $packageName);

            foreach ($packageNode->xpath('./file') as $fileNode) {
                $filename = substr($fileNode->attributes()->name, strlen($project->getDir()) + 1);

                $project->getFile($filename)->forAll(function(File $modelFile) use ($packageName, $project, $fileNode, $package, $filename) {
                    $this->tokenStream->setCode($modelFile->getContent());

                    $addedMethods = 0;
                    foreach ($fileNode->xpath('./class') as $classNode) {
                        $className = $packageName.'\\'.$classNode->attributes()->name;

                        $class = $project->getOrCreateCodeElement('class', $className);
                        $package->addChild($class);

                        $class->setLocation($filename);

                        $metricsAttrs = $classNode->metrics->attributes();
                        $methodCount = (integer) $metricsAttrs->methods;
                        $coveredMethodCount = (integer) $metricsAttrs->coveredmethods;
                        $statements = (integer) $metricsAttrs->statements;
                        $coveredStatements = (integer) $metricsAttrs->coveredstatements;
                        $class->setMetric('php_code_coverage.conditionals', (integer) $metricsAttrs->conditionals);
                        $class->setMetric('php_code_coverage.covered_conditionals', (integer) $metricsAttrs->coveredconditionals);
                        $class->setMetric('php_code_coverage.statements', $statements);
                        $class->setMetric('php_code_coverage.covered_statements', $coveredStatements);
                        $class->setMetric('php_code_coverage.elements', (integer) $metricsAttrs->elements);
                        $class->setMetric('php_code_coverage.covered_elements', (integer) $metricsAttrs->coveredelements);
                        $class->setMetric('php_code_coverage.coverage', $statements > 0 ? $coveredStatements / $statements : 1.0);

                        $i = -1;
                        $addedClassMethods = 0;
                        foreach ($fileNode->xpath('./line') as $lineNode) {
                            $lineAttrs = $lineNode->attributes();

                            if ((string) $lineAttrs->type !== 'method') {
                                continue;
                            }

                            // This is a workaround for a bug in CodeCoverage that displays arguments of closures as
                            // methods of the declaring class.
                            $methodName = (string) $lineAttrs->name;
                            $methodToken = $this->tokenStream->next->findNextToken(function(TokenStream\AbstractToken $token) use ($methodName) {
                                if ( ! $token->matches(T_FUNCTION)) {
                                    return false;
                                }

                                return $token->findNextToken('NO_WHITESPACE_OR_COMMENT')->map(function(TokenStream\AbstractToken $token) use ($methodName) {
                                    return $token->matches(T_STRING) && $token->getContent() === $methodName;
                                })->getOrElse(false);
                            });
                            if ( ! $methodToken->isDefined()) {
                                $methodCount -= 1;

                                if ($lineAttrs->count > 0) {
                                    $coveredMethodCount -= 1;
                                }

                                continue;
                            }

                            $i += 1;

                            if ($i < $addedMethods) {
                                continue;
                            }

                            if ($addedClassMethods >= (integer) $metricsAttrs->methods) {
                                break;
                            }

                            $addedClassMethods += 1;
                            $addedMethods += 1;
                            $method = $project->getOrCreateCodeElement('operation', $className.'::'.$methodName);
                            $class->addChild($method);

                            $method->setMetric('php_code_coverage.change_risk_anti_pattern', (integer) $lineAttrs->crap);
                            $method->setMetric('php_code_coverage.count', (integer) $lineAttrs->count);
                        }

                        $class->setMetric('php_code_coverage.methods', $methodCount);
                        $class->setMetric('php_code_coverage.covered_methods', $coveredMethodCount);
                    }
                });
            }
        }
    }
}