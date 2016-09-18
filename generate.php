<?php
use Gajus\Dindent\Indenter;
use phpDocumentor\Reflection\DocBlockFactory;

require 'vendor/autoload.php';

define('DOCBLOCK_REGEX_ACTIONS', '#(?>(?P<docblock>/\*\*.*?\*/))\s*Action::call\((?P<quote>[\'"])(?P<name>.*?)\g{quote}#sm');
define('DOCBLOCK_REGEX_FILTERS', '#(?>(?P<docblock>/\*\*.*?\*/))[^;]*Filter::call\((?P<quote>[\'"])(?P<name>.*?)\g{quote}#sm');

if (isset($argv[1])) {
    $codicePath = $argv[1];
} else {
    echo "Codice Documentation Index Generator\n";
    echo "$ generate.php [pathToCodiceSources]\n\n";
    echo "Output will be stored in /output\n";
    die;
}

if (!is_dir('./output')) {
    mkdir('output');
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($codicePath . '/app', RecursiveDirectoryIterator::SKIP_DOTS));

$hookables = [
    'actions' => [],
    'filters' => [],
];

foreach ($iterator as $fileinfo) {
    if (substr($filename = $fileinfo->getPathname(), -4) != '.php') {
        continue;
    }

    $contents = file_get_contents($filename);

    preg_match_all(DOCBLOCK_REGEX_ACTIONS, $contents, $fileActions, PREG_SET_ORDER);
    preg_match_all(DOCBLOCK_REGEX_FILTERS, $contents, $fileFilters, PREG_SET_ORDER);

    // Inject current file path into preg_match_all() results and save to temporary variables
    $hookables['actions'][] = injectFilePathIntoMatches(normalizeFilePath($filename, $codicePath), filterEmptyMatches($fileActions));
    $hookables['filters'][] = injectFilePathIntoMatches(normalizeFilePath($filename, $codicePath), filterEmptyMatches($fileFilters));
}

$factory  = DocBlockFactory::createInstance();

// Generate array, pass to template, render it and save results...
// for each type of hookable
foreach (['actions', 'filters'] as $hookableType) {
    $data[$hookableType] = [];

    foreach ($hookables[$hookableType] as $hookablesInFile) {
        foreach ($hookablesInFile as $hookable) {
            $docblock = $factory->create($hookable['docblock']);

            $params = [];
            foreach ($docblock->getTagsByName('param') as $param) {
                $params[] = [
                    'name' => $param->getVariableName(),
                    'type' => $param->getType(),
                    'description' => $param->getDescription()
                ];
            }

            // @todo: refactor (yeah, for sure I will...)
            $fileDefined = explode('/', $hookable['fileDefined']);
            $fileDefined = end($fileDefined);

            $data[$hookableType][] = [
                'name' => $hookable['name'],
                'description' => $docblock->getSummary(),
                'parameters' => $params,
                'returns' => $docblock->getTagsByName('return') ? $docblock->getTagsByName('return')[0]->getType() : null,
                'fileDefined' => $fileDefined,
                'pathDefined' => $hookable['fileDefined'],
            ];
        }
    }

    $data[$hookableType] = sortData($data[$hookableType]);

    $output = renderIndex($data[$hookableType], $hookableType);

    file_put_contents("./output/{$hookableType}-list.md", $output);

    echo "Generated {$hookableType} index\n";
}

function filterEmptyMatches($matches)
{
    return array_filter($matches, function($match) {
        return !empty($match);
    });
}

function renderIndex($data, $template)
{
    $indenter = new Indenter();
    
    ob_start();
    require "templates/$template.php";
    return $indenter->indent(ob_get_clean());
}

function injectFilePathIntoMatches($filePath, $matches)
{
    return array_map(function ($match) use ($filePath) {
        $match['fileDefined'] = $filePath;
        
        return $match;
    }, $matches);
}

function normalizeFilePath($filename, $codicePath)
{
    return str_replace('\\', '/', substr($filename, strlen($codicePath) + 1));
}

function sortData($data)
{
    usort($data, function($a, $b) {
        if ($a['name'] == $b['name']) {
            return 0;
        }

        return $a['name'] < $b['name'] ? -1 : 1;
    });

    return $data;
}
